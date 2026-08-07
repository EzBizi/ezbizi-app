<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Platform-wide subscription defaults (plan §13, §2.1 category 4).
 *
 * Platform data, not tenant data — no BelongsToTenant, no organization_id, the
 * same shape as PlatformModuleFlag. EzBizi owns this; organizations cannot see
 * or change it, because a tenant able to set its own trial length is a billing
 * hole rather than a preference.
 *
 * ── One row, deliberately ────────────────────────────────────────
 *
 * There is one platform, so there is one set of defaults. A key/value table
 * would have been the generic answer and §2.1 warns against exactly that: "do
 * not build one generic toggle system" covering release flags, entitlements,
 * permissions and tenant settings, because they look identical in a database
 * and have different owners. Named columns on a named table keep this one
 * unmistakably an entitlement.
 *
 * ── What this is NOT ─────────────────────────────────────────────
 *
 * It is not the expiry date. §13 requires the exact expiry timestamp to be
 * stored on the organization rather than recomputed, so `trial_ends_at` stays
 * authoritative once a trial has started and changing the number here never
 * moves a trial that is already running. Extending one organization's trial is
 * a write to that organization's `trial_ends_at`; this row only decides where
 * the next one starts out.
 */
class PlatformSubscriptionDefaults extends Model
{
    use HasFactory;
    use HasUuids;

    protected $table = 'platform_subscription_defaults';

    protected $fillable = ['trial_days', 'changed_by'];

    protected function casts(): array
    {
        return ['trial_days' => 'integer'];
    }

    /**
     * The single row.
     *
     * Created if it is somehow missing rather than falling back to a constant.
     * A fallback would be a second copy of the number living in code, which is
     * the thing this table exists to remove — and it would answer "what is the
     * trial length?" differently depending on whether anyone had looked yet.
     * The column default supplies the value, so the row it creates is the same
     * row the migration seeded.
     */
    public static function current(): self
    {
        return static::query()->firstOrCreate([]);
    }

    public static function trialDays(): int
    {
        return static::current()->trial_days;
    }
}
