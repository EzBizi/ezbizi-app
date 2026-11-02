<?php

namespace App\Console\Commands;

use App\Models\BusinessLocation;
use App\Models\Organization;
use App\Models\Review;
use App\Models\ReviewReply;
use App\Services\Reviews\BusinessProfileReviews;
use App\Support\Audit\AuditLogger;
use App\Support\Reviews\ReviewReplyWriter;
use App\Support\Tenancy\TenantContext;
use Illuminate\Console\Command;
use Throwable;

/**
 * Answers the Google reviews nobody has answered, a few a day so the listings
 * do not post hundreds of replies at once (Jose, 2026-11-02: "answer all the
 * unanswered ones, maybe do 50 per day ... each reply must be different").
 *
 * Only on listings with automatic replies on, never over a person's draft or a
 * published reply, and never a reply word-for-word the same as one already sent.
 */
class ReviewBacklogReplies extends Command
{
    protected $signature = 'ezbizi:review-backlog {--limit=50 : replies per organization per run}';

    protected $description = 'Publish AI replies to unanswered Google reviews, newest first, a few per run';

    public function handle(TenantContext $tenant, BusinessProfileReviews $google): int
    {
        $limit = (int) $this->option('limit');

        Organization::query()->each(fn (Organization $org) => $tenant->run($org, function () use ($limit, $google, $org) {
            $listings = BusinessLocation::query()->where('auto_reply', true)->pluck('remote_name')
                ->map(fn ($n) => preg_match('~(locations/[^/]+)$~', (string) $n, $m) ? $m[1] : null)->filter()->all();
            if ($listings === []) {
                return;
            }
            $said = ReviewReply::query()->where('status', ReviewReply::STATUS_PUBLISHED)->pluck('body')
                ->map(fn ($b) => self::norm($b))->flip()->all();

            $done = 0;
            Review::query()->where('source', Review::SOURCE_GOOGLE)
                ->where(fn ($q) => collect($listings)->each(fn ($l) => $q->orWhere('location_name', 'like', '%'.addcslashes($l, '%_'))))
                ->whereDoesntHave('reply', fn ($q) => $q->where('status', ReviewReply::STATUS_PUBLISHED)
                    ->orWhere('drafted_by', '!=', ReviewReply::BY_AUTOMATION))
                ->orderByDesc('reviewed_at')->limit($limit * 2)->get()
                ->each(function (Review $review) use (&$done, &$said, $limit, $google) {
                    if ($done >= $limit) {
                        return false;
                    }
                    try {
                        $body = ReviewReplyWriter::write($review);
                        if (isset($said[self::norm($body)])) {
                            $body = ReviewReplyWriter::write($review);
                        }
                        if (isset($said[self::norm($body)])) {
                            return; // ponytail: two identical drafts in a row, leave it for tomorrow's run
                        }
                        $reply = $review->reply()->first() ?? new ReviewReply(['organization_id' => $review->organization_id, 'review_id' => $review->getKey()]);
                        $reply->fill(['body' => $body, 'drafted_by' => ReviewReply::BY_AUTOMATION, 'status' => ReviewReply::STATUS_DRAFT])->save();
                        $google->publish($reply);
                        $reply->forceFill(['status' => ReviewReply::STATUS_PUBLISHED, 'published_at' => now(), 'failure_reason' => null])->save();
                        app(AuditLogger::class)->log('review.reply_published', $review, metadata: ['length' => strlen($body), 'by' => 'backlog']);
                        $said[self::norm($body)] = true;
                        $done++;
                    } catch (Throwable $e) {
                        report($e);
                        $this->error("Review {$review->id}: {$e->getMessage()}");
                    }
                });
            $this->line("{$org->name}: {$done} replies published");
        }));

        return self::SUCCESS;
    }

    private static function norm(?string $body): string
    {
        return strtolower(preg_replace('/\W+/u', '', (string) $body));
    }
}
