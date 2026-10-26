<?php

namespace App\Support\Scheduling;

use App\Models\Company;
use App\Models\Franchise;
use App\Models\ServiceArea;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Which days are overbooked, and which areas are fully booked — for the
 * Scheduling › Overbooking tab and the daily warning email (Jose,
 * 2026-09-29). Every number is Openings', so this cannot disagree with the
 * search, the calendar or Reports.
 *
 * OVERBOOKED: more seat-holding jobs on a day than that day takes. For an area
 * that is its time slots added up, lowered by its own daily limit; a closed day
 * with anything booked is overbooked by all of it. For a franchise it is its
 * daily limit, when it has one.
 *
 * FULLY BOOKED: an area that works in the window and has no spot left on any
 * day of it. An area that is simply closed all window is not "full", and one
 * nobody set up is neither — the same three absences Openings keeps apart.
 */
final class Overbooking
{
    /**
     * Area days and franchise days booked past what they take.
     *
     * @param  Collection<int, ServiceArea>  $areas
     * @return array<int, array<string, mixed>> sorted by date, then name
     */
    public function overbooked(Collection $areas, CarbonImmutable $from, int $days): array
    {
        $openings = app(Openings::class);
        $franchises = Franchise::whereIn('id', $areas->pluck('franchise_id')->unique())->pluck('name', 'id');
        $rows = [];
        $franchiseDays = [];

        // A shared pool is reported once, under its lead.
        foreach ($areas->whereNull('shares_with_id') as $area) {
            foreach ($openings->days($area, $from, $days) as $day) {
                $booked = array_sum(array_column($day['windows'], 'taken')) + $day['no_time'];
                $capacity = array_sum(array_column($day['windows'], 'capacity'));
                if ($day['area_limit'] !== null) {
                    $capacity = min($capacity, $day['area_limit']);
                }

                if ($booked > $capacity) {
                    $rows[] = [
                        'level' => 'area',
                        'date' => $day['date'],
                        'area_id' => $area->id,
                        'franchise_id' => $area->franchise_id,
                        'name' => $area->name,
                        'franchise_name' => $franchises[$area->franchise_id] ?? null,
                        'capacity' => $capacity,
                        'booked' => $booked,
                        'over' => $booked - $capacity,
                        'closed' => $day['windows'] === [],
                    ];
                }

                // Every area of a franchise reports the same franchise figures
                // for a day; keep one.
                if ($day['franchise_limit'] !== null) {
                    $franchiseDays[$area->franchise_id.'|'.$day['date']] = [$area->franchise_id, $day];
                }
            }
        }

        foreach ($franchiseDays as [$franchiseId, $day]) {
            if ($day['franchise_booked'] > $day['franchise_limit']) {
                $rows[] = [
                    'level' => 'franchise',
                    'date' => $day['date'],
                    'area_id' => null,
                    'franchise_id' => $franchiseId,
                    'name' => $franchises[$franchiseId] ?? '',
                    'franchise_name' => $franchises[$franchiseId] ?? null,
                    'capacity' => $day['franchise_limit'],
                    'booked' => $day['franchise_booked'],
                    'over' => $day['franchise_booked'] - $day['franchise_limit'],
                    'closed' => false,
                ];
            }
        }

        usort($rows, fn ($a, $b) => [$a['date'], $a['franchise_name'], $a['level'], $a['name']]
            <=> [$b['date'], $b['franchise_name'], $b['level'], $b['name']]);

        return $rows;
    }

    /**
     * Every area's day, booked against what it takes: the "booked vs
     * available" list (Shoval, 2026-10-26: "last friday we had empty spots,
     * when we are usually overbooked"). Areas with no capacity that day are
     * left out; a shared pool is one row, under its lead.
     *
     * @param  Collection<int, ServiceArea>  $areas
     * @return list<array<string, mixed>> sorted by company, branch, area
     */
    public function fill(Collection $areas, CarbonImmutable $date): array
    {
        $openings = app(Openings::class);
        $franchises = Franchise::whereIn('id', $areas->pluck('franchise_id')->unique())->get(['id', 'name', 'company_id'])->keyBy('id');
        $companies = Company::whereIn('id', $franchises->pluck('company_id')->unique())->pluck('name', 'id');
        $rows = [];

        foreach ($areas->whereNull('shares_with_id') as $area) {
            $day = $openings->days($area, $date, 1)[0];
            $capacity = array_sum(array_column($day['windows'], 'capacity'));
            if ($day['area_limit'] !== null) {
                $capacity = min($capacity, $day['area_limit']);
            }
            $booked = array_sum(array_column($day['windows'], 'taken')) + $day['no_time'];
            if ($capacity === 0 && $booked === 0) {
                continue;
            }
            $franchise = $franchises[$area->franchise_id] ?? null;
            $rows[] = [
                'date' => $day['date'],
                'area_id' => $area->id,
                'name' => $area->name,
                'franchise_id' => $area->franchise_id,
                'franchise_name' => $franchise?->name,
                'company_id' => $franchise?->company_id,
                'company_name' => $companies[$franchise?->company_id] ?? null,
                'capacity' => $capacity,
                'booked' => $booked,
                // Positive is open spots, negative is overbooked.
                'gap' => $capacity - $booked,
                'fill_pct' => $capacity === 0 ? null : (int) round(100 * $booked / $capacity),
                'windows' => array_map(fn ($w) => [
                    'start_time' => $w['start_time'], 'end_time' => $w['end_time'],
                    'capacity' => $w['capacity'], 'taken' => $w['taken'],
                ], $day['windows']),
            ];
        }

        usort($rows, fn ($a, $b) => [$a['company_name'], $a['franchise_name'], $a['name']] <=> [$b['company_name'], $b['franchise_name'], $b['name']]);

        return $rows;
    }

    /**
     * How far ahead an area may be full before somebody must act. Shoval,
     * 2026-10-05: "I basically want to know if there is a [service area]
     * fully booked for over 10 days. 10 days and above ... I need to pause
     * ads otherwise I'm going to lose money". The morning email, the
     * Overbooking tab and the dashboard all read this one number.
     */
    public const FULL_DAYS = 10;

    /**
     * Areas that work in the window and have no spot left on any day of it.
     *
     * @param  Collection<int, ServiceArea>  $areas
     * @return array<int, array<string, mixed>> sorted by franchise, then name
     */
    public function fullyBooked(Collection $areas, CarbonImmutable $from, int $days): array
    {
        $openings = app(Openings::class);
        $franchises = Franchise::whereIn('id', $areas->pluck('franchise_id')->unique())->pluck('name', 'id');
        $rows = [];

        // A shared pool is reported once, under its lead.
        foreach ($areas->whereNull('shares_with_id') as $area) {
            if (! Openings::hasCapacity($area)) {
                continue;
            }

            $window = $openings->days($area, $from, $days);
            $works = array_filter($window, fn ($d) => $d['windows'] !== []);

            if ($works === []) {
                continue;
            }

            $open = array_filter($works, fn ($d) => collect($d['windows'])->contains(fn ($w) => $w['remaining'] > 0));

            if ($open === []) {
                $next = $openings->next($area, $from->addDays($days), 0)['first']['date'] ?? null;
                $rows[] = [
                    'area_id' => $area->id,
                    'name' => $area->name,
                    'franchise_name' => $franchises[$area->franchise_id] ?? null,
                    'working_days' => count($works),
                    'next_opening' => $next,
                ];
            }
        }

        usort($rows, fn ($a, $b) => [$a['franchise_name'], $a['name']] <=> [$b['franchise_name'], $b['name']]);

        return $rows;
    }
}
