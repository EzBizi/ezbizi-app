<?php

namespace App\Support\Scheduling;

use App\Models\Franchise;
use App\Models\ServiceArea;
use App\Support\Settings\SettingsResolver;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The open window nearest work already booked (Jose, 2026-10-13: "check the
 * existing jobs and see if it can pair it with jobs that are closer to each
 * other ... show the Earlier Availability and the Suggested Availability").
 *
 * The customer's place is the centre of their zip, taken from the jobs A-1
 * has already done there (4,900+ zips carry coordinates), so nothing is sent
 * to a geocoder. Distances are straight-line miles. A day is scored by how
 * many of its booked jobs sit within the radius, then by how close the
 * nearest one is; inside a day, the window next to the nearby job wins, so a
 * technician goes there straight from it. Ties go to the earlier day.
 *
 * Bookings come from Openings::jobsBetween(), the same rows it counts, so a
 * job this weighs is a job that holds a seat.
 */
final class NearbySuggestion
{
    public const RADIUS = 'scheduling.suggest_radius_miles';

    public const DAYS = 'scheduling.suggest_days';

    public function __construct(
        private readonly Openings $openings,
        private readonly SettingsResolver $settings,
    ) {}

    /** @return array<string, mixed> */
    public function for(ServiceArea $area, string $zip, CarbonImmutable $from): array
    {
        $franchise = Franchise::query()->find($area->franchise_id);
        $radius = (float) $this->settings->get(self::RADIUS, $franchise);
        $span = (int) $this->settings->get(self::DAYS, $franchise);
        $origin = $this->centre($area->organization_id, $zip);

        $out = ['radius_miles' => $radius, 'days' => $span, 'origin' => $origin, 'earliest' => null, 'suggested' => null];

        $days = collect($this->openings->days($area, $from, $span))
            ->filter(fn (array $d) => collect($d['windows'])->contains(fn ($w) => $w['remaining'] > 0))
            ->values();

        if ($days->isEmpty()) {
            return $out;
        }

        $jobs = $this->openings->jobsBetween($area, $from, $from->addDays($span))
            ->filter(fn (object $j) => is_numeric($j->latitude ?? null) && is_numeric($j->longitude ?? null) && (float) $j->latitude != 0.0)
            ->groupBy(fn (object $j) => substr((string) $j->scheduled_for, 0, 10));

        $candidates = [];
        foreach ($days as $d) {
            $here = $this->placed($jobs[$d['date']] ?? collect(), $d['windows'], $origin, $radius);
            $nearby = $here->where('nearby', true);

            foreach ($d['windows'] as $i => $w) {
                if ($w['remaining'] <= 0) {
                    continue;
                }
                $candidates[] = [
                    'date' => $d['date'],
                    // The whole day, in the shape Availability already draws,
                    // and which of its windows this is.
                    'day' => $d,
                    'window_index' => $i,
                    'window' => ['start_time' => $w['start_time'], 'end_time' => $w['end_time'], 'remaining' => $w['remaining']],
                    'nearby' => $nearby->count(),
                    'nearest_miles' => $here->min('miles'),
                    // Windows between this one and the nearest nearby job's.
                    'gap' => $nearby->map(fn ($j) => $j['window'] === null ? 1 : abs($j['window'] - $i))->min() ?? 99,
                    'jobs' => $here->sortBy('miles')->values()->all(),
                    'technicians' => $nearby->pluck('technicians')->flatten()->unique()->values()->all(),
                ];
            }
        }

        $earliest = $candidates[0];
        $out['earliest'] = $this->shown($earliest);

        if ($origin === null) {
            return $out;
        }

        $best = collect($candidates)
            ->filter(fn ($c) => $c['nearby'] > 0)
            ->sort(fn ($a, $b) => [-$a['nearby'], $a['gap'], $a['nearest_miles'], $a['date'], $a['window']['start_time']]
                <=> [-$b['nearby'], $b['gap'], $b['nearest_miles'], $b['date'], $b['window']['start_time']])
            ->first();

        if ($best !== null) {
            $out['suggested'] = $this->shown($best) + [
                'same_as_earliest' => $best['date'] === $earliest['date'] && $best['window'] === $earliest['window'],
            ];
        }

        return $out;
    }

    /** @param  array<string, mixed>  $c */
    private function shown(array $c): array
    {
        return array_intersect_key($c, array_flip(['date', 'day', 'window', 'window_index', 'nearby', 'nearest_miles', 'technicians', 'jobs']));
    }

    /**
     * A day's booked jobs as the map and the score read them.
     *
     * @param  array<int, array<string, mixed>>  $windows
     * @param  ?array{latitude: float, longitude: float}  $origin
     */
    private function placed(Collection $jobs, array $windows, ?array $origin, float $radius): Collection
    {
        return $jobs->map(function (object $j) use ($windows, $origin, $radius) {
            $time = substr((string) $j->scheduled_for, 11, 5);
            $payload = is_string($j->payload ?? null) ? (json_decode($j->payload, true) ?: []) : [];
            $window = null;
            foreach ($windows as $i => $w) {
                if ($time !== '00:00' && $time >= $w['start_time'] && $time < $w['end_time']) {
                    $window = $i;
                }
            }
            $miles = $origin === null ? null
                : round(self::miles($origin['latitude'], $origin['longitude'], (float) $j->latitude, (float) $j->longitude), 1);

            return [
                'nearby' => $miles !== null && $miles <= $radius,
                'zip' => $j->postal_code ?? null,
                'city' => $j->city ?? null,
                'latitude' => (float) $j->latitude,
                'longitude' => (float) $j->longitude,
                'miles' => $miles,
                'time' => $time === '00:00' ? null : $time,
                'window' => $window,
                'technicians' => array_values(array_filter(array_map(
                    fn ($t) => is_array($t) ? ($t['Name'] ?? null) : null,
                    is_array($payload['Team'] ?? null) ? $payload['Team'] : [],
                ))),
            ];
        });
    }

    /**
     * The centre of a zip, from A-1's own jobs there.
     *
     * @return array{zip: string, latitude: float, longitude: float}|null
     */
    private function centre(string $organizationId, string $zip): ?array
    {
        $row = DB::table('workiz_jobs')
            ->where('organization_id', $organizationId)
            ->where('postal_code', 'like', $zip.'%')
            ->whereNotNull('latitude')->where('latitude', '!=', 0)
            ->selectRaw('AVG(latitude) lat, AVG(longitude) lng, COUNT(*) n')
            ->first();

        return $row === null || (int) $row->n === 0 ? null
            : ['zip' => $zip, 'latitude' => round((float) $row->lat, 5), 'longitude' => round((float) $row->lng, 5)];
    }

    /** Great-circle miles. */
    public static function miles(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);
        $a = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;

        return 2 * 3958.8 * asin(min(1, sqrt($a)));
    }
}
