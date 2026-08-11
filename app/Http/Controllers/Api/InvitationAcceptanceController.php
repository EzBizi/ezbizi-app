<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\SubscriptionLapsedException;
use App\Models\Assignment;
use App\Models\Invitation;
use App\Models\User;
use App\Services\Auth\IssueSession;
use App\Services\Hr\Invitations;
use App\Support\Access\ScopeReach;
use App\Support\Audit\AuditLogger;
use App\Support\Auth\PasswordPolicy;
use App\Support\Automations\EventEmitter;
use App\Support\Modules\PermissionCatalogue;
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
 * ── Two things are checked AGAIN at acceptance ───────────────────
 *
 * The invitation was bounded by the inviter's reach when it was sent, and a week
 * is long enough for that to change. So both bounds are re-asked here against
 * the inviter as they are NOW: the role they offered, and the place they offered
 * it at. An invitation sent by somebody who has since been demoted — or switched
 * off — is not honoured, because otherwise a demotion leaves a week's worth of
 * grants they are no longer entitled to make.
 *
 * That does mean an invitation can die because the person who sent it left. The
 * trade is deliberate and the refusal says so, so the fix is one click for
 * anybody still there: send a new one.
 */
class InvitationAcceptanceController extends Controller
{
    public function __construct(
        private readonly Invitations $invitations,
        private readonly ScopeReach $reach,
        private readonly PermissionCatalogue $catalogue,
        private readonly AuditLogger $audit,
        private readonly EventEmitter $events,
        private readonly TenantContext $tenant,
        private readonly IssueSession $session,
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
            'role'         => ['name' => $invitation->role?->name],
            'invited_by'   => ['name' => $invitation->invitedBy?->name],

            // What the inviter already filled in, so the form can show it filled
            // in — and so the person can CORRECT it. The plan is explicit about
            // why that matters: an employer pre-filling a date of birth wrongly
            // and the employee fixing it on receipt should be ordinary rather
            // than a support request.
            'email'      => $invitation->email,
            'first_name' => $invitation->first_name,
            'last_name'  => $invitation->last_name,

            'expires_at' => $invitation->expires_at->toIso8601String(),

            // So the acceptance form can state the right minimum instead of
            // guessing the platform floor and being corrected by a 422. The
            // organization may have raised it (§6).
            'password_min_length' => PasswordPolicy::minLengthFor($invitation->organization),
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
            'first_name'    => ['nullable', 'string', 'max:120'],
            'last_name'     => ['nullable', 'string', 'max:120'],
            'phone'         => ['nullable', 'string', 'max:40'],
            'date_of_birth' => ['nullable', 'date'],
            'gender'        => ['nullable', 'string', 'max:40'],
        ]);

        return $this->tenant->run($organization, function () use ($invitation, $input, $request) {
            $refusal = $this->refusalFor($invitation);

            if ($refusal !== null) {
                return $refusal;
            }

            $user = DB::transaction(fn () => $this->createAccount($invitation, $input));

            // Inside the tenant, because it audits the sign-in — an auth event
            // written with no organization is missing from that customer's own
            // history from the first second of the account's life.
            return ($this->session)($request, $user, Response::HTTP_CREATED);
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

        if ($invitation->role === null) {
            return $this->noLongerGrantable();
        }

        $inviter = $invitation->invitedBy;

        // No inviter left, or one who can no longer grant this. Both mean the
        // same thing: nobody currently stands behind what this link offers.
        if ($inviter === null
            || ! $this->catalogue->mayAssignRole($inviter, $invitation->role)
            || ! $this->reach->covers($inviter, $invitation->scopeType(), $invitation->scopeId())) {
            return $this->noLongerGrantable();
        }

        return null;
    }

    private function noLongerGrantable(): JsonResponse
    {
        return response()->json([
            'message' => 'This invitation can no longer be completed — the person who sent it '
                .'is no longer able to grant what it offers. Ask them, or somebody else there, '
                .'to send you a new one.',
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
        $last  = $input['last_name'] ?? $invitation->last_name;

        $user = User::create([
            'organization_id' => $invitation->organization_id,
            'role_id'         => $invitation->role_id,
            // `users.name` is NOT NULL and is what every screen in the app shows.
            // Built from the two halves, and falling back to the part of the
            // address before the @ rather than to an empty string — a blank row
            // in a list is worse than an imperfect one, and they can change it
            // in "Your account" in a second.
            'name'  => trim("{$first} {$last}") ?: Str::before($invitation->email, '@'),
            'email' => $invitation->email,
            'password'  => $input['password'],
            'is_active' => true,

            'first_name'    => $first,
            'last_name'     => $last,
            'phone'         => $input['phone'] ?? null,
            'date_of_birth' => $input['date_of_birth'] ?? null,
            'gender'        => $input['gender'] ?? null,
        ]);

        // §4's XOR, carried straight across: the invitation's three scope columns
        // ARE an assignment's three scope columns, which is why the tables share
        // their shape.
        Assignment::create([
            'organization_id' => $invitation->organization_id,
            'user_id'         => $user->getKey(),
            ...$invitation->assignmentColumns(),
        ]);

        $invitation->forceFill([
            'accepted_at'      => now(),
            'accepted_user_id' => $user->getKey(),
        ])->save();

        // The actor is the new person themselves — nobody is signed in, and
        // attributing their own acceptance to `system` would lose the one fact
        // this entry exists to record.
        $this->audit->log('invitation.accepted', $invitation, metadata: [
            'email' => $invitation->email,
            'role'  => $invitation->role?->name,
            'scope' => ['type' => $invitation->scopeType(), 'name' => $invitation->scopeLabel()],
        ], actor: $user);

        $this->events->emit('user.activated', $user, ['after' => [
            'is_active' => true,
            'role_id'   => $user->role_id,
            'via'       => 'invitation',
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
