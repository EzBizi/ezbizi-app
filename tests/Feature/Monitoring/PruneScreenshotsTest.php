<?php

namespace Tests\Feature\Monitoring;

use App\Models\MonitoredDevice;
use App\Models\MonitoringScreenshot;
use App\Support\Monitoring\ScreenshotPruner;
use App\Support\Monitoring\ScreenshotStore;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/**
 * The load-bearing guarantee MonitoringScreenshot's own `deleting` hook used
 * to carry now lives here: a screenshot past
 * MonitoringScreenshot::RETENTION_DAYS loses both its row and its JPEG, and
 * one still inside the window loses neither.
 */
class PruneScreenshotsTest extends TestCase
{
    use BuildsTenants, RefreshDatabase;

    public function test_prune_removes_the_row_and_the_file_for_expired_screenshots_and_leaves_recent_ones(): void
    {
        $alpha = $this->provisionTenant('Alpha');

        [$old, $recent, $oldAbs, $recentAbs] = $this->inTenant($alpha['org'], function () use ($alpha) {
            $device = MonitoredDevice::factory()->create([
                'organization_id' => $alpha['org']->id,
                'user_id'         => $alpha['admin']->getKey(),
            ]);
            $store = app(ScreenshotStore::class);

            $oldPath = $store->put($alpha['org']->id, $device->id, '2020-01-01T00-00-00', 'OLD-JPEG-BYTES');
            $old = MonitoringScreenshot::create([
                'organization_id' => $alpha['org']->id, 'device_id' => $device->id,
                'user_id' => $alpha['admin']->getKey(), 'captured_at' => now()->subDays(31),
                'path' => $oldPath, 'bytes' => 14, 'width' => 1, 'height' => 1,
            ]);

            $recentPath = $store->put($alpha['org']->id, $device->id, '2020-01-02T00-00-00', 'RECENT-JPEG-BYTES');
            $recent = MonitoringScreenshot::create([
                'organization_id' => $alpha['org']->id, 'device_id' => $device->id,
                'user_id' => $alpha['admin']->getKey(), 'captured_at' => now()->subDays(5),
                'path' => $recentPath, 'bytes' => 18, 'width' => 1, 'height' => 1,
            ]);

            return [$old, $recent, $store->absolute($oldPath), $store->absolute($recentPath)];
        });

        $this->assertFileExists($oldAbs);
        $this->assertFileExists($recentAbs);

        $pruned = app(ScreenshotPruner::class)->prune();

        $this->assertSame(1, $pruned);

        $this->assertFileDoesNotExist($oldAbs);
        $this->assertFileExists($recentAbs);

        $this->inTenant($alpha['org'], function () use ($old, $recent) {
            $this->assertNull(MonitoringScreenshot::find($old->id));
            $this->assertNotNull(MonitoringScreenshot::find($recent->id));
        });
    }
}
