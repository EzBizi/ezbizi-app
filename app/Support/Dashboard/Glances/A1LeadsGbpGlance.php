<?php

namespace App\Support\Dashboard\Glances;

use App\Models\A1LeadsReviewEvent;
use App\Models\User;
use App\Services\A1Leads\A1LeadsOwner;
use App\Support\Dashboard\DashboardPeriod;
use App\Support\Dashboard\DescribedGlance;
use App\Support\Dashboard\GlancePayload;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Carbon;

/**
 * A1Leads GBP box: the new Google reviews EzBizi raised to Automations.
 *
 * It counts EzBizi's OWN rows - one `A1LeadsReviewEvent` per new review - and
 * not A1 Leads' thirteen thousand, which are somebody else's to count and a
 * day old. So it can only say what has arrived since the watcher started, and
 * it says "new", not "all".
 *
 * The low ratings are counted out separately, for the reason the Reviews box
 * gives: a one-star sits in public at the top of the listing, and it is the
 * number somebody can act on.
 *
 * Reach is the tab's own rule (`A1LeadsOwner`): A-1 Chimney in view, or the
 * whole organization. Anyone else is told whose reviews these are.
 */
final class A1LeadsGbpGlance extends DescribedGlance
{
    protected const KEY = 'a1leads_gbp';
    protected const LABEL = 'A1Leads GBP';
    protected const PERMISSION = 'a1leads_gbp.view';
    protected const PRESENTATIONS = ['bar', 'line', 'text'];
    protected const DEFAULT_PRESENTATION = 'bar';
    protected const SNAPSHOT = false;

    public function resolve(User $user, DashboardPeriod $period, array $options): GlancePayload
    {
        return app(TenantContext::class)->run($user->organization, function () use ($user, $period) {
            $company = config('services.a1leads.company_id');
            if (! is_string($company) || $company === ''
                || config('services.a1leads.organization_id') !== $user->organization_id
                || ! A1LeadsOwner::inView($user, $company)) {
                return $this->payload([
                    'empty' => true,
                    'sentence' => "These are A-1 Chimney's Google reviews. Switch to A-1 Chimney to see them.",
                ]);
            }

            $arrived = A1LeadsReviewEvent::query()
                ->where('company_id', $company)
                ->where('review_created_at', '>=', $period->startsOn->copy()->utc())
                ->where('review_created_at', '<=', $period->endsOn->copy()->utc())
                ->get(['id', 'rating', 'review_created_at']);

            if ($arrived->isEmpty()) {
                return $this->payload([
                    'empty' => true,
                    'sentence' => "No new Google reviews this {$period->key}.",
                ]);
            }

            $counts = $this->emptyBuckets($period);
            foreach ($arrived as $review) {
                if ($review->review_created_at instanceof Carbon) {
                    $bucket = $period->bucket($review->review_created_at);
                    $counts[$bucket] = ($counts[$bucket] ?? 0) + 1;
                }
            }

            $low = $arrived->filter(fn ($r) => $r->rating !== null && $r->rating <= 3)->count();
            $total = $arrived->count();

            $points = [];
            $slices = [];
            foreach ($counts as $at => $value) {
                $points[] = ['at' => $at, 'value' => $value];
                $slices[] = ['label' => $at, 'total' => $value];
            }

            return $this->payload([
                'empty' => false,
                'series' => [['label' => 'New reviews', 'points' => $points]],
                'slices' => $slices,
                'sentences' => [
                    $total === 1
                        ? "1 new Google review this {$period->key}."
                        : "{$total} new Google reviews this {$period->key}.",
                    $low === 0
                        ? 'None of them 1 to 3 stars.'
                        : ($low === 1 ? '1 of them 1 to 3 stars.' : "{$low} of them 1 to 3 stars."),
                ],
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
        $out = [];
        $cursor = $period->startsOn->copy()->startOfDay();
        $end = $period->endsOn->copy()->startOfDay();

        while ($cursor->lte($end)) {
            $out[$period->bucket($cursor)] = 0;
            $cursor->addDay();
        }

        return $out;
    }
}
