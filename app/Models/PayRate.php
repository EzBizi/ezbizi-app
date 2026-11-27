<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Support\Privacy\Erasure;
use App\Support\Privacy\HoldsPersonalData;
use App\Support\Privacy\HoldsSensitiveValues;
use App\Support\Privacy\PersonalDataEntry;
use App\Support\Privacy\RetentionPolicy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * What somebody is paid, from a date (Jose, 2026-09-29: "Pay rates").
 *
 * A history rather than one value: a raise is a new row, and the current rate
 * is the latest one already in effect. The amount and the note are encrypted
 * at rest, and reading either needs `hr.pay.view` — its own permission, not
 * `hr.edit`, because who earns what is a narrower circle than who keeps the
 * people list.
 */
class PayRate extends Model implements HoldsPersonalData, HoldsSensitiveValues
{
    use BelongsToTenant;
    use HasUuids;

    public const BASES = ['hourly', 'salary'];

    public const PERIODS = ['hour', 'week', 'month', 'year'];

    protected $fillable = [
        'organization_id', 'user_id', 'basis', 'amount', 'period',
        'effective_on', 'note', 'created_by_user_id',
    ];

    protected function casts(): array
    {
        return [
            'amount'       => 'encrypted',
            'note'         => 'encrypted',
            'effective_on' => 'date',
        ];
    }

    /**
     * The rate that applied to someone on a day: the latest one effective on or
     * before it. Stages dated ahead (RateSteps) apply themselves this way, and a
     * payroll or time report asks this per date (Merav, 2026-11-17).
     */
    public static function rateOn(string $userId, string $date, ?string $basis = null): ?self
    {
        return self::query()->where('user_id', $userId)->when($basis, fn ($q) => $q->where('basis', $basis))
            ->whereDate('effective_on', '<=', $date)->orderByDesc('effective_on')->latest()->first();
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    // ── sensitive values ─────────────────────────────────────────

    public function sensitiveAttributes(): array
    {
        return ['amount', 'note'];
    }

    public function mayReveal(User $actor): bool
    {
        return $actor->hasPermission('hr.pay.view');
    }

    // ── §9 data inventory ────────────────────────────────────────

    public static function personalDataPurpose(): string
    {
        return 'What somebody is paid and from when, as the employer recorded it. EzBizi does '
            .'not run payroll; this is the reference an HR team keeps beside the person.';
    }

    public static function retention(): RetentionPolicy
    {
        return RetentionPolicy::forever(
            'Kept while the account exists. A superseded rate is the history of what somebody '
            .'was paid, which is exactly the question it is kept to answer.'
        );
    }

    public static function personalDataHeld(): array
    {
        return [
            new PersonalDataEntry('user_id', PersonalDataEntry::IDENTITY,
                'The employee this pay rate belongs to.',
                Erasure::drop('Pay is about one person and means nothing without them, so an '
                    .'erasure removes the rows rather than keeping amounts against nobody.')),
            new PersonalDataEntry('basis, amount, period, effective_on, note', PersonalDataEntry::CONTENT,
                'Hourly or salaried, how much, per what, from which day, and any note. The '
                .'amount and the note are encrypted at rest.',
                Erasure::drop('Removed with the row, for the reason above.')),
        ];
    }

    public static function personalDataAbout(Model $subject): ?Builder
    {
        return $subject instanceof User
            ? static::query()->where('user_id', $subject->getKey())
            : null;
    }
}
