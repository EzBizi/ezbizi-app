<?php

namespace Tests\Feature\Monitoring;

use App\Models\Company;
use App\Models\Franchise;
use App\Models\MonitoredDevice;
use App\Models\MonitoringScreenshot;
use App\Support\Settings\SettingsResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/**
 * Remote Control (2026-09-02-monitoring-remote-control) — a manager driving
 * a monitored machine's mouse and keyboard, riding on top of Live View: no
 * row is ever written to `monitoring_screenshots`, and the input queue is a
 * cache list that self-expires and clears itself on the agent's own read.
 *
 * Mirrors LiveViewTest's shape (beat/deviceFor helpers, the same §3 and
 * gating cases), one grant away from it: `monitoring.control` rather than
 * `monitoring.screenshots.view`.
 */
class RemoteControlTest extends TestCase
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

    // ── control → control-wanted AND live-wanted ────────────────────

    public function test_control_registers_control_wanted_and_live_wanted(): void
    {
        $alpha = $this->provisionTenant('Alpha');
        $this->beat($alpha['admin']);
        $device = $this->deviceFor($alpha['admin']);
        $controller = $this->userWithRole($alpha, 'Controller', ['monitoring.control']);

        $this->signedInAs($controller)
            ->postJson('/api/v1/monitoring/devices/'.$device->id.'/control')
            ->assertNoContent();

        $this->signedInAs($alpha['admin'])
            ->getJson('/api/v1/monitoring/live-wanted?device_key=dk-1')
            ->assertOk()
            ->assertJson(['wanted' => true]);

        $this->signedInAs($alpha['admin'])
            ->getJson('/api/v1/monitoring/input-pending?device_key=dk-1')
            ->assertOk()
            ->assertJson(['events' => [], 'control_wanted' => true]);
    }

    public function test_control_wanted_expires_after_the_ttl(): void
    {
        $alpha = $this->provisionTenant('Alpha');
        $this->beat($alpha['admin']);
        $device = $this->deviceFor($alpha['admin']);
        $controller = $this->userWithRole($alpha, 'Controller', ['monitoring.control']);

        $this->signedInAs($controller)
            ->postJson('/api/v1/monitoring/devices/'.$device->id.'/control')
            ->assertNoContent();

        $this->signedInAs($alpha['admin'])
            ->getJson('/api/v1/monitoring/input-pending?device_key=dk-1')
            ->assertOk()
            ->assertJson(['control_wanted' => true]);

        $this->travel(16)->seconds();

        $this->signedInAs($alpha['admin'])
            ->getJson('/api/v1/monitoring/input-pending?device_key=dk-1')
            ->assertOk()
            ->assertJson(['control_wanted' => false]);
    }

    // ── input queueing and read-once delivery ───────────────────────

    public function test_input_pending_returns_queued_events_in_order_and_clears_them(): void
    {
        $alpha = $this->provisionTenant('Alpha');
        $this->beat($alpha['admin']);
        $device = $this->deviceFor($alpha['admin']);
        $controller = $this->userWithRole($alpha, 'Controller', ['monitoring.control']);

        $events = [
            ['type' => 'mouseMove', 'x' => 10, 'y' => 20],
            ['type' => 'mouseButton', 'button' => 'left', 'down' => true],
            ['type' => 'mouseButton', 'button' => 'left', 'down' => false],
            ['type' => 'key', 'code' => 'KeyA', 'down' => true],
        ];

        $this->signedInAs($controller)
            ->postJson('/api/v1/monitoring/devices/'.$device->id.'/input', ['events' => $events])
            ->assertNoContent();

        $first = $this->signedInAs($alpha['admin'])
            ->getJson('/api/v1/monitoring/input-pending?device_key=dk-1')
            ->assertOk();

        $this->assertSame($events, $first->json('events'));

        // Read-once: a second poll finds the queue already emptied.
        $this->signedInAs($alpha['admin'])
            ->getJson('/api/v1/monitoring/input-pending?device_key=dk-1')
            ->assertOk()
            ->assertJson(['events' => []]);
    }

    public function test_a_second_input_batch_appends_rather_than_replaces(): void
    {
        $alpha = $this->provisionTenant('Alpha');
        $this->beat($alpha['admin']);
        $device = $this->deviceFor($alpha['admin']);
        $controller = $this->userWithRole($alpha, 'Controller', ['monitoring.control']);

        $first = [['type' => 'mouseMove', 'x' => 1, 'y' => 1]];
        $second = [['type' => 'mouseMove', 'x' => 2, 'y' => 2]];

        $this->signedInAs($controller)
            ->postJson('/api/v1/monitoring/devices/'.$device->id.'/input', ['events' => $first])
            ->assertNoContent();
        $this->signedInAs($controller)
            ->postJson('/api/v1/monitoring/devices/'.$device->id.'/input', ['events' => $second])
            ->assertNoContent();

        $resp = $this->signedInAs($alpha['admin'])
            ->getJson('/api/v1/monitoring/input-pending?device_key=dk-1')
            ->assertOk();

        $this->assertSame([...$first, ...$second], $resp->json('events'));
    }

    public function test_more_than_100_events_in_one_batch_is_rejected(): void
    {
        $alpha = $this->provisionTenant('Alpha');
        $this->beat($alpha['admin']);
        $device = $this->deviceFor($alpha['admin']);
        $controller = $this->userWithRole($alpha, 'Controller', ['monitoring.control']);

        $events = array_fill(0, 101, ['type' => 'mouseMove', 'x' => 1, 'y' => 1]);

        $this->signedInAs($controller)
            ->postJson('/api/v1/monitoring/devices/'.$device->id.'/input', ['events' => $events])
            ->assertStatus(422);

        $this->signedInAs($alpha['admin'])
            ->getJson('/api/v1/monitoring/input-pending?device_key=dk-1')
            ->assertOk()
            ->assertJson(['events' => []]);
    }

    public function test_an_oversized_events_batch_is_rejected(): void
    {
        $alpha = $this->provisionTenant('Alpha');
        $this->beat($alpha['admin']);
        $device = $this->deviceFor($alpha['admin']);
        $controller = $this->userWithRole($alpha, 'Controller', ['monitoring.control']);

        $events = [['type' => 'text', 'text' => str_repeat('a', 100_000)]];

        $this->signedInAs($controller)
            ->postJson('/api/v1/monitoring/devices/'.$device->id.'/input', ['events' => $events])
            ->assertStatus(422);
    }

    public function test_input_pending_for_an_unknown_device_key_is_rejected(): void
    {
        $alpha = $this->provisionTenant('Alpha');
        $this->beat($alpha['admin'], 'dk-registered');

        $this->signedInAs($alpha['admin'])
            ->getJson('/api/v1/monitoring/input-pending?device_key=dk-never-beat')
            ->assertStatus(404);
    }

    // ── the opt-in "being controlled" indicator rides input-pending ──

    public function test_input_pending_reports_indicator_false_by_default(): void
    {
        $alpha = $this->provisionTenant('Alpha');
        $this->beat($alpha['admin']);

        // Default OFF: silent everywhere until an org turns it on.
        $this->signedInAs($alpha['admin'])
            ->getJson('/api/v1/monitoring/input-pending?device_key=dk-1')
            ->assertOk()
            ->assertJson(['events' => [], 'indicator' => false]);
    }

    public function test_input_pending_reports_indicator_true_when_the_org_enables_it(): void
    {
        $alpha = $this->provisionTenant('Alpha');
        $this->beat($alpha['admin']);

        $this->inTenant($alpha['org'], fn () => app(SettingsResolver::class)
            ->set('monitoring.control_indicator', $alpha['org'], true));

        $this->signedInAs($alpha['admin'])
            ->getJson('/api/v1/monitoring/input-pending?device_key=dk-1')
            ->assertOk()
            ->assertJson(['indicator' => true]);
    }

    /**
     * Org-scoped: each device sees ITS OWN organization's answer. Org A on,
     * org B off — B's agent must never be told to show a notice because A
     * enabled one.
     */
    public function test_indicator_is_resolved_per_device_organization(): void
    {
        $alpha = $this->provisionTenant('Alpha');
        $beta = $this->provisionTenant('Beta');
        $this->beat($alpha['admin']);
        $this->beat($beta['admin']);

        $this->inTenant($alpha['org'], fn () => app(SettingsResolver::class)
            ->set('monitoring.control_indicator', $alpha['org'], true));

        $this->signedInAs($alpha['admin'])
            ->getJson('/api/v1/monitoring/input-pending?device_key=dk-1')
            ->assertOk()
            ->assertJson(['indicator' => true]);

        $this->signedInAs($beta['admin'])
            ->getJson('/api/v1/monitoring/input-pending?device_key=dk-1')
            ->assertOk()
            ->assertJson(['indicator' => false]);
    }

    // ── gating: monitoring.control is separate from screenshots.view ──

    public function test_control_without_the_permission_is_refused_with_a_sentence(): void
    {
        $alpha = $this->provisionTenant('Alpha');
        $this->beat($alpha['admin']);
        $device = $this->deviceFor($alpha['admin']);
        // Holds the VIEW grant, not control — proves the two are separate.
        $viewer = $this->userWithRole($alpha, 'Seer', ['monitoring.screenshots.view']);

        $this->signedInAs($viewer)
            ->postJson('/api/v1/monitoring/devices/'.$device->id.'/control')
            ->assertForbidden()
            ->assertJsonStructure(['message']);
    }

    public function test_input_without_the_permission_is_refused_with_a_sentence(): void
    {
        $alpha = $this->provisionTenant('Alpha');
        $this->beat($alpha['admin']);
        $device = $this->deviceFor($alpha['admin']);
        $viewer = $this->userWithRole($alpha, 'Seer', ['monitoring.screenshots.view']);

        $this->signedInAs($viewer)
            ->postJson('/api/v1/monitoring/devices/'.$device->id.'/input', [
                'events' => [['type' => 'mouseMove', 'x' => 1, 'y' => 1]],
            ])
            ->assertForbidden()
            ->assertJsonStructure(['message']);

        $this->assertSame(0, MonitoringScreenshot::withoutGlobalScopes()->count());
    }

    // ── §3 reach ─────────────────────────────────────────────────

    /**
     * Mirrors LiveViewTest::test_a_sibling_orgs_viewer_gets_404_watching_or_fetching_another_devices_live_view:
     * a manager scoped to their own franchise must not be able to open a
     * control session or send input to a device owned by someone under a
     * sibling company — 404, the same word a nonexistent id would answer with.
     */
    public function test_a_sibling_orgs_viewer_gets_404_controlling_or_sending_input_to_another_devices_remote_control(): void
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

        $manager = $this->userWithRole(
            $alpha, 'Branch Manager', ['monitoring.control'], onlyAt: $alpha['franchise'],
        );

        $this->signedInAs($manager)
            ->postJson('/api/v1/monitoring/devices/'.$theirDevice->id.'/control')
            ->assertNotFound();

        $this->signedInAs($manager)
            ->postJson('/api/v1/monitoring/devices/'.$theirDevice->id.'/input', [
                'events' => [['type' => 'mouseMove', 'x' => 1, 'y' => 1]],
            ])
            ->assertNotFound();
    }

    // ── nothing is ever written to monitoring_screenshots ─────────

    public function test_no_control_path_ever_creates_a_monitoring_screenshot_row(): void
    {
        $alpha = $this->provisionTenant('Alpha');
        $this->beat($alpha['admin']);
        $device = $this->deviceFor($alpha['admin']);
        $controller = $this->userWithRole($alpha, 'Controller', ['monitoring.control']);

        $this->signedInAs($controller)
            ->postJson('/api/v1/monitoring/devices/'.$device->id.'/control')
            ->assertNoContent();
        $this->signedInAs($controller)
            ->postJson('/api/v1/monitoring/devices/'.$device->id.'/input', [
                'events' => [['type' => 'mouseMove', 'x' => 1, 'y' => 1]],
            ])
            ->assertNoContent();
        $this->signedInAs($alpha['admin'])
            ->getJson('/api/v1/monitoring/input-pending?device_key=dk-1')
            ->assertOk();

        $this->assertSame(0, MonitoringScreenshot::withoutGlobalScopes()->count());
    }
}
