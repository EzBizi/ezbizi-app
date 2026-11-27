<?php

namespace App\Support\Auth;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use PragmaRX\Google2FA\Google2FA;

/**
 * Issuing and proving a second factor, for anybody who can hold one (§4.2, §6).
 *
 * Generalised out of StaffAuthenticator when tenant users gained TOTP. The rules
 * below are the ones that were learned the hard way and must not be re-derived
 * per account type:
 *
 *   - A new secret is PENDING until a code proves it. A mis-scan that took effect
 *     immediately would lock the account out with no way back.
 *   - Rotating keeps the working secret alive until the replacement is confirmed.
 *     Re-enrolling somebody else revokes theirs at once, because that path exists
 *     when a phone is lost or suspect — opposite situations, opposite defaults.
 *   - Each code is accepted ONCE. verifyKeyNewer refuses any step at or before the
 *     last one used, so six digits overheard cannot be replayed for the remaining
 *     minute and a half they would otherwise be valid.
 *   - Confirmation is measured against 0, not the live counter: the pending secret
 *     has never been used, and sharing the counter rejects a correct code that
 *     happens to land in the same 30-second step as whatever preceded it.
 */
class TotpEnrolment
{
    public function __construct(
        private readonly Google2FA $totp,
    ) {}

    /**
     * @param  bool  $revokeExisting  true when re-enrolling someone whose current
     *                                factor is gone or suspect; false when they
     *                                are simply moving to a new phone.
     * @return array{secret: string, uri: string}
     */
    public function begin(Model $account, string $label, bool $revokeExisting = true): array
    {
        $secret = $this->totp->generateSecretKey(32);

        $account->pending_totp_secret = $secret;

        if ($revokeExisting) {
            $account->totp_secret = null;
            $account->totp_confirmed_at = null;
        }

        $account->save();

        return [
            'secret' => $secret,
            'uri' => $this->totp->getQRCodeUrl('EzBizi', $label, $secret),
        ];
    }

    /**
     * Prove the pending secret and promote it.
     *
     * Returns recovery codes ONCE. Any previously issued set is replaced: the old
     * ones were minted against a secret no longer in use, and leaving them live
     * would mean a rotation that changes the phone but not what an old leaked
     * list can open.
     *
     * @return string[]|null null when the code did not verify
     */
    public function complete(Model $account, string $code): ?array
    {
        if ($account->pending_totp_secret === null) {
            return null;
        }

        $timestamp = $this->totp->verifyKeyNewer(
            $account->pending_totp_secret,
            trim($code),
            0,   // see the class docblock — not the live counter
            window: 1,
        );

        if ($timestamp === false) {
            return null;
        }

        $recovery = collect(range(1, 8))
            ->map(fn () => strtoupper(Str::random(5).'-'.Str::random(5)))
            ->all();

        $account->totp_secret = $account->pending_totp_secret;
        $account->pending_totp_secret = null;
        $account->totp_confirmed_at = now();
        $account->recovery_codes = $recovery;
        // The confirming code was spoken aloud or typed in front of somebody.
        // Burning its step stops it being turned straight around into a sign-in.
        $account->last_totp_timestamp = $timestamp;
        $account->save();

        return $recovery;
    }

    /**
     * Check a code, or a recovery code, against a live secret.
     *
     * `window: 1` accepts the adjacent 30-second steps, which covers ordinary
     * clock drift between the phone and the server. Wider windows trade real
     * security for a problem better solved by fixing the clock.
     */
    public function verify(Model $account, string $code): bool
    {
        if (! $account->hasTotp()) {
            return false;
        }

        $code = trim($code);

        // Recovery codes are longer and contain letters; TOTP is six digits.
        if (! preg_match('/^\d{6}$/', $code)) {
            return $account->consumeRecoveryCode($code);
        }

        $timestamp = $this->totp->verifyKeyNewer(
            $account->totp_secret,
            $code,
            // 0, never null. With null the library returns a bare `true` rather
            // than the step it matched, and storing that records step 1 — after
            // which every code counts as newer and the replay check does nothing.
            $account->last_totp_timestamp ?? 0,
            window: 1,
        );

        if ($timestamp === false) {
            return false;
        }

        // Compare-and-set: two requests racing with one code get one yes.
        return $account->claimTotpStep((int) $timestamp);
    }

    /** Switch it off entirely, and take the recovery codes with it. */
    public function disable(Model $account): void
    {
        $account->forceFill([
            'totp_secret' => null,
            'pending_totp_secret' => null,
            'totp_confirmed_at' => null,
            'recovery_codes' => null,
            'last_totp_timestamp' => null,
        ])->save();
    }
}
