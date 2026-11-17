<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Support\Privacy\Erasure;
use App\Support\Privacy\HoldsPersonalData;
use App\Support\Privacy\PersonalDataEntry;
use App\Support\Privacy\RetentionPolicy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A dash cam safety event (harsh brake, collision...). The clip stays with the
 * vendor: vendor_media_id is re-signed on play, never copied (TelematicsProviders::freshClipUrl).
 */
class CameraEvent extends Model implements HoldsPersonalData
{
    use BelongsToTenant, HasUuids;

    public const TYPES = ['harsh_brake', 'harsh_accel', 'harsh_turn', 'collision', 'distracted', 'speeding', 'other'];

    protected $fillable = [
        'organization_id', 'device_id', 'vehicle_id', 'driver_user_id', 'external_id', 'type', 'severity',
        'latitude', 'longitude', 'occurred_at', 'vendor_media_id', 'url', 'url_expires_at',
    ];

    protected $hidden = ['url'];

    protected function casts(): array
    {
        return ['latitude' => 'float', 'longitude' => 'float', 'occurred_at' => 'datetime', 'url_expires_at' => 'datetime'];
    }

    public static function personalDataPurpose(): string
    {
        return 'A driving event a dash cam reported, about whoever was driving.';
    }

    public static function retention(): RetentionPolicy
    {
        return RetentionPolicy::window(3, 'occurred_at', 'Ninety days, as for the trail; a claim needing longer is saved at the vendor.');
    }

    public static function personalDataHeld(): array
    {
        return [
            new PersonalDataEntry('driver_user_id, type, severity, latitude, longitude, occurred_at, vendor_media_id',
                PersonalDataEntry::ACTIVITY,
                'What the camera saw them do, where and when, and the id of the clip at the vendor.',
                Erasure::drop('The event is about the driver; the row goes.')),
        ];
    }

    public static function personalDataAbout(Model $subject): ?Builder
    {
        return $subject instanceof User ? static::query()->where('driver_user_id', $subject->getKey()) : null;
    }

    public function device(): BelongsTo
    {
        return $this->belongsTo(TelematicsDevice::class);
    }

    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class);
    }
}
