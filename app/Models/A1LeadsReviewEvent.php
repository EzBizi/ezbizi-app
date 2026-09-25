<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Support\Automations\CarriesAutomationScope;
use App\Support\Privacy\Erasure;
use App\Support\Privacy\HoldsPersonalData;
use App\Support\Privacy\PersonalDataEntry;
use App\Support\Privacy\RetentionPolicy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * A new Google review of A-1 Chimney's, as EzBizi raised it to Automations.
 *
 * EzBizi holds no copy of A1 Leads' reviews - the tab reads them live from the
 * daily snapshot. This row exists for three jobs only: to be the SUBJECT of
 * `a1leads_gbp.review_received` (a rule reads the review's name from here, not
 * from an editable payload), to make "once per review" a unique key, and to
 * give the dashboard something of EzBizi's own to count.
 *
 * It sits at A-1 Chimney's company, so a company-level rule covers it and a
 * notification reaches that company's people, not the whole organization.
 */
class A1LeadsReviewEvent extends Model implements CarriesAutomationScope, HoldsPersonalData
{
    use BelongsToTenant;
    use HasUuids;

    protected $table = 'a1leads_review_events';

    protected $fillable = [
        'organization_id', 'company_id', 'google_review_name', 'rating', 'reviewer_name',
        'location_name', 'location_address', 'has_reply', 'review_created_at',
    ];

    protected function casts(): array
    {
        return [
            'rating' => 'integer',
            'has_reply' => 'boolean',
            'review_created_at' => 'datetime',
        ];
    }

    public function automationScope(): array
    {
        return ['company', $this->company_id];
    }

    public function company()
    {
        return $this->belongsTo(Company::class);
    }

    // ── §9 data inventory ────────────────────────────────────────

    public static function personalDataPurpose(): string
    {
        return 'A note that a new Google review of the business arrived, so an automation '
            .'can answer it once and the dashboard can count it.';
    }

    /**
     * Thirteen months: longer than any window in which the review could be
     * seen as new again. The watcher's first gate is time - only reviews Google
     * stamped after the previous snapshot - and this row is the second. It must
     * outlive every rollback A1 Leads could make, or an expired row would let
     * an old review be answered twice.
     */
    public static function retention(): RetentionPolicy
    {
        return RetentionPolicy::window(13, 'created_at',
            'The record that a review was already raised to Automations. It must outlast '
            .'any snapshot A1 Leads could roll back to, or an old review could be answered '
            .'a second time. Google holds the review itself.'
        );
    }

    public static function personalDataHeld(): array
    {
        return [
            new PersonalDataEntry('reviewer_name', PersonalDataEntry::IDENTITY,
                'The name the reviewer shows on Google, for a reply that greets them.',
                Erasure::erase()),
        ];
    }

    /**
     * Nobody EzBizi can name: a Google reviewer is not a customer record here,
     * and matching one by name would be the guess A1 Leads keeps to itself.
     */
    public static function personalDataAbout(Model $subject): ?Builder
    {
        return null;
    }
}
