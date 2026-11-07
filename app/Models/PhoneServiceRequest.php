<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Support\Privacy\RetentionPolicy;
use App\Support\Privacy\StatesRetention;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * An organization asking EzBizi for phone service (§4 gate 2).
 *
 * The entitlement is NOT a separate table. "May this company have phone
 * service" is answered by [approvedFor] — is there an approved row naming it —
 * so there is one record of the decision rather than a decision and a copy of
 * it that can disagree. If revoking ever has to be a thing, it becomes a column
 * on the company row, not a second source of truth.
 */
#[Fillable([
    'requested_by', 'status', 'expected_volume', 'wants_sms', 'purpose',
    'decided_by', 'decided_at', 'decision_note',
])]
class PhoneServiceRequest extends Model implements StatesRetention
{
    use BelongsToTenant;
    use HasUuids;

    public const STATUS_PENDING = 'pending';

    /** Every company on it was approved. */
    public const STATUS_APPROVED = 'approved';

    /** Some yes, some no. The customer may ask again for the ones refused. */
    public const STATUS_PARTIAL = 'partial';

    public const STATUS_DECLINED = 'declined';

    /**
     * How long after a refusal before they may ask again.
     *
     * A cooling-off, not a punishment: it exists so a decision that took real
     * thought is not re-litigated the same afternoon. It starts at the DECISION,
     * not the request, and it only blocks asking again — nothing already
     * approved is affected. How long: setting phone.request_cooldown_days.
     */

    /** Monthly call volume, as bands rather than a number nobody can estimate. */
    public const VOLUMES = [
        'under_100' => 'Fewer than 100 calls a month',
        'from_100' => '100 to 500 calls a month',
        'from_500' => '500 to 2,000 calls a month',
        'over_2000' => 'More than 2,000 calls a month',
    ];

    public static function retention(): RetentionPolicy
    {
        return RetentionPolicy::forever(
            'Whether this organization asked for phone service and what EzBizi answered. '
            .'The record of a commercial decision; removing the organization takes the row.'
        );
    }

    protected function casts(): array
    {
        return [
            'wants_sms' => 'boolean',
            'decided_at' => 'datetime',
        ];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function companies(): HasMany
    {
        return $this->hasMany(PhoneServiceRequestCompany::class, 'request_id');
    }

    /**
     * Is this company entitled to phone service?
     *
     * The gate ConnectionsController asks before letting anybody activate. Runs
     * unscoped by id rather than by tenant because the caller has already
     * established which company it is asking about.
     */
    public static function approvedFor(string $companyId): bool
    {
        return PhoneServiceRequestCompany::query()
            ->where('company_id', $companyId)
            ->where('approved', true)
            ->exists();
    }

    /**
     * When this organization may ask again, or null if it may ask now.
     *
     * Null is also the answer while a request is still open — a pending request
     * is refused for being pending, which is a different sentence and a
     * different fix.
     */
    public function cooldownEndsAt(): ?\Illuminate\Support\Carbon
    {
        if ($this->decided_at === null) {
            return null;
        }

        if ($this->status === self::STATUS_APPROVED) {
            return null;
        }

        return $this->decided_at->copy()->addDays((int) app(\App\Support\Settings\SettingsResolver::class)->get('phone.request_cooldown_days', Organization::query()->withoutGlobalScopes()->findOrFail($this->organization_id)));
    }
}
