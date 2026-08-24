<?php

namespace App\Support\Dashboard\Glances;

use App\Models\Customer;
use App\Models\User;
use App\Support\Dashboard\DashboardPeriod;
use App\Support\Dashboard\Glance;
use App\Support\Dashboard\GlancePayload;
use App\Support\Scoping\ViewScope;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Carbon;

/**
 * Customers box: new customers owned in reach as a line over the period.
 *
 * Buckets follow DashboardPeriod (day / week / month). Organization-wide total
 * sits beside the in-reach count the same way CustomersAdded does — reach is
 * accountability for the line, not a read gate on customers.
 */
final class CustomersGlance implements Glance
{
    public function key(): string
    {
        return 'customers';
    }

    public function label(): string
    {
        return 'Customers';
    }

    public function permission(): string
    {
        return 'customers.view';
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

            // Period bounds are wall times in the view timezone; created_at is
            // stored UTC. Bind UTC instants so "now" in New York is not compared
            // as an earlier naive clock string than the row.
            $from = $period->startsOn->copy()->utc();
            $to = $period->endsOn->copy()->utc();

            $mine = Customer::query()
                ->whereIn('owning_franchise_id', $reachable)
                ->where('created_at', '>=', $from)
                ->where('created_at', '<=', $to)
                ->get(['id', 'created_at']);

            if ($mine->isEmpty()) {
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

            $counts = $this->emptyBuckets($period);
            foreach ($mine as $customer) {
                $at = $customer->created_at;
                if (! $at instanceof Carbon) {
                    continue;
                }
                $bucket = $period->bucket($at);
                $counts[$bucket] = ($counts[$bucket] ?? 0) + 1;
            }

            $total = $mine->count();
            $periodWord = $period->key;
            $sentence = $total === 1
                ? "1 new customer this {$periodWord}."
                : "{$total} new customers this {$periodWord}.";

            $organizationTotal = Customer::query()
                ->where('created_at', '>=', $from)
                ->where('created_at', '<=', $to)
                ->count();

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
                    'sentences' => [$sentence],
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
