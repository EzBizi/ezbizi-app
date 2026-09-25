<?php

namespace App\Support\Reports;

use App\Models\ServiceArea;
use App\Support\Scheduling\Openings;
use Carbon\CarbonImmutable;

/**
 * "Is this service area booked out for the next X days", for the report
 * builder - Jose, 2026-09-22, to warn by email or text through an automation.
 *
 * Read through Scheduling's `Openings`, NOT a copy of its SQL. Their count of
 * what takes a seat (Workiz statuses, deposit tags, the no-time midnight) and
 * the switch from `workiz_jobs` to `jobs.service_area_id` both happen inside
 * that one function, so calling it is how this column follows them.
 *
 * Three states, never collapsed (Scheduling's rule):
 *
 *     0..364  days from today until a window with room
 *     365     works in the next year, and every working day is full
 *     NULL    never set up, OR closed for the whole year - neither is "full"
 */
final class ServiceAreaFullness
{
    public function __construct(private readonly Openings $openings) {}

    public function daysUntilFreeSpot(string $id, CarbonImmutable $today): ?int
    {
        $area = ServiceArea::query()->find($id);

        if ($area === null || ! Openings::hasCapacity($area)) {
            return null;
        }

        $works = false;

        foreach ($this->openings->days($area, $today, Openings::HORIZON_DAYS) as $i => $day) {
            foreach ($day['windows'] as $window) {
                $works = true;

                if ($window['remaining'] > 0) {
                    return $i;
                }
            }
        }

        return $works ? Openings::HORIZON_DAYS : null;
    }
}
