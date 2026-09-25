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

/** One GPS point from a tracked person's phone (Field). */
class FieldLocation extends Model implements HoldsPersonalData
{
    use BelongsToTenant;
    use HasUuids;

    protected $fillable = [
        'organization_id', 'user_id', 'latitude', 'longitude',
        'accuracy_m', 'speed_mps', 'heading', 'recorded_at',
    ];

    protected function casts(): array
    {
        return [
            'latitude' => 'float',
            'longitude' => 'float',
            'accuracy_m' => 'integer',
            'speed_mps' => 'float',
            'heading' => 'integer',
            'recorded_at' => 'datetime',
        ];
    }

    public static function personalDataPurpose(): string
    {
        return 'Where a tracked employee was, while their organization had them tracked, '
            .'for their safety and to answer "where was the technician" afterwards.';
    }

    public static function retention(): RetentionPolicy
    {
        return RetentionPolicy::window(3, 'recorded_at',
            'Ninety days of trail (Jose, 2026-09-25). Long enough to answer a complaint '
            .'about last month\'s visit; anything older is a record of where somebody '
            .'went, held for no working reason.'
        );
    }

    public static function personalDataHeld(): array
    {
        return [
            new PersonalDataEntry('user_id', PersonalDataEntry::IDENTITY,
                'Whose phone reported the point.',
                Erasure::drop('A location trail is nothing but the person; there is no '
                    .'business record left once it no longer says whose it was.')),
            new PersonalDataEntry('latitude, longitude, accuracy_m, speed_mps, heading, recorded_at',
                PersonalDataEntry::ACTIVITY,
                'Where they were, how precisely the phone knew it, which way they were heading, and when.',
                Erasure::drop('Goes with the row above: a position with no owner is still a trail somebody could re-identify.')),
        ];
    }

    public static function personalDataAbout(Model $subject): ?Builder
    {
        return $subject instanceof User
            ? static::query()->where('user_id', $subject->getKey())
            : null;
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
