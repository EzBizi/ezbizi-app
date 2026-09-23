<?php

namespace Tests\Feature\Reports;

use App\Models\ServiceArea;
use App\Models\ServiceAreaSlot;
use App\Support\Reports\ReportRunner;
use App\Support\Scoping\ViewScope;
use Carbon\CarbonImmutable;
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

    protected function tearDown(): void
    {
        // Released explicitly: a frozen clock leaking into the next test is the
        // kind of failure that lands on somebody else's file.
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

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

    // ── the rolling window ────────────────────────────

    /**
     * THE DISTANCE IS COUNTED FROM TODAY, AND IT WRAPS.
     *
     * Jose asked for "no open day in the next X days" and got the weekly shape
     * first, with me saying a horizon was not expressible. It is — as a
     * DISTANCE rather than a horizon, so the number lives in the filter and
     * every window is one column.
     *
     * Time is frozen because the subject is arithmetic ON today: run on a
     * Thursday, an area open only on Monday is three days away, and a test that
     * read the real clock would assert a different number every day and be
     * green on the day it was written.
     */
    public function test_it_counts_the_days_until_the_next_open_day(): void
    {
        // A Wednesday. dayOfWeek 3, matching what ServiceAreaSlot stores and
        // what SchedulingAvailabilityController reads.
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-09-23'));
        $this->assertSame(3, CarbonImmutable::today()->dayOfWeek, 'the fixture is not a Wednesday');

        $tenant = $this->provisionTenant('Alpha');

        $this->inTenant($tenant['org'], function () use ($tenant) {
            // Open Wednesday: today, so zero.
            $this->area($tenant, 'Open today', [3]);

            // Open Friday: two days out.
            $this->area($tenant, 'Friday only', [5]);

            // Open MONDAY, which is BEHIND us this week - the wrap is the part
            // that a naive subtraction gets wrong, returning -2.
            $this->area($tenant, 'Monday only', [1]);
        });

        // keyBy with a CLOSURE, not the string: 'service_areas.name' is a
        // KEY with a dot in it, and data_get would read it as a path and look
        // for $row['service_areas']['name']. The report's own run view had the
        // same bug against the same column names.
        $rows = collect($this->rowsFor($tenant, []))
            ->keyBy(fn (array $row) => $row['service_areas.name']);

        $this->assertSame(0, (int) $rows['Open today']['service_areas.days_until_next_open_day']);
        $this->assertSame(2, (int) $rows['Friday only']['service_areas.days_until_next_open_day']);
        $this->assertSame(5, (int) $rows['Monday only']['service_areas.days_until_next_open_day']);
    }

    /**
     * THE WINDOW IS THE FILTER'S VALUE, which is the whole point.
     *
     * One column answers every horizon. Asserted at two different windows in
     * one test, because a filter that ignored its value would satisfy either
     * alone.
     */
    public function test_the_same_column_answers_two_different_windows(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-09-23'));

        $tenant = $this->provisionTenant('Alpha');

        $this->inTenant($tenant['org'], function () use ($tenant) {
            $this->area($tenant, 'Friday only', [5]);      // 2 days out
            $this->area($tenant, 'Monday only', [1]);      // 5 days out
        });

        $nothingForTwo = $this->rowsFor($tenant, [[
            'resource' => 'service_areas',
            'column' => 'days_until_next_open_day',
            'op' => 'gte',
            'value' => 2,
        ]]);

        $nothingForFour = $this->rowsFor($tenant, [[
            'resource' => 'service_areas',
            'column' => 'days_until_next_open_day',
            'op' => 'gte',
            'value' => 4,
        ]]);

        $this->assertCount(2, $nothingForTwo);
        $this->assertCount(1, $nothingForFour);
        $this->assertSame('Monday only', $nothingForFour[0]['service_areas.name']);
    }

    /**
     * AN UNCONFIGURED AREA IS NOT "BOOKED OUT FOR THREE DAYS".
     *
     * `min()` over no rows is NULL, and SQL keeps NULL out of every comparison
     * — so it does not match the window, and nothing reports it as a booked-out
     * area. It is caught by `open_days_a_week = 0`, which names it as what it
     * is.
     *
     * This is Scheduling's three-state warning enforced by the database rather
     * than by remembering: on production 21 of 45 areas are in this state, so a
     * column that answered 0 or a large sentinel here would fire on half the
     * estate into email and SMS.
     */
    public function test_an_unconfigured_area_never_matches_a_window(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-09-23'));

        $tenant = $this->provisionTenant('Alpha');

        $this->inTenant($tenant['org'], fn () => $this->area($tenant, 'Never set up', []));

        // No window matches it, at either end of the range.
        foreach ([0, 3, 14] as $window) {
            $this->assertCount(0, $this->rowsFor($tenant, [[
                'resource' => 'service_areas',
                'column' => 'days_until_next_open_day',
                'op' => 'gte',
                'value' => $window,
            ]]), "an unconfigured area matched a {$window}-day window");
        }

        // The control: it IS found by the column that names its actual state.
        $found = $this->rowsFor($tenant, [[
            'resource' => 'service_areas',
            'column' => 'open_days_a_week',
            'op' => 'eq',
            'value' => 0,
        ]]);

        $this->assertCount(1, $found);
        $this->assertNull($found[0]['service_areas.days_until_next_open_day']);
    }

    /** @param  list<int>  $weekdays */
    private function area(array $tenant, string $name, array $weekdays): void
    {
        $area = ServiceArea::query()->create([
            'organization_id' => $tenant['org']->id,
            'franchise_id' => $tenant['franchise']->id,
            'name' => $name,
        ]);

        foreach ($weekdays as $weekday) {
            ServiceAreaSlot::query()->create([
                'organization_id' => $tenant['org']->id,
                'service_area_id' => $area->id,
                'weekday' => $weekday,
                'start_time' => '09:00',
                'end_time' => '12:00',
                'max_jobs' => 2,
            ]);
        }
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
                ['resource' => 'service_areas', 'column' => 'days_until_next_open_day'],
            ],
            'filters' => $filters,
        ], $tenant['admin'], ViewScope::forUser($tenant['admin'])))->rows;
    }
}
