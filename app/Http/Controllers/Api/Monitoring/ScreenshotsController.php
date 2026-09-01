<?php

namespace App\Http\Controllers\Api\Monitoring;

use App\Http\Controllers\Api\Controller;
use App\Models\MonitoredDevice;
use App\Models\MonitoringScreenshot;
use App\Support\Monitoring\ScreenshotStore;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * A device uploading its own periodic screen capture (2026-08-31-monitoring-screenshots).
 *
 * Deliberately not gated by a permission, same reasoning as HeartbeatController:
 * this is not "view another device's screenshots", it is a device reporting a
 * capture of itself. `routes/api.php` keeps this OUTSIDE the `idle` middleware
 * group next to the heartbeat — a background upload must not slide the
 * session idle window either.
 */
class ScreenshotsController extends Controller
{
    /**
     * A compressed primary-monitor JPEG runs ~60-120KB; this leaves headroom
     * for a multi-monitor or unusually busy capture without opening the door
     * to a multi-megabyte upload every capture interval.
     */
    private const MAX_KILOBYTES = 2048;

    public function __construct(
        private readonly ScreenshotStore $store,
        private readonly TenantContext $tenant,
    ) {}

    public function store(Request $request): Response
    {
        $data = $request->validate([
            'image' => ['required', 'file', 'image', 'mimes:jpeg', 'max:'.self::MAX_KILOBYTES],
            'device_key' => ['required', 'string', 'max:64'],
        ]);

        $organizationId = $this->tenant->idOrFail();
        $userId = $request->user()->getKey();

        // Same three-key resolution as HeartbeatController::store() — this
        // MUST land on the identical row a heartbeat would, never a divergent
        // one. It is only ever new here if a capture races ahead of this
        // device's first heartbeat; the FK to monitored_devices still needs a
        // row to point at, so it is created with the minimum a heartbeat
        // would have filled in rather than left dangling.
        $device = MonitoredDevice::query()->firstOrNew([
            'organization_id' => $organizationId,
            'user_id' => $userId,
            'device_key' => $data['device_key'],
        ]);
        if (! $device->exists) {
            $device->platform = 'unknown';
            $device->capabilities = ['screenshot'];
            $device->save();
        }

        $image = $request->file('image');
        $bytes = (string) file_get_contents($image->getRealPath());
        $dimensions = getimagesize($image->getRealPath()) ?: [0, 0];
        $capturedAt = now();

        $path = $this->store->put(
            $organizationId,
            $device->id,
            $capturedAt->toIso8601String(),
            $bytes,
        );

        MonitoringScreenshot::create([
            'organization_id' => $organizationId,
            'device_id' => $device->id,
            'user_id' => $userId,
            'captured_at' => $capturedAt,
            'path' => $path,
            'bytes' => strlen($bytes),
            'width' => $dimensions[0],
            'height' => $dimensions[1],
        ]);

        return response()->noContent();
    }
}
