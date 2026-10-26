<?php

namespace Tests\Feature\Monitoring;

use App\Models\MonitoredDevice;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/**
 * RGB lighting (Jose, 2026-10-26: "change the color of all the computers
 * connected to ezbizi either in bulk or individually"). A manager sets the
 * wanted lighting; each PC's agent polls for its own and applies it through
 * the bundled OpenRGB.
 */
class LightingTest extends TestCase
{
    use BuildsTenants, RefreshDatabase;

    private function beat($user, string $key): void
    {
        $this->signedInAs($user)->postJson('/api/v1/monitoring/heartbeat', [
            'device_key' => $key, 'platform' => 'windows', 'capabilities' => ['specs'],
        ])->assertNoContent();
    }

    private function device(array $tenant, $user, string $key): MonitoredDevice
    {
        return $this->inTenant($tenant['org'], fn () => MonitoredDevice::query()
            ->where('device_key', $key)->firstOrFail());
    }

    public function test_a_manager_sets_one_pcs_lighting_and_that_pc_reads_it(): void
    {
        $alpha = $this->provisionTenant('Alpha');
        $this->beat($alpha['admin'], 'pc-1');
        $pc = $this->device($alpha, $alpha['admin'], 'pc-1');

        $this->signedInAs($alpha['admin'])
            ->putJson("/api/v1/monitoring/devices/{$pc->id}/lighting", ['mode' => 'static', 'color' => '#FF0080', 'brightness' => 70])
            ->assertOk()->assertJsonPath('data.color', '#FF0080');

        $wanted = $this->signedInAs($alpha['admin'])
            ->getJson('/api/v1/monitoring/lighting?device_key=pc-1')->assertOk();
        $this->assertSame('static', $wanted->json('lighting.mode'));
        $this->assertSame('#FF0080', $wanted->json('lighting.color'));
        $this->assertSame(70, $wanted->json('lighting.brightness'));
        $this->assertIsInt($wanted->json('lighting.version'));
    }

    public function test_bulk_sets_every_pc_in_view_or_just_the_ones_named(): void
    {
        $alpha = $this->provisionTenant('Alpha');
        $this->beat($alpha['admin'], 'pc-1');
        $this->beat($alpha['admin'], 'pc-2');
        $one = $this->device($alpha, $alpha['admin'], 'pc-1');

        $this->signedInAs($alpha['admin'])
            ->postJson('/api/v1/monitoring/lighting', ['mode' => 'static', 'color' => '#00FF00'])
            ->assertOk()->assertJsonPath('data.updated', 2);

        $this->signedInAs($alpha['admin'])
            ->postJson('/api/v1/monitoring/lighting', ['device_ids' => [$one->id], 'mode' => 'off'])
            ->assertOk()->assertJsonPath('data.updated', 1);

        $this->assertSame('off', $this->device($alpha, null, 'pc-1')->lighting['mode']);
        $this->assertSame('#00FF00', $this->device($alpha, null, 'pc-2')->lighting['color']);
    }

    public function test_lighting_needs_its_own_permission_and_stays_inside_the_organization(): void
    {
        $alpha = $this->provisionTenant('Alpha');
        $beta = $this->provisionTenant('Beta');
        $this->beat($beta['admin'], 'beta-pc');
        $theirs = $this->device($beta, null, 'beta-pc');

        $viewer = $this->userWithRole($alpha, 'Watch', ['monitoring.view']);
        $this->signedInAs($viewer)
            ->postJson('/api/v1/monitoring/lighting', ['mode' => 'off'])->assertForbidden();

        $this->signedInAs($alpha['admin'])
            ->putJson("/api/v1/monitoring/devices/{$theirs->id}/lighting", ['mode' => 'off'])->assertNotFound();
        $this->signedInAs($alpha['admin'])
            ->postJson('/api/v1/monitoring/lighting', ['device_ids' => [$theirs->id], 'mode' => 'off'])
            ->assertOk()->assertJsonPath('data.updated', 0);
    }

    public function test_bad_values_are_refused(): void
    {
        $alpha = $this->provisionTenant('Alpha');
        $this->signedInAs($alpha['admin'])
            ->postJson('/api/v1/monitoring/lighting', ['mode' => 'static', 'color' => 'red'])->assertStatus(422);
        $this->signedInAs($alpha['admin'])
            ->postJson('/api/v1/monitoring/lighting', ['mode' => 'disco'])->assertStatus(422);
    }

    public function test_a_pc_with_nothing_set_reads_null(): void
    {
        $alpha = $this->provisionTenant('Alpha');
        $this->beat($alpha['admin'], 'pc-1');
        $this->signedInAs($alpha['admin'])->getJson('/api/v1/monitoring/lighting?device_key=pc-1')
            ->assertOk()->assertJsonPath('lighting', null);
    }
    public function test_the_roster_shows_each_pcs_lighting_only_to_who_can_set_it(): void
    {
        $alpha = $this->provisionTenant('Alpha');
        $this->beat($alpha['admin'], 'pc-1');
        $this->signedInAs($alpha['admin'])->postJson('/api/v1/monitoring/lighting', ['mode' => 'static', 'color' => '#112233']);

        $this->signedInAs($alpha['admin'])->getJson('/api/v1/monitoring/devices')
            ->assertOk()->assertJsonPath('data.0.lighting.color', '#112233');

        $viewer = $this->userWithRole($alpha, 'Watch', ['monitoring.view']);
        $row = $this->signedInAs($viewer)->getJson('/api/v1/monitoring/devices')->assertOk()->json('data.0');
        $this->assertArrayNotHasKey('lighting', $row);
    }
}
