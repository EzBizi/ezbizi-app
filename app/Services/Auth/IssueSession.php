<?php

namespace App\Services\Auth;

use App\Models\User;
use App\Support\Audit\AuditLogger;
use App\Support\Auth\SessionContext;
use App\Support\Auth\SessionLifetime;
use App\Support\Auth\TwoFactorPolicy;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The session body every successful entry point returns.
 *
 * ── Why a service, and why it returns a JsonResponse ─────────────
 *
 * This was a private method on AuthController, and its own docblock explained
 * why signup lived in that controller rather than its own: "Splitting it out
 * would mean either making that method public or copying it, and a second copy
 * of the session contract is a second thing to keep in step with the client."
 *
 * Accepting an invitation is the third entry point, and it cannot live in
 * AuthController — it is public, unauthenticated, tenant-less, and belongs to
 * the HR module. So the reasoning that kept the method private now argues for
 * moving it out whole: one object, three callers, one contract.
 *
 * It returns a JsonResponse rather than an array because the RESPONSE is what
 * the client is promised — status code included. Signup answers 201 and login
 * 200 with the same keys, and a service handing back an array would leave each
 * caller to remember that, which is exactly the drift this exists to prevent.
 *
 * The one thing that varies is the status. Everything else — the token, its
 * idle window, the device stamp, the audit entry, the staff token for a linked
 * account, the two-factor detour flag, the organization block — is identical by
 * construction, because there is now only one copy of it.
 */
class IssueSession
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly SessionLifetime $lifetime,
        private readonly TwoFactorPolicy $twoFactor,
    ) {}

    /**
     * Must run with the user's organization already in context — it audits the
     * sign-in, and an audit row written with no tenant is missing from that
     * customer's own history.
     */
    public function __invoke(
        Request $request,
        User $user,
        int $status = Response::HTTP_OK,
        bool $chatOnly = false,
    ): JsonResponse {
        // Named after the device so a user can revoke one client without
        // signing out everywhere.
        //
        // `?:` and not `input(..., 'web')`. A default applies only to a MISSING
        // key, and ConvertEmptyStringsToNull turns `"device_name": ""` into
        // null — which reached createToken() and raised a TypeError. On login
        // that was a 500 where a 401 belonged; on signup it was a 500 raised
        // AFTER the tenant transaction had committed, so the caller was told
        // the server broke while a real, reachable organization existed, and
        // retrying built a second one. Resolved here rather than in each
        // caller's validation, because this is the line that cannot accept it.
        $deviceName = trim((string) $request->input('device_name')) ?: 'web';
        $remembered = $request->boolean('remember');
        if ($remembered && ! SessionLifetime::isRemembered($deviceName)) {
            $deviceName .= ':remember';
        }

        /*
         * The idle window, not a flat month (§6 — `security.session_timeout_minutes`).
         *
         * It was `now()->addDays(30)`, which was the only lifetime this app had:
         * the setting labelled "Sign out after inactivity" was read by nothing,
         * so an unattended browser stayed signed in for thirty days.
         *
         * SlideIdleWindow pushes this forward on every request the token makes,
         * so the thirty days survives as the absolute ceiling and the setting
         * decides how long a session may sit untouched inside it.
         */
        $token = $user->createToken(
            $chatOnly ? 'ezchat' : $deviceName,
            // EzChat signed in without an authenticator: chat only (ChatToken).
            $chatOnly ? \App\Support\Auth\ChatToken::ABILITIES : ['*'],
            expiresAt: $this->lifetime->expiryFor($user->organization, now(), $remembered),
        );

        // The user agent and address, so the session list can name this row
        // later. Every device sends `device_name: web`, so without this the
        // list is five identical entries and revoking one specific machine is
        // impossible.
        SessionContext::stamp($token->accessToken, $request);

        $this->audit->log('auth.signed_in', actor: $user, metadata: [
            'ip'         => $request->ip(),
            'user_agent' => $request->userAgent(),
            'app'        => $chatOnly ? 'ezchat' : 'ezbizi',
        ]);

        /*
         * A linked account gets BOTH tokens from one sign-in.
         *
         * Both factors have just been proven against the staff credential set,
         * so making them authenticate again to open the Development tab would be
         * ceremony rather than security. The tokens stay separate — the tenant
         * one still cannot touch /api/v1/staff and the staff one still cannot
         * touch tenant endpoints — because that separation is what keeps a
         * stolen CRM session from being a platform session.
         *
         * The staff token is deliberately shorter-lived (12 hours, as at
         * dev.ezbizi.com) even though the tenant token lasts 30 days.
         */
        return response()->json([
            'status' => 'authenticated',
            'token'  => $token->plainTextToken,
            'staff_token' => null,
            'staff' => null,
            'user'   => [
                'id'    => $user->id,
                'name'  => $user->name,
                'email' => $user->email,
            ],
            // The organization requires a second factor and this account has
            // none (§6). Sent at sign-in, so the client can take somebody
            // straight to enrolment rather than letting them find out by way of
            // a dashboard that answers 403. See RequireTwoFactorEnrolment for
            // why this is a detour and not a refusal.
            'two_factor_enrolment_required' => ! $chatOnly && $this->twoFactor->mustEnrol($user),
            'organization' => [
                'id'           => $user->organization->id,
                'name'         => $user->organization->name,
                // Surfaced at login so the client can explain the state up
                // front rather than after the first failed write (§13).
                'is_read_only' => $user->organization->isReadOnly(),
            ],
        ], $status);
    }
}
