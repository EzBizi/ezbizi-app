<?php

namespace App\Models\Concerns;

use Illuminate\Support\Facades\DB;

/**
 * An account that can carry a second factor (plan §4.2, §6).
 *
 * Extracted when tenant users gained TOTP, because staff and users now face the
 * identical problem and a second copy of this would be a second place for the
 * single-use rules to drift. The POLICY differs — staff must have it, an
 * organization may require it, a user may choose it — and policy lives at the
 * call sites. The mechanics live here.
 *
 * The model must declare the encrypted casts itself; a trait cannot merge into
 * casts() without fighting whatever the model already has there.
 */
trait HasTotp
{
    public function hasTotp(): bool
    {
        return $this->totp_secret !== null && $this->totp_confirmed_at !== null;
    }

    /** A secret has been issued but not yet proven to work. */
    public function hasPendingTotp(): bool
    {
        return $this->pending_totp_secret !== null;
    }

    /**
     * Record the TOTP time-step a code used — once, whoever gets there first.
     *
     * A compare-and-set, not read-then-save. Two requests carrying the same
     * code both read the old step, both found the code "newer", and both
     * saved: one code, two sign-ins. The WHERE makes the second update match
     * nothing, so exactly one of them is told the code was good.
     *
     * Straight to the query builder without scopes: this runs at login,
     * before any tenant is set, on the account's own row by primary key.
     */
    public function claimTotpStep(int $step): bool
    {
        $claimed = $this->newQueryWithoutScopes()
            ->whereKey($this->getKey())
            ->where(fn ($q) => $q->whereNull('last_totp_timestamp')->orWhere('last_totp_timestamp', '<', $step))
            ->update(['last_totp_timestamp' => $step]) === 1;

        if ($claimed) {
            $this->last_totp_timestamp = $step;
            $this->syncOriginalAttribute('last_totp_timestamp');
        }

        return $claimed;
    }

    /**
     * Burn a recovery code. Single use — returns false if it wasn't valid.
     *
     * Locked for the duration, because "single use" has to survive two requests
     * arriving together. The codes are encrypted, so the check cannot be pushed
     * into a WHERE clause; a row lock is what serialises it instead. Without one,
     * read-modify-write lets the same code be redeemed twice by two clients
     * racing — which is exactly what someone who copied the list would try.
     */
    public function consumeRecoveryCode(string $code): bool
    {
        $normalised = strtoupper(trim($code));

        return DB::transaction(function () use ($normalised) {
            $locked = static::query()->whereKey($this->getKey())->lockForUpdate()->first();

            if ($locked === null) {
                return false;
            }

            $codes = $locked->recovery_codes ?? [];
            $index = array_search($normalised, $codes, strict: true);

            if ($index === false) {
                return false;
            }

            unset($codes[$index]);
            $locked->recovery_codes = array_values($codes);
            $locked->save();

            // Keep the in-memory copy honest — callers read recovery_codes right
            // after this to report how many are left.
            $this->recovery_codes = $locked->recovery_codes;

            return true;
        });
    }

    public function recoveryCodeCount(): int
    {
        return count($this->recovery_codes ?? []);
    }
}
