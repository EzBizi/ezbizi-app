<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Support\Privacy\RetentionPolicy;
use App\Support\Privacy\StatesRetention;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A customer unhappy enough to say so (Jose, 2026-10-20): a review of 3 stars
 * or fewer, a Bad Rating from the website, or a call the AI heard as a
 * complaint - and how far handling it has got.
 */
#[Fillable(['organization_id', 'company_id', 'franchise_id', 'kind', 'source_type', 'source_id', 'status',
    'owner_user_id', 'rating', 'raised_at', 'solved_at', 'client_status', 'priority', 'due_at', 'title', 'summary'])]
class Complaint extends Model implements StatesRetention
{
    use BelongsToTenant;
    use \App\Support\Live\RecordsChanges;

    /** The change feed's name for this record (ChangeFeed::TYPES). */
    protected static string $changeType = 'complaints';

    use HasUuids;

    public const KINDS = ['bad_review', 'bad_rating', 'call_complaint', 'bbb', 'attorney_general', 'lawsuit', 'other'];

    /** Shoval 2026-11-20: these are Priority and want an answer within a day (two at most). */
    public const PRIORITY_KINDS = ['bbb', 'attorney_general', 'lawsuit'];

    public const STATUSES = ['new', 'in_progress', 'solved', 'not_solvable'];

    /** Still being worked. */
    public const OPEN = ['new', 'in_progress'];

    protected function casts(): array
    {
        return ['raised_at' => 'datetime', 'solved_at' => 'datetime', 'rating' => 'integer',
            'priority' => 'boolean', 'due_at' => 'datetime'];
    }

    /** Every complaint is mirrored as a card on Task Board's "Reviews & Complaints" (Shoval and Eric, 2026-11-20). */
    protected static function booted(): void
    {
        static::saved(fn (Complaint $c) => \App\Support\CustomerExperience\ComplaintBoardMirror::sync($c));
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_user_id');
    }

    public function franchise(): BelongsTo
    {
        return $this->belongsTo(Franchise::class);
    }

    public function notes(): HasMany
    {
        return $this->hasMany(ComplaintNote::class)->orderBy('created_at');
    }

    public static function retention(): RetentionPolicy
    {
        // No words or names are copied here: they are read from the review or
        // lead, so erasing the customer there erases them from this list too.
        return RetentionPolicy::forever(
            'That a complaint was raised, its status and who worked it. For a review, rating or call the '
            .'words stay on the review or lead; a complaint that came by email or was entered by hand keeps '
            .'its title and summary here. The record of how the business answered an unhappy customer; '
            .'deleted with the organization.'
        );
    }
}
