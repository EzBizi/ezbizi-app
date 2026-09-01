<?php

namespace Tests\Feature\Monitoring;

use App\Models\MonitoredDevice;
use App\Models\MonitoringScreenshot;
use App\Support\Monitoring\ScreenshotStore;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/**
 * `POST /api/v1/monitoring/screenshots` — a device uploading its own capture
 * (2026-08-31-monitoring-screenshots, Task 3).
 */
class ScreenshotIngestTest extends TestCase
{
    use BuildsTenants, RefreshDatabase;

    private function upload($user, array $overrides = [])
    {
        $data = array_merge([
            'image' => UploadedFile::fake()->image('s.jpg', 100, 80),
            'device_key' => 'dk-1',
        ], $overrides);

        return $this->signedInAs($user)->post('/api/v1/monitoring/screenshots', $data);
    }

    public function test_a_posted_jpeg_writes_one_row_and_one_file_for_the_callers_device(): void
    {
        $alpha = $this->provisionTenant('Alpha');

        $this->upload($alpha['admin'])->assertNoContent();

        $this->assertSame(1, MonitoringScreenshot::withoutGlobalScopes()->count());
        $this->assertSame(1, MonitoredDevice::withoutGlobalScopes()
            ->where('device_key', 'dk-1')->count());

        $shot = MonitoringScreenshot::withoutGlobalScopes()->firstOrFail();
        $this->assertFileExists(app(ScreenshotStore::class)->absolute($shot->path));
        $this->assertGreaterThan(0, $shot->bytes);
        $this->assertSame(100, $shot->width);
        $this->assertSame(80, $shot->height);
    }

    public function test_org_and_user_on_the_row_come_from_the_token_not_the_body(): void
    {
        $alpha = $this->provisionTenant('Alpha');
        $bogusOrgId = (string) Str::uuid();

        $this->upload($alpha['admin'], [
            'organization_id' => $bogusOrgId,
            'user_id' => (string) Str::uuid(),
        ])->assertNoContent();

        $shot = MonitoringScreenshot::withoutGlobalScopes()->firstOrFail();
        $this->assertSame($alpha['org']->id, $shot->organization_id);
        $this->assertSame($alpha['admin']->id, $shot->user_id);
        $this->assertNotSame($bogusOrgId, $shot->organization_id);
    }

    public function test_a_non_image_upload_is_rejected(): void
    {
        $alpha = $this->provisionTenant('Alpha');

        $this->upload($alpha['admin'], [
            'image' => UploadedFile::fake()->create('note.txt', 10),
        ])->assertStatus(422);

        $this->assertSame(0, MonitoringScreenshot::withoutGlobalScopes()->count());
    }

    public function test_an_oversize_image_is_rejected(): void
    {
        $alpha = $this->provisionTenant('Alpha');

        $this->upload($alpha['admin'], [
            'image' => UploadedFile::fake()->image('big.jpg', 100, 80)->size(3000),
        ])->assertStatus(422);

        $this->assertSame(0, MonitoringScreenshot::withoutGlobalScopes()->count());
    }

    public function test_a_second_upload_for_the_same_device_writes_a_second_row_against_the_same_device(): void
    {
        $alpha = $this->provisionTenant('Alpha');

        $this->upload($alpha['admin'])->assertNoContent();
        $this->upload($alpha['admin'])->assertNoContent();

        $this->assertSame(2, MonitoringScreenshot::withoutGlobalScopes()->count());
        $this->assertSame(1, MonitoredDevice::withoutGlobalScopes()
            ->where('device_key', 'dk-1')->count());
    }
}
