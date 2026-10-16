<?php

namespace App\Http\Controllers\Api;

use App\Services\Staff\StaffAuthenticator;
use App\Support\Audit\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Symfony\Component\HttpFoundation\Response;

/**
 * Staff sign-in (plan §4.2).
 *
 * ── Why one request and not a two-step challenge ─────────────────
 *
 * Password and TOTP arrive together. The alternative — POST the password, get a
 * short-lived challenge token, POST the code — is prettier in a UI but adds a
 * credential that, on its own, proves factor one. That token has to be stored,
 * scoped, expired and revoked, and if any of it is wrong the second factor is
 * bypassable. Sending both in one request keeps the number of things that can
 * stand in for a password at exactly one: the password.
 *
 * ponytail: single-request login. Add a challenge-token flow only if the UI
 * genuinely needs the code on a separate screen.
 */
class StaffAuthController extends Controller
{
    public function __construct(
        private readonly StaffAuthenticator $auth,
        private readonly AuditLogger $audit,
    ) {}

    /** POST /api/v1/staff/auth/login */
    public function login(Request $request): JsonResponse
    {
        $input = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
            'code' => ['required', 'string'],
        ]);

        // Tighter than the tenant login (5 per 5 min): fewer accounts exist here
        // and each one reaches every customer, so there is no legitimate reason
        // for a staff address to fail repeatedly.
        $key = 'staff|'.strtolower($input['email']).'|'.$request->ip();

        if (RateLimiter::tooManyAttempts($key, maxAttempts: 5)) {
            return response()->json([
                'message' => 'Too many attempts. Try again shortly.',
                'code' => 'throttled',
                'retry_after' => RateLimiter::availableIn($key),
            ], Response::HTTP_TOO_MANY_REQUESTS);
        }

        $staff = $this->auth->verifyPassword($input['email'], $input['password']);

        if ($staff === null) {
            RateLimiter::hit($key, decaySeconds: 900);

            return $this->rejected($request, $input['email'], 'password');
        }

        // Past this point the password is proven, so a specific message tells the
        // account owner something they already know rather than an attacker
        // something they don't.
        if (! $staff->hasTotp()) {
            return response()->json([
                'message' => 'This account has not completed authenticator setup and cannot sign in yet.',
                'code' => 'totp_enrolment_required',
            ], Response::HTTP_FORBIDDEN);
        }

        if (! $this->auth->verifySecondFactor($staff, $input['code'])) {
            RateLimiter::hit($key, decaySeconds: 900);

            return $this->rejected($request, $input['email'], 'second_factor');
        }

        RateLimiter::clear($key);

        $staff->forceFill([
            'last_login_at' => now(),
            'last_login_ip' => $request->ip(),
        ])->save();

        // 12 hours, against 30 days for tenant users. A stolen staff token is a
        // key to every customer on the platform; its useful life should be a
        // working day, not a month.
        $token = $staff->createToken(
            'staff:'.$request->input('device_name', 'web'),
            ['staff'],
            expiresAt: now()->addHours(12),
        );

        // Sign-in is recorded for EVERY tier. The Lead Developer's exemption
        // covers development noise, not "who opened the control plane and when".
        $this->audit->logStaff(
            action: 'staff.signed_in',
            staffId: $staff->getKey(),
            staffLabel: "{$staff->name} <{$staff->email}>",
            metadata: [
                'ip' => $request->ip(),
                'user_agent' => $request->userAgent(),
                'tier' => $staff->tier,
                'recovery_code_count' => count($staff->recovery_codes ?? []),
            ],
        );

        return response()->json([
            'status' => 'authenticated',
            'token' => $token->plainTextToken,
            'staff' => [
                'id' => $staff->id,
                'name' => $staff->name,
                'email' => $staff->email,
                'tier' => $staff->tier,
            ],
        ]);
    }

    /** POST /api/v1/staff/auth/logout */
    public function logout(Request $request): JsonResponse
    {
        $staff = $request->user();

        $staff->currentAccessToken()?->delete();

        $this->audit->logStaff(
            action: 'staff.signed_out',
            staffId: $staff->getKey(),
            staffLabel: "{$staff->name} <{$staff->email}>",
        );

        return response()->json(['message' => 'Signed out.']);
    }

    /** GET /api/v1/staff/me */
    public function me(Request $request): JsonResponse
    {
        $staff = $request->user();

        return response()->json(['data' => [
            'id' => $staff->id,
            'name' => $staff->name,
            'email' => $staff->email,
            'tier' => $staff->tier,
            'may_enter_without_approval' => $staff->mayEnterWithoutApproval(),
            'recovery_code_count' => count($staff->recovery_codes ?? []),
            'last_login_at' => $staff->last_login_at?->toIso8601String(),
        ]]);
    }

    /**
     * Identical for every failure, so nothing leaks about which factor was wrong.
     *
     * Recorded via logError, which is exempt from the Lead Developer suppression
     * (§4.2) and needs no actor — a failure often has no identified account at
     * all. Successes alone are not a security record: an attacker grinding the
     * control plane would otherwise leave nothing behind but a rate-limiter
     * counter that expires in fifteen minutes.
     */
    private function rejected(Request $request, string $email, string $stage): JsonResponse
    {
        $this->audit->logError('staff.sign_in_failed', [
            'email' => $email,
            'stage' => $stage,   // 'password' or 'second_factor'
            'ip' => $request->ip(),
            'user_agent' => $request->userAgent(),
        ]);

        return response()->json([
            'message' => 'Those credentials do not match our records.',
            'code' => 'invalid_credentials',
        ], Response::HTTP_UNAUTHORIZED);
    }
}
