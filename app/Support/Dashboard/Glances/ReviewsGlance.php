<?php

namespace App\Support\Dashboard\Glances;

use App\Models\Review;
use App\Models\ReviewReply;
use App\Models\User;
use App\Support\Dashboard\DashboardPeriod;
use App\Support\Dashboard\Glance;
use App\Support\Dashboard\GlancePayload;
use App\Support\Scoping\ViewScope;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Carbon;

/**
 * Reviews box: what arrived, and how many are still unanswered.
 *
 * ── It leads with unanswered, and with the average second ────
 *
 * A star average is the number everybody asks for and the one that moves
 * slowest — a business with two hundred reviews cannot shift it this week no
 * matter what it does. Unanswered is the number somebody can act on before
 * lunch, and an unanswered one-star is the most expensive thing on this
 * dashboard: it sits in public, at the top of the listing, being the first
 * thing a stranger reads.
 *
 * So the sentences are ordered by what the reader can change, not by what is
 * conventional.
 *
 * Bucketed on `reviewed_at` — when the review was left — rather than on
 * `created_at`, which is only when a sync happened to write it down.
 */
final class ReviewsGlance implements Glance
{
    public function key(): string
    {
        return 'reviews';
    }

    public function label(): string
    {
        return 'Reviews';
    }

    public function permission(): string
    {
        return 'reviews.view';
    }

    public function allowedPresentations(): array
    {
        return ['line', 'bar', 'text'];
    }

    public function defaultPresentation(): string
    {
        return 'line';
    }

    public function snapshot(): bool
    {
        return false;
    }

    public function resolve(User $user, DashboardPeriod $period, array $options): GlancePayload
    {
        return app(TenantContext::class)->run($user->organization, function () use ($user, $period) {
            $reachable = ViewScope::forUser($user)->franchiseIds();

            $from = $period->startsOn->copy()->utc();
            $to   = $period->endsOn->copy()->utc();

            // An organization-wide listing has no franchise and still belongs
            // to whoever can see this box.
            $inReach = fn ($query) => $query->where(
                fn ($q) => $q->whereNull('franchise_id')->orWhereIn('franchise_id', $reachable),
            );

            $total = (int) $inReach(Review::query())->count();

            if ($total === 0) {
                return $this->payload([
                    'empty'    => true,
                    'sentence' => 'No reviews yet.',
                ]);
            }

            $unanswered = (int) $inReach(Review::query())
                ->whereDoesntHave('reply', fn ($q) => $q->where('status', ReviewReply::STATUS_PUBLISHED))
                ->count();

            // Only rated reviews count toward an average. Google occasionally
            // returns one with no stars, and folding those in as zero would
            // libel the business by arithmetic.
            $average = $inReach(Review::query())->whereNotNull('rating')->avg('rating');

            $arrived = $inReach(Review::query())
                ->where('reviewed_at', '>=', $from)
                ->where('reviewed_at', '<=', $to)
                ->get(['id', 'reviewed_at']);

            $counts = $this->emptyBuckets($period);

            foreach ($arrived as $review) {
                $at = $review->reviewed_at;

                if (! $at instanceof Carbon) {
                    continue;
                }

                $bucket = $period->bucket($at);
                $counts[$bucket] = ($counts[$bucket] ?? 0) + 1;
            }

            $inPeriod = array_sum($counts);

            $sentences = [
                $unanswered === 0
                    ? 'Every review has been answered.'
                    : ($unanswered === 1 ? '1 review unanswered.' : "{$unanswered} reviews unanswered."),
                $average === null
                    ? 'No star ratings yet.'
                    : number_format((float) $average, 1).' stars across '.$total.'.',
                $inPeriod === 1
                    ? "1 arrived this {$period->key}."
                    : "{$inPeriod} arrived this {$period->key}.",
            ];

            $points = [];
            $slices = [];

            foreach ($counts as $at => $value) {
                $points[] = ['at' => $at, 'value' => $value];
                $slices[] = ['label' => $at, 'total' => $value];
            }

            return $this->payload([
                'empty'  => false,
                'series' => [['label' => 'Reviews', 'points' => $points]],
                'slices' => $slices,
                'sentences' => $sentences,
                'organization_total' => (int) Review::query()->count(),
            ]);
        });
    }

    /** @param array<string, mixed> $data */
    private function payload(array $data): GlancePayload
    {
        return new GlancePayload(
            key: $this->key(),
            label: $this->label(),
            presentation: $this->defaultPresentation(),
            allowedPresentations: $this->allowedPresentations(),
            snapshot: $this->snapshot(),
            comingSoon: false,
            options: [],
            data: $data,
        );
    }

    /** @return array<string, int> */
    private function emptyBuckets(DashboardPeriod $period): array
    {
        $out    = [];
        $cursor = $period->startsOn->copy()->startOfDay();
        $end    = $period->endsOn->copy()->startOfDay();

        while ($cursor->lte($end)) {
            $out[$period->bucket($cursor)] = 0;
            $cursor->addDay();
        }

        return $out;
    }
}
