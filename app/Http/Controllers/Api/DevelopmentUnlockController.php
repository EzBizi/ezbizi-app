<?php

namespace App\Http\Controllers\Api;

use App\Models\PlatformStaffLink;
use App\Services\Staff\StaffAuthenticator;
use App\Support\Audit\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Symfony\Component\HttpFoundation\Response;

/**
 * Open the Development tab from a CRM session (plan §4.2).
 *
 * A linked login used to mint a twelve-hour staff token up front. When that
 * token lapsed the CRM session was still good, and the tab said "sign in again".
 * The owner asked for the authenticator instead, every time the tab is opened.
 *
 * Lives under /me, not /staff/auth/login: that login is 404 on app.ezbizi.com.
 */
class DevelopmentUnlockController extends Controller
{
    /** Wrong codes per linked account per hour, from any number of IPs. */
    private const PER_ACCOUNT_ATTEMPTS = 10;

    public function __construct(
        private readonly StaffAuthenticator $auth,
        private readonly AuditLogger $audit,
    ) {}

    /** POST /api/v1/me/development/unlock */
    public function store(Request $request): JsonResponse
    {
        $input = $request->validate([
            'code' => ['required', 'string'],
        ]);

        $user = $request->user();
        $key = 'development-unlock|'.$user->getKey().'|'.$request->ip();

        // Per account as well: keyed on the IP too, a stolen CRM session behind
        // rotating addresses had unlimited guesses at the staff authenticator.
        $accountKey = 'development-unlock-account|'.$user->getKey();

        foreach ([$key => 5, $accountKey => self::PER_ACCOUNT_ATTEMPTS] as $limiter => $max) {
            if (RateLimiter::tooManyAttempts($limiter, maxAttempts: $max)) {
                return response()->json([
                    'message' => 'Too many attempts. Try again shortly.',
                    'code' => 'throttled',
                    'retry_after' => RateLimiter::availableIn($limiter),
                ], Response::HTTP_TOO_MANY_REQUESTS);
            }
        }

        $staff = PlatformStaffLink::staffFor($user->getKey());

        if ($staff === null) {
            RateLimiter::hit($key, decaySeconds: 900);

            return response()->json([
                'message' => 'This account does not reach the control plane.',
            ], Response::HTTP_FORBIDDEN);
        }

        if (! $staff->hasTotp()) {
            return response()->json([
                'message' => 'This account has not completed authenticator setup and cannot open Development yet.',
                'code' => 'totp_enrolment_required',
            ], Response::HTTP_FORBIDDEN);
        }

        if (! $this->auth->verifySecondFactor($staff, $input['code'])) {
            RateLimiter::hit($key, decaySeconds: 900);
            RateLimiter::hit($accountKey, decaySeconds: 3600);

            // The alert: one entry in the security record the moment the
            // account limit is reached, which is what someone grinding from
            // many addresses looks like.
            if (RateLimiter::attempts($accountKey) === self::PER_ACCOUNT_ATTEMPTS) {
                $this->audit->logError('staff.development_unlock_throttled', [
                    'email' => $staff->email,
                    'ip' => $request->ip(),
                    'user_agent' => $request->userAgent(),
                ]);
            }

            $this->audit->logError('staff.sign_in_failed', [
                'email' => $staff->email,
                'stage' => 'second_factor',
                'via' => 'development tab',
                'ip' => $request->ip(),
                'user_agent' => $request->userAgent(),
            ]);

            return response()->json([
                'message' => 'That code is not right. Try again.',
            ], Response::HTTP_UNAUTHORIZED);
        }

        RateLimiter::clear($key);
        RateLimiter::clear($accountKey);

        $staff->forceFill([
            'last_login_at' => now(),
            'last_login_ip' => $request->ip(),
        ])->save();

        $token = $staff->createToken(
            'staff:web (via Development tab)',
            ['staff'],
            expiresAt: now()->addHours(12),
        );

        $this->audit->logStaff(
            action: 'staff.signed_in',
            staffId: $staff->getKey(),
            staffLabel: "{$staff->name} <{$staff->email}>",
            metadata: [
                'ip' => $request->ip(),
                'via' => 'development tab totp',
                'organization' => $user->organization->name,
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
}
