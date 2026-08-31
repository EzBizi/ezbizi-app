<?php

namespace App\Support\Scheduling;

use App\Models\ServiceAreaSlot;
use Illuminate\Support\Collection;

/**
 * "The pattern", derived rather than stored
 * (docs/superpowers/specs/2026-08-31-scheduling-availability-design.md §5).
 *
 * The Settings screen talks about a franchise's slot pattern and the areas
 * that follow it, and there is no pattern row anywhere. There is deliberately
 * no pattern row: storing one turns this into a template system with a
 * precedence rule, an override model, and a migration for areas that already
 * differ — three decisions nobody has asked for, each a place where a closed
 * Sunday can quietly become an open one.
 *
 * So a shape is a canonical string built from an area's own slot rows, and
 * "the pattern" is whichever shape the most areas have. Two areas follow the
 * pattern when their rows say so, which means the claim on screen and the data
 * underneath it cannot drift apart — there is nothing to keep in step.
 */
final class SlotShape
{
    /**
     * One area's shape.
     *
     * Sorted before joining, because the same week entered in a different
     * order is the same week. An area with no slots at all has a shape too —
     * the empty string — and that is correct rather than a special case: a
     * franchise where nothing is configured yet has every area agreeing.
     *
     * @param  Collection<int, ServiceAreaSlot>  $slots
     */
    public static function of(Collection $slots): string
    {
        return $slots
            ->map(fn (ServiceAreaSlot $s) => sprintf(
                '%d|%s|%s|%d',
                $s->weekday,
                substr((string) $s->start_time, 0, 5),
                substr((string) $s->end_time, 0, 5),
                $s->max_jobs,
            ))
            ->sort()
            ->values()
            ->implode(',');
    }

    /**
     * The commonest shape, or null when there is nothing to be common about.
     *
     * A tie is broken by whichever shape sorts first, which is arbitrary and
     * says so. The screen only claims "followed by N of M" — it never claims
     * the pattern is the RIGHT one — so an arbitrary winner between two equal
     * halves is honest. What it must not do is vary between two identical
     * requests, which is why it is not "whichever came back first".
     *
     * @param  string[]  $shapes
     */
    public static function commonest(array $shapes): ?string
    {
        $real = array_filter($shapes, fn (string $s) => $s !== '');

        if ($real === []) {
            return null;
        }

        $counts = array_count_values($real);

        // Sort by count descending, then by the shape itself, so the answer is
        // stable across requests rather than dependent on hash order.
        ksort($counts);
        arsort($counts, SORT_NUMERIC);

        return (string) array_key_first($counts);
    }

    /**
     * A shape back as slot rows, for showing it and for applying it.
     *
     * @return array<int, array{weekday:int, start_time:string, end_time:string, max_jobs:int}>
     */
    public static function describe(string $shape): array
    {
        if ($shape === '') {
            return [];
        }

        return array_map(static function (string $part): array {
            [$weekday, $start, $end, $max] = explode('|', $part);

            return [
                'weekday' => (int) $weekday,
                'start_time' => $start,
                'end_time' => $end,
                'max_jobs' => (int) $max,
            ];
        }, explode(',', $shape));
    }
}
