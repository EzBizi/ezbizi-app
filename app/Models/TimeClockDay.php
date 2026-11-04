<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Support\Settings\SettingsResolver;
use App\Support\Privacy\Erasure;
use App\Support\Privacy\HoldsPersonalData;
use App\Support\Privacy\PersonalDataEntry;
use App\Support\Privacy\RetentionPolicy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * What HR says about one person's day, beside what the clock says
 * (Jose, 2026-09-24: extra hours, notes, and tags — Office, Home, Absent,
 * Sick, Holiday, Vacation).
 *
 * ── Why a DAY and not a shift ──
 *
 * Four of the six tags describe a day with no shift in it at all: nobody
 * clocks in on a sick day, so there is no row to hang "Sick" on. Extra hours
 * are the same — time worked that the clock never saw has no punch. Putting
 * them on the day lets one record answer for both, and lets a day with no
 * shifts still say something on Work Hours instead of "Nothing clocked".
 *
 * One row per person per date, created on first write. Absent means nothing
 * was said, which is the state of almost every day.
 */
class TimeClockDay extends Model implements HoldsPersonalData
{
    use BelongsToTenant;
    use HasUuids;

    /*
     * The default of `time_clock.day_tags` (Jose, 2026-11-04: every business
     * value editable). In the order the picker draws them: where they worked
     * first, then why they did not.
     */
    public const DEFAULT_TAGS = [
        ['value' => 'office', 'label' => 'Office'],
        ['value' => 'home', 'label' => 'Home'],
        ['value' => 'absent', 'label' => 'Absent'],
        ['value' => 'sick', 'label' => 'Sick'],
        ['value' => 'holiday', 'label' => 'Holiday'],
        ['value' => 'vacation', 'label' => 'Vacation'],
    ];

    /**
     * The organization's day tags. A label left as its default comes back
     * null, so the app shows its own translation instead of the English.
     *
     * @return list<array{value: string, label: ?string}>
     */
    public static function tagsFor(Organization $organization): array
    {
        $defaults = array_column(self::DEFAULT_TAGS, 'label', 'value');

        return array_values(array_map(fn (array $t) => [
            'value' => (string) $t['value'],
            'label' => ($defaults[$t['value']] ?? null) === $t['label'] ? null : (string) $t['label'],
        ], (array) app(SettingsResolver::class)->get('time_clock.day_tags', $organization)));
    }

    /** @return list<string> */
    public static function tagValuesFor(Organization $organization): array
    {
        return array_column(self::tagsFor($organization), 'value');
    }

    // ── §9 data inventory ────────────────────────────────────────

    public static function personalDataPurpose(): string
    {
        return 'What HR recorded about one of this person\'s working days — where they '
            .'worked or why they did not, time worked off the clock, and a note.';
    }

    public static function retention(): RetentionPolicy
    {
        return RetentionPolicy::forever(
            'Kept with the shifts it sits beside, for the same reason: it is employment '
            .'history, and an extra-hours entry removed by age would silently lower a '
            .'total somebody was paid on.'
        );
    }

    public static function personalDataHeld(): array
    {
        return [
            new PersonalDataEntry('user_id', PersonalDataEntry::IDENTITY,
                'Which employee this day is about.',
                Erasure::keep('A foreign key to the account, which the purge anonymises at its '
                    .'one home.')),
            new PersonalDataEntry('date, tag, extra_minutes', PersonalDataEntry::ACTIVITY,
                'Where they worked or why they were away that day, and time worked off the clock.',
                Erasure::keep('Employment history against an already-anonymised account, like '
                    .'the shifts. "Sick" is kept as a category of absence, not a diagnosis.')),
            new PersonalDataEntry('note', PersonalDataEntry::CONTENT,
                'Free text somebody in HR wrote about the day.',
                // Free text about a person can say anything, including why
                // they were ill. It is the one field here that is about them
                // rather than about the day.
                Erasure::erase()),
        ];
    }

    public static function personalDataAbout(Model $subject): ?Builder
    {
        return $subject instanceof User
            ? static::query()->where('user_id', $subject->getKey())
            : null;
    }

    protected $fillable = [
        'organization_id', 'user_id', 'date', 'tag', 'extra_minutes', 'note',
    ];

    protected function casts(): array
    {
        return [
            'date' => 'date:Y-m-d',
            'extra_minutes' => 'integer',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
