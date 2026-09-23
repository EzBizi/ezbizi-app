<?php

namespace App\Support\Web;

/**
 * The published `hours` string, read back as seven days — when it can be.
 *
 * ── Why a parser and not a column ──
 *
 * Because the string is the record and has to stay the record.
 * `client/lib/screens/web/hours.dart` is explicit about it: the grid on the SEO
 * screen is an EDITOR OVER A STRING, so that adding the grid needed no
 * migration and no plugin release. Every install in the wild caches `hours` as
 * one line and renders it with `[ezbizi_hours]`.
 *
 * A table on a website needs the days apart. The way to have both is the way
 * `address` and `website_url` already do it — DERIVE IT ON THE WAY OUT. The
 * stored value never changes shape, and `[ezbizi_hours_table]` gets structure
 * without anything else in the system learning a new type.
 *
 * ── Why null is the important answer ──
 *
 * `null` means the string did not come from the grid: "By appointment, November
 * to March" is a real value some sites hold, and it is the sites least like a
 * shop that hold it. A parser that guessed at those would put a wrong week on a
 * customer's page, so it refuses, `hours_week` is absent from the payload, and
 * the table renders the sentence as written. Same fail-open instinct as
 * `WidgetDefaults::hours`.
 *
 * ── Why this is tested against Dart rather than against fixtures ──
 *
 * It is the second implementation of a format whose first implementation is
 * `parseWeek` in `hours.dart`, and two parsers that disagree put one week on
 * the screen and a different one on the website. `tests/Feature/Web/HoursWeekTest.php`
 * and `client/test/web_hours_test.dart` run THE SAME table of cases, and the
 * table is the contract. Change one, change both.
 */
final class HoursWeek
{
    /** Monday first, matching `weekdayNames` in `hours.dart`. */
    public const DAYS = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'];

    /**
     * The format `formatWeek` writes, and nothing else.
     *
     * `Mon–Fri 8:00 AM – 5:00 PM · Sat 9:00 AM – 1:00 PM · Sun closed`
     *
     * The dashes are EN DASHES (U+2013) because that is what the Dart writes,
     * and the separator is a middle dot. Both are multibyte, which is why the
     * pattern carries `/u` — without it `.` and the character classes would
     * walk into the middle of a dash and the whole thing would fail on exactly
     * the strings it is meant to accept.
     */
    private const PATTERN = '/^([A-Za-z]{3})(?:\x{2013}([A-Za-z]{3}))?\s+'
        .'(closed|(\d{1,2}):(\d{2})\s*(AM|PM)\s*\x{2013}\s*(\d{1,2}):(\d{2})\s*(AM|PM))$/iu';

    /**
     * Seven days, or null when this is not a string the grid wrote.
     *
     * @return array<int, array{day: string, closed: bool, open: string, close: string}>|null
     */
    public static function parse(string $text): ?array
    {
        /*
         * A non-breaking space alongside the ASCII whitespace: this value has
         * been pasted out of a browser by somebody tidying a footer, and one
         * U+00A0 would otherwise make a perfectly good week unparseable.
         * Dart's `String.trim` already takes it, so accepting it here is what
         * keeps the two implementations agreeing rather than being generous.
         */
        $trimmed = preg_replace('/^[\s\x{00A0}]+|[\s\x{00A0}]+$/u', '', $text) ?? '';

        if ($trimmed === '') {
            return null;
        }

        /** @var array<int, array{day: string, closed: bool, open: string, close: string}|null> $week */
        $week = array_fill(0, 7, null);

        foreach (explode('·', $trimmed) as $part) {
            $chunk = trim($part);

            if ($chunk === '') {
                return null;
            }

            if (preg_match(self::PATTERN, $chunk, $m) !== 1) {
                return null;
            }

            $from = self::dayIndex($m[1]);
            $to = ($m[2] ?? '') === '' ? $from : self::dayIndex($m[2]);

            if ($from === null || $to === null || $to < $from) {
                return null;
            }

            if (strcasecmp($m[3], 'closed') === 0) {
                $value = ['closed' => true, 'open' => '00:00', 'close' => '00:00'];
            } else {
                $open = self::clock($m[4], $m[5], $m[6]);
                $close = self::clock($m[7], $m[8], $m[9]);

                if ($open === null || $close === null) {
                    return null;
                }

                $value = ['closed' => false, 'open' => $open, 'close' => $close];
            }

            for ($i = $from; $i <= $to; $i++) {
                // A day named twice means this is not something we wrote.
                if ($week[$i] !== null) {
                    return null;
                }

                $week[$i] = $value;
            }
        }

        $out = [];

        foreach ($week as $i => $day) {
            if ($day === null) {
                return null;
            }

            // The name rides along so the plugin renders a table without
            // carrying its own copy of the week in the right order.
            $out[] = ['day' => self::DAYS[$i], ...$day];
        }

        return $out;
    }

    private static function dayIndex(string $name): ?int
    {
        foreach (self::DAYS as $i => $day) {
            if (strcasecmp($day, $name) === 0) {
                return $i;
            }
        }

        return null;
    }

    /** A 12-hour time as `HH:MM` on a 24-hour clock, matching Dart's `_time`. */
    private static function clock(string $hour, string $minute, string $meridiem): ?string
    {
        $h = (int) $hour;
        $m = (int) $minute;

        if ($h < 1 || $h > 12 || $m < 0 || $m > 59) {
            return null;
        }

        if (strcasecmp($meridiem, 'PM') === 0 && $h !== 12) {
            $h += 12;
        }

        if (strcasecmp($meridiem, 'AM') === 0 && $h === 12) {
            $h = 0;
        }

        return sprintf('%02d:%02d', $h, $m);
    }
}
