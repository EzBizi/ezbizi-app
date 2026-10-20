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
 * A FROZEN COPY since 2026-10-20, when the A1Leads GBP tab and its delta sync
 * were retired (Jose: "we are now working directly on the customer experience
 * tab"). Its statuses, notes and matches were copied into Reviews by
 * ezbizi:reviews-import-a1leads; nothing writes here any more.
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
        return "A frozen copy of the business's Google reviews as A1 Leads last held them, "
            .'kept to check what was copied into Reviews.';
    }

    /** Six months after the last write, which was the retirement of its sync on 2026-10-20. */
    public static function retention(): RetentionPolicy
    {
        return RetentionPolicy::window(6, 'updated_at',
            "A frozen copy of A1 Leads' reviews, kept six months after its last update in case "
            .'the copy into Reviews has to be checked. Nothing writes to it any more.'
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
