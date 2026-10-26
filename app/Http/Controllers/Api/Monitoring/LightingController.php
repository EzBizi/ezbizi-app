<?php

namespace App\Http\Controllers\Api\Monitoring;

use App\Http\Controllers\Api\Controller;
use App\Models\MonitoredDevice;
use App\Support\Audit\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * RGB lighting on the organization's PCs (Jose, 2026-10-26). A manager sets
 * the wanted lighting on one PC or many; each PC's agent polls GET
 * monitoring/lighting with its device key and applies it through the bundled
 * OpenRGB (client: core/lighting/).
 */
class LightingController extends Controller
{
    public const MODES = ['off', 'static', 'breathing', 'rainbow'];

    public function __construct(private readonly AuditLogger $audit) {}

    /** PUT monitoring/devices/{id}/lighting */
    public function update(Request $request, string $id): JsonResponse
    {
        $this->authorize('lighting', MonitoredDevice::class);
        $wanted = $this->wanted($request);

        $device = MonitoredDevice::query()->visibleTo($request->user())->findOrFail($id);
        $device->forceFill(['lighting' => $wanted])->save();
        $this->audit->log('monitoring.lighting.changed', $device, [], ['mode' => $wanted['mode'], 'color' => $wanted['color']]);

        return response()->json(['data' => $wanted]);
    }

    /** POST monitoring/lighting {device_ids?: [...]} — none named means every PC in view. */
    public function bulk(Request $request): JsonResponse
    {
        $this->authorize('lighting', MonitoredDevice::class);
        $ids = $request->validate(['device_ids' => ['sometimes', 'array', 'max:500'], 'device_ids.*' => ['string']])['device_ids'] ?? null;
        $wanted = $this->wanted($request);

        $devices = MonitoredDevice::query()->visibleTo($request->user())
            ->when($ids !== null, fn ($q) => $q->whereIn('id', $ids))->get();
        foreach ($devices as $device) {
            $device->forceFill(['lighting' => $wanted])->save();
        }
        $this->audit->log('monitoring.lighting.bulk_changed', null, [], [
            'devices' => $devices->count(), 'mode' => $wanted['mode'], 'color' => $wanted['color'],
        ]);

        return response()->json(['data' => ['updated' => $devices->count(), 'lighting' => $wanted]]);
    }

    /** GET monitoring/lighting?device_key= — the PC's own agent, asking what to show. */
    public function pending(Request $request): array
    {
        $key = $request->validate(['device_key' => ['required', 'string', 'max:64']])['device_key'];
        $device = MonitoredDevice::query()->ownedBy($request->user(), $key)->firstOrFail();

        return ['lighting' => $device->lighting];
    }

    /** @return array{mode:string, color:?string, brightness:int, version:int, set_by:string} */
    private function wanted(Request $request): array
    {
        $data = $request->validate([
            'mode' => ['required', 'in:'.implode(',', self::MODES)],
            'color' => ['required_unless:mode,off,rainbow', 'nullable', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'brightness' => ['sometimes', 'integer', 'min:0', 'max:100'],
        ]);

        return [
            'mode' => $data['mode'],
            'color' => isset($data['color']) ? strtoupper($data['color']) : null,
            'brightness' => (int) ($data['brightness'] ?? 100),
            // The agent applies a version once; a new one means "apply again".
            'version' => (int) (microtime(true) * 1000),
            'set_by' => $request->user()->name,
        ];
    }
}
