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
 * A public Google review reply that A1 Leads confirmed as sent from EzBizi.
 *
 * It exists for one reason: the A1Leads GBP tab reads a DAILY snapshot, so a
 * reply posted at 10:00 would read as "No reply" until tomorrow's install and
 * invite somebody to answer the same review twice. The receipt overlays the
 * snapshot until the snapshot carries the reply itself.
 *
 * It is not the record of who spoke for the business - that is the audit
 * event `a1leads_gbp.review_replied`, under the audit log's own policy. And it
 * is not the reply's home - that is Google, and A1 Leads' own log.
 */
class A1LeadsReplyReceipt extends Model implements HoldsPersonalData
{
    use BelongsToTenant;
    use HasUuids;

    protected $table = 'a1leads_review_reply_receipts';

    protected $fillable = [
        'organization_id', 'google_review_name', 'reply_text', 'sent_at', 'actor_id',
    ];

    protected function casts(): array
    {
        return ['sent_at' => 'datetime'];
    }

    // ── §9 data inventory ────────────────────────────────────────

    public static function personalDataPurpose(): string
    {
        return 'Replies posted to Google reviews from the A1Leads GBP tab, kept only until '
            .'the daily copy of Google catches up, so a sent reply never shows as unanswered.';
    }

    /**
     * Three months after it was written.
     *
     * The job it does is over the next morning: the daily snapshot is installed
     * at 05:00 and carries the reply itself, after which the overlay gives way
     * and the row does nothing. Three months covers a refresh that stalls for
     * weeks - which happened for a day on 23 September - with room to spare,
     * and is not a history anybody reads: the audit log answers "who replied",
     * and Google holds the reply.
     */
    public static function retention(): RetentionPolicy
    {
        return RetentionPolicy::window(3, 'created_at',
            'A bridge between a reply being sent and the daily copy of Google showing it. '
            .'Its work is done the next morning; the audit log, not this, records who '
            .'replied, and Google holds the reply itself.'
        );
    }

    public static function personalDataHeld(): array
    {
        return [
            new PersonalDataEntry('actor_id', PersonalDataEntry::ACTIVITY,
                'Which member of staff posted the reply.',
                Erasure::erase()),
            new PersonalDataEntry('reply_text', PersonalDataEntry::CONTENT,
                'The reply as published on Google. It may greet the reviewer by name.',
                Erasure::keep('It is the business\'s own public statement, published on '
                    .'Google under its name, and is not about the member of staff whose '
                    .'erasure reaches this row - their link is actor_id, which is erased. '
                    .'It leaves with the row on the three-month window.')),
        ];
    }

    /** Receipts this person posted. Only those: the text is not indexed for names. */
    public static function personalDataAbout(Model $subject): ?Builder
    {
        return $subject instanceof User
            ? static::query()->where('actor_id', $subject->getKey())
            : null;
    }
}
