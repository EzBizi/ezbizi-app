<?php

namespace App\Http\Controllers\Api;

use App\Services\Auth\AuthenticateUser;
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

        $result = ($this->authenticate)($credentials['email'], $credentials['password']);

        if ($result->status === AuthenticationResult::FAILED) {
            RateLimiter::hit($throttleKey, decaySeconds: 300);

            // Deliberately identical whether the address exists or not — the
            // service already equalises timing; this equalises the wording.
            return response()->json([
                'message' => 'Those credentials do not match our records.',
                'code'    => 'invalid_credentials',
            ], Response::HTTP_UNAUTHORIZED);
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
        ]);

        // Re-verifies the password: the chosen id comes from the client, so
        // trusting it as proof of the earlier step would let anyone post a user
        // id and skip authentication.
        $result = $this->authenticate->completeChoice(
            $input['user_id'], $input['email'], $input['password']
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

        $this->audit->log('auth.signed_in', actor: $user, metadata: [
            'ip'         => $request->ip(),
            'user_agent' => $request->userAgent(),
        ]);

        return response()->json([
            'status' => 'authenticated',
            'token'  => $token->plainTextToken,
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
