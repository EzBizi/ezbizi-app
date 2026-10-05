<?php

namespace Tests\Feature\Dashboard;

use App\Models\Franchise;
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

    /**
     * The period cannot move this box, asserted as BEHAVIOUR rather than as a
     * flag.
     *
     * `test_it_is_a_snapshot...` above checks `snapshot()` returns true, which
     * is the DECLARATION. This checks the consequence: two different periods
     * must produce the same answer. A box that declared itself a snapshot and
     * then windowed its query would satisfy the first and fail this.
     *
     * It also settles the second axis the deploy desk raised — an all-clear
     * derived from a windowed query overclaims TIME as well as scope. Nothing
     * here is windowed, so no sentence in this box can. That is a property of
     * the query, and this is what holds it.
     */
    public function test_two_different_periods_give_the_same_answer(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $this->slot($this->area($tenant, 'Dallas'), 1, 3);

        $month = $this->glance()
            ->resolve($tenant['admin'], DashboardPeriod::for($tenant['admin'], 'month'), [])
            ->toArray()['data'];

        $week = $this->glance()
            ->resolve($tenant['admin'], DashboardPeriod::for($tenant['admin'], 'week'), [])
            ->toArray()['data'];

        $this->assertSame($month, $week, 'A snapshot must not vary with the period.');

        // The positive control: the fixture really did produce something, so
        // "identical" is not two empty boxes agreeing with each other.
        $this->assertFalse($month['empty']);
        $this->assertContains('Set up to take 3 jobs a week.', $month['sentences']);
    }

    public function test_with_no_areas_it_says_to_add_one_rather_than_showing_zero(): void
    {
        $tenant = $this->provisionTenant('Alpha');

        $data = $this->glance()
            ->resolve($tenant['admin'], DashboardPeriod::for($tenant['admin']), [])
            ->toArray()['data'];

        $this->assertTrue($data['empty']);

        // "in this view", never "yet". This box counts only the franchises in
        // reach, so it cannot know whether the ORGANIZATION has any — a reader
        // looking at one franchise was being told the company had none.
        $this->assertStringContainsString(
            'No service areas in this view',
            $data['sentence'],
        );
        $this->assertStringNotContainsString('yet', $data['sentence']);

        // The instruction is the half worth keeping: an empty state that
        // offers no way to make the first one is a front door with no handle.
        $this->assertStringContainsString('Scheduling settings', $data['sentence']);
    }

    /**
     * The claim the wording rests on, held by a test rather than by a comment.
     *
     * "In this view" is honest only while this box is SCOPED BY FRANCHISE. If
     * service areas ever became organization-wide the sentence would be
     * needlessly hedged and nothing would say so — the compliant sentence is
     * the fragile one, because a wrong sentence at least looks wrong to
     * somebody reading the file.
     *
     * TWO FRANCHISES IN ONE ORGANIZATION, and the view pinned to one. An
     * earlier version of this test used two ORGANIZATIONS, which proves
     * nothing about scoping: BelongsToTenant already isolates those, so it
     * would have passed with `franchiseIds()` deleted and left a comment
     * citing a test that did not test it.
     *
     * The view has to be SET. A user with no `navigation.scope` is in the
     * organization view however they are assigned, so the fixture would reach
     * both franchises and the assertion would be about the wrong rule.
     */
    public function test_capacity_in_another_franchise_does_not_reach_this_view(): void
    {
        $tenant = $this->provisionTenant('Alpha');

        app(TenantContext::class)->set($tenant['org']);

        $sibling = Franchise::create([
            'organization_id' => $tenant['org']->id,
            'company_id' => $tenant['company']->id,
            'name' => 'Georgia',
        ]);

        // All the capacity lives in the sibling franchise.
        $area = ServiceArea::create([
            'franchise_id' => $sibling->id,
            'name' => 'Atlanta',
        ]);
        $this->slot($area, 1, 4);

        // The reader is looking at their own franchise, not the sibling.
        $tenant['admin']->forceFill([
            'preferences' => ['navigation.scope' => 'franchise:'.$tenant['franchise']->id],
        ])->save();

        $data = $this->glance()
            ->resolve($tenant['admin'], DashboardPeriod::for($tenant['admin']), [])
            ->toArray()['data'];

        $this->assertTrue(
            $data['empty'],
            "Another franchise's capacity must not reach this view.",
        );

        // And with the view widened to the organization, the same rows arrive —
        // the positive control, without which "empty" above could mean the
        // fixture simply never created anything.
        $tenant['admin']->forceFill(['preferences' => []])->save();

        $wide = $this->glance()
            ->resolve($tenant['admin'], DashboardPeriod::for($tenant['admin']), [])
            ->toArray()['data'];

        $this->assertFalse(
            $wide['empty'],
            'The organization view should see the sibling capacity that was hidden.',
        );
    }

    /**
     * Areas but no hours says it ONCE, with a count.
     *
     * It used to say it twice: "N service areas have no hours set, so they can
     * take nothing." followed by "No hours set yet, so nothing can be taken."
     * `max_jobs` has a min of 1, so a total of zero means no slot rows exist,
     * which means every area is unconfigured — the two sentences described the
     * same state and one of them also claimed it about the organization.
     */
    public function test_areas_without_hours_are_reported_once_not_twice(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $this->area($tenant, 'Dallas');
        $this->area($tenant, 'Houston');

        $data = $this->glance()
            ->resolve($tenant['admin'], DashboardPeriod::for($tenant['admin']), [])
            ->toArray()['data'];

        $sentences = $data['sentences'];

        $this->assertFalse($data['empty'], 'Two areas exist, so this is not the empty box.');

        $noHours = array_values(array_filter(
            $sentences,
            fn (string $s) => str_contains($s, 'no hours set') || str_contains($s, 'nothing can be taken'),
        ));

        $this->assertCount(1, $noHours, 'One sentence, not two, for one fact.');
        $this->assertStringContainsString('2 service areas have no hours set', $noHours[0]);

        // And nothing anywhere in the box claims the organization.
        foreach ($sentences as $sentence) {
            $this->assertStringNotContainsString(
                'yet',
                $sentence,
                "A scoped box cannot say \"yet\": {$sentence}",
            );
        }
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
     * The figures: configured capacity first, named as capacity, then areas
     * and zip codes. Snapshots all, so no previous.
     */
    public function test_the_figures_lead_with_configured_capacity(): void
    {
        $tenant = $this->provisionTenant('Alpha');

        $dallas = $this->area($tenant, 'Dallas');
        $this->slot($dallas, 1, 2);
        $this->slot($dallas, 4, 3);
        $this->area($tenant, 'Houston'); // no hours: an area, and no capacity

        ServiceAreaZip::create([
            'franchise_id' => $tenant['franchise']->id,
            'service_area_id' => $dallas->id,
            'zip' => '75201',
        ]);

        $figures = $this->glance()
            ->resolve($tenant['admin'], DashboardPeriod::for($tenant['admin']), [])
            ->toArray()['data']['figures'];

        $this->assertSame([
            ['key' => 'capacity', 'label' => 'jobs a week capacity', 'value' => 5, 'previous' => null],
            ['key' => 'areas', 'label' => 'service areas', 'value' => 2, 'previous' => null],
            ['key' => 'zips', 'label' => 'zip code', 'value' => 1, 'previous' => null],
        ], $figures);
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
