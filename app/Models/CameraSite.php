<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Support\Privacy\RetentionPolicy;
use App\Support\Privacy\StatesRetention;
use App\Support\Scoping\ViewScope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One physical place whose cameras reach EzBizi through a bridge PC
 * (docs/superpowers/specs/2026-10-20-camera-bridge-design.md).
 *
 * Configuration, not a record of a person: the device it links is the bridge
 * PC, and nothing here names an individual — so plain StatesRetention, the
 * same answer MonitoringCamera gives.
 */
class CameraSite extends Model implements StatesRetention
{
    use BelongsToTenant, HasUuids;

    /** A bridge checks in every minute; two missed beats is offline. */
    public const ONLINE_SECONDS = 120;

    protected $fillable = [
        'organization_id', 'company_id', 'franchise_id', 'name', 'device_id', 'created_by',
    ];

    protected $hidden = ['site_key_hash'];

    protected function casts(): array
    {
        return [
            'paired_at' => 'datetime',
            'last_checkin_at' => 'datetime',
            'status' => 'array',
        ];
    }

    public static function retention(): RetentionPolicy
    {
        return RetentionPolicy::forever(
            'A camera site is configuration: one row per physical place, removed when the '
            .'organization deletes the site.'
        );
    }

    public function online(): bool
    {
        return $this->last_checkin_at !== null
            && $this->last_checkin_at->gte(now()->subSeconds(self::ONLINE_SECONDS));
    }

    public function cameras(): HasMany
    {
        return $this->hasMany(MonitoringCamera::class, 'site_id');
    }

    public function device(): BelongsTo
    {
        return $this->belongsTo(MonitoredDevice::class, 'device_id');
    }

    /** Sites in the viewer's current view (company switcher), like other places. */
    public function scopeVisibleTo(Builder $query, User $viewer): Builder
    {
        return ViewScope::forUser($viewer)->constrainPlaces($query);
    }
}
