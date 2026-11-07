<?php

namespace Tests\Feature\Scheduling;

use App\Models\BusinessLocation;
use App\Models\Franchise;
use App\Models\ServiceArea;
use App\Models\SocialConnection;
use App\Models\WebLocation;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/**
 * Scheduling › Locations (Jovanni, 2026-10-01: "company addresses with a
 * feature that shows the closest location to the inputed address ... as well
 * as contact info for newbie dispatchers"; queue 49).
 */
class SchedulingLocationsTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    /** Dallas and Houston branches; Dallas listed twice, as two sites list it. */
    private function branches(array $tenant): array
    {
        $area = $this->inTenant($tenant['org'], fn () => ServiceArea::create(['franchise_id' => $tenant['franchise']->id, 'name' => 'Dallas']));
        $this->signedInAs($tenant['admin'])
            ->putJson("/api/v1/scheduling/areas/{$area->id}/zips", ['zips' => ['75243']])
            ->assertOk();

        return $this->inTenant($tenant['org'], function () use ($tenant, $area) {

            $row = fn (string $name, string $zip, float $lat, float $lng, ?string $street = null) => [
                'company_id' => $tenant['company']->id, 'name' => $name, 'phone' => '(469) 454-0303',
                'address_line1' => $street ?? "1 {$name} St", 'city' => $name, 'state' => 'TX',
                'postal_code' => $zip, 'latitude' => $lat, 'longitude' => $lng,
            ];

            $dallas = WebLocation::create($row('Dallas', '75243', 32.9126, -96.7578, '11836 Judd Ct'));
            WebLocation::create($row('Dallas', '75243', 32.9126, -96.7578, '11836 Judd Ct')); // a second site
            $houston = WebLocation::create($row('Houston', '77056', 29.7520, -95.4625));

            return [$area, $dallas, $houston];
        });
    }

    public function test_branches_are_listed_once_each_with_the_area_holding_their_zip(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        [$area, $dallas, $houston] = $this->branches($tenant);

        $body = $this->signedInAs($tenant['admin'])->getJson('/api/v1/scheduling/locations')
            ->assertOk()->json();

        $this->assertSame(['Dallas', 'Houston'], array_column($body['data'], 'name'), 'A branch on two sites is one branch.');
        $this->assertSame(['id' => $area->id, 'name' => 'Dallas'], $body['data'][0]['area']);
        $this->assertNull($body['data'][1]['area'], 'No area holds 77056.');
        $this->assertSame([], $body['meta']['notes'], 'Only non-empty notes are listed.');
    }

    /** Jose, 2026-11-05: the Business Profile listing is the address; the website row is the fallback. */
    public function test_business_profile_listings_lead_and_website_rows_fill_the_gaps(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        [, $dallas, $houston] = $this->branches($tenant);
        $this->inTenant($tenant['org'], function () use ($tenant) {
            $gbp = SocialConnection::create([
                'organization_id' => $tenant['org']->id, 'scope_type' => SocialConnection::SCOPE_COMPANY,
                'scope_id' => $tenant['company']->id, 'provider' => 'google_business_profile', 'remote_id' => 'acc-1', 'display_name' => 'A1',
            ]);
            $gbp->connect(['refresh_token' => 'rt']);
            $gbp->save();
            $listing = fn (array $a) => BusinessLocation::create([
                'organization_id' => $tenant['org']->id, 'company_id' => $tenant['company']->id, 'connection_id' => $gbp->id,
                'remote_name' => 'locations/'.bin2hex(random_bytes(4)), 'title' => 'A1 Chimney', 'verification_state' => 'VERIFIED', ...$a,
            ]);
            // Same place as the Dallas website row, with Google's current phone.
            $listing(['address_line1' => '11836 Judd Ct', 'city' => 'Dallas', 'state' => 'TX', 'postal_code' => '75243', 'phone' => '(469) 000-1111']);
            // A service-area listing (no public address) whose phone is the Houston row's: Houston stays as the website has it.
            $listing(['phone' => '(469) 454-0303']);
        });

        $rows = collect($this->signedInAs($tenant['admin'])->getJson('/api/v1/scheduling/locations')->assertOk()->json('data'))->keyBy('name');

        $this->assertSame(['Dallas', 'Houston'], $rows->keys()->all(), 'One row per place, no duplicates.');
        $this->assertSame(['google_business_profile', '(469) 000-1111', 32.9126], [$rows['Dallas']['source'], $rows['Dallas']['phone'], $rows['Dallas']['latitude']]);
        $this->assertSame(['website', $houston->id], [$rows['Houston']['source'], $rows['Houston']['id']]);
    }

    /** Measured on prod 2026-11-05: 11 listings are service-area only (no storefront, only places). */
    public function test_a_service_area_listing_is_named_by_its_first_place_and_a_half_entered_site_row_is_dropped(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $this->branches($tenant);
        $this->inTenant($tenant['org'], function () use ($tenant) {
            $gbp = \App\Models\SocialConnection::create([
                'organization_id' => $tenant['org']->id, 'scope_type' => \App\Models\SocialConnection::SCOPE_COMPANY,
                'scope_id' => $tenant['company']->id, 'provider' => 'google_business_profile', 'remote_id' => 'acc-1', 'display_name' => 'A1',
            ]);
            $gbp->connect(['refresh_token' => 'rt']);
            $gbp->save();
            \App\Models\BusinessLocation::create(['organization_id' => $tenant['org']->id, 'company_id' => $tenant['company']->id,
                'connection_id' => $gbp->id, 'remote_name' => 'locations/mi', 'title' => 'A1 Chimney', 'verification_state' => 'VERIFIED',
                'phone' => '(313) 284-1124', 'service_places' => ['Warren, MI, USA', 'Detroit, MI, USA']]);
            // The Houston branch entered again on another site with no address.
            WebLocation::create(['company_id' => $tenant['company']->id, 'name' => 'Houston', 'phone' => '(469) 454-0303']);
        });

        $rows = collect($this->signedInAs($tenant['admin'])->getJson('/api/v1/scheduling/locations')->assertOk()->json('data'))->keyBy('name');

        $this->assertSame(['A1 Chimney · Warren, MI', 'Dallas', 'Houston'], $rows->keys()->sort()->values()->all());
        $this->assertSame(['Warren', 'MI', ['Warren, MI, USA', 'Detroit, MI, USA']],
            [$rows['A1 Chimney · Warren, MI']['city'], $rows['A1 Chimney · Warren, MI']['state'], $rows['A1 Chimney · Warren, MI']['service_places']]);
        $this->assertSame('1 Houston St', $rows['Houston']['address_line1'], 'The filled row stands; the empty one is gone.');
    }

    /** An unplaced listing shows in every branch view of its company; another branch's does not. */
    public function test_a_branch_view_lists_its_own_and_the_companys_unplaced_listings(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $other = $this->inTenant($tenant['org'], fn () => Franchise::create(['company_id' => $tenant['company']->id, 'name' => 'Other']));
        $this->inTenant($tenant['org'], function () use ($tenant, $other) {
            $gbp = SocialConnection::create([
                'organization_id' => $tenant['org']->id, 'scope_type' => SocialConnection::SCOPE_COMPANY,
                'scope_id' => $tenant['company']->id, 'provider' => 'google_business_profile', 'remote_id' => 'acc-1', 'display_name' => 'A1',
            ]);
            $gbp->connect(['refresh_token' => 'rt']);
            $gbp->save();
            foreach ([['Unplaced', null], ['Mine', $tenant['franchise']->id], ['Theirs', $other->id]] as [$city, $fr]) {
                BusinessLocation::create(['organization_id' => $tenant['org']->id, 'company_id' => $tenant['company']->id,
                    'franchise_id' => $fr, 'connection_id' => $gbp->id, 'remote_name' => 'locations/'.$city, 'title' => 'A1',
                    'verification_state' => 'VERIFIED', 'address_line1' => "1 {$city} St", 'city' => $city, 'state' => 'CT', 'postal_code' => '06010']);
            }
        });

        $names = collect($this->signedInAs($tenant['admin'])->withHeaders(['X-EzBizi-View' => 'franchise:'.$tenant['franchise']->id])
            ->getJson('/api/v1/scheduling/locations')->assertOk()->json('data'))->pluck('city')->sort()->values()->all();

        $this->assertSame(['Mine', 'Unplaced'], $names);
    }

    public function test_the_nearest_branches_and_the_area_serving_an_address(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        [$area, $dallas, $houston] = $this->branches($tenant);

        Http::fake(['photon.komoot.io/*' => Http::response(['features' => [[
            'geometry' => ['coordinates' => [-96.80, 32.78]],
            'properties' => ['countrycode' => 'US', 'housenumber' => '500', 'street' => 'Main St', 'city' => 'Dallas', 'state' => 'Texas', 'postcode' => '75243'],
        ]]])]);

        $data = $this->signedInAs($tenant['admin'])
            ->getJson('/api/v1/scheduling/locations/nearest?q='.urlencode('500 Main St, Dallas'))
            ->assertOk()->json('data');

        $this->assertSame('500 Main St, Dallas, Texas 75243', $data['place']['label']);
        $this->assertSame(['location_id' => $dallas->id, 'area' => ['id' => $area->id, 'name' => 'Dallas']], $data['serving']);
        $this->assertSame([$dallas->id, $houston->id], array_column($data['nearest'], 'location_id'));
        // Downtown Dallas to Judd Ct is about 9.6 miles as the crow flies.
        $this->assertEqualsWithDelta(9.6, $data['nearest'][0]['miles'], 0.5);
        $this->assertGreaterThan(200, $data['nearest'][1]['miles']);
    }

    public function test_an_address_that_does_not_geocode_is_a_422_on_q(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        Http::fake(['photon.komoot.io/*' => Http::response(['features' => []])]);

        $this->signedInAs($tenant['admin'])
            ->getJson('/api/v1/scheduling/locations/nearest?q=nowhere+at+all')
            ->assertStatus(422)
            ->assertJsonValidationErrors('q');
    }

    public function test_a_note_for_new_dispatchers_needs_scheduling_edit(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $this->travelTo(CarbonImmutable::parse('2026-10-02 10:00:00'));
        $url = "/api/v1/scheduling/locations/notes/{$tenant['company']->id}";

        $reader = $this->userWithRole($tenant, 'Dispatcher', ['scheduling.view', 'scheduling.settings.view', 'scheduling.locations.view']);
        $this->signedInAs($reader)->putJson($url, ['text' => 'Call the office first.'])->assertForbidden();

        $this->signedInAs($tenant['admin'])->putJson($url, ['text' => "  Call the office first.\n"])
            ->assertOk()
            ->assertJsonPath('data.text', 'Call the office first.');

        $this->signedInAs($reader)->getJson('/api/v1/scheduling/locations')
            ->assertOk()
            ->assertJsonPath('meta.notes.0.text', 'Call the office first.')
            ->assertJsonPath('meta.notes.0.company_name', $tenant['company']->name);

        // Cleared is not listed.
        $this->signedInAs($tenant['admin'])->putJson($url, ['text' => ''])->assertOk();
        $this->signedInAs($reader)->getJson('/api/v1/scheduling/locations')->assertJsonPath('meta.notes', []);
    }

    public function test_the_locations_tab_is_its_own_grant(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $caller = $this->userWithRole($tenant, 'Caller', ['scheduling.view', 'scheduling.availability.view']);

        $this->signedInAs($caller)->getJson('/api/v1/scheduling/locations')->assertForbidden();
        $this->signedInAs($caller)->getJson('/api/v1/scheduling/locations/nearest?q=75201')->assertForbidden();
    }
}
