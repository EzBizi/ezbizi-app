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
 *
 * ── Why this never creates the device ─────────────────────────────
 *
 * Device creation, its `capabilities`, and the `monitoring.new_device_seen`
 * emission all belong in exactly one place: `HeartbeatController`. This
 * controller and the heartbeat share the same `device_key` from the same
 * desktop app, which heartbeats every 60s from launch while the first
 * screenshot capture is ~5 minutes in — so by the time a screenshot can
 * possibly land, a heartbeat already has. An earlier version of this
 * controller bootstrapped the device defensively for a case that cannot
 * happen, and that bootstrap was wrong twice over: it guessed
 * `capabilities = ['screenshot']`, which the heartbeat's later
 * `fill(['capabilities' => ...])` can clobber, and it never emitted
 * `monitoring.new_device_seen`, so a screenshot-first device silently
 * bypassed that automation trigger. Requiring the device to already exist
 * removes both failure modes instead of patching either one.
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
        // MUST land on the identical row a heartbeat would, never a
        // divergent one. Not found is a real rejection, not a row to create:
        // see the class docblock for why.
        $device = MonitoredDevice::query()->where([
            'organization_id' => $organizationId,
            'user_id' => $userId,
            'device_key' => $data['device_key'],
        ])->first();

        if ($device === null) {
            abort(404, 'No registered device for this session — send a heartbeat first.');
        }

        $image = $request->file('image');
        $bytes = file_get_contents($image->getRealPath());
        $dimensions = getimagesize($image->getRealPath());
        if ($bytes === false || $dimensions === false) {
            // The `image` rule already passed, so a read failure here means
            // the tmp upload vanished or is unreadable mid-request — that is
            // exceptional, not a valid zero-byte/zero-dimension row.
            abort(422, 'The uploaded image could not be read.');
        }

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
