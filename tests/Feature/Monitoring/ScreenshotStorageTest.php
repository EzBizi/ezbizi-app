<?php

namespace Tests\Feature\Monitoring;

use App\Models\MonitoredDevice;
use App\Models\MonitoringScreenshot;
use App\Support\Monitoring\ScreenshotStore;
use App\Support\Privacy\RetentionPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

class ScreenshotStorageTest extends TestCase
{
    use BuildsTenants, RefreshDatabase;

    /**
     * `window()` would be a lie here — see MonitoringScreenshot::retention()'s
     * docblock for why a raw-SQL nightly sweep cannot be trusted with a file.
     */
    public function test_retention_is_enforced_elsewhere_by_the_prune_screenshots_command(): void
    {
        $p = MonitoringScreenshot::retention();
        $this->assertSame(RetentionPolicy::ELSEWHERE, $p->kind);
        $this->assertSame('the ezbizi:prune-screenshots command', $p->enforcedBy);
        $this->assertStringContainsString('30 days', $p->sentence());
    }

    public function test_put_then_get_returns_the_same_bytes(): void
    {
        $store = app(ScreenshotStore::class);

        $path = $store->put('org-1', 'device-1', now()->toIso8601String(), 'JPEGBYTES');

        $this->assertSame('JPEGBYTES', $store->get($path));

        $store->delete($path);
    }

    /**
     * The load-bearing "the file goes with the row" guarantee moved to
     * PruneScreenshotsTest (retention expiry) and to Anonymiser's after-commit
     * `personalDataFiles()` path (erasure) — see MonitoringScreenshot's class
     * docblock for why a `deleting` hook that deleted the file synchronously
     * was wrong on both of those paths. This test locks in the other half: a
     * plain, direct `->delete()` — the one a future caller might reach for
     * without going through either of the two sanctioned paths — must NOT be
     * relied on to remove bytes, so it had better not look like it does.
     */
    public function test_deleting_the_row_directly_does_not_delete_the_file(): void
    {
        $alpha = $this->provisionTenant('Alpha');

        [$shot, $abs] = $this->inTenant($alpha['org'], function () use ($alpha) {
            $device = MonitoredDevice::factory()->create([
                'organization_id' => $alpha['org']->id,
                'user_id'         => $alpha['admin']->getKey(),
            ]);
            $store = app(ScreenshotStore::class);
            $path = $store->put($alpha['org']->id, $device->id, now()->toIso8601String(), 'JPEGBYTES');
            $shot = MonitoringScreenshot::create([
                'organization_id' => $alpha['org']->id, 'device_id' => $device->id,
                'user_id' => $alpha['admin']->getKey(), 'captured_at' => now(),
                'path' => $path, 'bytes' => 9, 'width' => 1, 'height' => 1,
            ]);

            return [$shot, $store->absolute($path)];
        });

        $this->assertFileExists($abs);

        $this->inTenant($alpha['org'], fn () => $shot->delete());

        $this->assertNull($this->inTenant($alpha['org'], fn () => MonitoringScreenshot::find($shot->id)));
        $this->assertFileExists($abs, 'A plain ->delete() must leave the file alone — deleting it here '
            .'is exactly the synchronous hook this test exists to keep out.');

        app(ScreenshotStore::class)->delete($shot->path);
    }
}
