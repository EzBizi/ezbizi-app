<?php

namespace App\Support\Financials;

use App\Models\FinancialExpense;
use App\Models\FinancialMarketMap;
use App\Models\Franchise;
use App\Models\SocialConnection;
use App\Models\User;
use App\Support\Reports\ReportJobs;
use App\Support\Scoping\ViewScope;
use App\Support\Social\SocialProvider;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Expenses per market (branch), by week and by category, beside each
 * market's closing rate and cost per closed job (Shoval via Jose's board,
 * 2026-11-20). Every tenant, read inside the asker's current view.
 *
 *  - Expenses: QuickBooks lines (financial_expenses). A line's market is its
 *    Location's branch when that is mapped, else its Class's; neither is
 *    "Unassigned", shown only to an organization view.
 *  - Category: the expense account.
 *  - Weeks start Monday; a week is labelled by its Monday.
 *  - Closing rate: Reports' "leads to jobs" (MarketingReport::rows): leads
 *    received in the dates that became a job ÷ leads received, test leads
 *    left out.
 *  - Closed jobs: jobs Done with closed_at in the dates (ReportJobs, the one
 *    place reports read jobs). Cost per closed job = expenses ÷ closed jobs.
 */
final class MarketFinancials
{
    /** @return array<string, mixed> */
    public function report(User $viewer, string $from, string $to): array
    {
        $scope = ViewScope::forUser($viewer);
        $franchises = Franchise::query()->whereIn('id', $scope->franchiseIds())->orderBy('name')->get(['id', 'name']);
        $inView = $franchises->pluck('name', 'id')->all();
        $withUnassigned = $scope->isOrganization();

        $maps = [];
        foreach (FinancialMarketMap::query()->whereNotNull('franchise_id')->get() as $m) {
            $maps[$m->realm_id.'|'.$m->dimension.'|'.$m->remote_id] = (string) $m->franchise_id;
        }

        $markets = [];
        $blank = fn (?string $id, string $name) => ['franchise_id' => $id, 'name' => $name, 'expenses_cents' => 0, 'by_category' => [], 'weeks' => []];
        foreach ($inView as $id => $name) {
            $markets[$id] = $blank($id, $name);
        }

        FinancialExpense::query()->whereBetween('txn_date', [$from, $to])
            ->orderBy('txn_date')
            ->each(function (FinancialExpense $e) use (&$markets, $maps, $inView, $withUnassigned, $blank) {
                $market = self::marketOf($e, $maps);
                if ($market === null) {
                    if (! $withUnassigned) {
                        return;
                    }
                    $markets['unassigned'] ??= $blank(null, 'Unassigned');
                    $key = 'unassigned';
                } elseif (! isset($inView[$market])) {
                    return;
                } else {
                    $key = $market;
                }

                $cents = (int) $e->amount_cents;
                $category = (string) ($e->account_name ?: 'Uncategorised');
                $week = CarbonImmutable::parse($e->txn_date)->startOfWeek(CarbonImmutable::MONDAY)->toDateString();
                $markets[$key]['expenses_cents'] += $cents;
                $markets[$key]['by_category'][$category] = ($markets[$key]['by_category'][$category] ?? 0) + $cents;
                $markets[$key]['weeks'][$week]['cents'] = ($markets[$key]['weeks'][$week]['cents'] ?? 0) + $cents;
                $markets[$key]['weeks'][$week]['by_category'][$category] = ($markets[$key]['weeks'][$week]['by_category'][$category] ?? 0) + $cents;
            });

        $leads = DB::table('leads')->where('organization_id', $viewer->organization_id)
            ->whereIn('franchise_id', array_keys($inView))->where('is_test', false)
            ->whereBetween('received_at', [$from.' 00:00:00', $to.' 23:59:59'])
            ->groupBy('franchise_id')
            ->selectRaw('franchise_id, count(*) leads, sum(job_id is not null) won')
            ->get()->keyBy('franchise_id');

        $out = [];
        foreach ($markets as $key => $m) {
            $id = $m['franchise_id'];
            $l = $id !== null ? ($leads[$id] ?? null) : null;
            $closed = $id !== null ? self::closedJobs($id, $from, $to) : null;
            $out[] = [
                'franchise_id' => $id,
                'name' => $m['name'],
                'expenses_cents' => $m['expenses_cents'],
                'by_category' => self::categories($m['by_category']),
                'weeks' => collect($m['weeks'])->sortKeys()->map(fn ($w, $start) => [
                    'week_start' => $start, 'cents' => $w['cents'], 'by_category' => self::categories($w['by_category']),
                ])->values()->all(),
                'leads' => $l ? (int) $l->leads : ($id !== null ? 0 : null),
                'leads_won' => $l ? (int) $l->won : ($id !== null ? 0 : null),
                'closing_rate' => $l && $l->leads ? round($l->won / $l->leads * 100, 1) : null,
                'closed_jobs' => $closed,
                'cost_per_closed_job_cents' => $closed ? (int) round($m['expenses_cents'] / $closed) : null,
            ];
        }

        $connections = SocialConnection::query()->where('provider', SocialProvider::QuickBooks->value)->get();

        return [
            'from' => $from,
            'to' => $to,
            'markets' => $out,
            'total_expenses_cents' => array_sum(array_column($out, 'expenses_cents')),
            // Averages across the markets that have a rate, so a market with no leads does not read as 0%.
            'average_closing_rate' => ($rates = array_filter(array_column($out, 'closing_rate'), fn ($r) => $r !== null))
                ? round(array_sum($rates) / count($rates), 1) : null,
            'quickbooks' => [
                'connected' => $connections->isNotEmpty(),
                'synced_at' => $connections->map(fn ($c) => $c->setup['quickbooks']['synced_at'] ?? null)->filter()->max(),
            ],
        ];
    }

    /** @param  array<string, string>  $maps realm|dimension|remote id => franchise id */
    public static function marketOf(FinancialExpense $e, array $maps): ?string
    {
        return ($e->department_id !== null ? ($maps[$e->realm_id.'|department|'.$e->department_id] ?? null) : null)
            ?? ($e->class_id !== null ? ($maps[$e->realm_id.'|class|'.$e->class_id] ?? null) : null);
    }

    private static function closedJobs(string $franchiseId, string $from, string $to): int
    {
        return ReportJobs::jobs($franchiseId)->where('j.status', 'Done')
            ->whereRaw('coalesce(j.closed_at, j.last_status_update) between ? and ?', [$from.' 00:00:00', $to.' 23:59:59'])
            ->count();
    }

    /** @return list<array{category: string, cents: int}> largest first */
    private static function categories(array $byCategory): array
    {
        arsort($byCategory);

        return array_map(fn ($c, $cents) => ['category' => (string) $c, 'cents' => $cents], array_keys($byCategory), $byCategory);
    }
}
