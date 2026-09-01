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

    public function test_retention_is_one_month_from_captured_at(): void
    {
        $p = MonitoringScreenshot::retention();
        $this->assertSame(RetentionPolicy::WINDOW, $p->kind);
        $this->assertSame(1, $p->months);
        $this->assertSame('captured_at', $p->column);
    }

    public function test_deleting_the_row_deletes_the_file(): void
    {
        $alpha = $this->provisionTenant('Alpha');

        [$dev, $shot] = $this->inTenant($alpha['org'], function () use ($alpha) {
            $d = MonitoredDevice::factory()->create(['organization_id' => $alpha['org']->id, 'user_id' => $alpha['admin']->getKey()]);
            $store = app(ScreenshotStore::class);
            $path = $store->put($alpha['org']->id, $d->id, now()->toIso8601String(), 'JPEGBYTES');
            $s = MonitoringScreenshot::create(['organization_id' => $alpha['org']->id, 'device_id' => $d->id, 'user_id' => $alpha['admin']->getKey(), 'captured_at' => now(), 'path' => $path, 'bytes' => 9, 'width' => 1, 'height' => 1]);

            return [$d, $s];
        });

        $abs = app(ScreenshotStore::class)->absolute($shot->path);
        $this->assertFileExists($abs);

        // The brief's illustrative snippet calls $shot->delete() bare, but
        // BelongsToTenant's own `deleting` hook asks TenantContext::idOrFail()
        // before this model's own hook ever runs — so the delete itself has to
        // be inside a tenant, same as the create above.
        $this->inTenant($alpha['org'], fn () => $shot->delete());

        $this->assertFileDoesNotExist($abs);
    }
}
