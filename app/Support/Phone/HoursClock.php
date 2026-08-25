<?php

namespace App\Support\Phone;

use App\Models\PhoneHoursSchedule;
use DateTimeImmutable;
use DateTimeZone;

/**
 * Whether a named hours schedule is open at an instant (spec §7, Task 7).
 *
 * Wall clock in the schedule timezone — not UTC midnights. Empty day = closed.
 * Exceptions of `{ "on": "Y-m-d", "closed": true }` close that calendar day.
 */
final class HoursClock
{
    /** @var list<string> */
    private const DAY_KEYS = ['sun', 'mon', 'tue', 'wed', 'thu', 'fri', 'sat'];

    public static function open(PhoneHoursSchedule $schedule, DateTimeImmutable $at): bool
    {
        $tzName = is_string($schedule->timezone) && $schedule->timezone !== ''
            ? $schedule->timezone
            : 'UTC';

        $local = $at->setTimezone(new DateTimeZone($tzName));
        $date = $local->format('Y-m-d');
        $time = $local->format('H:i');

        if (self::exceptionCloses($schedule->exceptions, $date)) {
            return false;
        }

        $dayKey = self::DAY_KEYS[(int) $local->format('w')];
        $weeks = is_array($schedule->weeks) ? $schedule->weeks : [];
        $intervals = $weeks[$dayKey] ?? [];

        if (! is_array($intervals) || $intervals === []) {
            return false;
        }

        foreach ($intervals as $interval) {
            if (! is_array($interval) || count($interval) < 2) {
                continue;
            }

            $start = (string) $interval[0];
            $end = (string) $interval[1];

            // Half-open [start, end): open at 09:00, closed at 17:00.
            if ($time >= $start && $time < $end) {
                return true;
            }
        }

        return false;
    }

    private static function exceptionCloses(mixed $exceptions, string $date): bool
    {
        if (! is_array($exceptions)) {
            return false;
        }

        foreach ($exceptions as $row) {
            if (! is_array($row)) {
                continue;
            }

            if (($row['on'] ?? null) !== $date) {
                continue;
            }

            if (($row['closed'] ?? false) === true) {
                return true;
            }
        }

        return false;
    }
}
