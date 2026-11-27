<?php

namespace App\Support\Social;

use App\Models\SocialPost;
use App\Models\SocialPostTarget;
use Illuminate\Support\Collection;

/**
 * The one work state of a post, read from its targets, its schedule and the
 * provider's receipt (suggestion 01a1ef57). The Dashboard tiles, the planner
 * rows, the Calendar and the inspector all read this, so a post is never
 * "Scheduled" in one place and "Waiting for approval" in another.
 *
 * Judged on the targets the viewer can see. A failure outranks everything;
 * a post still on its way (queued) is in no state yet; a post every account
 * turned down or took back is in none either.
 */
final class PostState
{
    /** Some account is waiting for a person's yes, and its time (if any) has not passed. */
    public const NEEDS_APPROVAL = 'awaiting';

    /** Approved, but nobody has set a time. */
    public const READY = 'ready';

    /** Approved, with a time still ahead. */
    public const SCHEDULED = 'scheduled';

    /** Its time passed and it has not gone out: approve late, or pick a new time. */
    public const PAST_DUE = 'past_due';

    /** The provider answered with a receipt for at least one account. */
    public const PUBLISHED = 'sent';

    public const FAILED = 'failed';

    /** In the order the tiles draw them. */
    public const ALL = [self::NEEDS_APPROVAL, self::READY, self::SCHEDULED, self::PAST_DUE, self::PUBLISHED, self::FAILED];

    /**
     * How long a scheduled post may sit past its time before it reads as past
     * due: the sweep runs every minute, and a post a minute late is on its way.
     */
    public const GRACE_MINUTES = 5;

    /** @param  Collection<int, SocialPostTarget>  $targets  the ones this view can see */
    public static function of(SocialPost $post, Collection $targets): ?string
    {
        $statuses = $targets->pluck('status');

        if ($statuses->isEmpty()) {
            return null;
        }
        if ($statuses->contains(SocialPostTarget::STATUS_FAILED)) {
            return self::FAILED;
        }
        if ($statuses->contains(SocialPostTarget::STATUS_QUEUED)) {
            return null;
        }

        $awaiting = $statuses->contains(SocialPostTarget::STATUS_AWAITING_APPROVAL);
        $waiting = $awaiting || $statuses->contains(SocialPostTarget::STATUS_SCHEDULED);

        if ($waiting) {
            $due = $post->dueAt();
            if ($due !== null) {
                // A draft waiting for approval is late the minute its time
                // passes; an approved one gets the sweep's grace.
                $late = $awaiting ? $due->lte(now()) : $due->lte(now()->subMinutes(self::GRACE_MINUTES));
                if ($late) {
                    return self::PAST_DUE;
                }
            }
            if ($awaiting) {
                return self::NEEDS_APPROVAL;
            }

            return $due === null ? self::READY : self::SCHEDULED;
        }

        return $statuses->contains(SocialPostTarget::STATUS_SENT) ? self::PUBLISHED : null;
    }
}
