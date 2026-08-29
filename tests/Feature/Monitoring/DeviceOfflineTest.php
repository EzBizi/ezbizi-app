<?php

namespace Tests\Feature\Monitoring;

use App\Models\AutomationEvent;
use App\Models\MonitoredDevice;
use App\Support\Monitoring\DeviceOfflineWorker;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

class DeviceOfflineTest extends TestCase
{
    use BuildsTenants, RefreshDatabase;

    public function test_a_stale_device_raises_device_offline_exactly_once(): void
    {
        $alpha = $this->provisionTenant('Alpha');
        $this->inTenant($alpha['org'], fn () => MonitoredDevice::factory()->create([
            'organization_id' => $alpha['org']->id, 'user_id' => $alpha['admin']->getKey(),
            'last_seen_at' => now()->subMinutes(10), 'offline_alerted_at' => null,
        ]));

        app(DeviceOfflineWorker::class)->sweep();
        app(DeviceOfflineWorker::class)->sweep(); // second run must not re-fire

        $count = $this->inTenant($alpha['org'], fn () => AutomationEvent::query()
            ->where('event_key', 'monitoring.device_offline')->count());
        $this->assertSame(1, $count);
    }

    public function test_a_fresh_beat_rearms_the_alert(): void
    {
        $alpha = $this->provisionTenant('Alpha');
        $device = $this->inTenant($alpha['org'], fn () => MonitoredDevice::factory()->create([
            'organization_id' => $alpha['org']->id, 'user_id' => $alpha['admin']->getKey(),
            'last_seen_at' => now()->subMinutes(10), 'offline_alerted_at' => null,
        ]));
        app(DeviceOfflineWorker::class)->sweep();

        $this->inTenant($alpha['org'], function () use ($device): void {
            // Reload first: the worker's own sweep() set offline_alerted_at on
            // a SEPARATE instance of this row, so $device's in-memory copy is
            // still the pre-sweep null it was created with. Without a refresh,
            // forceFill(['offline_alerted_at' => null]) looks like no change
            // at all against that stale original and Eloquent drops it from
            // the UPDATE — leaving the real row re-armed on last_seen_at but
            // never on offline_alerted_at, which is exactly the bug this test
            // exists to catch, just relocated into the fixture instead of the
            // worker.
            $device->refresh();
            $device->forceFill(['last_seen_at' => now(), 'offline_alerted_at' => null])->save();
            $device->forceFill(['last_seen_at' => now()->subMinutes(10)])->save();
        });
        app(DeviceOfflineWorker::class)->sweep();

        $count = $this->inTenant($alpha['org'], fn () => AutomationEvent::query()
            ->where('event_key', 'monitoring.device_offline')->count());
        $this->assertSame(2, $count);
    }
}
