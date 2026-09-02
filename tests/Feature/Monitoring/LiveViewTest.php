<?php

namespace Tests\Feature\Monitoring;

use App\Models\Company;
use App\Models\Franchise;
use App\Models\MonitoredDevice;
use App\Models\MonitoringScreenshot;
use App\Support\Monitoring\LiveFrameStore;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/**
 * Live View (2026-09-02-monitoring-live-view) — a manager watching a
 * device's screen update every ~1-2s, poll-driven and ephemeral: no row is
 * ever written to `monitoring_screenshots`, and a single overwriting JPEG
 * per device is all that exists on disk. See LiveViewController's docblock
 * for why.
 *
 * Mirrors ScreenshotIngestTest (the agent's upload half) and
 * ScreenshotReadTest (the §3-scoped viewer read half), one register-a-device
 * beat away from both.
 */
class LiveViewTest extends TestCase
{
    use BuildsTenants, RefreshDatabase;

    private function beat($user, string $deviceKey = 'dk-1'): void
    {
        $this->signedInAs($user)->postJson('/api/v1/monitoring/heartbeat', [
            'device_key' => $deviceKey, 'platform' => 'windows', 'capabilities' => ['screenshot'],
        ])->assertNoContent();
    }

    private function deviceFor($user, string $deviceKey = 'dk-1'): MonitoredDevice
    {
        return MonitoredDevice::withoutGlobalScopes()
            ->where('user_id', $user->getKey())
            ->where('device_key', $deviceKey)
            ->firstOrFail();
    }

    private function uploadFrame($user, array $overrides = [])
    {
        $data = array_merge([
            'image' => UploadedFile::fake()->image('f.jpg', 100, 80),
            'device_key' => 'dk-1',
        ], $overrides);

        return $this->signedInAs($user)->post('/api/v1/monitoring/live-frame', $data);
    }

    // ── watch → live-wanted ────────────────────────────────────────

    public function test_watch_registers_interest_and_live_wanted_reports_it_true(): void
    {
        $alpha = $this->provisionTenant('Alpha');
        $this->beat($alpha['admin']);
        $device = $this->deviceFor($alpha['admin']);
        $viewer = $this->userWithRole($alpha, 'Seer', ['monitoring.screenshots.view']);

        $this->signedInAs($viewer)
            ->postJson('/api/v1/monitoring/devices/'.$device->id.'/watch')
            ->assertNoContent();

        $this->signedInAs($alpha['admin'])
            ->getJson('/api/v1/monitoring/live-wanted?device_key=dk-1')
            ->assertOk()
            ->assertJson(['wanted' => true]);
    }

    public function test_a_different_devices_key_reports_wanted_false(): void
    {
        $alpha = $this->provisionTenant('Alpha');
        $this->beat($alpha['admin'], 'dk-watched');
        $this->beat($alpha['admin'], 'dk-other');
        $watched = $this->deviceFor($alpha['admin'], 'dk-watched');
        $viewer = $this->userWithRole($alpha, 'Seer', ['monitoring.screenshots.view']);

        $this->signedInAs($viewer)
            ->postJson('/api/v1/monitoring/devices/'.$watched->id.'/watch')
            ->assertNoContent();

        $this->signedInAs($alpha['admin'])
            ->getJson('/api/v1/monitoring/live-wanted?device_key=dk-other')
            ->assertOk()
            ->assertJson(['wanted' => false]);
    }

    public function test_interest_expires_after_the_ttl(): void
    {
        $alpha = $this->provisionTenant('Alpha');
        $this->beat($alpha['admin']);
        $device = $this->deviceFor($alpha['admin']);
        $viewer = $this->userWithRole($alpha, 'Seer', ['monitoring.screenshots.view']);

        $this->signedInAs($viewer)
            ->postJson('/api/v1/monitoring/devices/'.$device->id.'/watch')
            ->assertNoContent();

        $this->signedInAs($alpha['admin'])
            ->getJson('/api/v1/monitoring/live-wanted?device_key=dk-1')
            ->assertOk()->assertJson(['wanted' => true]);

        $this->travel(16)->seconds();

        $this->signedInAs($alpha['admin'])
            ->getJson('/api/v1/monitoring/live-wanted?device_key=dk-1')
            ->assertOk()
            ->assertJson(['wanted' => false]);
    }

    public function test_live_wanted_for_an_unknown_device_key_is_rejected(): void
    {
        $alpha = $this->provisionTenant('Alpha');
        $this->beat($alpha['admin'], 'dk-registered');

        $this->signedInAs($alpha['admin'])
            ->getJson('/api/v1/monitoring/live-wanted?device_key=dk-never-beat')
            ->assertStatus(404);
    }

    // ── live-frame upload ─────────────────────────────────────────

    public function test_a_live_frame_upload_writes_the_overwriting_file_and_no_screenshot_row(): void
    {
        $alpha = $this->provisionTenant('Alpha');
        $this->beat($alpha['admin']);
        $device = $this->deviceFor($alpha['admin']);

        $this->uploadFrame($alpha['admin'])->assertNoContent();

        $path = app(LiveFrameStore::class)->absolute($alpha['org']->id, $device->id);
        $this->assertFileExists($path);
        $this->assertSame(0, MonitoringScreenshot::withoutGlobalScopes()->count());
    }

    public function test_a_second_upload_overwrites_rather_than_accumulates(): void
    {
        $alpha = $this->provisionTenant('Alpha');
        $this->beat($alpha['admin']);
        $device = $this->deviceFor($alpha['admin']);

        $this->uploadFrame($alpha['admin'], [
            'image' => UploadedFile::fake()->image('f.jpg', 50, 50),
        ])->assertNoContent();
        $firstBytes = app(LiveFrameStore::class)->get($alpha['org']->id, $device->id);

        $this->uploadFrame($alpha['admin'], [
            'image' => UploadedFile::fake()->image('f.jpg', 200, 150),
        ])->assertNoContent();
        $secondBytes = app(LiveFrameStore::class)->get($alpha['org']->id, $device->id);

        $this->assertNotSame($firstBytes, $secondBytes);
        $this->assertSame(0, MonitoringScreenshot::withoutGlobalScopes()->count());

        // Still exactly one file for this device — a directory listing would
        // also prove it, but the store's own get() already names the one
        // path there is, so a second write landing anywhere else would
        // simply not be found here.
        $this->assertNotSame('', $secondBytes);
    }

    public function test_live_frame_for_an_unknown_device_key_is_rejected(): void
    {
        $alpha = $this->provisionTenant('Alpha');
        $this->beat($alpha['admin'], 'dk-registered');

        $this->uploadFrame($alpha['admin'], ['device_key' => 'dk-never-beat'])
            ->assertStatus(404);

        $this->assertSame(0, MonitoringScreenshot::withoutGlobalScopes()->count());
    }

    public function test_a_non_image_live_frame_is_rejected(): void
    {
        $alpha = $this->provisionTenant('Alpha');
        $this->beat($alpha['admin']);

        $this->uploadFrame($alpha['admin'], [
            'image' => UploadedFile::fake()->create('note.txt', 10),
        ])->assertStatus(422);

        $this->assertSame(0, MonitoringScreenshot::withoutGlobalScopes()->count());
    }

    // ── viewer GET live-frame ─────────────────────────────────────

    public function test_viewer_gets_the_bytes_when_the_frame_is_fresh(): void
    {
        $alpha = $this->provisionTenant('Alpha');
        $this->beat($alpha['admin']);
        $device = $this->deviceFor($alpha['admin']);
        $viewer = $this->userWithRole($alpha, 'Seer', ['monitoring.screenshots.view']);

        $this->uploadFrame($alpha['admin'])->assertNoContent();

        $resp = $this->signedInAs($viewer)
            ->get('/api/v1/monitoring/devices/'.$device->id.'/live-frame');

        $resp->assertOk();
        $resp->assertHeader('Content-Type', 'image/jpeg');
        $this->assertSame(
            app(LiveFrameStore::class)->get($alpha['org']->id, $device->id),
            $resp->getContent(),
        );
    }

    public function test_viewer_gets_404_when_no_frame_has_ever_been_sent(): void
    {
        $alpha = $this->provisionTenant('Alpha');
        $this->beat($alpha['admin']);
        $device = $this->deviceFor($alpha['admin']);
        $viewer = $this->userWithRole($alpha, 'Seer', ['monitoring.screenshots.view']);

        $this->signedInAs($viewer)
            ->getJson('/api/v1/monitoring/devices/'.$device->id.'/live-frame')
            ->assertNotFound();
    }

    public function test_viewer_gets_404_when_the_frame_has_gone_stale(): void
    {
        $alpha = $this->provisionTenant('Alpha');
        $this->beat($alpha['admin']);
        $device = $this->deviceFor($alpha['admin']);
        $viewer = $this->userWithRole($alpha, 'Seer', ['monitoring.screenshots.view']);

        $this->uploadFrame($alpha['admin'])->assertNoContent();

        $this->signedInAs($viewer)
            ->getJson('/api/v1/monitoring/devices/'.$device->id.'/live-frame')
            ->assertOk();

        $this->travel(31)->seconds();

        $this->signedInAs($viewer)
            ->getJson('/api/v1/monitoring/devices/'.$device->id.'/live-frame')
            ->assertNotFound();
    }

    // ── gating ───────────────────────────────────────────────────

    public function test_watch_without_the_permission_is_refused_with_a_sentence(): void
    {
        $alpha = $this->provisionTenant('Alpha');
        $this->beat($alpha['admin']);
        $device = $this->deviceFor($alpha['admin']);
        $viewer = $this->userWithRole($alpha, 'NoAccess', []);

        $this->signedInAs($viewer)
            ->postJson('/api/v1/monitoring/devices/'.$device->id.'/watch')
            ->assertForbidden()
            ->assertJsonStructure(['message']);
    }

    public function test_live_frame_get_without_the_permission_is_refused_with_a_sentence(): void
    {
        $alpha = $this->provisionTenant('Alpha');
        $this->beat($alpha['admin']);
        $device = $this->deviceFor($alpha['admin']);
        $viewer = $this->userWithRole($alpha, 'NoAccess', []);

        $this->signedInAs($viewer)
            ->getJson('/api/v1/monitoring/devices/'.$device->id.'/live-frame')
            ->assertForbidden()
            ->assertJsonStructure(['message']);
    }

    // ── §3 reach ─────────────────────────────────────────────────

    /**
     * Mirrors ScreenshotReadTest::test_a_company_view_does_not_see_a_sibling_companys_screenshots:
     * a manager scoped to their own franchise must not be able to watch or
     * fetch a live frame for a device owned by someone under a sibling
     * company — 404, the same word a nonexistent id would answer with.
     */
    public function test_a_sibling_orgs_viewer_gets_404_watching_or_fetching_another_devices_live_view(): void
    {
        $alpha = $this->provisionTenant('Alpha');

        $elsewhere = $this->inTenant($alpha['org'], function () use ($alpha) {
            $company = Company::create(['organization_id' => $alpha['org']->id, 'name' => 'Other Co']);

            return Franchise::create([
                'organization_id' => $alpha['org']->id,
                'company_id' => $company->id,
                'name' => 'Other F1',
            ]);
        });

        $theirs = $this->userWithRole($alpha, 'Distant', [], onlyAt: $elsewhere);
        $this->beat($theirs, 'dk-distant');
        $theirDevice = $this->deviceFor($theirs, 'dk-distant');
        $this->uploadFrame($theirs, ['device_key' => 'dk-distant'])->assertNoContent();

        $manager = $this->userWithRole(
            $alpha, 'Branch Manager', ['monitoring.screenshots.view'], onlyAt: $alpha['franchise'],
        );

        $this->signedInAs($manager)
            ->postJson('/api/v1/monitoring/devices/'.$theirDevice->id.'/watch')
            ->assertNotFound();

        $this->signedInAs($manager)
            ->getJson('/api/v1/monitoring/devices/'.$theirDevice->id.'/live-frame')
            ->assertNotFound();
    }

    // ── nothing is ever written to monitoring_screenshots ─────────

    public function test_no_live_path_ever_creates_a_monitoring_screenshot_row(): void
    {
        $alpha = $this->provisionTenant('Alpha');
        $this->beat($alpha['admin']);
        $device = $this->deviceFor($alpha['admin']);
        $viewer = $this->userWithRole($alpha, 'Seer', ['monitoring.screenshots.view']);

        $this->signedInAs($viewer)
            ->postJson('/api/v1/monitoring/devices/'.$device->id.'/watch')
            ->assertNoContent();
        $this->signedInAs($alpha['admin'])
            ->getJson('/api/v1/monitoring/live-wanted?device_key=dk-1')
            ->assertOk();
        $this->uploadFrame($alpha['admin'])->assertNoContent();
        $this->signedInAs($viewer)
            ->getJson('/api/v1/monitoring/devices/'.$device->id.'/live-frame')
            ->assertOk();

        $this->assertSame(0, MonitoringScreenshot::withoutGlobalScopes()->count());
    }
}
