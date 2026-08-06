<?php

namespace App\Services\Staff;

use App\Models\PlatformStaff;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use PragmaRX\Google2FA\Google2FA;

/**
 * Staff sign-in (plan §4.2).
 *
 * Two factors, always. Unlike tenant users — where the organization decides
 * whether to require TOTP — staff have no choice: these accounts reach every
 * customer's data on the platform, and the Lead Developer tier is deliberately
 * outside the ordinary audit trail. Password-only on that combination would be
 * the weakest link in the entire system.
 */
class StaffAuthenticator
{
    public function __construct(
        private readonly Google2FA $totp,
    ) {}

    /**
     * Step one: password.
     *
     * Deliberately does NOT sign anyone in. It returns the account only so step
     * two can challenge for TOTP. Splitting the steps is what makes the second
     * factor real — a single call that accepts both would let a caller omit the
     * code and still succeed if the check were ever mis-ordered.
     */
    public function verifyPassword(string $email, string $password): ?PlatformStaff
    {
        $staff = PlatformStaff::where('email', $email)->first();

        // Hash even when nobody matched, so a non-existent address costs the
        // same as a wrong password and cannot be enumerated by timing.
        if ($staff === null) {
            Hash::check($password, Hash::make('timing-equalisation'));

            return null;
        }

        if (! Hash::check($password, $staff->password) || ! $staff->is_active) {
            return null;
        }

        return $staff;
    }

    /**
     * Step two: the TOTP code, or a recovery code.
     *
     * `window: 1` accepts the adjacent 30-second steps, which covers ordinary
     * clock drift between the phone and the server. Wider windows trade real
     * security for a problem better solved by fixing the clock.
     */
    public function verifySecondFactor(PlatformStaff $staff, string $code): bool
    {
        if (! $staff->hasTotp()) {
            return false;
        }

        $code = trim($code);

        // Recovery codes are longer and contain letters; TOTP is six digits.
        if (! preg_match('/^\d{6}$/', $code)) {
            return $staff->consumeRecoveryCode($code);
        }

        return $this->totp->verifyKey($staff->totp_secret, $code, window: 1);
    }

    // ── enrolment ────────────────────────────────────────────────

    /**
     * Begin TOTP setup. The secret is stored but NOT confirmed, so the account
     * still cannot authenticate — proving the code works is what completes it.
     * Otherwise a mistyped setup locks the account out permanently.
     */
    public function beginEnrolment(PlatformStaff $staff): array
    {
        $secret = $this->totp->generateSecretKey(32);

        $staff->totp_secret = $secret;
        $staff->totp_confirmed_at = null;
        $staff->save();

        return [
            'secret' => $secret,
            'uri'    => $this->totp->getQRCodeUrl('EzBizi', $staff->email, $secret),
        ];
    }

    /**
     * Finish enrolment by proving the authenticator works.
     *
     * Returns recovery codes ONCE. They are stored hashed-at-rest via the
     * model's encrypted cast and never shown again — a code the server can
     * redisplay is a code an attacker can read out of the database.
     */
    public function completeEnrolment(PlatformStaff $staff, string $code): ?array
    {
        if ($staff->totp_secret === null) {
            return null;
        }

        if (! $this->totp->verifyKey($staff->totp_secret, trim($code), window: 1)) {
            return null;
        }

        $recovery = collect(range(1, 8))
            ->map(fn () => strtoupper(Str::random(5).'-'.Str::random(5)))
            ->all();

        $staff->totp_confirmed_at = now();
        $staff->recovery_codes = $recovery;
        $staff->save();

        return $recovery;
    }
}
