<?php

namespace Tests\Feature\Reports;

use App\Models\ServiceArea;
use App\Models\ServiceAreaSlot;
use App\Support\Reports\ReportRunner;
use App\Support\Scoping\ViewScope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/**
 * "Which service areas are not taking work?" — the half that is answerable now.
 *
 * Jose, 2026-09-23: warn me when a service area is booked out, so a rule can
 * email or text. The booked half is blocked — nothing links a Job to a service
 * area, so no booked count exists — and Scheduling named the trap that outlives
 * that blocker: "is this area taking work" has THREE ways to be empty.
 *
 *     no capacity left    open, taking nothing more     <- booked out
 *     closed              not a working day             <- NOT booked out
 *     unconfigured        nobody ever set this up       <- NOT booked out
 *
 * On production today 21 of 45 areas have no slot row at all, so a report that
 * collapses these fires on nearly half of them — into email and SMS.
 *
 * These tests pin the two states that ARE separable from configuration alone,
 * and pin that neither is labelled "booked out".
 */
class ServiceAreaShapeReportTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    /**
     * AN AREA NOBODY CONFIGURED IS FOUND, AND A WORKING ONE IS NOT.
     *
     * Both halves in one test on purpose. A filter that returned everything
     * satisfies "the unconfigured area appears" perfectly, and a filter that
     * returned nothing satisfies no other assertion here — so the pair is what
     * distinguishes a working filter from either failure.
     */
    public function test_it_finds_areas_with_no_open_day_and_leaves_working_ones_out(): void
    {
        $tenant = $this->provisionTenant('Alpha');

        $this->inTenant($tenant['org'], function () use ($tenant) {
            $working = ServiceArea::query()->create([
                'organization_id' => $tenant['org']->id,
                'franchise_id' => $tenant['franchise']->id,
                'name' => 'Dallas',
            ]);

            ServiceAreaSlot::query()->create([
                'organization_id' => $tenant['org']->id,
                'service_area_id' => $working->id,
                'weekday' => 2,
                'start_time' => '09:00',
                'end_time' => '12:00',
                'max_jobs' => 3,
            ]);

            ServiceArea::query()->create([
                'organization_id' => $tenant['org']->id,
                'franchise_id' => $tenant['franchise']->id,
                'name' => 'Never set up',
            ]);
        });

        $rows = $this->rowsFor($tenant, [[
            'resource' => 'service_areas',
            'column' => 'open_days_a_week',
            'op' => 'eq',
            'value' => 0,
        ]]);

        $this->assertCount(1, $rows);
        $this->assertSame('Never set up', $rows[0]['service_areas.name']);
    }

    /**
     * THE DERIVED VALUE THAT IS PRINTED IS THE ONE THAT WAS FILTERED ON.
     *
     * The filter and the select are the same SQL expression, written once. Two
     * spellings of one derived value is how a report comes to exclude a row it
     * then displays — and the row would look correct in isolation, which is the
     * kind of wrong nobody reports.
     */
    public function test_the_printed_value_agrees_with_the_filter(): void
    {
        $tenant = $this->provisionTenant('Alpha');

        $this->inTenant($tenant['org'], function () use ($tenant) {
            $area = ServiceArea::query()->create([
                'organization_id' => $tenant['org']->id,
                'franchise_id' => $tenant['franchise']->id,
                'name' => 'Two days, ten jobs',
            ]);

            // TWO SLOTS ON ONE WEEKDAY, deliberately. With one slot per day
            // count(*) and count(distinct weekday) agree, and this test could
            // not tell the select from the filter if they ever came apart.
            // Monday morning and Monday afternoon is also the ordinary shape.
            foreach ([[1, 5], [1, 2], [4, 3]] as [$weekday, $jobs]) {
                ServiceAreaSlot::query()->create([
                    'organization_id' => $tenant['org']->id,
                    'service_area_id' => $area->id,
                    'weekday' => $weekday,
                    'start_time' => $jobs === 2 ? '13:00' : '09:00',
                    'end_time' => $jobs === 2 ? '17:00' : '12:00',
                    'max_jobs' => $jobs,
                ]);
            }
        });

        $rows = $this->rowsFor($tenant, [[
            'resource' => 'service_areas',
            'column' => 'open_days_a_week',
            'op' => 'gt',
            'value' => 0,
        ]]);

        $this->assertCount(1, $rows);
        $this->assertSame(2, (int) $rows[0]['service_areas.open_days_a_week']);
        $this->assertSame(10, (int) $rows[0]['service_areas.jobs_a_week']);
    }

    /**
     * OPEN AND TAKING NOTHING IS ITS OWN STATE.
     *
     * An area with slots whose capacity is zero is configured, open on the
     * calendar, and accepting no work. It is NOT unconfigured, and a report
     * that told somebody to "set this area up" would send them to a screen
     * that is already filled in.
     */
    public function test_an_area_open_with_no_capacity_is_not_the_same_as_unconfigured(): void
    {
        $tenant = $this->provisionTenant('Alpha');

        $this->inTenant($tenant['org'], function () use ($tenant) {
            $area = ServiceArea::query()->create([
                'organization_id' => $tenant['org']->id,
                'franchise_id' => $tenant['franchise']->id,
                'name' => 'Open, takes nothing',
            ]);

            ServiceAreaSlot::query()->create([
                'organization_id' => $tenant['org']->id,
                'service_area_id' => $area->id,
                'weekday' => 3,
                'start_time' => '09:00',
                'end_time' => '12:00',
                'max_jobs' => 0,
            ]);
        });

        $rows = $this->rowsFor($tenant, []);

        $this->assertCount(1, $rows);

        // Distinguishable from unconfigured by the pair, which is the whole
        // reason there are two columns rather than one "is it working" flag.
        $this->assertSame(1, (int) $rows[0]['service_areas.open_days_a_week']);
        $this->assertSame(0, (int) $rows[0]['service_areas.jobs_a_week']);
    }

    /** @param  list<array<string, mixed>>  $filters */
    private function rowsFor(array $tenant, array $filters): array
    {
        return $this->inTenant($tenant['org'], fn () => app(ReportRunner::class)->run([
            'root' => 'service_areas',
            'columns' => [
                ['resource' => 'service_areas', 'column' => 'name'],
                ['resource' => 'service_areas', 'column' => 'open_days_a_week'],
                ['resource' => 'service_areas', 'column' => 'jobs_a_week'],
            ],
            'filters' => $filters,
        ], $tenant['admin'], ViewScope::forUser($tenant['admin'])))->rows;
    }
}
