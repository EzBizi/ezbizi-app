<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Support\Privacy\RetentionPolicy;
use App\Support\Privacy\StatesRetention;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * The original of one newsletter import (P1c, 2026-11-18): the HTML or SVG
 * exactly as it was pasted or uploaded, and what the import made of it. Kept
 * so the original can be compared and imported again after the draft moved on.
 */
class NewsletterImport extends Model implements StatesRetention
{
    use BelongsToTenant;
    use HasUuids;

    public const KIND_HTML = 'html';

    public const KIND_SVG = 'svg';

    protected $fillable = ['scope_type', 'scope_id', 'newsletter_id', 'kind', 'mode', 'original', 'summary', 'created_by'];

    protected function casts(): array
    {
        return ['summary' => 'array'];
    }

    public static function retention(): RetentionPolicy
    {
        return RetentionPolicy::forever(
            'The original HTML or SVG design somebody imported into a newsletter, and a summary of what the '
            .'import made of it, kept so the import can be compared or redone. It goes with the organization.'
        );
    }
}
