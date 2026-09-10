<?php

namespace Tests\Feature\Monitoring;

use App\Models\MonitoredDevice;
use App\Models\PlatformStaff;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/**
 * `specs.active_window` — the title of the window in front on a desktop agent.
 *
 * It rides in the `specs` blob because the agent reads it in the same pass,
 * but it is NOT a spec: every other key there says what the MACHINE is, while
 * this one says what is ON it — a customer's name, a subject line, the job
 * somebody has open. So it is treated as screen content everywhere:
 *
 *   - it needs `monitoring.screenshots.view`, not `monitoring.specs.view`
 *     (DevicesController::present), and
 *   - an exempt device never reports it at all (HeartbeatController, and the
 *     agent itself in monitoring_heartbeat.dart).
 *
 * Every test here carries a POSITIVE CONTROL — a hardware key that must
 * survive the same call. Without one, a bug that dropped the whole `specs`
 * blob would pass as "the title was withheld".
 */
class ActiveWindowTest extends TestCase
{
    use BuildsTenants, RefreshDatabase;

    private const TITLE = 'EDIT JOB - Kowalski, 14 Elm St - EzBizi';

    public function test_the_title_needs_the_screenshot_grant_not_the_specs_grant(): void
    {
        $alpha = $this->provisionTenant('Alpha');
        $this->inTenant($alpha['org'], fn () => MonitoredDevice::factory()->create([
            'organization_id' => $alpha['org']->id,
            'user_id' => $alpha['admin']->getKey(),
            'capabilities' => ['activity', 'specs'],
            'specs' => ['ram_gb' => 31, 'active_window' => self::TITLE],
        ]));

        // Hardware only: may see what the machine IS, never what is on it.
        $hardware = $this->userWithRole($alpha, 'Hardware', [
            'monitoring.view', 'monitoring.specs.view',
        ]);
        $specs = $this->signedInAs($hardware)->getJson('/api/v1/monitoring/devices')
            ->assertOk()
            ->json('data.0.specs');

        $this->assertSame(31, $specs['ram_gb'], 'Positive control: the hardware '
            .'keys must survive, or "no title" only means "no specs at all".');
        $this->assertArrayNotHasKey('active_window', $specs);

        // The same row, read by somebody who may see the screen itself.
        $screen = $this->userWithRole($alpha, 'Screen', [
            'monitoring.view', 'monitoring.specs.view', 'monitoring.screenshots.view',
        ]);
        $withTitle = $this->signedInAs($screen)->getJson('/api/v1/monitoring/devices')
            ->assertOk()
            ->json('data.0.specs');

        // The control that makes the assertion above readable: the title IS
        // reachable on this row, so its absence for the hardware role is the
        // gate doing its job rather than a fixture that never had one.
        $this->assertSame(self::TITLE, $withTitle['active_window']);
    }

    public function test_an_exempt_device_never_stores_a_title_but_still_reports_its_hardware(): void
    {
        $alpha = $this->provisionTenant('Alpha');
        $lead = $alpha['admin'];

        // Same shape ScreenMonitoringExemptionTest uses: the exemption is
        // anchored on the EMAIL, because PlatformStaff is a separate identity
        // from an organization User and nothing joins them by id.
        PlatformStaff::create([
            'name' => 'Lead '.$lead->email,
            'email' => $lead->email,
            'password' => 'a-sufficiently-long-staff-password',
            'tier' => PlatformStaff::TIER_LEAD,
            'is_active' => true,
        ]);

        $this->signedInAs($lead)->postJson('/api/v1/monitoring/heartbeat', [
            'device_key' => 'key-lead',
            'platform' => 'windows',
            'capabilities' => ['activity', 'specs'],
            'specs' => ['ram_gb' => 31, 'active_window' => self::TITLE],
        ])->assertSuccessful();

        $stored = MonitoredDevice::withoutGlobalScopes()
            ->where('device_key', 'key-lead')->sole()->specs;

        $this->assertArrayNotHasKey('active_window', $stored,
            'A Lead Developer handles credentials; the title of what they have '
            .'open is exactly the thing the exemption exists to keep off the roster.');
        $this->assertSame(31, $stored['ram_gb'], 'Positive control: an exempt '
            .'device still reports its hardware and still shows as online — the '
            .'exemption draws a line, it does not switch the device off.');
    }

    public function test_a_device_that_is_not_exempt_does_store_the_title(): void
    {
        // The control for the test above. Without it, a heartbeat that dropped
        // `active_window` for EVERY device would look like a working exemption.
        $alpha = $this->provisionTenant('Alpha');

        $this->signedInAs($alpha['admin'])->postJson('/api/v1/monitoring/heartbeat', [
            'device_key' => 'key-ordinary',
            'platform' => 'windows',
            'capabilities' => ['activity', 'specs'],
            'specs' => ['ram_gb' => 16, 'active_window' => self::TITLE],
        ])->assertSuccessful();

        $stored = MonitoredDevice::withoutGlobalScopes()
            ->where('device_key', 'key-ordinary')->sole()->specs;

        $this->assertSame(self::TITLE, $stored['active_window']);
    }
}
