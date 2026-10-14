<?php

namespace App\Support\Scheduling;

use App\Models\ServiceArea;
use Carbon\CarbonImmutable;

/**
 * A suggested route per technician for one area's day (Jose, 2026-10-14:
 * "1 technician is able to take a max of 6 jobs per day ... Maryland has 18
 * availability on monday which translates to 3 technicians ... a Routing Tab
 * where it shows the recommended routes (actual routes being done on google
 * maps when clicked)").
 *
 * Technicians = the day's capacity / 6, rounded up, with no names. Jobs go in
 * start-time order to the technician whose last stop is nearest, so a route
 * runs outward from where it began; a technician takes one job per start
 * time and a fair share of each window. A job farther than NEW_ROUTE_MILES
 * from every route so far starts the next technician's.
 *
 * ponytail: greedy and straight-line, not a solver over road times. Good for
 * "who goes where" on six stops; a VRP solver when routes get long.
 */
final class Routes
{
    public const JOBS_PER_TECH = 6;

    public const NEW_ROUTE_MILES = 15.0;

    public function __construct(private readonly Openings $openings) {}

    /** @return array{capacity: int, technicians: int, routes: list<array>, unplaced: list<object>} */
    public function for(ServiceArea $area, CarbonImmutable $date): array
    {
        $windows = $this->openings->days($area, $date, 1)[0]['windows'] ?? [];
        $capacity = array_sum(array_column($windows, 'capacity'));
        $techs = max(1, (int) ceil($capacity / self::JOBS_PER_TECH));

        // Web bookings too: each is a visit a technician makes.
        $jobs = $this->openings->jobsOn($area, $date)->values();
        [$placeable, $unplaced] = $jobs->partition(fn (object $j) => is_numeric($j->latitude ?? null) && is_numeric($j->longitude ?? null)
            && NearbySuggestion::inUs((float) $j->latitude, (float) $j->longitude));

        $time = fn (object $j) => substr((string) $j->scheduled_for, 11, 5);
        $windowOf = function (string $t) use ($windows): ?int {
            foreach ($windows as $i => $w) {
                if ($t !== '00:00' && $t >= $w['start_time'] && $t < $w['end_time']) {
                    return $i;
                }
            }

            return null;
        };
        $share = array_map(fn ($w) => max(1, (int) ceil($w['capacity'] / $techs)), $windows);

        $routes = array_fill(0, $techs, ['stops' => [], 'times' => [], 'per_window' => []]);

        // Ties broken by Workiz id, so "Technician 1" is the same route on
        // every refresh.
        foreach ($placeable->sortBy(fn ($j) => [$time($j) === '00:00' ? '99:99' : $time($j), (string) ($j->remote_id ?? $j->lead_id ?? '')]) as $job) {
            $t = $time($job);
            $w = $windowOf($t);
            $best = null;
            $bestScore = INF;

            foreach ($routes as $i => $r) {
                $full = count($r['stops']) >= self::JOBS_PER_TECH
                    || ($t !== '00:00' && in_array($t, $r['times'], true))
                    || ($w !== null && ($r['per_window'][$w] ?? 0) >= $share[$w]);
                if ($full) {
                    continue;
                }
                $last = end($r['stops']);
                $score = $last === false ? self::NEW_ROUTE_MILES
                    : NearbySuggestion::miles((float) $last->latitude, (float) $last->longitude, (float) $job->latitude, (float) $job->longitude);
                if ($score < $bestScore) {
                    [$best, $bestScore] = [$i, $score];
                }
            }

            // More booked than the day's technicians can take (on production
            // most busy days are: five 8:00 jobs for four technicians): an
            // extra route, so the screen says "one more technician" instead
            // of piling five 8:00 stops on one.
            if ($best === null) {
                $routes[] = ['stops' => [], 'times' => [], 'per_window' => [], 'extra' => true];
                $best = array_key_last($routes);
            }

            $routes[$best]['stops'][] = $job;
            $routes[$best]['times'][] = $t;
            if ($w !== null) {
                $routes[$best]['per_window'][$w] = ($routes[$best]['per_window'][$w] ?? 0) + 1;
            }
        }

        return [
            'capacity' => $capacity,
            'technicians' => $techs,
            'routes' => array_values(array_map(fn (array $r, int $i) => [
                'technician' => $i + 1,
                'stops' => $r['stops'],
                'miles' => round($this->miles($r['stops']), 1),
                // Beyond the day's capacity / 6: a technician the day does not have.
                'extra' => $r['extra'] ?? false,
                'maps_url' => self::mapsUrl($r['stops']),
            ], $routes, array_keys($routes))),
            'unplaced' => $unplaced->values()->all(),
        ];
    }

    /** Straight-line miles stop to stop. */
    private function miles(array $stops): float
    {
        $total = 0.0;
        for ($i = 1; $i < count($stops); $i++) {
            $total += NearbySuggestion::miles((float) $stops[$i - 1]->latitude, (float) $stops[$i - 1]->longitude,
                (float) $stops[$i]->latitude, (float) $stops[$i]->longitude);
        }

        return $total;
    }

    /**
     * Google Maps driving directions through every stop in order: the first is
     * the origin, the last the destination (Google's documented "dir" URL;
     * up to 9 waypoints, and a route has at most 6 stops).
     */
    public static function mapsUrl(array $stops): ?string
    {
        if ($stops === []) {
            return null;
        }
        $at = fn (object $j) => round((float) $j->latitude, 6).','.round((float) $j->longitude, 6);
        $points = array_map($at, $stops);
        $query = ['api' => 1, 'origin' => $points[0], 'destination' => end($points), 'travelmode' => 'driving'];
        if (count($points) > 2) {
            $query['waypoints'] = implode('|', array_slice($points, 1, -1));
        }

        return 'https://www.google.com/maps/dir/?'.http_build_query($query);
    }
}
