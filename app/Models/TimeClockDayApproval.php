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
 * One person's working day, waiting for or given a manager's approval
 * (Work Hours › Daily approval; Shoval via Jose, 2026-10-22, for Ohio's GM).
 *
 * Created as `waiting` when the day's reminder goes out, so the notification
 * has a row to open; `approved` once the approver says the hours are right.
 * An adjustment is a correction of the shift itself, audited with its reason.
 */
class TimeClockDayApproval extends Model implements HoldsPersonalData
{
    use BelongsToTenant;
    use HasUuids;

    public const WAITING = 'waiting';

    public const APPROVED = 'approved';

    public static function personalDataPurpose(): string
    {
        return 'Whether a manager approved this person\'s clock-ins for one day, and who.';
    }

    public static function retention(): RetentionPolicy
    {
        return RetentionPolicy::forever(
            'Kept with the shifts it approves: it is the record that the hours paid were checked.'
        );
    }

    public static function personalDataHeld(): array
    {
        return [
            new PersonalDataEntry('user_id, date, status, approved_by, approved_at', PersonalDataEntry::ACTIVITY,
                'Which day of whose hours, and who approved it when.',
                Erasure::keep('Employment history against an already-anonymised account, like the shifts.')),
        ];
    }

    public static function personalDataAbout(Model $subject): ?Builder
    {
        return $subject instanceof User
            ? static::query()->where('user_id', $subject->getKey())
            : null;
    }

    protected $fillable = [
        'organization_id', 'user_id', 'date', 'status', 'approved_by', 'approved_at',
    ];

    protected function casts(): array
    {
        return [
            'date' => 'date:Y-m-d',
            'approved_at' => 'datetime',
        ];
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
