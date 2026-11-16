<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Support\Privacy\RetentionPolicy;
use App\Support\Privacy\StatesRetention;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A note somebody left on a review while working it (2026-10-16). */
#[Fillable(['organization_id', 'review_id', 'user_id', 'body', 'created_at'])]
class ReviewNote extends Model implements StatesRetention
{
    use BelongsToTenant;
    use \App\Support\Live\RecordsParentChanges;

    /** A write here is its review's change on the live feed (RecordsParentChanges). */
    protected static array $changeParent = ['reviews', Review::class, 'review_id'];

    use HasUuids;

    public const UPDATED_AT = null;

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public static function retention(): RetentionPolicy
    {
        return RetentionPolicy::withParent(
            'A note staff wrote while handling a public review, and who wrote it. Deleted with the review.'
        );
    }
}
