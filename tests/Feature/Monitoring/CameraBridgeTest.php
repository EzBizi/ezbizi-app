<?php

namespace Tests\Feature\Monitoring;

use App\Models\CameraSite;
use App\Models\MonitoringCamera;
use App\Support\Monitoring\CameraRelay;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/**
 * The bridge PC's own API (camera bridge, phase 1). It authenticates with its
 * site key — `Authorization: Bridge <key>` — and never with a person's login,
 * so it keeps working with nobody signed in to EzBizi on that PC.
 */
class CameraBridgeTest extends TestCase
{
    use BuildsTenants, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['camera_bridge.long_poll_seconds' => 1]);
    }

    /** A site with a known key, and one camera on channel 1. */
    private function pairedSite(array $tenant, string $name = 'Hialeah', ?string $key = null): array
    {
        $key ??= str_repeat('k', 39).substr($name, 0, 1);

        return $this->inTenant($tenant['org'], function () use ($tenant, $name, $key) {
            $site = CameraSite::create(['organization_id' => $tenant['org']->id, 'name' => $name]);
            $site->forceFill(['site_key_hash' => hash('sha256', $key), 'paired_at' => now()])->save();
            $camera = MonitoringCamera::create([
                'organization_id' => $tenant['org']->id, 'label' => "{$name} dock",
                'protocol' => 'mjpeg', 'url' => '', 'source' => 'bridge',
                'site_id' => $site->id, 'channel' => '1', 'capabilities' => ['live'],
            ]);

            return ['site' => $site, 'camera' => $camera, 'key' => $key];
        });
    }

    private function asBridge(string $key): static
    {
        return $this->withHeader('Authorization', "Bridge {$key}");
    }

    public function test_a_missing_or_unknown_key_is_refused(): void
    {
        $this->postJson('/api/v1/monitoring/bridge/checkin', [])
            ->assertUnauthorized()->assertJsonPath('code', 'bridge_unpaired');
        $this->asBridge('not-a-real-key')->postJson('/api/v1/monitoring/bridge/checkin', [])
            ->assertUnauthorized()->assertJsonPath('code', 'bridge_unpaired');
    }

    public function test_a_checkin_marks_the_site_online_and_records_each_cameras_state(): void
    {
        $alpha = $this->provisionTenant('Alpha');
        $p = $this->pairedSite($alpha);

        $this->asBridge($p['key'])->postJson('/api/v1/monitoring/bridge/checkin', [
            'engine_version' => '1.9.14',
            'app_version' => '0.0.0450',
            'found' => [['key' => 'nvr-1', 'label' => 'NV2108E-HS', 'kind' => 'recorder',
                'address' => '192.168.1.50', 'channels' => [['channel' => '1', 'label' => 'Dock']]]],
            'cameras' => [['camera_id' => $p['camera']->id, 'status' => 'auth_failed',
                'reason' => 'The recorder refused the password.']],
        ])->assertNoContent();

        $site = $this->inTenant($alpha['org'], fn () => $p['site']->fresh());
        $this->assertTrue($site->online());
        $this->assertSame('0.0.0450', $site->status['app_version']);
        $this->assertSame('192.168.1.50', $site->status['found'][0]['address']);

        $camera = $this->inTenant($alpha['org'], fn () => $p['camera']->fresh());
        $this->assertSame('auth_failed', $camera->bridge_status);
        $this->assertSame('The recorder refused the password.', $camera->bridge_reason);
    }

    public function test_a_key_can_report_on_and_see_only_its_own_sites_cameras(): void
    {
        $alpha = $this->provisionTenant('Alpha');
        $mine = $this->pairedSite($alpha, 'Hialeah');
        $theirs = $this->pairedSite($alpha, 'Boston');

        // Another site's camera, even in the same organization, is ignored.
        $this->asBridge($mine['key'])->postJson('/api/v1/monitoring/bridge/checkin', [
            'cameras' => [['camera_id' => $theirs['camera']->id, 'status' => 'unreachable']],
        ])->assertNoContent();
        $this->assertNull($this->inTenant($alpha['org'], fn () => $theirs['camera']->fresh()->bridge_status));

        // And it is never asked to stream another site's camera.
        app(CameraRelay::class)->want($theirs['camera'], 'sub');
        $this->asBridge($mine['key'])->getJson('/api/v1/monitoring/bridge/work?since=0')
            ->assertOk()->assertJsonCount(0, 'wanted');
    }

    public function test_work_answers_at_once_when_a_camera_is_wanted_and_on_timeout_otherwise(): void
    {
        $alpha = $this->provisionTenant('Alpha');
        $p = $this->pairedSite($alpha);

        $idle = $this->asBridge($p['key'])->getJson('/api/v1/monitoring/bridge/work?since=0')->assertOk();
        $this->assertSame([], $idle->json('wanted'));

        app(CameraRelay::class)->want($p['camera'], 'sub');
        $busy = $this->asBridge($p['key'])->getJson('/api/v1/monitoring/bridge/work?since='.$idle->json('version'))
            ->assertOk();

        $wanted = $busy->json('wanted.0');
        $this->assertSame($p['camera']->id, $wanted['camera_id']);
        $this->assertSame('1', $wanted['channel']);
        $this->assertSame('sub', $wanted['quality']);
        // The publish URL names exactly this camera's relay path and carries its token.
        $this->assertStringContainsString("/cam-{$p['camera']->id}-sub?user=bridge&pass=", $wanted['publish']['url']);
        $this->assertTrue(app(CameraRelay::class)->verify($wanted['publish']['token'], 'publish', "cam-{$p['camera']->id}-sub"));
    }

    public function test_a_still_is_kept_for_its_own_sites_camera_only(): void
    {
        \Illuminate\Support\Facades\Storage::fake('local');
        $alpha = $this->provisionTenant('Alpha');
        $mine = $this->pairedSite($alpha, 'Hialeah');
        $theirs = $this->pairedSite($alpha, 'Boston');
        $jpeg = fn () => \Illuminate\Http\UploadedFile::fake()->image('s.jpg', 640, 360);

        $this->asBridge($mine['key'])->post('/api/v1/monitoring/bridge/still',
            ['camera_id' => $mine['camera']->id, 'image' => $jpeg()], ['Accept' => 'application/json'])
            ->assertNoContent();
        \Illuminate\Support\Facades\Storage::disk('local')->assertExists("camera-stills/{$mine['camera']->id}.jpg");

        $this->asBridge($mine['key'])->post('/api/v1/monitoring/bridge/still',
            ['camera_id' => $theirs['camera']->id, 'image' => $jpeg()], ['Accept' => 'application/json'])
            ->assertNotFound();
    }

    public function test_a_manager_adds_a_found_channel_as_a_camera_on_the_site(): void
    {
        $alpha = $this->provisionTenant('Alpha');
        $p = $this->pairedSite($alpha);

        $id = $this->signedInAs($alpha['admin'])
            ->postJson("/api/v1/monitoring/camera-sites/{$p['site']->id}/cameras", [
                'channel' => '2', 'label' => 'Front gate',
            ])
            ->assertCreated()
            ->json('data.id');

        $camera = $this->inTenant($alpha['org'], fn () => MonitoringCamera::find($id));
        $this->assertSame('bridge', $camera->source);
        $this->assertSame($p['site']->id, $camera->site_id);
        $this->assertSame(['live'], $camera->capabilities);

        $viewer = $this->userWithRole($alpha, 'Watch', ['monitoring.cameras.view']);
        $this->signedInAs($viewer)
            ->postJson("/api/v1/monitoring/camera-sites/{$p['site']->id}/cameras", ['channel' => '3', 'label' => 'X'])
            ->assertForbidden();
    }
}
