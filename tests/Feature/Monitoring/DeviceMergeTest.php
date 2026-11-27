<?php

namespace Tests\Feature\Monitoring;

use App\Models\MonitoredDevice;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/** Jose, 2026-11-02: one roster row per person per machine. */
class DeviceMergeTest extends TestCase
{
    use BuildsTenants, RefreshDatabase;

    public function test_the_roster_keeps_one_row_per_person_per_machine(): void
    {
        $alpha = $this->provisionTenant('Alpha');
        $u = $alpha['admin'];
        $make = fn (string $key, string $platform, ?string $pc, int $daysAgo) => MonitoredDevice::factory()->create([
            'organization_id' => $alpha['org']->id, 'user_id' => $u->getKey(), 'device_key' => $key,
            'platform' => $platform, 'specs' => $pc ? ['computer_name' => $pc] : null,
            'last_seen_at' => now()->subDays($daysAgo),
        ]);
        $ids = $this->inTenant($alpha['org'], fn () => [
            'desk2_new' => $make('a', 'windows', 'DESK-2', 0)->id,
            'desk2_reinstall' => $make('b', 'windows', 'desk-2', 1)->id,  // same machine
            'desk4_recent' => $make('c', 'windows', 'DESK-4', 2)->id,     // second PC, in use
            'desk9_stale' => $make('d', 'windows', 'DESK-9', 20)->id,     // moved off it
            'web_new' => $make('e', 'web', null, 0)->id,
            'web_old' => $make('f', 'web', null, 3)->id,                  // another browser
        ]);
        $viewer = $this->userWithRole($alpha, 'Viewer', ['monitoring.view']);

        $listed = array_column($this->signedInAs($viewer)->getJson('/api/v1/monitoring/devices')
            ->assertOk()->json('data'), 'id');

        sort($listed);
        $expected = [$ids['desk2_new'], $ids['desk4_recent'], $ids['web_new']];
        sort($expected);
        $this->assertSame($expected, $listed);
    }
}
