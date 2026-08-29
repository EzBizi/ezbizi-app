<?php

namespace Tests\Feature\Monitoring;

use App\Models\AutomationEvent;
use App\Models\MonitoredDevice;
use App\Support\Monitoring\DeviceOfflineWorker;
use App\Support\Settings\SettingsResolver;
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

    /**
     * Proves the worker actually reads `monitoring.offline_after_seconds`
     * rather than a hardcoded 180 — the other two tests use a 10-minute-stale
     * device against the 180s default and would pass identically either way.
     *
     * A 10-minute-stale device is well past the 180s default but well inside
     * a 1200s (20 minute) override, so raising the setting must suppress the
     * sweep entirely; lowering it back down to 1s on the same still-stale
     * device must then let it fire. If the worker ignored the setting and
     * kept the 180s literal, the FIRST assertion below would already see 1
     * event instead of 0 — the test fails before the override is even
     * lowered.
     */
    public function test_the_offline_threshold_is_read_from_the_setting_not_hardcoded(): void
    {
        $alpha = $this->provisionTenant('Alpha');
        $this->inTenant($alpha['org'], function () use ($alpha): void {
            app(SettingsResolver::class)->set('monitoring.offline_after_seconds', $alpha['org'], 1200);
            MonitoredDevice::factory()->create([
                'organization_id' => $alpha['org']->id, 'user_id' => $alpha['admin']->getKey(),
                'last_seen_at' => now()->subMinutes(10), 'offline_alerted_at' => null,
            ]);
        });

        app(DeviceOfflineWorker::class)->sweep();
        $afterRaised = $this->inTenant($alpha['org'], fn () => AutomationEvent::query()
            ->where('event_key', 'monitoring.device_offline')->count());
        $this->assertSame(0, $afterRaised,
            'A device stale for 10 minutes must not be swept when the org threshold is 1200 seconds.');

        $this->inTenant($alpha['org'], fn () => app(SettingsResolver::class)
            ->set('monitoring.offline_after_seconds', $alpha['org'], 1));
        app(DeviceOfflineWorker::class)->sweep();
        $afterLowered = $this->inTenant($alpha['org'], fn () => AutomationEvent::query()
            ->where('event_key', 'monitoring.device_offline')->count());
        $this->assertSame(1, $afterLowered,
            'The same still-stale device must be swept once the threshold drops to 1 second.');
    }
}
