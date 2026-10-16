<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Support\Privacy\Erasure;
use App\Support\Privacy\HoldsPersonalData;
use App\Support\Privacy\PersonalDataEntry;
use App\Support\Privacy\RetentionPolicy;
use App\Support\Privacy\StatesRetention;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * A review somebody left on a connected Business Profile.
 *
 * Not ours. Every field on this row except the ones EzBizi adds is a member of
 * the public's own words about this business, held so the business can read and
 * answer them in one place instead of in Google's interface. It is a cached
 * copy of something already public, which is what makes storing it reasonable
 * and what makes editing it not.
 */
class Review extends Model implements HoldsPersonalData, StatesRetention
{
    use BelongsToTenant;
    use HasUuids;

    /**
     * Google's own word for a review nobody rated.
     *
     * Rare, and not zero. A review with no stars is not a one-star review, and
     * defaulting it either way would put an opinion in somebody's mouth.
     */
    public const NO_RATING = null;

    public static function retention(): RetentionPolicy
    {
        return RetentionPolicy::forever(
            'Reviews left publicly on the organization\'s Google Business Profile, cached so they '
            .'can be read and answered inside EzBizi. Held while the profile is connected and '
            .'deleted with the organization. The authoritative copy is Google\'s: a review removed '
            .'there disappears from here on the next sync, and nothing here can put it back.'
        );
    }

    /**
     * §9. Somebody else's words about a business, not a relationship with it.
     *
     * A reviewer is not a customer of EzBizi and usually not a user of anything
     * here. What is held is a display name, a photo URL Google serves, and free
     * text they chose to publish. It is personal data all the same, and the
     * unusual part is that erasing it locally does not erase it — the public
     * copy is Google's, which the retention note says out loud rather than
     * implying an erasure this platform cannot deliver.
     */
    public static function personalDataPurpose(): string
    {
        return 'A person who reviewed this organization publicly on Google. Their name, photo and '
            .'the text they wrote are cached so the business can read and reply to reviews in one '
            .'place. EzBizi does not use it for anything else and never combines it across '
            .'organizations.';
    }

    /** @return PersonalDataEntry[] */
    public static function personalDataHeld(): array
    {
        return [
            new PersonalDataEntry('reviewer_name', PersonalDataEntry::IDENTITY,
                'Who left the review, as Google displays them.',
                Erasure::erase('A Google user')),
            new PersonalDataEntry('reviewer_photo_url', PersonalDataEntry::IDENTITY,
                'The avatar Google serves beside the review. A URL rather than an image — '
                .'EzBizi stores no copy of the picture itself.',
                Erasure::erase()),
            new PersonalDataEntry('comment', PersonalDataEntry::CONTENT,
                'What they wrote, in their own words. Free text, so it can name people, '
                .'places, or an address they mention in passing.',
                Erasure::erase()),
        ];
    }

    /**
     * Nothing joins a reviewer to a person on this platform.
     *
     * A reviewer has a Google display name and nothing else — no id shared with
     * a user, a customer or a lead. Matching on the name would be a guess, and
     * a purge built on a guess erases the wrong stranger.
     */
    public static function personalDataAbout(Model $subject): ?Builder
    {
        return null;
    }

    protected $fillable = [
        'organization_id', 'company_id', 'franchise_id', 'connection_id',
        'remote_name', 'location_name', 'reviewer_name', 'reviewer_photo_url',
        'rating', 'comment', 'reviewed_at', 'edited_at', 'synced_at',
        'status', 'customer_id', 'customer_match', 'job_id', 'reported_at', 'report_reason',
    ];

    /** Where somebody working the review has got to (UI queue 101, 2026-10-16). */
    public const STATUSES = ['cant_locate', 'working', 'awaiting_report', 'done'];

    protected function casts(): array
    {
        return [
            'rating'      => 'integer',
            'reviewed_at' => 'datetime',
            'edited_at'   => 'datetime',
            'synced_at'   => 'datetime',
            'reported_at' => 'datetime',
            'missing_since' => 'datetime',
            'missing_checked_at' => 'datetime',
            'removed_at' => 'datetime',
        ];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function franchise(): BelongsTo
    {
        return $this->belongsTo(Franchise::class);
    }

    public function connection(): BelongsTo
    {
        return $this->belongsTo(SocialConnection::class, 'connection_id');
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function job(): BelongsTo
    {
        return $this->belongsTo(Job::class);
    }

    public function notes(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(ReviewNote::class)->orderBy('created_at');
    }

    /** One, because Google allows one. */
    public function reply(): HasOne
    {
        return $this->hasOne(ReviewReply::class);
    }

    /**
     * Was this edited after it was written?
     *
     * Worth asking before publishing a reply: a reply composed against the
     * original wording can read as a non-sequitur once the customer has
     * rewritten what they said.
     */
    public function wasEdited(): bool
    {
        return $this->edited_at !== null
            && $this->reviewed_at !== null
            && $this->edited_at->gt($this->reviewed_at);
    }
}
