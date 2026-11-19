<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Support\Privacy\Erasure;
use App\Support\Privacy\HoldsPersonalData;
use App\Support\Privacy\PersonalDataEntry;
use App\Support\Privacy\RetentionPolicy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One person's payroll week as their manager signed it off (Work Hours ›
 * Payroll; Merav via Jose, 2026-11-19): bonus, deductions, a note, and who
 * approved it. The money is encrypted at rest, like PayRate's.
 */
class PayrollWeekLine extends Model implements HoldsPersonalData
{
    use BelongsToTenant;
    use HasUuids;

    protected $fillable = [
        'organization_id', 'user_id', 'week_start', 'bonus', 'deductions', 'note', 'approved_by', 'approved_at',
    ];

    protected function casts(): array
    {
        return [
            'week_start' => 'date:Y-m-d',
            'bonus' => 'encrypted',
            'deductions' => 'encrypted',
            'note' => 'encrypted',
            'approved_at' => 'datetime',
        ];
    }

    public static function personalDataPurpose(): string
    {
        return 'A week of somebody\'s pay as their manager approved it: bonus, deductions and why.';
    }

    public static function retention(): RetentionPolicy
    {
        return RetentionPolicy::forever(
            'Payroll records: what somebody was paid and why is kept with the hours it paid for.'
        );
    }

    public static function personalDataHeld(): array
    {
        return [
            new PersonalDataEntry('user_id, week_start, bonus, deductions, note, approved_by, approved_at', PersonalDataEntry::ACTIVITY,
                'Whose week, its bonus and deductions, why, and who approved it.',
                Erasure::keep('Payroll history against an already-anonymised account, like the shifts it paid.')),
        ];
    }

    public static function personalDataAbout(Model $subject): ?Builder
    {
        return $subject instanceof User
            ? static::query()->where('user_id', $subject->getKey())
            : null;
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }
}
