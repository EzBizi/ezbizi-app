<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Support\Privacy\RetentionPolicy;
use App\Support\Privacy\StatesRetention;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * What the business says back, and who decided to say it.
 *
 * A published reply appears on Google under the organization's own name, in
 * public, permanently, to an audience that did not opt into anything. That is
 * the entire reason this is a row with an author and a timestamp rather than a
 * `reply` column on the review: when one goes wrong, the first question is
 * always who approved it, and a column cannot answer.
 *
 * ── There is no `approved` state ─────────────────────────────
 *
 * draft → published, or draft → failed. Approval and publication are the same
 * act by the same person in the same request. A stored "approved, not yet sent"
 * would be a promise no outbox exists to keep — the reply would sit looking
 * settled while nothing was carrying it anywhere.
 */
class ReviewReply extends Model implements StatesRetention
{
    use BelongsToTenant;
    use \App\Support\Live\RecordsParentChanges;

    /** A write here is its review's change on the live feed (RecordsParentChanges). */
    protected static array $changeParent = ['reviews', Review::class, 'review_id'];

    use HasUuids;

    public const STATUS_DRAFT     = 'draft';
    public const STATUS_PUBLISHED = 'published';
    public const STATUS_FAILED    = 'failed';

    /** Who composed the words — not who approved them. */
    public const BY_PERSON = 'person';

    public const BY_DRAFTING_JOB = 'drafting_job';

    /**
     * An automation rule wrote these words, from a message its author typed.
     * Still a draft and still published only by a person — this names who
     * composed it, which is the question the reply screen answers.
     */
    public const BY_AUTOMATION = 'automation';

    /**
     * Already on Google when it was fetched, written there or elsewhere
     * (UI/UX finding, 2026-10-16: A-1's answered reviews read as Unanswered).
     */
    public const BY_GOOGLE = 'google';

    public static function retention(): RetentionPolicy
    {
        return RetentionPolicy::forever(
            'A reply this organization wrote to a public review, whether or not it was published, '
            .'together with who published it and when. It is the organization\'s own words rather '
            .'than a person\'s, and it is kept as the record of a public statement made in the '
            .'organization\'s name. Deleted with the organization.'
        );
    }

    protected $fillable = [
        'organization_id', 'review_id', 'body', 'status', 'drafted_by',
        'published_by_user_id', 'published_at', 'failure_reason',
    ];

    protected function casts(): array
    {
        return [
            'published_at' => 'datetime',
        ];
    }

    public function review(): BelongsTo
    {
        return $this->belongsTo(Review::class);
    }

    public function isPublished(): bool
    {
        return $this->status === self::STATUS_PUBLISHED;
    }

    /**
     * Can this be edited?
     *
     * A published reply is already public. Editing the stored copy without
     * republishing would make EzBizi disagree with Google about what the
     * business said, and the screen would be the one lying.
     */
    public function isEditable(): bool
    {
        return $this->status !== self::STATUS_PUBLISHED;
    }
}
