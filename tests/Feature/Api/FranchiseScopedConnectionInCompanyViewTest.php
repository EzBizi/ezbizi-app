<?php

namespace Tests\Feature\Api;

use App\Models\Company;
use App\Models\Franchise;
use App\Models\SocialConnection;
use App\Support\Social\SocialConnectionAccess;
use App\Support\Social\SocialProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/**
 * A company sees its own franchises' connections, and not its siblings'.
 *
 * `SocialConnectionAccess::inView()` has a COMPANY branch that admits a
 * franchise-scoped connection when the franchise belongs to the viewer's
 * company. Nothing exercised it. The Reports session checked every test file
 * mentioning SCOPE_FRANCHISE for one that also puts the viewer in a company
 * view and found none - so the branch was unpinned in BOTH directions, and
 * either behaviour could have been changed without a failure.
 *
 * It mattered because three places in the codebase asserted the opposite. The
 * migration for `leads_franchise_id`, its test, and placeLeads() all said
 * inView "makes a franchise-scoped connection invisible to the company above",
 * which is false and was false when written - the admitting line landed in
 * d3f17b70 on 2026-08-21, before any of them. Three copies of a wrong sentence
 * and no test to contradict any of them.
 *
 * The method is what is correct here, and this fixes the intent as the method's:
 * somebody who places an account at a franchise must still be able to see and
 * manage it from the company they did it from. Hiding it would strand the
 * account behind the very act of placing it.
 *
 * The sibling case is the control. Without it this passes just as well for an
 * inView that admits every franchise-scoped connection in the organization,
 * which is the actual leak SocialConnectionAccess exists to prevent.
 */
class FranchiseScopedConnectionInCompanyViewTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    private function connectionAt(array $t, Franchise $franchise, string $customerId): SocialConnection
    {
        return $this->inTenant($t['org'], fn () => SocialConnection::create([
            'organization_id' => $t['org']->id,
            'scope_type' => SocialConnection::SCOPE_FRANCHISE,
            'scope_id' => $franchise->getKey(),
            'provider' => SocialProvider::GoogleAds->value,
            'remote_id' => $customerId,
            'display_name' => 'Account '.$customerId,
        ]));
    }

    private function viewingCompany(array $t, Company $company): void
    {
        $this->inTenant($t['org'], fn () => $t['admin']->forceFill([
            'preferences' => ['navigation.scope' => 'company:'.$company->getKey()],
        ])->save());
    }

    public function test_a_company_view_admits_its_own_franchises_connection_and_not_a_siblings(): void
    {
        $t = $this->provisionTenant('A1-Group');

        $mine = $this->connectionAt($t, $t['franchise'], '7061094046');

        // A franchise under a DIFFERENT company in the same organization. The
        // tenant scope does not separate these - only inView does.
        $siblingCompany = $this->inTenant($t['org'], fn () => Company::create([
            'organization_id' => $t['org']->id,
            'name' => 'Premier Garage Repair',
        ]));
        $theirFranchise = $this->inTenant($t['org'], fn () => Franchise::create([
            'organization_id' => $t['org']->id,
            'company_id' => $siblingCompany->getKey(),
            'name' => 'Premier - Dallas',
        ]));
        $theirs = $this->connectionAt($t, $theirFranchise, '1234567890');

        $this->viewingCompany($t, $t['company']);

        $this->inTenant($t['org'], function () use ($t, $mine, $theirs) {
            $access = app(SocialConnectionAccess::class);
            $user = $t['admin']->fresh();

            $this->assertTrue(
                $access->inView($user, $mine),
                'a company must see a connection placed at its own franchise - hiding it would '
                .'strand the account behind the act of placing it',
            );

            $this->assertFalse(
                $access->inView($user, $theirs),
                'and must not see one under a sibling company, which is the leak inView exists for',
            );
        });
    }

    public function test_a_franchise_view_does_not_see_a_sibling_franchise_in_the_same_company(): void
    {
        $t = $this->provisionTenant('A1-Group');

        $mine = $this->connectionAt($t, $t['franchise'], '7061094046');

        $siblingFranchise = $this->inTenant($t['org'], fn () => Franchise::create([
            'organization_id' => $t['org']->id,
            'company_id' => $t['company']->getKey(),
            'name' => 'A-1 - Connecticut',
        ]));
        $theirs = $this->connectionAt($t, $siblingFranchise, '5555555555');

        $this->inTenant($t['org'], fn () => $t['admin']->forceFill([
            'preferences' => ['navigation.scope' => 'franchise:'.$t['franchise']->getKey()],
        ])->save());

        $this->inTenant($t['org'], function () use ($t, $mine, $theirs) {
            $access = app(SocialConnectionAccess::class);
            $user = $t['admin']->fresh();

            $this->assertTrue($access->inView($user, $mine));
            $this->assertFalse(
                $access->inView($user, $theirs),
                'the one thing scoping to a franchise really does buy over leads_franchise_id: '
                .'sibling franchises in the SAME company stop seeing it',
            );
        });
    }

    /**
     * And the branch is actually reached from a request, not only callable.
     *
     * Asserting the method alone would pass even if every listing had stopped
     * calling it. Google Ads is deliberately NOT used here: index() excludes
     * dataSources() on purpose, so a Google Ads row never appears on this
     * endpoint whatever inView says, and a test written with one would have
     * failed for the wrong reason - which is exactly what my first draft did.
     */
    public function test_the_connections_endpoint_lists_a_franchise_scoped_row_in_company_view(): void
    {
        $t = $this->provisionTenant('A1-Group');

        $row = $this->inTenant($t['org'], fn () => SocialConnection::create([
            'organization_id' => $t['org']->id,
            'scope_type' => SocialConnection::SCOPE_FRANCHISE,
            'scope_id' => $t['franchise']->getKey(),
            'provider' => SocialProvider::FacebookPage->value,
            'remote_id' => 'fb-1',
            'display_name' => 'A-1 Chimney on Facebook',
        ]));

        $this->viewingCompany($t, $t['company']);

        $ids = collect(
            $this->signedInAs($t['admin']->fresh())
                ->getJson('/api/v1/social/connections')
                ->assertOk()
                ->json('data')
        )->pluck('id');

        $this->assertContains($row->getKey(), $ids->all());
    }
}
