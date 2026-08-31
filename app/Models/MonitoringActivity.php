<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Support\Privacy\Erasure;
use App\Support\Privacy\HoldsPersonalData;
use App\Support\Privacy\PersonalDataEntry;
use App\Support\Privacy\RetentionPolicy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * One append-only row: which tab a person was in, and for how long, this beat.
 *
 * Unlike MonitoredDevice's one-current-row-per-device, this is a log — every
 * beat writes a new row rather than overwriting the last one, which is what the
 * three-month sweep exists to bound.
 */
class MonitoringActivity extends Model implements HoldsPersonalData
{
    use BelongsToTenant, HasFactory, HasUuids;

    /** The migration names it singular, as an append log rather than a set of rows. */
    protected $table = 'monitoring_activity';

    public $timestamps = false;

    protected $fillable = [
        'organization_id', 'device_id', 'user_id', 'module_key',
        'dwell_seconds', 'occurred_at',
    ];

    protected $casts = [
        'occurred_at'   => 'datetime',
        'dwell_seconds' => 'integer',
    ];

    public static function retention(): RetentionPolicy
    {
        return RetentionPolicy::window(3, 'occurred_at',
            'A three-month view of which tabs a person worked in, then gone.');
    }

    public static function personalDataPurpose(): string
    {
        return 'How long each person spent in each tab, so an organization can see how '
            .'its people work.';
    }

    /** @return PersonalDataEntry[] */
    public static function personalDataHeld(): array
    {
        return [
            new PersonalDataEntry('module_key', PersonalDataEntry::ACTIVITY,
                'The tab a person was in for a stretch of time.',
                Erasure::drop('The whole row is a person\'s activity; it is removed.')),
        ];
    }

    public static function personalDataAbout(Model $subject): ?Builder
    {
        return $subject instanceof User
            ? static::query()->where('user_id', $subject->getKey())
            : null;
    }
}
