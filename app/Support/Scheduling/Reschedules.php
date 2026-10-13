<?php

namespace App\Support\Scheduling;

use App\Models\ServiceArea;
use Carbon\CarbonImmutable;

/**
 * Booked jobs that would sit better on another day (Jose, 2026-10-13: "a tab
 * with Suggested Reschedules ... what to replace them for, to better fit a
 * comprehensive route for the technicians").
 *
 * A job is a candidate when nothing else booked that day is within the
 * suggestion radius of it: a technician drives out for it alone. It is moved,
 * on paper, to the open window NearbySuggestion would offer a new customer at
 * its address, so the two features can never disagree. Online bookings are
 * left alone (the customer picked that window themselves seconds ago).
 *
 * ponytail: one job at a time, so two lone jobs that could pair on a third
 * day are not proposed together, and two suggestions may name the same last
 * seat (the dispatcher who books first takes it; the list refreshes). Plan
 * them jointly if the list runs dry or seats collide in practice.
 */
final class Reschedules
{
    public function __construct(private readonly NearbySuggestion $nearby) {}

    /** @return array<int, array<string, mixed>> */
    public function for(ServiceArea $area, CarbonImmutable $from): array
    {
        $look = $this->nearby->load($area, $from);
        $radius = $look['radius'];
        $out = [];

        foreach ($look['jobs'] as $date => $onDay) {
            foreach ($onDay as $job) {
                if ($job->kind === 'booking') {
                    continue;
                }
                $at = ['latitude' => (float) $job->latitude, 'longitude' => (float) $job->longitude];
                $others = $look['jobs']->map(fn ($d) => $d->reject(fn ($j) => $j === $job));
                $today = $this->nearby->placed($others[$date], [], $at, $radius);

                if ($today->contains('nearby', true)) {
                    continue;
                }

                $best = NearbySuggestion::best($this->nearby->candidates(
                    $look['days']->reject(fn (array $d) => $d['date'] === $date), $others, $at, $radius,
                ));

                if ($best !== null) {
                    $out[] = [
                        'row' => $job,
                        'current' => ['date' => $date, 'nearest_miles' => $today->min('miles')],
                        'suggested' => $this->nearby->shown($best),
                        // The jobs it would join: they stay where they are.
                        'anchors' => $others[$best['date']]->filter(fn ($j) => NearbySuggestion::miles(
                            $at['latitude'], $at['longitude'], (float) $j->latitude, (float) $j->longitude) <= $radius)->all(),
                    ];
                }
            }
        }

        // The biggest saving first: alone and far today, close on the new day.
        // A job another suggestion moves next to is not itself moved, or two
        // lone jobs would each be sent to the other's day.
        $anchored = [];

        return collect($out)
            ->sortBy(fn ($r) => [$r['suggested']['nearest_miles'] - ($r['current']['nearest_miles'] ?? 999), $r['current']['date']])
            ->reject(function (array $r) use (&$anchored) {
                if (in_array($r['row'], $anchored, true)) {
                    return true;
                }
                array_push($anchored, ...array_values($r['anchors']));

                return false;
            })
            ->map(fn (array $r) => array_diff_key($r, ['anchors' => 0]))
            ->values()->all();
    }
}
