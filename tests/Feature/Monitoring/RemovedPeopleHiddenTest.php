<?php

namespace Tests\Feature\Monitoring;

use App\Models\MonitoredDevice;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/**
 * Jose, 2026-10-27: removed or erased people ("Removed account") must not
 * appear in any Monitoring list. Both are is_active = false; an active person
 * beside them is the control that the list still lists.
 */
class RemovedPeopleHiddenTest extends TestCase
{
    use BuildsTenants, RefreshDatabase;

    public function test_removed_people_are_left_out_of_the_roster_and_the_picker(): void
    {
        $alpha = $this->provisionTenant('Alpha');
        $active = $this->userWithRole($alpha, 'Still Here', []);
        $removed = $this->userWithRole($alpha, 'Gone', []);
        $this->inTenant($alpha['org'], function () use ($alpha, $active, $removed) {
            User::whereKey($removed->getKey())->update(['is_active' => false, 'name' => 'Removed account']);
            foreach ([$active, $removed] as $u) {
                MonitoredDevice::factory()->create(['organization_id' => $alpha['org']->id, 'user_id' => $u->getKey()]);
            }
        });
        $viewer = $this->userWithRole($alpha, 'Viewer', ['monitoring.view', 'monitoring.map.manage']);

        $roster = array_column($this->signedInAs($viewer)->getJson('/api/v1/monitoring/devices')
            ->assertOk()->json('data'), 'user');
        $this->assertContains($active->getKey(), array_column($roster, 'id'));
        $this->assertNotContains($removed->getKey(), array_column($roster, 'id'));

        $picker = array_column($this->signedInAs($viewer)->getJson('/api/v1/monitoring/assignable-people')
            ->assertOk()->json('data'), 'id');
        $this->assertContains($active->getKey(), $picker);
        $this->assertNotContains($removed->getKey(), $picker);
    }

    public function test_the_people_chart_leaves_out_removed_people_and_lists_online_first(): void
    {
        $alpha = $this->provisionTenant('Alpha');
        $offline = $this->userWithRole($alpha, 'Aaron Offline', []);
        $online = $this->userWithRole($alpha, 'Zed Online', []);
        $removed = $this->userWithRole($alpha, 'Gone', []);
        $this->inTenant($alpha['org'], function () use ($alpha, $online, $removed) {
            User::whereKey($removed->getKey())->update(['is_active' => false, 'name' => 'Removed account']);
            MonitoredDevice::factory()->create(['organization_id' => $alpha['org']->id, 'user_id' => $online->getKey(), 'last_seen_at' => now()]);
        });
        $viewer = $this->userWithRole($alpha, 'Viewer', ['monitoring.view', 'monitoring.general.view']);

        $ids = array_column(array_column($this->signedInAs($viewer)->getJson('/api/v1/monitoring/ownership')
            ->assertOk()->json('data'), 'user'), 'id');

        $this->assertNotContains($removed->getKey(), $ids);
        $this->assertLessThan(array_search($offline->getKey(), $ids), array_search($online->getKey(), $ids),
            'Zed is online, so he comes before Aaron despite the alphabet');
    }
}
