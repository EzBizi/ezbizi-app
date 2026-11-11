<?php

namespace App\Support\Reports\Texts;

use App\Models\Franchise;
use App\Models\Organization;
use App\Support\Files\HardenedPdf;
use App\Support\Reports\Airtable\AirtablePnl;
use App\Support\Reports\Airtable\ReportSources;
use App\Support\Reports\LocationOrder;
use App\Support\Reports\ReportJobs;
use App\Support\Settings\OrgTimezone;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * The weekly and monthly PDF, per branch and consolidated, for any
 * organization: ad sources (created and closed), field performance with
 * deposits, repair teams, refunds and disputes, the profit and loss, and HQ
 * office expenses on the consolidated one. The Airtable parts come from the
 * tables the organization mapped in Financials → Settings (ReportSources);
 * colours from its goals (ReportGoals). Not here yet: LSA by location,
 * reviews, monthly bills.
 *
 * Workiz stamps are the account's own clock, compared as wall-clock text.
 * The Meta session sends it, after the approver approves.
 */
final class PeriodReport
{
    /**
     * A draft's corrections (ReportChanges): exclude_jobs (job numbers),
     * company_order (branch ids), goals (branch id or '' => key => value).
     *
     * @var array<string, mixed>
     */
    private array $options = [];

    public function __construct(private readonly AirtablePnl $pnl) {}

    /** The same report with a draft's corrections applied. */
    public function withOptions(array $options): self
    {
        $copy = clone $this;
        $copy->options = $options;

        return $copy;
    }

    /** One branch's report, as PDF bytes. $from and $to are Y-m-d, inclusive. */
    public function forFranchise(Franchise $branch, string $from, string $to): string
    {
        return HardenedPdf::render($this->html($branch, $from, $to), 'letter');
    }

    /** The page the PDF is printed from. */
    public function html(Franchise $branch, string $from, string $to): string
    {
        return $this->page($this->title(BranchName::of($branch), $from, $to), $this->branch($branch, $from, $to));
    }

    /**
     * The period as people read it on WhatsApp, sent before its file (Jose,
     * 2026-11-10: the text with the data, then the PDF): each branch's jobs
     * created, done and canceled and its done sales, in the location order,
     * and the total.
     *
     * @param  iterable<Franchise>  $branches
     */
    public function summaryText(string $title, iterable $branches, string $from, string $to): string
    {
        $money = fn (float $v) => ($v < 0 ? '-$' : '$').number_format(abs($v), 2);
        $lines = ["\u{1F4CA} *{$title}*", '_'.CarbonImmutable::parse($from)->format('M j').' – '.CarbonImmutable::parse($to)->format('M j, Y').'_', ''];
        $all = ['created' => 0, 'done' => 0, 'canceled' => 0, 'gross' => 0.0];
        foreach (LocationOrder::sort(collect($branches)) as $branch) {
            $f = $this->figures($branch, $from, $to);
            $n = ['created' => array_sum(array_column($f['created'], 'all')), 'done' => array_sum(array_column($f['closed'], 'done')),
                'canceled' => array_sum(array_column($f['closed'], 'canceled')), 'gross' => (float) array_sum(array_column($f['closed'], 'gross'))];
            if ($n['created'] + $n['done'] + $n['canceled'] === 0 && $n['gross'] == 0) {
                continue;
            }
            $lines[] = '• *'.BranchName::of($branch).'*: '.$n['created'].' created · '.$n['done'].' done · '.$n['canceled'].' canceled · '.$money($n['gross']).' sales';
            foreach ($n as $k => $v) {
                $all[$k] += $v;
            }
        }
        $lines[] = '';
        $lines[] = '*TOTAL: '.$all['created'].' created · '.$all['done'].' done · '.$all['canceled'].' canceled · '.$money($all['gross']).' sales*';

        return implode("\n", $lines);
    }

    /**
     * Every branch with Workiz jobs, after a company summary. $only: a rule's branches.
     *
     * @param  list<string>|null  $only
     */
    public function consolidated(Organization $organization, string $from, string $to, ?array $only = null): string
    {
        $branches = Franchise::query()->withoutGlobalScopes()
            ->where('organization_id', $organization->getKey())
            ->switchedOn()
            ->whereIn('id', ReportJobs::branchesWithJobs((string) $organization->getKey()))
            ->when($only !== null, fn ($q) => $q->whereIn('id', $only))
            ->orderBy('name')
            ->get();
        $branches = LocationOrder::sort($branches);

        // A draft's company order; any branch it does not name keeps its place after.
        if (($order = $this->options['company_order'] ?? []) !== []) {
            $branches = $branches->sortBy(fn ($b) => [array_search($b->getKey(), $order, true) === false ? PHP_INT_MAX : array_search($b->getKey(), $order, true), $b->name])->values();
        }

        $summary = [];
        $body = '';

        foreach ($branches as $branch) {
            $f = $this->figures($branch, $from, $to);
            $pnl = $this->profitAndLoss($branch, $from, $to);
            $summary[] = [
                BranchName::of($branch),
                (string) array_sum(array_column($f['created'], 'all')),
                (string) array_sum(array_column($f['closed'], 'done')),
                (string) array_sum(array_column($f['closed'], 'canceled')),
                self::money(array_sum(array_column($f['closed'], 'gross'))),
                self::money(array_sum(array_column($f['techs'], 'deposits'))),
                $pnl['income'] === null ? '—' : self::money($pnl['income']),
                $pnl['net'] === null ? '—' : self::money($pnl['net']),
                $pnl['income'] ? self::percent($pnl['net'] / $pnl['income']) : '—',
            ];

            $body .= '<div style="page-break-before:always"><h1>'.e(BranchName::of($branch)).'</h1>'
                .$this->sections($f, $pnl, (string) $branch->getKey(), $from, $to, (string) $branch->organization_id).'</div>';
        }

        $head = '<h2>Company summary</h2>'.self::table(
            ['Company', 'Jobs created', 'Done', 'Canceled', 'Gross', 'Deposits', 'P&L income', 'Net', 'Margin'],
            $summary,
        ).'<h2>HQ office expenses</h2>'.app(AirtableSections::class)->hqExpenses((string) $organization->getKey(), $from, $to);

        return HardenedPdf::render($this->page($this->title($organization->name.' Consolidated', $from, $to), $head.$body), 'letter');
    }

    private function branch(Franchise $branch, string $from, string $to): string
    {
        return $this->sections($this->figures($branch, $from, $to), $this->profitAndLoss($branch, $from, $to), (string) $branch->getKey(), $from, $to, (string) $branch->organization_id);
    }

    private function sections(array $f, array $pnl, ?string $branchId = null, string $from = '', string $to = '', string $org = ''): string
    {
        // Red when worse than the company's goal (ReportGoals).
        $goals = ReportGoals::for($org, $branchId, $this->options['goals'] ?? []);
        $cancel = fn ($canceled, $of) => ReportGoals::mark(e(self::share($canceled, $of)), $of > 0 && $goals->bad('cancel_rate', $canceled / $of));
        $avg = fn ($gross, $done) => $done ? ReportGoals::mark(e(self::money($gross / $done)), $goals->bad('avg_sale', $gross / $done)) : '—';
        $sourceRows = fn (array $rows, bool $all) => array_map(fn ($name, $r) => array_merge(
            [e($name)],
            $all ? [(string) $r['all'], (string) $r['done'], (string) $r['open']] : [(string) $r['done']],
            [(string) $r['canceled'], $cancel($r['canceled'], $all ? $r['all'] : $r['done'] + $r['canceled']),
                e(self::money($r['gross'])), $avg($r['gross'], $r['done'])],
        ), array_keys($rows), $rows);

        $airtable = app(AirtableSections::class);

        // Jose's list, 2026-11-03: "ADD KPI's".
        $created = ['all' => array_sum(array_column($f['created'], 'all')), 'done' => array_sum(array_column($f['created'], 'done'))];
        $done = array_sum(array_column($f['closed'], 'done'));
        $canceled = array_sum(array_column($f['closed'], 'canceled'));
        $gross = array_sum(array_column($f['closed'], 'gross'));
        $kpis = '<h2>Key figures</h2>'.self::table(
            ['Jobs created', 'Close rate', 'Done', 'Canceled', 'Canc%', 'Gross', 'Avg sale', 'Deposits', 'Net margin'],
            [[(string) $created['all'], e(self::share($created['done'], $created['all'])), (string) $done, (string) $canceled,
                $cancel($canceled, $done + $canceled), e(self::money($gross)), $avg($gross, $done),
                e(self::money(array_sum(array_column($f['techs'], 'deposits')))),
                $pnl['income'] ? e(self::percent($pnl['net'] / $pnl['income'])) : '—']],
            raw: true,
        ).'<p class="na">Close rate: jobs created in the dates that are done. Canc%: of the jobs closed in the dates.</p>';
        $reasons = $f['reasons'] ?? [];
        $breakdown = '<h2>Cancellation breakdown</h2>'.($reasons === [] ? '<p class="na">No jobs were canceled.</p>' : self::table(
            ['Reason', 'Canceled', 'Share'],
            array_map(fn ($why, $n) => [e($why), (string) $n, e(self::share($n, $canceled))], array_keys($reasons), $reasons),
            raw: true,
        ));

        return $kpis.'<h2>Ad sources - created</h2>'
            .self::table(['Source', 'All', 'Done', 'Open', 'Canc', 'Canc%', 'Gross', 'Avg sale'], $sourceRows($f['created'], true), raw: true)
            .'<h2>Ad sources - closed</h2>'
            .self::table(['Source', 'Done', 'Canc', 'Canc%', 'Gross', 'Avg sale'], $sourceRows($f['closed'], false), raw: true)
            .$breakdown
            // Jose's list, 2026-11-03: "Add Tech KPI's breakdown".
            .'<h2>Field performance (by tech)</h2>'
            .self::table(['Technician', 'Done', 'Canc', 'Canc%', 'Tech-caused canc', 'Gross', 'Share of gross', 'Avg sale', 'Deposits'], array_map(fn ($t) => [
                e($t['name']), (string) $t['done'], (string) $t['canceled'],
                $cancel($t['canceled'], $t['done'] + $t['canceled']),
                (string) ($t['tech_canceled'] ?? 0),
                e(self::money($t['gross'])), e($gross > 0 ? self::percent($t['gross'] / $gross) : '—'),
                $avg($t['gross'], $t['done']), e(self::money($t['deposits'])),
            ], $f['techs']), raw: true)
            .($branchId === null ? '' : '<h2>Repair teams</h2>'.$airtable->repairTeams($org, $branchId, $from, $to, $this->options['goals'] ?? [])
                .'<h2>Refunds, disputes and returned checks</h2>'.$airtable->refunds($org, $branchId, $from, $to))
            .'<h2>Profit &amp; loss</h2>'.$this->pnlSection($pnl);
    }

    private function pnlSection(array $pnl): string
    {
        if ($pnl['weeks'] === []) {
            return '<p class="na">Unavailable: '.e($pnl['why']).'</p>';
        }

        $out = '';

        // One week: its categories. A month: one line per week.
        if (! $pnl['monthly']) {
            $week = $pnl['weeks'][0];
            $rows = [];

            // Income first, then the expenses.
            $categories = $week['categories'];
            uksort($categories, fn ($a, $b) => [$a !== 'Income', $a] <=> [$b !== 'Income', $b]);

            foreach ($categories as $name => $c) {
                $rows[] = [$name, $c['total'] === null ? 'not filled in' : self::money($c['total']),
                    $c['total'] !== null && $week['income'] ? self::percent($c['total'] / $week['income']) : '—'];
            }

            $out .= self::table(['Category ('.e($week['week']).')', 'Amount', '% of income'], $rows);
        } else {
            $out .= self::table(['Week', 'Income', 'Expenses', 'Net'], array_map(fn ($w) => [
                $w['week'],
                $w['income'] === null ? '—' : self::money($w['income']),
                $w['costs'] === null ? '—' : self::money($w['costs']),
                $w['net'] === null ? '—' : self::money($w['net']),
            ], $pnl['weeks']));
        }

        $out .= self::table(['Income', 'Total expenses', 'Net balance', 'Profit margin'], [[
            $pnl['income'] === null ? '—' : self::money($pnl['income']),
            $pnl['costs'] === null ? '—' : self::money($pnl['costs']),
            $pnl['net'] === null ? '—' : self::money($pnl['net']),
            $pnl['income'] ? self::percent($pnl['net'] / $pnl['income']) : '—',
        ]]);

        foreach ($pnl['missing'] as $label) {
            $out .= '<p class="na">Week '.e($label).' is not in the Airtable P&amp;L yet.</p>';
        }

        foreach ($pnl['weeks'] as $w) {
            if (! $w['complete']) {
                $out .= '<p class="na">Week '.e($w['week']).' is not complete in Airtable, so it carries no totals.</p>';
            }
            foreach ($w['disagreements'] as $d) {
                $out .= '<p class="na">'.e($d).'</p>';
            }
        }

        return $out;
    }

    /**
     * $scheduled: the closed jobs are those SCHEDULED in the period, as Workiz's technician report
     * counts them (report_by=2, Rodrigo's daily); the weekly keeps the closed date (desk, 2026-11-11).
     *
     * @return array{created: array, closed: array, techs: list<array>}
     */
    public function figures(Franchise $branch, string $from, string $to, bool $scheduled = false): array
    {
        $range = [$from.' 00:00:00', $to.' 23:59:59'];
        $jobs = fn () => ReportJobs::jobs((string) $branch->getKey());
        $row = fn () => ['all' => 0, 'done' => 0, 'open' => 0, 'canceled' => 0, 'gross' => 0.0];
        $source = fn ($v) => trim((string) $v) ?: 'No source';

        // Created: everything booked in the period, as it stands now.
        $created = [];
        // Jobs a draft leaves out, by Workiz's job number or id.
        $excluded = array_flip(array_map('strval', $this->options['exclude_jobs'] ?? []));
        $kept = fn ($job) => $excluded === [] || ! (isset($excluded[(string) $job->ref]) || isset($excluded[(string) $job->serial]));

        foreach (ReportJobs::created($jobs()->whereBetween('j.booked_at', $range)->get())->filter($kept) as $job) {
            $s = $source($job->job_source);
            $created[$s] ??= $row();
            $created[$s]['all']++;
            $kind = self::kind($job->status);
            $created[$s][$kind]++;
            if ($kind === 'done') {
                $created[$s]['gross'] += (float) $job->total;
            }
        }

        // Closed: done or canceled in the period, whenever booked: when the sweep
        // first saw it closed (closed_at), else its latest change for a row
        // closed before that was kept.
        $closed = [];
        $techs = [];
        $reasons = [];
        $when = $scheduled ? 'j.scheduled_for' : DB::raw('coalesce(j.closed_at, j.last_status_update)');
        foreach ($jobs()->whereBetween($when, $range)->whereIn('j.status', ['Done', 'Canceled'])->get()->filter($kept) as $job) {
            $s = $source($job->job_source);
            $kind = self::kind($job->status);
            $price = $kind === 'done' ? (float) $job->total : 0.0;
            $closed[$s] ??= $row();
            $closed[$s][$kind]++;
            $closed[$s]['gross'] += $price;
            // Jose's list, 2026-11-03: "Add Cancellation Breakdown". Workiz's
            // sub-status is the reason; most cancels carry none.
            $techFault = false;
            if ($kind === 'canceled') {
                $why = trim((string) ($job->sub_status ?? '')) ?: 'No reason given';
                $reasons[$why] = ($reasons[$why] ?? 0) + 1;
                $techFault = self::techFault($why);
            }

            $team = ReportJobs::team($job);
            foreach ($team as $tech) {
                $id = (string) ($tech['id'] ?? $tech['Name'] ?? 'none');
                $techs[$id] ??= ['name' => trim((string) ($tech['Name'] ?? 'Unassigned')), 'done' => 0, 'canceled' => 0, 'tech_canceled' => 0, 'gross' => 0.0, 'deposits' => 0.0];
                $techs[$id][$kind]++;
                $techs[$id]['tech_canceled'] += (int) $techFault;
                // A job worked by two shares its price, so totals stay the job's.
                $techs[$id]['gross'] += $price / count($team);
            }
        }

        // A deposit is the job's team's, split like its price; whoever keyed it in only when the job has no
        // team (Nov 10: 46.6% had gone to someone not on the job, desk 2026-11-11).
        $names = ReportJobs::people((string) $branch->getKey());
        foreach (ReportJobs::paidFor((string) $branch->getKey(), ...$range) as $payment) {
            $team = json_decode((string) $payment->team, true) ?: [['id' => (string) $payment->user_ref]];
            foreach ($team as $tech) {
                $id = (string) ($tech['id'] ?? $tech['Name'] ?? 'none');
                $techs[$id] ??= ['name' => trim((string) ($tech['Name'] ?? '')) ?: self::collector($names[$id] ?? null, $id), 'done' => 0, 'canceled' => 0, 'tech_canceled' => 0, 'gross' => 0.0, 'deposits' => 0.0];
                $techs[$id]['deposits'] += (float) $payment->amount / count($team);
            }
        }

        $byAll = fn ($a, $b) => [$b['all'] + $b['done'] + $b['canceled'], $b['gross']] <=> [$a['all'] + $a['done'] + $a['canceled'], $a['gross']];
        uasort($created, $byAll);
        uasort($closed, $byAll);
        usort($techs, fn ($a, $b) => [$b['gross'], $b['deposits']] <=> [$a['gross'], $a['deposits']]);
        arsort($reasons);

        return ['created' => $created, 'closed' => $closed, 'techs' => $techs, 'reasons' => $reasons];
    }

    /**
     * The P&L weeks that start (Monday) inside the period, from the first
     * Airtable base holding this branch's table with any weeks in it.
     *
     * @return array{weeks: list<array>, missing: list<string>, income: ?float, costs: ?float, net: ?float, why: string, monthly: bool}
     */
    public function profitAndLoss(Franchise $branch, string $from, string $to): array
    {
        $none = fn (string $why) => ['weeks' => [], 'missing' => [], 'income' => null, 'costs' => null, 'net' => null, 'why' => $why, 'monthly' => false];
        // The branch's P&L table, as mapped in Financials → Settings, read
        // from the hourly copy.
        if (! ReportSources::mapped((string) $branch->organization_id, 'pnl', (string) $branch->getKey())) {
            return $none('no profit & loss table is mapped for this branch (Financials → Settings)');
        }

        $rows = ReportSources::allRows((string) $branch->organization_id, 'pnl', (string) $branch->getKey(), $from, $to);

        if ($this->pnl->weeks($rows) === []) {
            return $none('the mapped profit & loss table has no weeks in it yet');
        }

        $labels = [];
        for ($monday = CarbonImmutable::parse($from)->startOfWeek(); $monday->toDateString() <= $to; $monday = $monday->addWeek()) {
            if ($monday->toDateString() >= $from) {
                $labels[] = $monday->format('j M').' - '.$monday->addDays(6)->format('j M');
            }
        }

        $have = $this->pnl->weeks($rows);
        $weeks = array_map(fn ($l) => $this->pnl->week($rows, $l), array_values(array_intersect($labels, $have)));
        $missing = array_values(array_diff($labels, $have));

        if ($weeks === []) {
            return $none('the profit & loss table has no week '.implode(', ', $labels).' yet');
        }

        // A total only when every week is there and complete: a missing week
        // read as zero is a profit that looks ordinary and is too high.
        $whole = $missing === [] && collect($weeks)->every(fn ($w) => $w['complete']);
        $sum = fn ($key) => $whole ? array_sum(array_column($weeks, $key)) : null;

        return ['weeks' => $weeks, 'missing' => $missing, 'income' => $sum('income'), 'costs' => $sum('costs'), 'net' => $sum('net'), 'why' => '', 'monthly' => count($labels) > 1];
    }

    private function title(string $name, string $from, string $to): string
    {
        $a = CarbonImmutable::parse($from);
        $b = CarbonImmutable::parse($to);
        $monthly = $a->day === 1 && $b->isSameDay($a->endOfMonth());

        return $monthly
            ? "{$name} · Monthly Report · ".$a->format('F Y')
            : "{$name} · Weekly Report · ".$a->format('M j').' – '.$b->format('M j, Y');
    }

    private function page(string $title, string $body): string
    {
        return '<!doctype html><html><head><meta charset="utf-8"><style>'
            .'body{font-family:Helvetica,Arial,sans-serif;font-size:9pt;color:#111}'
            .'h1{font-size:15pt;margin:0 0 6pt}h2{font-size:11pt;margin:14pt 0 4pt}'
            .'table{width:100%;border-collapse:collapse;margin-bottom:6pt}'
            .'th,td{border:1px solid #bbb;padding:3pt 5pt;text-align:right}'
            .'th:first-child,td:first-child{text-align:left}th{background:#eee}'
            .'.na{color:#b45309;margin:2pt 0}.foot{color:#666;font-size:7pt;margin-top:14pt}'
            .'</style></head><body><h1>'.e($title).'</h1>'.$body
            .'<p class="foot">Internal management report. From EzBizi\'s copy of Workiz and the Airtable P&amp;L, made '
            .e(CarbonImmutable::now(OrgTimezone::of())->format('M j, Y g:i A T')).'.</p></body></html>';
    }

    /**
     * @param  list<list<string>>  $rows
     * @param  bool  $raw  cells are HTML the caller escaped (goal colours)
     */
    public static function table(array $head, array $rows, bool $raw = false): string
    {
        if ($rows === []) {
            return '<p>None in this period.</p>';
        }

        $cells = fn (array $r, string $tag, bool $html) => '<tr>'.implode('', array_map(fn ($c) => "<{$tag}>".($html ? $c : e($c))."</{$tag}>", $r)).'</tr>';

        return '<table><thead>'.$cells($head, 'th', false).'</thead><tbody>'
            .implode('', array_map(fn ($r) => $cells($r, 'td', $raw), $rows)).'</tbody></table>';
    }

    /**
     * Workiz's API names nobody behind 40% of payments (office and owner
     * logins: /users omits them, 2026-10-06), so the id is shown, for someone
     * who knows it to name.
     */
    public static function collector(?string $name, string $id): string
    {
        return trim((string) $name) ?: "Not in Workiz's API ({$id})";
    }

    /** A cancel the technician caused, by Workiz's reason ("Tech No Call No Show", "Technician Fault"). */
    private static function techFault(string $reason): bool
    {
        return (bool) preg_match('/\btech(nician)?\b/i', $reason);
    }

    private static function kind(?string $status): string
    {
        return match ($status) {
            'Done' => 'done',
            'Canceled' => 'canceled',
            default => 'open',
        };
    }

    private static function money(float $amount): string
    {
        return ($amount < 0 ? '-' : '').'$'.number_format(abs($amount), 2);
    }

    private static function share(int $part, int $of): string
    {
        return $of === 0 ? '—' : self::percent($part / $of);
    }

    private static function percent(float $ratio): string
    {
        return number_format($ratio * 100, 1).'%';
    }
}
