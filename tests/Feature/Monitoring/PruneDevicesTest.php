<?php

namespace Tests\Feature\Monitoring;

use App\Models\MonitoredDevice;
use App\Models\MonitoringActivity;
use App\Models\MonitoringScreenshot;
use App\Support\Monitoring\DevicePruner;
use App\Support\Monitoring\LiveFrameStore;
use App\Support\Monitoring\ScreenshotStore;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/**
 * `monitored_devices` is one-current-row-per-device and nothing trims it on its
 * own, so `ezbizi:prune-devices` (DevicePruner) is what stops the roster growing
 * forever. The load-bearing guarantee: a device silent past
 * DevicePruner::RETENTION_DAYS loses its row AND every file that belonged to it
 * (screenshot JPEGs, live frame) AND its child rows (screenshots, activity),
 * while a device seen inside the window loses nothing.
 */
class PruneDevicesTest extends TestCase
{
    use BuildsTenants, RefreshDatabase;

    public function test_prunes_devices_silent_past_the_window_and_keeps_recent_ones(): void
    {
        $alpha = $this->provisionTenant('Alpha');

        $stale       = $this->makeDevice($alpha, now()->subDays(31))['device']->id;
        $recent      = $this->makeDevice($alpha, now()->subDays(29))['device']->id;
        $justNow     = $this->makeDevice($alpha, now())['device']->id;
        // Never beat: judged by created_at instead, so it still ages out / survives.
        $neverOld    = $this->makeDevice($alpha, null, now()->subDays(40))['device']->id;
        $neverRecent = $this->makeDevice($alpha, null, now())['device']->id;

        $pruned = app(DevicePruner::class)->prune();

        $this->assertSame(2, $pruned);

        $this->inTenant($alpha['org'], function () use ($stale, $recent, $justNow, $neverOld, $neverRecent) {
            $this->assertNull(MonitoredDevice::find($stale));
            $this->assertNull(MonitoredDevice::find($neverOld));

            $this->assertNotNull(MonitoredDevice::find($recent));
            $this->assertNotNull(MonitoredDevice::find($justNow));
            $this->assertNotNull(MonitoredDevice::find($neverRecent));
        });
    }

    public function test_pruning_a_stale_device_removes_its_rows_and_files_and_leaves_a_recent_device_whole(): void
    {
        $alpha = $this->provisionTenant('Alpha');

        $stale  = $this->makeDevice($alpha, now()->subDays(31));
        $recent = $this->makeDevice($alpha, now()->subDays(2));

        // Everything is on disk to begin with.
        $this->assertFileExists($stale['shotAbs']);
        $this->assertFileExists($stale['frameAbs']);
        $this->assertFileExists($recent['shotAbs']);
        $this->assertFileExists($recent['frameAbs']);

        app(DevicePruner::class)->prune();

        // The stale device: row, screenshot row + JPEG, live frame, activity — all gone.
        $this->assertFileDoesNotExist($stale['shotAbs']);
        $this->assertFileDoesNotExist($stale['frameAbs']);

        $this->inTenant($alpha['org'], function () use ($stale, $recent) {
            $this->assertNull(MonitoredDevice::find($stale['device']->id));
            $this->assertNull(MonitoringScreenshot::find($stale['shot']->id));
            $this->assertSame(0, MonitoringScreenshot::where('device_id', $stale['device']->id)->count());
            $this->assertSame(0, MonitoringActivity::where('device_id', $stale['device']->id)->count());

            // The recent device is untouched: its rows survive...
            $this->assertNotNull(MonitoredDevice::find($recent['device']->id));
            $this->assertNotNull(MonitoringScreenshot::find($recent['shot']->id));
            $this->assertSame(1, MonitoringActivity::where('device_id', $recent['device']->id)->count());
        });

        // ...and so do its files.
        $this->assertFileExists($recent['shotAbs']);
        $this->assertFileExists($recent['frameAbs']);
    }

    public function test_a_stale_device_in_one_tenant_is_pruned_without_touching_another(): void
    {
        $alpha = $this->provisionTenant('Alpha');
        $beta  = $this->provisionTenant('Beta');

        $alphaStale = $this->makeDevice($alpha, now()->subDays(31));
        $betaStale  = $this->makeDevice($beta, now()->subDays(31));

        $pruned = app(DevicePruner::class)->prune();

        // Both orgs' stale devices go, but each only touches its own rows/files.
        $this->assertSame(2, $pruned);

        $this->inTenant($alpha['org'], fn () => $this->assertNull(MonitoredDevice::find($alphaStale['device']->id)));
        $this->inTenant($beta['org'], fn () => $this->assertNull(MonitoredDevice::find($betaStale['device']->id)));

        // Now prove isolation directly: a stale device in A, an untouched recent
        // device in B, and pruning must leave B's device and its files whole.
        $alpha2      = $this->provisionTenant('Alpha2');
        $beta2       = $this->provisionTenant('Beta2');
        $aStale      = $this->makeDevice($alpha2, now()->subDays(31));
        $bRecent     = $this->makeDevice($beta2, now());

        app(DevicePruner::class)->prune();

        $this->inTenant($alpha2['org'], fn () => $this->assertNull(MonitoredDevice::find($aStale['device']->id)));
        $this->inTenant($beta2['org'], fn () => $this->assertNotNull(MonitoredDevice::find($bRecent['device']->id)));
        $this->assertFileExists($bRecent['shotAbs']);
        $this->assertFileExists($bRecent['frameAbs']);
    }

    public function test_a_read_only_organization_is_still_pruned_and_does_not_stop_the_others(): void
    {
        // 2026-11-25 04:31 UTC: a lapsed subscription's read-only guard refused
        // the delete and the whole command exited 1. Retention is still owed.
        $lapsed = $this->provisionTenant('Lapsed');
        $active = $this->provisionTenant('Active');
        $lapsedStale = $this->makeDevice($lapsed, now()->subDays(31))['device']->id;
        $activeStale = $this->makeDevice($active, now()->subDays(31))['device']->id;
        $lapsed['org']->forceFill(['is_active' => false])->save();
        \App\Support\Subscriptions\SubscriptionGuard::flush();
        $this->app->forgetScopedInstances();
        $this->assertTrue($lapsed['org']->fresh()->isReadOnly(), 'the control: really read-only');

        $this->assertSame(2, app(DevicePruner::class)->prune());

        $this->inTenant($lapsed['org'], fn () => $this->assertNull(MonitoredDevice::find($lapsedStale)));
        $this->inTenant($active['org'], fn () => $this->assertNull(MonitoredDevice::find($activeStale)));
    }

    public function test_the_sweep_is_idempotent_and_self_healing(): void
    {
        $alpha = $this->provisionTenant('Alpha');

        $stale = $this->makeDevice($alpha, now()->subDays(31));

        // Self-healing: a file already gone before the sweep (a prior half-run,
        // or a manual cleanup) must not make pruning error.
        @unlink($stale['shotAbs']);
        @unlink($stale['frameAbs']);
        $this->assertFileDoesNotExist($stale['shotAbs']);

        // Run through the command itself, so its wiring is exercised too.
        $this->artisan('ezbizi:prune-devices')
            ->expectsOutputToContain('Pruned 1 device(s).')
            ->assertSuccessful();

        $this->inTenant($alpha['org'], function () use ($stale) {
            $this->assertNull(MonitoredDevice::find($stale['device']->id));
            $this->assertSame(0, MonitoringScreenshot::where('device_id', $stale['device']->id)->count());
            $this->assertSame(0, MonitoringActivity::where('device_id', $stale['device']->id)->count());
        });

        // Idempotent: nothing stale is left, so a second run is a no-op.
        $this->assertSame(0, app(DevicePruner::class)->prune());
    }

    /**
     * A device with one screenshot (row + JPEG on disk), a live frame on disk,
     * and one activity row. `last_seen_at` (and, for the never-beat case,
     * `created_at`) are set to make the device stale or fresh as the test needs.
     *
     * @param  array{org: \App\Models\Organization, admin: \App\Models\User}  $tenant
     * @return array{device: MonitoredDevice, shot: MonitoringScreenshot, activity: MonitoringActivity, shotAbs: string, frameAbs: string}
     */
    private function makeDevice(array $tenant, ?Carbon $lastSeenAt, ?Carbon $createdAt = null): array
    {
        return $this->inTenant($tenant['org'], function () use ($tenant, $lastSeenAt, $createdAt) {
            $device = MonitoredDevice::factory()->create([
                'organization_id' => $tenant['org']->id,
                'user_id'         => $tenant['admin']->getKey(),
                'last_seen_at'    => $lastSeenAt,
            ]);

            // created_at is not fillable and updateTimestamps() won't move it on
            // an existing row, so push it into the past quietly to model a device
            // that was inserted long ago and never checked in.
            if ($createdAt !== null) {
                $device->forceFill(['created_at' => $createdAt])->saveQuietly();
            }

            $store  = app(ScreenshotStore::class);
            $frames = app(LiveFrameStore::class);

            // captured_at is NOW on purpose: a fresh screenshot the screenshot
            // prune would keep, so this proves the DEVICE prune removes it purely
            // because the device it belongs to is stale.
            $shotPath = $store->put($tenant['org']->id, $device->id, '2020-01-01T00-00-00', 'SHOT-BYTES');
            $shot = MonitoringScreenshot::create([
                'organization_id' => $tenant['org']->id, 'device_id' => $device->id,
                'user_id' => $tenant['admin']->getKey(), 'captured_at' => now(),
                'path' => $shotPath, 'bytes' => 9, 'width' => 1, 'height' => 1,
            ]);

            $frames->put($tenant['org']->id, $device->id, 'FRAME-BYTES');

            $activity = MonitoringActivity::factory()->create([
                'organization_id' => $tenant['org']->id, 'device_id' => $device->id,
                'user_id' => $tenant['admin']->getKey(),
            ]);

            return [
                'device'   => $device,
                'shot'     => $shot,
                'activity' => $activity,
                'shotAbs'  => $store->absolute($shotPath),
                'frameAbs' => $frames->absolute($tenant['org']->id, $device->id),
            ];
        });
    }
}
