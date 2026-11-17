<?php

namespace Tests\Feature\Monitoring;

use App\Models\MonitoredDevice;
use App\Models\MonitoringScreenshot;
use App\Models\PlatformStaff;
use App\Models\User;
use App\Support\Monitoring\ScreenMonitoringExemption;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/**
 * The SECURITY exemption: the Lead Developer's own device is never
 * screen-captured, live-viewed or remote-controlled, while it still beats
 * (online) and reports PC specs. Keyed on the shared email between an org
 * `User` and an ACTIVE `lead_developer` PlatformStaff — the only link the two
 * separate identities have (ScreenMonitoringExemption).
 *
 * ── Non-vacuity is the whole design of this file ──────────────────
 *
 * Every refusal case is paired with an ORDINARY device — same request, same
 * grants, a user whose email matches no lead-developer staff — that SUCCEEDS.
 * A guard that refused everyone, or the exemption silently matching nobody,
 * would flip one half of each pair red. The heartbeat case is the same shape:
 * the exempt beat drops `screenshot`/`control` where the ordinary beat keeps
 * `screenshot`.
 */
class ScreenMonitoringExemptionTest extends TestCase
{
    use BuildsTenants, RefreshDatabase;

    private const REFUSAL = ScreenMonitoringExemption::REFUSAL;

    /** An active lead-developer staff account sharing this email — what makes the matching user exempt. */
    private function leadStaff(string $email, bool $active = true, string $tier = PlatformStaff::TIER_LEAD): PlatformStaff
    {
        return PlatformStaff::create([
            'name' => 'Dev '.$email,
            'email' => $email,
            'password' => 'a-sufficiently-long-staff-password',
            'tier' => $tier,
            'is_active' => $active,
        ]);
    }

    private function beat($user, string $deviceKey = 'dk-1', array $capabilities = ['screenshot']): void
    {
        $this->signedInAs($user)->postJson('/api/v1/monitoring/heartbeat', [
            'device_key' => $deviceKey,
            'platform' => 'windows',
            'capabilities' => $capabilities,
            'specs' => ['ram_gb' => 16],
        ])->assertNoContent();
    }

    private function deviceFor($user, string $deviceKey = 'dk-1'): MonitoredDevice
    {
        return MonitoredDevice::withoutGlobalScopes()
            ->where('user_id', $user->getKey())
            ->where('device_key', $deviceKey)
            ->firstOrFail();
    }

    private function frame(): UploadedFile
    {
        return UploadedFile::fake()->image('f.jpg', 100, 80);
    }

    private function seedShot($org, MonitoredDevice $device, $user): MonitoringScreenshot
    {
        return $this->inTenant($org, fn () => MonitoringScreenshot::create([
            'organization_id' => $org->id,
            'device_id' => $device->id,
            'user_id' => $user->getKey(),
            'captured_at' => now(),
            'path' => 'monitoring/screenshots/seed-'.$device->id.'.jpg',
            'bytes' => 100, 'width' => 800, 'height' => 600,
        ]));
    }

    // ── historical screenshots of an exempt device stay hidden ────────

    public function test_screenshots_of_an_exempt_device_are_never_listed_or_served(): void
    {
        // A capture from BEFORE the exemption shipped, seeded directly (the
        // ingest guard would refuse a fresh one). It must still never surface.
        $alpha = $this->provisionTenant('Alpha');
        $this->leadStaff($alpha['admin']->email);          // admin === the Lead Developer
        $ordinary = $this->userWithRole($alpha, 'Worker', []);

        $this->beat($alpha['admin']);
        $this->beat($ordinary, 'dk-2');
        $exemptShot = $this->seedShot($alpha['org'], $this->deviceFor($alpha['admin']), $alpha['admin']);
        $ordinaryShot = $this->seedShot($alpha['org'], $this->deviceFor($ordinary, 'dk-2'), $ordinary);

        // The admin (Lead Developer) can view others' screenshots, but not
        // their own exempt device's — and the ordinary one IS listed, so this
        // is an exemption, not a blanket empty list (the control).
        $ids = $this->signedInAs($alpha['admin'])
            ->getJson('/api/v1/monitoring/screenshots')
            ->assertOk()->json('data.*.id');
        $this->assertNotContains($exemptShot->id, $ids);
        $this->assertContains($ordinaryShot->id, $ids);

        // And a direct id fetch of the exempt shot 404s (the §3 "does not
        // exist" answer), while the ordinary one is found.
        $this->signedInAs($alpha['admin'])
            ->get('/api/v1/monitoring/screenshots/'.$exemptShot->id)
            ->assertNotFound();
    }

    // ── 1. heartbeat strips screenshot/control, keeps activity/specs ──

    public function test_an_exempt_users_beat_drops_screenshot_and_control_but_keeps_activity_and_specs(): void
    {
        $alpha = $this->provisionTenant('Alpha');
        $this->leadStaff($alpha['admin']->email);

        $this->beat($alpha['admin'], 'dk-1', ['activity', 'specs', 'screenshot', 'control']);

        $device = $this->deviceFor($alpha['admin']);
        $this->assertNotContains('screenshot', $device->capabilities);
        $this->assertNotContains('control', $device->capabilities);
        $this->assertContains('activity', $device->capabilities);
        $this->assertContains('specs', $device->capabilities);
        // Specs still land — PC Specs keeps showing the Lead Developer's box.
        $this->assertSame(['ram_gb' => 16], $device->specs);
    }

    public function test_the_shared_email_match_is_case_insensitive(): void
    {
        $alpha = $this->provisionTenant('Alpha');
        $this->leadStaff(strtoupper($alpha['admin']->email));

        $this->beat($alpha['admin'], 'dk-1', ['activity', 'screenshot']);

        $this->assertNotContains('screenshot', $this->deviceFor($alpha['admin'])->capabilities);
    }

    public function test_an_ordinary_users_beat_keeps_screenshot_the_control_case(): void
    {
        $alpha = $this->provisionTenant('Alpha');
        // No lead-developer staff shares this admin's email → not exempt.

        $this->beat($alpha['admin'], 'dk-1', ['activity', 'specs', 'screenshot', 'control']);

        $device = $this->deviceFor($alpha['admin']);
        $this->assertContains('screenshot', $device->capabilities);
        $this->assertContains('control', $device->capabilities);
    }

    // ── 2. screenshot ingest ──────────────────────────────────────────

    public function test_screenshot_ingest_is_refused_for_an_exempt_device_and_succeeds_for_an_ordinary_one(): void
    {
        $alpha = $this->provisionTenant('Alpha');
        $this->leadStaff($alpha['admin']->email);
        $ordinary = $this->userWithRole($alpha, 'Worker', []);

        $this->beat($alpha['admin']);
        $this->beat($ordinary);

        $this->signedInAs($alpha['admin'])
            ->post('/api/v1/monitoring/screenshots', ['image' => $this->frame(), 'device_key' => 'dk-1'])
            ->assertStatus(403)
            ->assertJsonPath('message', self::REFUSAL);

        $this->signedInAs($ordinary)
            ->post('/api/v1/monitoring/screenshots', ['image' => $this->frame(), 'device_key' => 'dk-1'])
            ->assertNoContent();

        // Nothing was ever written for the exempt device — exactly one row, the ordinary one.
        $this->assertSame(1, MonitoringScreenshot::withoutGlobalScopes()->count());
        $this->assertSame(
            $ordinary->getKey(),
            MonitoringScreenshot::withoutGlobalScopes()->value('user_id'),
        );
    }

    // ── 3. live view: upload, watch, show, live-wanted ────────────────

    public function test_live_frame_upload_is_refused_for_exempt_and_succeeds_for_ordinary(): void
    {
        $alpha = $this->provisionTenant('Alpha');
        $this->leadStaff($alpha['admin']->email);
        $ordinary = $this->userWithRole($alpha, 'Worker', []);
        $this->beat($alpha['admin']);
        $this->beat($ordinary);

        $this->signedInAs($alpha['admin'])
            ->post('/api/v1/monitoring/live-frame', ['image' => $this->frame(), 'device_key' => 'dk-1'])
            ->assertStatus(403)
            ->assertJsonPath('message', self::REFUSAL);

        $this->signedInAs($ordinary)
            ->post('/api/v1/monitoring/live-frame', ['image' => $this->frame(), 'device_key' => 'dk-1'])
            ->assertNoContent();
    }

    public function test_live_wanted_is_refused_for_exempt_and_answered_for_ordinary(): void
    {
        $alpha = $this->provisionTenant('Alpha');
        $this->leadStaff($alpha['admin']->email);
        $ordinary = $this->userWithRole($alpha, 'Worker', []);
        $this->beat($alpha['admin']);
        $this->beat($ordinary);

        $this->signedInAs($alpha['admin'])
            ->getJson('/api/v1/monitoring/live-wanted?device_key=dk-1')
            ->assertStatus(403)
            ->assertJsonPath('message', self::REFUSAL);

        $this->signedInAs($ordinary)
            ->getJson('/api/v1/monitoring/live-wanted?device_key=dk-1')
            ->assertOk()
            ->assertJson(['wanted' => false]);
    }

    public function test_watch_is_refused_against_an_exempt_device_and_succeeds_against_an_ordinary_one(): void
    {
        $alpha = $this->provisionTenant('Alpha');
        $this->leadStaff($alpha['admin']->email);
        $ordinary = $this->userWithRole($alpha, 'Worker', []);
        $viewer = $this->userWithRole($alpha, 'Seer', ['monitoring.screenshots.view']);
        $this->beat($alpha['admin']);
        $this->beat($ordinary);

        $exemptDevice = $this->deviceFor($alpha['admin']);
        $ordinaryDevice = $this->deviceFor($ordinary);

        $this->signedInAs($viewer)
            ->postJson('/api/v1/monitoring/devices/'.$exemptDevice->id.'/watch')
            ->assertStatus(403)
            ->assertJsonPath('message', self::REFUSAL);

        $this->signedInAs($viewer)
            ->postJson('/api/v1/monitoring/devices/'.$ordinaryDevice->id.'/watch')
            ->assertNoContent();
    }

    public function test_live_frame_show_is_refused_for_an_exempt_device(): void
    {
        $alpha = $this->provisionTenant('Alpha');
        $this->leadStaff($alpha['admin']->email);
        $viewer = $this->userWithRole($alpha, 'Seer', ['monitoring.screenshots.view']);
        $this->beat($alpha['admin']);
        $device = $this->deviceFor($alpha['admin']);

        $this->signedInAs($viewer)
            ->getJson('/api/v1/monitoring/devices/'.$device->id.'/live-frame')
            ->assertStatus(403)
            ->assertJsonPath('message', self::REFUSAL);
    }

    // ── 4. remote control: control, input, input-pending ──────────────

    public function test_control_is_refused_against_an_exempt_device_and_succeeds_against_an_ordinary_one(): void
    {
        $alpha = $this->provisionTenant('Alpha');
        $this->leadStaff($alpha['admin']->email);
        $ordinary = $this->userWithRole($alpha, 'Worker', []);
        $controller = $this->userWithRole($alpha, 'Controller', ['monitoring.control']);
        $this->beat($alpha['admin']);
        $this->beat($ordinary);

        $exemptDevice = $this->deviceFor($alpha['admin']);
        $ordinaryDevice = $this->deviceFor($ordinary);

        $this->signedInAs($controller)
            ->postJson('/api/v1/monitoring/devices/'.$exemptDevice->id.'/control')
            ->assertStatus(403)
            ->assertJsonPath('message', self::REFUSAL);

        $this->signedInAs($controller)
            ->postJson('/api/v1/monitoring/devices/'.$ordinaryDevice->id.'/control')
            ->assertNoContent();
    }

    public function test_input_is_refused_against_an_exempt_device_and_succeeds_against_an_ordinary_one(): void
    {
        $alpha = $this->provisionTenant('Alpha');
        $this->leadStaff($alpha['admin']->email);
        $ordinary = $this->userWithRole($alpha, 'Worker', []);
        $controller = $this->userWithRole($alpha, 'Controller', ['monitoring.control']);
        $this->beat($alpha['admin']);
        $this->beat($ordinary);

        $exemptDevice = $this->deviceFor($alpha['admin']);
        $ordinaryDevice = $this->deviceFor($ordinary);
        $events = ['events' => [['type' => 'mouseMove', 'x' => 1, 'y' => 2]]];

        $this->signedInAs($controller)
            ->postJson('/api/v1/monitoring/devices/'.$exemptDevice->id.'/input', $events)
            ->assertStatus(403)
            ->assertJsonPath('message', self::REFUSAL);

        $this->signedInAs($controller)
            ->postJson('/api/v1/monitoring/devices/'.$ordinaryDevice->id.'/input', $events)
            ->assertNoContent();
    }

    public function test_input_pending_is_refused_for_exempt_and_answered_for_ordinary(): void
    {
        $alpha = $this->provisionTenant('Alpha');
        $this->leadStaff($alpha['admin']->email);
        $ordinary = $this->userWithRole($alpha, 'Worker', []);
        $this->beat($alpha['admin']);
        $this->beat($ordinary);

        $this->signedInAs($alpha['admin'])
            ->getJson('/api/v1/monitoring/input-pending?device_key=dk-1')
            ->assertStatus(403)
            ->assertJsonPath('message', self::REFUSAL);

        $this->signedInAs($ordinary)
            ->getJson('/api/v1/monitoring/input-pending?device_key=dk-1')
            ->assertOk()
            ->assertJson(['events' => [], 'control_wanted' => false]);
    }

    // ── 5. the match conditions — inactive / wrong-tier / wrong-email ─

    public function test_an_inactive_lead_developer_does_not_exempt_the_user(): void
    {
        $alpha = $this->provisionTenant('Alpha');
        $this->leadStaff($alpha['admin']->email, active: false);

        $this->beat($alpha['admin'], 'dk-1', ['activity', 'screenshot']);

        // Inactive staff is no link at all → the beat keeps screenshot.
        $this->assertContains('screenshot', $this->deviceFor($alpha['admin'])->capabilities);
    }

    public function test_a_lead_developer_with_a_different_email_does_not_exempt_the_user(): void
    {
        $alpha = $this->provisionTenant('Alpha');
        $this->leadStaff('someone-else@ezbizi.test');

        $this->beat($alpha['admin'], 'dk-1', ['activity', 'screenshot']);

        $this->assertContains('screenshot', $this->deviceFor($alpha['admin'])->capabilities);
    }

    public function test_a_developer_tier_staff_with_the_same_email_does_not_exempt_the_user(): void
    {
        $alpha = $this->provisionTenant('Alpha');
        $this->leadStaff($alpha['admin']->email, tier: PlatformStaff::TIER_DEV);

        $this->beat($alpha['admin'], 'dk-1', ['activity', 'screenshot']);

        $this->assertContains('screenshot', $this->deviceFor($alpha['admin'])->capabilities);
    }

    // ── 5b. the flag on /me ───────────────────────────────────────────

    public function test_me_carries_the_exempt_flag_true_for_a_lead_and_false_for_an_ordinary_user(): void
    {
        $alpha = $this->provisionTenant('Alpha');
        $this->leadStaff($alpha['admin']->email);
        $ordinary = $this->userWithRole($alpha, 'Worker', []);

        $this->signedInAs($alpha['admin'])
            ->getJson('/api/v1/me')
            ->assertOk()
            ->assertJsonPath('data.screen_monitoring_exempt', true);

        $this->signedInAs($ordinary)
            ->getJson('/api/v1/me')
            ->assertOk()
            ->assertJsonPath('data.screen_monitoring_exempt', false);
    }

    // ── 6. the helper itself ──────────────────────────────────────────

    public function test_is_exempt_is_false_for_a_null_user(): void
    {
        $this->assertFalse(ScreenMonitoringExemption::isExempt(null));
    }

    public function test_is_exempt_is_true_for_a_user_matching_an_active_lead(): void
    {
        $alpha = $this->provisionTenant('Alpha');
        $this->leadStaff($alpha['admin']->email);

        $this->assertTrue(ScreenMonitoringExemption::isExempt($alpha['admin']->fresh()));
    }

    public function test_is_exempt_is_false_for_a_developer_tier_match(): void
    {
        $alpha = $this->provisionTenant('Alpha');
        $this->leadStaff($alpha['admin']->email, tier: PlatformStaff::TIER_DEV);

        $this->assertFalse(ScreenMonitoringExemption::isExempt($alpha['admin']->fresh()));
    }

    public function test_exempt_emails_lists_only_active_leads_lower_cased(): void
    {
        $this->leadStaff('Lead-One@EzBizi.Test');
        $this->leadStaff('lead-two@ezbizi.test');
        $this->leadStaff('inactive@ezbizi.test', active: false);
        $this->leadStaff('dev@ezbizi.test', tier: PlatformStaff::TIER_DEV);

        $emails = ScreenMonitoringExemption::exemptEmails();

        sort($emails);
        $this->assertSame(['lead-one@ezbizi.test', 'lead-two@ezbizi.test'], $emails);
    }
}
