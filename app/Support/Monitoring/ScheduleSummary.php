<?php

namespace App\Support\Monitoring;

use App\Support\Settings\SettingDefinition;

/**
 * Two short sentences out of a week's worth of hours — "Mon – Fri" and
 * "8:00 AM – 5:00 PM" (Jose, 2026-09-23).
 *
 * The ownership chart used to print the raw map, which came out as
 *
 *   monday {open: 08:00, close: 17:00}, tuesday {open: 08:00, close: 17:00}, …
 *   days {friday: {end: 17:00, start: 09:00}, monday: {…}, …}, timezone …
 *
 * — two different renderings in one column, because the two sources have two
 * different shapes and neither was ever meant to be read.
 *
 * ── The two shapes, measured rather than assumed ─────────────────
 *
 * `ops.business_hours` (SettingRegistry) is flat, keyed by weekday, with
 * `open`/`close` and `null` for a closed day.
 *
 * `users.work_schedule` (HrPeopleController::updateSchedule, via WorkSchedule)
 * is `{ timezone, days: { monday: {start, end} | null, … } }` — nested, and
 * with different key names for the same two facts.
 *
 * Both are accepted here rather than normalised at one of the sources, because
 * they are two settings owned by two modules and this is a reader.
 *
 * ── What it refuses to do ────────────────────────────────────────
 *
 * When the working days do NOT share one window, [shift] answers "Varies"
 * rather than picking the first, the longest or the most common. A chart that
 * printed "8:00 AM – 5:00 PM" for somebody who works four short days and one
 * long one would be stating a shift nobody works, and it would look exactly
 * like the truth.
 */
final class ScheduleSummary
{
    /** Three letters each, in the order a week is read. */
    private const SHORT = [
        'monday' => 'Mon',
        'tuesday' => 'Tue',
        'wednesday' => 'Wed',
        'thursday' => 'Thu',
        'friday' => 'Fri',
        'saturday' => 'Sat',
        'sunday' => 'Sun',
    ];

    /** Said when the working days do not share one window. */
    public const VARIES = 'Varies';

    /**
     * "Mon – Fri", "Mon, Wed, Fri", or null when no day is worked.
     *
     * Consecutive days collapse into a range; a gap starts a new group. Three
     * separate days stay three names, because "Mon – Fri" for somebody who
     * works Monday, Wednesday and Friday is a claim about two days they do not.
     */
    public static function workDays(mixed $schedule): ?string
    {
        $days = self::windows($schedule);

        if ($days === []) {
            return null;
        }

        $groups = [];
        $run = [];

        foreach (SettingDefinition::WEEKDAYS as $day) {
            if (isset($days[$day])) {
                $run[] = $day;

                continue;
            }

            if ($run !== []) {
                $groups[] = $run;
                $run = [];
            }
        }

        if ($run !== []) {
            $groups[] = $run;
        }

        return implode(', ', array_map(static function (array $group) {
            $first = self::SHORT[$group[0]];
            $last = self::SHORT[$group[count($group) - 1]];

            // Two adjacent days read better as a pair than as a range:
            // "Sat, Sun" rather than "Sat – Sun".
            return count($group) <= 2
                ? implode(', ', array_map(static fn ($d) => self::SHORT[$d], $group))
                : "{$first} – {$last}";
        }, $groups));
    }

    /**
     * "8:00 AM – 5:00 PM", [VARIES], or null when no day is worked.
     */
    public static function shift(mixed $schedule): ?string
    {
        $days = self::windows($schedule);

        if ($days === []) {
            return null;
        }

        $windows = array_unique(array_map(
            static fn (array $w) => $w['start'].'|'.$w['end'],
            $days
        ));

        if (count($windows) > 1) {
            return self::VARIES;
        }

        $only = reset($days);

        return self::clock($only['start']).' – '.self::clock($only['end']);
    }

    /**
     * The worked days, keyed by weekday, each `['start' => , 'end' => ]`.
     *
     * Accepts both shapes and drops anything that is not a real window: a null
     * day is a day off, and a day whose times are missing or malformed is not
     * a day anybody can be said to work.
     *
     * @return array<string, array{start: string, end: string}>
     */
    private static function windows(mixed $schedule): array
    {
        if (! is_array($schedule)) {
            return [];
        }

        // `users.work_schedule` nests its week under `days` and carries the
        // zone beside it; `ops.business_hours` is the week itself.
        $source = is_array($schedule['days'] ?? null) ? $schedule['days'] : $schedule;

        $days = [];

        foreach (SettingDefinition::WEEKDAYS as $day) {
            $window = $source[$day] ?? null;

            if (! is_array($window)) {
                continue;
            }

            // open/close from the organization's hours, start/end from a
            // person's schedule — the same two facts under two names.
            $start = $window['start'] ?? $window['open'] ?? null;
            $end = $window['end'] ?? $window['close'] ?? null;

            if (! is_string($start) || ! is_string($end) || $start === '' || $end === '') {
                continue;
            }

            $days[$day] = ['start' => $start, 'end' => $end];
        }

        return $days;
    }

    /**
     * "08:00" -> "8:00 AM". Left alone if it is not a 24-hour time, because a
     * value this reader does not recognise is better shown than reformatted
     * into something confident and wrong.
     */
    private static function clock(string $time): string
    {
        if (preg_match('/^(\d{1,2}):(\d{2})$/', $time, $m) !== 1) {
            return $time;
        }

        $hour = (int) $m[1];

        if ($hour > 23) {
            return $time;
        }

        $meridiem = $hour < 12 ? 'AM' : 'PM';
        $twelve = $hour % 12 === 0 ? 12 : $hour % 12;

        return "{$twelve}:{$m[2]} {$meridiem}";
    }
}
