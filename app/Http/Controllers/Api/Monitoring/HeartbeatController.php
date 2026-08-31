<?php

namespace App\Http\Controllers\Api\Monitoring;

use App\Http\Controllers\Api\Controller;
use App\Models\MonitoredDevice;
use App\Models\MonitoringActivity;
use App\Support\Automations\EventEmitter;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;

/**
 * A person's own device reporting its presence (2026-08-28-monitoring-pc-specs).
 *
 * Deliberately not gated by a permission: this is not "view another device",
 * it is a device reporting itself, the way PhonePresence and PhoneTokens are.
 * `routes/api.php` keeps this OUTSIDE the `idle` middleware group — see the
 * comment there for why a timer-driven beat must never slide the idle window.
 */
class HeartbeatController extends Controller
{
    private const PLATFORMS = ['web', 'android', 'ios', 'windows', 'macos', 'linux'];

    private const CAPABILITIES = ['activity', 'specs', 'screenshot', 'control'];

    public function __construct(
        private readonly EventEmitter $events,
        private readonly TenantContext $tenant,
    ) {}

    public function store(Request $request): Response
    {
        $data = $request->validate([
            'device_key'          => ['required', 'string', 'max:64'],
            'platform'            => ['required', Rule::in(self::PLATFORMS)],
            'capabilities'        => ['required', 'array'],
            'capabilities.*'      => [Rule::in(self::CAPABILITIES)],
            'label'               => ['nullable', 'string', 'max:120'],
            'current_module'      => ['nullable', 'string', 'max:64'],
            'specs'               => ['nullable', 'array'],
            'segment'             => ['nullable', 'array'],
            'segment.module_key'  => ['required_with:segment', 'string', 'max:64'],
            'segment.dwell_seconds' => ['required_with:segment', 'integer', 'min:0'],
        ]);

        $keepSpecs = in_array('specs', $data['capabilities'], true);

        $device = MonitoredDevice::query()->firstOrNew([
            'organization_id' => $this->tenant->idOrFail(),
            'user_id'         => $request->user()->getKey(),
            'device_key'      => $data['device_key'],
        ]);
        $isNew = ! $device->exists;

        $device->fill([
            'label'          => $data['label'] ?? $device->label,
            'platform'       => $data['platform'],
            'capabilities'   => $data['capabilities'],
            'user_agent'     => substr((string) $request->userAgent(), 0, 400),
            'ip_address'     => $request->ip(),
            'last_seen_at'   => now(),
            'current_module' => $data['current_module'] ?? $device->current_module,
            'offline_alerted_at' => null, // a beat re-arms the offline alert
        ]);
        if ($keepSpecs && isset($data['specs'])) {
            $device->specs = $data['specs'];
        }
        $device->save();

        if (isset($data['segment'])) {
            MonitoringActivity::create([
                'organization_id' => $this->tenant->idOrFail(),
                'device_id'       => $device->id,
                'user_id'         => $request->user()->getKey(),
                'module_key'      => $data['segment']['module_key'],
                'dwell_seconds'   => $data['segment']['dwell_seconds'],
                'occurred_at'     => now(),
            ]);
        }

        if ($isNew) {
            $this->events->emit('monitoring.new_device_seen', $device, [
                'platform' => $device->platform,
                'label'    => $device->label,
            ]);
        }

        return response()->noContent();
    }
}
