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
 * Somebody asking for one of their own shifts to be corrected (Trey Brannan's
 * suggestion, 2026-09-30). Nothing changes until a person holding
 * `work_hours.manage` approves it, and approving goes through
 * TimeClock::correct — the same checks, audit and reach as an edit made by hand.
 */
class TimeClockCorrectionRequest extends Model implements HoldsPersonalData
{
    use BelongsToTenant;
    use \App\Support\Live\RecordsParentChanges;

    /** A write here is its person's change on the live feed (RecordsParentChanges). */
    protected static array $changeParent = ['people', User::class, 'user_id'];

    use HasUuids;

    public const PENDING = 'pending';

    public const APPROVED = 'approved';

    public const DENIED = 'denied';

    protected $fillable = [
        'organization_id', 'user_id', 'shift_id', 'was_in_at', 'was_out_at',
        'clocked_in_at', 'clocked_out_at', 'reason', 'status',
        'decided_by', 'decided_at', 'decision_note',
    ];

    protected function casts(): array
    {
        return [
            'was_in_at' => 'datetime',
            'was_out_at' => 'datetime',
            'clocked_in_at' => 'datetime',
            'clocked_out_at' => 'datetime',
            'decided_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function shift(): BelongsTo
    {
        return $this->belongsTo(TimeClockShift::class, 'shift_id');
    }

    // ── §9 data inventory ────────────────────────────────────────

    public static function personalDataPurpose(): string
    {
        return 'Corrections you asked for to your own clock in and out times, why, and what was decided.';
    }

    public static function retention(): RetentionPolicy
    {
        return RetentionPolicy::forever(
            'The account of why a paid hour changed. It is kept as long as the shift it '
            .'corrected, which is kept for as long as the organization keeps employment history.'
        );
    }

    public static function personalDataHeld(): array
    {
        return [
            new PersonalDataEntry('user_id', PersonalDataEntry::IDENTITY,
                'Which employee asked for their own shift to be corrected.',
                Erasure::keep('A foreign key to the account, anonymised at its one home.')),
            new PersonalDataEntry('was_in_at, was_out_at, clocked_in_at, clocked_out_at', PersonalDataEntry::ACTIVITY,
                'The shift times before, and the times asked for.',
                Erasure::keep('History of a paid shift, kept with the shift it corrected.')),
            new PersonalDataEntry('reason, decision_note', PersonalDataEntry::CONTENT,
                'Why they asked, and what the manager answered. Free text.',
                // Blanked to the stand-in, not dropped: a drop would delete the
                // row and with it the record of which paid hours changed.
                Erasure::erase()),
        ];
    }

    public static function personalDataAbout(Model $subject): ?Builder
    {
        return $subject instanceof User
            ? static::query()->where('user_id', $subject->getKey())
            : null;
    }
}
