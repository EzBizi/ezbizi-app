<?php

namespace App\Support\Reports;

use App\Models\Franchise;
use App\Services\Ads\AdsCost;
use App\Support\Reports\Texts\BranchName;
use App\Support\Reports\Texts\PeriodReport;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Ad cost against what it brought in, per branch and Workiz job source (Jose,
 * 2026-10-12: clicks + cost, conversions + cost, jobs created per source,
 * leads to jobs, average cost per job, done jobs, gross sales, profit, and
 * net profit = profit - cost).
 *
 * Jobs and sales are Workiz's (the same counting as the period PDFs). Profit
 * is an ESTIMATE, Jose's choice of three: Workiz holds no job costs, so each
 * source's gross is multiplied by the branch's Airtable P&L margin for the
 * same dates, taken BEFORE marketing so the ad cost is not subtracted twice.
 * Ad cost arrives per branch and channel from the Google Ads module; until an
 * account is linked to a branch its cells are null, never zero.
 */
final class MarketingReport
{
    /** Workiz job source => the Google Ads channel that pays for it. */
    public const PAID = ['LS Ads' => 'lsa', 'PPC' => 'ppc'];

    /** Lead source => the Workiz job source it arrives as. */
    private const LEAD_SOURCES = ['local_services' => 'LS Ads'];

    /** @var (callable(Franchise, string, string): ?array)|null */
    private $cost;

    public function __construct(private readonly PeriodReport $period) {}

    /**
     * Where ad cost comes from: fn (branch, from, to) => ['lsa' => [cost_minor,
     * clicks, conversions], 'ppc' => [...]] or null when not known.
     */
    public function withCost(callable $cost): self
    {
        $this->cost = $cost;

        return $this;
    }

    /**
     * The rows with Google Ads cost (AdsCost, Google API module), as the
     * screen shows them. Reused by the Grok notes so they read our numbers.
     *
     * @return list<array<string, mixed>>
     */
    public function forBranch(Franchise $branch, string $from, string $to): array
    {
        return $this->withCost(fn ($b, $f, $t) => app(AdsCost::class)->forBranch($b, CarbonImmutable::parse($f), CarbonImmutable::parse($t)->endOfDay()))
            ->rows($branch, $from, $to);
    }

    /** @return list<array<string, mixed>> */
    public function rows(Franchise $branch, string $from, string $to): array
    {
        $f = $this->period->figures($branch, $from, $to);
        [$margin, $noProfit] = $this->margin($branch, $from, $to);
        $ads = $this->cost ? ($this->cost)($branch, $from, $to) : null;
        $leads = $this->leads($branch, $from, $to);

        $sources = array_unique([...array_keys($f['created']), ...array_keys($f['closed']), ...array_keys(self::PAID)]);
        $rows = [];

        foreach ($sources as $source) {
            $created = $f['created'][$source]['all'] ?? 0;
            $done = $f['closed'][$source]['done'] ?? 0;
            $gross = (int) round(($f['closed'][$source]['gross'] ?? 0) * 100);
            $channel = $ads[self::PAID[$source] ?? ''] ?? null;
            $cost = $channel['cost_minor'] ?? null;
            $profit = $margin === null ? null : (int) round($gross * $margin);
            $lead = $leads[$source] ?? null;

            if (! $created && ! $done && $channel === null) {
                continue;
            }

            $rows[] = [
                'branch' => BranchName::of($branch),
                'source' => $source,
                'clicks' => $channel['clicks'] ?? null,
                'conversions' => $channel['conversions'] ?? null,
                'cost_minor' => $cost,
                // Accounts AdsCost could not read: the cost is then too low.
                'cost_refused' => $channel === null ? 0 : count($ads['refused'] ?? []),
                'leads' => $lead['leads'] ?? null,
                // The jobs those leads became, so a total can use the column's formula.
                'lead_jobs' => $lead['jobs'] ?? null,
                'leads_to_jobs' => $lead === null || ! $lead['leads'] ? null : round($lead['jobs'] / $lead['leads'] * 100, 1),
                'jobs_created' => $created,
                'cost_per_job_minor' => $cost === null || ! $created ? null : intdiv($cost, $created),
                'done_jobs' => $done,
                'gross_minor' => $gross,
                'profit_minor' => $profit,
                'net_profit_minor' => $profit === null ? null : $profit - ($cost ?? 0),
                // Why there is no profit, said on the cell (Jose kept asking).
                'profit_missing' => $noProfit,
            ];
        }

        usort($rows, fn ($a, $b) => [$b['cost_minor'] ?? -1, $b['gross_minor']] <=> [$a['cost_minor'] ?? -1, $a['gross_minor']]);

        return $rows;
    }

    /**
     * (Net + marketing) / income from the branch's P&L: the margin before
     * marketing; or null and why, when the P&L has no complete figures for
     * the dates (its weeks run Monday to Sunday, so a part week has none).
     *
     * @return array{0: ?float, 1: ?string}
     */
    private function margin(Franchise $branch, string $from, string $to): array
    {
        $pnl = $this->period->profitAndLoss($branch, $from, $to);

        if (! $pnl['income'] || $pnl['net'] === null) {
            return [null, $pnl['why'] ?: 'the P&L is not filled in for every week of these dates'];
        }

        $marketing = array_sum(array_map(fn ($w) => $w['categories']['Marketing']['total'] ?? 0, $pnl['weeks']));

        return [($pnl['net'] + $marketing) / $pnl['income'], null];
    }

    /**
     * Leads received in the dates, by the Workiz source they become, through
     * the ad account's branch; with how many were matched to a job.
     *
     * @return array<string, array{leads: int, jobs: int}>
     */
    private function leads(Franchise $branch, string $from, string $to): array
    {
        $rows = DB::table('leads')
            ->join('social_connections', 'social_connections.id', '=', 'leads.connection_id')
            ->where('social_connections.leads_franchise_id', $branch->getKey())
            ->where('leads.is_test', false)
            ->whereIn('leads.source', array_keys(self::LEAD_SOURCES))
            ->whereBetween('leads.received_at', [$from.' 00:00:00', $to.' 23:59:59'])
            ->groupBy('leads.source')
            ->selectRaw('leads.source, count(*) leads, sum(leads.job_id is not null) jobs')
            ->get();

        $out = [];
        foreach ($rows as $r) {
            $out[self::LEAD_SOURCES[$r->source]] = ['leads' => (int) $r->leads, 'jobs' => (int) $r->jobs];
        }

        return $out;
    }
}
