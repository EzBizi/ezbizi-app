<?php

namespace App\Http\Controllers\Api;

use App\Services\Auth\AuthenticateUser;
use App\Support\Auth\SessionContext;
use App\Services\Auth\AuthenticationResult;
use App\Support\Audit\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Symfony\Component\HttpFoundation\Response;

/**
 * Sign in and out (plan §4).
 *
 * Sits OUTSIDE auth:sanctum and outside the tenant middleware — there is no
 * user and no organization until this succeeds.
 */
class AuthController extends Controller
{
    public function __construct(
        private readonly AuthenticateUser $authenticate,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * POST /api/v1/auth/login
     *
     * Three outcomes, because one email may exist in several organizations
     * (§4). See AuthenticateUser for why the password is verified BEFORE any
     * organization is named.
     */
    public function login(Request $request): JsonResponse
    {
        $credentials = $request->validate([
            'email'    => ['required', 'email'],
            'password' => ['required', 'string'],
            // Only accounts linked to a staff record need this (§4.2, revised),
            // so it is optional here and demanded by the service if it applies.
            'code'     => ['nullable', 'string'],
        ]);

        // Throttled per email+IP. Without this, the same-email design gives an
        // attacker a way to test one password against several organizations at
        // once, which is strictly better value than a single-tenant login.
        $throttleKey = strtolower($credentials['email']).'|'.$request->ip();

        if (RateLimiter::tooManyAttempts($throttleKey, maxAttempts: 5)) {
            return response()->json([
                'message'     => 'Too many attempts. Try again shortly.',
                'code'        => 'throttled',
                'retry_after' => RateLimiter::availableIn($throttleKey),
            ], Response::HTTP_TOO_MANY_REQUESTS);
        }

        $result = ($this->authenticate)(
            $credentials['email'],
            $credentials['password'],
            $credentials['code'] ?? null,
        );

        if ($result->status === AuthenticationResult::FAILED) {
            RateLimiter::hit($throttleKey, decaySeconds: 300);

            // Deliberately identical whether the address exists or not — the
            // service already equalises timing; this equalises the wording.
            return response()->json([
                'message' => 'Those credentials do not match our records.',
                'code'    => 'invalid_credentials',
            ], Response::HTTP_UNAUTHORIZED);
        }

        if ($result->needsSecondFactor()) {
            // Not a failure and not a success. The password was right; this
            // account carries platform reach, so it needs the authenticator too.
            // The throttle is NOT cleared — an attacker with a stolen password
            // must not get unlimited attempts at the second factor.
            return response()->json([
                'status'  => 'second_factor_required',

                // The reason when there is one — a code that did not verify —
                // and otherwise nothing but the ask. The old text named the
                // control plane on the one screen every customer sees; whether
                // a second system exists is nobody else's business.
                'message' => $result->message
                    ?? 'Enter the code from your authenticator.',
            ]);
        }

        RateLimiter::clear($throttleKey);

        if ($result->isAmbiguous()) {
            // Reachable only AFTER the password verified, so it discloses
            // nothing to someone who merely guessed an address.
            return response()->json([
                'status'  => 'choose_organization',
                'message' => 'This address has accounts in more than one organization.',
                'choices' => $result->choices(),
            ]);
        }

        return $this->issueToken($request, $result);
    }

    /** POST /api/v1/auth/choose — complete an ambiguous login. */
    public function choose(Request $request): JsonResponse
    {
        $input = $request->validate([
            'user_id'  => ['required', 'uuid'],
            'email'    => ['required', 'email'],
            'password' => ['required', 'string'],
            'code'     => ['nullable', 'string'],
        ]);

        // Re-verifies the password: the chosen id comes from the client, so
        // trusting it as proof of the earlier step would let anyone post a user
        // id and skip authentication.
        $result = $this->authenticate->completeChoice(
            $input['user_id'], $input['email'], $input['password'], $input['code'] ?? null,
        );

        if (! $result->succeeded()) {
            return response()->json([
                'message' => 'Those credentials do not match our records.',
                'code'    => 'invalid_credentials',
            ], Response::HTTP_UNAUTHORIZED);
        }

        return $this->issueToken($request, $result);
    }

    /** POST /api/v1/auth/logout — revoke the token that made this request. */
    public function logout(Request $request): JsonResponse
    {
        $request->user()?->currentAccessToken()?->delete();

        $this->audit->log('auth.signed_out', actor: $request->user());

        return response()->json(['message' => 'Signed out.']);
    }

    private function issueToken(Request $request, AuthenticationResult $result): JsonResponse
    {
        $user = $result->user;

        // Named after the device so a user can revoke one client without
        // signing out everywhere.
        $token = $user->createToken(
            $request->input('device_name', 'web'),
            expiresAt: now()->addDays(30),
        );

        // The user agent and address, so the session list can name this row
        // later. Every device sends `device_name: web`, so without this the
        // list is five identical entries and revoking one specific machine is
        // impossible.
        SessionContext::stamp($token->accessToken, $request);

        $this->audit->log('auth.signed_in', actor: $user, metadata: [
            'ip'         => $request->ip(),
            'user_agent' => $request->userAgent(),
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
        $staff = $this->authenticate->staffFor($user);

        $staffToken = null;

        if ($staff !== null) {
            $staffToken = $staff->createToken(
                'staff:'.$request->input('device_name', 'web').' (via CRM)',
                ['staff'],
                expiresAt: now()->addHours(12),
            )->plainTextToken;

            $staff->forceFill(['last_login_at' => now(), 'last_login_ip' => $request->ip()])->save();

            $this->audit->logStaff(
                action: 'staff.signed_in',
                staffId: $staff->getKey(),
                staffLabel: "{$staff->name} <{$staff->email}>",
                metadata: [
                    'ip' => $request->ip(),
                    'via' => 'linked tenant login',
                    'organization' => $user->organization->name,
                ],
            );
        }

        return response()->json([
            'status' => 'authenticated',
            'token'  => $token->plainTextToken,
            // Present only for linked accounts. The client shows the Development
            // section when it is there and hides it when it is not, so the tab
            // cannot appear for somebody who has no staff account behind them.
            'staff_token' => $staffToken,
            'staff' => $staff === null ? null : [
                'id' => $staff->id, 'name' => $staff->name, 'tier' => $staff->tier,
            ],
            'user'   => [
                'id'    => $user->id,
                'name'  => $user->name,
                'email' => $user->email,
            ],
            'organization' => [
                'id'           => $user->organization->id,
                'name'         => $user->organization->name,
                // Surfaced at login so the client can explain the state up
                // front rather than after the first failed write (§13).
                'is_read_only' => $user->organization->isReadOnly(),
            ],
        ]);
    }
}
