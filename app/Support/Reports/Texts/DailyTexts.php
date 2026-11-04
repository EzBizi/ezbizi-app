<?php

namespace App\Support\Reports\Texts;

use App\Models\Franchise;
use App\Models\Organization;
use App\Models\SocialConnection;
use App\Services\Ads\AdsCost;
use App\Support\Reports\Airtable\ReportSources;
use App\Support\Reports\Experimental\PerformancePeople;
use App\Support\Reports\ReportJobs;
use App\Support\Social\SocialProvider;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The daily and weekly WhatsApp texts (the A1 Leads takeover, 2026-10-20),
 * for any organization: Google PPC, other-marketing PPC, dispatchers,
 * technician sales, weekly net losses and weekly reviews. Each reads the
 * organization's own connections and mapped tables; delivery is Automations'
 * (a rule names the text and who gets it).
 *
 * Inside the tenant. $branches limits a text to those branches (a rule's
 * scope); null is every active branch.
 */
final class DailyTexts
{
    /** kind => what a person calls it. */
    public const KINDS = [
        'ppc' => 'Google PPC · daily breakdown',
        'marketing_ppc' => 'Other marketing PPC · daily update',
        'dispatchers' => 'Dispatcher performance · daily',
        'technician_sales' => 'Technician sales · daily',
        'weekly_losses' => 'Weekly net loss report',
        'weekly_reviews' => 'Weekly reviews by branch',
        'job_assignments' => 'Daily job assignments · In Progress by technician',
    ];

    public function __construct(
        private readonly PeriodReport $report,
        private readonly AdsCost $ads,
        private readonly PerformancePeople $performance,
    ) {}

    /** @param list<string>|null $branches */
    public function render(string $kind, Organization $organization, CarbonImmutable $day, ?array $branches = null): string
    {
        $list = Franchise::query()->where('is_active', true)
            ->when($branches !== null, fn ($q) => $q->whereIn('id', $branches))
            ->orderBy('name')->get();

        return match ($kind) {
            'ppc' => $this->ppc($list, $day),
            'marketing_ppc' => $this->marketingPpc($organization, $day, $branches === null ? null : $list),
            'dispatchers' => $this->dispatchers($day, $branches),
            'technician_sales' => $this->technicianSales($list, $day),
            'weekly_losses' => $this->weeklyLosses($list, $day),
            'weekly_reviews' => $this->weeklyReviews($list, $day),
            'job_assignments' => $this->jobAssignments($list, $day),
        };
    }

    /** Each branch's Google Search spend against its PPC jobs created that day. */
    private function ppc(Collection $branches, CarbonImmutable $day): string
    {
        $lines = ["\u{1F4CA} *Google PPC · Daily breakdown*", $day->format('D, M j, Y').' · Search ads', ''];
        $total = ['cost' => 0, 'clicks' => 0, 'jobs' => 0];

        foreach ($branches as $branch) {
            $cost = $this->ads->forBranch($branch, $day->startOfDay(), $day->endOfDay());
            if ($cost['accounts'] === 0) {
                continue;
            }
            $jobs = ReportJobs::jobs((string) $branch->getKey())->where('j.job_source', 'PPC')
                ->whereBetween('j.booked_at', [$day->toDateString().' 00:00:00', $day->toDateString().' 23:59:59'])->count();
            $c = $cost['ppc'];
            $lines = [...$lines, '*'.BranchName::of($branch).'*', ...$this->ppcLines($c['cost_minor'], $c['clicks'], $jobs, $c['conversions']), ''];
            $total['cost'] += $c['cost_minor'];
            $total['clicks'] += $c['clicks'];
            $total['jobs'] += $jobs;
        }

        if (count($lines) === 3) {
            return $lines[0]."\n".$lines[1]."\n\nNo branch has a Google Ads account linked.";
        }

        return implode("\n", [...$lines, '*TOTAL*', ...$this->ppcLines($total['cost'], $total['clicks'], $total['jobs'], null)]);
    }

    /** @return list<string> */
    private function ppcLines(int $costMinor, int $clicks, int $jobs, ?float $conversions): array
    {
        return array_values(array_filter([
            'Spent: '.self::money($costMinor).' · Clicks: '.$clicks,
            'Average per click: '.($clicks > 0 ? self::money(intdiv($costMinor, $clicks)) : 'N/A'),
            'PPC jobs created: '.$jobs.' · Cost per job: '.($jobs > 0 ? self::money(intdiv($costMinor, $jobs)) : 'N/A'),
            $conversions === null ? null : 'Google conversions: '.rtrim(rtrim(number_format($conversions, 1), '0'), '.'),
        ]));
    }

    /**
     * The mapped "marketing" tables (Financials → Settings): the latest day on
     * or before $day. $branches null: the organization's own; else only those branches' tables.
     */
    private function marketingPpc(Organization $organization, CarbonImmutable $day, ?Collection $branches): string
    {
        $read = fn (?string $branch) => ReportSources::rows((string) $organization->id, 'marketing', $branch, $day->subDays(14)->toDateString(), $day->toDateString());
        $rows = $branches === null ? $read(null) : $branches->flatMap(fn ($b) => $read((string) $b->getKey()))->values();
        $head = "\u{1F4CA} *Marketing PPC · Daily update*\nReport: ".$day->format('D, M j, Y');

        if ($rows->isEmpty()) {
            // Nothing to report is no text: a rule sends none (desk, 2026-11-03).
            return '';
        }

        $latest = $rows->max('date');
        $lines = [$head, '*Data date: '.CarbonImmutable::parse($latest)->format('M j').'*', ''];
        $total = ['cost' => 0, 'calls' => 0, 'jobs' => 0];
        foreach ($rows->where('date', $latest)->groupBy(fn ($r) => (string) ($r['area'] ?? 'No area'))->sortKeys() as $area => $r) {
            $cost = $r->sum(fn ($x) => AirtableSections::cents($x['amount'] ?? 0));
            $calls = (int) $r->sum(fn ($x) => (float) ($x['calls'] ?? 0));
            $jobs = (int) $r->sum(fn ($x) => (float) ($x['jobs'] ?? 0));
            $lines[] = '*'.$area.'*';
            $lines[] = '• Spent: '.self::money($cost).' · Calls: '.$calls.' · Jobs: '.$jobs;
            $lines[] = '• Cost/job: '.($jobs > 0 ? self::money(intdiv($cost, $jobs)) : 'N/A').' · Call-to-job: '.($calls > 0 ? number_format($jobs / $calls * 100, 1).'%' : 'N/A');
            $total['cost'] += $cost;
            $total['calls'] += $calls;
            $total['jobs'] += $jobs;
        }
        $lines[] = '';
        $lines[] = '*TOTAL* · Spent: '.self::money($total['cost']).' · Calls: '.$total['calls'].' · Jobs: '.$total['jobs'];

        return implode("\n", $lines);
    }

    /**
     * Performance's People for the day: who answered, and the jobs they created.
     * ponytail: calls are not per branch, so a branch-scoped rule gets no
     * figures; split by branch when the call log carries one.
     *
     * @param  list<string>|null  $branches
     */
    private function dispatchers(CarbonImmutable $day, ?array $branches): string
    {
        $head = "\u{1F4CB} *Dispatcher performance*\n".$day->format('D, M j, Y');
        // One section per phone account the rule covers (2026-11-04: with no
        // one signed in, first() read EverDoors' calls for A-1's rule). A branch
        // rule takes an account only when every branch it answers for is the
        // rule's: never the whole organization's figures (desk, 2026-10-27).
        $accounts = $this->phoneAccounts($branches);
        if ($accounts === []) {
            return $head."\n\nNo phone account answers for these branches alone.";
        }

        $sections = [];
        foreach ($accounts as $id => $name) {
            $rows = collect($this->performance->for(null, $day->startOfDay(), $day->endOfDay(), $id === '' ? null : (string) $id))
                ->filter(fn ($p) => ($p['answered'] ?? 0) > 0 || ($p['jobs'] ?? 0) > 0)
                ->sortByDesc(fn ($p) => $p['answered'] ?? 0);
            $title = count($accounts) > 1 ? ['*'.$name.'*'] : [];
            if ($rows->isEmpty()) {
                $sections[] = implode("\n", [...$title, 'No answered calls or jobs created.']);

                continue;
            }
            $lines = [...$title, '*Jobs created: '.$rows->sum('jobs').'*', ''];
            foreach ($rows as $p) {
                $new = (int) ($p['new_client'] ?? 0);
                // Booked out of the new-client calls they answered, as Performance (Trey, 2026-11-04).
                $lines[] = '• *'.$p['person'].'*: '.(int) ($p['jobs'] ?? 0).' jobs created · '.(int) ($p['answered'] ?? 0).' answered'
                    .($new > 0 ? ' · '.number_format(($p['booked_new'] ?? 0) / $new * 100, 1).'% of new-client calls booked' : '');
            }
            $sections[] = implode("\n", $lines);
        }

        return $head."\n\n".implode("\n\n", $sections);
    }

    /**
     * The connected RingCentral accounts a rule over $branches may read, id =>
     * name; '' is the env account (A1-Group before its connection), for the
     * whole organization only.
     *
     * @param  list<string>|null  $branches
     * @return array<string, string>
     */
    private function phoneAccounts(?array $branches): array
    {
        $all = SocialConnection::query()->withoutGlobalScopes()->where('organization_id', app(TenantContext::class)->idOrFail())
            ->where('provider', SocialProvider::RingCentral->value)->where('status', SocialConnection::STATUS_CONNECTED)
            ->orderBy('created_at')->get();
        if ($all->isEmpty()) {
            return $branches === null ? ['' => 'RingCentral'] : [];
        }
        // The branches an account answers for; null is the organization's own.
        $answersFor = fn (SocialConnection $c) => match ($c->scope_type) {
            'franchise' => [(string) $c->scope_id],
            'company' => Franchise::query()->where('company_id', $c->scope_id)->pluck('id')->map(fn ($id) => (string) $id)->all(),
            default => null,
        };

        return $all->filter(fn (SocialConnection $c) => $branches === null
            || (($covers = $answersFor($c)) !== null && $covers !== [] && array_diff($covers, $branches) === []))
            ->mapWithKeys(fn (SocialConnection $c) => [(string) $c->getKey() => (string) ($c->display_name ?: 'RingCentral')])
            ->all();
    }

    /**
     * Today's In Progress jobs per technician per branch, from EzBizi's own
     * jobs and crews (A1 Leads' daily-job-assignments, which read Workiz's
     * Jobs tab and under-counted). Jobs tagged Deposit Received (Workiz also spells it
     * "DEPOSIT RECIEVED") are left out.
     * ponytail: "today" is $day's date in every branch's own wall time, as
     * jobs store it.
     */
    private function jobAssignments(Collection $branches, CarbonImmutable $day): string
    {
        $jobs = DB::table('jobs')->whereIn('franchise_id', $branches->pluck('id'))->whereNull('deleted_at')
            ->whereRaw('lower(status) = ?', ['in progress'])->whereDate('scheduled_for', $day->toDateString())
            ->get(['id', 'franchise_id', 'tags'])
            ->reject(fn ($j) => array_intersect(['deposit received', 'deposit recieved'], array_map(fn ($t) => mb_strtolower(trim((string) $t)), (array) json_decode((string) $j->tags, true))) !== []);
        $crew = DB::table('job_technicians as jt')->join('scheduling_technicians as t', 't.id', '=', 'jt.technician_id')
            ->whereIn('jt.job_id', $jobs->pluck('id'))->get(['jt.job_id', 't.name'])->groupBy('job_id');
        $plural = fn (int $n) => $n.' job'.($n === 1 ? '' : 's');

        $lines = ["\u{1F9F0} *Today's In Progress jobs by technician*",
            '_'.$day->format('D, M j, Y').' · measured '.now(\App\Support\Settings\OrgTimezone::of())->format('g:i A T').' from EzBizi jobs_',
            '_Excludes jobs tagged Deposit Received._'];
        $empty = [];
        $assigned = 0;
        foreach ($branches as $branch) {
            $mine = $jobs->where('franchise_id', $branch->getKey());
            if ($mine->isEmpty()) {
                $empty[] = BranchName::of($branch);

                continue;
            }
            $lines[] = '';
            $lines[] = '*'.BranchName::of($branch).' — '.$plural($mine->count()).'*';
            $byTech = $mine->flatMap(fn ($j) => $crew->get($j->id, collect())->pluck('name'))->countBy()->sortKeys();
            foreach ($byTech as $name => $n) {
                $lines[] = '• '.$name.' — '.$plural($n);
            }
            $unassigned = $mine->filter(fn ($j) => ! $crew->has($j->id))->count();
            if ($unassigned > 0) {
                $lines[] = "• \u{26A0} Unassigned — ".$plural($unassigned);
            }
            $assigned += $mine->count() - $unassigned;
        }
        if ($empty !== []) {
            $lines[] = '';
            $lines[] = '_No jobs: '.implode(', ', $empty).'_';
        }
        $lines[] = '';
        $lines[] = '*All branches — '.$jobs->count().' In Progress '.($jobs->count() === 1 ? 'job' : 'jobs').'*';
        $lines[] = $assigned.' assigned · '.($jobs->count() - $assigned).' unassigned';

        return implode("\n", $lines);
    }

    /** Each branch's technicians that day: completed, canceled, sales and deposits. */
    private function technicianSales(Collection $branches, CarbonImmutable $day): string
    {
        $lines = ["*Technician sales*\n".$day->format('D, M j, Y'), ''];
        $all = ['done' => 0, 'canceled' => 0, 'gross' => 0.0, 'deposits' => 0.0];

        foreach ($branches as $branch) {
            $techs = $this->report->figures($branch, $day->toDateString(), $day->toDateString())['techs'];
            if ($techs === []) {
                continue;
            }
            $lines[] = '*'.BranchName::of($branch).'*';
            foreach ($techs as $t) {
                $lines[] = '• *'.$t['name'].'*: '.$t['done'].' completed · '.$t['canceled'].' canceled · '
                    .self::money((int) round($t['gross'] * 100)).' sales · '.self::money((int) round($t['deposits'] * 100)).' deposits';
                foreach (array_keys($all) as $k) {
                    $all[$k] += $t[$k];
                }
            }
            $lines[] = '';
        }

        if (count($lines) === 2) {
            return $lines[0]."\n\nNo technician activity.";
        }

        $lines[] = '*Total:* '.$all['done'].' completed · '.$all['canceled'].' canceled · '
            .self::money((int) round($all['gross'] * 100)).' sales · '.self::money((int) round($all['deposits'] * 100)).' deposits';

        return implode("\n", $lines);
    }

    /** Last Monday-Sunday: the branches whose profit & loss closed negative. */
    private function weeklyLosses(Collection $branches, CarbonImmutable $day): string
    {
        [$from, $to] = [$day->startOfWeek()->subWeek(), $day->startOfWeek()->subDay()];
        $losses = [];
        $pending = [];

        foreach ($branches as $branch) {
            $pnl = $this->report->profitAndLoss($branch, $from->toDateString(), $to->toDateString());
            if ($pnl['net'] === null) {
                $pending[] = BranchName::of($branch);
            } elseif ($pnl['net'] < 0) {
                $losses[] = [BranchName::of($branch), $pnl];
            }
        }

        usort($losses, fn ($a, $b) => $a[1]['net'] <=> $b[1]['net']);
        $lines = ["\u{1F4C9} *Weekly Net Loss Report*", '_'.$from->format('M j').' - '.$to->format('M j, Y').'_', ''];
        // No all-clear on missing data (desk, 2026-11-03): a branch whose week
        // is not complete cannot be judged, and the text says so first.
        $judged = $branches->count() - count($pending);
        if ($judged === 0) {
            return implode("\n", [...$lines, "\u{26A0}\u{FE0F} Can't be judged yet: no branch has a complete P&L for the week.", 'Not complete yet: '.implode(', ', $pending)]);
        }
        if ($pending !== []) {
            $lines[] = "\u{26A0}\u{FE0F} Only {$judged} of {$branches->count()} branches have a complete P&L for the week; the rest can't be judged yet.";
        }
        $lines[] = '• *'.count($losses).' '.(count($losses) === 1 ? 'branch' : 'branches').' with negative net profit*';
        $lines[] = '• *Combined losses: '.self::money((int) round(array_sum(array_map(fn ($l) => $l[1]['net'], $losses)) * 100)).'*';
        foreach ($losses as [$name, $pnl]) {
            $lines[] = '';
            $lines[] = '*'.$name.'*';
            $lines[] = '• Net profit: '.self::money((int) round($pnl['net'] * 100));
            $lines[] = '• Gross income: '.self::money((int) round($pnl['income'] * 100)).' · Total expenses: '.self::money((int) round($pnl['costs'] * 100));
        }
        if ($pending !== []) {
            $lines[] = '';
            $lines[] = "\u{26A0}\u{FE0F} Not complete yet: ".implode(', ', $pending);
        }

        return implode("\n", $lines);
    }

    /** Last Monday-Sunday: new Google reviews per branch, 4-5 stars positive, 1-3 negative. */
    private function weeklyReviews(Collection $branches, CarbonImmutable $day): string
    {
        [$from, $to] = [$day->startOfWeek()->subWeek(), $day->startOfWeek()->subDay()->endOfDay()];
        $counts = DB::table('reviews')->whereIn('franchise_id', $branches->pluck('id'))
            ->whereBetween('reviewed_at', [$from->utc(), $to->utc()])
            ->groupBy('franchise_id')
            ->selectRaw('franchise_id, sum(rating >= 4) positive, sum(rating between 1 and 3) negative')
            ->get()->keyBy('franchise_id');

        $lines = ["\u{2B50} *Weekly reviews by branch*", '_'.$from->format('M j').' - '.$to->format('M j, Y').'_', ''];
        $lines[] = '*Total: '.(int) $counts->sum(fn ($c) => $c->positive + $c->negative).' reviews*';
        $lines[] = "\u{2705} ".(int) $counts->sum('positive').' positive · '."\u{26A0}\u{FE0F} ".(int) $counts->sum('negative').' negative';
        foreach ($branches as $branch) {
            if (($c = $counts->get($branch->getKey())) !== null) {
                $lines[] = '';
                $lines[] = '*'.BranchName::of($branch).'*  • '.(int) $c->positive.' positive  • '.(int) $c->negative.' negative';
            }
        }
        $lines[] = '';
        $lines[] = '_Positive: 4–5★ · Negative: 1–3★. New reviews in this week only._';

        return implode("\n", $lines);
    }

    private static function money(int $cents): string
    {
        return ($cents < 0 ? '-$' : '$').number_format(abs($cents) / 100, 2);
    }
}
