<?php

namespace Tests\Feature\Monitoring;

use App\Models\MonitoringCamera;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/**
 * `GET/POST/PATCH/DELETE /api/v1/monitoring/cameras` — generic-protocol v1
 * (docs/superpowers/specs/2026-09-01-monitoring-cameras-design.md).
 *
 * A camera is an organization asset, not data about a person: unlike
 * ScreenshotReadTest/DevicesReadTest there is no §3 per-person reach to
 * prove here (see MonitoringCameraPolicy's docblock) — only the tenant
 * boundary BelongsToTenant already applies, so the load-bearing test is a
 * genuine SIBLING ORGANIZATION (two `provisionTenant()` calls, mirroring
 * AttachmentsTest::test_another_organizations_attachment_does_not_exist)
 * rather than a sibling company/franchise within one org.
 */
class CamerasTest extends TestCase
{
    use BuildsTenants, RefreshDatabase;

    /** @param array{org: \App\Models\Organization, admin: \App\Models\User} $tenant */
    private function makeCamera(array $tenant, array $overrides = []): MonitoringCamera
    {
        return $this->inTenant($tenant['org'], fn () => MonitoringCamera::create(array_merge([
            'label' => 'Front Door',
            'protocol' => 'hls',
            'url' => 'https://cam.example.test/stream.m3u8',
            'enabled' => true,
            'created_by' => $tenant['admin']->getKey(),
        ], $overrides)));
    }

    // ── create ──────────────────────────────────────────────────────

    public function test_cameras_manage_can_create_a_camera_with_correct_fields_and_created_by(): void
    {
        $alpha = $this->provisionTenant('Alpha');
        $manager = $this->userWithRole($alpha, 'Manager', ['monitoring.cameras.manage']);

        $row = $this->signedInAs($manager)->postJson('/api/v1/monitoring/cameras', [
            'label' => 'Front Door',
            'protocol' => 'hls',
            'url' => 'https://cam.example.test/stream.m3u8',
        ])->assertCreated()->json('data');

        $this->assertSame('Front Door', $row['label']);
        $this->assertSame('hls', $row['protocol']);
        $this->assertSame('https://cam.example.test/stream.m3u8', $row['url']);
        $this->assertTrue($row['enabled']);

        $camera = $this->inTenant($alpha['org'], fn () => MonitoringCamera::query()->sole());
        $this->assertSame($alpha['org']->id, $camera->organization_id);
        $this->assertSame($manager->getKey(), $camera->created_by);
    }

    public function test_a_valid_rtsp_url_with_protocol_rtsp_is_accepted(): void
    {
        $alpha = $this->provisionTenant('Alpha');
        $manager = $this->userWithRole($alpha, 'Manager', ['monitoring.cameras.manage']);

        $this->signedInAs($manager)->postJson('/api/v1/monitoring/cameras', [
            'label' => 'Loading Dock',
            'protocol' => 'rtsp',
            'url' => 'rtsp://192.168.1.50:554/stream1',
        ])->assertCreated()->assertJsonPath('data.protocol', 'rtsp');
    }

    public function test_bad_protocol_is_rejected(): void
    {
        $alpha = $this->provisionTenant('Alpha');
        $manager = $this->userWithRole($alpha, 'Manager', ['monitoring.cameras.manage']);

        $this->signedInAs($manager)->postJson('/api/v1/monitoring/cameras', [
            'label' => 'Bad',
            'protocol' => 'not-a-protocol',
            'url' => 'https://cam.example.test/stream.m3u8',
        ])->assertStatus(422)->assertJsonValidationErrors(['protocol']);
    }

    public function test_bad_url_is_rejected(): void
    {
        $alpha = $this->provisionTenant('Alpha');
        $manager = $this->userWithRole($alpha, 'Manager', ['monitoring.cameras.manage']);

        $this->signedInAs($manager)->postJson('/api/v1/monitoring/cameras', [
            'label' => 'Bad',
            'protocol' => 'hls',
            'url' => 'not a url',
        ])->assertStatus(422)->assertJsonValidationErrors(['url']);
    }

    public function test_rtsp_url_with_protocol_hls_is_rejected(): void
    {
        $alpha = $this->provisionTenant('Alpha');
        $manager = $this->userWithRole($alpha, 'Manager', ['monitoring.cameras.manage']);

        $this->signedInAs($manager)->postJson('/api/v1/monitoring/cameras', [
            'label' => 'Mismatched',
            'protocol' => 'hls',
            'url' => 'rtsp://192.168.1.50:554/stream1',
        ])->assertStatus(422)->assertJsonValidationErrors(['url']);
    }

    public function test_http_url_with_protocol_rtsp_is_rejected(): void
    {
        $alpha = $this->provisionTenant('Alpha');
        $manager = $this->userWithRole($alpha, 'Manager', ['monitoring.cameras.manage']);

        $this->signedInAs($manager)->postJson('/api/v1/monitoring/cameras', [
            'label' => 'Mismatched',
            'protocol' => 'rtsp',
            'url' => 'https://cam.example.test/stream.m3u8',
        ])->assertStatus(422)->assertJsonValidationErrors(['url']);
    }

    // ── permissions ─────────────────────────────────────────────────

    public function test_no_cameras_view_permission_is_refused_with_a_sentence(): void
    {
        $alpha = $this->provisionTenant('Alpha');
        $viewer = $this->userWithRole($alpha, 'NoAccess', []);

        $this->signedInAs($viewer)->getJson('/api/v1/monitoring/cameras')
            ->assertForbidden()
            ->assertJsonStructure(['message']);
    }

    public function test_no_cameras_manage_permission_refuses_create_update_delete_with_a_sentence(): void
    {
        $alpha = $this->provisionTenant('Alpha');
        $camera = $this->makeCamera($alpha);
        $viewer = $this->userWithRole($alpha, 'Seer', ['monitoring.cameras.view']);

        $this->signedInAs($viewer)->postJson('/api/v1/monitoring/cameras', [
            'label' => 'Nope', 'protocol' => 'hls', 'url' => 'https://cam.example.test/x.m3u8',
        ])->assertForbidden()->assertJsonStructure(['message']);

        $this->signedInAs($viewer)->patchJson('/api/v1/monitoring/cameras/'.$camera->id, ['label' => 'Nope'])
            ->assertForbidden()->assertJsonStructure(['message']);

        $this->signedInAs($viewer)->deleteJson('/api/v1/monitoring/cameras/'.$camera->id)
            ->assertForbidden()->assertJsonStructure(['message']);
    }

    // ── read + tenant isolation ─────────────────────────────────────

    public function test_cameras_view_lists_only_this_orgs_cameras(): void
    {
        $alpha = $this->provisionTenant('Alpha');
        $beta = $this->provisionTenant('Beta');

        $mine = $this->makeCamera($alpha);
        $theirs = $this->makeCamera($beta);

        $viewer = $this->userWithRole($alpha, 'Seer', ['monitoring.cameras.view']);

        $ids = $this->signedInAs($viewer)->getJson('/api/v1/monitoring/cameras')
            ->assertOk()->json('data.*.id');

        $this->assertContains($mine->id, $ids);
        $this->assertNotContains($theirs->id, $ids);
    }

    public function test_a_sibling_organizations_camera_404s_on_update_and_delete(): void
    {
        $alpha = $this->provisionTenant('Alpha');
        $beta = $this->provisionTenant('Beta');

        $theirs = $this->makeCamera($beta);
        $manager = $this->userWithRole($alpha, 'Manager', ['monitoring.cameras.manage']);

        $this->signedInAs($manager)->patchJson('/api/v1/monitoring/cameras/'.$theirs->id, ['label' => 'Hijack'])
            ->assertNotFound();

        $this->signedInAs($manager)->deleteJson('/api/v1/monitoring/cameras/'.$theirs->id)
            ->assertNotFound();

        // Untouched: still there, under Beta.
        $this->assertNotNull($this->inTenant($beta['org'], fn () => MonitoringCamera::find($theirs->id)));
    }

    // ── update / delete ─────────────────────────────────────────────

    public function test_update_changes_fields(): void
    {
        $alpha = $this->provisionTenant('Alpha');
        $camera = $this->makeCamera($alpha);
        $manager = $this->userWithRole($alpha, 'Manager', ['monitoring.cameras.manage']);

        $row = $this->signedInAs($manager)->patchJson('/api/v1/monitoring/cameras/'.$camera->id, [
            'label' => 'Back Door',
            'enabled' => false,
        ])->assertOk()->json('data');

        $this->assertSame('Back Door', $row['label']);
        $this->assertFalse($row['enabled']);

        $fresh = $this->inTenant($alpha['org'], fn () => MonitoringCamera::find($camera->id));
        $this->assertSame('Back Door', $fresh->label);
        $this->assertFalse($fresh->enabled);
    }

    public function test_delete_removes_the_row(): void
    {
        $alpha = $this->provisionTenant('Alpha');
        $camera = $this->makeCamera($alpha);
        $manager = $this->userWithRole($alpha, 'Manager', ['monitoring.cameras.manage']);

        $this->signedInAs($manager)->deleteJson('/api/v1/monitoring/cameras/'.$camera->id)
            ->assertNoContent();

        $this->assertSame(0, $this->inTenant($alpha['org'], fn () => MonitoringCamera::query()->count()));
    }
}
