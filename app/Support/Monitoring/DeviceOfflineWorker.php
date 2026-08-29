<?php

namespace App\Support\Monitoring;

use App\Models\MonitoredDevice;
use App\Models\Organization;
use App\Support\Automations\EventEmitter;
use App\Support\Settings\SettingsResolver;
use App\Support\Tenancy\TenantContext;

/**
 * The scheduled scan behind `monitoring.device_offline` (task 6).
 *
 * Pattern: {@see \App\Support\Tasks\TaskDueWorker} behind DispatchTaskOverdue —
 * plucked organization ids, one `TenantContext::run()` per organization so
 * `EventEmitter::emit()` has a tenant, read-only organizations skipped because
 * this writes new data (a device's `offline_alerted_at`, an outbox row).
 *
 * `offline_alerted_at` is the once-per-episode guard: HeartbeatController
 * clears it on every beat (task 4), so a stale device is only ever selected
 * while that column is still null. Emitting sets it, which is what stops the
 * very next sweep re-firing for the same silence — and a fresh beat clearing it
 * again is what lets a LATER silence fire again.
 */
class DeviceOfflineWorker
{
    public function __construct(
        private readonly EventEmitter $events,
        private readonly TenantContext $tenant,
        private readonly SettingsResolver $settings,
    ) {}

    public function sweep(): int
    {
        $count = 0;

        foreach (Organization::query()->pluck('id') as $organizationId) {
            $organization = Organization::query()->find($organizationId);
            if ($organization === null || $organization->isReadOnly()) {
                continue;
            }

            $count += $this->tenant->run($organization, function () use ($organization): int {
                $seconds = (int) $this->settings->get('monitoring.offline_after_seconds', $organization);
                $cutoff = now()->subSeconds($seconds);

                $stale = MonitoredDevice::query()
                    ->whereNotNull('last_seen_at')
                    ->where('last_seen_at', '<', $cutoff)
                    ->whereNull('offline_alerted_at')
                    ->get();

                foreach ($stale as $device) {
                    $this->events->emit('monitoring.device_offline', $device, [
                        'last_seen_at' => $device->last_seen_at?->toIso8601String(),
                    ]);
                    $device->forceFill(['offline_alerted_at' => now()])->save();
                }

                return $stale->count();
            });
        }

        return $count;
    }
}
