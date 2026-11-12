<?php

namespace App\Support\Automations;

use Illuminate\Support\Carbon;
use InvalidArgumentException;

/**
 * When a scheduled rule next comes round (plan §5.5, "scheduled / recurring").
 *
 * ── The one thing this class exists to get right ─────────────────
 *
 * A schedule is a WALL CLOCK plus the ZONE it was typed in, and the instant it
 * means is computed fresh every time. "9am America/New_York" is 14:00 UTC in
 * January and 13:00 UTC in July, and re-resolving on each advance produces both
 * without anybody thinking about daylight saving.
 *
 * The alternative — storing an offset, or resolving once at save time — bakes
 * one of those in forever and drifts an hour for eight months of the year. The
 * organization timezone setting DOES do that: it defaults to `Etc/GMT+5`, a
 * fixed offset whose own help text says there is no daylight saving. That is a
 * deliberate product decision for displaying times, and it is the wrong thing
 * to schedule against, which is why the zone lives on the rule and the picker
 * offers the twelve real places rather than that setting's default.
 *
 * ── Never twice, and late at most once ───────────────────────────
 *
 * Every advance computes the next occurrence STRICTLY AFTER a given instant, so
 * an hour that happens twice on a fall-back night fires once. A worker that was
 * down for six hours finds one due rule, not six: it runs once and advances to
 * the next FUTURE occurrence. Catching up once is useful; a stampede of six
 * identical reports is a bug wearing the costume of correctness.
 *
 * An hour that does not exist on a spring-forward morning is moved forward by
 * PHP rather than skipped, so a 2:30am daily rule runs at 3:30am that one day.
 * Stated because somebody will notice and it is the better of two wrong answers
 * — the alternative is not running at all, silently, once a year.
 */
class AutomationSchedule
{
    public const DAILY = 'daily';

    public const WEEKLY = 'weekly';

    public const MONTHLY = 'monthly';

    public const KINDS = [self::DAILY, self::WEEKLY, self::MONTHLY];

    /** Monday first, because a working week does. */
    public const WEEKDAYS = [
        'monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday',
    ];

    /**
     * repeat_until: again every hour after `time`, up to this time, on the same days (the Wednesday
     * losses wait for the P&L, audit 2026-11-11). A step with nothing to say yet sends nothing.
     *
     * @param  array{kind: string, time: string, repeat_until?: string, days?: string[], day_of_month?: int, timezone: string}  $schedule
     */
    public function __construct(private readonly array $schedule)
    {
        self::assertValid($schedule);
    }

    /**
     * Refuse a schedule that cannot come round, at SAVE time.
     *
     * The engine's own rule: a rule that saves and never runs is worse than one
     * refused, because nothing reports it afterwards. "Weekly on no days" is the
     * shape that does that — it validates as an array, stores, activates, and
     * waits forever.
     *
     * @throws InvalidArgumentException
     */
    public static function assertValid(mixed $schedule): void
    {
        if (! is_array($schedule)) {
            throw new InvalidArgumentException('A schedule needs a kind, a time and a timezone.');
        }

        $kind = $schedule['kind'] ?? null;

        if (! in_array($kind, self::KINDS, strict: true)) {
            throw new InvalidArgumentException(
                'A schedule runs daily, weekly or monthly.'
            );
        }

        if (! is_string($schedule['time'] ?? null)
            || ! preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $schedule['time'])) {
            throw new InvalidArgumentException(
                'A schedule needs a time of day written as HH:MM, such as 09:00.'
            );
        }

        if (array_key_exists('repeat_until', $schedule) && (! is_string($schedule['repeat_until'])
            || ! preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $schedule['repeat_until']) || $schedule['repeat_until'] < $schedule['time'])) {
            throw new InvalidArgumentException('Repeat hourly until a time written as HH:MM, no earlier than the first run.');
        }

        $zone = $schedule['timezone'] ?? null;

        /*
         * Asked of PHP rather than of a list, because the list is the wrong one.
         *
         * `timezone_identifiers_list()` defaults to the geographic groups and
         * EXCLUDES `Etc/*` — so the organization's own default, `Etc/GMT+5`,
         * failed this check while being a zone the product ships and every
         * settings screen offers. Caught by the test that asserts a zone with no
         * daylight saving does not move, which exists as the control for the
         * DST test beside it and found a different bug than it was written for.
         */
        try {
            new \DateTimeZone(is_string($zone) ? $zone : '');
        } catch (\Throwable) {
            throw new InvalidArgumentException(
                'A schedule needs the timezone its time was written in.'
            );
        }

        if ($kind === self::WEEKLY) {
            $days = $schedule['days'] ?? [];

            if (! is_array($days) || $days === []) {
                throw new InvalidArgumentException(
                    'A weekly schedule needs at least one day of the week, or it never comes round.'
                );
            }

            foreach ($days as $day) {
                if (! in_array($day, self::WEEKDAYS, strict: true)) {
                    throw new InvalidArgumentException("'{$day}' is not a day of the week.");
                }
            }
        }

        if ($kind === self::MONTHLY) {
            $day = $schedule['day_of_month'] ?? null;

            if (! is_int($day) || $day < 1 || $day > 31) {
                throw new InvalidArgumentException(
                    'A monthly schedule needs a day of the month between 1 and 31.'
                );
            }
        }
    }

    /**
     * The next moment this comes round, strictly after [$after], as an instant.
     *
     * Computed in the schedule's own zone and returned in UTC, which is what
     * `automations.next_run_at` stores and the sweep compares against.
     */
    public function nextAfter(Carbon $after): Carbon
    {
        [$hour, $minute] = array_map('intval', explode(':', $this->schedule['time']));
        [$untilHour, $untilMinute] = array_map('intval', explode(':', $this->schedule['repeat_until'] ?? $this->schedule['time']));
        $last = max($hour, $untilHour - ($untilMinute < $minute ? 1 : 0));

        // The earliest of the day's hourly slots; one slot without repeat_until.
        return collect(range($hour, $last))->map(fn (int $h) => $this->nextAt($after, $h, $minute))->sortBy(fn (Carbon $at) => $at->getTimestamp())->first();
    }

    private function nextAt(Carbon $after, int $hour, int $minute): Carbon
    {
        $zone = $this->schedule['timezone'];

        $local = $after->copy()->setTimezone($zone);
        $candidate = $local->copy()->setTime($hour, $minute, 0);

        // Strictly after: an occurrence exactly at $after has already been dealt
        // with by whoever passed it in, and returning it again runs it twice.
        if ($candidate->lessThanOrEqualTo($local)) {
            $candidate->addDay();
        }

        $candidate = match ($this->schedule['kind']) {
            self::DAILY => $candidate,
            self::WEEKLY => $this->nextSelectedWeekday($candidate),
            self::MONTHLY => $this->nextDayOfMonth($candidate, $hour, $minute),
        };

        return $candidate->setTimezone('UTC');
    }

    /** The first of the chosen weekdays on or after [$from]. */
    private function nextSelectedWeekday(Carbon $from): Carbon
    {
        $wanted = array_map('strtolower', $this->schedule['days']);

        // Seven, not "while true": a week always contains every weekday, so a
        // loop that cannot terminate here means the days were not validated.
        for ($i = 0; $i < 7; $i++) {
            if (in_array(strtolower($from->format('l')), $wanted, strict: true)) {
                return $from;
            }

            $from->addDay();
        }

        throw new InvalidArgumentException('A weekly schedule with no valid day cannot come round.');
    }

    /**
     * The chosen day of the month, on or after [$from].
     *
     * A rule set for the 31st runs on the LAST day of a month that has no 31st.
     * The alternative is skipping February, March in a leap year and four other
     * months, which is not what anybody means by "monthly".
     */
    private function nextDayOfMonth(Carbon $from, int $hour, int $minute): Carbon
    {
        $wanted = (int) $this->schedule['day_of_month'];

        $candidate = $from->copy()
            ->setDay(min($wanted, $from->daysInMonth))
            ->setTime($hour, $minute, 0);

        if ($candidate->lessThan($from)) {
            $next = $from->copy()->startOfMonth()->addMonthNoOverflow();

            $candidate = $next
                ->setDay(min($wanted, $next->daysInMonth))
                ->setTime($hour, $minute, 0);
        }

        return $candidate;
    }
}
