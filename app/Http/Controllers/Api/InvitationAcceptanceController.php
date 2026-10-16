<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\SubscriptionLapsedException;
use App\Models\Assignment;
use App\Models\EmploymentEvent;
use App\Models\Invitation;
use App\Models\User;
use App\Services\Auth\IssueSession;
use App\Services\Hr\Invitations;
use App\Support\Audit\AuditLogger;
use App\Support\Auth\PasswordPolicy;
use App\Support\Automations\EventEmitter;
use App\Support\Modules\PermissionCatalogue;
use App\Support\Settings\DisplaySettings;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Accepting an invitation — public, unauthenticated, and the security surface.
 *
 * ── Every dead token answers the same way ────────────────────────
 *
 * Wrong, expired, withdrawn, already used: one status, one body, one sentence.
 * Not a courtesy — telling a stranger that a token was "already used" tells them
 * the address they guessed belongs to a real employee of a real organization,
 * and telling them it "expired" says an organization exists that once wanted
 * them. Invitations::open() answers null for all four so this controller cannot
 * distinguish them even by accident.
 *
 * Nothing here reveals whether an organization exists. The GET names one only
 * AFTER a live token has proved the reader was invited to it, and it names
 * nothing else about it — not its id, not its slug, not its size.
 *
 * ── Tenancy has to be established by hand ────────────────────────
 *
 * These routes are outside `auth:sanctum` and outside the tenant middleware,
 * because there is no user and no session — the token is the only thing that
 * names an organization. TenantScope now THROWS with no tenant set, so every
 * read runs through `runWithoutTenant()` (inside the service) and every WRITE
 * runs inside `TenantContext::run()`, which is what AuthController::signup does
 * and for the reason recorded there: `set()` leaves the tenant in place for
 * whatever the process handles next, and platform scope skips both the tenant
 * stamp and the §13 subscription floor.
 *
 * ── An invitation belongs to the ORGANIZATION ────────────────────
 *
 * Decided 2026-08-11, correcting the first implementation of this file.
 *
 * Both bounds used to be re-asked here against the INVITER as they are now:
 * could that person still grant this role, and does their reach still cover this
 * place. The reasoning was that a demotion should not leave a week's worth of
 * grants behind it. What it actually built was an invitation that died when the
 * manager who sent it changed jobs — a new starter turned away at the door
 * because of a reorganisation on the other side of a business they had not
 * joined yet, holding a link nobody had withdrawn.
 *
 * The bound belongs where the authority does. Reach and role assignability are
 * checked at SENDING, by HrInvitationsController, against the person choosing —
 * that is where §4's escalation rule bites, and it is the moment somebody is
 * actually exercising authority. What survives them leaving is the
 * organization's decision, not their personal standing.
 *
 * So what is re-asked here is only what the ORGANIZATION can still offer:
 *
 *   - the role still exists, and the platform has not switched off everything
 *     behind it (§13)
 *   - the place still exists and is still switched on
 *
 * Whether the individual who sent it is still there, or could still send
 * another, is not the question. `invited_by_user_id` remains nullable and the
 * acceptance screen still names them where it can, because "who asked me here"
 * is worth showing — it is simply no longer a condition of getting in.
 */
class InvitationAcceptanceController extends Controller
{
    public function __construct(
        private readonly Invitations $invitations,
        // No ScopeReach here any more. Reach is the INVITER's bound and it is
        // checked where they exercise it — at sending, in
        // HrInvitationsController. See the note on this class about who an
        // invitation belongs to.
        private readonly PermissionCatalogue $catalogue,
        private readonly AuditLogger $audit,
        private readonly EventEmitter $events,
        private readonly TenantContext $tenant,
        private readonly IssueSession $session,
        private readonly DisplaySettings $display,
    ) {}

    /**
     * GET /api/v1/invitations/{token}
     *
     * What this invitation is for, and — as a side effect — the moment it counts
     * as `received`. Opening the link IS the receipt; there is nothing else the
     * server can observe, since no mail is delivered and no pixel is loaded.
     *
     * `{token}` is a plain string parameter and not a bound model, deliberately.
     * Route model binding would run a tenant-scoped read with no tenant, and the
     * token is not the primary key anyway — it is a credential, and looking one
     * up is this controller's job rather than the router's.
     */
    public function show(Request $request): JsonResponse
    {
        // From the body, not the path — nginx logs request lines, so a token in
        // the URL was a credential written to disk for a fortnight. See the
        // route group's note in routes/api.php.
        $token = (string) $request->validate([
            'token' => ['required', 'string', 'max:255'],
        ])['token'];

        $invitation = $this->invitations->open($token);

        if ($invitation === null) {
            return $this->refuse();
        }

        $this->tenant->run($invitation->organization, function () use ($invitation) {
            // Only the FIRST open. "Received" is a fact about when they saw it,
            // and a reload should not keep moving it forward.
            if ($invitation->opened_at !== null) {
                return;
            }

            try {
                $invitation->forceFill(['opened_at' => now()])->save();
            } catch (SubscriptionLapsedException) {
                /*
                 * A lapsed organization (§13) still gets to show this page.
                 *
                 * Stamping the receipt is a write, and the subscription floor
                 * refuses every tenant-owned write — so without this catch, a
                 * READ endpoint answers 402 and a brand-new employee is shown a
                 * payment error about a company they do not work for yet. The
                 * POST below is a real business write and is left to 402
                 * properly, which is where §13's refusal belongs.
                 *
                 * Caught by its own type, not by Throwable: a cross-tenant
                 * write here would be a bug and must still surface as a 500.
                 */
                return;
            }

            // No actor: there is nobody signed in, and the person opening the
            // link has no account yet. `system` is the honest attribution.
            $this->audit->log('invitation.received', $invitation, metadata: [
                'email' => $invitation->email,
            ]);
        });

        return response()->json(['data' => [
            // The name, and nothing else about the organization. Somebody
            // holding a live token is entitled to know who is asking; they are
            // not entitled to its id, its slug or anything they could use to
            // address it elsewhere on the platform.
            'organization' => ['name' => $invitation->organization->name],
            'role' => ['name' => $invitation->role?->name],
            'invited_by' => ['name' => $invitation->invitedBy?->name],

            // What the inviter already filled in, so the form can show it filled
            // in — and so the person can CORRECT it. The plan is explicit about
            // why that matters: an employer pre-filling a date of birth wrongly
            // and the employee fixing it on receipt should be ordinary rather
            // than a support request.
            'email' => $invitation->email,
            'first_name' => $invitation->first_name,
            'last_name' => $invitation->last_name,

            'expires_at' => $invitation->expires_at->toIso8601String(),

            // So the acceptance form can state the right minimum instead of
            // guessing the platform floor and being corrected by a 422. The
            // organization may have raised it (§6).
            'password_min_length' => PasswordPolicy::minLengthFor($invitation->organization),

            // Date/time format for the form on this page — the same resolved
            // pair /me would send after they join. Without it the date of
            // birth boxes would guess month-first, and an organization that
            // set D/M/YYYY would collect 3/8 as the wrong day.
            'display' => $this->tenant->run(
                $invitation->organization,
                fn () => $this->display->forScope($invitation->organization),
            ),
        ]]);
    }

    /**
     * POST /api/v1/invitations/{token}
     *
     * Creates the account, the assignment and the role link, and answers with
     * exactly what `login` answers — so the client can treat acceptance as a
     * sign-in rather than bouncing somebody to a form they have just proved they
     * do not need. Same body, built by the same object (IssueSession).
     */
    public function accept(Request $request): JsonResponse
    {
        $token = (string) $request->validate([
            'token' => ['required', 'string', 'max:255'],
        ])['token'];

        $invitation = $this->invitations->open($token);

        if ($invitation === null) {
            return $this->refuse();
        }

        $organization = $invitation->organization;

        $input = $request->validate([
            // The organization's minimum, which may be higher than the platform
            // floor and can never be lower (§6). Reachable here because the
            // token has already said which organization this is.
            //
            // No `confirmed`: the acceptance form asks once. The rule belongs at
            // the call site rather than in PasswordPolicy for exactly this
            // reason — see its docblock.
            'password' => PasswordPolicy::rulesFor($organization),

            // The common core, minus what the invitation already carries. Every
            // one optional: somebody should be able to get in with a password
            // and fill the rest of their profile afterwards, and "Your account"
            // is where that lives (the plan's one-home rule).
            'first_name' => ['nullable', 'string', 'max:60'],
            'last_name' => ['nullable', 'string', 'max:60'],
            'phone' => ['nullable', 'string', 'max:40'],
            'address' => ['nullable', 'string', 'max:500'],
            'date_of_birth' => ['nullable', 'date'],
        ]);

        return $this->tenant->run($organization, function () use ($invitation, $input, $request) {
            return DB::transaction(function () use ($invitation, $input, $request) {
                $locked = Invitation::query()
                    ->with(['organization', 'role'])
                    ->whereKey($invitation->getKey())
                    ->lockForUpdate()
                    ->first();

                if ($locked === null || ! $locked->isOpen()) {
                    return $this->refuse();
                }

                $refusal = $this->refusalFor($locked);

                if ($refusal !== null) {
                    return $refusal;
                }

                $user = $this->createAccount($locked, $input);

                // Inside the tenant, because it audits the sign-in — an auth event
                // written with no organization is missing from that customer's own
                // history from the first second of the account's life.
                return ($this->session)($request, $user, Response::HTTP_CREATED);
            });
        });
    }

    // ── shared ───────────────────────────────────────────────────

    /**
     * The refusals that need a real sentence, checked inside the tenant.
     *
     * Distinct from the blanket 404 above, and that is safe: every one of them
     * is reachable only with a LIVE token, so there is nobody to leak to who was
     * not already invited. What would be unsafe is the reverse — collapsing
     * these into "invalid link" and leaving somebody staring at a page that will
     * never work for a reason nobody can discover.
     *
     * @return JsonResponse|null null when acceptance may proceed
     */
    private function refusalFor(Invitation $invitation): ?JsonResponse
    {
        /*
         * §4: email is unique PER ORGANIZATION, not globally.
         *
         * So the same person genuinely may hold accounts in several — this query
         * is tenant-scoped and asks about THIS one only. What it catches is the
         * real case: somebody was invited, an administrator created them another
         * way in the meantime, and the second account would be a duplicate
         * identity in one organization that the unique index would refuse as a
         * 500 anyway.
         */
        if (User::query()->where('email', $invitation->email)->exists()) {
            return response()->json([
                'message' => 'There is already an account with this address at '
                    .$invitation->organization->name.'. Sign in with it instead — and if you '
                    .'cannot, ask for a password reset rather than a second account.',
                'code' => 'already_a_member',
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        /*
         * The role, as the ORGANIZATION can offer it today.
         *
         * Gone entirely: `invitations.role_id` is ON DELETE SET NULL, and
         * RolesController refuses to delete a role with an outstanding
         * invitation — so this is reachable only by a path that goes around the
         * API, and it is still checked, because the alternative is an account
         * created with no role at all.
         *
         * Emptied by the platform: §13 switched off every module behind it. See
         * PermissionCatalogue::platformHasWithdrawnEverything for why that is
         * the line rather than "any permission is unavailable".
         */
        if ($invitation->role === null
            || $this->catalogue->platformHasWithdrawnEverything($invitation->role)) {
            return $this->refuseWith(
                'This invitation can no longer be completed — the role it offers is not '
                .'something '.$invitation->organization->name.' can give out any more. Ask '
                .'somebody there to send you a new one.',
            );
        }

        // And the place. A franchise that has been switched off or removed since
        // the link was sent would give somebody an account whose only assignment
        // is somewhere nobody works.
        if (! $invitation->scopeIsLive()) {
            return $this->refuseWith(
                'This invitation can no longer be completed — the '.$invitation->scopeType()
                .' it was for has been closed or switched off. Ask somebody at '
                .$invitation->organization->name.' to send you a new one.',
            );
        }

        return null;
    }

    /**
     * One code, two sentences.
     *
     * `invitation_superseded` is what the client keys off, and both refusals are
     * the same thing to it: the link is real, it cannot be completed, and a new
     * one is the fix. The MESSAGE differs because the two send somebody to ask
     * about different things — a role and a place — and one generic sentence
     * would have them describing the wrong problem to whoever picks up.
     */
    private function refuseWith(string $message): JsonResponse
    {
        return response()->json([
            'message' => $message,
            'code' => 'invitation_superseded',
        ], Response::HTTP_UNPROCESSABLE_ENTITY);
    }

    /**
     * The account, its place, and the invitation closed — one transaction.
     *
     * All three or none. An account with no assignment can sign in and reach
     * nothing; an assignment with no account is a dangling row; and an
     * invitation left open after the account exists is a second token for an
     * identity that already has a password.
     */
    private function createAccount(Invitation $invitation, array $input): User
    {
        $first = $input['first_name'] ?? $invitation->first_name;
        $last = $input['last_name'] ?? $invitation->last_name;

        $user = User::create([
            'organization_id' => $invitation->organization_id,
            'role_id' => $invitation->role_id,
            // `users.name` is NOT NULL and is what every screen in the app shows.
            // Built from the two halves, and falling back to the part of the
            // address before the @ rather than to an empty string — a blank row
            // in a list is worse than an imperfect one, and they can change it
            // in "Your account" in a second.
            'name' => trim("{$first} {$last}") ?: Str::before($invitation->email, '@'),
            'email' => $invitation->email,
            'password' => $input['password'],
            'is_active' => true,

            'first_name' => $first,
            'last_name' => $last,
            'phone' => $input['phone'] ?? null,
            'address' => $input['address'] ?? null,
            'date_of_birth' => $input['date_of_birth'] ?? null,
        ]);

        /*
         * Verified on arrival, and not as a shortcut (TODO #22).
         *
         * The invitation was SENT to this address, and the link inside it is
         * single use. Redeeming it IS proof of control of that mailbox - the
         * same proof a confirmation email asks for, already furnished. Asking
         * again would be asking somebody to prove twice, by email, that they can
         * read the email we just sent them.
         *
         * Leaving it null would be worse than pointless: RequireVerifiedEmail
         * refuses writes to an unverified account, so every invited employee
         * would sign in successfully and then be unable to enter anything, told
         * to check a confirmation message nobody ever sent them. Signup is the
         * only route with something left to prove, because nothing was sent to
         * that address first.
         *
         * forceFill rather than a key in the create() above: `email_verified_at`
         * is deliberately absent from the model's #[Fillable] list, because a
         * verification state must never be settable by mass assignment - see the
         * note in routes/api.php beside the profile PATCH. Passing it to create()
         * is silently discarded, which is exactly how this would have shipped
         * looking correct and doing nothing.
         */
        $user->forceFill(['email_verified_at' => now()])->save();

        EmploymentEvent::create([
            'organization_id' => $user->organization_id,
            'user_id' => $user->getKey(),
            'kind' => EmploymentEvent::STARTED,
            'occurred_on' => now()->toDateString(),
        ]);

        // §4's XOR, carried straight across: the invitation's three scope columns
        // ARE an assignment's three scope columns, which is why the tables share
        // their shape.
        Assignment::create([
            'organization_id' => $invitation->organization_id,
            'user_id' => $user->getKey(),
            ...$invitation->assignmentColumns(),
        ]);

        $invitation->forceFill([
            'accepted_at' => now(),
            'accepted_user_id' => $user->getKey(),
        ])->save();

        /*
         * And any onboarding already waiting on this invitation now has its
         * person. Without this it never gets one, and can never be finished.
         *
         * HrOnboardingsController::create sets `user_id` from
         * `$invitation->accepted_user_id`, which is null in the flow its own
         * comment calls the ordinary case — the employer starts the paperwork
         * and pre-fills it before the new starter has an account. Nothing then
         * back-filled it. Proven end to end: after acceptance,
         * `onboardings.user_id` was still null, so the employee could not be
         * identified as the subject, could not reach their own form, and could
         * not sign — a dead end reached by following the documented path.
         *
         * Here rather than in the HR controller because this is the moment the
         * account comes into existence, and a fact is written where it becomes
         * true.
         */
        $invitation->onboarding()
            ->whereNull('user_id')
            ->update(['user_id' => $user->getKey()]);

        // The actor is the new person themselves — nobody is signed in, and
        // attributing their own acceptance to `system` would lose the one fact
        // this entry exists to record.
        $this->audit->log('invitation.accepted', $invitation, metadata: [
            'email' => $invitation->email,
            'role' => $invitation->role?->name,
            'scope' => ['type' => $invitation->scopeType(), 'name' => $invitation->scopeLabel()],
        ], actor: $user);

        $this->events->emit('user.activated', $user, ['after' => [
            'is_active' => true,
            'role_id' => $user->role_id,
            'via' => 'invitation',
        ]], actor: $user);

        /*
         * And the invitation's own event, which carries the invitation.
         *
         * `user.activated` is about the account and is raised two ways — this,
         * and somebody switching a deactivated person back on — so a rule built
         * on it cannot mean "a new starter arrived" without a condition. This
         * one means exactly that and nothing else, and it carries the record
         * that knows which franchise they were invited into, which is what lets
         * a branch-level rule match. See HrInvitationsController::store for the
         * same pairing on the way out.
         *
         * The actor is the new person, for the reason the audit entry above
         * gives: nobody is signed in, and attributing their own acceptance to
         * `system` loses the one fact worth recording.
         */
        $this->events->emit('invitation.accepted', $invitation, ['after' => [
            'status' => $invitation->status(),
            'email' => $invitation->email,
            'role' => $invitation->role?->name,
            'role_id' => $invitation->role_id,
            'scope_type' => $invitation->scopeType(),
            'scope_id' => $invitation->scopeId(),
            'scope' => $invitation->scopeLabel(),
            'accepted_user_id' => $user->getKey(),
        ]], actor: $user);

        return $user;
    }

    /**
     * The one answer every dead token gets.
     *
     * 404 rather than 422: the resource named by that token does not exist as
     * far as this caller is concerned, and that is true of all four ways it can
     * be dead. The wording lists the possibilities without saying which — the
     * same shape the password reset uses, and for the same reason.
     */
    private function refuse(): JsonResponse
    {
        return response()->json([
            'message' => 'That invitation link is not valid. It may have expired, been used '
                .'already, or been withdrawn. Ask whoever invited you to send a new one.',
            'code' => 'invalid_invitation',
        ], Response::HTTP_NOT_FOUND);
    }
}
