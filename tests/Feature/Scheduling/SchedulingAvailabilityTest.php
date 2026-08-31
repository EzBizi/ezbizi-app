<?php

namespace Tests\Feature\Scheduling;

use App\Models\ServiceArea;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/**
 * Reading capacity by zip or by area
 * (design docs/previews/scheduling-layouts.html #4).
 *
 * The check that carries a promise is `basis`. Nothing subtracts bookings yet,
 * so the response must say the number is CONFIGURED capacity rather than
 * remaining — a cell labelled "remaining" would read full forever and be a
 * confident false statement rather than an empty one.
 */
class SchedulingAvailabilityTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    /** Dallas: 2 jobs in a 7–11 Monday slot, and nothing on Sunday. */
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
                ],
            ])->assertOk();

        $this->signedInAs($tenant['admin'])
            ->putJson("/api/v1/scheduling/areas/{$area->id}/zips", ['zips' => ['75201']])
            ->assertOk();

        return $area;
    }

    public function test_a_zip_resolves_to_its_area_and_the_number_is_named_configured(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $this->dallas($tenant);

        $body = $this->signedInAs($tenant['admin'])
            ->getJson('/api/v1/scheduling/availability?zip=75201&week=2026-09-02')
            ->assertOk()
            ->json('data');

        $this->assertSame('Dallas', $body['matched']['area_name']);
        $this->assertSame('zip', $body['matched']['by']);

        // The promise: not "remaining", because nothing subtracts bookings.
        $this->assertSame('configured', $body['basis']);

        // 2026-09-02 is a Wednesday; its week starts Monday the 31st.
        $this->assertSame('2026-08-31', $body['week_start']);
        $this->assertSame('2026-08-24', $body['previous_week']);
        $this->assertSame('2026-09-07', $body['next_week']);
    }

    /**
     * A closed day is flagged, not omitted. The client needs to tell "we do not
     * work Sundays" from "Sunday is full", and it cannot do that from a day
     * that is simply missing from the payload.
     */
    public function test_a_day_with_no_slots_comes_back_closed_rather_than_absent(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $this->dallas($tenant);

        $days = $this->signedInAs($tenant['admin'])
            ->getJson('/api/v1/scheduling/availability?zip=75201&week=2026-08-31')
            ->assertOk()
            ->json('data.days');

        $this->assertCount(7, $days, 'All seven days travel; the client decides what to draw.');

        $monday = collect($days)->firstWhere('date', '2026-08-31');
        $this->assertFalse($monday['closed']);
        $this->assertSame(2, $monday['slots'][0]['capacity']);
        $this->assertSame('07:00', $monday['slots'][0]['start_time']);

        $sunday = collect($days)->firstWhere('date', '2026-09-06');
        $this->assertTrue($sunday['closed']);
        $this->assertSame([], $sunday['slots']);
    }

    /** A zip nobody covers is an answer, not a 404. */
    public function test_an_uncovered_zip_answers_with_no_match_rather_than_failing(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $this->dallas($tenant);

        $body = $this->signedInAs($tenant['admin'])
            ->getJson('/api/v1/scheduling/availability?zip=99999')
            ->assertOk()
            ->json('data');

        $this->assertNull($body['matched']);
        $this->assertSame([], $body['days']);
    }

    /** Another organization's zip is not findable, even by exact match. */
    public function test_a_zip_belonging_to_another_organization_is_not_matched(): void
    {
        $alpha = $this->provisionTenant('Alpha');
        $beta = $this->provisionTenant('Beta', 'beta@example.test');

        $this->dallas($beta);

        $body = $this->signedInAs($alpha['admin'])
            ->getJson('/api/v1/scheduling/availability?zip=75201')
            ->assertOk()
            ->json('data');

        $this->assertNull($body['matched'], "Alpha must not see Beta's coverage.");
    }

    public function test_searching_with_neither_a_zip_nor_an_area_is_refused(): void
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
}
