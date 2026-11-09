<?php

namespace App\Console\Commands;

use App\Jobs\PublishSocialTarget;
use App\Models\Organization;
use App\Models\SocialPost;
use App\Models\SocialPostTarget;
use App\Support\Automations\EventEmitter;
use App\Support\Tenancy\TenantContext;
use Illuminate\Console\Command;

/**
 * Release scheduled posts whose time has come.
 *
 * ── Why a sweep and not a delayed job ────────────────────────────
 *
 * The same argument the automations outbox makes (§10, `routes/console.php`): a
 * delayed job carries the work, so changing your mind means finding and
 * cancelling it, and a queue that loses its backing store loses every post
 * anybody scheduled. A row simply stops being due. The state lives in the
 * database where somebody can look at it, and this command is the only thing
 * that turns "scheduled" into "queued".
 *
 * ── Why the due check is not in the query ────────────────────────
 *
 * `scheduled_for` is a wall clock and `scheduled_timezone` is the clock it
 * belongs to, so "is it due" is `Carbon::parse($wall, $zone) <= now()` — per
 * row, against a different zone each time. SQL could only answer that by
 * storing an instant, which is the thing TODO #20 says not to do.
 *
 * The scan stays small because the query narrows to targets still in
 * `scheduled` first, and a target leaves that state the moment it is released.
 * The set is "posts waiting for their time", not "posts ever sent".
 *
 * ponytail: no batching and no cursor. If a tenant ever schedules more posts
 * than one minute's sweep can read, chunk it — the fix is `->chunkById()` on
 * the same query, not a different design.
 */
class PublishDueSocialPosts extends Command
{
    protected $signature = 'ezbizi:publish-due-social-posts';

    protected $description = 'Queue scheduled social posts whose time has arrived';

    /** Longer than any send plus its Instagram/TikTok follow-up checks can take. */
    public const STALE_QUEUED_MINUTES = 30;

    public function handle(TenantContext $tenant, EventEmitter $events): int
    {
        $this->failStaleQueued($tenant, $events);

        /*
         * Without tenant scope: this runs from the scheduler, which is nobody,
         * and the global scope would filter every row to nothing. The job it
         * dispatches re-enters the right tenant per target — that is where the
         * boundary is enforced, not here.
         */
        $waiting = SocialPostTarget::query()
            ->withoutGlobalScopes()
            ->where('status', SocialPostTarget::STATUS_SCHEDULED)
            ->get()
            ->groupBy('social_post_id');

        if ($waiting->isEmpty()) {
            return self::SUCCESS;
        }

        $posts = SocialPost::query()
            ->withoutGlobalScopes()
            ->whereIn('id', $waiting->keys())
            ->get()
            ->keyBy(fn (SocialPost $p) => $p->getKey());

        $released = 0;

        foreach ($waiting as $postId => $targets) {
            $post = $posts->get($postId);

            // The post is gone and the targets outlived it. Nothing to send and
            // nothing to report to; the cascade should have taken them, so this
            // is a guard rather than a path.
            if ($post === null || ! $post->isDue()) {
                continue;
            }

            foreach ($targets as $target) {
                /*
                 * Moved to `queued` BEFORE dispatching, and only from
                 * `scheduled`. Whoever changes the row first owns it.
                 *
                 * ── Deliberately not covered by a test, and why ──
                 *
                 * This is unreachable in sequence: the query above already
                 * narrows to `scheduled`, so a second pass never sees a row the
                 * first one took. Three separate tests were written to catch a
                 * missing condition here and all three passed against it —
                 * once because MySQL's `update()` reports CHANGED rows so
                 * `queued` → `queued` returns 0 either way, and twice because
                 * the outer query had already excluded the row. A test that
                 * cannot fail is not a test, so there is none.
                 *
                 * It stays because the race it guards is real and the cost is
                 * one WHERE clause. Two sweeps genuinely overlapping — a slow
                 * minute against `withoutOverlapping(5)`, or a second worker —
                 * can both pass the query before either writes. If the first
                 * then finishes the send, an UNCONDITIONAL update would drag a
                 * `sent` row back to `queued` and publish it to a real audience
                 * twice. None of these networks has an idempotency key.
                 *
                 * Exercising that needs two processes interleaved mid-sweep,
                 * which this suite has no way to arrange. Stated here rather
                 * than left as a line somebody deletes for looking redundant.
                 */
                $claimed = SocialPostTarget::query()
                    ->withoutGlobalScopes()
                    ->whereKey($target->getKey())
                    ->where('status', SocialPostTarget::STATUS_SCHEDULED)
                    ->update(['status' => SocialPostTarget::STATUS_QUEUED]);

                if ($claimed === 1) {
                    PublishSocialTarget::dispatch($target->getKey());
                    $released++;
                }
            }
        }

        if ($released > 0) {
            $this->info("Released {$released} scheduled target(s).");
        }

        return self::SUCCESS;
    }

    /**
     * A target `queued` for half an hour lost its job: the worker died, or the
     * job's own failure hook could not run. Nothing else can move it (retry,
     * edit and cancel all refuse `queued`), so it is failed here with a sentence,
     * claimed with a conditional UPDATE so a job finishing at the same moment wins.
     */
    private function failStaleQueued(TenantContext $tenant, EventEmitter $events): void
    {
        $stale = SocialPostTarget::query()
            ->withoutGlobalScopes()
            ->where('status', SocialPostTarget::STATUS_QUEUED)
            ->where('updated_at', '<', now()->subMinutes(self::STALE_QUEUED_MINUTES))
            ->get();

        foreach ($stale as $target) {
            $organization = Organization::query()->withoutGlobalScopes()->find($target->organization_id);

            if ($organization === null) {
                continue;
            }

            $tenant->run($organization, function () use ($target, $events) {
                $connection = $target->connection;
                $because = PublishSocialTarget::unknownOutcome((string) $connection?->providerLabel());

                $claimed = SocialPostTarget::query()
                    ->whereKey($target->getKey())
                    ->where('status', SocialPostTarget::STATUS_QUEUED)
                    ->update(['status' => SocialPostTarget::STATUS_FAILED, 'failure' => $because]);

                if ($claimed === 1) {
                    $events->emit('social.publish_failed', $target->fresh(), [
                        'provider' => $connection?->provider,
                        'account' => $connection?->display_name,
                        'because' => $because,
                    ]);
                }
            });
        }
    }
}
