<?php

namespace App\Support\Telematics;

use App\Models\CameraEvent;
use App\Models\TelematicsConnection;
use App\Models\TelematicsDevice;
use App\Models\VehiclePosition;
use App\Support\Automations\EventEmitter;
use Illuminate\Support\Carbon;
use InvalidArgumentException;

/**
 * The provider-agnostic side: every vendor's readings land here in one shape.
 * Vendors register in $providers; none is wired until its account exists.
 */
final class TelematicsProviders
{
    /** @var array<string, class-string<TelematicsProvider>> */
    public static array $providers = [];

    public function __construct(private readonly EventEmitter $events) {}

    public function for(TelematicsConnection $connection): TelematicsProvider
    {
        $class = self::$providers[$connection->provider]
            ?? throw new InvalidArgumentException("No telematics provider '{$connection->provider}'.");

        return app($class);
    }

    /** Connect flow: ask the vendor, list its devices, record the verdict. */
    public function check(TelematicsConnection $connection): TelematicsConnection
    {
        $result = $this->for($connection)->check($connection);
        foreach ($result['devices'] ?? [] as $d) {
            $connection->devices()->firstOrCreate(
                ['external_id' => $d['external_id']],
                ['organization_id' => $connection->organization_id, 'label' => $d['label'] ?? null, 'kind' => $d['kind'] ?? 'tracker'],
            );
        }
        $connection->forceFill([
            'status' => $result['ok'] ? 'connected' : 'failed',
            'status_detail' => $result['detail'] ?? null,
            'checked_at' => now(),
        ])->save();

        return $connection;
    }

    /** A reading: latest on the device, one row of trail, driver from the van's assignment. */
    public function ingestPosition(TelematicsDevice $device, array $r): void
    {
        $at = Carbon::parse($r['recorded_at'])->utc();
        if ($device->last_seen_at === null || $at->gte($device->last_seen_at)) {
            $device->forceFill(array_intersect_key($r, array_flip([
                'latitude', 'longitude', 'speed_mph', 'heading', 'ignition_on', 'odometer_mi', 'fuel_pct', 'fault_codes',
            ])) + ['last_seen_at' => $at])->save();
        }
        VehiclePosition::query()->create([
            'organization_id' => $device->organization_id,
            'device_id' => $device->getKey(),
            'vehicle_id' => $device->vehicle_id,
            'driver_user_id' => $this->driver($device),
            'latitude' => $r['latitude'],
            'longitude' => $r['longitude'],
            'speed_mph' => $r['speed_mph'] ?? null,
            'ignition_on' => $r['ignition_on'] ?? null,
            'recorded_at' => $at,
        ]);
    }

    /** A safety event; the same external id twice is one event. Emits monitoring.camera_event. */
    public function ingestCameraEvent(TelematicsDevice $device, array $e): CameraEvent
    {
        $event = CameraEvent::query()->firstOrNew(['device_id' => $device->getKey(), 'external_id' => $e['external_id']]);
        if ($event->exists) {
            return $event;
        }
        $event->fill([
            'organization_id' => $device->organization_id,
            'vehicle_id' => $device->vehicle_id,
            'driver_user_id' => $this->driver($device),
            'type' => in_array($e['type'], CameraEvent::TYPES, true) ? $e['type'] : 'other',
            'severity' => $e['severity'] ?? null,
            'latitude' => $e['latitude'] ?? null,
            'longitude' => $e['longitude'] ?? null,
            'occurred_at' => Carbon::parse($e['occurred_at'])->utc(),
            'vendor_media_id' => $e['vendor_media_id'] ?? null,
        ])->save();
        $this->events->emit('monitoring.camera_event', $event, [
            'type' => $event->type,
            'vehicle_id' => $event->vehicle_id,
            'driver_user_id' => $event->driver_user_id,
            'occurred_at' => $event->occurred_at->toIso8601ZuluString(),
            'severity' => $event->severity,
        ]);

        return $event;
    }

    /** The clip's URL, re-signed by the vendor when the cached one is stale. Nothing is stored but the link. */
    public function freshClipUrl(CameraEvent $event): ?array
    {
        if ($event->vendor_media_id === null) {
            return null;
        }
        if ($event->url === null || $event->url_expires_at === null || $event->url_expires_at->lte(now()->addMinute())) {
            $connection = $event->device->connection;
            $signed = $this->for($connection)->clipUrl($connection, $event);
            $event->forceFill(['url' => $signed['url'], 'url_expires_at' => $signed['expires_at']])->save();
        }

        return ['url' => $event->url, 'expires_at' => $event->url_expires_at->toIso8601ZuluString()];
    }

    private function driver(TelematicsDevice $device): ?string
    {
        return $device->vehicle?->openAssignment()?->user_id;
    }
}
