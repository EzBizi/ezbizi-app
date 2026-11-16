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
 * One clock-in / clock-out stretch for a person (Clock in design §6).
 *
 * Soft-delete is not used. Open = `clocked_out_at` null; at most one open
 * row per user (enforced by the generated `open_slot` unique).
 */
class TimeClockShift extends Model implements HoldsPersonalData
{
    use BelongsToTenant;
    use \App\Support\Live\RecordsParentChanges;

    /** A write here is its person's change on the live feed (RecordsParentChanges). */
    protected static array $changeParent = ['people', User::class, 'user_id'];

    use HasUuids;

    /** Who closed the shift (design 2026-09-08 §5.2). Null while open. */
    public const ENDED_BY_PERSON = 'person';

    public const ENDED_BY_SCHEDULE = 'schedule';

    public const ENDED_BY_AUTOMATION = 'automation';

    /*
     * Somebody with `work_hours.manage` set this time by hand. It is its own
     * value for the reason `ended_by` exists at all: a row reading `person`
     * claims the person clocked out, and on a corrected row that is false —
     * the commonest correction is exactly the shift they FORGOT to close.
     */
    public const ENDED_BY_CORRECTION = 'correction';

    /*
     * Clocked out by somebody in HR while the shift was still running — Force
     * clock out on Work Hours (2026-09-24). Not `correction`: nothing was
     * rewritten, the end is the moment it happened, it just was not the
     * person who pressed it.
     */
    public const ENDED_BY_MANAGER = 'manager';

    /** `edit_source`: HR changed it unasked ("Edited"), or approved a request ("Shift corrected"). */
    public const EDITED_BY_HR = 'hr';

    public const EDITED_BY_REQUEST = 'request';

    // ── §9 data inventory ────────────────────────────────────────

    public static function personalDataPurpose(): string
    {
        return 'When this person started and finished work. The punch is the history '
            .'the day list and a later rights request need — not inventing an out time '
            .'and not dropping the row when the account is anonymised.';
    }

    public static function retention(): RetentionPolicy
    {
        return RetentionPolicy::forever(
            'Kept for as long as the organization keeps employment history. A punch is a '
            .'legal artefact; an age-based window here would delete the oldest shifts first, '
            .'which is the stretch a dispute or a year-to-year roll-up still needs.'
        );
    }

    public static function personalDataHeld(): array
    {
        return [
            new PersonalDataEntry('user_id', PersonalDataEntry::IDENTITY,
                'Which employee this clock-in stretch is about.',
                Erasure::keep('A foreign key to the account, which the purge anonymises at '
                    .'its one home. Nulling it here would orphan a dated punch from the '
                    .'person it is about without removing anything about them.')),
            new PersonalDataEntry('clocked_in_at, clocked_out_at', PersonalDataEntry::ACTIVITY,
                'When they clocked in, and when they clocked out (null while still in).',
                Erasure::keep('The instants are the history. Blanking them would leave a row that '
                    .'says somebody punched without saying when, which is worse than '
                    .'keeping times against an already-anonymised account. Do not invent '
                    .'an out time on erasure.')),
            new PersonalDataEntry('scheduled_end_at, extensions, outside_schedule, ended_by, import_ref',
                PersonalDataEntry::ACTIVITY,
                'What the schedule said about this shift and who closed it.',
                Erasure::keep('Facts about the punch, not about the person; they go with the row.')),
        ];
    }

    public static function personalDataAbout(Model $subject): ?Builder
    {
        return $subject instanceof User
            ? static::query()->where('user_id', $subject->getKey())
            : null;
    }

    protected $fillable = [
        'organization_id', 'user_id', 'clocked_in_at', 'clocked_out_at',
        'scheduled_end_at', 'extensions', 'outside_schedule', 'ended_by', 'import_ref',
        'edit_source',
    ];

    protected function casts(): array
    {
        return [
            'clocked_in_at' => 'datetime',
            'clocked_out_at' => 'datetime',
            'scheduled_end_at' => 'datetime',
            'extensions' => 'integer',
            'outside_schedule' => 'boolean',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereNull('clocked_out_at');
    }

    public static function openFor(User $user): ?self
    {
        return static::query()
            ->open()
            ->where('user_id', $user->getKey())
            ->first();
    }
}
