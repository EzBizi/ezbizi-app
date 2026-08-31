<?php

namespace App\Support\Dashboard\Glances;

use App\Models\Lead;
use App\Models\User;
use App\Support\Dashboard\DashboardPeriod;
use App\Support\Dashboard\DescribedGlance;
use App\Support\Dashboard\GlancePayload;
use App\Support\Scoping\ViewScope;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Carbon;

/**
 * Leads box: enquiries arriving as a line, and how many are still untouched.
 *
 * ── The number that leads with is the unanswered one ─────────────
 *
 * Every other glance leads with a total, because a total is what those modules
 * are about. This one leads with how many are still `new`, because that is the
 * only number on the dashboard that represents a member of the public waiting
 * for a call back. Forty leads this week is a good week; forty leads nobody has
 * opened is a problem, and a box that showed only the first would report the
 * good week on the morning of the bad one.
 *
 * Bucketed on `received_at` — when the enquiry was MADE — rather than on
 * `created_at`, which is when a sync happened to write it down. A fetch that
 * runs once at nine in the morning would otherwise draw every one of the
 * previous day's enquiries as a spike at nine.
 */
final class LeadsGlance extends DescribedGlance
{
    protected const KEY = 'leads';
    protected const LABEL = 'Leads';
    protected const PERMISSION = 'leads.view';
    protected const PRESENTATIONS = ['line', 'bar', 'text'];
    protected const DEFAULT_PRESENTATION = 'line';
    protected const SNAPSHOT = false;

    public function resolve(User $user, DashboardPeriod $period, array $options): GlancePayload
    {
        return app(TenantContext::class)->run($user->organization, function () use ($user, $period) {
            $reachable = ViewScope::forUser($user)->franchiseIds();

            // Period bounds are wall times in the view timezone; received_at is
            // stored UTC. Bind UTC instants so "now" in New York is not compared
            // as an earlier naive clock string than the row.
            $from = $period->startsOn->copy()->utc();
            $to   = $period->endsOn->copy()->utc();

            // An organization-wide lead has no franchise, and it still belongs
            // to whoever can see this box. Filtering on the franchise column
            // alone would hide every lead that arrived through a connection
            // scoped at the top — which, for a single-company tenant, is all
            // of them.
            $inReach = fn ($query) => $query->where(
                fn ($q) => $q->whereNull('franchise_id')->orWhereIn('franchise_id', $reachable),
            );

            $total = (int) $inReach(Lead::query())->count();

            if ($total === 0) {
                return new GlancePayload(
                    key: $this->key(),
                    label: $this->label(),
                    presentation: $this->defaultPresentation(),
                    allowedPresentations: $this->allowedPresentations(),
                    snapshot: $this->snapshot(),
                    comingSoon: false,
                    options: [],
                    data: [
                        'empty' => true,
                        'sentence' => 'No enquiries yet.',
                    ],
                );
            }

            $waiting = (int) $inReach(Lead::query())
                ->where('status', Lead::STATUS_NEW)
                ->count();

            $arrived = $inReach(Lead::query())
                ->where('received_at', '>=', $from)
                ->where('received_at', '<=', $to)
                ->get(['id', 'received_at']);

            $counts = $this->emptyBuckets($period);

            foreach ($arrived as $lead) {
                $at = $lead->received_at;

                if (! $at instanceof Carbon) {
                    continue;
                }

                $bucket = $period->bucket($at);
                $counts[$bucket] = ($counts[$bucket] ?? 0) + 1;
            }

            $periodWord = $period->key;
            $inPeriod   = array_sum($counts);

            $sentences = [
                $waiting === 1
                    ? '1 lead not yet dealt with.'
                    : "{$waiting} leads not yet dealt with.",
                $inPeriod === 1
                    ? "1 arrived this {$periodWord}."
                    : "{$inPeriod} arrived this {$periodWord}.",
            ];

            $points = [];
            $slices = [];

            foreach ($counts as $at => $value) {
                $points[] = ['at' => $at, 'value' => $value];
                $slices[] = ['label' => $at, 'total' => $value];
            }

            return new GlancePayload(
                key: $this->key(),
                label: $this->label(),
                presentation: $this->defaultPresentation(),
                allowedPresentations: $this->allowedPresentations(),
                snapshot: $this->snapshot(),
                comingSoon: false,
                options: [],
                data: [
                    'empty'  => false,
                    'series' => [
                        [
                            'label'  => 'Arrived',
                            'points' => $points,
                        ],
                    ],
                    'slices'             => $slices,
                    'sentences'          => $sentences,
                    'organization_total' => (int) Lead::query()->count(),
                ],
            );
        });
    }

    /**
     * Every axis key from startsOn through endsOn, zeros included so the line
     * does not skip quiet days / weeks / months.
     *
     * @return array<string, int>
     */
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
