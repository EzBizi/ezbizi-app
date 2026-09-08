<?php

namespace App\Support\TimeClock;

use App\Support\Settings\SettingDefinition;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

/**
 * The shape of `users.work_schedule` and the one question asked of it:
 * what is today's window, in the zone the schedule was written in.
 *
 * `{ timezone: 'Etc/GMT+5', days: { monday: {start, end} | null, … } }`.
 * The zone is snapshotted at save from the editor's view, so a shift can be
 * stamped and closed with no view in hand (design 2026-09-08 §3.2).
 */
final class WorkSchedule
{
    /**
     * Validate the seven days as sent. Returns them normalised, week order.
     *
     * @throws ValidationException
     */
    public static function validate(mixed $days): array
    {
        if (! is_array($days)) {
            throw ValidationException::withMessages(['days' => 'Send one entry per weekday.']);
        }

        $clean = [];

        foreach (SettingDefinition::WEEKDAYS as $day) {
            if (! array_key_exists($day, $days)) {
                throw ValidationException::withMessages(['days' => "Send an entry for {$day}, or null for a day off."]);
            }

            $window = $days[$day];

            if ($window === null) {
                $clean[$day] = null;

                continue;
            }

            $start = is_array($window) ? ($window['start'] ?? null) : null;
            $end = is_array($window) ? ($window['end'] ?? null) : null;

            foreach ([$start, $end] as $time) {
                if (! is_string($time) || ! preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $time)) {
                    throw ValidationException::withMessages(["days.{$day}" => 'Times are HH:MM on a 24-hour clock.']);
                }
            }

            if ($end <= $start) {
                throw ValidationException::withMessages(["days.{$day}" => 'A shift that crosses midnight is not supported yet.']);
            }

            $clean[$day] = ['start' => $start, 'end' => $end];
        }

        return $clean;
    }

    /**
     * Today's window for the calendar day `$at` falls on in the schedule zone.
     *
     * @return null|array{start_at: Carbon, end_at: Carbon, start: string, end: string}
     */
    public static function windowFor(?array $schedule, Carbon $at): ?array
    {
        $tz = $schedule['timezone'] ?? null;
        $days = $schedule['days'] ?? null;

        if (! is_string($tz) || ! is_array($days)) {
            return null;
        }

        $local = $at->copy()->timezone($tz);
        $window = $days[strtolower($local->format('l'))] ?? null;

        if (! is_array($window) || ! isset($window['start'], $window['end'])) {
            return null;
        }

        $date = $local->toDateString();

        return [
            'start_at' => Carbon::parse("{$date} {$window['start']}", $tz)->utc(),
            'end_at' => Carbon::parse("{$date} {$window['end']}", $tz)->utc(),
            'start' => $window['start'],
            'end' => $window['end'],
        ];
    }
}
