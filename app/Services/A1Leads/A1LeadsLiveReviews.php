<?php

namespace App\Services\A1Leads;

use App\Console\Commands\A1LeadsDeltaSync;
use App\Models\A1LeadsReview;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

/**
 * The A1Leads GBP tab's reviews, read from the live copy instead of the
 * once-a-day snapshot - in the snapshot's own shape, so the screen does not
 * change to follow it.
 *
 * Used only when the copy is trustworthy: it has caught up with the feed at
 * least once, and the sync ran in the last fifteen minutes. Otherwise the tab
 * falls back to the snapshot it read before, which says its own age.
 */
class A1LeadsLiveReviews
{
    private const FRESH_MINUTES = 15;

    public function ready(): bool
    {
        $state = Cache::get(A1LeadsDeltaSync::STATE);

        return is_array($state)
            && isset($state['caught_up_at'], $state['synced_at'])
            && Carbon::parse($state['synced_at'])->greaterThan(now()->subMinutes(self::FRESH_MINUTES));
    }

    /**
     * The source line: as of A1 Leads' own database read, not a capture.
     *
     * @return array<string, mixed>
     */
    public function source(): array
    {
        $state = (array) Cache::get(A1LeadsDeltaSync::STATE, []);
        // When EzBizi last read the feed. A1's generated_at is a projection
        // time and certifies nothing about Google (Rodrigo, 01a13160).
        $asOf = $state['synced_at'] ?? null;

        return [
            'configured' => true,
            'reachable' => true,
            'live' => true,
            'captured_at' => $asOf !== null ? Carbon::parse($asOf)->toIso8601String() : null,
            'installed_at' => isset($state['synced_at']) ? Carbon::parse($state['synced_at'])->toIso8601String() : null,
            'generation' => 'live:'.($state['cursor'] ?? '0'),
            'hours_old' => $asOf !== null ? round(Carbon::parse($asOf)->diffInMinutes(now()) / 60, 1) : null,
            'stale' => false,
            'because' => null,
        ];
    }

    /** @return array{total: int} */
    public function summary(): array
    {
        return ['total' => A1LeadsReview::query()->where('removed', false)->count()];
    }

    /**
     * One page, newest first unless asked otherwise, with the board joined on.
     *
     * @param  array<string, mixed>  $filters  the tab's own: rating, replied, matched, from, location, sort
     * @return array{data: array<int, array<string, mixed>>, meta: array{total: int}}
     */
    public function page(array $filters, int $limit, int $offset): array
    {
        $query = $this->filtered(A1LeadsReview::query(), $filters);
        $total = (clone $query)->count();

        $sorted = match ($filters['sort'] ?? 'newest') {
            'oldest' => $query->orderBy('review_created_at'),
            'highest' => $query->orderByDesc('rating')->orderByDesc('review_created_at'),
            'lowest' => $query->orderBy('rating')->orderByDesc('review_created_at'),
            default => $query->orderByDesc('review_created_at'),
        };
        $rows = $sorted->limit(max(1, min($limit, 100)))->offset(max(0, $offset))->get();

        return [
            'data' => $rows->map(fn (A1LeadsReview $r) => self::asSnapshotRow($r))->all(),
            'meta' => ['total' => $total],
        ];
    }

    private function filtered(Builder $query, array $filters): Builder
    {
        $rating = (string) ($filters['rating'] ?? '');
        match (true) {
            $rating === 'positive' => $query->where('rating', '>=', 4),
            $rating === 'negative' => $query->where('rating', '<=', 3),
            in_array($rating, ['1', '2', '3', '4', '5'], true) => $query->where('rating', (int) $rating),
            default => null,
        };

        $replied = $filters['replied'] ?? null;
        if ($replied === 'true' || $replied === 'false') {
            $query->where('has_reply', $replied === 'true');
        }

        // "Matched" is the tier the match line draws as a match.
        $matched = $filters['matched'] ?? null;
        if ($matched === 'true') {
            $query->whereIn('match_tier', ['high', 'medium']);
        } elseif ($matched === 'false') {
            $query->where(fn ($q) => $q->whereNull('match_tier')->orWhereNotIn('match_tier', ['high', 'medium']));
        }

        if (is_string($filters['from'] ?? null) && $filters['from'] !== '') {
            $query->where('review_created_at', '>=', Carbon::parse($filters['from'])->startOfDay());
        }
        if (is_string($filters['location'] ?? null) && $filters['location'] !== '') {
            $query->where('gbp_location_name', $filters['location']);
        }

        return $query;
    }

    /**
     * The row the screen already reads, with A1 Leads' card joined as `board`.
     *
     * @return array<string, mixed>
     */
    public static function asSnapshotRow(A1LeadsReview $row): array
    {
        $review = (array) $row->review;
        $reply = (array) ($row->reply ?? []);
        $card = $row->card;

        // The customer comes from the review, not the card: most matched
        // reviews have no card. The raw match evidence (candidates, scores) is
        // dropped here, like the card's, before it reaches a response.
        $customer = A1LeadsClient::matchedCustomer(
            is_array($review['customer'] ?? null) ? $review['customer'] : ($review['customer_match'] ?? null),
            $review['contact'] ?? null,
        );
        unset($review['customer'], $review['contact'], $review['customer_match']);

        return [
            ...$review,
            'customer' => $customer,
            'reply' => trim((string) ($reply['text'] ?? '')) !== '' ? $reply['text'] : null,
            'reply_at' => $reply['at'] ?? null,
            'board' => is_array($card)
                ? A1LeadsClient::card([...$card, 'report' => ['state' => $row->report['status'] ?? null]])
                : null,
        ];
    }
}
