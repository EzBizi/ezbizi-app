<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Support\Privacy\Erasure;
use App\Support\Privacy\HoldsPersonalData;
use App\Support\Privacy\PersonalDataEntry;
use App\Support\Privacy\RetentionPolicy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * One of A-1 Chimney's Google reviews, as A1 Leads' change feed last said it.
 *
 * A COPY, kept in step by `ezbizi:a1leads-delta-sync`, never edited here:
 * every write to a review, its card, its match or its report goes to A1 Leads,
 * and comes back through the feed. The tab reads this so it is a minute old,
 * not a day.
 */
class A1LeadsReview extends Model implements HoldsPersonalData
{
    use BelongsToTenant;
    use HasUuids;

    protected $table = 'a1leads_reviews';

    protected $fillable = [
        'organization_id', 'google_review_name', 'review_key', 'a1_review_id', 'gbp_location_name', 'rating',
        'reviewer_name', 'has_reply', 'match_tier', 'removed', 'review_created_at', 'seq',
        'review', 'card', 'reply', 'report',
    ];

    protected function casts(): array
    {
        return [
            'rating' => 'integer',
            'has_reply' => 'boolean',
            'removed' => 'boolean',
            'review_created_at' => 'datetime',
            'review' => 'array',
            'card' => 'array',
            'reply' => 'array',
            'report' => 'array',
        ];
    }

    // ── §9 data inventory ────────────────────────────────────────

    public static function personalDataPurpose(): string
    {
        return "A live copy of the business's Google reviews from A1 Leads, so the "
            .'A1Leads GBP tab shows them as they are now rather than as they were yesterday.';
    }

    /**
     * Exactly as long as A1 Leads holds the review: the feed's hard delete
     * removes the row here on the next sync, a Google removal is marked on it,
     * and nothing here outlives its source.
     */
    public static function retention(): RetentionPolicy
    {
        return RetentionPolicy::elsewhere('ezbizi:a1leads-delta-sync',
            "A copy of A1 Leads' reviews kept in step with its change feed. A review A1 "
            .'Leads deletes is deleted here on the next sync; one Google removed is marked '
            .'removed. Nothing is kept that the source no longer holds.'
        );
    }

    public static function personalDataHeld(): array
    {
        return [
            new PersonalDataEntry('reviewer_name', PersonalDataEntry::IDENTITY,
                'The name the reviewer shows publicly on Google.',
                Erasure::keep('A copy of a public review, deleted here when A1 Leads deletes '
                    .'it. Erasing it here alone would be undone by the next sync.')),
            new PersonalDataEntry('review', PersonalDataEntry::CONTENT,
                'The public review as A1 Leads holds it, including what the reviewer wrote.',
                Erasure::keep('As above: a copy of a public review that follows its source.')),
            new PersonalDataEntry('card', PersonalDataEntry::CONTENT,
                "A-1's workflow card for the review, which may name the matched customer "
                .'and their phone and service address.',
                Erasure::keep("A1 Leads owns the card; it changes and leaves with A1 Leads' copy.")),
        ];
    }

    public static function personalDataAbout(Model $subject): ?Builder
    {
        return null;
    }
}
