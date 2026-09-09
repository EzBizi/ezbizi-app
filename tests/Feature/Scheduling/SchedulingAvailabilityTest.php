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

    /**
     * The screen has one box and a person types a NAME into it, not a uuid.
     * This exists because the client was written sending `area_name` against a
     * server that only took `zip` and `area_id` — the two halves disagreed and
     * nothing failed until somebody searched.
     */
    public function test_an_area_can_be_found_by_the_name_somebody_types(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $this->dallas($tenant);

        $body = $this->signedInAs($tenant['admin'])
            ->getJson('/api/v1/scheduling/availability?area_name=Dallas')
            ->assertOk()
            ->json('data');

        $this->assertSame('Dallas', $body['matched']['area_name']);
        $this->assertSame('name', $body['matched']['by']);
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

    /**
     * A city is a second way to find an area, and the whole reason it exists.
     * Somebody on the phone knows "Plano", not the zip.
     */
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

        $body = $this->signedInAs($tenant['admin'])
            ->getJson('/api/v1/scheduling/availability?city=Plano')
            ->assertOk()
            ->json('data');

        $this->assertSame('Dallas', $body['matched']['area_name']);
        $this->assertSame('city', $body['matched']['by']);
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
