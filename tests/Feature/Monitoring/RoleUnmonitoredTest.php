<?php

namespace Tests\Feature\Monitoring;

use App\Models\MonitoredDevice;
use App\Models\MonitoringScreenshot;
use App\Support\Modules\PermissionCatalogue;
use App\Support\Monitoring\ScreenMonitoringExemption;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/**
 * Jose, 2026-11-17: "Lead Managers should not be desktop monitored as they may
 * handle financial information". A role holding `monitoring.unmonitored` is
 * exempt from screen monitoring, the same way the Lead Developer's device is;
 * the same role without it is the control in every pair.
 */
class RoleUnmonitoredTest extends TestCase
{
    use BuildsTenants, RefreshDatabase;

    private function beat($user): MonitoredDevice
    {
        $this->signedInAs($user)->postJson('/api/v1/monitoring/heartbeat', [
            'device_key' => 'dk-'.$user->getKey(),
            'platform' => 'windows',
            'capabilities' => ['activity', 'specs', 'screenshot', 'control'],
            'specs' => ['ram_gb' => 16],
        ])->assertNoContent();

        return MonitoredDevice::withoutGlobalScopes()->where('user_id', $user->getKey())->firstOrFail();
    }

    public function test_a_role_ticked_unmonitored_loses_screen_capture_and_the_plain_role_keeps_it(): void
    {
        $alpha = $this->provisionTenant('Alpha');
        $leadManager = $this->userWithRole($alpha, 'Lead Manager', ['leads.view', ScreenMonitoringExemption::UNMONITORED]);
        $worker = $this->userWithRole($alpha, 'Worker', ['leads.view']);

        $exempt = $this->beat($leadManager);
        $this->assertNotContains('screenshot', $exempt->capabilities);
        $this->assertNotContains('control', $exempt->capabilities);
        $this->assertContains('specs', $exempt->capabilities, 'Still online with PC specs.');

        $this->assertContains('screenshot', $this->beat($worker)->capabilities, 'The control.');

        $this->signedInAs($leadManager)
            ->post('/api/v1/monitoring/screenshots', [
                'image' => UploadedFile::fake()->image('f.jpg', 100, 80),
                'device_key' => 'dk-'.$leadManager->getKey(),
            ])
            ->assertStatus(403)
            ->assertJsonPath('message', ScreenMonitoringExemption::REFUSAL);
        $this->assertSame(0, MonitoringScreenshot::withoutGlobalScopes()->count());

        $this->signedInAs($leadManager)->getJson('/api/v1/me')->assertJsonPath('data.screen_monitoring_exempt', true);
        $this->signedInAs($worker)->getJson('/api/v1/me')->assertJsonPath('data.screen_monitoring_exempt', false);
    }

    public function test_the_roster_says_which_device_is_exempt(): void
    {
        $alpha = $this->provisionTenant('Alpha');
        $leadManager = $this->userWithRole($alpha, 'Lead Manager', [ScreenMonitoringExemption::UNMONITORED]);
        $worker = $this->userWithRole($alpha, 'Worker', []);
        $this->beat($leadManager);
        $this->beat($worker);

        $rows = collect($this->signedInAs($alpha['admin'])->getJson('/api/v1/monitoring/devices')->assertOk()->json('data'))
            ->keyBy('user.id');

        $this->assertTrue($rows[$leadManager->getKey()]['screen_exempt']);
        $this->assertFalse($rows[$worker->getKey()]['screen_exempt']);
    }

    public function test_administrators_stay_monitored_though_they_hold_every_permission(): void
    {
        $alpha = $this->provisionTenant('Alpha');

        $this->assertContains('screenshot', $this->beat($alpha['admin'])->capabilities);
    }

    public function test_being_unmonitored_does_not_open_the_monitoring_module(): void
    {
        $catalogue = app(PermissionCatalogue::class);

        $this->assertSame(['monitoring.unmonitored'], $catalogue->withImpliedView(['monitoring.unmonitored']));
        $this->assertSame(
            ['monitoring.control', 'monitoring.view'],
            $catalogue->withImpliedView(['monitoring.control']),
            'The control: an ordinary action still implies the view.',
        );
    }
}
