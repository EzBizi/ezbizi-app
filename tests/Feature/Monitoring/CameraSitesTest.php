<?php

namespace Tests\Feature\Monitoring;

use App\Models\CameraSite;
use App\Models\Company;
use App\Models\Franchise;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/**
 * Camera sites and pairing a bridge PC to one (camera bridge, phase 1).
 *
 * Pairing: a manager makes a one-time code for a site in Monitoring ›
 * Settings, then, signed in on the bridge PC, enters it there. The bridge gets
 * a site key — shown once, stored only as a hash — and from then on runs
 * without anybody signed in.
 */
class CameraSitesTest extends TestCase
{
    use BuildsTenants, RefreshDatabase;

    private function beat($user, string $deviceKey = 'bridge-pc'): void
    {
        $this->signedInAs($user)->postJson('/api/v1/monitoring/heartbeat', [
            'device_key' => $deviceKey, 'platform' => 'windows', 'capabilities' => ['activity'],
        ])->assertNoContent();
    }

    private function createSite($user, array $body = ['name' => 'Hialeah warehouse'])
    {
        return $this->signedInAs($user)->postJson('/api/v1/monitoring/camera-sites', $body);
    }

    private function codeFor($user, string $siteId): string
    {
        return $this->signedInAs($user)
            ->postJson("/api/v1/monitoring/camera-sites/{$siteId}/pairing-code")
            ->assertOk()
            ->json('data.code');
    }

    private function pair($user, string $code, string $deviceKey = 'bridge-pc')
    {
        return $this->signedInAs($user)->postJson('/api/v1/monitoring/bridge/pair', [
            'code' => $code, 'device_key' => $deviceKey,
        ]);
    }

    public function test_a_manager_creates_a_site_and_a_viewer_sees_it_listed(): void
    {
        $alpha = $this->provisionTenant('Alpha');
        $manager = $this->userWithRole($alpha, 'Cams', ['monitoring.cameras.view', 'monitoring.cameras.manage']);
        $viewer = $this->userWithRole($alpha, 'Watch', ['monitoring.cameras.view']);

        $id = $this->createSite($manager, ['name' => 'Hialeah warehouse', 'franchise_id' => $alpha['franchise']->id])
            ->assertCreated()->json('data.id');

        $this->signedInAs($viewer)->getJson('/api/v1/monitoring/camera-sites')
            ->assertOk()
            ->assertJsonPath('data.0.id', $id)
            ->assertJsonPath('data.0.online', false)
            ->assertJsonPath('data.0.camera_count', 0);
    }

    public function test_a_view_only_person_cannot_create_or_make_a_pairing_code(): void
    {
        $alpha = $this->provisionTenant('Alpha');
        $viewer = $this->userWithRole($alpha, 'Watch', ['monitoring.cameras.view']);
        $this->createSite($viewer)->assertForbidden();

        $site = $this->createSite($alpha['admin'])->json('data.id');
        $this->signedInAs($viewer)
            ->postJson("/api/v1/monitoring/camera-sites/{$site}/pairing-code")
            ->assertForbidden();
    }

    public function test_a_site_in_another_company_is_not_listed_in_a_branch_view(): void
    {
        $alpha = $this->provisionTenant('Alpha');
        $other = $this->inTenant($alpha['org'], function () use ($alpha) {
            $company = Company::create(['organization_id' => $alpha['org']->id, 'name' => 'Other Co']);

            return Franchise::create(['organization_id' => $alpha['org']->id, 'company_id' => $company->id, 'name' => 'Other F1']);
        });
        $this->createSite($alpha['admin'], ['name' => 'Boston', 'franchise_id' => $other->id])->assertCreated();

        $branch = $this->userWithRole($alpha, 'Branch', ['monitoring.cameras.view'], onlyAt: $alpha['franchise']);
        $this->signedInAs($branch)->getJson('/api/v1/monitoring/camera-sites')
            ->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_pairing_gives_the_bridge_a_key_that_is_stored_only_as_a_hash(): void
    {
        $alpha = $this->provisionTenant('Alpha');
        $this->beat($alpha['admin']);
        $site = $this->createSite($alpha['admin'])->json('data.id');

        $key = $this->pair($alpha['admin'], $this->codeFor($alpha['admin'], $site))
            ->assertOk()
            ->assertJsonPath('data.site_id', $site)
            ->assertJsonPath('data.site_name', 'Hialeah warehouse')
            ->json('data.site_key');

        $this->assertSame(40, strlen($key));
        $row = $this->inTenant($alpha['org'], fn () => CameraSite::find($site));
        $this->assertSame(hash('sha256', $key), $row->site_key_hash);
        $this->assertNotNull($row->device_id, 'The bridge PC is linked to the site.');
        $this->assertNotNull($row->paired_at);
    }

    public function test_a_pairing_code_works_once_and_expires_in_15_minutes(): void
    {
        $alpha = $this->provisionTenant('Alpha');
        $this->beat($alpha['admin']);
        $site = $this->createSite($alpha['admin'])->json('data.id');

        $code = $this->codeFor($alpha['admin'], $site);
        $this->pair($alpha['admin'], $code)->assertOk();
        $this->pair($alpha['admin'], $code)->assertStatus(422);

        $late = $this->codeFor($alpha['admin'], $site);
        $this->travel(16)->minutes();
        $this->pair($alpha['admin'], $late)->assertStatus(422);
    }

    public function test_guessing_pairing_codes_is_rate_limited(): void
    {
        $alpha = $this->provisionTenant('Alpha');
        $this->beat($alpha['admin']);

        for ($i = 0; $i < 5; $i++) {
            $this->pair($alpha['admin'], 'WRNG-000'.$i)->assertStatus(422);
        }
        $this->pair($alpha['admin'], 'WRNG-0009')->assertStatus(429);
    }

    public function test_pairing_again_replaces_the_previous_key(): void
    {
        $alpha = $this->provisionTenant('Alpha');
        $this->beat($alpha['admin']);
        $site = $this->createSite($alpha['admin'])->json('data.id');

        $first = $this->pair($alpha['admin'], $this->codeFor($alpha['admin'], $site))->json('data.site_key');
        $second = $this->pair($alpha['admin'], $this->codeFor($alpha['admin'], $site))->json('data.site_key');

        $this->assertNotSame($first, $second);
        $row = $this->inTenant($alpha['org'], fn () => CameraSite::find($site));
        $this->assertSame(hash('sha256', $second), $row->site_key_hash, 'Only the newest key works.');
    }

    public function test_disconnecting_a_site_forgets_its_key_and_pc(): void
    {
        $alpha = $this->provisionTenant('Alpha');
        $this->beat($alpha['admin']);
        $site = $this->createSite($alpha['admin'])->json('data.id');
        $this->pair($alpha['admin'], $this->codeFor($alpha['admin'], $site));

        $this->signedInAs($alpha['admin'])
            ->postJson("/api/v1/monitoring/camera-sites/{$site}/revoke")
            ->assertNoContent();

        $row = $this->inTenant($alpha['org'], fn () => CameraSite::find($site));
        $this->assertNull($row->site_key_hash);
        $this->assertNull($row->device_id);
    }
}
