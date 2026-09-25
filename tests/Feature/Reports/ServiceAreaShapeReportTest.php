<?php

namespace Tests\Feature\Reports;

use App\Models\ServiceArea;
use App\Models\ServiceAreaDateOverride;
use App\Models\ServiceAreaSlot;
use App\Support\Reports\ReportRunner;
use App\Support\Scheduling\Openings;
use App\Support\Scoping\ViewScope;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
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

    // ── the assumption both columns rest on ─────────────

    /**
     * A SLOT IS PER WEEKDAY, NOT PER DATE — and this fails when that changes.
     *
     * Both derived columns read `service_area_slots` as a weekly pattern.
     * `count(distinct weekday)` is "days a week" and
     * `min((weekday + 7 - today) % 7)` is "days until the next open day" ONLY
     * while a row means "every Tuesday" rather than "Tuesday the 14th".
     *
     * ── WHY THIS IS A TEST AND NOT A NOTE ─────────────────────
     *
     * Scheduling's spec has per-date overrides as §3.4, unbuilt. The day it
     * lands, a row becomes per DATE and `count(distinct weekday)` still returns
     * a number — it just stops meaning what the column is labelled. Nothing
     * errors, no existing test reds, and a warning that was true keeps being
     * sent with a different meaning. They have recorded it on their own model
     * and in their spec (d89aa2c5) and have said they will tell me.
     *
     * That is two people remembering. This is the mechanism: a `date` column
     * appearing on that table fails HERE, in the suite that owns the claim,
     * with the reason attached — whether or not anybody remembered.
     *
     * Asserted narrowly on purpose. Any new column at all would be noisy and a
     * noisy guard gets deleted; what invalidates these columns specifically is
     * a per-date dimension, so that is what is named. A rename of `weekday`
     * fails the first assertion for the same reason.
     */
    public function test_a_slot_is_still_a_weekly_pattern(): void
    {
        $columns = collect(Schema::getColumns('service_area_slots'))
            ->keyBy('name');

        $this->assertTrue(
            $columns->has('weekday'),
            'service_area_slots has no `weekday` column, so "days a week" and '
            .'"days until the next open day" are both reading something else now. '
            .'Both derived columns in ReportMap need rewriting, not extending.',
        );

        $dated = $columns
            ->reject(fn (array $c) => in_array($c['name'], ['created_at', 'updated_at'], true))
            ->filter(fn (array $c) => in_array($c['type_name'], ['date', 'datetime', 'timestamp'], true))
            ->keys()
            ->all();

        $this->assertSame(
            [],
            $dated,
            'service_area_slots gained a date column ('.implode(', ', $dated).'), so a slot row '
            .'may no longer mean "every week". ReportMap\'s `open_days_a_week` and '
            .'`days_until_next_open_day` still return numbers and have stopped meaning what they '
            .'say — and they feed warnings that email and text. Rewrite them against the new '
            .'grain before this is made green.',
        );
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

    // ── dates, not just weekdays (Scheduling b7bb9048) ─────────────

    /**
     * THE COLUMN AGREES WITH SCHEDULING'S OWN ANSWER, date by date.
     *
     * `Openings::days()` is the one function Scheduling says folds in the
     * weekday pattern, per-date overrides and `available_from`. This column is
     * SQL and cannot call it, so it is pinned against it instead: every
     * fixture below is asked both ways and the two must agree, then the
     * expected number is asserted too, so an oracle that went wrong in step
     * with the SQL still fails.
     *
     * "Works", not "has room": the oracle is read as `windows != []`, which
     * Scheduling pointed out - `Openings::next()` would also skip full days.
     */
    public function test_it_agrees_with_openings_on_overrides_and_the_horizon(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-09-23')); // a Wednesday

        $tenant = $this->provisionTenant('Alpha');

        $expected = [
            // The pattern alone: Friday is two days out.
            'Pattern only' => 2,
            // This Friday closed by a no-window row: the next one, 9 days out.
            'Friday closed' => 9,
            // A window row on this Friday, alongside the no-window row that
            // closes it: closed wins, whatever else is there.
            'Friday closed, with a window too' => 9,
            // A Monday-only area opened this Thursday by an override.
            'Thursday opened' => 1,
            // Open today by pattern, but not taking bookings until Monday the
            // 28th: the first working day on or after it is Wednesday the 30th.
            'Closed until Monday' => 7,
            // Today's pattern REPLACED by an override window - still open.
            'Today replaced' => 0,
            // No pattern and no override: the third state, not a number.
            'Never set up' => null,
        ];

        $this->inTenant($tenant['org'], function () use ($tenant) {
            $this->area($tenant, 'Pattern only', [5]);

            $this->area($tenant, 'Friday closed', [5]);
            $this->override($tenant, 'Friday closed', '2026-09-25');

            $this->area($tenant, 'Friday closed, with a window too', [5]);
            $this->override($tenant, 'Friday closed, with a window too', '2026-09-25');
            $this->override($tenant, 'Friday closed, with a window too', '2026-09-25', window: true);

            $this->area($tenant, 'Thursday opened', [1]);
            $this->override($tenant, 'Thursday opened', '2026-09-24', window: true);

            $this->area($tenant, 'Closed until Monday', [3]);
            ServiceArea::query()->where('name', 'Closed until Monday')->update(['available_from' => '2026-09-28']);

            $this->area($tenant, 'Today replaced', [3]);
            $this->override($tenant, 'Today replaced', '2026-09-23', window: true);

            $this->area($tenant, 'Never set up', []);
        });

        $rows = collect($this->rowsFor($tenant, []))
            ->keyBy(fn (array $row) => $row['service_areas.name']);

        foreach ($expected as $name => $days) {
            $column = $rows[$name]['service_areas.days_until_next_open_day'];
            $column = $column === null ? null : (int) $column;

            $oracle = $this->inTenant($tenant['org'], function () use ($name) {
                $area = ServiceArea::query()->where('name', $name)->firstOrFail();
                $calendar = app(Openings::class)->days($area, CarbonImmutable::today(), 366);

                foreach ($calendar as $i => $day) {
                    if ($day['windows'] !== []) {
                        return $i;
                    }
                }

                return null;
            });

            $this->assertSame($oracle, $column, "{$name}: the column and Openings::days() disagree");
            $this->assertSame($days, $column, "{$name}: both agree, on the wrong day");
        }
    }

    /** A per-date override: no window closes the day, a window opens one. */
    private function override(array $tenant, string $area, string $date, bool $window = false): void
    {
        ServiceAreaDateOverride::query()->create([
            'organization_id' => $tenant['org']->id,
            'service_area_id' => ServiceArea::query()->where('name', $area)->value('id'),
            'date' => $date,
            'start_time' => $window ? '09:00' : null,
            'end_time' => $window ? '12:00' : null,
            'max_jobs' => $window ? 2 : null,
        ]);
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
