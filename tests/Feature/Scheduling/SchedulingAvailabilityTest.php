<?php

namespace Tests\Feature\Scheduling;

use App\Models\Customer;
use App\Models\Franchise;
use App\Models\Job;
use App\Models\ServiceArea;
use App\Models\WorkizJob;
use App\Support\Scheduling\Openings;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/**
 * "When can we come out?" — the first day an area has room, and the next few.
 *
 * Laid out like A1 Tools' Availability tab (Jose, 2026-09-25). The checks that
 * carry a promise are the ones about what holds a SEAT: a remaining-count can
 * only be wrong in the optimistic direction, so it never announces itself —
 * A1 shipped with every occupancy figure silently zero and nobody noticed,
 * because "0 taken" reads as a quiet day. So every test here that asserts a
 * number of spots left has a booked job in it that must be subtracted.
 *
 * The clock is pinned: Wednesday 2026-09-30, 10 AM New York. Tomorrow is
 * Thursday 1 October, and the first Monday after it is 5 October.
 */
class SchedulingAvailabilityTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-09-30 10:00:00', 'America/New_York'));
    }

    /** Dallas works Mondays: 7–11 takes 2, 11–2 takes 1. Nothing else. */
    private function dallas(array $tenant): ServiceArea
    {
        app(TenantContext::class)->set($tenant['org']);

        $area = ServiceArea::create([
            'franchise_id' => $tenant['franchise']->id,
            'name' => 'Dallas',
        ]);

        $this->signedInAs($tenant['admin'])
            ->putJson("/api/v1/scheduling/areas/{$area->id}/slots", [
                'slots' => [
                    ['weekday' => 1, 'start_time' => '07:00', 'end_time' => '11:00', 'max_jobs' => 2],
                    ['weekday' => 1, 'start_time' => '11:00', 'end_time' => '14:00', 'max_jobs' => 1],
                ],
            ])->assertOk();

        $this->signedInAs($tenant['admin'])
            ->putJson("/api/v1/scheduling/areas/{$area->id}/zips", ['zips' => ['75201']])
            ->assertOk();

        return $area;
    }

    private function booked(
        array $tenant,
        string $at,
        string $status = 'Submitted',
        array $tags = [],
        string $area = 'Dallas',
        ?Franchise $franchise = null,
    ): void {
        app(TenantContext::class)->set($tenant['org']);

        WorkizJob::create([
            'franchise_id' => ($franchise ?? $tenant['franchise'])->id,
            'remote_id' => strtoupper(bin2hex(random_bytes(4))),
            'service_area' => $area,
            'postal_code' => '75201',
            'status' => $status,
            'scheduled_for' => $at,
            'payload' => ['Tags' => $tags],
            'synced_at' => now(),
        ]);
    }

    /**
     * A job as the Workiz projection leaves it: an EzBizi job placed in an
     * area, and its mirror row linked to it by `job_id`. The mirror's own area
     * NAME can say anything — the placement is what counts.
     */
    private function placed(
        array $tenant,
        ?ServiceArea $area,
        string $at,
        string $status = 'Submitted',
        string $workizName = 'Dallas',
        array $tags = [],
    ): Job {
        app(TenantContext::class)->set($tenant['org']);

        $customer = Customer::create([
            'owning_franchise_id' => $tenant['franchise']->id,
            'number' => 'C-'.bin2hex(random_bytes(3)),
            'name' => 'A Customer',
        ]);

        $job = Job::create([
            'franchise_id' => $tenant['franchise']->id,
            'customer_id' => $customer->id,
            'service_area_id' => $area?->id,
            'number' => 'J-'.bin2hex(random_bytes(3)),
            'title' => 'A job',
            'status' => $status,
            'scheduled_for' => $at,
        ]);

        WorkizJob::create([
            'franchise_id' => $tenant['franchise']->id,
            'remote_id' => strtoupper(bin2hex(random_bytes(4))),
            'service_area' => $workizName,
            'postal_code' => '75201',
            'status' => $status,
            'scheduled_for' => $at,
            'payload' => ['Tags' => $tags],
            'synced_at' => now(),
            'job_id' => $job->id,
        ]);

        return $job;
    }

    private function first(array $tenant, string $query): array
    {
        return $this->signedInAs($tenant['admin'])
            ->getJson("/api/v1/scheduling/availability?{$query}")
            ->assertOk()
            ->json('data.areas.0');
    }

    /** With nothing booked, the first Monday offers everything, and `basis` says remaining. */
    public function test_a_zip_finds_its_area_and_the_first_day_with_room(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $this->dallas($tenant);

        $body = $this->signedInAs($tenant['admin'])
            ->getJson('/api/v1/scheduling/availability?zip=75201')
            ->assertOk()
            ->json('data');

        $this->assertSame('remaining', $body['basis']);
        $this->assertSame('zip', $body['by']);
        $this->assertSame('2026-10-01', $body['from'], 'Today is never offered; the search starts tomorrow.');

        $area = $body['areas'][0];
        $this->assertSame('Dallas', $area['area_name']);
        $this->assertTrue($area['configured']);
        $this->assertSame('2026-10-05', $area['first']['date']);
        $this->assertSame([2, 1], array_column($area['first']['windows'], 'remaining'));
        $this->assertCount(5, $area['after']);
        $this->assertSame('2026-10-12', $area['after'][0]['date']);
    }

    /**
     * Mia, 2026-10-02: San Antonio is set up closed every day, and Availability
     * said "check Workiz" as if nobody had set it up. A week saved with every
     * day closed is set up, and closed.
     */
    public function test_an_area_saved_closed_every_day_is_closed_not_unknown(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $area = $this->dallas($tenant);

        $this->signedInAs($tenant['admin'])
            ->putJson("/api/v1/scheduling/areas/{$area->id}/slots", ['slots' => []])
            ->assertOk();

        $body = $this->first($tenant, 'zip=75201');
        $this->assertTrue($body['configured']);
        $this->assertNull($body['first']);
        $this->assertSame('closed', $body['none_because']);

        $listed = $this->signedInAs($tenant['admin'])->getJson('/api/v1/scheduling/areas')->assertOk()->json('data');
        $this->assertTrue(collect($listed)->firstWhere('id', $area->id)['configured']);
    }

    /** A date closed on its own (a holiday) is listed as closed, in place. */
    public function test_a_closed_date_is_listed_between_the_openings(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $area = $this->dallas($tenant);

        // Mondays are Oct 5, 12, 19 ...: close the 12th.
        $this->signedInAs($tenant['admin'])
            ->putJson("/api/v1/scheduling/areas/{$area->id}/dates/2026-10-12", ['closed' => true])
            ->assertOk();

        $body = $this->first($tenant, 'zip=75201');
        $this->assertSame('2026-10-05', $body['first']['date']);
        $this->assertSame([['date' => '2026-10-12', 'reason' => 'date']], $body['closed']);
        $this->assertNotContains('2026-10-12', array_column($body['after'], 'date'));
    }

    /**
     * An address a customer wrote is searched by the zip Google gives it
     * (Mia, 2026-10-02: "86 Jerusalem hill Trumbull"), and the answer says
     * which address the zip came from.
     */
    public function test_an_address_is_searched_by_its_zip(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $this->dallas($tenant);

        config(['services.google_maps.server_key' => 'maps-key']);
        Http::fake(['maps.googleapis.com/*' => Http::response(['status' => 'OK', 'results' => [[
            'formatted_address' => '1500 Marilla St, Dallas, TX 75201, USA',
            'address_components' => [['short_name' => '75201', 'types' => ['postal_code']]],
            'geometry' => ['location_type' => 'ROOFTOP'],
        ]]])]);

        $body = $this->signedInAs($tenant['admin'])
            ->getJson('/api/v1/scheduling/availability?address='.urlencode('1500 Marilla St Dallas'))
            ->assertOk()
            ->json('data');

        $this->assertSame('zip', $body['by']);
        $this->assertSame('75201', $body['resolved']['zip']);
        $this->assertSame('Dallas', $body['areas'][0]['area_name']);
    }

    public function test_an_address_with_no_zip_says_so(): void
    {
        $tenant = $this->provisionTenant('Alpha');

        config(['services.google_maps.server_key' => 'maps-key']);
        Http::fake(['maps.googleapis.com/*' => Http::response(['status' => 'ZERO_RESULTS', 'results' => []])]);

        $this->signedInAs($tenant['admin'])
            ->getJson('/api/v1/scheduling/availability?address=nowhere')
            ->assertStatus(422)
            ->assertJsonValidationErrors('address');
    }

    /**
     * A BOOKED JOB TAKES A SEAT, and the window is half-open. A job at exactly
     * 11:00 belongs to 11–2, not 7–11 — 11:00 is one of A-1's two most common
     * start times, so the boundary is where a wrong answer would land.
     */
    public function test_booked_jobs_take_seats_and_eleven_oclock_is_the_second_window(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $this->dallas($tenant);

        $this->booked($tenant, '2026-10-05 07:00:00');
        $this->booked($tenant, '2026-10-05 11:00:00');

        $first = $this->first($tenant, 'zip=75201')['first'];

        $this->assertSame('2026-10-05', $first['date']);
        $this->assertSame([1, 1], array_column($first['windows'], 'taken'));
        $this->assertSame([1, 0], array_column($first['windows'], 'remaining'));
    }

    /**
     * What does NOT hold a seat: a cancelled job, a done job, and a DEPOSIT
     * RECEIVED job (a different crew does those — New Jersey read 31 of 30
     * over three of them in A1). A status nobody has classified DOES hold one:
     * it constrains booking rather than freeing it. Both spellings of the tag
     * are live, typed by hand, with stray spaces.
     */
    public function test_cancelled_done_and_deposit_jobs_hold_no_seat_and_an_unknown_status_does(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $this->dallas($tenant);

        $this->booked($tenant, '2026-10-05 07:00:00', 'Canceled');
        $this->booked($tenant, '2026-10-05 08:00:00', 'Done');
        $this->booked($tenant, '2026-10-05 09:00:00', 'Submitted', ['DONT CHANGE TECH', 'DEPOSIT RECIEVED']);
        $this->booked($tenant, '2026-10-05 09:30:00', 'Submitted', ['deposit received ']);
        $this->booked($tenant, '2026-10-05 10:00:00', 'Something new');

        $first = $this->first($tenant, 'zip=75201')['first'];

        $this->assertSame(1, $first['windows'][0]['taken'], 'Only the unclassified status should count.');
    }

    /** A full day is skipped; the first Monday that still has room is offered. */
    public function test_a_full_day_is_skipped(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $this->dallas($tenant);

        foreach (['07:00', '08:00', '11:30'] as $time) {
            $this->booked($tenant, "2026-10-05 {$time}:00");
        }

        $area = $this->first($tenant, 'zip=75201');

        $this->assertSame('2026-10-12', $area['first']['date'], 'Monday the 5th is full and must be skipped.');
    }

    /**
     * A job with no time — Workiz writes midnight — holds no window's seat and
     * is counted separately, so the screen can say so rather than dropping it.
     * A job the same day in the 2–5 afternoon Dallas does not offer is the same.
     */
    public function test_a_job_with_no_time_or_outside_every_window_is_counted_apart(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $this->dallas($tenant);

        $this->booked($tenant, '2026-10-05 00:00:00');
        $this->booked($tenant, '2026-10-05 15:00:00');

        $first = $this->first($tenant, 'zip=75201')['first'];

        $this->assertSame([0, 0], array_column($first['windows'], 'taken'));
        $this->assertSame(2, $first['no_time']);
    }

    /**
     * A job another franchise's account tagged with this area's NAME is not
     * this area's booking. It is exactly the Nationwide call centre tagging
     * out-of-state work "New York".
     */
    public function test_a_job_from_another_franchise_under_the_same_name_holds_no_seat(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $this->dallas($tenant);

        app(TenantContext::class)->set($tenant['org']);
        $nationwide = Franchise::create([
            'organization_id' => $tenant['org']->id,
            'company_id' => $tenant['company']->id,
            'name' => 'Nationwide',
        ]);

        $this->booked($tenant, '2026-10-05 07:00:00', franchise: $nationwide);

        $first = $this->first($tenant, 'zip=75201')['first'];

        $this->assertSame(0, $first['windows'][0]['taken']);
    }

    /**
     * THE AREA IS WHERE EZBIZI PLACED THE JOB, not the name Workiz typed. A-1
     * still has jobs filed under the retired "Fort Worth", and jobs with no
     * area at all that the zip placed — both hold a seat where they were
     * placed (measured at the switch: one in DFW, one in Austin).
     */
    public function test_a_job_placed_in_the_area_holds_a_seat_whatever_workiz_named_it(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $dallas = $this->dallas($tenant);

        $this->placed($tenant, $dallas, '2026-10-05 07:00:00', workizName: 'Fort Worth');

        $this->assertSame(1, $this->first($tenant, 'zip=75201')['first']['windows'][0]['taken']);
    }

    /** And a job placed ELSEWHERE is not Dallas's just because Workiz says "Dallas" — nor counted twice. */
    public function test_a_projected_job_counts_once_and_only_where_it_was_placed(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $dallas = $this->dallas($tenant);

        app(TenantContext::class)->set($tenant['org']);
        $houston = ServiceArea::create(['franchise_id' => $tenant['franchise']->id, 'name' => 'Houston']);

        $this->placed($tenant, $houston, '2026-10-05 07:00:00');
        $this->placed($tenant, $dallas, '2026-10-05 08:00:00');

        $this->assertSame(1, $this->first($tenant, 'zip=75201')['first']['windows'][0]['taken']);
    }

    /** Placed jobs obey the same rules: the job's status, the mirror's tags, and a deleted job holds nothing. */
    public function test_a_placed_job_that_is_cancelled_tagged_deposit_or_deleted_holds_no_seat(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $dallas = $this->dallas($tenant);

        $this->placed($tenant, $dallas, '2026-10-05 07:00:00', 'Canceled');
        $this->placed($tenant, $dallas, '2026-10-05 08:00:00', tags: ['DEPOSIT RECEIVED']);
        $this->placed($tenant, $dallas, '2026-10-05 09:00:00')->delete();
        $this->placed($tenant, $dallas, '2026-10-05 11:00:00');

        $this->assertSame([0, 1], array_column($this->first($tenant, 'zip=75201')['first']['windows'], 'taken'));
    }

    private function limit(array $tenant, array $limits): void
    {
        $this->signedInAs($tenant['admin'])
            ->putJson("/api/v1/scheduling/franchises/{$tenant['franchise']->id}/limits", ['limits' => $limits])
            ->assertOk();
    }

    /**
     * THE FRANCHISE'S DAILY LIMIT (Jose, 2026-09-25: "when im at 15 bookings
     * already it should stop offering said day"). A job in ANOTHER area of the
     * franchise uses the limit up, so Dallas's Monday is full although Dallas
     * itself has all three seats free.
     */
    public function test_a_franchise_at_its_daily_limit_stops_offering_the_day_in_every_area(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $this->dallas($tenant);
        $this->limit($tenant, ['1' => 1]);

        $this->booked($tenant, '2026-10-05 08:00:00', area: 'Houston');

        $area = $this->first($tenant, 'zip=75201');

        $this->assertSame('2026-10-12', $area['first']['date'], 'Monday the 5th: DMV-style limit of 1, already 1 booked.');
    }

    /**
     * THE DAY IS A CEILING (A1 Tools' B3). Cincinnati, 2026-09-30: 6 of 6
     * booked with one window over by one, and another window still offered a
     * spot. Dallas takes 2 + 1; three jobs in the first window fill the day.
     */
    public function test_jobs_over_one_window_fill_the_day_for_the_others(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $this->dallas($tenant);

        foreach (['07:00', '08:00', '09:00'] as $t) {
            $this->booked($tenant, "2026-10-05 {$t}:00");
        }

        $this->assertSame('2026-10-12', $this->first($tenant, 'zip=75201')['first']['date'], 'Monday is 3 of 3.');
    }

    /** A job with no time holds no window's seat, but it takes the day's. */
    public function test_a_job_with_no_time_counts_against_the_day(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $this->dallas($tenant);

        $this->booked($tenant, '2026-10-05 00:00:00');
        $this->booked($tenant, '2026-10-05 00:00:00');

        $first = $this->first($tenant, 'zip=75201')['first'];

        $this->assertSame('2026-10-05', $first['date']);
        $this->assertSame([1, 0], array_column($first['windows'], 'remaining'), '3 a day, 2 booked with no time: 1 left, earliest first.');
    }

    /** Short of the limit, no window offers more than the franchise has left — and the day says why. */
    public function test_no_window_offers_more_than_the_franchise_has_left(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $this->dallas($tenant);
        $this->limit($tenant, ['1' => 2]);

        $this->booked($tenant, '2026-10-05 00:00:00', area: 'Houston');

        $first = $this->first($tenant, 'zip=75201')['first'];

        $this->assertSame('2026-10-05', $first['date']);
        // One spot, handed to the earliest window. It used to be offered in
        // both — two windows advertising the same last spot (A1's B3).
        $this->assertSame([1, 0], array_column($first['windows'], 'remaining'), 'Dallas has 2 and 1; the franchise has 1 left.');
        $this->assertSame(2, $first['franchise_limit']);
        $this->assertSame(1, $first['franchise_booked']);
    }

    /** A cancelled job is not a booking against the limit either. */
    public function test_a_free_status_does_not_count_against_the_limit(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $this->dallas($tenant);
        $this->limit($tenant, ['1' => 1]);

        $this->booked($tenant, '2026-10-05 08:00:00', 'Canceled', area: 'Houston');

        $this->assertSame('2026-10-05', $this->first($tenant, 'zip=75201')['first']['date']);
    }

    /** Stored per weekday, blank is none, zero refused, and the areas list carries it. */
    public function test_limits_are_stored_per_weekday_and_listed_with_the_areas(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $this->dallas($tenant);

        $this->signedInAs($tenant['admin'])
            ->putJson("/api/v1/scheduling/franchises/{$tenant['franchise']->id}/limits", ['limits' => ['1' => 0]])
            ->assertStatus(422);

        $this->limit($tenant, ['1' => 15, '5' => 10, '6' => null]);

        $this->signedInAs($tenant['admin'])
            ->getJson('/api/v1/scheduling/areas')
            ->assertOk()
            ->assertJsonPath("franchise_limits.{$tenant['franchise']->id}", ['1' => 15, '5' => 10]);

        $viewer = $this->userWithRole($tenant, 'Viewer', ['scheduling.view', 'scheduling.settings.view']);
        $this->signedInAs($viewer)
            ->putJson("/api/v1/scheduling/franchises/{$tenant['franchise']->id}/limits", ['limits' => ['1' => 3]])
            ->assertForbidden();
    }

    private function calendarDay(array $tenant, string $query, string $date): array
    {
        $days = $this->signedInAs($tenant['admin'])
            ->getJson("/api/v1/scheduling/availability/calendar?month=2026-10&franchise_id={$tenant['franchise']->id}{$query}")
            ->assertOk()
            ->json('data.days');

        return collect($days)->firstWhere('date', $date);
    }

    /** The calendar (Jose, 2026-09-25): per day what it takes, what is booked, what is left. */
    public function test_the_calendar_shows_each_day_left_of_capacity_and_closed_days(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $this->dallas($tenant);
        $this->booked($tenant, '2026-10-05 07:00:00');

        $monday = $this->calendarDay($tenant, '', '2026-10-05');
        $this->assertSame(['closed' => false, 'capacity' => 3, 'booked' => 1, 'remaining' => 2, 'over' => 0], array_intersect_key($monday, array_flip(['closed', 'capacity', 'booked', 'remaining', 'over'])));

        $this->assertTrue($this->calendarDay($tenant, '', '2026-10-06')['closed'], 'Dallas works Mondays only.');
    }

    /** Booked past what the day takes shows as over — on a closed day too, where nothing should be. */
    public function test_the_calendar_counts_bookings_over_capacity(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $this->dallas($tenant);

        foreach (['07:00', '08:00', '11:00', '12:00'] as $t) {
            $this->booked($tenant, "2026-10-05 {$t}:00");
        }
        $this->booked($tenant, '2026-10-06 09:00:00');

        $this->assertSame(1, $this->calendarDay($tenant, '', '2026-10-05')['over']);

        $tuesday = $this->calendarDay($tenant, '', '2026-10-06');
        $this->assertTrue($tuesday['closed']);
        $this->assertSame(1, $tuesday['over']);
    }

    /**
     * With a franchise limit, the franchise view never shows more than the
     * limit and counts the franchise's own bookings; an area's view counts
     * its own, with what is left capped by the franchise.
     */
    public function test_the_calendar_applies_the_franchise_limit(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $dallas = $this->dallas($tenant);
        $this->limit($tenant, ['1' => 2]);
        $this->booked($tenant, '2026-10-05 08:00:00', area: 'Houston');

        $whole = $this->calendarDay($tenant, '', '2026-10-05');
        $this->assertSame([2, 1, 1, 2], [$whole['capacity'], $whole['booked'], $whole['remaining'], $whole['limit']]);

        $one = $this->calendarDay($tenant, "&area_id={$dallas->id}", '2026-10-05');
        $this->assertSame([3, 0, 1], [$one['capacity'], $one['booked'], $one['remaining']]);
    }

    /** "All areas" (Jose, 2026-09-28): every area in view, franchise by franchise, in both the search and the calendar. */
    public function test_all_areas_searches_every_area_and_sums_every_franchise_month(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $this->dallas($tenant);

        app(TenantContext::class)->set($tenant['org']);
        $georgia = Franchise::create([
            'organization_id' => $tenant['org']->id,
            'company_id' => $tenant['company']->id,
            'name' => 'Georgia',
        ]);
        $atlanta = ServiceArea::create(['franchise_id' => $georgia->id, 'name' => 'Atlanta']);
        $this->signedInAs($tenant['admin'])
            ->putJson("/api/v1/scheduling/areas/{$atlanta->id}/slots", ['slots' => [
                ['weekday' => 1, 'start_time' => '08:00', 'end_time' => '11:00', 'max_jobs' => 4],
            ]])->assertOk();
        $this->booked($tenant, '2026-10-05 08:00:00', area: 'Atlanta', franchise: $georgia);

        $areas = $this->signedInAs($tenant['admin'])
            ->getJson('/api/v1/scheduling/availability?all=1')
            ->assertOk()
            ->json('data.areas');
        $this->assertEqualsCanonicalizing(['Dallas', 'Atlanta'], array_column($areas, 'area_name'));

        $monday = collect($this->signedInAs($tenant['admin'])
            ->getJson('/api/v1/scheduling/availability/calendar?month=2026-10')
            ->assertOk()
            ->assertJsonPath('data.franchise', null)
            ->json('data.days'))->firstWhere('date', '2026-10-05');

        // Dallas 3 + Atlanta 4 capacity; one Atlanta job.
        $this->assertSame([7, 1, 6, false], [$monday['capacity'], $monday['booked'], $monday['remaining'], $monday['closed']]);
    }

    /**
     * THE AREA'S OWN DAILY LIMIT (Jose, 2026-09-28). Dallas's windows add up
     * to 3 on a Monday; a limit of 1 with one job booked fills the day.
     */
    public function test_an_area_daily_limit_lowers_the_day_below_its_windows(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $dallas = $this->dallas($tenant);

        $this->signedInAs($tenant['admin'])
            ->putJson("/api/v1/scheduling/areas/{$dallas->id}/limits", ['limits' => ['1' => 0]])
            ->assertStatus(422);
        $this->signedInAs($tenant['admin'])
            ->putJson("/api/v1/scheduling/areas/{$dallas->id}/limits", ['limits' => ['1' => 2, '5' => null]])
            ->assertOk()
            ->assertJsonPath('data.daily_limits', ['1' => 2]);

        $first = $this->first($tenant, 'zip=75201')['first'];
        $this->assertSame('2026-10-05', $first['date']);
        $this->assertSame([2, 0], array_column($first['windows'], 'remaining'), 'Limit 2: both go to the earliest window.');
        $this->assertSame(2, $first['area_limit']);

        $this->booked($tenant, '2026-10-05 11:00:00');
        $this->booked($tenant, '2026-10-05 12:00:00');

        $this->assertSame('2026-10-12', $this->first($tenant, 'zip=75201')['first']['date'], 'Two booked of a limit of 2.');

        $this->signedInAs($tenant['admin'])->getJson('/api/v1/scheduling/areas')
            ->assertJsonPath('data.0.daily_limits', ['1' => 2]);
    }

    /**
     * Scheduling › Overbooking (Jose, 2026-09-29). Dallas takes 3 on a Monday;
     * four booked is one over, and a job on a closed Tuesday is over by all of
     * it. The franchise, limited to 4, is over too once a Houston job lands.
     */
    public function test_the_overbooking_tab_lists_days_booked_past_what_they_take(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $this->dallas($tenant);
        $this->limit($tenant, ['1' => 4]);

        foreach (['07:00', '08:00', '11:00', '12:00'] as $t) {
            $this->booked($tenant, "2026-10-05 {$t}:00");
        }
        $this->booked($tenant, '2026-10-06 09:00:00');
        $this->booked($tenant, '2026-10-05 09:00:00', area: 'Houston');

        $body = $this->signedInAs($tenant['admin'])
            ->getJson('/api/v1/scheduling/overbooking')
            ->assertOk()
            ->json('data');

        $this->assertSame('2026-09-30', $body['from'], 'Today counts: an overbooked today still needs solving.');

        $rows = collect($body['overbooked'])->map(fn ($r) => [$r['date'], $r['level'], $r['name'], $r['booked'], $r['capacity'], $r['over'], $r['closed']])->all();
        $this->assertContains(['2026-10-05', 'area', 'Dallas', 4, 3, 1, false], $rows);
        $this->assertContains(['2026-10-05', 'franchise', $tenant['franchise']->name, 5, 4, 1, false], $rows);
        $this->assertContains(['2026-10-06', 'area', 'Dallas', 1, 0, 1, true], $rows);
    }

    /**
     * The warning as a row in Reports' library (Jose, 2026-10-01). Rule names
     * are Automations' data: a reader without automations.view learns only
     * that one goes out — `sent_by` null, and no rule name anywhere in the body.
     */
    public function test_the_overbooking_warning_is_listed_as_a_report(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $this->inTenant($tenant['org'], fn () => \App\Models\Automation::create([
            'organization_id' => $tenant['org']->id,
            'name' => 'Morning overbooking mail',
            'trigger_key' => 'scheduling.overbooking_found',
            'is_active' => true,
        ]));

        $this->signedInAs($tenant['admin'])->getJson('/api/v1/scheduling/reports')
            ->assertOk()
            ->assertJsonPath('data.0.id', 'overbooking')
            ->assertJsonPath('data.0.sent_automatically', true)
            ->assertJsonPath('data.0.sent_by.0.name', 'Morning overbooking mail');

        $reader = $this->userWithRole($tenant, 'Dispatcher', ['scheduling.view', 'scheduling.overbooking.view']);
        $body = $this->signedInAs($reader)->getJson('/api/v1/scheduling/reports')
            ->assertOk()
            ->assertJsonPath('data.0.sent_automatically', true)
            ->assertJsonPath('data.0.sent_by', null);
        $this->assertStringNotContainsString('Morning overbooking mail', $body->getContent());

        $caller = $this->userWithRole($tenant, 'Caller', ['scheduling.view', 'scheduling.availability.view']);
        $this->signedInAs($caller)->getJson('/api/v1/scheduling/reports')->assertForbidden();
    }

    /**
     * Jovanni, 2026-10-01: "pls improve the zip code validation". A number
     * that is not a zip is refused in words, never cut to five digits and
     * searched; zip+4 finds the same area as its five digits.
     */
    public function test_a_number_that_is_not_a_zip_is_refused(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $this->dallas($tenant);

        foreach (['123', '752011', '75201-12'] as $typo) {
            $this->signedInAs($tenant['admin'])
                ->getJson('/api/v1/scheduling/availability?zip='.urlencode($typo))
                ->assertStatus(422)
                ->assertJsonPath('errors.zip.0', \App\Http\Controllers\Api\SchedulingAvailabilityController::NOT_A_ZIP);
        }

        $this->assertSame('Dallas', $this->first($tenant, 'zip='.urlencode('75201-1234'))['area_name']);
    }

    /**
     * An overbooked row opens to the jobs it counted, each with its client and
     * appointment (Bar, via Jose, 2026-10-12). The list is the count: four
     * booked of three lists four, and a cancelled job is in neither.
     */
    public function test_an_overbooked_row_lists_the_jobs_it_counted_with_their_details(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $dallas = $this->dallas($tenant);
        $this->limit($tenant, ['1' => 4]);

        foreach (['07:00', '11:00', '12:00'] as $t) {
            $this->booked($tenant, "2026-10-05 {$t}:00");
        }
        $this->booked($tenant, '2026-10-05 09:00:00', 'Canceled');
        WorkizJob::create([
            'franchise_id' => $tenant['franchise']->id,
            'remote_id' => 'RICH01',
            'service_area' => 'Dallas',
            'postal_code' => '75201',
            'city' => 'Dallas',
            'address' => '1430 Red Oaks Dr',
            'status' => 'Submitted',
            'scheduled_for' => '2026-10-05 08:00:00',
            'payload' => [
                'Tags' => [], 'SerialId' => 600012, 'FirstName' => 'John', 'LastName' => 'Lynch',
                'Phone' => '2145550100', 'Email' => 'john@example.com', 'JobType' => 'Chimney Inspection',
                'JobEndDateTime' => '2026-10-05 11:00:00', 'JobNotes' => 'Gate code 4411',
                'Team' => [['id' => 'USR-1', 'Name' => 'Ivan Sharkov']],
            ],
            'synced_at' => now(),
        ]);

        $row = collect($this->signedInAs($tenant['admin'])->getJson('/api/v1/scheduling/overbooking')->json('data.overbooked'))
            ->first(fn ($r) => $r['level'] === 'area' && $r['date'] === '2026-10-05');
        $this->assertSame(4, $row['booked']);

        $jobs = $this->signedInAs($tenant['admin'])
            ->getJson("/api/v1/scheduling/overbooking/jobs?date=2026-10-05&area_id={$dallas->id}")
            ->assertOk()
            ->json('data');

        $this->assertCount($row['booked'], $jobs, 'The list is exactly what the row counted.');
        $this->assertSame(['07:00', '08:00', '11:00', '12:00'], array_column($jobs, 'time'));
        $this->assertSame([
            'number' => '600012', 'status' => 'Submitted', 'time' => '08:00', 'end_time' => '11:00',
            'service' => 'Chimney Inspection', 'technicians' => ['Ivan Sharkov'], 'notes' => 'Gate code 4411',
            'customer' => ['name' => 'John Lynch', 'phone' => '2145550100', 'email' => 'john@example.com', 'address' => '1430 Red Oaks Dr, Dallas 75201'],
        ], array_intersect_key($jobs[1], array_flip(['number', 'status', 'time', 'end_time', 'service', 'technicians', 'notes', 'customer'])));

        // A franchise row lists the franchise's jobs that day.
        $this->assertCount(4, $this->signedInAs($tenant['admin'])
            ->getJson("/api/v1/scheduling/overbooking/jobs?date=2026-10-05&franchise_id={$tenant['franchise']->id}")
            ->assertOk()->json('data'));

        // Another tenant's area and franchise are not found, and a caller
        // without the Overbooking tab cannot ask.
        $other = $this->provisionTenant('Beta');
        $this->signedInAs($other['admin'])->getJson("/api/v1/scheduling/overbooking/jobs?date=2026-10-05&area_id={$dallas->id}")->assertNotFound();
        $this->signedInAs($other['admin'])->getJson("/api/v1/scheduling/overbooking/jobs?date=2026-10-05&franchise_id={$tenant['franchise']->id}")->assertNotFound();
        $caller = $this->userWithRole($tenant, 'Caller', ['scheduling.view', 'scheduling.availability.view']);
        $this->signedInAs($caller)->getJson("/api/v1/scheduling/overbooking/jobs?date=2026-10-05&area_id={$dallas->id}")->assertForbidden();
    }

    /** A Workiz job with a place on the map, for the nearby suggestion. */
    private function located(array $tenant, string $at, float $lat, float $lng, string $zip = '75201', string $tech = 'Ivan Sharkov'): void
    {
        app(TenantContext::class)->set($tenant['org']);
        WorkizJob::create([
            'franchise_id' => $tenant['franchise']->id,
            'remote_id' => strtoupper(bin2hex(random_bytes(4))),
            'service_area' => 'Dallas',
            'postal_code' => $zip,
            'latitude' => $lat,
            'longitude' => $lng,
            'status' => 'Submitted',
            'scheduled_for' => $at,
            'payload' => ['Tags' => [], 'Team' => [['id' => 'USR-1', 'Name' => $tech]], 'FirstName' => 'Dana', 'LastName' => 'Reyes', 'JobType' => 'Chimney Sweep', 'SerialId' => '4417'],
            'synced_at' => now(),
        ]);
    }

    /**
     * Jose, 2026-10-13: suggest the day with work already booked nearby. The
     * earliest Monday has only a job 230 miles off; the next one has a job two
     * blocks away at 11, so it is suggested, in the window beside it, and
     * names the technician already there.
     */
    public function test_a_zip_is_suggested_the_day_with_booked_work_nearby(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $this->dallas($tenant);
        // A past job in 75201 gives the zip its place; one Workiz could not
        // place (-1,-1) must not drag the zip's centre to Africa.
        $this->located($tenant, '2026-08-03 08:00:00', 32.7870, -96.7990);
        $this->located($tenant, '2026-08-10 08:00:00', -1.0, -1.0);
        // Oct 5: one job, far away (Houston).
        $this->located($tenant, '2026-10-05 07:00:00', 29.7600, -95.3700, '77002', 'Harry Jenkins');
        // Oct 12: one job a few blocks from the customer, in the 11-2 window.
        $this->located($tenant, '2026-10-12 11:00:00', 32.7900, -96.8010);

        $data = $this->signedInAs($tenant['admin'])
            ->getJson('/api/v1/scheduling/availability/suggestion?zip=75201')
            ->assertOk()
            ->json('data');

        $this->assertSame(30.0, (float) $data['radius_miles']);
        $this->assertSame('2026-10-05', $data['earliest']['date']);
        $this->assertSame(0, $data['earliest']['nearby']);
        $this->assertGreaterThan(200, $data['earliest']['nearest_miles']);

        $this->assertSame('2026-10-12', $data['suggested']['date']);
        // That job fills 11-2 (it takes one), so the window right before it.
        $this->assertSame('07:00', $data['suggested']['window']['start_time'], 'The window beside the nearby job.');
        $this->assertSame(1, $data['suggested']['nearby']);
        // The day in Availability's own shape, and which window is meant.
        $this->assertSame(['07:00', '11:00'], array_column($data['suggested']['day']['windows'], 'start_time'));
        $this->assertSame(0, $data['suggested']['window_index']);
        $this->assertSame(['Ivan Sharkov'], $data['suggested']['technicians']);
        $this->assertFalse($data['suggested']['same_as_earliest']);
        $this->assertLessThan(1, $data['suggested']['jobs'][0]['miles']);
        // What its dot on the map says when hovered.
        $this->assertSame(['Dana Reyes', 'Chimney Sweep', '4417', 'Submitted'], [
            $data['suggested']['jobs'][0]['customer'], $data['suggested']['jobs'][0]['service'],
            $data['suggested']['jobs'][0]['number'], $data['suggested']['jobs'][0]['status'],
        ]);
        $this->assertEqualsWithDelta(32.788, $data['origin']['latitude'], 0.002, 'The centre of the zip from its jobs.');

        // A wider radius from Settings changes nothing here, a 1-mile one still
        // finds the job, and nothing nearby means no suggestion at all.
        app(TenantContext::class)->set($tenant['org']);
        app(\App\Support\Settings\SettingsResolver::class)->set('scheduling.suggest_radius_miles', $tenant['org'], 1);
        $this->assertSame('2026-10-12', $this->signedInAs($tenant['admin'])
            ->getJson('/api/v1/scheduling/availability/suggestion?zip=75201')->json('data.suggested.date'));
        app(TenantContext::class)->set($tenant['org']);
        DB::table('workiz_jobs')->where('scheduled_for', '2026-10-12 11:00:00')->update(['latitude' => 29.7601, 'longitude' => -95.3701]);
        $this->assertNull($this->signedInAs($tenant['admin'])
            ->getJson('/api/v1/scheduling/availability/suggestion?zip=75201')->json('data.suggested'));
    }

    /**
     * Jose, 2026-10-14: technicians = the day's capacity / 6, a route each,
     * opened in Google Maps. 12 seats are 2 technicians; Dallas and Fort Worth
     * each get one, every job joins the route already nearest it, and two
     * jobs at 8:00 never share a technician.
     */
    public function test_a_day_is_split_into_a_route_per_technician(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $area = $this->dallas($tenant);
        $this->signedInAs($tenant['admin'])->putJson("/api/v1/scheduling/areas/{$area->id}/slots", ['slots' => [
            ['weekday' => 1, 'start_time' => '07:00', 'end_time' => '11:00', 'max_jobs' => 4],
            ['weekday' => 1, 'start_time' => '11:00', 'end_time' => '14:00', 'max_jobs' => 4],
            ['weekday' => 1, 'start_time' => '14:00', 'end_time' => '17:00', 'max_jobs' => 4],
        ]])->assertOk();

        $this->located($tenant, '2026-10-05 08:00:00', 32.7870, -96.7990);              // Dallas
        $this->located($tenant, '2026-10-05 08:00:00', 32.7550, -97.3300, '76102');     // Fort Worth
        $this->located($tenant, '2026-10-05 11:00:00', 32.8000, -96.8000);              // by Dallas
        $this->located($tenant, '2026-10-05 12:00:00', 32.7600, -97.3350, '76102');     // by Fort Worth
        $this->located($tenant, '2026-10-05 14:00:00', 32.8100, -96.8050);              // by Dallas
        $this->located($tenant, '2026-10-05 12:30:00', -1.0, -1.0, '75202');            // nowhere

        $data = $this->signedInAs($tenant['admin'])
            ->getJson("/api/v1/scheduling/routes?area_id={$area->id}&date=2026-10-05")
            ->assertOk()->json('data');

        $this->assertSame([12, 2], [$data['capacity'], $data['technicians']]);
        $routes = collect($data['routes'])->keyBy(fn ($r) => substr((string) $r['stops'][0]['customer']['address'], -5));
        $stops = $routes->map(fn ($r) => array_map(fn ($s) => $s['time'].' '.substr((string) $s['customer']['address'], -5), $r['stops']))->sortKeys()->all();
        $this->assertSame([
            '75201' => ['08:00 75201', '11:00 75201', '14:00 75201'],
            '76102' => ['08:00 76102', '12:00 76102'],
        ], $stops);
        $this->assertSame([1, 2], $routes->pluck('technician')->sort()->values()->all());
        $this->assertStringStartsWith('https://www.google.com/maps/dir/?api=1&origin=32.787%2C-96.799&destination=32.81%2C-96.805', $routes['75201']['maps_url']);
        $this->assertStringContainsString('waypoints=32.8%2C-96.8', $routes['75201']['maps_url']);
        $this->assertLessThan(5, $routes['75201']['miles']);
        $this->assertCount(1, $data['unplaced']);

        // Two 8:00 jobs a mile apart are still two technicians.
        $this->located($tenant, '2026-10-12 08:00:00', 32.7870, -96.7990);
        $this->located($tenant, '2026-10-12 08:00:00', 32.8010, -96.7990);
        $twelfth = $this->signedInAs($tenant['admin'])
            ->getJson("/api/v1/scheduling/routes?area_id={$area->id}&date=2026-10-12")
            ->assertOk()->json('data.routes');
        $this->assertSame([1, 1], array_map(fn ($r) => count($r['stops']), $twelfth));
        $this->assertSame([false, false], array_column($twelfth, 'extra'));

        // A third 8:00 job is a technician the day does not have: its own
        // extra route, not a second 8:00 stop on someone's.
        $this->located($tenant, '2026-10-12 08:00:00', 32.8050, -96.7990);
        $twelfth = $this->signedInAs($tenant['admin'])
            ->getJson("/api/v1/scheduling/routes?area_id={$area->id}&date=2026-10-12")
            ->assertOk()->json('data.routes');
        $this->assertSame([[1, false], [1, false], [1, true]], array_map(fn ($r) => [count($r['stops']), $r['extra']], $twelfth));
        $this->assertSame(3, $twelfth[2]['technician']);

        // A job Workiz never placed, with a zip A-1 has worked: routed at the
        // zip's centre and marked approximate, not left out.
        $this->located($tenant, '2026-10-19 08:00:00', 0.0, 0.0);
        app(TenantContext::class)->set($tenant['org']);
        DB::table('workiz_jobs')->where('scheduled_for', '2026-10-19 08:00:00')->update(['latitude' => null, 'longitude' => null]);
        $nineteenth = $this->signedInAs($tenant['admin'])
            ->getJson("/api/v1/scheduling/routes?area_id={$area->id}&date=2026-10-19")
            ->assertOk()->json('data');
        $this->assertSame([], $nineteenth['unplaced']);
        $this->assertTrue($nineteenth['routes'][0]['stops'][0]['approximate']);
        $this->assertEqualsWithDelta(32.79, $nineteenth['routes'][0]['stops'][0]['latitude'], 0.02);
    }

    /**
     * A job with no coordinates takes Field's stored geocode of its customer's
     * address first (exact, not approximate); the zip's centre only after.
     */
    public function test_a_job_without_coordinates_takes_fields_stored_geocode_first(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        app(TenantContext::class)->set($tenant['org']);
        $customer = \App\Models\Customer::factory()->create([
            'owning_franchise_id' => $tenant['franchise']->id,
            'address_line1' => '712 Pacific St', 'city' => 'Baltimore', 'state' => 'MD', 'postal_code' => '21211',
        ]);
        \App\Models\FieldGeocode::query()->create([
            'address_hash' => \App\Models\FieldGeocode::hash(\App\Models\FieldGeocode::addressOf($customer)),
            'address' => \App\Models\FieldGeocode::addressOf($customer),
            'latitude' => 39.3301, 'longitude' => -76.6301, 'status' => \App\Models\FieldGeocode::OK,
        ]);

        $row = app(\App\Support\Scheduling\NearbySuggestion::class)->located(collect([
            (object) ['customer_id' => $customer->id, 'latitude' => '-1', 'longitude' => '-1', 'postal_code' => '21211'],
        ]), $tenant['org']->id)->first();

        $this->assertSame([39.3301, -76.6301, false], [$row->latitude, $row->longitude, $row->approximate ?? false]);
    }

    /**
     * Grok's review of the live list (2026-10-14): a reschedule must be worth
     * the phone call. Each pair below would be suggested without one rule;
     * only the Houston pair passes them all.
     */
    public function test_a_reschedule_is_offered_only_when_worth_the_call(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $area = $this->dallas($tenant);
        $slots = [];
        foreach ([1, 3] as $weekday) {
            $slots[] = ['weekday' => $weekday, 'start_time' => '07:00', 'end_time' => '11:00', 'max_jobs' => 5];
            $slots[] = ['weekday' => $weekday, 'start_time' => '11:00', 'end_time' => '14:00', 'max_jobs' => 1];
            $slots[] = ['weekday' => $weekday, 'start_time' => '14:00', 'end_time' => '17:00', 'max_jobs' => 2];
        }
        $this->signedInAs($tenant['admin'])->putJson("/api/v1/scheduling/areas/{$area->id}/slots", ['slots' => $slots])->assertOk();

        // Within 48 hours (Thu Oct 2, tomorrow is Oct 1): never moved, though
        // Mon Oct 5 has work next door.
        $this->located($tenant, '2026-10-02 08:00:00', 32.7870, -96.7990);
        $this->located($tenant, '2026-10-05 08:00:00', 32.7910, -96.7990);
        // Waco: the work on Wed Oct 7 fills 2-5, and 11-2 is full too, so the
        // only open window is 7-11, two windows from it.
        $this->located($tenant, '2026-10-07 15:00:00', 31.5500, -97.1500, '76701');
        $this->located($tenant, '2026-10-07 16:00:00', 31.5550, -97.1500, '76701');
        $this->located($tenant, '2026-10-07 12:00:00', 29.4200, -98.4900, '78205');
        $this->located($tenant, '2026-10-14 08:00:00', 31.5520, -97.1510, '76701');
        // Tyler: the only neighbour is 12 miles off, even after the move.
        $this->located($tenant, '2026-10-12 08:00:00', 32.3500, -95.3000, '75701');
        $this->located($tenant, '2026-10-05 09:00:00', 32.5240, -95.3000, '75701');
        // Austin: next door, but 9 days apart.
        $this->located($tenant, '2026-10-14 09:00:00', 30.2700, -97.7400, '78701');
        $this->located($tenant, '2026-10-05 10:00:00', 30.2730, -97.7400, '78701');
        // Houston: next door, 5 days apart, same window. This one passes.
        $this->located($tenant, '2026-10-12 09:00:00', 29.7600, -95.3700, '77002');
        $this->located($tenant, '2026-10-07 08:00:00', 29.7630, -95.3700, '77002');

        $rows = $this->signedInAs($tenant['admin'])
            ->getJson('/api/v1/scheduling/reschedules')
            ->assertOk()
            ->json('data');

        $this->assertSame(['77002'], array_values(array_unique(array_map(
            fn ($r) => substr((string) ($r['job']['customer']['address'] ?? ''), -5), $rows))),
            json_encode(array_map(fn ($r) => [$r['job']['date'], $r['job']['time'], $r['job']['customer']['address']], $rows)));
        $this->assertCount(1, $rows);
    }

    /**
     * Jose, 2026-10-13: a website booking, seen by the dispatcher, says how it
     * sits beside booked work and names a better day if there is one. A
     * booking already beside work gets no "better".
     */
    public function test_a_picked_window_is_told_its_fit_and_a_better_day(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $this->dallas($tenant);
        $this->located($tenant, '2026-08-03 08:00:00', 32.7870, -96.7990);
        $this->located($tenant, '2026-10-05 07:00:00', 29.7600, -95.3700, '77002');
        $this->located($tenant, '2026-10-12 11:00:00', 32.7900, -96.8010);

        $fit = fn (string $date) => $this->signedInAs($tenant['admin'])
            ->getJson("/api/v1/scheduling/availability/fit?zip=75201&date={$date}&start_time=07:00")
            ->assertOk()->json('data');

        $lone = $fit('2026-10-05');
        $this->assertSame(0, $lone['chosen']['nearby']);
        $this->assertGreaterThan(200, $lone['chosen']['nearest_miles']);
        $this->assertSame('2026-10-12', $lone['better']['date']);
        $this->assertSame(['Ivan Sharkov'], $lone['better']['technicians']);

        $paired = $fit('2026-10-12');
        $this->assertSame(1, $paired['chosen']['nearby']);
        $this->assertSame('Dana Reyes', $paired['chosen']['jobs'][0]['customer']);
        $this->assertNull($paired['better']);

        // An equally good day that is merely earlier is not "better".
        $this->located($tenant, '2026-10-05 11:00:00', 32.7900, -96.8010);
        $this->assertNull($fit('2026-10-12')['better']);
    }

    /**
     * Found on production 2026-10-13 (Atlanta): a later, farther day won
     * because its open window sat next to the job. The day is chosen first,
     * closest work then earliest; the window beside the job only picks within it.
     */
    public function test_a_closer_earlier_day_beats_a_later_one_with_an_adjacent_window(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $this->dallas($tenant);
        $this->located($tenant, '2026-08-03 08:00:00', 32.7870, -96.7990);
        // Oct 5: ~2.8 miles off, at 11, which fills 11-2; only 7-11 is open.
        $this->located($tenant, '2026-10-05 11:00:00', 32.8270, -96.7990, '75204');
        // Oct 12: ~4 miles off, at 8, inside the open 7-11.
        $this->located($tenant, '2026-10-12 08:00:00', 32.7290, -96.7990, '75215');

        $suggested = $this->signedInAs($tenant['admin'])
            ->getJson('/api/v1/scheduling/availability/suggestion?zip=75201')
            ->assertOk()
            ->json('data.suggested');

        $this->assertSame('2026-10-05', $suggested['date']);
        $this->assertSame('07:00', $suggested['window']['start_time']);
    }

    /**
     * Jose, 2026-10-13: Re-Scheduling lists booked jobs that a technician
     * drives to alone, with the day beside other work they could move to.
     * Two lone jobs near each other on different days are ONE suggestion,
     * not each sent to the other's day; a job already paired on its day, or
     * with nothing near it on any day, is not listed.
     */
    public function test_a_lone_job_is_suggested_a_day_beside_other_work(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $area = $this->dallas($tenant);
        // Mondays and Wednesdays, 7-11 for two and 11-2 for one.
        $this->signedInAs($tenant['admin'])->putJson("/api/v1/scheduling/areas/{$area->id}/slots", ['slots' => [
            ['weekday' => 1, 'start_time' => '07:00', 'end_time' => '11:00', 'max_jobs' => 2],
            ['weekday' => 1, 'start_time' => '11:00', 'end_time' => '14:00', 'max_jobs' => 1],
            ['weekday' => 3, 'start_time' => '07:00', 'end_time' => '11:00', 'max_jobs' => 2],
            ['weekday' => 3, 'start_time' => '11:00', 'end_time' => '14:00', 'max_jobs' => 1],
        ]])->assertOk();
        // Mon Oct 5: A downtown, alone. Mon Oct 12: C two blocks from A, alone.
        $this->located($tenant, '2026-10-05 08:00:00', 32.7870, -96.7990);
        $this->located($tenant, '2026-10-12 08:00:00', 32.7900, -96.8010);
        // Wed Oct 7: B alone in Houston with nothing near it on any day, and
        // D+E together in Denton, 38 miles off. That fills the day.
        $this->located($tenant, '2026-10-07 07:00:00', 29.7600, -95.3700, '77002');
        $this->located($tenant, '2026-10-07 08:00:00', 33.2150, -97.1330, '76201');
        $this->located($tenant, '2026-10-07 11:00:00', 33.2160, -97.1340, '76201');
        // Mon Oct 12: F near D+E, filling 7-11. D is already paired, so it is
        // not offered F's day even though F sits next to it.
        $this->located($tenant, '2026-10-12 07:30:00', 33.2170, -97.1330, '76201');
        // Two jobs Workiz could not place (-1,-1): "0 miles apart", not a pair.
        $this->located($tenant, '2026-10-05 12:00:00', -1.0, -1.0, '75202');
        $this->located($tenant, '2026-10-14 08:00:00', -1.0, -1.0, '75202');

        $rows = $this->signedInAs($tenant['admin'])
            ->getJson('/api/v1/scheduling/reschedules')
            ->assertOk()
            ->json('data');

        $this->assertCount(1, $rows, json_encode(array_map(fn ($r) => [$r['job']['date'], $r['job']['time']], $rows)));
        $this->assertSame('Dallas', $rows[0]['area_name']);
        // A and C save the same; the earlier one moves beside the other, into
        // Oct 12's only open window, and C stays put. F is alone too, but Oct 7
        // is full.
        $this->assertSame(['2026-10-05', '08:00'], [$rows[0]['job']['date'], $rows[0]['job']['time']]);
        $this->assertNull($rows[0]['current_nearest_miles'], 'Nothing else booked on Oct 5.');
        // The id POST scheduling/board/jobs/{job}/reschedule takes.
        app(TenantContext::class)->set($tenant['org']);
        $this->assertSame('2026-10-05 08:00', substr((string) WorkizJob::findOrFail($rows[0]['job']['workiz_job_id'])->getRawOriginal('scheduled_for'), 0, 16));
        $this->assertSame('2026-10-12', $rows[0]['suggested']['date']);
        $this->assertSame('11:00', $rows[0]['suggested']['window']['start_time']);
        $this->assertSame(1, $rows[0]['suggested']['nearby']);
        $this->assertLessThan(1, $rows[0]['suggested']['nearest_miles']);
    }

    /** A picked date (Jose, 2026-09-29) is that day alone: the 5th, not the 6th. */
    public function test_the_overbooking_tab_shows_one_picked_date(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $this->dallas($tenant);

        foreach (['07:00', '08:00', '11:00', '12:00'] as $t) {
            $this->booked($tenant, "2026-10-05 {$t}:00");
        }
        $this->booked($tenant, '2026-10-06 09:00:00');

        $body = $this->signedInAs($tenant['admin'])
            ->getJson('/api/v1/scheduling/overbooking?date=2026-10-05')
            ->assertOk()
            ->json('data');

        $this->assertSame(['2026-10-05', 1], [$body['from'], $body['days']]);
        $this->assertSame([['2026-10-05', 'Dallas', 4, 3]], array_map(
            fn ($r) => [$r['date'], $r['name'], $r['booked'], $r['capacity']],
            $body['overbooked'],
        ));

        $this->signedInAs($tenant['admin'])->getJson('/api/v1/scheduling/overbooking?date=10/05/2026')->assertStatus(422);
    }

    /** Fully booked: works in the next 7 days and has no spot left in any of them. */
    public function test_an_area_with_no_spot_in_the_next_seven_days_is_fully_booked(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $this->dallas($tenant);

        foreach (['07:00', '08:00', '11:00'] as $t) {
            $this->booked($tenant, "2026-10-05 {$t}:00");
        }

        $full = $this->signedInAs($tenant['admin'])
            ->getJson('/api/v1/scheduling/overbooking')
            ->assertOk()
            ->json('data.fully_booked');

        $this->assertSame([['Dallas', '2026-10-12']], array_map(fn ($r) => [$r['name'], $r['next_opening']], $full));

        $viewer = $this->userWithRole($tenant, 'Caller', ['scheduling.view', 'scheduling.availability.view']);
        $this->signedInAs($viewer)->getJson('/api/v1/scheduling/overbooking')->assertForbidden();
    }

    /**
     * The morning warning (Shoval, 2026-10-05: "fully booked for over 10 days
     * ... I need to pause ads"). It raises `scheduling.overbooking_found` only
     * for an area with no spot left for the next 10 days or more, and stays
     * quiet for an area that is merely overbooked or full for a few days.
     */
    public function test_the_morning_check_warns_only_of_areas_full_for_ten_days(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $dallas = $this->dallas($tenant);
        $events = fn () => DB::table('automation_events')->where('event_key', 'scheduling.overbooking_found');

        // Monday Oct 5 overbooked; Dallas works Mondays only, so it has no spot
        // until Monday Oct 12 — but a Thursday takes 1 first.
        foreach (['07:00', '08:00', '11:00', '12:00'] as $t) {
            $this->booked($tenant, "2026-10-05 {$t}:00");
        }
        $this->signedInAs($tenant['admin'])->putJson("/api/v1/scheduling/areas/{$dallas->id}/slots", ['slots' => [
            ['weekday' => 1, 'start_time' => '07:00', 'end_time' => '11:00', 'max_jobs' => 2],
            ['weekday' => 1, 'start_time' => '11:00', 'end_time' => '14:00', 'max_jobs' => 1],
            ['weekday' => 4, 'start_time' => '07:00', 'end_time' => '11:00', 'max_jobs' => 1],
        ]])->assertOk();

        $this->artisan('scheduling:warn-overbooking')->assertSuccessful();
        $this->assertSame(0, $events()->count(), 'Overbooked and open on Thursday: nothing to pause.');

        // Mondays only again: nothing until Oct 12, twelve days from today.
        $this->signedInAs($tenant['admin'])->putJson("/api/v1/scheduling/areas/{$dallas->id}/slots", ['slots' => [
            ['weekday' => 1, 'start_time' => '07:00', 'end_time' => '11:00', 'max_jobs' => 2],
            ['weekday' => 1, 'start_time' => '11:00', 'end_time' => '14:00', 'max_jobs' => 1],
        ]])->assertOk();

        $this->artisan('scheduling:warn-overbooking')->assertSuccessful();

        $after = json_decode($events()->first()->payload, true)['after'];
        $this->assertSame(1, $after['fully_booked_areas']);
        $this->assertArrayNotHasKey('overbooked_days', $after);
        $this->assertStringStartsWith('## FULLY BOOKED for 10 days or more', $after['summary']);
        $this->assertStringContainsString('Dallas: next opening Mon Oct 12 (12 days away)', $after['summary']);
        $this->assertStringNotContainsString('over)', $after['summary'], 'Overbooked days are not the email\'s.');
    }

    /**
     * The summary is Markdown an email renders as structure (UI queue 39):
     * the area furthest from an opening first, and a name's own punctuation
     * stays text rather than becoming emphasis.
     */
    public function test_the_warning_summary_lists_the_furthest_out_first(): void
    {
        $row = fn (string $name, ?string $franchise, ?string $next) => [
            'area_id' => $name, 'name' => $name, 'franchise_name' => $franchise, 'working_days' => 5, 'next_opening' => $next,
        ];

        $summary = \App\Console\Commands\SchedulingWarnOverbooking::summary([
            $row('Dallas', 'Texas', '2026-10-12'),
            $row('St. *Louis*', null, '2026-10-26'),
            $row('Houston', 'Texas', null),
        ], CarbonImmutable::parse('2026-09-30'));

        $this->assertSame(
            "## FULLY BOOKED for 10 days or more\n\n"
            ."- Texas › Houston: no opening in the next year\n"
            ."- St\. \*Louis\*: next opening Mon Oct 26 (26 days away)\n"
            ."- Texas › Dallas: next opening Mon Oct 12 (12 days away)\n\n"
            .'Consider pausing ads for these areas. See Scheduling › Overbooking in EzBizi.',
            $summary,
        );
    }

    /**
     * A WEEK SCHEDULED AHEAD (Eve, 2026-09-29: "change automatically starting
     * from October 30"). Dallas takes 2 + 1 on Mondays; from Monday Oct 12 it
     * takes 5 in one window. The Monday before keeps the old numbers.
     */
    public function test_a_scheduled_week_takes_over_from_its_date(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $dallas = $this->dallas($tenant);
        $url = "/api/v1/scheduling/areas/{$dallas->id}/scheduled";
        $week = ['slots' => [['weekday' => 1, 'start_time' => '07:00', 'end_time' => '11:00', 'max_jobs' => 5]]];

        $this->signedInAs($tenant['admin'])->putJson("{$url}/2026-09-30", $week)->assertStatus(422);
        $this->signedInAs($tenant['admin'])->putJson("{$url}/2026-10-12", ['slots' => []])->assertStatus(422);

        $this->signedInAs($tenant['admin'])->putJson("{$url}/2026-10-12", $week)
            ->assertOk()
            ->assertJsonPath('data.scheduled.0.starts_on', '2026-10-12')
            ->assertJsonPath('data.scheduled.0.slots.0.max_jobs', 5);

        $area = $this->first($tenant, 'zip=75201');
        $this->assertSame('2026-10-05', $area['first']['date']);
        $this->assertSame([2, 1], array_column($area['first']['windows'], 'remaining'), 'Before the change: the old week.');
        $this->assertSame('2026-10-12', $area['after'][0]['date']);
        $this->assertSame([5], array_column($area['after'][0]['windows'], 'remaining'), 'From the change: the new week.');

        $this->signedInAs($tenant['admin'])->deleteJson("{$url}/2026-10-12")
            ->assertOk()
            ->assertJsonPath('data.scheduled', []);
    }

    /** Two areas, one pool (Eve, 2026-09-29): a booking in either fills both. */
    public function test_an_area_sharing_availability_books_into_its_lead_pool(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $dallas = $this->dallas($tenant);
        app(TenantContext::class)->set($tenant['org']);
        $plano = ServiceArea::create(['franchise_id' => $tenant['franchise']->id, 'name' => 'Plano']);
        $url = "/api/v1/scheduling/areas/{$plano->id}/shares-with";

        $this->signedInAs($tenant['admin'])->putJson($url, ['shares_with_id' => $plano->id])->assertStatus(422);
        $this->signedInAs($tenant['admin'])->putJson($url, ['shares_with_id' => $dallas->id])
            ->assertOk()
            ->assertJsonPath('data.shares_with_id', $dallas->id);
        // No chains: the lead cannot now share into another area.
        $this->signedInAs($tenant['admin'])->putJson("/api/v1/scheduling/areas/{$dallas->id}/shares-with", ['shares_with_id' => $plano->id])
            ->assertStatus(422);

        // Two booked in Plano take Dallas's 7–11 window.
        $this->booked($tenant, '2026-10-05 07:00:00', area: 'Plano');
        $this->booked($tenant, '2026-10-05 08:00:00', area: 'Plano');

        $area = $this->first($tenant, 'zip=75201');
        $this->assertSame('2026-10-05', $area['first']['date']);
        $this->assertSame([0, 1], array_column($area['first']['windows'], 'remaining'));

        app(TenantContext::class)->set($tenant['org']);
        $this->assertTrue(Openings::hasCapacity($plano->fresh()), 'A member is configured through its lead.');
        $this->assertSame([0, 1], array_column(
            app(Openings::class)->days($plano->fresh(), CarbonImmutable::parse('2026-10-05'), 1)[0]['windows'],
            'remaining',
        ), 'Plano answers the pool, not a week of its own.');

        // The franchise calendar counts the pool once.
        $this->assertSame(3, $this->calendarDay($tenant, '', '2026-10-05')['capacity']);

        $this->signedInAs($tenant['admin'])->putJson($url, ['shares_with_id' => null])
            ->assertOk()
            ->assertJsonPath('data.shares_with_id', null);
    }

    /** On its date the scheduled week becomes the area's week, and the version goes. */
    public function test_the_scheduled_week_becomes_the_area_week_on_its_date(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $dallas = $this->dallas($tenant);

        $this->signedInAs($tenant['admin'])->putJson("/api/v1/scheduling/areas/{$dallas->id}/scheduled/2026-10-12", ['slots' => [
            ['weekday' => 1, 'start_time' => '07:00', 'end_time' => '11:00', 'max_jobs' => 5],
            ['weekday' => 5, 'start_time' => '07:00', 'end_time' => '11:00', 'max_jobs' => 2],
        ]])->assertOk();

        $this->artisan('scheduling:apply-scheduled-changes')->assertSuccessful();
        app(TenantContext::class)->set($tenant['org']);
        $this->assertSame(2, \App\Models\ServiceAreaSlot::where('service_area_id', $dallas->id)->count(), 'Not yet: Oct 12 has not come.');

        $this->travelTo(CarbonImmutable::parse('2026-10-12 00:30:00', 'America/New_York'));
        $this->artisan('scheduling:apply-scheduled-changes')->assertSuccessful();

        app(TenantContext::class)->set($tenant['org']);
        $this->assertSame([[1, 5], [5, 2]], \App\Models\ServiceAreaSlot::where('service_area_id', $dallas->id)
            ->orderBy('weekday')->get()->map(fn ($s) => [$s->weekday, $s->max_jobs])->all());
        $this->assertSame(0, \App\Models\ServiceAreaScheduledSlot::count());
    }

    /**
     * An area nobody has configured says so — not "closed", not "full", and
     * never a date. The client turns it into "check Workiz".
     */
    public function test_an_unconfigured_area_offers_no_date(): void
    {
        $tenant = $this->provisionTenant('Alpha');

        app(TenantContext::class)->set($tenant['org']);
        ServiceArea::create(['franchise_id' => $tenant['franchise']->id, 'name' => 'Austin']);

        $area = $this->first($tenant, 'area_name=Austin');

        $this->assertFalse($area['configured']);
        $this->assertNull($area['first']);
        $this->assertSame([], $area['after']);
    }

    /** "Search by Area": a franchise answers with every area in it, by name. */
    public function test_a_franchise_answers_with_every_area_in_it(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $this->dallas($tenant);

        app(TenantContext::class)->set($tenant['org']);
        ServiceArea::create(['franchise_id' => $tenant['franchise']->id, 'name' => 'Austin']);

        $body = $this->signedInAs($tenant['admin'])
            ->getJson("/api/v1/scheduling/availability?franchise_id={$tenant['franchise']->id}")
            ->assertOk()
            ->json('data');

        $this->assertSame('franchise', $body['by']);
        $this->assertSame(['Austin', 'Dallas'], array_column($body['areas'], 'area_name'));
        $this->assertSame($tenant['franchise']->name, $body['areas'][0]['franchise_name']);
    }

    /** A zip nobody covers is an answer — no areas — not a 404. */
    public function test_an_uncovered_zip_answers_with_no_areas_rather_than_failing(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $this->dallas($tenant);

        $this->assertSame([], $this->signedInAs($tenant['admin'])
            ->getJson('/api/v1/scheduling/availability?zip=99999')
            ->assertOk()
            ->json('data.areas'));
    }

    /** Another organization's zip is not findable, even by exact match. */
    public function test_a_zip_belonging_to_another_organization_is_not_matched(): void
    {
        $alpha = $this->provisionTenant('Alpha');
        $beta = $this->provisionTenant('Beta', 'beta@example.test');

        $this->dallas($beta);

        $this->assertSame([], $this->signedInAs($alpha['admin'])
            ->getJson('/api/v1/scheduling/availability?zip=75201')
            ->assertOk()
            ->json('data.areas'), "Alpha must not see Beta's coverage.");
    }

    /** The name somebody types, and a zip that lost its leading zero. */
    public function test_an_area_is_found_by_its_name_and_a_four_digit_zip_is_padded(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $area = $this->dallas($tenant);

        $this->assertSame('Dallas', $this->first($tenant, 'area_name=Dallas')['area_name']);

        $this->signedInAs($tenant['admin'])
            ->putJson("/api/v1/scheduling/areas/{$area->id}/zips", ['zips' => ['06457']])
            ->assertOk();

        $this->assertSame('Dallas', $this->first($tenant, 'zip=6457')['area_name']);
    }

    public function test_searching_with_nothing_is_refused(): void
    {
        $tenant = $this->provisionTenant('Alpha');

        $this->signedInAs($tenant['admin'])
            ->getJson('/api/v1/scheduling/availability')
            ->assertStatus(422);
    }

    public function test_a_role_without_scheduling_cannot_read_availability(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $this->dallas($tenant);

        $viewer = $this->userWithRole($tenant, 'Viewer', ['customers.view']);

        $this->signedInAs($viewer)
            ->getJson('/api/v1/scheduling/availability?zip=75201')
            ->assertForbidden();
    }

    /** Somebody on the phone knows "Plano", not the zip. */
    public function test_an_area_can_be_found_by_a_city_on_one_of_its_zips(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $area = $this->dallas($tenant);

        $this->signedInAs($tenant['admin'])
            ->putJson("/api/v1/scheduling/areas/{$area->id}/zips", [
                'zips' => [
                    ['zip' => '75201', 'city' => 'Dallas'],
                    ['zip' => '75024', 'city' => 'Plano'],
                ],
            ])->assertOk();

        $this->assertSame('Dallas', $this->first($tenant, 'city=Plano')['area_name']);
    }

    // ── Specific dates and "full until" (Jose, 2026-09-25: finish it so A1
    //    Tools can go — A1 runs Cleveland on 612 of these) ───────────────────

    /** A date marked closed is skipped; the next Monday is first. */
    public function test_a_closed_specific_date_is_skipped(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $area = $this->dallas($tenant);

        $this->signedInAs($tenant['admin'])
            ->putJson("/api/v1/scheduling/areas/{$area->id}/dates/2026-10-05", ['closed' => true])
            ->assertOk()
            ->assertJsonPath('data.dates.0.date', '2026-10-05')
            ->assertJsonPath('data.dates.0.closed', true);

        $this->assertSame('2026-10-12', $this->first($tenant, 'zip=75201')['first']['date']);
    }

    /**
     * A specific date REPLACES the day's windows — including opening a day the
     * pattern does not work (A1: "3 jobs on Sunday the 14th").
     */
    public function test_a_specific_date_can_open_a_non_working_day_with_its_own_windows(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $area = $this->dallas($tenant);

        $this->signedInAs($tenant['admin'])
            ->putJson("/api/v1/scheduling/areas/{$area->id}/dates/2026-10-05", ['closed' => true])
            ->assertOk();

        // Tuesday the 6th: Dallas does not work Tuesdays. Open it, one window.
        $this->signedInAs($tenant['admin'])
            ->putJson("/api/v1/scheduling/areas/{$area->id}/dates/2026-10-06", [
                'windows' => [['start_time' => '10:00', 'end_time' => '13:00', 'max_jobs' => 3]],
            ])->assertOk();

        $this->booked($tenant, '2026-10-06 10:00:00');

        $first = $this->first($tenant, 'zip=75201')['first'];

        $this->assertSame('2026-10-06', $first['date']);
        $this->assertSame([['10:00', 2]], array_map(
            fn ($w) => [$w['start_time'], $w['remaining']],
            $first['windows'],
        ), 'The date offers only its own window, and a booking in it counts.');
    }

    /** Clearing a date puts the weekly pattern back. */
    public function test_clearing_a_specific_date_restores_the_pattern(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $area = $this->dallas($tenant);

        $this->signedInAs($tenant['admin'])
            ->putJson("/api/v1/scheduling/areas/{$area->id}/dates/2026-10-05", ['closed' => true])
            ->assertOk();
        $this->signedInAs($tenant['admin'])
            ->deleteJson("/api/v1/scheduling/areas/{$area->id}/dates/2026-10-05")
            ->assertOk()
            ->assertJsonPath('data.dates', []);

        $this->assertSame('2026-10-05', $this->first($tenant, 'zip=75201')['first']['date']);
    }

    /**
     * "We are full until the 15th": every day before it is CLOSED — skipped,
     * not shown as full — and the first Monday on or after it is offered.
     */
    public function test_available_from_closes_every_day_before_it(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $area = $this->dallas($tenant);

        $this->signedInAs($tenant['admin'])
            ->putJson("/api/v1/scheduling/areas/{$area->id}/available-from", ['available_from' => '2026-10-15'])
            ->assertOk()
            ->assertJsonPath('data.available_from', '2026-10-15');

        $this->assertSame('2026-10-19', $this->first($tenant, 'zip=75201')['first']['date']);

        $this->signedInAs($tenant['admin'])
            ->putJson("/api/v1/scheduling/areas/{$area->id}/available-from", ['available_from' => null])
            ->assertOk();

        $this->assertSame('2026-10-05', $this->first($tenant, 'zip=75201')['first']['date']);
    }

    /** A window is never stored as a zero; closing is its own flag. */
    public function test_a_specific_date_refuses_a_zero(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $area = $this->dallas($tenant);

        $this->signedInAs($tenant['admin'])
            ->putJson("/api/v1/scheduling/areas/{$area->id}/dates/2026-10-05", [
                'windows' => [['start_time' => '07:00', 'end_time' => '11:00', 'max_jobs' => 0]],
            ])->assertUnprocessable();
    }

    /** The suggest box offers all three kinds, and says what each one is. */
    public function test_suggest_offers_areas_cities_and_zips(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $area = $this->dallas($tenant);

        $this->signedInAs($tenant['admin'])
            ->putJson("/api/v1/scheduling/areas/{$area->id}/zips", [
                'zips' => [['zip' => '75201', 'city' => 'Dallas']],
            ])->assertOk();

        $rows = $this->signedInAs($tenant['admin'])
            ->getJson('/api/v1/scheduling/suggest?q=Dal')
            ->assertOk()
            ->json('data');

        $types = collect($rows)->pluck('type')->all();

        $this->assertContains('area', $types, 'The area named Dallas should be offered.');
        $this->assertContains('city', $types, 'The city Dallas should be offered separately.');

        // The area and the city are DIFFERENT answers with the same label —
        // which is exactly why this suggests instead of guessing.
        $city = collect($rows)->firstWhere('type', 'city');
        $this->assertSame('Dallas', $city['label']);
        $this->assertStringContainsString('1 zip', $city['detail']);
    }

    public function test_suggest_matches_a_zip_by_prefix(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $area = $this->dallas($tenant);

        $this->signedInAs($tenant['admin'])
            ->putJson("/api/v1/scheduling/areas/{$area->id}/zips", [
                'zips' => [['zip' => '75201', 'city' => 'Dallas']],
            ])->assertOk();

        $rows = $this->signedInAs($tenant['admin'])
            ->getJson('/api/v1/scheduling/suggest?q=752')
            ->assertOk()
            ->json('data');

        $zip = collect($rows)->firstWhere('type', 'zip');

        $this->assertNotNull($zip, 'A zip prefix should suggest the zip.');
        $this->assertSame('75201', $zip['label']);
        $this->assertSame($area->id, $zip['area_id']);
    }

    /** A suggestion must never name a place in another organization. */
    public function test_suggest_never_leaves_the_organization(): void
    {
        $alpha = $this->provisionTenant('Alpha');
        $beta = $this->provisionTenant('Beta', 'beta@example.test');

        $betaArea = $this->dallas($beta);

        $this->signedInAs($beta['admin'])
            ->putJson("/api/v1/scheduling/areas/{$betaArea->id}/zips", [
                'zips' => [['zip' => '75201', 'city' => 'Dallas']],
            ])->assertOk();

        $rows = $this->signedInAs($alpha['admin'])
            ->getJson('/api/v1/scheduling/suggest?q=Dal')
            ->assertOk()
            ->json('data');

        $this->assertSame([], $rows, "Alpha must not see Beta's areas or cities.");
    }
}
