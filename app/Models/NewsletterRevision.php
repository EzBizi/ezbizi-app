<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Support\Privacy\RetentionPolicy;
use App\Support\Privacy\StatesRetention;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One saved version of a newsletter (P1a, 2026-11-17): what it said at that
 * revision, who saved it and when. Written on every successful save; a
 * restore copies an older row into a NEW revision, so nothing newer is lost.
 */
class NewsletterRevision extends Model implements StatesRetention
{
    use BelongsToTenant;

    /** The newest this many are kept per newsletter, plus every restore point. */
    public const KEEP = 50;

    public const UPDATED_AT = null;

    protected $fillable = [
        'organization_id', 'newsletter_id', 'revision', 'blocks', 'html_override', 'subject', 'name',
        'preheader', 'author_id', 'restore_point', 'label', 'restored_from',
    ];

    protected function casts(): array
    {
        return ['blocks' => 'array', 'revision' => 'integer', 'restore_point' => 'boolean', 'restored_from' => 'integer'];
    }

    public static function retention(): RetentionPolicy
    {
        return RetentionPolicy::withParent(
            'An earlier saved version of a newsletter the organization wrote: its subject, blocks and who '
            .'saved it. The newest 50 per newsletter are kept (plus versions marked as restore points), and '
            .'they go with the newsletter when it is deleted.'
        );
    }

    public function newsletter(): BelongsTo
    {
        return $this->belongsTo(Newsletter::class);
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_id');
    }
}
