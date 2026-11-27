<?php

namespace App\Services\Staff;

use App\Models\PlatformStaff;
use App\Support\Auth\PasswordTiming;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use PragmaRX\Google2FA\Google2FA;

/**
 * Staff sign-in (plan §4.2).
 *
 * Two factors, always. Unlike tenant users — where the organization decides
 * whether to require TOTP — staff have no choice: these accounts reach every
 * customer's data on the platform, and one of them — the account holding
 * `audit_exempt` (§4.2) — is deliberately outside the ordinary audit trail. It
 * is a flag on one account rather than a property of the Lead Developer tier,
 * but the reasoning is unchanged: password-only on that combination would be
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
        // PasswordTiming reuses one dummy hash; Hash::make on every miss was
        // slower than a real check and became the oracle it was meant to close.
        if ($staff === null) {
            PasswordTiming::equalise($password);

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
     *
     * Each code is accepted ONCE. `verifyKeyNewer` refuses any time-step at or
     * before the last one this account used, so six digits overheard — during the
     * enrolment hand-off, in a screenshot, through a phishing proxy — cannot be
     * replayed for the remaining minute and a half they would otherwise be valid.
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

        $timestamp = $this->totp->verifyKeyNewer(
            $staff->totp_secret,
            $code,
            // 0, never null. With null the library returns a bare `true` instead
            // of the time-slice it matched, and storing that as the "last used
            // step" records step 1 — after which every subsequent code counts as
            // newer and the replay check silently does nothing.
            $staff->last_totp_timestamp ?? 0,
            window: 1,
        );

        if ($timestamp === false) {
            return false;
        }

        // Compare-and-set: two requests racing with one code get one yes.
        return $staff->claimTotpStep((int) $timestamp);
    }

    // ── enrolment ────────────────────────────────────────────────

    /**
     * Issue a secret to be enrolled. It is PENDING until a code proves it works.
     *
     * @param  bool  $revokeExisting  true when someone is being re-enrolled
     *                                because the old authenticator is gone or
     *                                suspect — the current secret dies now, and
     *                                the account is inert until the new one is
     *                                confirmed. False when a signed-in staff
     *                                member is simply moving to a new phone: the
     *                                working secret must keep working until the
     *                                replacement is proven, or a failed scan
     *                                locks them out of their own account.
     */
    public function beginEnrolment(PlatformStaff $staff, bool $revokeExisting = true): array
    {
        $secret = $this->totp->generateSecretKey(32);

        $staff->pending_totp_secret = $secret;

        if ($revokeExisting) {
            $staff->totp_secret = null;
            $staff->totp_confirmed_at = null;
        }

        $staff->save();

        return [
            'secret' => $secret,
            'uri' => $this->totp->getQRCodeUrl('EzBizi', $staff->email, $secret),
        ];
    }

    /**
     * Finish enrolment by proving the authenticator works.
     *
     * Returns recovery codes ONCE. They are stored encrypted at rest and never
     * shown again — a code the server can redisplay is a code an attacker can
     * read out of the database.
     *
     * Any previously issued recovery codes are replaced. The old ones were minted
     * against a secret that is no longer in use; leaving them live would mean a
     * rotation that changes the phone but not what an old leaked list can open.
     */
    public function completeEnrolment(PlatformStaff $staff, string $code): ?array
    {
        if ($staff->pending_totp_secret === null) {
            return null;
        }

        /*
         * Deliberately NOT measured against last_totp_timestamp.
         *
         * That counter belongs to the secret currently in use; this code comes
         * from a different one that has never been used for anything. Sharing the
         * counter would reject a perfectly good confirmation whenever it lands in
         * the same 30-second step as the re-authentication that preceded it —
         * which is most of the time, since the two happen seconds apart. The
         * resulting "that code did not verify" would be a lie.
         *
         * The counter IS set from this on success (below), so the confirming code
         * cannot then be turned around into a sign-in on the secret it activated.
         */
        $timestamp = $this->totp->verifyKeyNewer(
            $staff->pending_totp_secret,
            trim($code),
            0,   // 0 rather than null — see verifySecondFactor
            window: 1,
        );

        if ($timestamp === false) {
            return null;
        }

        $recovery = collect(range(1, 8))
            ->map(fn () => strtoupper(Str::random(5).'-'.Str::random(5)))
            ->all();

        $staff->totp_secret = $staff->pending_totp_secret;
        $staff->pending_totp_secret = null;
        $staff->totp_confirmed_at = now();
        $staff->recovery_codes = $recovery;
        // The confirming code was spoken aloud to whoever ran the enrolment.
        // Burning its time-step here stops it being turned straight around into
        // a sign-in on the secret it just activated.
        $staff->last_totp_timestamp = $timestamp;
        $staff->save();

        return $recovery;
    }
}
