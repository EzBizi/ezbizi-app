<?php

namespace Tests\Feature\CustomerExperience;

use App\Models\BusinessLocation;
use App\Models\Franchise;
use App\Models\SocialConnection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/** Jose 2026-11-12: a connect files its listings under a branch by state; the leads branch follows. */
class ListingBranchesTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    public function test_listings_are_filed_by_state_and_the_connection_gets_its_leads_branch(): void
    {
        $t = $this->provisionTenant('Alpha');
        [$texas, $c1, $c2] = $this->inTenant($t['org'], function () use ($t) {
            $texas = Franchise::create(['organization_id' => $t['org']->id, 'company_id' => $t['company']->id, 'name' => 'Texas', 'is_active' => true]);
            Franchise::create(['organization_id' => $t['org']->id, 'company_id' => $t['company']->id, 'name' => 'Nationwide', 'is_active' => true]);
            $make = function (string $remote) use ($t) {
                $c = SocialConnection::create(['organization_id' => $t['org']->id, 'scope_type' => 'organization', 'scope_id' => $t['org']->id,
                    'provider' => 'google_business_profile', 'remote_id' => $remote, 'display_name' => $remote]);

                return $c;
            };
            [$c1, $c2] = [$make('accounts/1'), $make('accounts/2')];
            $place = fn ($c, $l, $state) => (new BusinessLocation)->forceFill(['organization_id' => $t['org']->id, 'company_id' => $t['company']->id,
                'connection_id' => $c->id, 'remote_name' => "locations/$l", 'title' => 'A', 'state' => $state])->save();
            $place($c1, 1, 'TX');
            $place($c1, 2, 'tx');
            $place($c2, 3, 'NC');   // no Texas-like branch: Nationwide
            $place($c2, 4, null);   // no address: left for a person

            return [$texas, $c1, $c2];
        });

        $this->artisan('ezbizi:gbp-map-branches')->assertSuccessful()->expectsOutputToContain('3 listings filed, 1 left for a person');

        $byLoc = BusinessLocation::withoutGlobalScopes()->pluck('franchise_id', 'remote_name');
        $this->assertSame($texas->id, $byLoc['locations/1']);
        $this->assertSame($texas->id, $byLoc['locations/2']);
        $this->assertNotNull($byLoc['locations/3']);
        $this->assertNull($byLoc['locations/4']);
        $this->assertSame($texas->id, SocialConnection::withoutGlobalScopes()->find($c1->id)->leads_franchise_id);
        $this->assertNull(SocialConnection::withoutGlobalScopes()->find($c2->id)->leads_franchise_id, 'two branches: none chosen');
    }
}
