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
 * One dated join or leave (TODO #27).
 *
 * `users.is_active` remains the current switch. This table is the history.
 * Leaving history begins at the migration that created it — there is no
 * backfill from `users.updated_at`.
 */
class EmploymentEvent extends Model implements HoldsPersonalData
{
    use HasUuids;
    use BelongsToTenant;
    use \App\Support\Live\RecordsParentChanges;

    /** A write here is its person's change on the live feed (RecordsParentChanges). */
    protected static array $changeParent = ['people', User::class, 'user_id'];


    public const STARTED = 'started';

    public const LEFT = 'left';

    // ── §9 data inventory ────────────────────────────────────────

    public static function personalDataPurpose(): string
    {
        return 'When somebody joined or left. The account itself stays the current switch; '
            .'this is the dated history that screen needs, kept so a starters-and-leavers '
            .'count is a count of days rather than of people who happen to be inactive now.';
    }

    public static function retention(): RetentionPolicy
    {
        return RetentionPolicy::forever(
            'Kept for as long as the account exists. Somebody who leaves is deactivated '
            .'rather than deleted, and the date they left is the fact the history exists to '
            .'remember — an age-based window here would delete the oldest employment first, '
            .'which is the one a later rights request or a year-to-year roll-up still needs.'
        );
    }

    public static function personalDataHeld(): array
    {
        return [
            new PersonalDataEntry('user_id', PersonalDataEntry::IDENTITY,
                'Which employee this dated join or leave is about.',
                Erasure::keep('A foreign key to the account, which the purge anonymises at '
                    .'its one home. Nulling it here would orphan a dated event from the '
                    .'person it is about without removing anything about them.')),
            new PersonalDataEntry('kind, occurred_on', PersonalDataEntry::ACTIVITY,
                'Whether they joined or left, and on which day.',
                Erasure::keep('The date is the history. Blanking it would leave a row that '
                    .'says somebody changed employment without saying when, which is worse '
                    .'than keeping a date against an already-anonymised account.')),
        ];
    }

    public static function personalDataAbout(Model $subject): ?Builder
    {
        return $subject instanceof User
            ? static::query()->where('user_id', $subject->getKey())
            : null;
    }

    protected $fillable = [
        'organization_id', 'user_id', 'kind', 'occurred_on',
    ];

    protected function casts(): array
    {
        return ['occurred_on' => 'date'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
