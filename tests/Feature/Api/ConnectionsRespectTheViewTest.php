<?php

namespace Tests\Feature\Api;

use App\Models\Company;
use App\Models\SocialConnection;
use App\Models\User;
use App\Support\Social\SocialProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/**
 * A sibling company's connection is none of your business.
 *
 * SocialConnectionAccess states the rule plainly: "Connecting on A-1 Chimney
 * still never appears on Premier Garage." Marketing's own catalogue obeys it —
 * `index()` filters every row through `inView()`.
 *
 * Leads, Reviews and Business Profile did not. Their `connectionState()` ran a
 * bare `SocialConnection::query()`, which the tenant scope narrows to the
 * organization and nothing narrows to the view, so every company in an
 * organization saw every other company's connected Google account. Jose found
 * it: the Google Ads account connected on the EzBizi company was sitting in
 * A-1 Chimney's Leads tab, and those two are siblings.
 *
 * The sync paths had the same hole and it is the worse half. `sync()` collected
 * every connection in the organization and fetched through all of them, so a
 * person in one company could spend a sibling's API quota and pull that
 * sibling's enquiries — while `index()` correctly filters the rows by view, so
 * the leads they caused to be created were never even visible to them.
 *
 * What was NOT broken, and is asserted here so a fix does not "tidy" it away:
 * the lead list itself filters by company and franchise, so no customer's
 * details ever crossed a company boundary.
 */
class ConnectionsRespectTheViewTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    /** @return array{0: array, 1: Company, 2: User} */
    private function twoCompanies(): array
    {
        $t = $this->provisionTenant('A1-Group');

        $sibling = $this->inTenant($t['org'], fn () => Company::create([
            'organization_id' => $t['org']->id,
            'name'            => 'Premier Garage Repair',
        ]));

        // The connection belongs to the FIRST company.
        $this->inTenant($t['org'], function () use ($t) {
            $row = SocialConnection::create([
                'organization_id' => $t['org']->id,
                'scope_type'      => SocialConnection::SCOPE_COMPANY,
                'scope_id'        => $t['company']->id,
                'provider'        => SocialProvider::GoogleAds->value,
                'remote_id'       => '7061094046',
                'display_name'    => '706-109-4046',
            ]);

            $row->connect(['refresh_token' => 'rt-sibling']);
            $row->save();
        });

        $this->inTenant($t['org'], function () use ($t) {
            $row = SocialConnection::create([
                'organization_id' => $t['org']->id,
                'scope_type'      => SocialConnection::SCOPE_COMPANY,
                'scope_id'        => $t['company']->id,
                'provider'        => SocialProvider::GoogleBusinessProfile->value,
                'remote_id'       => 'accounts/111',
                'display_name'    => 'A-1 on Maps',
            ]);

            $row->connect(['refresh_token' => 'rt-sibling-gbp']);
            $row->save();
        });

        // The admin, looking at the OTHER company.
        $admin = $this->inTenant($t['org'], function () use ($t, $sibling) {
            $u = $t['admin'];
            $u->forceFill([
                'preferences' => ['navigation.scope' => 'company:'.$sibling->id],
            ])->save();

            return $u->fresh();
        });

        return [$t, $sibling, $admin];
    }

    public function test_leads_does_not_show_a_sibling_companys_advertising_account(): void
    {
        [, , $admin] = $this->twoCompanies();

        $connections = $this->signedInAs($admin)
            ->getJson('/api/v1/leads')
            ->assertOk()
            ->json('meta.connections');

        $this->assertSame(
            [],
            $connections,
            'A connection made on one company must not appear in a sibling company.',
        );
    }

    public function test_reviews_does_not_show_a_sibling_companys_business_profile(): void
    {
        [, , $admin] = $this->twoCompanies();

        $this->assertSame(
            [],
            $this->signedInAs($admin)->getJson('/api/v1/reviews')->assertOk()->json('meta.connections'),
        );
    }

    public function test_business_profile_does_not_show_a_siblings_connection(): void
    {
        [, , $admin] = $this->twoCompanies();

        $this->assertSame(
            [],
            $this->signedInAs($admin)
                ->getJson('/api/v1/business-profile/locations')
                ->assertOk()
                ->json('meta.connections'),
        );
    }

    public function test_fetching_leads_cannot_reach_through_a_siblings_connection(): void
    {
        [, , $admin] = $this->twoCompanies();

        // 409 is the "nothing is connected here" answer. Anything else means
        // sync collected a connection this person cannot see, and every request
        // it makes spends the sibling's quota on the sibling's account.
        $this->signedInAs($admin)
            ->postJson('/api/v1/leads/sync')
            ->assertStatus(409);
    }

    public function test_the_company_that_owns_the_connection_still_sees_it(): void
    {
        [$t, , ] = $this->twoCompanies();

        // The guard must narrow the view, not close it. Same organization, same
        // connection, viewed from the company that made it.
        $owner = $this->inTenant($t['org'], function () use ($t) {
            $u = $t['admin'];
            $u->forceFill([
                'preferences' => ['navigation.scope' => 'company:'.$t['company']->id],
            ])->save();

            return $u->fresh();
        });

        $connections = $this->signedInAs($owner)
            ->getJson('/api/v1/leads')
            ->assertOk()
            ->json('meta.connections');

        // Leads' state covers every data source, so the owning company sees the
        // Business Profile it also connected. What matters is that the guard
        // narrowed the view rather than closing it.
        $names = array_column($connections, 'display_name');

        $this->assertContains('706-109-4046', $names);
    }
}
