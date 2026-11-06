<?php

namespace App\Support\Leads;

use Carbon\CarbonImmutable;

/**
 * The dates a customer asked for, read from their words (A1 Leads takeover:
 * its parseRequestedWindow, for the second auto-reply that offers an open
 * slot). Plain phrases only - "today", "tomorrow", "friday", "before friday",
 * "after monday", "this week", "next week", "this weekend". Null when they
 * named no day; SlotOffer then offers the earliest open window.
 */
final class RequestedWindow
{
    private const DAYS = ['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday'];

    /**
     * $earliest: the first day that can be booked. A day named alone that falls
     * before it means the next one - "friday" said on a Friday to the booking
     * bot, which offers from tomorrow, is next Friday, not a Saturday
     * (2026-11-06).
     *
     * @return ?array{start: string, end: string} Y-m-d, inclusive
     */
    public static function from(string $text, CarbonImmutable $today, ?CarbonImmutable $earliest = null): ?array
    {
        $t = mb_strtolower($text);
        $range = fn (CarbonImmutable $a, CarbonImmutable $b) => $b->lt($a) ? null : ['start' => $a->toDateString(), 'end' => $b->toDateString()];
        $next = function (string $day) use ($today): CarbonImmutable {
            $d = $today->modify('next '.$day);

            return $today->format('l') === ucfirst($day) ? $today : $d;
        };

        foreach (self::DAYS as $day) {
            if (preg_match('/\b(before|by)\s+'.$day.'\b/', $t)) {
                return $range($today, $next($day)->subDay());
            }
            if (preg_match('/\bafter\s+'.$day.'\b/', $t)) {
                return $range($next($day)->addDay(), $next($day)->addDays(7));
            }
        }

        return match (true) {
            (bool) preg_match('/\btoday\b|\btonight\b|\bthis (morning|afternoon|evening)\b/', $t) => $range($today, $today),
            (bool) preg_match('/\btomorrow\b/', $t) => $range($today->addDay(), $today->addDay()),
            (bool) preg_match('/\bthis weekend\b/', $t) => $range($next('saturday'), $next('saturday')->addDay()),
            (bool) preg_match('/\bnext week\b/', $t) => $range($today->modify('next monday'), $today->modify('next monday')->addDays(6)),
            (bool) preg_match('/\bthis week\b/', $t) => $range($today, $today->endOfWeek()),
            default => self::namedDay($t, $next, $earliest),
        };
    }

    private static function namedDay(string $t, \Closure $next, ?CarbonImmutable $earliest): ?array
    {
        foreach (self::DAYS as $day) {
            if (preg_match('/\b'.$day.'\b/', $t)) {
                $d = $next($day);
                if ($earliest !== null && $d->lt($earliest)) {
                    $d = $d->addWeek();
                }

                return ['start' => $d->toDateString(), 'end' => $d->toDateString()];
            }
        }

        return null;
    }
}
