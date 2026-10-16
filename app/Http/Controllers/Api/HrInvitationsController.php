<?php

namespace App\Http\Controllers\Api;

use App\Models\Contract;
use App\Models\Invitation;
use App\Models\Role;
use App\Models\User;
use App\Notifications\InvitationLink;
use App\Services\Hr\Invitations;
use App\Support\Access\ScopeReach;
use App\Support\Audit\AuditLogger;
use App\Support\Automations\EventEmitter;
use App\Support\Hr\CannotActOnInvitation;
use App\Support\Hr\InvitationActions;
use App\Support\Mail\MailStatus;
use App\Support\Modules\PermissionCatalogue;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Inviting somebody into the organization (EZBIZI-HR-PLAN.md, §4).
 *
 * ── The invitation carries the role AND the place ────────────────
 *
 * The owner's decision, and the shape of this endpoint follows from it: the
 * inviter chooses both at the moment of inviting, so the link is complete and
 * the person lands somewhere real rather than in a limbo an administrator has to
 * remember to resolve. Every field is required except the two names.
 *
 * ── Somebody can be invited with no contract at all ──────────────
 *
 * Also the owner's: office staff, a co-owner, "just give this person a login".
 * The richer onboarding path — the contract template, the fields it defines, the
 * form the two sides fill — comes later and will hang off this same invitation.
 * This plain path has to work on its own, and does.
 *
 * ── The link, not the email ──────────────────────────────────────
 *
 * The response carries the one-time link, and that is the only moment the raw
 * token exists anywhere. An email goes out too (see deliver() below), and since
 * SES production access was granted on 2026-09-14 it genuinely arrives.
 *
 * The link stays in the response anyway, and the reason changed rather than
 * expired: it used to be there because nothing could send at all, and it is
 * there now because a send can still go nowhere — a box on `log` or `array`, a
 * blank credential, a refused transport — and because the inviter is the only
 * person who can hand it over when it does.
 *
 * This paragraph previously read "nothing on this platform can send mail". That
 * was true when it was written and false the hour SES was approved, and my own
 * sweep for sandbox-era copy missed it because I grepped for "emailed" rather
 * than for the claim. A describer goes stale silently; it is worth suspecting
 * every sentence that says what the platform CANNOT do.
 */
class HrInvitationsController extends Controller
{
    /**
     * What became of the send, as a state rather than a yes/no.
     *
     * Three, and every one of them is something the server can PROVE. The
     * screen turns each into a sentence and offers one action; it never names
     * a cause we guessed. In particular there is no "the address was refused"
     * here, though it is the single most useful thing a reader could be told:
     * this is a platform send over Notification::route, so a rejected
     * recipient and a dead connection arrive as the same transport exception,
     * and telling them apart means string-matching a vendor's error prose. The
     * failure mode of getting that wrong is telling somebody to correct a
     * perfectly good address, so it is not offered. A wrong address becomes
     * knowable when SES's bounce events land as typed data, and that is a
     * FOURTH state arriving later rather than a cause parsed out of this one.
     */
    public const SENT = 'sent';

    /** The box delivers nowhere — `log`, `array`, `null`, or a blank credential. */
    public const NOT_SENT_NO_TRANSPORT = 'not_sent_no_transport';

    /** The transport was asked and refused. True that it failed, silent about why. */
    public const NOT_SENT_REFUSED = 'not_sent_refused';

    public function __construct(
        private readonly Invitations $invitations,
        private readonly ScopeReach $reach,
        private readonly PermissionCatalogue $catalogue,
        private readonly AuditLogger $audit,
        private readonly EventEmitter $events,
        private readonly InvitationActions $actions,
        private readonly TenantContext $tenant,
    ) {}

    /**
     * GET /api/v1/hr/invitations
     *
     * Filtered to what the caller may reach, in SQL rather than afterwards —
     * see ScopeReach::constrain for why a list cannot be filtered row by row and
     * paginated at the same time.
     */
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Invitation::class);

        $query = Invitation::query()
            // `onboarding` is loaded because Invitation::status() reads it —
            // `in_progress` is a fact about whether a form was started, and
            // without this the derivation would be one query per row.
            ->with(['role:id,name', 'invitedBy:id,name', 'assignedOrganization:id,name',
                'assignedCompany:id,name', 'assignedFranchise:id,name', 'onboarding']);

        $this->reach->constrain($query, $request->user());

        // A search box over the list (Jose, 2026-10-14: "we lack searchbars on
        // the 3 last human resources tabs"). Before the tiles, so their counts
        // are of what was searched for.
        if (filled($term = trim((string) $request->query('search')))) {
            $like = "%{$term}%";
            $query->where(fn ($q) => $q->where('email', 'like', $like)
                ->orWhere('first_name', 'like', $like)
                ->orWhere('last_name', 'like', $like)
                ->orWhereRaw("CONCAT_WS(' ', first_name, last_name) LIKE ?", [$like]));
        }

        // The tiles count every page, before the one they filter is applied.
        $tiles = [];
        foreach ([Invitation::SENT, 'opened', Invitation::COMPLETED, Invitation::ABANDONED, Invitation::REVOKED] as $status) {
            $tiles[$status] = (clone $query)->inStatus($status)->count();
        }

        if (filled($request->query('status'))) {
            $query->inStatus((string) $request->query('status'));
        }

        $invitations = $query
            // Newest first: the list exists to be acted on, and the thing most
            // likely to need chasing or withdrawing is the thing just sent.
            ->orderByDesc('sent_at')
            ->paginate(perPage: min((int) $request->query('per_page', 50), 200));

        return response()->json([
            'data' => collect($invitations->items())->map(fn (Invitation $i) => $this->present($i)),
            'meta' => [
                ...$this->pageMeta($invitations),
                'tiles' => $tiles,
            ],
        ]);
    }

    /**
     * POST /api/v1/hr/invitations
     *
     * Four refusals before anything is written, and they are deliberately
     * different from each other:
     *
     *   403  you may not invite at all                     (policy)
     *   422  that role reaches further than yours          (escalation, §4)
     *   422  that place is outside your reach              (§4, the owner's rule)
     *   422  that address is already here, or already asked
     */
    public function store(Request $request): JsonResponse
    {
        $this->authorize('create', Invitation::class);

        $actor = $request->user();
        $input = $request->validate([
            'email' => ['required', 'email', 'max:255'],

            // The only optional fields. The plan is explicit that the employer
            // MAY pre-fill the common core and does not have to; whatever is
            // left blank the person completes when they accept.
            'first_name' => ['nullable', 'string', 'max:60'],
            'last_name' => ['nullable', 'string', 'max:60'],

            'role_id' => ['required', 'uuid', Rule::exists('roles', 'id')
                ->where('organization_id', $this->tenant->idOrFail())],

            'scope_type' => ['required', Rule::in(ScopeReach::TYPES)],
            'scope_id' => ['required', 'uuid'],

            // Optional: the paperwork starts with the invitation (Jose,
            // 2026-09-30: "yes but toggable, some people dont need contracts").
            'contract_id' => ['nullable', 'uuid', Rule::exists('contracts', 'id')
                ->where('organization_id', $this->tenant->idOrFail())
                ->whereNull('archived_at')],
        ]);

        $role = Role::findOrFail($input['role_id']);

        // Giving somebody a role grants every permission it holds, without
        // naming any of them — the same escalation as the roles editor, through
        // a door that has no checkboxes on it.
        $this->catalogue->assertRoleAssignable($actor, $role);

        // "He should only be able to see same level or downwards when his
        // options to assign an employee are presented" — checked here as well as
        // filtered in scope-options, because a picker is a convenience and a
        // request is what actually happens.
        $this->reach->assertCovers($actor, $input['scope_type'], $input['scope_id']);

        $this->assertNotAlreadyHere($input['email']);

        [$invitation, $token] = DB::transaction(function () use ($input, $actor) {
            $issued = $this->invitations->issue([
                'email' => strtolower(trim($input['email'])),
                'first_name' => $input['first_name'] ?? null,
                'last_name' => $input['last_name'] ?? null,
                'role_id' => $input['role_id'],
                'invited_by_user_id' => $actor->getKey(),
                ...$this->scopeColumns($input['scope_type'], $input['scope_id']),
            ]);

            $invitation = $issued['invitation'];

            // Inside the transaction, so the record and its history commit
            // together or not at all (§10). The token is not in it, and must
            // never be: an audit log is read by more people than a credential
            // should be.
            $this->audit->log('invitation.sent', $invitation, metadata: [
                'email' => $invitation->email,
                'role' => $invitation->role?->name,
                'scope' => [
                    'type' => $invitation->scopeType(),
                    'name' => $invitation->scopeLabel(),
                ],
            ]);

            /*
             * `user.invited`, with no subject — and it cannot have one.
             *
             * There is no user yet; that is what the event is about. The payload
             * carries what a rule could plausibly act on instead. An
             * `update_field` action under this trigger has nothing to write to,
             * which the catalogue already says of any trigger whose module
             * declares no subject for it.
             */
            $this->events->emit('user.invited', payload: ['after' => [
                'email' => $invitation->email,
                'role_id' => $invitation->role_id,
                'scope_type' => $invitation->scopeType(),
                'scope_id' => $invitation->scopeId(),
                'expires_at' => $invitation->expires_at->toIso8601String(),
            ]], actor: $actor);

            /*
             * And `invitation.sent`, which is the same act carrying the record.
             *
             * Not a duplicate of the line above, and the difference is the whole
             * reason both exist: `user.invited` is about a person who has no
             * account yet, so it has no subject, and an event with no subject has
             * no scope — a franchise-scoped rule can never match one. This
             * carries the invitation, and an invitation knows which franchise or
             * company it was issued into. "Tell me when somebody is invited into
             * my branch" is writable because of this event and not the other.
             *
             * A rule listening for both runs twice, which is the same bargain
             * `job.updated` and `job.status_changed` already make.
             */
            $this->events->emit('invitation.sent', $invitation, ['after' => [
                'status' => $invitation->status(),
                'email' => $invitation->email,
                'role_id' => $invitation->role_id,
                'role' => $invitation->role?->name,
                'scope_type' => $invitation->scopeType(),
                'scope_id' => $invitation->scopeId(),
                'scope' => $invitation->scopeLabel(),
                'expires_at' => $invitation->expires_at->toIso8601String(),
            ]], actor: $actor);

            // Inside the same transaction: a contract that cannot carry an
            // onboarding (never published, no signature line) refuses the
            // whole invite, so nobody is emailed into paperwork that never
            // started. The email goes after the commit, below.
            if (($input['contract_id'] ?? null) !== null) {
                try {
                    $this->actions->startOnboarding(
                        $invitation, Contract::findOrFail($input['contract_id']), $actor,
                    );
                } catch (CannotActOnInvitation $e) {
                    throw ValidationException::withMessages(['contract_id' => $e->getMessage()]);
                }
                // With paperwork, it is emailed once the employer has signed
                // it (Jose, 2026-10-06: "make it so the onboarding gets sent
                // only after the employer signs").
                $invitation->forceFill(['held_at' => now()])->save();
            }

            return [$invitation, $issued['token']];
        });

        if ($invitation->isHeld()) {
            // No link either: a link handed over by hand would be the send
            // the hold exists to stop.
            return response()->json(['data' => $this->present($invitation)], Response::HTTP_CREATED);
        }

        $state = $this->deliver($invitation, $token);

        return response()->json([
            'data' => $this->present($invitation, $token, $state),
        ], Response::HTTP_CREATED);
    }

    /**
     * POST /api/v1/hr/invitations/{invitation}/resend
     *
     * A new token and a fresh week; the old link stops working. One at a time —
     * the owner was explicit that there is no bulk resend, and there is no
     * endpoint here that could become one.
     *
     * Deliberately allowed on an EXPIRED invitation: chasing one that lapsed is
     * the single most useful thing this button does. Not allowed on one that was
     * accepted or withdrawn, because both of those are decisions — the first by
     * the person, the second by the organization — and re-opening either should
     * be a new invitation somebody consciously sends.
     */
    public function resend(Request $request, Invitation $invitation): JsonResponse
    {
        $this->authorize('resend', $invitation);

        /*
         * RE-INVITING AT A DIFFERENT ADDRESS IS THIS ACT, not a PATCH.
         *
         * Approved by Jose on 2026-09-14 (desktop 2, mobile 5), and it is a
         * reissue rather than a field edit for a reason that is a security one
         * rather than a modelling preference. The row and the credential are
         * different things: editing the address changes where the NEXT message
         * goes and cannot recall the one already delivered. So if somebody
         * mistyped an address that happens to belong to a real person, quietly
         * repointing the row leaves that stranger holding a live invitation
         * into this organization while the screen says the problem is fixed.
         *
         * It is worst in the case the control most exists for — noticing the
         * typo just after a successful send — and it is only safe because
         * resend() already mints a new token and kills the old one. Which is
         * also why the address is optional HERE rather than an endpoint of its
         * own: one act, one dead link, one new send, and nothing to keep in
         * step.
         */
        $input = $request->validate([
            'email' => ['sometimes', 'required', 'email', 'max:255'],
        ]);

        $readdressed = isset($input['email'])
            && strtolower(trim($input['email'])) !== $invitation->email;

        /*
         * The SAME role check `store()` makes, because resend mints a new
         * credential and is therefore the same act.
         *
         * It was missing, and that was an escalation anybody with `hr.create`
         * could execute. Proven: a franchise-scoped user holding only hr.view
         * and hr.create is refused when INVITING into a role carrying
         * roles.create and users.delete — "it reaches further than yours does"
         * — and then resends an administrator's invitation into that same role,
         * gets 200, and is handed a fresh accept_url with a live token in it.
         * Accepting produced an account holding permissions the person who
         * pressed the button could never have granted.
         *
         * The policy asks whether they may resend THIS invitation — reach, and
         * hr.create. It has never asked what the invitation is FOR, and the
         * whole escalation bound is about what it is for.
         */
        $this->catalogue->assertRoleAssignable($request->user(), $invitation->role);

        // The same two refusals store() makes, and for the same reasons — an
        // address already holding an account, or already holding an open
        // invitation. This invitation is excluded from the second, or the act
        // would be refused on the grounds of the very row it is reissuing.
        //
        // An ACTOR rule, so it stays here: it asks what this organization's
        // book already contains, which is a question about the request rather
        // than about the invitation's own state. See InvitationActions for the
        // split between the two.
        if ($readdressed) {
            $this->assertNotAlreadyHere($input['email'], except: $invitation);
        }

        // What resending MEANS lives in one place now, because Automations
        // causes the same act from a rule and a second copy would have drifted
        // the first time one of them changed. The accepted and withdrawn
        // refusals moved in with it; everything above this line is the actor
        // half, which a rule has no answer for and this controller must still
        // ask.
        // Converted rather than rendered bare, because this endpoint's refusal
        // shape is somebody's contract: it has always answered 422 with an
        // `errors.invitation` key, the client prefers `errors` over `message`,
        // and a field that goes absent is the one response change that breaks a
        // reader who asked for none of this. Fleet returns the message alone;
        // theirs was a new endpoint with nothing to keep faith with.
        try {
            $token = $this->actions->resend(
                $invitation,
                $readdressed ? $input['email'] : null,
            );
        } catch (CannotActOnInvitation $e) {
            throw ValidationException::withMessages([
                $e->field ?? 'invitation' => $e->getMessage(),
            ]);
        }

        $state = $this->deliver($invitation, $token);

        return response()->json(['data' => $this->present($invitation->fresh(), $token, $state)]);
    }

    /**
     * DELETE /api/v1/hr/invitations/{invitation} — withdraw it.
     *
     * The row stays. `revoked_at` is set and the status becomes `revoked`, which
     * is its own word and not `abandoned`: the organization changed its mind, and
     * that is a different fact from the person never turning up. Deleting instead
     * would erase the fact that somebody was invited at all, which is exactly the
     * kind of thing an audit trail is for.
     *
     * The token is NOT left alive. `isOpen()` refuses a revoked invitation, so
     * the link stops working the moment this returns.
     */
    public function destroy(Request $request, Invitation $invitation): JsonResponse
    {
        $this->authorize('delete', $invitation);

        // Idempotent, and the state rules travel with it — see
        // InvitationActions::revoke. A rule withdraws stale invitations on the
        // same path this button does. Converted to the refusal shape this
        // endpoint has always answered with, for the reason given in resend().
        try {
            $this->actions->revoke($invitation, $request->user());
        } catch (CannotActOnInvitation $e) {
            throw ValidationException::withMessages([
                $e->field ?? 'invitation' => $e->getMessage(),
            ]);
        }

        return response()->json(['data' => $this->present($invitation->fresh())]);
    }

    // ── shared ───────────────────────────────────────────────────

    /**
     * Refuse an address that already has an account here, or an outstanding ask.
     *
     * §4: email is unique PER ORGANIZATION, not globally — the same person may
     * hold accounts in several — so this asks about THIS organization only, and
     * the query is tenant-scoped to make that structural rather than a `where`
     * somebody could omit.
     *
     * Two separate messages, because the two have different fixes: one is "they
     * are already here", the other is "you already asked, chase it instead".
     * A single "cannot invite" would send an administrator hunting through the
     * wrong list.
     */
    private function assertNotAlreadyHere(string $email, ?Invitation $except = null): void
    {
        $email = strtolower(trim($email));

        if (User::query()->where('email', $email)->exists()) {
            throw ValidationException::withMessages(['email' => 'Somebody with that address already has an account in this organization. If '
                .'they cannot sign in, a password reset is what they need, not a second '
                .'account.',
            ]);
        }

        $open = Invitation::query()
            ->where('email', $email)
            ->when($except !== null, fn ($q) => $q->whereKeyNot($except->getKey()))
            ->whereNull('accepted_at')
            ->whereNull('revoked_at')
            ->where('expires_at', '>', now())
            ->exists();

        if ($open) {
            throw ValidationException::withMessages(['email' => 'That address already has an invitation waiting. Resend it to give them a '
                .'fresh link, or withdraw it first if the role or the place has changed.',
            ]);
        }
    }

    /** One field pair on the wire, three columns in the table (§4's XOR). */
    private function scopeColumns(string $type, string $id): array
    {
        return [
            'assigned_organization_id' => $type === Invitation::SCOPE_ORGANIZATION ? $id : null,
            'assigned_company_id' => $type === Invitation::SCOPE_COMPANY ? $id : null,
            'assigned_franchise_id' => $type === Invitation::SCOPE_FRANCHISE ? $id : null,
        ];
    }

    /**
     * Send the email, and never let it break the invitation.
     *
     * Outside the transaction on purpose: a mail failure must not roll back an
     * invitation that is perfectly good and whose link is about to be handed
     * back in the response.
     *
     * The try/catch is not defensive habit. InvitationLink is deliberately NOT
     * ShouldQueue — the class says why — so it sends inline on every installation,
     * not only on one where `QUEUE_CONNECTION=sync` happens to collapse the queue.
     * On a box with a real mail transport and a bad credential, the exception
     * surfaces here, as a 500, AFTER the row has committed. The inviter would
     * be told the server broke while a working link existed that they were never
     * shown. Logged and swallowed instead: MailStatus and the Development health
     * screen are where a broken transport is meant to be noticed, and they
     * already look.
     *
     * Swallowed is not the same as unreported. It returns whether the message
     * actually left, because the ONE person who needs to know is the inviter
     * standing in front of the dialog: they either hand the link over themselves
     * or they do not, and that is a different action depending on the answer.
     * The panel used to assert "nothing has been emailed" unconditionally, which
     * was true while nothing could send and became a lie the day something could.
     */
    /**
     * AND WHY IT SENDS AS EZBIZI, which is now a ruling rather than a default.
     *
     * Jose, 2026-09-14, asked directly: "invitations should come from ezbizi,
     * password resets, etc." So this is deliberate and not a gap waiting for
     * somebody to wire up tenant sending.
     *
     * It matters because of the ruling made in the same breath: a business with
     * NO configured sending identity must now be refused rather than silently
     * sent as EzBizi. That is about `MailDispatcher::asTenant`, whose resolver
     * fell through to the platform identity — and the obvious way to implement
     * it is a check applied to all outbound mail, which would break every
     * invitation on every organization that has not configured a domain, which
     * is all of them. This send is not tenant mail and must not acquire that
     * refusal.
     *
     * The line between them is the one this module keeps drawing: content the
     * CUSTOMER authors goes out under their identity or not at all, and mail
     * that IS the mechanism — the invitation link, a password reset, an email
     * verification — is EzBizi's own and always has been. The three notification
     * classes in this app are all the second kind.
     *
     * WHY THIS SEND IS HARDCODED, when Jose has ruled that email belongs in
     * automations — "it should be an option to be enabled on automations, never
     * hardcoded" (2026-09-14, relayed by Training).
     *
     * Because the ruling is about notifications ABOUT an event, and this is not
     * one. The link IS the invitation: it is the only way in, and there is no
     * other channel that produces the same act. An invitation that sends
     * nothing until somebody first builds a rule is a broken feature rather
     * than a configurable one, and the person who would report it is the person
     * who cannot get in.
     *
     * The line, and it is worth keeping in one piece: everything ABOUT an event
     * is automations' to configure; the thing that CONSTITUTES the act is not.
     * The same boundary protects PasswordResetLink and EmailVerificationLink,
     * which are the only other notification classes in the app and are both the
     * mechanism kind. A welcome message on acceptance is on the other side of
     * it and HR deliberately does not send one: `invitation.accepted` is
     * declared and raised, so a company builds that rule itself the day an
     * email action exists.
     */
    private function deliver(Invitation $invitation, string $token): string
    {
        // A handoff can SUCCEED while nothing leaves the building. `log`,
        // `array` and `null` deliver nowhere and throw nothing, so the catch
        // below never fires and this used to return true — the panel then told
        // an administrator the invitation had been emailed, on exactly the
        // demo and staging boxes where somebody tests the flow and concludes
        // it works. `ses` with a blank credential is the same shape.
        //
        // Found by UI/UX (2026-09-14) as a fourth state their vocabulary was
        // missing; they credited this method with already handling it, and it
        // did not. The test below me asserted `emailed: true` under `array`
        // and its comment said "this one genuinely sends", which was the same
        // belief written down twice.
        //
        // Not an exception, because nothing went wrong: the box is configured
        // not to send. It reports the outcome the inviter needs, which is the
        // one they already act on — hand the link over yourself.
        //
        // Asked AFTER the handoff rather than instead of it. The message is
        // still given to whatever transport is configured — that is what makes
        // it observable to a `log` reader and to Notification::fake — and only
        // the CLAIM depends on whether that transport goes anywhere. Skipping
        // the send instead would have been a second behaviour to keep in step
        // and would have made the mailable untestable on this box.
        try {
            Notification::route('mail', $invitation->email)
                ->notify(new InvitationLink($invitation, $this->invitations->acceptUrl($token)));

            return app(MailStatus::class)->delivers()
                ? self::SENT
                : self::NOT_SENT_NO_TRANSPORT;
        } catch (Throwable $e) {
            // The invitation id, never the address and never the link. This line
            // exists to say the transport is broken, and a log is not a place to
            // put a live credential.
            Log::warning('Invitation email could not be sent.', [
                'invitation_id' => $invitation->getKey(),
                'reason' => $e->getMessage(),
            ]);

            return self::NOT_SENT_REFUSED;
        }
    }

    /**
     * @param  string|null  $token  present ONLY on the two responses that have
     *                              just minted one. Everywhere else the raw
     *                              token does not exist any more — it was never
     *                              stored — so there is nothing to leave out.
     */
    private function present(Invitation $invitation, ?string $token = null, ?string $state = null): array
    {
        $body = [
            'id' => $invitation->id,
            'email' => $invitation->email,
            'first_name' => $invitation->first_name,
            'last_name' => $invitation->last_name,
            'status' => $invitation->status(),
            'role' => [
                'id' => $invitation->role_id,
                'name' => $invitation->role?->name,
            ],
            'scope' => [
                'type' => $invitation->scopeType(),
                'id' => $invitation->scopeId(),
                'name' => $invitation->scopeLabel(),
            ],
            'invited_by' => [
                'id' => $invitation->invited_by_user_id,
                'name' => $invitation->invitedBy?->name,
            ],
            'sent_at' => $invitation->sent_at->toIso8601String(),
            // Waiting for the employer's signature before it is emailed.
            'held' => $invitation->isHeld(),
            'expires_at' => $invitation->expires_at->toIso8601String(),
            // The status says WHAT became of it; these say WHEN. Carried on
            // every row rather than only on the one the status names, because a
            // withdrawn invitation that was opened first is two facts about the
            // same person and the screen shows both.
            'opened_at' => $invitation->opened_at?->toIso8601String(),
            'accepted_at' => $invitation->accepted_at?->toIso8601String(),
            'revoked_at' => $invitation->revoked_at?->toIso8601String(),
        ];

        // Beside the link and never without it. `emailed` answers a question
        // only the two endpoints that just tried can answer, and a listing has
        // no business guessing: an invitation row from last week does not know
        // whether its email arrived, and a stale false is what sends somebody
        // chasing a colleague who has had the message all along.
        if ($token !== null) {
            $body['accept_url'] = $this->invitations->acceptUrl($token);

            // `emailed` is kept alongside `sent_state` rather than replaced.
            // An installed client older than this release reads it, and a
            // field that goes absent is the one response change that breaks
            // somebody who never asked for any of this. It costs a line.
            //
            // ── AND `bounced` IS NOT A VALUE OF `sent_state` ──────────
            //
            // Decided now rather than when the bounce table lands, because
            // UI/UX asked the right question: once a bounce can be known,
            // does `emailed: true` become a lie an old client renders as "the
            // invitation has been emailed to them"?
            //
            // It does not, and the reason is the lifetime of these two fields
            // rather than anything about their values. BOTH EXIST ONLY INSIDE
            // THIS BRANCH — on the two responses that have just minted a
            // token. At that instant the message has been handed off and no
            // bounce has happened or could have; SES answers minutes later.
            // So neither field can ever be contradicted by a bounce: they
            // describe the handoff, at the moment of the handoff, and then
            // they are gone. A listing row carries neither, which is also why
            // an old client reading the list says nothing about email at all.
            //
            // Therefore a bounce belongs on the ROW as its own fact, not as a
            // fourth `sent_state`. They answer different questions with
            // different lifetimes: what happened when we tried, versus what
            // became of it afterwards. Folding the second into the first
            // would put a value into a field that only exists on a response
            // which cannot yet know it — and that, not the old client, is
            // where the lie would actually be manufactured.
            $body['emailed'] = $state === null ? null : $state === self::SENT;
            $body['sent_state'] = $state;
        }

        return $body;
    }
}
