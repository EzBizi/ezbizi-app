<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Support\Privacy\RetentionPolicy;
use App\Support\Privacy\StatesRetention;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/** The Google review a review link most likely produced (Jose, 2026-11-09). */
#[Fillable(['review_id', 'job_id', 'customer_id', 'confidence', 'clicked', 'status', 'sent_at'])]
class ReviewLinkMatch extends Model implements StatesRetention
{
    use BelongsToTenant;
    use HasUuids;

    public const CONFIDENCE = ['exact', 'likely', 'possible'];

    protected function casts(): array
    {
        return ['clicked' => 'boolean', 'sent_at' => 'datetime'];
    }

    public static function retention(): RetentionPolicy
    {
        return RetentionPolicy::withParent(
            'Which Google review a review link probably led to, how sure the match is, and whether a '
            .'person confirmed it. Ids only; deleted with the review, job or customer.'
        );
    }
}
