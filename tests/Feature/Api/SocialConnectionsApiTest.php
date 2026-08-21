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

    public function test_the_row_never_carries_tokens(): void
    {
        $t = $this->provisionTenant('A1-Group');
        $this->connection($t, 'company', $t['company']->id);

        $row = $this->signedInAs($t['admin'])->getJson(self::ENDPOINT)
            ->assertOk()->json('data.0');

        $this->assertSame([
            'id', 'provider', 'provider_label', 'display_name', 'handle',
            'scope_type', 'scope_id', 'status', 'status_reason', 'last_reached_at',
        ], array_keys($row));

        $this->assertArrayNotHasKey('credentials', $row);
        $this->assertArrayNotHasKey('remote_id', $row);
        $this->assertStringNotContainsString(self::REFRESH, json_encode($row));

        $this->assertSame('pinterest', $row['provider']);
        $this->assertSame('Pinterest', $row['provider_label']);
        $this->assertSame(SocialConnection::STATUS_CONNECTED, $row['status']);
    }

    private function connection(
        array $t,
        string $scopeType,
        string $scopeId,
        string $name = 'A-1 Chimney',
        string $remoteId = 'pin-1',
    ): SocialConnection {
        return $this->inTenant($t['org'], function () use ($t, $scopeType, $scopeId, $name, $remoteId) {
            $row = new SocialConnection;
            $row->forceFill([
                'organization_id' => $t['org']->id,
                'scope_type'      => $scopeType,
                'scope_id'        => $scopeId,
                'provider'        => 'pinterest',
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
