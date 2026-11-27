<?php

namespace App\Support\Reports;

use App\Models\Franchise;
use App\Models\SocialConnection;
use App\Services\Ads\AdsCost;
use App\Support\Reports\Texts\BranchName;
use App\Support\Reports\Texts\PeriodReport;
use App\Support\Settings\Lists\BusinessLists;
use App\Support\Social\SocialProvider;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

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
    /** Workiz job source => the Google Ads channel that pays for it, until the business edits its list. */
    public const PAID = ['LS Ads' => 'lsa', 'PPC' => 'ppc', 'Meta' => 'meta'];

    /** @return array<string, string> job source => channel (lsa or ppc), as the business has it (Settings › Lists) */
    public static function paid(): array
    {
        $out = [];
        foreach (app(BusinessLists::class)->rows('reports.paid_sources') as $row) {
            $out[(string) ($row['value']['source'] ?? $row['key'])] = strtolower(trim((string) ($row['value']['channel'] ?? '')));
        }

        return $out;
    }

    /**
     * Job source => the sub company whose own ad accounts pay for it (Jose, 2026-11-16: Angi Sweep and
     * Ace showed no cost, their accounts being theirs, not the branch's). Settings › Lists, reports.paid_sources.
     *
     * @return array<string, string>
     */
    public static function companies(): array
    {
        $out = [];
        foreach (app(BusinessLists::class)->rows('reports.paid_sources') as $row) {
            if (($company = trim((string) ($row['value']['company'] ?? ''))) !== '') {
                $out[(string) ($row['value']['source'] ?? $row['key'])] = $company;
            }
        }

        return $out;
    }

    /** The job source a channel's jobs arrive as; null when the business named none. */
    public static function sourceOf(string $channel): ?string
    {
        $source = array_search($channel, self::paid(), true);

        return $source === false ? null : (string) $source;
    }

    /** @var (callable(Franchise, string, string): ?array)|null */
    private $cost;

    /** @var (callable(Collection<int, SocialConnection>, string, string): array)|null */
    private $accountsCost;

    /** @var array<string, array<string, int>> "from|to" => job source => jobs created, the organization's */
    private array $createdAll = [];

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

    /** Where a sub company's accounts' cost comes from: fn (accounts, from, to) => AdsCost::forAccounts' shape. */
    public function withAccountsCost(callable $cost): self
    {
        $this->accountsCost = $cost;

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
            ->withAccountsCost(fn ($a, $f, $t) => app(AdsCost::class)->forAccounts($a, CarbonImmutable::parse($f), CarbonImmutable::parse($t)->endOfDay()))
            ->rows($branch, $from, $to);
    }

    /** @return list<array<string, mixed>> */
    public function rows(Franchise $branch, string $from, string $to): array
    {
        $f = $this->period->figures($branch, $from, $to);
        [$margin, $noProfit] = $this->margin($branch, $from, $to);
        $ads = $this->cost ? ($this->cost)($branch, $from, $to) : null;
        $leads = $this->leads($branch, $from, $to);

        $paid = self::paid();
        $companies = self::companies();
        $sources = array_unique([...array_keys($f['created']), ...array_keys($f['closed']), ...array_keys($paid)]);
        $rows = [];

        foreach ($sources as $source) {
            $created = $f['created'][$source]['all'] ?? 0;
            $done = $f['closed'][$source]['done'] ?? 0;
            $gross = (int) round(($f['closed'][$source]['gross'] ?? 0) * 100);
            $channel = isset($companies[$source])
                ? $this->companyCost($branch, $source, $companies[$source], $paid[$source] ?? '', $from, $to, $created)
                : ($ads[$paid[$source] ?? ''] ?? null);
            $cost = $channel['cost_minor'] ?? null;
            $profit = $margin === null ? null : (int) round($gross * $margin);
            $lead = isset($companies[$source]) ? ($channel['leads'] ?? null) : ($leads[$source] ?? null);

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
                'cost_refused' => $channel === null ? 0 : count((isset($companies[$source]) ? $channel : $ads)['refused'] ?? []),
                // A share of a sub company's account that names no branch: an estimate, said on the cell.
                'cost_note' => $channel['note'] ?? null,
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
     * A sub company's own Google Ads accounts as one job source's cost and leads on this branch: the
     * accounts whose name ends in one of the branch's codes (Settings › Lists, reports.locations:
     * "AngiSweep - CT" is Connecticut's), plus, for those that end in no branch's code (one
     * "Ace Home Care LLC"), this branch's share of the source's jobs created. Null with no account.
     *
     * @return array{cost_minor: int, clicks: int, conversions: float, refused: list<array>, leads: ?array{leads: int, jobs: int}, note: ?string}|null
     */
    private function companyCost(Franchise $branch, string $source, string $company, string $channel, string $from, string $to, int $created): ?array
    {
        $companyId = DB::table('companies')->where('organization_id', $branch->organization_id)
            ->whereRaw('lower(trim(name)) = ?', [mb_strtolower($company)])->value('id');
        $accounts = $companyId === null ? collect() : SocialConnection::query()->withoutGlobalScopes()
            ->where('organization_id', $branch->organization_id)
            ->where('provider', SocialProvider::GoogleAds->value)
            ->where('status', SocialConnection::STATUS_CONNECTED)
            ->where('is_manager', false)
            ->where('scope_type', SocialConnection::SCOPE_COMPANY)->where('scope_id', $companyId)
            ->get();
        if ($accounts->isEmpty() || $this->accountsCost === null) {
            return null;
        }

        $codes = self::locationCodes();
        $code = fn (SocialConnection $a) => mb_strtolower(trim(Str::afterLast((string) $a->display_name, ' - ')));
        // Codes key on the branch name, which another company can share (Everdoors · Connecticut took
        // AngiSweep - CT on prod, 11-16): only a branch that books this source takes its accounts.
        $books = ReportJobs::jobs((string) $branch->id)->where('j.job_source', $source)->exists();
        $here = ! $books ? collect() : $accounts->filter(fn ($a) => in_array($code($a), $codes[mb_strtolower(trim((string) $branch->name))] ?? [], true))->values();
        $nowhere = $accounts->reject(fn ($a) => in_array($code($a), array_merge(...array_values($codes ?: [[]])), true))->values();

        $pick = fn (array $cost) => in_array($channel, ['lsa', 'ppc'], true)
            ? $cost[$channel]
            : ['cost_minor' => $cost['lsa']['cost_minor'] + $cost['ppc']['cost_minor'], 'clicks' => $cost['lsa']['clicks'] + $cost['ppc']['clicks'],
                'conversions' => $cost['lsa']['conversions'] + $cost['ppc']['conversions']];
        $out = ['cost_minor' => 0, 'clicks' => 0, 'conversions' => 0.0, 'refused' => [], 'leads' => null, 'note' => null];
        if ($here->isNotEmpty()) {
            $cost = ($this->accountsCost)($here, $from, $to);
            $out = ['cost_minor' => $pick($cost)['cost_minor'], 'clicks' => $pick($cost)['clicks'], 'conversions' => (float) $pick($cost)['conversions'],
                'refused' => $cost['refused'] ?? [], 'leads' => $this->accountLeads($here, $from, $to), 'note' => null];
        }

        $all = $this->createdAll($branch, $from, $to)[$source] ?? 0;
        if ($nowhere->isNotEmpty() && $created > 0 && $all > 0) {
            $share = $created / $all;
            $cost = ($this->accountsCost)($nowhere, $from, $to);
            $out['cost_minor'] += (int) round($pick($cost)['cost_minor'] * $share);
            $out['clicks'] += (int) round($pick($cost)['clicks'] * $share);
            $out['conversions'] += round($pick($cost)['conversions'] * $share, 1);
            $out['refused'] = [...$out['refused'], ...($cost['refused'] ?? [])];
            $out['note'] = 'Includes '.round($share * 100).'% of '.$nowhere->pluck('display_name')->implode(', ')
                ." (no branch in its name): this branch's {$created} of the {$all} {$source} jobs created.";
        } elseif ($here->isEmpty()) {
            return null;
        }

        return $out;
    }

    /** @return array<string, list<string>> branch name, lower case => its codes, lower case (reports.locations) */
    private static function locationCodes(): array
    {
        $out = [];
        foreach (app(BusinessLists::class)->rows('reports.locations') as $row) {
            $branch = mb_strtolower(trim((string) ($row['value']['branch'] ?? '')));
            foreach (explode(',', (string) ($row['value']['code'] ?? $row['key'])) as $code) {
                if (($code = mb_strtolower(trim($code))) !== '') {
                    $out[$branch][] = $code;
                }
            }
        }

        return $out;
    }

    /** @return array<string, int> job source => jobs created in the dates across the organization (the share's denominator) */
    private function createdAll(Franchise $branch, string $from, string $to): array
    {
        return $this->createdAll[$from.'|'.$to] ??= ReportJobs::created(
            DB::table('workiz_jobs as j')->where('j.organization_id', $branch->organization_id)
                ->whereBetween('j.booked_at', [$from.' 00:00:00', $to.' 23:59:59'])
                ->leftJoin('workiz_records as ec', fn ($on) => $on->on('ec.franchise_id', '=', 'j.franchise_id')
                    ->where('ec.type', 'external_company')->whereRaw("ec.remote_id = concat('EC-', j.external_company_id)"))
                ->get([DB::raw("coalesce(nullif(trim(json_unquote(json_extract(ec.payload, '$.company_name'))), ''), j.job_source) as job_source"),
                    DB::raw("coalesce(json_extract(j.payload, '$.Tags'), json_array()) as tags"), 'j.status',
                    DB::raw("cast(coalesce(json_unquote(json_extract(j.payload, '$.JobTotalPrice')), 0) as decimal(12,2)) as total")])
        )->countBy(fn ($j) => trim((string) $j->job_source) ?: 'No source')->all();
    }

    /**
     * The Local Services leads of these accounts in the dates, and how many became jobs.
     *
     * @param  Collection<int, SocialConnection>  $accounts
     * @return array{leads: int, jobs: int}|null
     */
    private function accountLeads(Collection $accounts, string $from, string $to): ?array
    {
        $r = DB::table('leads')->whereIn('connection_id', $accounts->modelKeys())->where('is_test', false)->where('source', 'local_services')
            ->whereBetween('received_at', [$from.' 00:00:00', $to.' 23:59:59'])
            ->selectRaw('count(*) leads, sum(job_id is not null) jobs')->first();

        return $r === null || ! $r->leads ? null : ['leads' => (int) $r->leads, 'jobs' => (int) $r->jobs];
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
        // Local Services leads arrive as the job source its channel pays for.
        if (($source = self::sourceOf('lsa')) === null) {
            return [];
        }

        $rows = DB::table('leads')
            ->join('social_connections', 'social_connections.id', '=', 'leads.connection_id')
            ->whereRaw(SocialConnection::BRANCH_SQL.' = ?', [$branch->getKey()])
            ->where('leads.is_test', false)
            ->where('leads.source', 'local_services')
            ->whereBetween('leads.received_at', [$from.' 00:00:00', $to.' 23:59:59'])
            ->groupBy('leads.source')
            ->selectRaw('leads.source, count(*) leads, sum(leads.job_id is not null) jobs')
            ->get();

        $out = [];
        foreach ($rows as $r) {
            $out[$source] = ['leads' => (int) $r->leads, 'jobs' => (int) $r->jobs];
        }

        return $out;
    }
}
