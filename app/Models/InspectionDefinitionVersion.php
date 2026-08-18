<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\WrittenOnce;
use App\Support\Privacy\RetentionPolicy;
use App\Support\Privacy\StatesRetention;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use RuntimeException;

/**
 * An immutable published inspection: look, screens, and field list.
 *
 * A run pins the version it started on; a report pins the version it was
 * submitted against. Editing this row would rewrite the questions both claim
 * to have asked, which is the same reason ContractVersion is written once.
 */
class InspectionDefinitionVersion extends Model implements StatesRetention
{
    use BelongsToTenant;
    use HasUuids;
    use WrittenOnce;

    public const UPDATED_AT = null;

    public static function retention(): RetentionPolicy
    {
        return RetentionPolicy::forever(
            'Kept for as long as the organization exists. A version is the exact set of '
            .'screens and fields a run was pinned to, and deleting one would leave answers '
            .'already given to it unreadable. It grows one row per publish, which is a '
            .'human act.'
        );
    }

    protected $fillable = [
        'organization_id', 'inspection_definition_id', 'version',
        'layout_preset', 'screens', 'intro', 'cover_path', 'cover_disk',
        'published_by',
    ];

    protected function casts(): array
    {
        return [
            'screens' => 'array',
            'version' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function () {
            throw new RuntimeException(
                'Inspection versions are immutable. Changing a named inspection means '
                .'publishing a new version — editing this one would rewrite the questions '
                .'every run and report produced by it claims to have been asked.'
            );
        });

        static::deleting(function () {
            throw new RuntimeException(
                'A published inspection version cannot be deleted. Runs and reports name it, '
                .'and the answers they hold are unreadable without its screens. Publish a '
                .'new version instead; the old one stays because somebody already used it.'
            );
        });
    }

    public function definition(): BelongsTo
    {
        return $this->belongsTo(InspectionDefinition::class, 'inspection_definition_id');
    }

    public function publishedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'published_by');
    }
}
