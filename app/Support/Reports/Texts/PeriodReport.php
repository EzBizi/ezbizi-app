<?php

namespace App\Support\Reports\Texts;

use App\Models\Franchise;
use App\Models\Organization;
use App\Models\SocialConnection;
use App\Support\Files\HardenedPdf;
use App\Support\Reports\Airtable\AirtableBase;
use App\Support\Reports\Airtable\AirtablePnl;
use App\Support\Social\SocialProvider;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * The weekly and monthly PDF, per branch and consolidated: EzBizi's
 * replacement for the Rodrigo bot's (docs/REPLACING-RODRIGO-BOT.md, report C).
 *
 * Jose, 2026-10-06, choosing between core-now and full parity: "Ship core,
 * switch now". So this holds the sections EzBizi can read by API today: ad
 * sources (created and closed), field performance with deposits, and the
 * Airtable profit and loss. Rodrigo's Workiz profit (scraped), LSA by
 * location, reviews, repair teams, refunds and disputes, HQ office expenses,
 * monthly bills and goal colours are not here yet.
 *
 * Workiz stamps are the account's own clock, compared as wall-clock text.
 * The Meta session sends it, after Merav approves.
 */
final class PeriodReport
{
    /**
     * Branch name to its table in the P&L base, which names tables by state
     * code. ponytail: a name map; store the table on the branch when a branch
     * is named differently.
     */
    private const PNL_TABLES = [
        'New Jersey' => 'NJ', 'Connecticut' => 'CT', 'Texas' => 'TX', 'Ohio' => 'OH',
        'Illinois' => 'IL', 'Georgia' => 'GA', 'DMV' => 'DMV', 'Washington' => 'WA',
        'Nationwide' => 'NW', 'New York' => 'NY', 'Massachusetts' => 'MA',
    ];

    /** @var array<string, list<array<string,mixed>>|null> rows per table, for one consolidated run */
    private array $tables = [];

    /** @var array<string, list<string>> each base's table names, asked once per run */
    private array $baseTables = [];

    public function __construct(
        private readonly AirtableBase $airtable,
        private readonly AirtablePnl $pnl,
    ) {}

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

    /** Every branch with Workiz jobs, after a company summary. */
    public function consolidated(Organization $organization, string $from, string $to): string
    {
        $branches = Franchise::query()->withoutGlobalScopes()
            ->where('organization_id', $organization->getKey())
            ->where('is_active', true)
            ->whereIn('id', DB::table('workiz_jobs')->select('franchise_id'))
            ->orderBy('name')
            ->get();

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
                .$this->sections($f, $pnl).'</div>';
        }

        $head = '<h2>Company summary</h2>'.self::table(
            ['Company', 'Jobs created', 'Done', 'Canceled', 'Gross', 'Deposits', 'P&L income', 'Net', 'Margin'],
            $summary,
        );

        return HardenedPdf::render($this->page($this->title($organization->name.' Consolidated', $from, $to), $head.$body), 'letter');
    }

    private function branch(Franchise $branch, string $from, string $to): string
    {
        return $this->sections($this->figures($branch, $from, $to), $this->profitAndLoss($branch, $from, $to));
    }

    private function sections(array $f, array $pnl): string
    {
        $sourceRows = fn (array $rows, bool $all) => array_map(fn ($name, $r) => array_merge(
            [$name],
            $all ? [(string) $r['all'], (string) $r['done'], (string) $r['open']] : [(string) $r['done']],
            [(string) $r['canceled'], self::share($r['canceled'], $all ? $r['all'] : $r['done'] + $r['canceled']),
                self::money($r['gross']), $r['done'] ? self::money($r['gross'] / $r['done']) : '—'],
        ), array_keys($rows), $rows);

        return '<h2>Ad sources - created</h2>'
            .self::table(['Source', 'All', 'Done', 'Open', 'Canc', 'Canc%', 'Gross', 'Avg sale'], $sourceRows($f['created'], true))
            .'<h2>Ad sources - closed</h2>'
            .self::table(['Source', 'Done', 'Canc', 'Canc%', 'Gross', 'Avg sale'], $sourceRows($f['closed'], false))
            .'<h2>Field performance (by tech)</h2>'
            .self::table(['Technician', 'Done', 'Canc', 'Canc%', 'Gross', 'Avg sale', 'Deposits'], array_map(fn ($t) => [
                $t['name'], (string) $t['done'], (string) $t['canceled'],
                self::share($t['canceled'], $t['done'] + $t['canceled']),
                self::money($t['gross']), $t['done'] ? self::money($t['gross'] / $t['done']) : '—', self::money($t['deposits']),
            ], $f['techs']))
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
     * @return array{created: array, closed: array, techs: list<array>}
     */
    public function figures(Franchise $branch, string $from, string $to): array
    {
        $range = [$from.' 00:00:00', $to.' 23:59:59'];
        $jobs = fn () => DB::table('workiz_jobs')->where('franchise_id', $branch->getKey());
        $row = fn () => ['all' => 0, 'done' => 0, 'open' => 0, 'canceled' => 0, 'gross' => 0.0];
        $source = fn ($v) => trim((string) $v) ?: 'No source';

        // Created: everything booked in the period, as it stands now.
        $created = [];
        foreach ($jobs()->whereBetween('booked_at', $range)->get(['job_source', 'status', 'payload']) as $job) {
            $s = $source($job->job_source);
            $created[$s] ??= $row();
            $created[$s]['all']++;
            $kind = self::kind($job->status);
            $created[$s][$kind]++;
            if ($kind === 'done') {
                $created[$s]['gross'] += self::price($job->payload);
            }
        }

        // Closed: done or canceled in the period, whenever booked. ponytail:
        // last_status_update is the latest change, Workiz's API has no
        // "completed at".
        $closed = [];
        $techs = [];
        foreach ($jobs()->whereBetween('last_status_update', $range)->whereIn('status', ['Done', 'Canceled'])->get(['job_source', 'status', 'payload']) as $job) {
            $s = $source($job->job_source);
            $kind = self::kind($job->status);
            $price = $kind === 'done' ? self::price($job->payload) : 0.0;
            $closed[$s] ??= $row();
            $closed[$s][$kind]++;
            $closed[$s]['gross'] += $price;

            $team = (json_decode($job->payload, true)['Team'] ?? []) ?: [['id' => 'none', 'Name' => 'Unassigned']];
            foreach ($team as $tech) {
                $id = (string) ($tech['id'] ?? $tech['Name'] ?? 'none');
                $techs[$id] ??= ['name' => trim((string) ($tech['Name'] ?? 'Unassigned')), 'done' => 0, 'canceled' => 0, 'gross' => 0.0, 'deposits' => 0.0];
                $techs[$id][$kind]++;
                // A job worked by two shares its price, so totals stay the job's.
                $techs[$id]['gross'] += $price / count($team);
            }
        }

        $names = DB::table('workiz_team_members')->where('franchise_id', $branch->getKey())->pluck('name', 'remote_id');
        $paid = DB::table('workiz_records')->where('franchise_id', $branch->getKey())->where('type', 'payment')
            ->whereBetween('starts_at', $range)->groupBy('user_remote_id')->selectRaw('user_remote_id, sum(amount) amount')->get();

        foreach ($paid as $p) {
            $id = (string) $p->user_remote_id;
            $techs[$id] ??= ['name' => self::collector($names[$id] ?? null, $id), 'done' => 0, 'canceled' => 0, 'gross' => 0.0, 'deposits' => 0.0];
            $techs[$id]['deposits'] += (float) $p->amount;
        }

        $byAll = fn ($a, $b) => [$b['all'] + $b['done'] + $b['canceled'], $b['gross']] <=> [$a['all'] + $a['done'] + $a['canceled'], $a['gross']];
        uasort($created, $byAll);
        uasort($closed, $byAll);
        usort($techs, fn ($a, $b) => [$b['gross'], $b['deposits']] <=> [$a['gross'], $a['deposits']]);

        return ['created' => $created, 'closed' => $closed, 'techs' => $techs];
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
        $code = self::PNL_TABLES[$branch->name] ?? null;

        if ($code === null) {
            return $none("this branch has no table in the Airtable P&L (looked for one named after {$branch->name})");
        }

        $rows = $this->pnlRows($branch->organization_id, $code);

        if ($rows === null) {
            return $none("no Airtable base with a {$code} table that has weeks in it");
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
            return $none('the '.$code.' table has no week '.implode(', ', $labels).' yet');
        }

        // A total only when every week is there and complete: a missing week
        // read as zero is a profit that looks ordinary and is too high.
        $whole = $missing === [] && collect($weeks)->every(fn ($w) => $w['complete']);
        $sum = fn ($key) => $whole ? array_sum(array_column($weeks, $key)) : null;

        return ['weeks' => $weeks, 'missing' => $missing, 'income' => $sum('income'), 'costs' => $sum('costs'), 'net' => $sum('net'), 'why' => '', 'monthly' => count($labels) > 1];
    }

    private function pnlRows(string $organizationId, string $code): ?array
    {
        $key = $organizationId.'/'.$code;

        if (array_key_exists($key, $this->tables)) {
            return $this->tables[$key];
        }

        $connections = SocialConnection::query()->withoutGlobalScopes()
            ->where('organization_id', $organizationId)
            ->where('provider', SocialProvider::Airtable->value)
            ->get();

        foreach ($connections as $c) {
            $token = (string) (($c->credentials ?? [])['api_token'] ?? '');

            try {
                // Asked once per base per run: the Marketing tab reads every
                // branch, and asking each time cost a round trip per branch.
                $tables = $this->baseTables[$c->getKey()] ??= $this->airtable->verify($token, (string) $c->remote_id)['tables'];

                if (! in_array($code, $tables, true)) {
                    continue;
                }

                $rows = $this->airtable->records($token, (string) $c->remote_id, $code);
            } catch (Throwable $e) {
                report($e);

                continue;
            }

            if ($this->pnl->weeks($rows) !== []) {
                return $this->tables[$key] = $rows;
            }
        }

        return $this->tables[$key] = null;
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
            .e(CarbonImmutable::now('America/New_York')->format('M j, Y g:i A T')).'.</p></body></html>';
    }

    /** @param list<list<string>> $rows */
    private static function table(array $head, array $rows): string
    {
        if ($rows === []) {
            return '<p>None in this period.</p>';
        }

        $cells = fn (array $r, string $tag) => '<tr>'.implode('', array_map(fn ($c) => "<{$tag}>".e($c)."</{$tag}>", $r)).'</tr>';

        return '<table><thead>'.$cells($head, 'th').'</thead><tbody>'
            .implode('', array_map(fn ($r) => $cells($r, 'td'), $rows)).'</tbody></table>';
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

    private static function kind(?string $status): string
    {
        return match ($status) {
            'Done' => 'done',
            'Canceled' => 'canceled',
            default => 'open',
        };
    }

    private static function price(string $payload): float
    {
        return (float) (json_decode($payload, true)['JobTotalPrice'] ?? 0);
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
