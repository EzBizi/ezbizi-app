<?php

namespace Tests\Feature\Api;

use App\Models\Company;
use App\Models\SocialConnection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/**
 * Connected pages — XOR-scoped the way inboxes are (v0.0.0041), with one
 * difference the owner locked for this module: an organization-level page is
 * visible down into a company and a franchise, because "every company can use
 * it". A sibling company still cannot see another company's page.
 */
class SocialConnectionsApiTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    private const ENDPOINT = '/api/v1/social/connections';

    private const REFRESH = 'pinr-a-1-chimney-refresh-token';

    public function test_the_list_is_refused_without_the_marketing_tab(): void
    {
        $t      = $this->provisionTenant('A1-Group');
        $viewer = $this->userWithRole($t, 'Viewer', ['customers.view']);

        $this->signedInAs($viewer)
            ->getJson(self::ENDPOINT)
            ->assertForbidden()
            ->assertJsonPath(
                'message',
                fn (string $m) => str_contains($m, 'open Marketing') && ! str_contains($m, 'marketing.view'),
            );
    }

    public function test_a_company_page_is_hidden_from_a_sibling_company(): void
    {
        $t = $this->provisionTenant('A1-Group');
        $premier = $this->inTenant($t['org'], fn () => Company::create([
            'organization_id' => $t['org']->id,
            'name'            => 'Premier Garage Repair',
        ]));

        $this->connection($t, 'company', $t['company']->id);

        $this->lookingAt($t['admin'], 'company', $premier->id);

        $names = $this->signedInAs($t['admin']->fresh())
            ->getJson(self::ENDPOINT)
            ->assertOk()
            ->json('data.*.display_name');

        $this->assertSame([], $names);
    }

    public function test_the_organization_view_lists_a_company_page(): void
    {
        $t = $this->provisionTenant('A1-Group');
        $this->connection($t, 'company', $t['company']->id);

        $names = $this->signedInAs($t['admin'])
            ->getJson(self::ENDPOINT)
            ->assertOk()
            ->json('data.*.display_name');

        $this->assertSame(['A-1 Chimney'], $names);
    }

    /**
     * A Google Ads account is not a page.
     *
     * Jose, 2026-09-08: twenty-two Google Ads accounts appeared under "Pages"
     * in Social Media Management, each with a tick box in the composer. They
     * are read by Leads through its own payload; nothing is ever published to
     * one, and the composer offering them is a control that cannot work.
     */
    public function test_a_data_source_is_not_listed_among_the_pages(): void
    {
        $t = $this->provisionTenant('A1-Group');
        $this->connection($t, 'company', $t['company']->id);
        $this->connection($t, 'company', $t['company']->id, 'A1 Chimney - Cleveland', '4941850746', 'google_ads');
        $this->connection($t, 'company', $t['company']->id, 'A-1 Chimney GBP', 'accounts/1', 'google_business_profile');

        $names = $this->signedInAs($t['admin'])
            ->getJson(self::ENDPOINT)
            ->assertOk()
            ->json('data.*.display_name');

        $this->assertSame(['A-1 Chimney'], $names);
    }

    public function test_a_company_view_lists_org_company_and_branch_pages_not_a_sibling(): void
    {
        $t = $this->provisionTenant('A1-Group');
        $premier = $this->inTenant($t['org'], fn () => Company::create([
            'organization_id' => $t['org']->id,
            'name'            => 'Premier Garage Repair',
        ]));

        $this->connection($t, 'organization', $t['org']->id, 'Group', 'group-pin');
        $this->connection($t, 'company', $t['company']->id, 'A-1 Chimney', 'a1-pin');
        $this->connection($t, 'franchise', $t['franchise']->id, 'Connecticut', 'ct-pin');
        $this->connection($t, 'company', $premier->id, 'Premier', 'premier-pin');

        $this->lookingAt($t['admin'], 'company', $t['company']->id);

        $names = $this->signedInAs($t['admin']->fresh())
            ->getJson(self::ENDPOINT)
            ->assertOk()
            ->json('data.*.display_name');

        $this->assertSame(['A-1 Chimney', 'Connecticut', 'Group'], $names);
    }

    public function test_a_franchise_view_lists_org_parent_and_that_branch_only(): void
    {
        $t = $this->provisionTenant('A1-Group');
        $other = $this->inTenant($t['org'], fn () => \App\Models\Franchise::create([
            'organization_id' => $t['org']->id,
            'company_id'      => $t['company']->id,
            'name'            => 'Illinois',
        ]));

        $this->connection($t, 'organization', $t['org']->id, 'Group', 'group-pin');
        $this->connection($t, 'company', $t['company']->id, 'A-1 Chimney', 'a1-pin');
        $this->connection($t, 'franchise', $t['franchise']->id, 'Connecticut', 'ct-pin');
        $this->connection($t, 'franchise', $other->id, 'Illinois', 'il-pin');

        $this->lookingAt($t['admin'], 'franchise', $t['franchise']->id);

        $names = $this->signedInAs($t['admin']->fresh())
            ->getJson(self::ENDPOINT)
            ->assertOk()
            ->json('data.*.display_name');

        $this->assertSame(['A-1 Chimney', 'Connecticut', 'Group'], $names);
    }

    public function test_a_branch_only_user_who_never_used_the_switcher_does_not_see_a_sibling_branch(): void
    {
        // No stored view means ViewScope falls back to organization; that arm
        // must answer from this person's reach, not from the scope.
        $t = $this->provisionTenant('A1-Group');
        $sibling = $this->extraFranchise($t, 'Illinois');

        $this->connection($t, 'organization', $t['org']->id, 'Group', 'group-pin');
        $this->connection($t, 'company', $t['company']->id, 'A-1 Chimney', 'a1-pin');
        $this->connection($t, 'franchise', $t['franchise']->id, 'Connecticut', 'ct-pin');
        $this->connection($t, 'franchise', $sibling->id, 'Illinois', 'il-pin');

        $branch = $this->userWithRole($t, 'Branch', ['marketing.view'], onlyAt: $t['franchise']);

        $names = $this->signedInAs($branch)
            ->getJson(self::ENDPOINT)
            ->assertOk()
            ->json('data.*.display_name');

        $this->assertSame(['A-1 Chimney', 'Connecticut', 'Group'], $names);
    }

    public function test_the_row_never_carries_tokens(): void
    {
        $t = $this->provisionTenant('A1-Group');
        $this->connection($t, 'company', $t['company']->id);

        $row = $this->signedInAs($t['admin'])->getJson(self::ENDPOINT)
            ->assertOk()->json('data.0');

        // The whole key list, in order, on purpose: this is the guard that
        // catches a token arriving in this payload by somebody adding a field
        // without thinking about what a connection row contains. `label` was
        // added 2026-09-16 by the Reports module - the tenant's own name for a
        // connection, which nothing listed before, so naming one had no
        // visible effect anywhere.
        $this->assertSame([
            'id', 'provider', 'provider_label', 'display_name', 'label', 'handle',
            'scope_type', 'scope_id', 'status', 'status_reason', 'detail', 'last_reached_at',
            'can_publish', 'cannot_publish_because', 'posting_note', 'asks_tiktok_choices',
        ], array_keys($row));

        $this->assertArrayNotHasKey('credentials', $row);
        $this->assertArrayNotHasKey('remote_id', $row);
        $this->assertStringNotContainsString(self::REFRESH, json_encode($row));

        $this->assertSame('pinterest', $row['provider']);
        $this->assertSame('Pinterest', $row['provider_label']);
        $this->assertSame(SocialConnection::STATUS_CONNECTED, $row['status']);
    }

    public function test_disconnecting_forgets_the_row_and_its_tokens(): void
    {
        $t   = $this->provisionTenant('A1-Group');
        $row = $this->connection($t, 'company', $t['company']->id);

        $this->signedInAs($t['admin'])
            ->deleteJson(self::ENDPOINT.'/'.$row->getKey())
            ->assertOk();

        $this->inTenant($t['org'], function () use ($row) {
            $this->assertNull(SocialConnection::query()->find($row->getKey()));
        });
    }

    public function test_a_sibling_company_cannot_disconnect_this_companys_page(): void
    {
        $t = $this->provisionTenant('A1-Group');
        $premier = $this->inTenant($t['org'], fn () => Company::create([
            'organization_id' => $t['org']->id,
            'name'            => 'Premier Garage Repair',
        ]));

        $row = $this->connection($t, 'company', $t['company']->id);

        $this->lookingAt($t['admin'], 'company', $premier->id);

        // Out of reach is a 404, not a 403: a sibling learning that a
        // connection exists is itself the leak.
        $this->signedInAs($t['admin']->fresh())
            ->deleteJson(self::ENDPOINT.'/'.$row->getKey())
            ->assertNotFound();

        $this->inTenant($t['org'], function () use ($row) {
            $this->assertNotNull(SocialConnection::query()->find($row->getKey()));
        });
    }

    public function test_disconnecting_is_refused_without_marketing_edit(): void
    {
        $t      = $this->provisionTenant('A1-Group');
        $row    = $this->connection($t, 'company', $t['company']->id);
        $viewer = $this->userWithRole($t, 'Reader', ['marketing.view']);

        $this->signedInAs($viewer)
            ->deleteJson(self::ENDPOINT.'/'.$row->getKey())
            ->assertForbidden();

        $this->inTenant($t['org'], function () use ($row) {
            $this->assertNotNull(SocialConnection::query()->find($row->getKey()));
        });
    }

    private function connection(
        array $t,
        string $scopeType,
        string $scopeId,
        string $name = 'A-1 Chimney',
        string $remoteId = 'pin-1',
        string $provider = 'pinterest',
    ): SocialConnection {
        return $this->inTenant($t['org'], function () use ($t, $scopeType, $scopeId, $name, $remoteId, $provider) {
            $row = new SocialConnection;
            $row->forceFill([
                'organization_id' => $t['org']->id,
                'scope_type'      => $scopeType,
                'scope_id'        => $scopeId,
                'provider'        => $provider,
                'remote_id'       => $remoteId,
                'display_name'    => $name,
                'handle'          => strtolower(str_replace(' ', '', $name)),
            ]);
            $row->connect([
                'refresh_token' => self::REFRESH,
                'access_token'  => 'pina-an-access-token',
            ]);
            $row->save();

            return $row;
        });
    }

    private function lookingAt(\App\Models\User $user, string $type, string $id): void
    {
        $this->inTenant($user->organization, function () use ($user, $type, $id) {
            $user->forceFill([
                'preferences' => array_merge($user->preferences ?? [], [
                    'navigation.scope' => $type.':'.$id,
                ]),
            ])->save();
        });
    }
}
