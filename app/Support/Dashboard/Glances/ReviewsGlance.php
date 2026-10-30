<?php

namespace App\Support\Dashboard\Glances;

use App\Models\Review;
use App\Models\ReviewReply;
use App\Models\User;
use App\Support\Dashboard\DashboardPeriod;
use App\Support\Dashboard\DescribedGlance;
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
final class ReviewsGlance extends DescribedGlance
{
    protected const KEY = 'reviews';
    protected const LABEL = 'Reviews';
    protected const PERMISSION = 'reviews.view';
    protected const PRESENTATIONS = ['line', 'bar', 'text'];
    protected const DEFAULT_PRESENTATION = 'line';
    protected const SNAPSHOT = false;

    public function resolve(User $user, DashboardPeriod $period, array $options): GlancePayload
    {
        return app(TenantContext::class)->run($user->organization, function () use ($user, $period) {
            $view = ViewScope::forUser($user);

            $from = $period->startsOn->copy()->utc();
            $to   = $period->endsOn->copy()->utc();

            // An organization-wide listing has no franchise and still belongs
            // to whoever can see this box.
            $inReach = fn ($query) => $view->constrainPlaces($query);

            $total = (int) $inReach(Review::query())->count();

            if ($total === 0) {
                return $this->payload([
                    'empty' => true,
                    /*
                     * "In this view", because this box is scoped and the
                     * sentence it carried was not.
                     *
                     * "No reviews yet." says the BUSINESS has none, from a box
                     * that saw the part of it this reader may see. Board-wide
                     * ruling, UI/UX 30ff2369 — and this string is the one that
                     * sent that ruling the wrong way for two hours, cited to
                     * UI/UX as proof the board already wrote the compliant
                     * plain form. It was not compliant; it was the defect,
                     * standing where the proof should have been.
                     */
                    'sentence' => 'No reviews in this view.',
                ]);
            }

            $unanswered = (int) $inReach(Review::query())
                ->whereDoesntHave('reply', fn ($q) => $q->where('status', ReviewReply::STATUS_PUBLISHED))
                ->count();

            // The ones that cost most left unanswered: 1 to 3 stars.
            $lowUnanswered = (int) $inReach(Review::query())
                ->whereDoesntHave('reply', fn ($q) => $q->where('status', ReviewReply::STATUS_PUBLISHED))
                ->where('rating', '<=', 3)
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
                /*
                 * The good news is the one that must not overreach. "Every
                 * review has been answered." to a franchise reader means every
                 * review THEY can see, and reads as nothing needs attention
                 * anywhere — the most expensive false reassurance this
                 * dashboard could give, since an unanswered one-star sits in
                 * public at the top of the listing.
                 */
                $unanswered === 0
                    ? 'Every review in this view has been answered.'
                    : ($unanswered === 1 ? '1 review unanswered.' : "{$unanswered} reviews unanswered."),
                /*
                 * Not "No star ratings yet.": these reviews are here and carry
                 * no stars, which is a fact about the rows the box is holding
                 * rather than a wait for something to arrive. Parallel with
                 * the rated form so the slot reads the same either way.
                 */
                $average === null
                    ? 'No star ratings across '.$total.'.'
                    : number_format((float) $average, 1).' stars across '.$total.'.',
                $inPeriod === 1
                    ? "1 arrived {$period->phrase()}."
                    : "{$inPeriod} arrived {$period->phrase()}.",
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
                'figures' => [
                    GlancePayload::figure('arrived', 'arrived '.$period->phrase(), $inPeriod, $this->arrivedIn($period->previous(), $inReach)),
                    GlancePayload::figure('unanswered', 'unanswered', $unanswered),
                ],
                ...($lowUnanswered === 0 ? [] : ['attention' => [GlancePayload::attention('bad', $lowUnanswered === 1
                    ? '1 review of 1 to 3 stars unanswered'
                    : "{$lowUnanswered} reviews of 1 to 3 stars unanswered")]]),
                /*
                 * `organization_total` was here and is gone. Dashboard's rule,
                 * 2026-09-15: a glance sends what its box draws, and nothing
                 * else. Nothing in client/lib read it, so it was a COUNT(*) on
                 * every dashboard load for a figure nobody sees.
                 *
                 * And it was bare - `Review::query()->count()` with no reach
                 * clause, sitting in the same payload as sentences built from
                 * `$inReach(...)`. Two numbers answering different questions,
                 * one of them the whole organization's, delivered to a reader
                 * who may see one franchise and can open the network tab. That
                 * is the exact overreach the sentences above were rewritten to
                 * stop, still shipping one field below them.
                 *
                 * The fifth of five glances to carry it, each written by
                 * starting from the last. Customers keeps its own and is the
                 * legitimate shape: its docblock argues the case. The
                 * exception is available and costs a sentence - if this box
                 * ever needs a figure it does not draw, say why here.
                 */
            ]);
        });
    }

    /**
     * How many reviews were left in a window, on the same `reviewed_at` and
     * the same reach the line reads. Only a count: the figure's `previous`
     * needs no buckets.
     */
    private function arrivedIn(DashboardPeriod $period, callable $inReach): int
    {
        return (int) $inReach(Review::query())
            ->where('reviewed_at', '>=', $period->startsOn->copy()->utc())
            ->where('reviewed_at', '<=', $period->endsOn->copy()->utc())
            ->count();
    }

    /** @return array<string, int> */
    private function emptyBuckets(DashboardPeriod $period): array
    {
        return $period->emptyBuckets();
    }
}
