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
 * A tracked person's latest position and the evaluator's memory of them:
 * where they last stood still, which job they are at, and which alerts have
 * already fired, so each fires once per episode.
 */
class FieldPresence extends Model implements HoldsPersonalData
{
    use BelongsToTenant;
    use \App\Support\Live\RecordsParentChanges;

    /** Where a technician is (moving, stopped, signal lost) is that person's change on the live feed (RecordsParentChanges). */
    protected static array $changeParent = ['field', User::class, 'user_id'];

    use HasUuids;

    protected $fillable = [
        'organization_id', 'user_id', 'latitude', 'longitude', 'accuracy_m', 'last_seen_at',
        'anchor_latitude', 'anchor_longitude', 'anchored_at', 'at_job_id',
        'stopped_alerted', 'signal_lost_alerted',
        'battery_pct', 'charging', 'platform', 'os_version', 'device_model', 'app_version',
    ];

    protected function casts(): array
    {
        return [
            'latitude' => 'float',
            'longitude' => 'float',
            'anchor_latitude' => 'float',
            'anchor_longitude' => 'float',
            'accuracy_m' => 'integer',
            'last_seen_at' => 'datetime',
            'anchored_at' => 'datetime',
            'stopped_alerted' => 'boolean',
            'signal_lost_alerted' => 'boolean',
            'battery_pct' => 'integer',
            'charging' => 'boolean',
        ];
    }

    public static function personalDataPurpose(): string
    {
        return 'The latest position of a tracked employee, which is what the Field map draws.';
    }

    public static function retention(): RetentionPolicy
    {
        return RetentionPolicy::window(3, 'last_seen_at',
            'The same ninety days as the trail it summarises. A person not seen for that '
            .'long is not on the map, and their last position is history nobody asked to keep.'
        );
    }

    public static function personalDataHeld(): array
    {
        return [
            new PersonalDataEntry(
                'user_id, latitude, longitude, accuracy_m, last_seen_at, anchor_latitude, anchor_longitude, anchored_at, at_job_id',
                PersonalDataEntry::ACTIVITY,
                'Where they were last seen, where they last stood still, and the job they were at.',
                Erasure::drop('A position is only about the person; the row goes.')),
            new PersonalDataEntry('battery_pct, charging, platform, os_version, device_model, app_version',
                PersonalDataEntry::TECHNICAL,
                'The phone that is reporting: its battery, operating system, model and EzBizi version, so a manager can tell a dying phone from a lost signal.',
                Erasure::drop('Goes with the row: a phone description on its own says nothing useful once nobody owns it.')),
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

    public function atJob(): BelongsTo
    {
        return $this->belongsTo(Job::class, 'at_job_id');
    }
}
