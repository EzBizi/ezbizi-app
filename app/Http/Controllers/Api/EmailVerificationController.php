<?php

namespace App\Http\Controllers\Api;

use App\Models\User;
use App\Services\Auth\EmailVerifications;
use App\Support\Audit\AuditLogger;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Symfony\Component\HttpFoundation\Response;

/**
 * Confirming the address somebody signed up with (TODO #22).
 *
 * A sibling of PasswordController, and public for the same reason it is: the
 * whole point is to be reachable from a link in a mailbox, by somebody who may
 * not be signed in on the device they opened it on.
 *
 * ── Why the token is in the BODY ─────────────────────────────────
 *
 * routes/api.php records what a token in a URL costs here: nginx logs the
 * request line, so `GET /api/v1/invitations/<token>` wrote every invitation
 * credential into an access log kept for weeks. A POST with the token in the
 * body keeps it out of that log. It is also why Laravel's own MustVerifyEmail
 * is not used — its implementation signs a URL.
 *
 * The message links the CLIENT (`/verify?token=...`), which then posts the
 * token here. That is the same two-step PasswordResetLink uses.
 *
 * Sending lives on EmailVerifications, not here, because signup sends the first
 * message and this sends the rest — one place decides what a verification
 * message is.
 */
class EmailVerificationController extends Controller
{
    public function __construct(
        private readonly EmailVerifications $verifications,
        private readonly TenantContext $tenant,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * POST /api/v1/auth/verify — spend a token, confirm the address.
     *
     * Unlike `forgot`, this does NOT answer identically whatever happened. A
     * verification token is a 48-character random string, so telling its holder
     * it has expired or been used reveals nothing about who exists — and the
     * alternative is a screen saying "something went wrong" to somebody whose
     * only fault was clicking a link on Monday instead of Friday.
     */
    public function verify(Request $request): JsonResponse
    {
        $input = $request->validate(['token' => ['required', 'string']]);

        $user = $this->verifications->redeem($input['token']);

        if ($user === null) {
            return response()->json([
                'message' => 'That confirmation link has expired or has already been used.',
                'code'    => 'verification_token_invalid',
                'detail'  => 'Sign in and ask for another one.',
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        // Inside the tenant, because that is where the account lives and an
        // audit row about a user belongs against their organization.
        $this->tenant->run($user->organization, function () use ($user, $request) {
            $this->audit->logError('auth.email_verified', [
                'user_id' => $user->getKey(),
                'email'   => $user->email,
                'ip'      => $request->ip(),
            ]);
        });

        return response()->json([
            'message' => 'Your address is confirmed.',
            'data'    => ['email_verified_at' => $user->email_verified_at?->toIso8601String()],
        ]);
    }

    /**
     * POST /api/v1/auth/verify/resend — send another link.
     *
     * This one DOES answer identically whatever happened, because it takes an
     * address rather than a token: a different answer for a known address would
     * make it an oracle for who has an account, which is exactly what `forgot`
     * is careful to avoid two files over.
     */
    public function resend(Request $request): JsonResponse
    {
        $input = $request->validate(['email' => ['required', 'email', 'max:255']]);

        $key = 'verify-resend|'.strtolower($input['email']).'|'.$request->ip();

        // Per address AND per IP, the shape `forgot` uses: without the address
        // in the key, one person resending would lock out everybody behind the
        // same office NAT.
        if (RateLimiter::tooManyAttempts($key, maxAttempts: 5)) {
            return $this->sameAnswer();
        }

        RateLimiter::hit($key, decaySeconds: 900);

        // Users are tenant-scoped and nobody is signed in here.
        $users = $this->tenant->runWithoutTenant(
            fn () => User::query()->where('email', $input['email'])->get()
        );

        foreach ($users as $user) {
            // Already confirmed, or deactivated. Sending either would be a
            // message that cannot be acted on.
            if ($user->email_verified_at !== null || ! $user->is_active) {
                continue;
            }

            $this->verifications->issueAndSend($user, $request->ip());
        }

        return $this->sameAnswer();
    }

    private function sameAnswer(): JsonResponse
    {
        return response()->json([
            'message' => 'If that address has an account awaiting confirmation, '
                .'another link is on its way. It expires in 48 hours.',
        ]);
    }
}
