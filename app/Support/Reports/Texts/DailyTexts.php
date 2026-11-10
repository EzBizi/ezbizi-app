<?php

namespace App\Support\Reports\Texts;

use App\Models\Franchise;
use App\Models\Organization;
use App\Models\SocialConnection;
use App\Models\User;
use App\Services\Ads\AdsCost;
use App\Support\Reports\Airtable\ReportSources;
use App\Support\Reports\Experimental\PerformancePeople;
use App\Support\Reports\LocationOrder;
use App\Support\Reports\MarketingReport;
use App\Support\Reports\MetaSpend;
use App\Support\Reports\ReportJobs;
use App\Support\Settings\Lists\BusinessLists;
use App\Support\Settings\OrgTimezone;
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
 * scope); null is every active branch. Branches come in the business's
 * location order (Settings › Lists, reports.locations).
 *
 * Jose's NEW REPORTS lists (2026-11-06) are three texts, one a time slot:
 * revenue_collected (1 AM, yesterday), evening (8 PM: jobs created, Google
 * Ads, Meta Ads) and weekly (Sunday 8 PM, the seven days to $day); 8 AM is
 * job_assignments.
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
        'revenue_collected' => 'Revenue collected · yesterday by location',
        'evening' => 'Daily 8 PM · jobs created, Google Ads, Meta Ads',
        'weekly' => 'Weekly · revenue, jobs, dispatchers, ads, Local Services',
    ];

    private const RULE = "\n\n\u{2014}\u{2014}\u{2014}\u{2014}\u{2014}\n\n";

    public function __construct(
        private readonly PeriodReport $report,
        private readonly AdsCost $ads,
        private readonly PerformancePeople $performance,
    ) {}

    /** @param list<string>|null $branches */
    public function render(string $kind, Organization $organization, CarbonImmutable $day, ?array $branches = null): string
    {
        $this->locations = LocationOrder::positions();
        // The listed branches in the list's order, every other branch after (Shoval, 2026-11-09).
        $list = LocationOrder::sort(Franchise::query()->where('is_active', true)
            ->when($branches !== null, fn ($q) => $q->whereIn('id', $branches))
            ->orderBy('name')->get());
        // A listed name is one branch: A-1's "Texas", not AngiSweep's.
        $this->claimed = LocationOrder::claimed($list, $this->locations);
        // One company's branches read as "Texas"; several companies' need theirs, unless the business listed it by name.
        $several = $list->pluck('company_id')->unique()->count() > 1;
        $this->name = fn (Franchise $b) => ! $several || ($this->locations !== [] && $this->listed($b)) ? (string) $b->name : BranchName::of($b);

        return match ($kind) {
            'ppc' => $this->ppc($list, $day),
            'marketing_ppc' => $this->marketingPpc($organization, $day, $branches === null ? null : $list),
            'dispatchers' => $this->dispatchers($day, $branches),
            'technician_sales' => $this->technicianSales($list, $day),
            'weekly_losses' => $this->weeklyLosses($list, $day),
            'weekly_reviews' => $this->weeklyReviews($list, $day),
            'job_assignments' => $this->jobAssignments($list, $day, $day),
            'revenue_collected' => $this->revenue($list, $day->subDay(), $day->subDay()),
            'evening' => implode(self::RULE, [$this->jobsCreated($list, $day, $day), $this->googleAds($list, $day, $day), $this->metaAds($list, $day, $day)]),
            'weekly' => implode(self::RULE, [
                "\u{1F4C5} *Weekly report*\n_".self::period($day->subDays(6), $day).'_',
                $this->revenue($list, $day->subDays(6), $day),
                $this->jobAssignments($list, $day->subDays(6), $day),
                $this->jobsCreated($list, $day->subDays(6), $day),
                $this->dispatcherCalls($day->subDays(6), $day, $branches, 'new'),
                $this->dispatcherCalls($day->subDays(6), $day, $branches, 'existing'),
                $this->googleAds($list, $day->subDays(6), $day),
                $this->metaAds($list, $day->subDays(6), $day),
                $this->lsaLosses($list, $day->subDays(6), $day),
            ]),
        };
    }

    /** @var \Closure(Franchise): string */
    private \Closure $name;

    /** @var array<string, int> reports.locations: lower-cased branch name => position */
    private array $locations = [];

    /** @var array<string, true> the branch each listed name stands for, by id */
    private array $claimed = [];

    /** A branch the business lists (or every branch, when it lists none): an unlisted one is shown only with something to show. */
    private function listed(Franchise $branch): bool
    {
        return $this->locations === [] || isset($this->claimed[(string) $branch->getKey()]);
    }

    /** Payments received per location (Workiz's Payments, by payment date), and the total. */
    private function revenue(Collection $branches, CarbonImmutable $from, CarbonImmutable $to): string
    {
        $lines = ["\u{1F4B5} *Revenue collected*", '_'.self::period($from, $to).' · payments received_', ''];
        $total = 0;
        foreach ($branches as $branch) {
            $cents = (int) round((float) ReportJobs::payments((string) $branch->getKey())->whereBetween('starts_at', self::range($from, $to))->sum('amount') * 100);
            if ($cents === 0 && ! $this->listed($branch)) {
                continue;
            }
            $lines[] = '• *'.($this->name)($branch).'*: '.self::money($cents);
            $total += $cents;
        }

        return implode("\n", [...$lines, '', '*TOTAL: '.self::money($total).'*']);
    }

    /** Jobs booked per location, less the tags the business leaves out (reports.tags_not_created). */
    private function jobsCreated(Collection $branches, CarbonImmutable $from, CarbonImmutable $to): string
    {
        $skip = self::tags('reports.tags_not_created');
        $lines = ["\u{1F4DD} *Jobs created*", '_'.self::period($from, $to).'_'];
        if ($skip !== []) {
            $lines[] = '_Not counting jobs tagged '.implode(', ', array_keys($skip)).'._';
        }
        $lines[] = '';
        $total = 0;
        foreach ($branches as $branch) {
            $n = ReportJobs::jobs((string) $branch->getKey())->whereBetween('j.booked_at', self::range($from, $to))->get()
                ->reject(fn ($job) => self::tagged($job->tags, $skip))->count();
            if ($n === 0 && ! $this->listed($branch)) {
                continue;
            }
            $lines[] = '• *'.($this->name)($branch).'*: '.$n;
            $total += $n;
        }

        return implode("\n", [...$lines, '', '*TOTAL: '.$total.' '.($total === 1 ? 'job' : 'jobs').'*']);
    }

    /** Google spend on the campaigns switched on, against the jobs from Google's sources (reports.paid_sources). */
    private function googleAds(Collection $branches, CarbonImmutable $from, CarbonImmutable $to): string
    {
        $sources = array_values(array_filter([MarketingReport::sourceOf('lsa'), MarketingReport::sourceOf('ppc')]));
        $lines = ["\u{1F4CA} *Google Ads · active campaigns*", '_'.self::period($from, $to).' · jobs from '.(implode(' and ', $sources) ?: 'no source').'_', ''];
        $total = ['cost' => 0, 'jobs' => 0];
        $refused = [];
        foreach ($branches as $branch) {
            $cost = $this->ads->forBranch($branch, $from->startOfDay(), $to->endOfDay(), activeOnly: true);
            $refused = [...$refused, ...array_column($cost['refused'], 'account')];
            if ($cost['accounts'] === 0) {
                continue;
            }
            $spent = $cost['lsa']['cost_minor'] + $cost['ppc']['cost_minor'];
            $jobs = $sources === [] ? 0 : ReportJobs::jobs((string) $branch->getKey())->whereIn('j.job_source', $sources)
                ->whereBetween('j.booked_at', self::range($from, $to))->count();
            $lines[] = self::adLine(($this->name)($branch), $spent, $jobs);
            $total['cost'] += $spent;
            $total['jobs'] += $jobs;
        }
        if (count($lines) === 3 && $refused === []) {
            return implode("\n", [...array_slice($lines, 0, 2), '', 'No branch has a Google Ads account linked.']);
        }
        $lines = [...$lines, '', self::adLine('TOTAL', $total['cost'], $total['jobs'])];
        if ($refused !== []) {
            // A refused account's spend is missing, so the totals are too low: said, not hidden.
            $lines[] = "\u{26A0}\u{FE0F} Google would not answer for: ".implode(', ', array_unique($refused)).'. Their spend is not in these figures.';
        }

        return implode("\n", $lines);
    }

    /**
     * Facebook spend and lead forms per location, against the jobs from Meta's
     * source. A campaign counts toward its branch, else the branch whose code
     * (reports.locations: "TX, DFW") is its state or a word of its name; the
     * rest are said, not dropped.
     * ponytail: active is "spent in the dates"; Meta's live status is an API
     * call per account, worth it if paused-today campaigns ever matter.
     */
    private function metaAds(Collection $branches, CarbonImmutable $from, CarbonImmutable $to): string
    {
        $byName = $branches->keyBy(fn (Franchise $b) => mb_strtolower(trim((string) $b->name)));
        $codes = [];
        foreach (app(BusinessLists::class)->rows('reports.locations') as $row) {
            $id = $byName->get(mb_strtolower(trim((string) ($row['value']['branch'] ?? $row['key']))))?->getKey();
            foreach (array_filter(array_map('trim', explode(',', (string) ($row['value']['code'] ?? '')))) as $code) {
                $codes[mb_strtoupper($code)] ??= (string) $id;
            }
        }
        $place = function (array $c) use ($codes): string {
            if (($c['franchise_id'] ?? null) !== null) {
                return (string) $c['franchise_id'];
            }
            foreach ($codes as $code => $id) {
                if ($code === mb_strtoupper((string) $c['state']) || preg_match('/\b'.preg_quote($code, '/').'\b/iu', (string) $c['name'])) {
                    return $id;
                }
            }

            return '';
        };
        $ours = $branches->keyBy(fn (Franchise $b) => (string) $b->getKey());
        $per = [];
        $elsewhere = ['spend' => 0, 'campaigns' => 0];
        foreach (MetaSpend::rows($from->toDateString(), $to->toDateString()) as $c) {
            if ($c['spend_minor'] <= 0) {
                continue;
            }
            $id = $place($c);
            if (! $ours->has($id)) {
                $elsewhere['spend'] += $c['spend_minor'];
                $elsewhere['campaigns']++;

                continue;
            }
            $per[$id] ??= ['spend' => 0, 'forms' => 0];
            $per[$id]['spend'] += $c['spend_minor'];
            $per[$id]['forms'] += $c['leads'];
        }

        $source = MarketingReport::sourceOf('meta');
        $lines = ["\u{1F4F1} *Meta Ads · active campaigns*", '_'.self::period($from, $to).' · jobs from '.($source ?? 'no source').'_', ''];
        $total = ['spend' => 0, 'forms' => 0, 'jobs' => 0];
        foreach ($branches as $branch) {
            $jobs = $source === null ? 0 : ReportJobs::jobs((string) $branch->getKey())->where('j.job_source', $source)
                ->whereBetween('j.booked_at', self::range($from, $to))->count();
            $mine = $per[(string) $branch->getKey()] ?? ['spend' => 0, 'forms' => 0];
            if ($mine['spend'] === 0 && $jobs === 0) {
                continue;
            }
            $lines[] = self::adLine(($this->name)($branch), $mine['spend'], $jobs).' · '.$mine['forms'].' '.($mine['forms'] === 1 ? 'form' : 'forms');
            $total['spend'] += $mine['spend'];
            $total['forms'] += $mine['forms'];
            $total['jobs'] += $jobs;
        }
        if (count($lines) === 3) {
            $lines[] = 'No Meta spend or Meta jobs.';
        } else {
            $lines = [...$lines, '', self::adLine('TOTAL', $total['spend'], $total['jobs']).' · '.$total['forms'].' forms'];
        }
        if ($elsewhere['campaigns'] > 0) {
            $lines[] = '_'.$elsewhere['campaigns'].' other '.($elsewhere['campaigns'] === 1 ? 'campaign' : 'campaigns').' spent '
                .self::money($elsewhere['spend']).': no branch here, or no code of theirs in Report locations._';
        }

        return implode("\n", $lines);
    }

    /**
     * Who answered which queue, in the business's role order
     * (reports.dispatcher_roles): new clients with the jobs they created,
     * existing clients with their calls.
     *
     * @param  list<string>|null  $branches
     */
    private function dispatcherCalls(CarbonImmutable $from, CarbonImmutable $to, ?array $branches, string $clients): string
    {
        $new = $clients === 'new';
        $head = "\u{1F4DE} *Dispatcher performance · ".($new ? 'new' : 'existing')." clients*\n_".self::period($from, $to).'_';
        $accounts = $this->phoneAccounts($branches);
        if ($accounts === []) {
            return $head."\n\nNo phone account answers for these branches alone.";
        }

        $people = [];
        foreach (array_keys($accounts) as $id) {
            foreach ($this->performance->for(null, $from->startOfDay(), $to->endOfDay(), $id === '' ? null : (string) $id) as $p) {
                $people[$p['person']]['calls'] = ($people[$p['person']]['calls'] ?? 0) + (int) ($p[$new ? 'new_client' : 'existing_client'] ?? 0);
                $people[$p['person']]['jobs'] = ($people[$p['person']]['jobs'] ?? 0) + (int) ($p['jobs'] ?? 0);
            }
        }
        $people = array_filter($people, fn (array $p) => $p['calls'] > 0 || ($new && $p['jobs'] > 0));
        if ($people === []) {
            return $head."\n\nNo calls answered.";
        }

        // Workiz and the phones spell a name as they like: "Nelly Escobar" is the user "Nelly escobar".
        $roles = User::query()->with('role:id,name')->whereIn('name', array_keys($people))->get()
            ->mapWithKeys(fn (User $u) => [mb_strtolower($u->name) => (string) $u->role?->name]);
        $order = array_map(fn (array $row) => (string) ($row['value']['role'] ?? $row['key']), app(BusinessLists::class)->rows('reports.dispatcher_roles'));
        $groups = [];
        foreach ($people as $person => $p) {
            $role = $roles->get(mb_strtolower((string) $person), '');
            $groups[in_array($role, $order, true) ? $role : 'Others'][$person] = $p;
        }

        $sections = [];
        foreach ([...$order, 'Others'] as $role) {
            if (! isset($groups[$role])) {
                continue;
            }
            uasort($groups[$role], fn ($a, $b) => [$b['calls'], $b['jobs']] <=> [$a['calls'], $a['jobs']]);
            $lines = ['*'.$role.'*'];
            foreach ($groups[$role] as $person => $p) {
                $lines[] = '• '.$person.' — '.$p['calls'].' '.($p['calls'] === 1 ? 'call' : 'calls').($new ? ' · '.$p['jobs'].' '.($p['jobs'] === 1 ? 'job' : 'jobs').' created' : '');
            }
            $sections[] = implode("\n", $lines);
        }

        return $head."\n\n".implode("\n\n", $sections);
    }

    /**
     * The branches whose Local Services jobs made the company less than their
     * Local Services spend: Workiz's Sales profit on the lsa source's jobs
     * (reports.paid_sources), less Google's Local Services cost. Only the
     * losing ones (Jose, 2026-11-06).
     */
    private function lsaLosses(Collection $branches, CarbonImmutable $from, CarbonImmutable $to): string
    {
        $source = MarketingReport::sourceOf('lsa');
        $head = ["\u{1F4C9} *Local Services · locations losing money*", '_'.self::period($from, $to).' · Workiz profit on '.($source ?? 'Local Services').' jobs, less Local Services spend_', ''];
        if ($source === null) {
            return implode("\n", [...$head, 'No job source is set as lsa in Settings › Lists › Paid job sources.']);
        }

        $losing = [];
        $unread = [];
        $judged = 0;
        foreach ($branches as $branch) {
            $cost = $this->ads->forBranch($branch, $from->startOfDay(), $to->endOfDay());
            if ($cost['accounts'] === 0) {
                continue;
            }
            $sales = ReportJobs::sales((string) $branch->getKey())->whereBetween('starts_at', self::range($from, $to))->get();
            // No Sales rows at all is Workiz unread, not a branch that made nothing.
            if ($sales->isEmpty()) {
                $unread[] = ($this->name)($branch);

                continue;
            }
            $judged++;
            $lsa = $sales->where('job_source', $source);
            $net = (int) round((float) $lsa->sum('profit') * 100) - $cost['lsa']['cost_minor'];
            if ($net < 0) {
                $losing[] = [($this->name)($branch), $net, (int) round((float) $lsa->sum('profit') * 100), $cost['lsa']['cost_minor'], $lsa->count()];
            }
        }

        usort($losing, fn ($a, $b) => $a[1] <=> $b[1]);
        $lines = $head;
        if ($judged === 0) {
            $lines[] = 'No branch with a Google Ads account has Workiz Sales figures for these dates.';
        } elseif ($losing === []) {
            $lines[] = "\u{2705} No location lost money on Local Services.";
        }
        foreach ($losing as [$name, $net, $profit, $spent, $jobs]) {
            $lines[] = '• *'.$name.'*: '.self::money($net).' · profit '.self::money($profit).' on '.$jobs.' '.($jobs === 1 ? 'job' : 'jobs').' · spent '.self::money($spent);
        }
        if ($unread !== []) {
            $lines[] = '';
            $lines[] = "\u{26A0}\u{FE0F} No Workiz Sales figures yet, so not judged: ".implode(', ', $unread);
        }

        return implode("\n", $lines);
    }

    private static function adLine(string $where, int $spentMinor, int $jobs): string
    {
        return '• *'.$where.'*: spent '.self::money($spentMinor).' · '.$jobs.' '.($jobs === 1 ? 'job' : 'jobs')
            .' · '.($jobs > 0 ? self::money(intdiv($spentMinor, $jobs)).' per job' : 'no jobs');
    }

    /** A tag as a person reads it, letters and digits only: "Paid – Estimated" with any dot in front is "paidestimated". */
    private static function tagKey(string $tag): string
    {
        return (string) preg_replace('/[^\p{L}\p{N}]+/u', '', mb_strtolower($tag));
    }

    /** @return array<string, string> a list's tags as written => as matched */
    private static function tags(string $list): array
    {
        return collect(app(BusinessLists::class)->rows($list))
            ->mapWithKeys(fn (array $row) => [(string) ($row['value']['tag'] ?? $row['key']) => self::tagKey((string) ($row['value']['tag'] ?? $row['key']))])
            ->all();
    }

    /** @param  array<string, string>  $tags  from tags() */
    private static function tagged(mixed $jobTags, array $tags): bool
    {
        $have = array_map(fn ($t) => self::tagKey((string) $t), (array) (is_string($jobTags) ? json_decode($jobTags, true) : $jobTags));

        return array_intersect(array_values($tags), $have) !== [];
    }

    /** @return array{0: string, 1: string} wall-clock stamps, as jobs and payments store them */
    private static function range(CarbonImmutable $from, CarbonImmutable $to): array
    {
        return [$from->toDateString().' 00:00:00', $to->toDateString().' 23:59:59'];
    }

    private static function period(CarbonImmutable $from, CarbonImmutable $to): string
    {
        return $from->isSameDay($to) ? $from->format('D, M j, Y') : $from->format('M j').' - '.$to->format('M j, Y');
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
            $jobs = ReportJobs::jobs((string) $branch->getKey())->where('j.job_source', MarketingReport::sourceOf('ppc') ?? '')
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

        // The latest day with a figure: a sheet can hold a day as zeros before it is entered (ACE's Nov 8, 2026-11-09).
        $latest = $rows->filter(fn ($r) => (float) ($r['amount'] ?? 0) + (float) ($r['calls'] ?? 0) + (float) ($r['jobs'] ?? 0) > 0)->max('date') ?? $rows->max('date');
        // A table without calls (ACE's) shows none rather than "Calls: 0".
        $hasCalls = $rows->contains(fn ($r) => ($r['calls'] ?? null) !== null);
        $lines = [$head, '*Data date: '.CarbonImmutable::parse($latest)->format('M j').'*', ''];
        $total = ['cost' => 0, 'calls' => 0, 'jobs' => 0];
        foreach ($rows->where('date', $latest)->groupBy(fn ($r) => trim((string) ($r['area'] ?? '')) ?: 'No area')->sortKeys() as $area => $r) {
            $cost = $r->sum(fn ($x) => AirtableSections::cents($x['amount'] ?? 0));
            $calls = (int) $r->sum(fn ($x) => (float) ($x['calls'] ?? 0));
            $jobs = (int) $r->sum(fn ($x) => (float) ($x['jobs'] ?? 0));
            $lines[] = '*'.$area.'*';
            $lines[] = '• Spent: '.self::money($cost).($hasCalls ? ' · Calls: '.$calls : '').' · Jobs: '.$jobs;
            $lines[] = '• Cost/job: '.($jobs > 0 ? self::money(intdiv($cost, $jobs)) : 'N/A')
                .($hasCalls ? ' · Call-to-job: '.($calls > 0 ? number_format($jobs / $calls * 100, 1).'%' : 'N/A') : '');
            $total['cost'] += $cost;
            $total['calls'] += $calls;
            $total['jobs'] += $jobs;
        }
        $lines[] = '';
        $lines[] = '*TOTAL* · Spent: '.self::money($total['cost']).($hasCalls ? ' · Calls: '.$total['calls'] : '').' · Jobs: '.$total['jobs'];

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
     * The In Progress jobs scheduled in the dates, per technician per branch,
     * from EzBizi's own jobs and crews (A1 Leads' daily-job-assignments, which
     * read Workiz's Jobs tab and under-counted). Jobs with a tag the business
     * leaves out (reports.tags_not_in_progress: Deposit Received, which Workiz
     * also spells "DEPOSIT RECIEVED") are not counted.
     * ponytail: a date is in every branch's own wall time, as jobs store it.
     */
    private function jobAssignments(Collection $branches, CarbonImmutable $from, CarbonImmutable $to): string
    {
        $skip = self::tags('reports.tags_not_in_progress');
        $jobs = DB::table('jobs')->whereIn('franchise_id', $branches->pluck('id'))->whereNull('deleted_at')
            ->whereRaw('lower(status) = ?', ['in progress'])
            ->whereDate('scheduled_for', '>=', $from->toDateString())->whereDate('scheduled_for', '<=', $to->toDateString())
            ->get(['id', 'franchise_id', 'tags'])
            ->reject(fn ($j) => self::tagged($j->tags, $skip));
        $crew = DB::table('job_technicians as jt')->join('scheduling_technicians as t', 't.id', '=', 'jt.technician_id')
            ->whereIn('jt.job_id', $jobs->pluck('id'))->get(['jt.job_id', 't.name'])->groupBy('job_id');
        $plural = fn (int $n) => $n.' job'.($n === 1 ? '' : 's');

        $lines = ["\u{1F9F0} *".($from->isSameDay($to) ? "Today's In Progress jobs" : 'In Progress jobs').' by technician*',
            '_'.self::period($from, $to).' · measured '.now(OrgTimezone::of())->format('g:i A T').' from EzBizi jobs_'];
        if ($skip !== []) {
            $lines[] = '_Excludes jobs tagged '.implode(', ', array_keys($skip)).'._';
        }
        $empty = [];
        $assigned = 0;
        foreach ($branches as $branch) {
            $mine = $jobs->where('franchise_id', $branch->getKey());
            if ($mine->isEmpty()) {
                if ($this->listed($branch)) {
                    $empty[] = ($this->name)($branch);
                }

                continue;
            }
            $lines[] = '';
            $lines[] = '*'.($this->name)($branch).' — '.$plural($mine->count()).'*';
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
