<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Support\Privacy\RetentionPolicy;
use App\Support\Privacy\StatesRetention;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A camera registered by its stream URL — generic-protocol v1
 * (docs/superpowers/specs/2026-09-01-monitoring-cameras-design.md).
 *
 * An organization ASSET, not data about a person: unlike MonitoringScreenshot
 * (a picture OF somebody, reach-scoped through the capturing device's owner)
 * or MonitoredDevice (who is signed in from what), a camera names no
 * individual. So this deliberately implements plain `StatesRetention`
 * rather than `HoldsPersonalData` — the §9 purpose/held/erasure machinery
 * has nothing to describe here — and CamerasController applies no §3
 * per-person reach: `monitoring.cameras.view`/`.manage` (BelongsToTenant's
 * tenant scope) is the whole boundary, the same shape ServiceArea uses for
 * "where this franchise works".
 *
 * No credentials column. // ponytail: auth'd streams + a server-side media
 * proxy are v2 — see the migration's note and the design doc's Deferred
 * section.
 */
class MonitoringCamera extends Model implements StatesRetention
{
    use BelongsToTenant, HasUuids;

    protected $table = 'monitoring_cameras';

    protected $fillable = [
        'organization_id', 'label', 'protocol', 'url', 'enabled', 'created_by',
        // Camera bridge (2026-10-20): a camera can come through a site's bridge.
        'site_id', 'source', 'channel', 'capabilities', 'bridge_status', 'bridge_reason',
    ];

    protected $casts = [
        'enabled' => 'boolean',
        'capabilities' => 'array',
    ];

    /** The DB default, so a freshly created model reads the same as a stored one. */
    protected $attributes = [
        'source' => 'url',
    ];

    public function site(): BelongsTo
    {
        return $this->belongsTo(CameraSite::class, 'site_id');
    }

    /**
     * What a viewer may see. A site-less camera (a public URL, the Ring
     * cameras) is an organization asset, as it always was. A site camera
     * follows its site's company/franchise through the viewer's current view.
     */
    public function scopeVisibleTo(Builder $query, User $viewer): Builder
    {
        return $query->where(fn (Builder $q) => $q
            ->whereNull('site_id')
            ->orWhereHas('site', fn (Builder $s) => $s->visibleTo($viewer)));
    }

    public static function retention(): RetentionPolicy
    {
        return RetentionPolicy::forever(
            "A camera's stream URL is configuration, not a record of anything that "
            .'happened — it stays as long as the organization keeps the camera registered, '
            .'the same answer ServiceArea gives for where a franchise works.'
        );
    }
}
