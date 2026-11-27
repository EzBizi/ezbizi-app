<?php

namespace App\Support\Integrations;

use App\Support\Auth\FactorAttempts;
use App\Support\Auth\TotpEnrolment;
use App\Support\Auth\TwoFactorPolicy;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;

/**
 * A fresh authenticator code before any integration is disconnected.
 *
 * Jose, 2026-10-21: "disconnecting an integration should require an
 * authenticator code". The same step-up Monitoring › Files uses
 * (FilesController::unlock): six digits only, so a recovery code is never
 * burned on it; the authenticator the person signs in with (a staff-linked
 * account's factor lives on PlatformStaff); the sign-in attempt budget, so this
 * is not a second place to guess codes. Every disconnect controller calls
 * require() AFTER it has found and authorised the row, so a 404 or 403 still
 * says what it always said.
 *
 * Refusals are 422 with a `code` the client reads to keep the panel open.
 */
final class DisconnectCode
{
    public function __construct(
        private readonly TotpEnrolment $totp,
        private readonly FactorAttempts $attempts,
        private readonly TwoFactorPolicy $twoFactor,
    ) {}

    /** @param  string  $doing  what the code is for, in the refusal ("disconnect", "delete this group") */
    public function require(Request $request, string $doing = 'disconnect'): void
    {
        $subject = $this->twoFactor->subjectFor($request->user());

        if (! $subject->hasTotp()) {
            $this->refuse(
                'Set up an authenticator on your account first (My account › Security). '
                .($doing === 'disconnect' ? 'Disconnecting an integration needs a code from it.' : 'You need a code from it to '.$doing.'.'),
                'no_authenticator',
            );
        }

        $code = trim((string) $request->input('code', ''));

        if (! preg_match('/^\d{6}$/', $code)) {
            $this->refuse('Enter the 6-digit code from your authenticator to '.$doing.'.', 'authenticator_code_required');
        }

        $verified = $this->attempts->verify(
            $subject,
            $code,
            fn () => $this->totp->verify($subject, $code),
        );

        if (! $verified) {
            $this->refuse(
                'That code did not verify. If you just used it, wait for the next one.',
                'authenticator_code_invalid',
            );
        }
    }

    private function refuse(string $message, string $code): never
    {
        throw new HttpResponseException(response()->json([
            'message' => $message,
            'code' => $code,
            'errors' => ['code' => [$message]],
        ], 422));
    }
}
