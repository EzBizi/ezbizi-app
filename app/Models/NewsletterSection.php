<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Support\Privacy\RetentionPolicy;
use App\Support\Privacy\StatesRetention;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A saved newsletter section (P1b, 2026-11-18): named blocks a company
 * reuses. Inserted as a copy (independent blocks) or linked (a `section_ref`
 * block pinning a version). Publishing a change writes a new version row; a
 * linked newsletter keeps its pinned version until somebody updates it.
 */
class NewsletterSection extends Model implements StatesRetention
{
    use BelongsToTenant;
    use HasUuids;
    use SoftDeletes;

    public const MODE_COPY = 'copy';

    public const MODE_LINKED = 'linked';

    protected $fillable = ['scope_type', 'scope_id', 'name', 'blocks', 'mode', 'version', 'thumbnail_url', 'updated_by'];

    protected function casts(): array
    {
        return ['blocks' => 'array', 'version' => 'integer'];
    }

    public static function retention(): RetentionPolicy
    {
        return RetentionPolicy::forever(
            'A reusable newsletter section the company saved: its name and content blocks, and every '
            .'published version so newsletters that link to it keep the version they were built with.'
        );
    }

    public function versions(): HasMany
    {
        return $this->hasMany(NewsletterSectionVersion::class);
    }
}
