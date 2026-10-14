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
 * runs outward from where it began. A technician takes up to PER_WINDOW jobs
 * in a window ("2 morning, mid day, 2 afternoon"), the second within
 * PAIR_MILES of the first; a job farther than NEW_ROUTE_MILES from every
 * route so far starts the next technician's. A job FAR_MILES from the day's
 * centre is listed `elsewhere`, not routed: on production those were jobs
 * booked in the wrong area (Columbus OH on Connecticut, 508 miles), and one
 * wrecked a whole route (Grok's review, 2026-10-14).
 *
 * ponytail: greedy and straight-line, not a solver over road times. Good for
 * "who goes where" on six stops; a VRP solver when routes get long.
 */
final class Routes
{
    public const JOBS_PER_TECH = 6;

    public const NEW_ROUTE_MILES = 15.0;

    public const PER_WINDOW = 2;

    public const PAIR_MILES = 10.0;

    public const FAR_MILES = 60.0;

    public function __construct(private readonly Openings $openings, private readonly NearbySuggestion $nearby) {}

    /** @return array{capacity: int, technicians: int, routes: list<array>, unplaced: list<object>, elsewhere: list<object>} */
    public function for(ServiceArea $area, CarbonImmutable $date): array
    {
        $windows = $this->openings->days($area, $date, 1)[0]['windows'] ?? [];
        $capacity = array_sum(array_column($windows, 'capacity'));
        $techs = max(1, (int) ceil($capacity / self::JOBS_PER_TECH));

        // Web bookings too: each is a visit a technician makes.
        $jobs = $this->nearby->located($this->openings->jobsOn($area, $date), $area->organization_id)->values();
        [$placeable, $unplaced] = $jobs->partition(fn (object $j) => is_numeric($j->latitude ?? null) && is_numeric($j->longitude ?? null)
            && NearbySuggestion::inUs((float) $j->latitude, (float) $j->longitude));

        // The day's centre is its median stop, so a few wrong-area jobs
        // cannot drag it off.
        $median = fn (array $v) => $v === [] ? 0.0 : (sort($v) ? $v[intdiv(count($v), 2)] : 0.0);
        $centre = [$median($placeable->map(fn ($j) => (float) $j->latitude)->all()), $median($placeable->map(fn ($j) => (float) $j->longitude)->all())];
        [$elsewhere, $placeable] = $placeable->partition(fn (object $j) => NearbySuggestion::miles($centre[0], $centre[1], (float) $j->latitude, (float) $j->longitude) > self::FAR_MILES);

        $time = fn (object $j) => substr((string) $j->scheduled_for, 11, 5);
        $windowOf = function (string $t) use ($windows): ?int {
            foreach ($windows as $i => $w) {
                if ($t !== '00:00' && $t >= $w['start_time'] && $t < $w['end_time']) {
                    return $i;
                }
            }

            return null;
        };
        $routes = array_fill(0, $techs, ['stops' => [], 'per_window' => []]);

        // Ties broken by Workiz id, so "Technician 1" is the same route on
        // every refresh.
        foreach ($placeable->sortBy(fn ($j) => [$time($j) === '00:00' ? '99:99' : $time($j), (string) ($j->remote_id ?? $j->lead_id ?? '')]) as $job) {
            $t = $time($job);
            $w = $windowOf($t);
            $best = null;
            $bestScore = INF;

            foreach ($routes as $i => $r) {
                $last = end($r['stops']);
                $score = $last === false ? self::NEW_ROUTE_MILES
                    : NearbySuggestion::miles((float) $last->latitude, (float) $last->longitude, (float) $job->latitude, (float) $job->longitude);
                $inWindow = $w === null ? [] : ($r['per_window'][$w] ?? []);
                $full = count($r['stops']) >= self::JOBS_PER_TECH
                    || count($inWindow) >= self::PER_WINDOW
                    // A second job in the window is near the first, or the
                    // technician cannot reach both in it.
                    || collect($inWindow)->contains(fn ($o) => NearbySuggestion::miles((float) $o->latitude, (float) $o->longitude,
                        (float) $job->latitude, (float) $job->longitude) > self::PAIR_MILES);
                if (! $full && $score < $bestScore) {
                    [$best, $bestScore] = [$i, $score];
                }
            }

            // More booked than the day's technicians can take (on production
            // most busy days are: five 8:00 jobs for four technicians): an
            // extra route, so the screen says "one more technician" instead
            // of piling five 8:00 stops on one.
            if ($best === null) {
                $routes[] = ['stops' => [], 'per_window' => [], 'extra' => true];
                $best = array_key_last($routes);
            }

            $routes[$best]['stops'][] = $job;
            if ($w !== null) {
                $routes[$best]['per_window'][$w][] = $job;
            }
        }

        // Two stops in one window: the nearer to the stop before goes first.
        foreach ($routes as &$r) {
            for ($i = 1; $i + 1 < count($r['stops']); $i++) {
                [$a, $b] = [$r['stops'][$i], $r['stops'][$i + 1]];
                if ($windowOf($time($a)) !== null && $windowOf($time($a)) === $windowOf($time($b))) {
                    $prev = $r['stops'][$i - 1];
                    $from = fn ($s) => NearbySuggestion::miles((float) $prev->latitude, (float) $prev->longitude, (float) $s->latitude, (float) $s->longitude);
                    if ($from($b) < $from($a)) {
                        [$r['stops'][$i], $r['stops'][$i + 1]] = [$b, $a];
                    }
                }
            }
        }
        unset($r);

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
            'elsewhere' => $elsewhere->values()->all(),
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
