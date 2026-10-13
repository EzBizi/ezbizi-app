<?php

namespace App\Console\Commands;

use App\Models\A1LeadsReview;
use App\Models\A1LeadsReviewEvent;
use App\Models\Organization;
use App\Services\A1Leads\A1LeadsReviewOpsClient;
use App\Support\Automations\EventEmitter;
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
 * applied. Records come in `seq` order and one review may appear many times,
 * so the last word wins. `tombstone: true` is a review Google removed (kept,
 * marked removed, so the screen can say so); `review: null` is a delete.
 *
 * One row per REVIEW, not per name (Rodrigo, suggestion 01a13160, 2026-10-12):
 * the account in `accounts/A/locations/L/reviews/R` is a routing alias, so the
 * row is keyed on business + L + R (`review_key`) and `google_review_name`
 * holds the name to write through. An `account_alias` tombstone re-points that
 * name to the canonical one and never deletes the review; a `reply` or `match`
 * tombstone withdraws only that part. A changed `feed_id` or a
 * `review_cursor_ahead` refusal rebuilds from 0, and a rebuild raises nothing.
 *
 * It reads A1 Leads' own database - no Google request, no Google quota.
 *
 * `--rebuild` starts again from cursor 0.
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

        // One import at a time, scheduled or by hand (Rodrigo, 01a13160).
        $lock = Cache::lock('a1leads:delta:lock', 900);
        if (! $lock->get()) {
            $this->line('Another sync is running.');

            return self::SUCCESS;
        }

        try {
            return $tenant->run($organization, fn () => $this->sync($feed));
        } finally {
            $lock->release();
        }
    }

    private function sync(A1LeadsReviewOpsClient $feed): int
    {
        $state = Cache::get(self::STATE, []);
        $rebuilt = false;

        // Rows from before review_key cannot be told apart from their aliases.
        if ($this->option('rebuild') || A1LeadsReview::query()->whereNull('review_key')->exists()) {
            $state = $this->rebuild();
            $rebuilt = true;
        }

        $cursor = (string) ($state['cursor'] ?? '0');
        $applied = 0;

        for ($page = 0; $page < self::MAX_PAGES; $page++) {
            $answer = $feed->delta((int) $cursor, 500);
            $code = $answer['body']['error']['code'] ?? null;

            // Our cursor is past the source's: the copy is from another history.
            if ($answer['status'] !== 200 && $code === 'review_cursor_ahead' && ! $rebuilt) {
                $state = $this->rebuild();
                $rebuilt = true;
                $cursor = '0';

                continue;
            }
            if ($answer['status'] !== 200) {
                $this->error('A1 Leads refused the change feed: '.($code ?? $answer['status']).'. The copy is left as it was.');

                return self::FAILURE;
            }

            $records = (array) ($answer['body']['data'] ?? []);
            $meta = (array) ($answer['body']['meta'] ?? []);

            // A new feed_id is a new source history: start again from 0.
            $feedId = $meta['feed_id'] ?? null;
            if (is_string($feedId) && isset($state['feed_id']) && $state['feed_id'] !== $feedId && ! $rebuilt) {
                $state = $this->rebuild();
                $rebuilt = true;
                $cursor = '0';

                continue;
            }

            $quiet = ($state['rebuilding'] ?? false) === true;
            DB::transaction(function () use ($records, $meta, $feedId, $quiet, &$state) {
                foreach ($records as $record) {
                    if (is_array($record)) {
                        self::apply($record, raise: ! $quiet);
                    }
                }
                // The cursor moves only with the page it describes.
                $state = [
                    ...$state,
                    'cursor' => (string) ($meta['next_cursor'] ?? $state['cursor'] ?? '0'),
                    'feed_id' => is_string($feedId) ? $feedId : ($state['feed_id'] ?? null),
                    'synced_at' => now()->toIso8601String(),
                ];
                if (($meta['has_more'] ?? false) !== true) {
                    $state['caught_up_at'] = now()->toIso8601String();
                    unset($state['rebuilding']);
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
    }

    /**
     * Empty the copy and start from cursor 0. Until the feed is caught up again
     * nothing raises an event: a rebuild is not news (no backlogged
     * notifications, Rodrigo 01a13160).
     *
     * @return array<string, mixed>
     */
    private function rebuild(): array
    {
        A1LeadsReview::query()->delete();
        $state = ['cursor' => '0', 'rebuilding' => true];
        Cache::forever(self::STATE, $state);
        $this->line('Rebuilding from cursor 0.');

        return $state;
    }

    /**
     * Business + location + review: the account in a Google review name is a
     * routing alias, so two names that differ only there are one review.
     */
    public static function reviewKey(mixed $businessId, string $ref): string
    {
        return mb_substr(((string) $businessId).'|'.preg_replace('#^accounts/[^/]+/#', '', $ref), 0, 300);
    }

    /**
     * One feed record onto the copy.
     *
     * @param  array<string, mixed>  $record
     */
    public static function apply(array $record, bool $raise = true): void
    {
        $name = $record['review_ref'] ?? ($record['review']['google_review_name'] ?? null);
        if (! is_string($name) || $name === '' || strlen($name) > 300) {
            return;
        }
        $key = self::reviewKey($record['business_id'] ?? '', $name);
        $seq = (int) ($record['seq'] ?? 0);
        $kind = (string) ($record['kind'] ?? 'review');
        $row = A1LeadsReview::query()->where('review_key', $key)->first();

        $review = $record['review'] ?? null;
        if (! is_array($review)) {
            if ($row === null || $row->seq > $seq) {
                return;
            }
            // An alias retired: only the name it was reached by changes; the
            // review itself survives under its canonical name.
            if (($record['tombstone_reason'] ?? null) === 'account_alias') {
                $canonical = $record['canonical_review_ref'] ?? null;
                if ($row->google_review_name === $name && is_string($canonical) && $canonical !== '' && strlen($canonical) <= 300) {
                    $row->forceFill(['google_review_name' => $canonical])->save();
                }

                return;
            }
            // A reply or a match withdrawn is not the review deleted.
            if ($kind === 'reply') {
                $row->forceFill(['reply' => null, 'has_reply' => false, 'seq' => $seq])->save();

                return;
            }
            if ($kind === 'match') {
                $kept = (array) $row->review;
                unset($kept['customer'], $kept['contact']);
                $row->forceFill(['review' => $kept, 'match_tier' => null, 'seq' => $seq])->save();

                return;
            }
            // A hard delete of the name the row holds. One for a retired
            // alias leaves the surviving review alone.
            if ($row->google_review_name === $name) {
                $row->delete();
            }

            return;
        }

        if ($row !== null && $row->seq > $seq) {
            return;   // an older word about a review we already have newer news of
        }

        // The matched customer and their contact ride on every record kind -
        // `match` events included - and do not depend on a workflow card. The
        // record's own copy is the one A1 Leads emitted with this seq, so it is
        // kept on the review, where the tab reads it; a record without them
        // keeps what the row already had.
        $before = (array) ($row?->review ?? []);
        foreach (['customer', 'contact'] as $part) {
            if (is_array($record[$part] ?? null)) {
                $review[$part] = $record[$part];
            } elseif (is_array($before[$part] ?? null)) {
                $review[$part] = $before[$part];
            }
        }

        // A sent reply stays with the review: only a `reply` record withdraws
        // it, not another alias's record saying "none" (Rodrigo, 01a13160).
        $reply = is_array($record['reply'] ?? null) ? $record['reply'] : $row?->reply;
        if (trim((string) ($reply['text'] ?? '')) === '' && $kind !== 'reply'
            && trim((string) (($row?->reply ?? [])['text'] ?? '')) !== '') {
            $reply = $row->reply;
        }
        $values = [
            'google_review_name' => $name,
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
            'card' => is_array($record['card'] ?? null) ? $record['card'] : $row?->card,
            'reply' => $reply,
            'report' => is_array($record['report'] ?? null) ? $record['report'] : $row?->report,
        ];

        if ($row === null) {
            A1LeadsReview::query()->create(['review_key' => $key, ...$values]);
        } else {
            $wasRemoved = $row->removed;
            $row->fill($values)->save();

            // Google took down a review we held (row G). Never during a
            // rebuild, and never on a rebuild's empty copy.
            if ($raise && ! $wasRemoved && $row->removed) {
                self::raiseRemoved($row);
            }
        }
    }

    private static function raiseRemoved(A1LeadsReview $row): void
    {
        $companyId = config('services.a1leads.company_id');
        if (! is_string($companyId) || $companyId === '') {
            return;
        }

        $review = (array) $row->review;
        $subject = A1LeadsReviewEvent::query()->firstOrCreate(
            ['google_review_name' => $row->google_review_name],
            [
                'company_id' => $companyId,
                'rating' => $row->rating,
                'reviewer_name' => $row->reviewer_name,
                'location_name' => is_string($review['location_title'] ?? null) ? mb_substr($review['location_title'], 0, 200) : null,
                'location_address' => is_string($review['location_address'] ?? null) ? mb_substr($review['location_address'], 0, 300) : null,
                'has_reply' => $row->has_reply,
                'review_created_at' => $row->review_created_at,
            ],
        );

        app(EventEmitter::class)->emit('a1leads_gbp.review_removed', $subject, [
            'rating' => $row->rating,
            'reviewer_name' => $row->reviewer_name,
            'location_name' => $subject->location_name,
            'location_address' => $subject->location_address,
            'comment' => is_string($review['comment'] ?? null) ? mb_substr($review['comment'], 0, 500) : null,
            'review_created_at' => $row->review_created_at?->toIso8601String(),
        ]);
    }
}
