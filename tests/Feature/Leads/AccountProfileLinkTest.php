<?php

namespace Tests\Feature\Leads;

use App\Models\BusinessLocation;
use App\Models\SocialConnection;
use App\Support\Leads\AccountProfileLink;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/** A1 Leads takeover (2026-10-19): Local Services account <-> Business Profile listing. */
class AccountProfileLinkTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    private function account(array $t, string $name, ?string $branch = null): SocialConnection
    {
        return SocialConnection::create(['organization_id' => $t['org']->id, 'scope_type' => SocialConnection::SCOPE_COMPANY,
            'scope_id' => $t['company']->id, 'provider' => 'google_ads', 'remote_id' => (string) random_int(1000000000, 9999999999),
            'display_name' => $name, 'leads_franchise_id' => $branch]);
    }

    private function listing(array $t, string $city, ?string $branch = null): BusinessLocation
    {
        return BusinessLocation::create(['organization_id' => $t['org']->id, 'company_id' => $t['company']->id, 'franchise_id' => $branch,
            'remote_name' => 'locations/'.uniqid(), 'title' => 'A1 Chimney', 'address_line1' => '1 Main St', 'city' => $city, 'state' => 'TX']);
    }

    public function test_one_clear_match_links_two_do_not_a_person_decides_and_a_move_renames(): void
    {
        $t = $this->provisionTenant('A1-Group');

        $this->inTenant($t['org'], function () use ($t) {
            $austin = $this->account($t, 'A1 Chimney - Austin');
            $branch = $this->account($t, 'A1 Chimney - Bucks County', $t['franchise']->id);
            $twice = $this->account($t, 'A1 Chimney - Dallas');
            $austinListing = $this->listing($t, 'Austin');
            $branchListing = $this->listing($t, 'Doylestown', $t['franchise']->id);
            $this->listing($t, 'Dallas');
            $this->listing($t, 'Dallas');

            $this->assertSame([2, 0], app(AccountProfileLink::class)->run());
            $this->assertSame($austinListing->id, $austin->refresh()->business_location_id, 'by city');
            $this->assertSame($branchListing->id, $branch->refresh()->business_location_id, 'by branch');
            $this->assertNull($twice->refresh()->business_location_id, 'two Dallas listings: a person decides');

            // The Austin listing moves to Round Rock: the account's name follows.
            $austinListing->forceFill(['city' => 'Round Rock'])->save();
            $this->assertSame([0, 1], app(AccountProfileLink::class)->run());
            $this->assertSame('A1 Chimney - Round Rock', $austin->refresh()->label);
        });

        // A person links Dallas by hand; it is theirs, and the office shows on its leads.
        [$dallas, $listing] = $this->inTenant($t['org'], fn () => [
            SocialConnection::query()->where('display_name', 'A1 Chimney - Dallas')->sole(),
            BusinessLocation::query()->where('city', 'Dallas')->first(),
        ]);
        $this->signedInAs($t['admin'])->patchJson("/api/v1/social/connections/{$dallas->id}/business-location", ['business_location_id' => $listing->id])
            ->assertOk()->assertJsonPath('data.office', '1 Main St, Dallas, TX');
        $this->inTenant($t['org'], fn () => $this->assertSame($t['admin']->id, $dallas->refresh()->business_location_by_user_id));
    }
}
