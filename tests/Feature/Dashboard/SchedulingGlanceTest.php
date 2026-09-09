<?php

namespace Tests\Feature\Dashboard;

use App\Models\ServiceArea;
use App\Models\ServiceAreaSlot;
use App\Models\ServiceAreaZip;
use App\Support\Dashboard\DashboardPeriod;
use App\Support\Dashboard\Glances\SchedulingGlance;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/**
 * The Scheduling box shows CONFIGURED capacity, and says so.
 *
 * It replaced a placeholder reading "will show what is booked here once the
 * Schedule tab is live" — a sentence whose condition was already met and whose
 * promise nothing can keep until Scheduled Jobs exists. The tests that matter
 * are therefore about the WORDING as much as the arithmetic: a box that says
 * "booked" while counting configuration is the failure being fixed.
 */
class SchedulingGlanceTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    private function glance(): SchedulingGlance
    {
        return new SchedulingGlance;
    }

    private function area(array $tenant, string $name): ServiceArea
    {
        app(TenantContext::class)->set($tenant['org']);

        return ServiceArea::create([
            'franchise_id' => $tenant['franchise']->id,
            'name' => $name,
        ]);
    }

    private function slot(ServiceArea $area, int $weekday, int $jobs): void
    {
        ServiceAreaSlot::create([
            'service_area_id' => $area->id,
            'weekday' => $weekday,
            'start_time' => '07:00',
            'end_time' => '11:00',
            'max_jobs' => $jobs,
        ]);
    }

    /** A snapshot, deliberately: capacity has no history to bucket. */
    public function test_it_is_a_snapshot_so_the_period_chips_do_not_move_it(): void
    {
        $this->assertTrue($this->glance()->snapshot());
    }

    public function test_with_no_areas_it_says_to_add_one_rather_than_showing_zero(): void
    {
        $tenant = $this->provisionTenant('Alpha');

        $data = $this->glance()
            ->resolve($tenant['admin'], DashboardPeriod::for($tenant['admin']), [])
            ->toArray()['data'];

        $this->assertTrue($data['empty']);
        $this->assertStringContainsString('No service areas yet', $data['sentence']);
    }

    /**
     * Seven bars whatever the week looks like, and a closed day drawn as a
     * real zero — a four-day week must not look like a full one.
     */
    public function test_every_weekday_gets_a_point_and_a_closed_day_is_zero(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $dallas = $this->area($tenant, 'Dallas');

        $this->slot($dallas, 1, 2); // Monday
        $this->slot($dallas, 4, 3); // Thursday

        $data = $this->glance()
            ->resolve($tenant['admin'], DashboardPeriod::for($tenant['admin']), [])
            ->toArray()['data'];

        $points = collect($data['series'][0]['points']);

        $this->assertCount(7, $points, 'The week is always seven columns.');
        $this->assertSame(2, $points->firstWhere('at', 'Mon')['value']);
        $this->assertSame(3, $points->firstWhere('at', 'Thu')['value']);
        $this->assertSame(
            0,
            $points->firstWhere('at', 'Sun')['value'],
            'A day with no slots is a drawn zero, not a missing column.',
        );
    }

    /** Capacity sums across areas — the box is the whole business in view. */
    public function test_capacity_sums_across_areas_and_is_named_configured(): void
    {
        $tenant = $this->provisionTenant('Alpha');

        $this->slot($this->area($tenant, 'Dallas'), 1, 2);
        $this->slot($this->area($tenant, 'Houston'), 1, 3);

        $data = $this->glance()
            ->resolve($tenant['admin'], DashboardPeriod::for($tenant['admin']), [])
            ->toArray()['data'];

        $points = collect($data['series'][0]['points']);
        $this->assertSame(5, $points->firstWhere('at', 'Mon')['value']);

        $sentences = implode(' ', $data['sentences']);

        $this->assertStringContainsString('5 jobs a week', $sentences);
        $this->assertStringContainsString('2 service areas', $sentences);

        // The promise the old placeholder made and this must never make.
        $this->assertStringContainsString('not what is booked', $sentences);
        $this->assertStringNotContainsString('remaining', $sentences);
    }

    /**
     * An area with no hours contributes zero to every bar, so it is invisible
     * on the chart and looks exactly like a quiet business. It gets a sentence.
     */
    public function test_an_area_with_no_hours_is_named_because_the_chart_cannot_show_it(): void
    {
        $tenant = $this->provisionTenant('Alpha');

        $this->slot($this->area($tenant, 'Dallas'), 1, 2);
        $this->area($tenant, 'Houston'); // no slots at all

        $data = $this->glance()
            ->resolve($tenant['admin'], DashboardPeriod::for($tenant['admin']), [])
            ->toArray()['data'];

        $sentences = implode(' ', $data['sentences']);

        $this->assertStringContainsString('1 service area has no hours set', $sentences);
    }

    /** Another organization's coverage is never counted. */
    public function test_it_counts_only_areas_in_reach(): void
    {
        $alpha = $this->provisionTenant('Alpha');
        $beta = $this->provisionTenant('Beta', 'beta@example.test');

        $this->slot($this->area($alpha, 'Dallas'), 1, 2);

        app(TenantContext::class)->set($beta['org']);
        $betaArea = ServiceArea::create([
            'franchise_id' => $beta['franchise']->id,
            'name' => 'Atlanta',
        ]);
        $this->slot($betaArea, 1, 9);
        ServiceAreaZip::create([
            'franchise_id' => $beta['franchise']->id,
            'service_area_id' => $betaArea->id,
            'zip' => '30301',
        ]);

        $data = $this->glance()
            ->resolve($alpha['admin'], DashboardPeriod::for($alpha['admin']), [])
            ->toArray()['data'];

        $points = collect($data['series'][0]['points']);

        $this->assertSame(
            2,
            $points->firstWhere('at', 'Mon')['value'],
            "Beta's nine jobs must not appear on Alpha's dashboard.",
        );
        $this->assertStringContainsString('1 service area', implode(' ', $data['sentences']));
    }
}
