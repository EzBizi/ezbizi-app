<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Support\Privacy\RetentionPolicy;
use App\Support\Privacy\StatesRetention;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A named inspection (the pointer). Screens and look live on the version.
 *
 * Same shape as Contract: this row is a name, a cover, and which immutable
 * version is current. Editing and publishing write a new version and repoint.
 * In-progress runs and frozen reports keep the version they started on.
 */
class InspectionDefinition extends Model implements StatesRetention
{
    use BelongsToTenant;
    use HasUuids;

    /**
     * The name is configuration. Versions and reports are the evidence, and they
     * outlive a rename. Cascade from the organization is what removes the row.
     */
    public static function retention(): RetentionPolicy
    {
        return RetentionPolicy::withParent(
            'The named inspection as the organization currently offers it. Removing the '
            .'organization takes it. Published versions and submitted reports keep their '
            .'own copies of the questions and the name, so renaming or unpublishing here '
            .'does not rewrite anything already started or already submitted.'
        );
    }

    protected $fillable = [
        'organization_id', 'name', 'intro', 'cover_path', 'cover_disk',
        'current_version_id', 'published_at', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'published_at' => 'datetime',
        ];
    }

    public function versions(): HasMany
    {
        return $this->hasMany(InspectionDefinitionVersion::class)->orderByDesc('version');
    }

    public function currentVersion(): BelongsTo
    {
        return $this->belongsTo(InspectionDefinitionVersion::class, 'current_version_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function grants(): HasMany
    {
        return $this->hasMany(InspectionDefinitionGrant::class);
    }

    public function runs(): HasMany
    {
        return $this->hasMany(InspectionRun::class);
    }

    public function reports(): HasMany
    {
        return $this->hasMany(InspectionReport::class);
    }
}
