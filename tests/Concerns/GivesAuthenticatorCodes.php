<?php

namespace Tests\Concerns;

use App\Models\User;
use App\Support\Tenancy\TenantContext;
use PragmaRX\Google2FA\Google2FA;

/**
 * Every integration disconnect needs a fresh authenticator code
 * (App\Support\Integrations\DisconnectCode). This enrols the user and returns
 * the body field a disconnect sends. Calling it again re-enrols, so the same
 * user can disconnect twice in one test without tripping the replay check.
 */
trait GivesAuthenticatorCodes
{
    /** @return array{code: string} */
    protected function authenticatorCode(User $user): array
    {
        $secret = app(Google2FA::class)->generateSecretKey(32);

        // Through the row, not the instance: a disconnect stamps
        // last_totp_timestamp on the server's copy, so the caller's instance
        // still reads null and forceFill(null) would not be dirty.
        app(TenantContext::class)->run($user->organization, function () use ($user, $secret) {
            $fresh = $user->fresh();
            $fresh->forceFill([
                'totp_secret' => $secret,
                'totp_confirmed_at' => now(),
                'last_totp_timestamp' => null,
            ])->save();
        });

        return ['code' => app(Google2FA::class)->getCurrentOtp($secret)];
    }
}
