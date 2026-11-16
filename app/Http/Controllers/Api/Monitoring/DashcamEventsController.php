<?php

namespace App\Http\Controllers\Api\Monitoring;

use App\Http\Controllers\Api\Controller;
use App\Models\CameraEvent;
use App\Models\MonitoringCamera;
use App\Models\User;
use App\Support\Audit\AuditLogger;
use App\Support\Inventory\InventoryVisibility;
use App\Support\Scoping\ViewScope;
use App\Support\Telematics\TelematicsProviders;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Dash cam safety events (Jose, 2026-11-16: "Connect FORD Car tracking & Dash
 * Cams"). Field ingests them into camera_events; this is the Monitoring side:
 * the list next to Ring and Wyze, and the clip, which stays with the vendor.
 *
 * Gate: monitoring.cameras.view, reached through the event's VEHICLE (its
 * company and franchise, Fleet's own rule). An event with no vehicle is not
 * listed: with no parent it has no reach to check (the null-parent hole).
 *
 * The clip is never copied (Jose 10-27, recordings): the client gets a vendor
 * link, re-signed only when stale, and each play is audited.
 */
class DashcamEventsController extends Controller
{
    public function __construct(
        private readonly TelematicsProviders $providers,
        private readonly AuditLogger $audit,
    ) {}

    /** GET monitoring/dashcam-events?vehicle_id&type&from&to&page */
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', MonitoringCamera::class);

        $data = $request->validate([
            'vehicle_id' => ['sometimes', 'nullable', 'uuid'],
            'type' => ['sometimes', 'nullable', Rule::in(CameraEvent::TYPES)],
            'from' => ['sometimes', 'nullable', 'date'],
            'to' => ['sometimes', 'nullable', 'date'],
        ]);

        $page = $this->reachable($request)
            ->with('vehicle:id,number,make,model')
            ->when($data['vehicle_id'] ?? null, fn (Builder $q, $id) => $q->where('vehicle_id', $id))
            ->when($data['type'] ?? null, fn (Builder $q, $t) => $q->where('type', $t))
            ->when($data['from'] ?? null, fn (Builder $q, $d) => $q->where('occurred_at', '>=', $d))
            ->when($data['to'] ?? null, fn (Builder $q, $d) => $q->where('occurred_at', '<=', $d))
            ->orderByDesc('occurred_at')
            ->paginate(50);

        $drivers = User::query()
            ->whereIn('id', collect($page->items())->pluck('driver_user_id')->filter()->unique())
            ->pluck('name', 'id');

        return response()->json([
            'data' => collect($page->items())->map(fn (CameraEvent $e) => [
                'id' => $e->id,
                'type' => $e->type,
                'severity' => $e->severity,
                'occurred_at' => $e->occurred_at->toIso8601ZuluString(),
                'latitude' => $e->latitude,
                'longitude' => $e->longitude,
                'vehicle' => $e->vehicle ? [
                    'id' => $e->vehicle->id,
                    'name' => trim("{$e->vehicle->number} {$e->vehicle->make} {$e->vehicle->model}"),
                ] : null,
                'driver' => isset($drivers[$e->driver_user_id])
                    ? ['id' => $e->driver_user_id, 'name' => $drivers[$e->driver_user_id]] : null,
                'has_clip' => $e->vendor_media_id !== null,
            ])->all(),
            'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'total' => $page->total()],
        ]);
    }

    /** POST monitoring/dashcam-events/{id}/clip-url → {url, expires_at} */
    public function clipUrl(Request $request, string $id): JsonResponse
    {
        $this->authorize('viewAny', MonitoringCamera::class);

        $event = $this->reachable($request)->findOrFail($id);
        $clip = $this->providers->freshClipUrl($event);

        if ($clip === null) {
            return response()->json(['message' => 'This event has no video.'], 404);
        }

        $this->audit->log('monitoring.dashcam.clip_viewed', $event, [], ['type' => $event->type]);

        return response()->json(['data' => $clip]);
    }

    private function reachable(Request $request): Builder
    {
        $user = $request->user();
        $scope = ViewScope::forUser($user);

        return CameraEvent::query()->whereHas(
            'vehicle',
            fn (Builder $v) => InventoryVisibility::constrain($v, $user, $scope),
        );
    }
}
