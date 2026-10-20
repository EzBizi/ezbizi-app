<?php

namespace Tests\Feature\Monitoring;

use App\Models\CameraSite;
use App\Models\Company;
use App\Models\Franchise;
use App\Models\MonitoringCamera;
use App\Support\Monitoring\CameraRelay;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/**
 * Watching a bridge camera (camera bridge, phase 1): a viewer asks to watch,
 * the camera joins its site's wanted set, and the relay asks Laravel whether
 * each read or publish token is good for the path it names.
 */
class CameraRelayTest extends TestCase
{
    use BuildsTenants, RefreshDatabase;

    private const SECRET = 'relay-secret-for-tests';

    protected function setUp(): void
    {
        parent::setUp();
        config(['camera_bridge.relay_secret' => self::SECRET]);
    }

    private function camera(array $tenant, bool $online = true, ?Franchise $at = null): MonitoringCamera
    {
        return $this->inTenant($tenant['org'], function () use ($tenant, $online, $at) {
            $site = CameraSite::create([
                'organization_id' => $tenant['org']->id, 'name' => 'Hialeah',
                'company_id' => $at?->company_id, 'franchise_id' => $at?->id,
            ]);
            $site->forceFill(['last_checkin_at' => $online ? now() : now()->subHour()])->save();

            return MonitoringCamera::create([
                'organization_id' => $tenant['org']->id, 'label' => 'Dock', 'protocol' => 'mjpeg',
                'url' => '', 'source' => 'bridge', 'site_id' => $site->id, 'channel' => '1',
            ]);
        });
    }

    private function hook(array $body)
    {
        return $this->withHeader('X-Relay-Secret', self::SECRET)->postJson('/api/v1/camera-relay/auth', $body);
    }

    private function wanted(array $tenant, MonitoringCamera $camera): array
    {
        return $this->inTenant($tenant['org'], fn () => app(CameraRelay::class)->wanted($camera->site));
    }

    public function test_watch_returns_a_play_url_and_wants_the_camera(): void
    {
        $alpha = $this->provisionTenant('Alpha');
        $camera = $this->camera($alpha);

        $play = $this->signedInAs($alpha['admin'])
            ->postJson("/api/v1/monitoring/cameras/{$camera->id}/watch", ['quality' => 'sub'])
            ->assertOk()->json('play');

        $this->assertStringContainsString("cam-{$camera->id}-sub", $play['url']);
        $this->assertNotEmpty($play['expires_at']);
        $this->assertSame([['camera_id' => $camera->id, 'quality' => 'sub']], $this->wanted($alpha, $camera));
    }

    public function test_watch_on_an_offline_site_is_409_bridge_offline(): void
    {
        $alpha = $this->provisionTenant('Alpha');
        $camera = $this->camera($alpha, online: false);

        $this->signedInAs($alpha['admin'])
            ->postJson("/api/v1/monitoring/cameras/{$camera->id}/watch", ['quality' => 'sub'])
            ->assertStatus(409)->assertJsonPath('code', 'bridge_offline');
        $this->assertSame([], $this->wanted($alpha, $camera));
    }

    public function test_a_viewer_outside_reach_gets_404(): void
    {
        $alpha = $this->provisionTenant('Alpha');
        $other = $this->inTenant($alpha['org'], function () use ($alpha) {
            $company = Company::create(['organization_id' => $alpha['org']->id, 'name' => 'Other Co']);

            return Franchise::create(['organization_id' => $alpha['org']->id, 'company_id' => $company->id, 'name' => 'F2']);
        });
        $camera = $this->camera($alpha, at: $other);
        $branch = $this->userWithRole($alpha, 'Branch', ['monitoring.cameras.view'], onlyAt: $alpha['franchise']);

        $this->signedInAs($branch)
            ->postJson("/api/v1/monitoring/cameras/{$camera->id}/watch", ['quality' => 'sub'])
            ->assertNotFound();
        $this->signedInAs($branch)->getJson('/api/v1/monitoring/cameras')
            ->assertOk()->assertJsonMissing(['id' => $camera->id]);
    }

    public function test_a_play_token_for_one_camera_cannot_read_another(): void
    {
        $alpha = $this->provisionTenant('Alpha');
        $a = $this->camera($alpha);
        $b = $this->camera($alpha);
        $token = app(CameraRelay::class)->mintPlay($a, $alpha['admin'], 'sub')['token'];

        $this->hook(['action' => 'read', 'path' => "cam-{$a->id}-sub", 'password' => $token])->assertOk();
        $this->hook(['action' => 'read', 'path' => "cam-{$b->id}-sub", 'password' => $token])->assertUnauthorized();
    }

    public function test_a_publish_token_cannot_be_used_to_read(): void
    {
        $alpha = $this->provisionTenant('Alpha');
        $camera = $this->camera($alpha);
        $token = app(CameraRelay::class)->mintPublish($camera, 'sub')['token'];

        $this->hook(['action' => 'publish', 'path' => "cam-{$camera->id}-sub", 'password' => $token])->assertOk();
        $this->hook(['action' => 'read', 'path' => "cam-{$camera->id}-sub", 'password' => $token])->assertUnauthorized();
    }

    public function test_expired_tokens_are_refused(): void
    {
        $alpha = $this->provisionTenant('Alpha');
        $camera = $this->camera($alpha);
        $token = app(CameraRelay::class)->mintPlay($camera, $alpha['admin'], 'sub')['token'];

        $this->travel(6)->minutes();
        $this->hook(['action' => 'read', 'path' => "cam-{$camera->id}-sub", 'password' => $token])->assertUnauthorized();
    }

    public function test_relay_hooks_refuse_without_the_shared_secret(): void
    {
        $alpha = $this->provisionTenant('Alpha');
        $camera = $this->camera($alpha);
        $token = app(CameraRelay::class)->mintPlay($camera, $alpha['admin'], 'sub')['token'];

        $this->postJson('/api/v1/camera-relay/auth', ['action' => 'read', 'path' => "cam-{$camera->id}-sub", 'password' => $token])
            ->assertUnauthorized();
        $this->withHeader('X-Relay-Secret', 'wrong')->postJson('/api/v1/camera-relay/viewers', ['path' => "cam-{$camera->id}-sub", 'viewers' => 0])
            ->assertUnauthorized();
    }

    public function test_last_viewer_leaving_unwants_and_two_viewers_keep_it(): void
    {
        $alpha = $this->provisionTenant('Alpha');
        $camera = $this->camera($alpha);
        $this->signedInAs($alpha['admin'])->postJson("/api/v1/monitoring/cameras/{$camera->id}/watch", ['quality' => 'sub']);

        $viewers = fn (int $n) => $this->withHeader('X-Relay-Secret', self::SECRET)
            ->postJson('/api/v1/camera-relay/viewers', ['path' => "cam-{$camera->id}-sub", 'viewers' => $n])
            ->assertNoContent();

        $viewers(2);
        $this->assertCount(1, $this->wanted($alpha, $camera), 'Two viewers keep it wanted.');

        $viewers(0); // the grace job runs at once on the sync queue
        $this->assertSame([], $this->wanted($alpha, $camera), 'The last viewer leaving unwants it.');
    }

    public function test_watch_asks_the_boxs_go2rtc_for_the_stream_and_its_mjpeg(): void
    {
        \Illuminate\Support\Facades\Http::fake();
        $alpha = $this->provisionTenant('Alpha');
        $camera = $this->camera($alpha);

        $url = $this->signedInAs($alpha['admin'])
            ->postJson("/api/v1/monitoring/cameras/{$camera->id}/watch", ['quality' => 'main'])
            ->assertOk()->json('play.url');

        $path = "cam-{$camera->id}-main";
        $this->assertStringContainsString("src={$path}_jpg", $url);
        \Illuminate\Support\Facades\Http::assertSent(fn ($r) => $r->method() === 'PUT'
            && str_contains($r->url(), "name={$path}&")
            && str_contains(urldecode($r->url()), "src=rtsp://box:".self::SECRET."@127.0.0.1:8554/{$path}"));
        \Illuminate\Support\Facades\Http::assertSent(fn ($r) => str_contains($r->url(), "name={$path}_jpg&")
            && str_contains(urldecode($r->url()), "src=ffmpeg:{$path}#video=mjpeg#width=1280#height=720"));
    }

    public function test_the_box_reads_the_relay_with_the_shared_secret_from_loopback_only(): void
    {
        $this->hook(['action' => 'read', 'path' => 'cam-x-sub', 'user' => 'box', 'password' => self::SECRET, 'ip' => '127.0.0.1'])->assertOk();
        $this->hook(['action' => 'read', 'path' => 'cam-x-sub', 'user' => 'box', 'password' => self::SECRET, 'ip' => '203.0.113.9'])->assertUnauthorized();
        $this->hook(['action' => 'publish', 'path' => 'cam-x-sub', 'user' => 'box', 'password' => self::SECRET, 'ip' => '127.0.0.1'])->assertUnauthorized();
    }

    public function test_play_check_lets_a_valid_token_through_to_its_own_mjpeg_only(): void
    {
        $alpha = $this->provisionTenant('Alpha');
        $a = $this->camera($alpha);
        $b = $this->camera($alpha);
        $token = app(CameraRelay::class)->mintPlay($a, $alpha['admin'], 'sub')['token'];

        $check = fn (string $src) => $this->withHeader('X-Relay-Secret', self::SECRET)
            ->withHeader('X-Original-URI', "/api/stream.mjpeg?src={$src}&token={$token}")
            ->get('/api/v1/camera-relay/play-check');

        $check("cam-{$a->id}-sub_jpg")->assertNoContent();
        $check("cam-{$b->id}-sub_jpg")->assertUnauthorized();
    }

    public function test_play_check_refuses_a_second_src_smuggled_beside_a_valid_one(): void
    {
        $alpha = $this->provisionTenant('Alpha');
        $mine = $this->camera($alpha);
        $victim = $this->camera($alpha);
        $token = app(CameraRelay::class)->mintPlay($mine, $alpha['admin'], 'sub')['token'];

        $this->withHeader('X-Relay-Secret', self::SECRET)
            ->withHeader('X-Original-URI', "/api/stream.mjpeg?src=cam-{$victim->id}-main_jpg&src=cam-{$mine->id}-sub_jpg&token={$token}")
            ->get('/api/v1/camera-relay/play-check')
            ->assertUnauthorized();
    }

    public function test_a_disabled_camera_cannot_be_watched_and_disabling_unwants_it(): void
    {
        \Illuminate\Support\Facades\Http::fake();
        $alpha = $this->provisionTenant('Alpha');
        $camera = $this->camera($alpha);
        $this->signedInAs($alpha['admin'])->postJson("/api/v1/monitoring/cameras/{$camera->id}/watch", ['quality' => 'sub'])->assertOk();

        $this->signedInAs($alpha['admin'])->patchJson("/api/v1/monitoring/cameras/{$camera->id}", ['enabled' => false])->assertOk();
        $this->assertSame([], $this->wanted($alpha, $camera), 'Disabling stops the stream now.');

        $this->signedInAs($alpha['admin'])->postJson("/api/v1/monitoring/cameras/{$camera->id}/watch", ['quality' => 'sub'])->assertNotFound();
    }

    public function test_disconnecting_a_site_forgets_what_it_was_asked_to_stream(): void
    {
        \Illuminate\Support\Facades\Http::fake();
        $alpha = $this->provisionTenant('Alpha');
        $camera = $this->camera($alpha);
        $this->signedInAs($alpha['admin'])->postJson("/api/v1/monitoring/cameras/{$camera->id}/watch", ['quality' => 'sub'])->assertOk();
        $before = $this->inTenant($alpha['org'], fn () => app(CameraRelay::class)->version($camera->site));

        $this->signedInAs($alpha['admin'])->postJson("/api/v1/monitoring/camera-sites/{$camera->site_id}/revoke")->assertNoContent();

        $this->assertSame([], $this->wanted($alpha, $camera));
        $this->assertGreaterThan($before, $this->inTenant($alpha['org'], fn () => app(CameraRelay::class)->version($camera->site)));
    }
}
