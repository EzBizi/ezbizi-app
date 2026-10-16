<?php

namespace App\Support\Web\Hours;

use App\Support\Web\HoursWeek;
use DateTimeImmutable;
use DateTimeZone;

/**
 * One requested change to a week of hours, worked out for one place.
 *
 * Rodrigo's brief (2026-09-28, relayed by Jose): each day is Keep existing,
 * Set hours or Closed all day; under Set hours each boundary is Keep existing
 * or a time; times may be entered in another zone and converted per place; and
 * a "don't open before" floor applies to the converted opening only.
 *
 * Pure: it reads nothing and writes nothing, so every rule in the brief is a
 * unit of this class and the destinations only ever see a finished week.
 *
 * ── Why a whole place is blocked when one day is ──
 *
 * The brief says to block "that location/day and explain the conflict instead
 * of inventing hours". A place written with six of seven requested days is a
 * week nobody asked for, so a blocked day holds the whole place back, with the
 * reason, and the other places go ahead.
 *
 * ── Why a day that crosses midnight is refused ──
 *
 * Overnight hours are out of scope in the brief, and a converted opening that
 * lands on another weekday would have to move to that day. Refused with the
 * reason rather than written somewhere the operator did not look.
 */
final class WeekEdit
{
    public const KEEP = 'keep';

    public const SET = 'set';

    public const CLOSED = 'closed';

    /**
     * @param  array<int, array{closed: bool, open: string, close: string}>|null  $existing  Monday first; null when the
     *                                                                                       place has no weekly schedule (empty, or a sentence)
     * @param  array<int, array{action: string, open: ?string, close: ?string}>  $days  Monday first; a null time is Keep existing
     * @return array{status: string, reasons: list<string>, week: ?array, days: list<array<string, mixed>>}
     */
    public static function plan(
        ?array $existing,
        array $days,
        string $placeZone,
        ?string $sourceZone,
        ?string $notBefore,
        DateTimeImmutable $now,
    ): array {
        $reasons = [];
        $out = [];
        $week = [];

        foreach (HoursWeek::DAYS as $i => $name) {
            $spec = $days[$i];
            $was = $existing[$i] ?? null;
            $row = ['day' => $name, 'action' => $spec['action'], 'existing' => $was, 'requested' => null,
                'converted' => null, 'final' => $was, 'adjusted' => false, 'error' => null];

            if ($spec['action'] === self::KEEP) {
                if ($was === null) {
                    $row['error'] = 'This site has no weekly hours to keep, so every day needs setting.';
                }
            } elseif ($spec['action'] === self::CLOSED) {
                $row['final'] = ['closed' => true, 'open' => '00:00', 'close' => '00:00'];
            } else {
                $row['requested'] = ['open' => $spec['open'], 'close' => $spec['close']];
                $open = $spec['open'];
                $close = $spec['close'];

                if ($open === null && $close === null) {
                    // Both kept: the brief's "treat the day as unchanged".
                    $row['action'] = self::KEEP;
                    $row['final'] = $was;
                    if ($was === null) {
                        $row['error'] = 'This site has no weekly hours to keep, so every day needs setting.';
                    }
                } elseif (($open === null || $close === null) && ($was === null || $was['closed'])) {
                    $row['error'] = 'Closed now, so opening it needs both an opening and a closing time.';
                } else {
                    $converted = ['open' => null, 'close' => null];

                    foreach (['open', 'close'] as $edge) {
                        if ($spec[$edge] === null) {
                            continue;
                        }

                        $local = self::convert($spec[$edge], $i, $sourceZone, $placeZone, $now);

                        if ($local === null) {
                            $row['error'] = "The {$edge}ing time falls on another day in {$placeZone}.";
                            break;
                        }

                        $converted[$edge] = $local;
                    }

                    if ($row['error'] === null) {
                        $row['converted'] = $sourceZone === null ? null : $converted;
                        $finalOpen = $converted['open'] ?? $was['open'];
                        $finalClose = $converted['close'] ?? $was['close'];

                        // The floor moves a requested opening, never a kept one or a closing.
                        if ($notBefore !== null && $converted['open'] !== null && $finalOpen < $notBefore) {
                            $finalOpen = $notBefore;
                            $row['adjusted'] = true;
                        }

                        if ($finalOpen >= $finalClose) {
                            $row['error'] = $row['adjusted']
                                ? "Not opening before {$notBefore} leaves no time before closing at {$finalClose}."
                                : "Opening at {$finalOpen} is not before closing at {$finalClose}.";
                        } else {
                            $row['final'] = ['closed' => false, 'open' => $finalOpen, 'close' => $finalClose];
                        }
                    }
                }
            }

            if ($row['error'] !== null) {
                $reasons[] = "{$name}: {$row['error']}";
            }

            $row['changed'] = $row['error'] === null && $row['final'] !== null && $was !== null
                ? self::signature($row['final']) !== self::signature($was)
                : $row['error'] === null && $row['final'] !== null;
            $week[] = $row['final'];
            $out[] = $row;
        }

        if ($reasons !== []) {
            return ['status' => 'blocked', 'reasons' => $reasons, 'week' => null, 'days' => $out];
        }

        $changed = array_filter($out, fn (array $r) => $r['changed']) !== [];

        return ['status' => $changed ? 'change' : 'unchanged', 'reasons' => [], 'week' => $week, 'days' => $out];
    }

    /**
     * A wall-clock time on a weekday, read in the source zone and given back in
     * the place's zone — or unchanged when there is no source zone. Null when
     * the converted moment is on another weekday.
     *
     * The date is the next such weekday from now, so the offset is the one in
     * force this week: a DST change moves the result, which is the point.
     */
    private static function convert(string $hhmm, int $weekday, ?string $sourceZone, string $placeZone, DateTimeImmutable $now): ?string
    {
        if ($sourceZone === null) {
            return $hhmm;
        }

        $from = new DateTimeZone($sourceZone);
        $day = $now->setTimezone($from)->setTime(0, 0);
        $day = $day->modify('+'.((($weekday + 1) - (int) $day->format('N') + 7) % 7).' days');
        [$h, $m] = array_map('intval', explode(':', $hhmm));
        $local = $day->setTime($h, $m)->setTimezone(new DateTimeZone($placeZone));

        return (int) $local->format('N') === $weekday + 1 ? $local->format('H:i') : null;
    }

    private static function signature(array $day): string
    {
        return $day['closed'] ? 'closed' : $day['open'].'-'.$day['close'];
    }
}
