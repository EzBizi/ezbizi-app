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

/** Where a van was. Personal data: it says where its driver was. */
class VehiclePosition extends Model implements HoldsPersonalData
{
    use BelongsToTenant, HasUuids;

    protected $fillable = ['organization_id', 'device_id', 'vehicle_id', 'driver_user_id', 'latitude', 'longitude', 'speed_mph', 'ignition_on', 'recorded_at'];

    protected function casts(): array
    {
        return ['latitude' => 'float', 'longitude' => 'float', 'ignition_on' => 'boolean', 'recorded_at' => 'datetime'];
    }

    public static function personalDataPurpose(): string
    {
        return "A van's trail, which says where whoever had its keys was driving.";
    }

    public static function retention(): RetentionPolicy
    {
        return RetentionPolicy::window(3, 'recorded_at', "The same ninety days as a technician's phone trail.");
    }

    public static function personalDataHeld(): array
    {
        return [
            new PersonalDataEntry('driver_user_id, latitude, longitude, speed_mph, ignition_on, recorded_at',
                PersonalDataEntry::ACTIVITY,
                'Where and how fast the van they were assigned was driving.',
                Erasure::drop('A position with its driver is only about that person; the row goes.')),
        ];
    }

    public static function personalDataAbout(Model $subject): ?Builder
    {
        return $subject instanceof User ? static::query()->where('driver_user_id', $subject->getKey()) : null;
    }
}
