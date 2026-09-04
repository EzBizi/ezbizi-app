<?php

namespace App\Support\Dashboard\Glances;

use App\Models\Customer;
use App\Models\User;
use App\Support\Dashboard\DashboardPeriod;
use App\Support\Dashboard\DescribedGlance;
use App\Support\Dashboard\GlancePayload;
use App\Support\Scoping\ViewScope;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Carbon;

/**
 * Customers box: in-reach book as a snapshot, new customers as a line.
 *
 * Empty only when nobody is in the book. Buckets follow DashboardPeriod
 * (day / week / month) so a quiet stretch still draws zeros. organization_total
 * is the whole tenant book — reach is accountability for the line, not a read
 * gate on customers.
 */
final class CustomersGlance extends DescribedGlance
{
    protected const KEY = 'customers';
    protected const LABEL = 'Customers';
    protected const PERMISSION = 'customers.view';
    protected const PRESENTATIONS = ['line', 'bar', 'text'];
    protected const DEFAULT_PRESENTATION = 'line';
    protected const SNAPSHOT = false;

    public function resolve(User $user, DashboardPeriod $period, array $options): GlancePayload
    {
        return app(TenantContext::class)->run($user->organization, function () use ($user, $period) {
            $reachable = ViewScope::forUser($user)->franchiseIds();

            // Period bounds are wall times in the view timezone; created_at is
            // stored UTC. Bind UTC instants so "now" in New York is not compared
            // as an earlier naive clock string than the row.
            $from = $period->startsOn->copy()->utc();
            $to = $period->endsOn->copy()->utc();

            $book = (int) Customer::query()
                ->whereIn('owning_franchise_id', $reachable)
                ->count();

            if ($book === 0) {
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
                        'sentence' => 'No new customers this '.$period->key.'.',
                    ],
                );
            }

            $mine = Customer::query()
                ->whereIn('owning_franchise_id', $reachable)
                ->where('created_at', '>=', $from)
                ->where('created_at', '<=', $to)
                ->get(['id', 'created_at']);

            $counts = $this->emptyBuckets($period);
            foreach ($mine as $customer) {
                $at = $customer->created_at;
                if (! $at instanceof Carbon) {
                    continue;
                }
                $bucket = $period->bucket($at);
                $counts[$bucket] = ($counts[$bucket] ?? 0) + 1;
            }

            $added = array_sum($counts);
            $periodWord = $period->key;
            $sentences = [
                $book === 1 ? '1 customer in reach.' : "{$book} customers in reach.",
                "{$added} new this {$periodWord}.",
            ];

            $organizationTotal = Customer::query()->count();

            $points = [];
            $slices = [];
            foreach ($counts as $at => $value) {
                $points[] = ['at' => $at, 'value' => $value];
                // Bar (and pie, if ever offered) read slices, not series.
                // Same buckets; label is the axis key so a year month stays
                // readable (`2026-08`) without the client aggregating.
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
                    'empty' => false,
                    'series' => [
                        [
                            'label' => 'Added',
                            'points' => $points,
                        ],
                    ],
                    'slices' => $slices,
                    'sentences' => $sentences,
                    'organization_total' => $organizationTotal,
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
