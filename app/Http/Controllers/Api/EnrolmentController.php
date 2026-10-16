<?php

namespace App\Http\Controllers\Api;

use App\Models\PlatformStaff;
use App\Support\Audit\AuditLogger;
use App\Support\Auth\PasswordTiming;
use App\Support\Auth\TotpEnrolment;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use PragmaRX\Google2FA\Google2FA;
use Symfony\Component\HttpFoundation\Response;

/**
 * Setting up a second factor without anybody else present (plan §4.2, §6).
 *
 * ── The problem this solves ──────────────────────────────────────
 *
 * TOTP is mandatory for staff, which means a new staff account cannot sign in
 * until it is enrolled — and therefore cannot enrol itself. Until now that was
 * resolved two ways, both of which need somebody else: a console command, or a
 * Lead Developer sitting with the person reading digits back.
 *
 * Neither scales past the first few people, and both mean the owner has to be
 * available for anyone to get started.
 *
 * ── What this trades, stated plainly ─────────────────────────────
 *
 * An unenrolled staff account is inert today: the password alone is useless.
 * This endpoint makes the password sufficient to BIND an authenticator, at which
 * point the account becomes usable. So for accounts that have never been
 * enrolled, the password is briefly a single factor.
 *
 * That is a real reduction, and it is smaller than the alternative it replaces:
 * the console path needs shell access on the box, but the Lead-Developer path
 * needs nothing an attacker with the password could not also social-engineer,
 * and "email the new person their password" is how these accounts are handed
 * over regardless.
 *
 * What bounds it:
 *
 *   - Only reachable while there is NO confirmed secret. An enrolled account
 *     cannot be re-bound here — that needs the current factor (`/staff/me/totp`)
 *     or a Lead Developer's deliberate reset.
 *   - The session it issues can do nothing except finish enrolling. It carries a
 *     single ability and expires in fifteen minutes.
 *   - Throttled per address, and every attempt is audited whether or not it
 *     succeeds — this is exactly the endpoint somebody would probe.
 */
class EnrolmentController extends Controller
{
    public function __construct(
        private readonly TotpEnrolment $totp,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * POST /api/v1/staff/auth/enrolment
     *
     * Email + password → a QR, and a session that can only confirm it.
     */
    public function begin(Request $request): JsonResponse
    {
        $input = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        $key = 'enrol|'.strtolower($input['email']).'|'.$request->ip();

        if (RateLimiter::tooManyAttempts($key, maxAttempts: 5)) {
            return response()->json([
                'message' => 'Too many attempts. Try again shortly.',
                'code' => 'throttled',
                'retry_after' => RateLimiter::availableIn($key),
            ], Response::HTTP_TOO_MANY_REQUESTS);
        }

        $staff = PlatformStaff::where('email', strtolower($input['email']))->first();

        // Hash even when nobody matched, so a non-existent address costs the same
        // as a wrong password and cannot be enumerated by timing.
        if ($staff === null) {
            PasswordTiming::equalise($input['password']);
            $passwordOk = false;
        } else {
            $passwordOk = Hash::check($input['password'], $staff->password);
        }

        if ($staff === null || ! $passwordOk || ! $staff->is_active) {
            RateLimiter::hit($key, decaySeconds: 900);

            $this->audit->logError('staff.enrolment_refused', [
                'email' => $input['email'],
                'reason' => 'credentials',
                'ip' => $request->ip(),
            ]);

            return $this->rejected();
        }

        if ($staff->hasTotp()) {
            RateLimiter::hit($key, decaySeconds: 900);

            // Identical wording to a bad password. Saying "already enrolled"
            // would confirm the address belongs to a working staff account, and
            // this route is unauthenticated.
            $this->audit->logError('staff.enrolment_refused', [
                'email' => $input['email'],
                'reason' => 'already enrolled',
                'ip' => $request->ip(),
            ]);

            return $this->rejected();
        }

        RateLimiter::clear($key);

        // Reuse a pending secret if one is waiting — an administrator may have
        // created it, and the person may already have scanned it. Generating a
        // fresh one here would invalidate what is on their phone.
        $enrolment = $staff->hasPendingTotp()
            ? [
                'secret' => $staff->pending_totp_secret,
                'uri' => app(Google2FA::class)
                    ->getQRCodeUrl('EzBizi', $staff->email, $staff->pending_totp_secret),
            ]
            : $this->totp->begin($staff, $staff->email);

        $this->audit->logStaff(
            action: 'staff.enrolment_started',
            staffId: $staff->getKey(),
            staffLabel: "{$staff->name} <{$staff->email}>",
            metadata: ['ip' => $request->ip(), 'user_agent' => $request->userAgent()],
        );

        return response()->json([
            'status' => 'enrolment_started',
            // Fifteen minutes and one ability. It cannot read an organization,
            // cannot open a customer, cannot do anything but finish this.
            'token' => $staff->createToken('enrolment', ['enrol'], expiresAt: now()->addMinutes(15))
                ->plainTextToken,
            'enrolment' => $enrolment,
            'account' => ['name' => $staff->name, 'email' => $staff->email],
        ]);
    }

    /**
     * POST /api/v1/staff/auth/enrolment/confirm
     *
     * The enrolment session proves the code and receives the recovery codes.
     */
    public function confirm(Request $request): JsonResponse
    {
        $input = $request->validate(['code' => ['required', 'string']]);

        $staff = $request->user();

        // Both of these used to be bare `abort_unless(..., 403)`, so both
        // rendered "This action is unauthorized." — the framework's words,
        // naming nothing and offering nothing. R8 is about who is reading, and
        // somebody halfway through setting up an authenticator is reading; the
        // staff plane is a screen with a person in front of it like any other,
        // which is why authorizeManagement over in StaffAccountsController has
        // written a sentence since the day it was added.
        abort_unless($staff instanceof PlatformStaff, Response::HTTP_FORBIDDEN,
            'This is the EzBizi staff setup, and you are signed in to a customer '
            .'account. Sign out, then start again from the staff sign-in screen.');

        // The ability is the boundary. Without this an ordinary staff token
        // would reach the same endpoint, which is harmless today but is the kind
        // of thing that stops being harmless when somebody adds a route beside it.
        //
        // Naming the fifteen minutes matters: the commonest way to arrive here
        // is to have left the QR code on screen over lunch, and "start again" is
        // an instruction somebody can follow without knowing what a token is.
        abort_unless($staff->currentAccessToken()?->can('enrol'), Response::HTTP_FORBIDDEN,
            'This step needs the setup session that showed you the QR code, and this '
            .'is not it — it lasts fifteen minutes and may have run out. Start the '
            .'authenticator setup again to get a fresh one.');

        if ($staff->hasTotp()) {
            return response()->json([
                'message' => 'This account already has an authenticator.',
                'code' => 'already_enrolled',
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $recovery = $this->totp->complete($staff, $input['code']);

        if ($recovery === null) {
            return response()->json([
                'message' => 'That code did not verify. Check the clock on the device, then try again.',
                'code' => 'invalid_code',
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        // The enrolment session has done its job. Leaving it alive would be a
        // credential with no remaining purpose, which is the kind that gets
        // forgotten rather than revoked.
        $staff->currentAccessToken()?->delete();

        $this->audit->logStaff(
            action: 'staff.enrolment_completed',
            staffId: $staff->getKey(),
            staffLabel: "{$staff->name} <{$staff->email}>",
            metadata: ['ip' => $request->ip()],
        );

        return response()->json([
            'status' => 'enrolled',
            // Once. Never retrievable again — a code the server can redisplay is
            // a code an attacker can read out of the database.
            'recovery_codes' => $recovery,
        ]);
    }

    private function rejected(): JsonResponse
    {
        return response()->json([
            'message' => 'Those credentials do not match an account that needs setting up.',
            'code' => 'invalid_credentials',
        ], Response::HTTP_UNAUTHORIZED);
    }
}
