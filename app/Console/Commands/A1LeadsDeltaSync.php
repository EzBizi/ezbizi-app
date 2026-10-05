<?php

namespace App\Console\Commands;

use App\Models\A1LeadsReview;
use App\Models\Organization;
use App\Services\A1Leads\A1LeadsReviewOpsClient;
use App\Support\Tenancy\TenantContext;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Keep `a1leads_reviews` in step with A1 Leads' change feed.
 *
 * Rodrigo's contract for `/delta` (25 September): start at cursor 0, follow
 * `meta.next_cursor` while `has_more`, and save the cursor only AFTER a page is
 * applied. Records come in `seq` order and one review may appear many times;
 * upsert by the exact Google review name, in that order, so the last word
 * wins. `tombstone: true` is a review Google removed (kept, marked removed, so
 * the screen can say so); `review: null` is a hard delete (the row goes).
 *
 * It reads A1 Leads' own database - no Google request, no Google quota.
 *
 * `--rebuild` starts again from cursor 0. Rodrigo asked for exactly one of
 * these when the match fields were added to the feed; it is also the way back
 * from anything that leaves the copy in doubt.
 */
class A1LeadsDeltaSync extends Command
{
    protected $signature = 'ezbizi:a1leads-delta-sync {--rebuild : Start again from cursor 0}';

    protected $description = "Keep the local copy of A-1 Chimney's Google reviews in step with A1 Leads' change feed";

    public const STATE = 'a1leads:delta';

    /** Pages per run: 60 x 500 covers a full rebuild of ~13k reviews twice over. */
    private const MAX_PAGES = 60;

    public function handle(A1LeadsReviewOpsClient $feed, TenantContext $tenant): int
    {
        $orgId = config('services.a1leads.organization_id');
        if (! is_string($orgId) || $orgId === '' || ! $feed->configured()) {
            $this->line('A1 Leads review-ops is not configured; nothing to sync.');

            return self::SUCCESS;
        }
        $organization = Organization::withoutGlobalScopes()->findOrFail($orgId);

        return $tenant->run($organization, function () use ($feed) {
            if ($this->option('rebuild')) {
                A1LeadsReview::query()->delete();
                Cache::forget(self::STATE);
                $this->line('Rebuilding from cursor 0.');
            }

            $state = Cache::get(self::STATE, []);
            $cursor = (string) ($state['cursor'] ?? '0');
            $applied = 0;

            for ($page = 0; $page < self::MAX_PAGES; $page++) {
                $answer = $feed->delta((int) $cursor, 500);
                if ($answer['status'] !== 200) {
                    $this->error('A1 Leads refused the change feed: '
                        .($answer['body']['error']['code'] ?? $answer['status']).'. The copy is left as it was.');

                    return self::FAILURE;
                }

                $records = (array) ($answer['body']['data'] ?? []);
                $meta = (array) ($answer['body']['meta'] ?? []);

                DB::transaction(function () use ($records, $meta, &$state) {
                    foreach ($records as $record) {
                        if (is_array($record)) {
                            self::apply($record);
                        }
                    }
                    // The cursor moves only with the page it describes.
                    $state = [
                        ...$state,
                        'cursor' => (string) ($meta['next_cursor'] ?? $state['cursor'] ?? '0'),
                        'source_as_of' => $meta['source_as_of'] ?? null,
                        'synced_at' => now()->toIso8601String(),
                    ];
                    if (($meta['has_more'] ?? false) !== true) {
                        $state['caught_up_at'] = now()->toIso8601String();
                    }
                    Cache::forever(self::STATE, $state);
                });

                $applied += count($records);
                $cursor = $state['cursor'];
                if (($meta['has_more'] ?? false) !== true) {
                    break;
                }
            }

            $this->line("Applied {$applied} change(s); cursor {$cursor}.");

            return self::SUCCESS;
        });
    }

    /**
     * One feed record onto the copy.
     *
     * @param  array<string, mixed>  $record
     */
    public static function apply(array $record): void
    {
        $name = $record['review_ref'] ?? ($record['review']['google_review_name'] ?? null);
        if (! is_string($name) || $name === '' || strlen($name) > 300) {
            return;
        }

        $review = $record['review'] ?? null;
        if (! is_array($review)) {
            // A hard delete: A1 Leads no longer holds it, so neither do we.
            A1LeadsReview::query()->where('google_review_name', $name)->delete();

            return;
        }

        // The matched customer and their contact ride on every record kind -
        // `match` events included - and do not depend on a workflow card. The
        // record's own copy is the one A1 Leads emitted with this seq, so it is
        // kept on the review, where the tab reads it.
        foreach (['customer', 'contact'] as $key) {
            if (is_array($record[$key] ?? null)) {
                $review[$key] = $record[$key];
            }
        }

        $seq = (int) ($record['seq'] ?? 0);
        $row = A1LeadsReview::query()->where('google_review_name', $name)->first();
        if ($row !== null && $row->seq > $seq) {
            return;   // an older word about a review we already have newer news of
        }

        $reply = is_array($record['reply'] ?? null) ? $record['reply'] : null;
        $values = [
            'a1_review_id' => is_numeric($review['id'] ?? null) ? (int) $review['id'] : null,
            'gbp_location_name' => is_string($review['gbp_location_name'] ?? null) ? mb_substr($review['gbp_location_name'], 0, 200) : null,
            'rating' => is_numeric($review['rating'] ?? null) ? (int) $review['rating'] : null,
            'reviewer_name' => is_string($review['reviewer_name'] ?? null) ? mb_substr($review['reviewer_name'], 0, 200) : null,
            'has_reply' => trim((string) ($reply['text'] ?? '')) !== '',
            'match_tier' => is_string($review['match_tier'] ?? null) ? mb_substr($review['match_tier'], 0, 20) : null,
            'removed' => ($record['tombstone'] ?? false) === true || ($review['deleted_at'] ?? null) !== null,
            'review_created_at' => is_string($review['review_created_at'] ?? null) ? Carbon::parse($review['review_created_at']) : null,
            'seq' => $seq,
            'review' => $review,
            'card' => is_array($record['card'] ?? null) ? $record['card'] : null,
            'reply' => $reply,
            'report' => is_array($record['report'] ?? null) ? $record['report'] : null,
        ];

        if ($row === null) {
            A1LeadsReview::query()->create(['google_review_name' => $name, ...$values]);
        } else {
            $row->fill($values)->save();
        }
    }
}
