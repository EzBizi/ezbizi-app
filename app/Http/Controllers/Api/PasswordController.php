<?php

namespace App\Http\Controllers\Api;

use App\Models\PlatformStaffLink;
use App\Notifications\PasswordResetLink;
use App\Services\Auth\PasswordResets;
use App\Support\Audit\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\RateLimiter;
use Symfony\Component\HttpFoundation\Response;

/**
 * Forgotten and changed passwords (plan §4, §4.2).
 */
class PasswordController extends Controller
{
    /**
     * Long enough to be worth having, short enough that people use it.
     *
     * Deliberately NOT the staff minimum of 16 — that applies to accounts which
     * reach every customer on the platform. This is the ordinary tenant policy,
     * and a length nobody complies with is a length that produces Summer2026!.
     */
    private const MIN_LENGTH = 12;

    public function __construct(
        private readonly PasswordResets $resets,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * POST /api/v1/auth/forgot
     *
     * Always the same answer. Telling a stranger whether an address is
     * registered is a customer list, an employment record, and for a competitor
     * a useful signal — the same reason the login never says which half was
     * wrong (§4).
     */
    public function forgot(Request $request): JsonResponse
    {
        $input = $request->validate(['email' => ['required', 'email']]);

        $key = 'forgot|'.strtolower($input['email']).'|'.$request->ip();

        // Throttled per address AND per IP: without the address in the key, one
        // person forgetting their password would lock out everybody behind the
        // same office NAT.
        if (RateLimiter::tooManyAttempts($key, maxAttempts: 5)) {
            return $this->sameAnswer();
        }

        RateLimiter::hit($key, decaySeconds: 900);

        foreach ($this->resets->subjectsFor($input['email']) as $subject) {
            $issued = $this->resets->issue($subject, $request->ip());

            Notification::route('mail', $subject->email)
                ->notify(new PasswordResetLink($subject, $issued['token'], $issued['expires_at']));

            $this->audit->logError('auth.password_reset_requested', [
                'subject' => class_basename($subject),
                'email'   => $subject->email,
                'ip'      => $request->ip(),
            ]);
        }

        return $this->sameAnswer();
    }

    /** POST /api/v1/auth/reset */
    public function reset(Request $request): JsonResponse
    {
        $input = $request->validate([
            'token'    => ['required', 'string'],
            'password' => ['required', 'string', 'min:'.self::MIN_LENGTH, 'confirmed'],
        ]);

        $subject = $this->resets->redeem($input['token'], $input['password']);

        if ($subject === null) {
            return response()->json([
                'message' => 'That link has expired or has already been used. Ask for a new one.',
                'code'    => 'invalid_token',
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return response()->json([
            'message' => 'Your password has been changed. Sign in with it.',
            // Said plainly, because somebody who has just reset a password
            // expects to be let in and will otherwise think the reset failed.
            'needs_second_factor' => $subject->hasTotp(),
        ]);
    }

    /**
     * POST /api/v1/me/password
     *
     * Changing it while signed in. The current password is required — a session
     * proves somebody sat down at an unlocked laptop, not that they are the
     * account holder.
     */
    public function change(Request $request): JsonResponse
    {
        $input = $request->validate([
            'current_password' => ['required', 'string'],
            'password'         => ['required', 'string', 'min:'.self::MIN_LENGTH, 'confirmed'],
        ]);

        $user = $request->user();

        // A linked account's password lives on the STAFF record (§4.2, revised).
        // Writing it to the user row would change a value nothing reads, and the
        // person would be left believing they had changed their password.
        $subject = PlatformStaffLink::staffFor($user->getKey()) ?? $user;

        if (! Hash::check($input['current_password'], $subject->password)) {
            return response()->json([
                'message' => 'That is not your current password.',
                'code'    => 'wrong_password',
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        if (Hash::check($input['password'], $subject->password)) {
            return response()->json([
                'message' => 'That is the password you already have.',
                'code'    => 'unchanged',
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $this->resets->change(
            $subject,
            $input['password'],
            // This session survives; every other one does not.
            keepTokenId: $subject === $user ? $user->currentAccessToken()?->id : null,
        );

        return response()->json([
            'message' => $subject === $user
                ? 'Password changed. You are still signed in here; other devices are not.'
                : 'Password changed. It covers both the app and the control plane, and every '
                    .'other session has been signed out — including this tab\'s Development section.',
        ]);
    }

    private function sameAnswer(): JsonResponse
    {
        return response()->json([
            'message' => 'If that address has an account, a reset link is on its way. '
                .'It expires in an hour.',
        ]);
    }
}
