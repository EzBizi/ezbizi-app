<?php

namespace Tests\Feature\Api;

use App\Models\Assignment;
use App\Models\Company;
use App\Models\PhoneNumber;
use App\Models\SocialConnection;
use App\Support\Social\SocialProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/**
 * The people / numbers / connections figures on each card of the Organization
 * tree (UI queue 111). Two companies with different figures, so a count that
 * came from the wrong place, or from the whole organization, cannot pass.
 */
class CompaniesCountsTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    private function number(array $tenant, string $scopeType, string $scopeId): void
    {
        $this->inTenant($tenant['org'], fn () => PhoneNumber::factory()->create([
            'organization_id' => $tenant['org']->id, 'scope_type' => $scopeType, 'scope_id' => $scopeId,
        ]));
    }

    private function connection(array $tenant, string $scopeType, string $scopeId, string $status): void
    {
        $this->inTenant($tenant['org'], function () use ($tenant, $scopeType, $scopeId, $status) {
            $row = SocialConnection::create([
                'organization_id' => $tenant['org']->id, 'scope_type' => $scopeType, 'scope_id' => $scopeId,
                'provider' => SocialProvider::FacebookPage->value, 'remote_id' => uniqid(), 'display_name' => 'Page',
            ]);
            $row->forceFill(['status' => $status])->save();
        });
    }

    public function test_each_place_carries_its_own_counts_and_the_organization_carries_them_all(): void
    {
        $t    = $this->provisionTenant();
        $beta = $this->provisionTenant('Beta');

        $this->signedInAs($t['admin'])->postJson('/api/v1/companies', [
            'name' => 'Everdoors', 'franchise' => ['name' => 'Connecticut'],
        ])->assertOk();
        $everdoors   = $this->inTenant($t['org'], fn () => Company::where('name', 'Everdoors')->with('franchises')->firstOrFail());
        $connecticut = $everdoors->franchises->first();

        // People: two at Alpha F1, one at Connecticut, one at Alpha Co itself,
        // and a deactivated one at Alpha F1 who is not counted anywhere.
        $this->userWithRole($t, 'Tech A', onlyAt: $t['franchise']);
        $this->userWithRole($t, 'Tech B', onlyAt: $t['franchise']);
        $this->userWithRole($t, 'Tech C', onlyAt: $connecticut);
        $gone = $this->userWithRole($t, 'Gone', onlyAt: $t['franchise']);
        $this->inTenant($t['org'], fn () => $gone->forceFill(['is_active' => false])->save());
        $manager = $this->userWithRole($t, 'Manager');
        $this->inTenant($t['org'], fn () => Assignment::create([
            'organization_id' => $t['org']->id, 'user_id' => $manager->id, 'assigned_company_id' => $t['company']->id,
        ]));

        $this->number($t, 'company', $t['company']->id);
        $this->number($t, 'company', $t['company']->id);
        $this->number($t, 'franchise', $connecticut->id);
        $this->number($t, 'organization', $t['org']->id);
        $this->number($beta, 'company', $beta['company']->id);

        $this->connection($t, 'franchise', $t['franchise']->id, SocialConnection::STATUS_REVOKED);
        $this->connection($t, 'organization', $t['org']->id, SocialConnection::STATUS_CONNECTED);

        $tree = $this->signedInAs($t['admin'])->getJson('/api/v1/companies')->assertOk()->json();

        $alpha = collect($tree['companies'])->firstWhere('name', 'Alpha Co');
        $ever  = collect($tree['companies'])->firstWhere('name', 'Everdoors');

        $this->assertSame(['people' => 1, 'numbers' => 2, 'connections' => 0], $alpha['counts']);
        $this->assertSame(['people' => 0, 'numbers' => 0, 'connections' => 0], $ever['counts']);
        $this->assertSame(['people' => 2, 'numbers' => 0, 'connections' => 1], $alpha['franchises'][0]['counts']);
        $this->assertSame(['people' => 1, 'numbers' => 1, 'connections' => 0], $ever['franchises'][0]['counts']);

        // Admin, Tech A, B, C and Manager: active, wherever they stand. Beta's
        // number is not Alpha's.
        $this->assertSame(['people' => 5, 'numbers' => 4, 'connections' => 2], $tree['organization']['counts']);
    }
}
