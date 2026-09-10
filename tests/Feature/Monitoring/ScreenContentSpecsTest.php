<?php

namespace Tests\Feature\Monitoring;

use App\Models\MonitoredDevice;
use App\Models\PlatformStaff;
use App\Support\Monitoring\ScreenMonitoringExemption;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/**
 * The `specs` keys that are SCREEN CONTENT — `active_window` (the title of
 * whatever is in front) and `browser_tabs` (every open tab's title).
 *
 * They ride in the `specs` blob because the agent reads them in the same pass,
 * but they are not specs: every other key there says what the MACHINE is,
 * while these say what is ON it — a customer's name, a subject line, the job
 * somebody has open. So they are treated as screen content everywhere:
 *
 *   - they need `monitoring.screenshots.view`, not `monitoring.specs.view`
 *     (DevicesController::present), and
 *   - an exempt device never reports them (HeartbeatController, and the agent
 *     itself in monitoring_heartbeat.dart).
 *
 * Every test here LOOPS over ScreenMonitoringExemption::SCREEN_CONTENT_SPEC_KEYS
 * rather than naming the two keys, so a third key added to that list is
 * covered by these tests the day it is added instead of the day somebody
 * remembers. And every test carries a POSITIVE CONTROL — a hardware key that
 * must survive the same call — because without one, a bug that dropped the
 * whole `specs` blob would read exactly like a working gate.
 */
class ScreenContentSpecsTest extends TestCase
{
    use BuildsTenants, RefreshDatabase;

    /** Content for each screen-content key, shaped the way the agent sends it. */
    private function screenContent(): array
    {
        return [
            'active_window' => 'EDIT JOB - Kowalski, 14 Elm St - EzBizi',
            'browser_tabs' => ['Interviews - Indeed for Employers', 'A1 Chimney Mail'],
        ];
    }

    public function test_screen_content_needs_the_screenshot_grant_not_the_specs_grant(): void
    {
        $alpha = $this->provisionTenant('Alpha');
        $this->inTenant($alpha['org'], fn () => MonitoredDevice::factory()->create([
            'organization_id' => $alpha['org']->id,
            'user_id' => $alpha['admin']->getKey(),
            'capabilities' => ['activity', 'specs'],
            'specs' => ['ram_gb' => 31] + $this->screenContent(),
        ]));

        // Hardware only: may see what the machine IS, never what is on it.
        $hardware = $this->userWithRole($alpha, 'Hardware', [
            'monitoring.view', 'monitoring.specs.view',
        ]);
        $specs = $this->signedInAs($hardware)->getJson('/api/v1/monitoring/devices')
            ->assertOk()
            ->json('data.0.specs');

        $this->assertSame(31, $specs['ram_gb'], 'Positive control: the hardware '
            .'keys must survive, or "no screen content" only means "no specs at all".');

        foreach (ScreenMonitoringExemption::SCREEN_CONTENT_SPEC_KEYS as $key) {
            $this->assertArrayNotHasKey($key, $specs,
                "specs.$key is what is on the screen, so the hardware grant must not buy it.");
        }

        // The same row, read by somebody who may see the screen itself.
        $screen = $this->userWithRole($alpha, 'Screen', [
            'monitoring.view', 'monitoring.specs.view', 'monitoring.screenshots.view',
        ]);
        $withContent = $this->signedInAs($screen)->getJson('/api/v1/monitoring/devices')
            ->assertOk()
            ->json('data.0.specs');

        // The control that makes the assertions above readable: this content IS
        // reachable on this row, so its absence for the hardware role is the
        // gate working rather than a fixture that never carried any.
        foreach ($this->screenContent() as $key => $value) {
            $this->assertSame($value, $withContent[$key]);
        }
    }

    public function test_an_exempt_device_reports_no_screen_content_but_still_its_hardware(): void
    {
        $alpha = $this->provisionTenant('Alpha');
        $lead = $alpha['admin'];

        // The exemption is anchored on the EMAIL: PlatformStaff is a separate
        // identity from an organization User and nothing joins them by id.
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
            'specs' => ['ram_gb' => 31] + $this->screenContent(),
        ])->assertSuccessful();

        $stored = MonitoredDevice::withoutGlobalScopes()
            ->where('device_key', 'key-lead')->sole()->specs;

        foreach (ScreenMonitoringExemption::SCREEN_CONTENT_SPEC_KEYS as $key) {
            $this->assertArrayNotHasKey($key, $stored,
                "A Lead Developer handles credentials; specs.$key is exactly the "
                .'kind of thing the exemption exists to keep off the roster.');
        }

        $this->assertSame(31, $stored['ram_gb'], 'Positive control: an exempt '
            .'device still reports its hardware and still shows as online — the '
            .'exemption draws a line, it does not switch the device off.');
    }

    public function test_a_device_that_is_not_exempt_does_store_its_screen_content(): void
    {
        // The control for the test above. Without it, a heartbeat that dropped
        // these keys for EVERY device would look like a working exemption.
        $alpha = $this->provisionTenant('Alpha');

        $this->signedInAs($alpha['admin'])->postJson('/api/v1/monitoring/heartbeat', [
            'device_key' => 'key-ordinary',
            'platform' => 'windows',
            'capabilities' => ['activity', 'specs'],
            'specs' => ['ram_gb' => 16] + $this->screenContent(),
        ])->assertSuccessful();

        $stored = MonitoredDevice::withoutGlobalScopes()
            ->where('device_key', 'key-ordinary')->sole()->specs;

        foreach ($this->screenContent() as $key => $value) {
            $this->assertSame($value, $stored[$key]);
        }
    }

    public function test_the_roster_says_which_devices_are_exempt(): void
    {
        // Guards a trap rather than a feature: the exemption is anchored on the
        // EMAIL, and the roster eager-loads the user with an explicit column
        // list. Drop `email` from that list and this flag answers "nobody is
        // exempt" for everybody — silently, with no error and no failing query.
        // So the assertion that matters is the TRUE one.
        $alpha = $this->provisionTenant('Alpha');
        $lead = $alpha['admin'];

        PlatformStaff::create([
            'name' => 'Lead '.$lead->email,
            'email' => $lead->email,
            'password' => 'a-sufficiently-long-staff-password',
            'tier' => PlatformStaff::TIER_LEAD,
            'is_active' => true,
        ]);

        $ordinary = $this->userWithRole($alpha, 'Ordinary', ['monitoring.view']);

        foreach ([$lead, $ordinary] as $owner) {
            $this->inTenant($alpha['org'], fn () => MonitoredDevice::factory()->create([
                'organization_id' => $alpha['org']->id,
                'user_id' => $owner->getKey(),
                'device_key' => 'dk-'.$owner->getKey(),
                'capabilities' => ['activity', 'specs'],
            ]));
        }

        $rows = collect($this->signedInAs($ordinary)
            ->getJson('/api/v1/monitoring/devices')->assertOk()->json('data'))
            ->keyBy(fn (array $row) => $row['user']['id']);

        $this->assertTrue($rows[$lead->getKey()]['screen_exempt'],
            'A Lead Developer device must be reported as exempt — if this is '
            .'false, the user relation is probably not selecting `email`.');

        // The control: without it, a flag hardcoded to true would pass above.
        $this->assertFalse($rows[$ordinary->getKey()]['screen_exempt']);
    }

    public function test_every_named_screen_content_key_is_exercised_by_this_file(): void
    {
        // A guard on the loops above: they iterate the constant, so they are
        // only meaningful while this file actually SENDS every key the constant
        // names. Add a third key to the constant and forget the fixture, and
        // the loops would still pass while testing nothing about it.
        $this->assertSame(
            ScreenMonitoringExemption::SCREEN_CONTENT_SPEC_KEYS,
            array_keys($this->screenContent()),
            'screenContent() must carry every key named as screen content, in '
            .'the same order — otherwise the loops in this file quietly stop '
            .'covering whatever was added.'
        );
    }
}
