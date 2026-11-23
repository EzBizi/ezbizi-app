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
        'weekly_company' => 'Weekly company report · every branch in a PDF',
    ];

    /** kind => the report's name in its title; its first heading, when that says the same, is dropped under the title. */
    public const NAMES = [
        'ppc' => 'Google PPC · Daily breakdown',
        'marketing_ppc' => 'Marketing PPC · Daily update',
        'dispatchers' => 'Dispatcher performance',
        'technician_sales' => 'Technician sales',
        'weekly_losses' => 'Weekly Net Loss Report',
        'weekly_reviews' => 'Weekly reviews by branch',
        'job_assignments' => "Today's In Progress jobs by technician",
        'revenue_collected' => 'Revenue collected',
        'evening' => 'Daily 8 PM report',
        'weekly' => 'Weekly report',
        'weekly_company' => 'Weekly company report',
    ];

    /** kind => the emoji its title starts with, its heading's where it has one (Jose, 2026-11-10). */
    public const ICONS = [
        'ppc' => "\u{1F4CA}",
        'marketing_ppc' => "\u{1F4CA}",
        'dispatchers' => "\u{1F4CB}",
        'technician_sales' => "\u{1F477}",
        'weekly_losses' => "\u{1F4C9}",
        'weekly_reviews' => "\u{2B50}",
        'job_assignments' => "\u{1F9F0}",
        'revenue_collected' => "\u{1F4B5}",
        'evening' => "\u{1F319}",
        'weekly' => "\u{1F4C5}",
        'weekly_company' => "\u{1F4D1}",
    ];

    /** A text's own date: "Mon, Nov 9, 2026", "Nov 3 - Nov 9, 2026". */
    private const DATE = '/(?:(?<weekday>Mon|Tue|Wed|Thu|Fri|Sat|Sun), )?(?<from>[A-Z][a-z]{2} \d{1,2})(?: - (?<to>(?:[A-Z][a-z]{2} )?\d{1,2}))?, \d{4}/';

    private const RULE = "\n\n\u{2014}\u{2014}\u{2014}\u{2014}\u{2014}\n\n";

    public function __construct(
        private readonly PeriodReport $report,
        private readonly AdsCost $ads,
        private readonly PerformancePeople $performance,
    ) {}

    /** @param list<string>|null $branches */
    public function render(string $kind, Organization $organization, CarbonImmutable $day, ?array $branches = null): string
    {
        $this->at = $day;
        $this->locations = LocationOrder::positions();
        // The listed branches in the list's order, every other branch after (Shoval, 2026-11-09).
        $list = LocationOrder::sort(Franchise::query()->switchedOn()
            ->when($branches !== null, fn ($q) => $q->whereIn('id', $branches))
            ->orderBy('name')->get());
        // A listed name is one branch: A-1's "Texas", not AngiSweep's.
        $this->claimed = LocationOrder::claimed($list, $this->locations);
        // One company's branches read as "Texas"; several companies' need theirs, unless the business listed it by name.
        $several = $list->pluck('company_id')->unique()->count() > 1;
        $this->several = $several;
        $this->name = fn (Franchise $b) => ! $several || ($this->locations !== [] && $this->listed($b)) ? (string) $b->name : BranchName::of($b);

        $text = match ($kind) {
            'ppc' => $this->ppc($list, $day),
            'marketing_ppc' => $this->marketingPpc($organization, $day, $branches === null ? null : $list),
            'dispatchers' => $this->dispatchers($day, $branches),
            'technician_sales' => $this->technicianSales($list, $day),
            'weekly_losses' => $this->weeklyLosses($list, $day),
            'weekly_reviews' => $this->weeklyReviews($list, $day),
            'weekly_company' => $this->weeklyCompany($list, $day),
            'job_assignments' => $this->jobAssignments($list, $day, $day),
            'revenue_collected' => $this->revenue($list, $day->subDay(), $day->subDay()),
            'evening' => implode(self::RULE, [$this->jobsCreated($list, $day, $day), $this->googleAds($list, $day, $day), $this->metaAds($list, $day, $day)]),
            'weekly' => implode(self::RULE, [
                "\u{1F4C5} *Weekly report*\n_".$this->period($day->subDays(6), $day).'_',
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

        // One blank line between sections, never a run of them (Meta Ads with no branch line, 2026-11-11).
        return $text === '' ? '' : (string) preg_replace("/\n{3,}/", "\n\n", implode("\n\n", [$text, ...$this->stale($kind, $list)]));
    }

    /**
     * A source that has not caught up, said under the report: Rodrigo held such a report back (audit #9, 2026-11-11).
     * Workiz over two hours behind the run, Google reviews over 36.
     *
     * @return list<string>
     */
    private function stale(string $kind, Collection $branches): array
    {
        $lines = [];
        $reads = match ($kind) {
            'technician_sales', 'job_assignments', 'evening' => ['jobs'],
            'revenue_collected' => ['payments'],
            'weekly' => ['jobs', 'payments'],
            default => [],
        };
        $when = fn (string $utc) => CarbonImmutable::parse($utc, 'UTC')->setTimezone($this->at->getTimezone())->format('M j, g:i A');
        foreach ($reads as $what) {
            $behind = $branches->mapWithKeys(fn (Franchise $b) => [($this->name)($b) => ReportJobs::syncedAt((string) $b->getKey(), $what)])
                ->filter(fn ($at) => $at !== null && CarbonImmutable::parse($at, 'UTC')->lessThan($this->at->subHours(2)));
            if ($behind->isNotEmpty()) {
                $lines[] = "\u{26A0}\u{FE0F} Workiz {$what} last synced ".$when($behind->min()).' for '.$behind->keys()->implode(', ').': anything since is not in these figures.';
            }
        }
        if ($kind === 'weekly_reviews') {
            $read = DB::table('social_connections')->whereIn('id', DB::table('reviews')->whereIn('franchise_id', $branches->pluck('id'))->distinct()->select('connection_id'))
                ->min('last_reached_at');
            if ($read !== null && CarbonImmutable::parse($read, 'UTC')->lessThan($this->at->subHours(36))) {
                $lines[] = "\u{26A0}\u{FE0F} Google reviews last read ".$when($read).': newer reviews are not in these figures.';
            }
        }

        return $lines;
    }

    /**
     * The text under ONE header line, "A-1 Chimney · Revenue collected · Mon,
     * Nov 9" (Jose, 2026-11-10): the date its figures are for, read off its
     * own first lines and taken out of them, and its first heading dropped
     * when that is the report's name.
     *
     * @param  list<string>|null  $branches
     * @return array{0: string, 1: string} the title and the text under it
     */
    public function headed(string $kind, string $whose, Organization $organization, CarbonImmutable $day, ?array $branches = null): array
    {
        $lines = explode("\n", $this->render($kind, $organization, $day, $branches));
        if (preg_match('/^(?:\S+ )?\*(.+)\*$/u', $lines[0], $m) === 1 && strcasecmp($m[1], self::NAMES[$kind]) === 0) {
            array_shift($lines);
        }
        $date = $day->format('D, M j');
        foreach ([0, 1] as $i) {
            if (! isset($lines[$i]) || preg_match(self::DATE, $lines[$i], $d) !== 1) {
                continue;
            }
            $date = ($d['weekday'] !== '' ? $d['weekday'].', ' : '').$d['from'].(($d['to'] ?? '') !== '' ? ' - '.$d['to'] : '');
            $italic = preg_match('/^_(.*)_$/u', $lines[$i], $inner) === 1;
            $rest = trim(str_replace($d[0], '', $italic ? $inner[1] : $lines[$i]), ' ·');
            // A span's bare "through 8:01 PM" goes with its date; each section says it again (the Sunday weekly, audit #14).
            if ($rest === '' || (($d['to'] ?? '') !== '' && str_starts_with($rest, 'through '))) {
                unset($lines[$i]);
            } else {
                $lines[$i] = $italic ? '_'.ucfirst($rest).'_' : ucfirst($rest);
            }
            break;
        }

        // A report whose first section was only its heading starts at what follows (the weekly's rule).
        return [self::ICONS[$kind].' '.$whose.' · '.self::NAMES[$kind].' · '.$date, (string) preg_replace('/^[\s\x{2014}]+/u', '', implode("\n", $lines))];
    }

    /** @var \Closure(Franchise): string */
    private \Closure $name;

    /** When the text is made: a rule's run. */
    private CarbonImmutable $at;

    /** The rule's branches are more than one company's. */
    private bool $several = false;

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
        // When it was read: a payment entered after the 1 AM run is not in it (2026-11-10).
        $lines = ["\u{1F4B5} *Revenue collected*", '_'.$this->period($from, $to).' · payments received, as of '.$this->at->format('M j, g:i A').'_', ''];
        $total = 0;
        foreach ($branches as $branch) {
            $cents = (int) round((float) ReportJobs::payments((string) $branch->getKey())->whereBetween('starts_at', self::range($from, $to))->sum('amount') * 100);
            if ($cents === 0 && ! $this->listed($branch)) {
                continue;
            }
            $lines[] = '• *'.($this->name)($branch).'*: '.self::money($cents);
            $total += $cents;
        }

        return implode("\n", [...$lines, '', "\u{1F4B0} *TOTAL: ".self::money($total).'*']);
    }

    /** Jobs booked per location, less the tags the business leaves out (reports.tags_not_created). */
    private function jobsCreated(Collection $branches, CarbonImmutable $from, CarbonImmutable $to): string
    {
        $skip = ReportJobs::tags('reports.tags_not_created');
        $lines = ["\u{1F4DD} *Jobs created*", '_'.$this->period($from, $to).'_'];
        if ($skip !== []) {
            $lines[] = '_Not counting jobs tagged '.implode(', ', array_keys($skip)).'._';
        }
        $lines[] = '';
        $total = 0;
        foreach ($branches as $branch) {
            $n = ReportJobs::created(ReportJobs::jobs((string) $branch->getKey())->whereBetween('j.booked_at', self::range($from, $to))->get())->count();
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
        // Today alone: the campaigns switched on. Any span, even one ending today: every campaign that spent in it,
        // paused since or not (DMV's $142.53, 2026-11-10; the Sunday weekly dropped it too, audit 2026-11-11).
        $live = $from->isSameDay($to) && $to->isSameDay($this->at);
        $lines = ["\u{1F4CA} *Google Ads · ".($live ? 'active campaigns' : 'campaigns').'*', '_'.$this->period($from, $to).' · jobs from '.(implode(' and ', $sources) ?: 'no source').'_', ''];
        $total = ['cost' => 0, 'jobs' => 0];
        $refused = [];
        foreach ($branches as $branch) {
            $cost = $this->ads->forBranch($branch, $from->startOfDay(), $to->endOfDay(), activeOnly: $live);
            $refused = [...$refused, ...array_column($cost['refused'], 'account')];
            if ($cost['accounts'] === 0) {
                continue;
            }
            $spent = $cost['lsa']['cost_minor'] + $cost['ppc']['cost_minor'];
            $jobs = $sources === [] ? 0 : ReportJobs::created(ReportJobs::jobs((string) $branch->getKey())->whereIn('j.job_source', $sources)
                ->whereBetween('j.booked_at', self::range($from, $to))->get())->count();
            // A sub-company's jobs sit in its parent's Workiz under its own source (ACE's 6 on Nov 10 read 0, audit 2026-11-11).
            if (($company = $branch->company)?->parent_company_id !== null) {
                $jobs += self::sourcedTo((string) $branch->organization_id, (string) $company->name, ...self::range($from, $to))->count();
            }
            $lines[] = self::adLine(($this->name)($branch), $spent, $jobs);
            $total['cost'] += $spent;
            $total['jobs'] += $jobs;
        }
        if (count($lines) === 3 && $refused === []) {
            return implode("\n", [...array_slice($lines, 0, 2), '', 'No branch has a Google Ads account linked.']);
        }
        $lines = [...$lines, '', self::adLine('TOTAL', $total['cost'], $total['jobs'])];
        // Spend on accounts linked to no branch is said, not dropped (audit, 2026-11-10: $4,645.62 on Nov 9).
        if (($org = $branches->first()?->organization_id) !== null) {
            $loose = $this->ads->unmapped((string) $org, $branches->pluck('company_id')->filter()->unique()->values()->all(), $from->startOfDay(), $to->endOfDay(), activeOnly: $live);
            $spent = $loose['lsa']['cost_minor'] + $loose['ppc']['cost_minor'];
            if ($spent > 0) {
                $lines[] = "\u{26A0}\u{FE0F} ".$loose['accounts'].' Google Ads '.($loose['accounts'] === 1 ? 'account' : 'accounts').' linked to no branch spent '
                    .self::money($spent).'; it is not in the figures above.';
            }
        }
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
        // A listed name is the branch the list claims: A-1's Texas, not AngiSweep's (Meta DFW, 2026-11-10).
        $byName = $branches->filter(fn (Franchise $b) => $this->listed($b))->keyBy(fn (Franchise $b) => mb_strtolower(trim((string) $b->name)));
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
        $lines = ["\u{1F4F1} *Meta Ads · active campaigns*", '_'.$this->period($from, $to).' · jobs from '.($source ?? 'no source').'_', ...$this->metaFreshness(), ''];
        $total = ['spend' => 0, 'forms' => 0, 'jobs' => 0];
        foreach ($branches as $branch) {
            $jobs = $source === null ? 0 : ReportJobs::created(ReportJobs::jobs((string) $branch->getKey())->where('j.job_source', $source)
                ->whereBetween('j.booked_at', self::range($from, $to))->get())->count();
            $mine = $per[(string) $branch->getKey()] ?? ['spend' => 0, 'forms' => 0];
            if ($mine['spend'] === 0 && $jobs === 0) {
                continue;
            }
            $lines[] = self::adLine(($this->name)($branch), $mine['spend'], $jobs, [$mine['forms'].' '.($mine['forms'] === 1 ? 'form' : 'forms')]);
            $total['spend'] += $mine['spend'];
            $total['forms'] += $mine['forms'];
            $total['jobs'] += $jobs;
        }
        if (count($lines) === 3) {
            $lines[] = 'No Meta spend or Meta jobs.';
        } else {
            $lines = [...$lines, '', self::adLine('TOTAL', $total['spend'], $total['jobs'], [$total['forms'].' forms'])];
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
        $head = "\u{1F4DE} *Dispatcher performance · ".($new ? 'new' : 'existing')." clients*\n_".$this->period($from, $to).'_';
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
        $head = ["\u{1F4C9} *Local Services · locations losing money*", '_'.$this->period($from, $to).' · Workiz profit on '.($source ?? 'Local Services').' jobs, less Local Services spend_', ''];
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

    /**
     * One place's ads on one line, its $more after (Jose, 2026-11-11: "a massive wall of text").
     *
     * @param  list<string>  $more
     */
    private static function adLine(string $where, ?int $spentMinor, int $jobs, array $more = []): string
    {
        return implode(' · ', ['• *'.$where.'*: spent '.($spentMinor === null ? 'N/A' : self::money($spentMinor)), $jobs.' '.($jobs === 1 ? 'job' : 'jobs'),
            $jobs === 0 ? 'no jobs' : ($spentMinor === null ? 'N/A' : self::money(intdiv($spentMinor, $jobs))).' per job', ...$more]);
    }

    /** @return array{0: string, 1: string} wall-clock stamps, as jobs and payments store them */
    private static function range(CarbonImmutable $from, CarbonImmutable $to): array
    {
        return [$from->toDateString().' 00:00:00', $to->toDateString().' 23:59:59'];
    }

    /** The organization's jobs created in the stamps under a company's own source: Workiz spells it its way ("Angi Sweep" for AngiSweep), letters and digits compared. */
    private static function sourcedTo(string $organizationId, string $company, string $from, string $to): Collection
    {
        return ReportJobs::created(DB::table('workiz_jobs')->where('organization_id', $organizationId)->whereBetween('booked_at', [$from, $to])
            ->get(['job_source', 'service_area', 'status', DB::raw("coalesce(json_extract(payload, '$.Tags'), json_array()) as tags"),
                DB::raw("cast(coalesce(json_unquote(json_extract(payload, '$.JobTotalPrice')), 0) as decimal(12,2)) as total")]))
            ->filter(fn ($j) => ReportJobs::tagKey((string) $j->job_source) === ReportJobs::tagKey($company))->values();
    }

    /** A range ending on the run's own day says how far it reaches: "through 8:00 PM" (audit, 2026-11-10). */
    private function period(CarbonImmutable $from, CarbonImmutable $to): string
    {
        $through = $to->isSameDay($this->at) && $this->at->format('H:i:s') !== '00:00:00' ? ' · through '.$this->at->format('g:i A') : '';

        return ($from->isSameDay($to) ? $from->format('D, M j, Y') : $from->format('M j').' - '.$to->format('M j, Y')).$through;
    }

    /**
     * When Meta's figures were read, and a warning once that is over three
     * hours before the run (audit, 2026-11-10: the 8 PM text read the 5:30 AM
     * sync, so the day's spend was missing without a word).
     *
     * @return list<string>
     */
    private function metaFreshness(): array
    {
        if (($synced = MetaSpend::syncedAt()) === null) {
            return [];
        }
        $at = CarbonImmutable::parse($synced, 'UTC')->setTimezone($this->at->getTimezone());

        return $at->lessThan($this->at->subHours(3))
            ? ["\u{26A0}\u{FE0F} Meta last refreshed ".$at->format('M j, g:i A').': spend since then is not in these figures.']
            : ['_Meta as of '.$at->format('M j, g:i A').'_'];
    }

    /** Each branch's Google Search spend against its PPC jobs created that day. */
    private function ppc(Collection $branches, CarbonImmutable $day): string
    {
        $lines = ["\u{1F4CA} *Google PPC · Daily breakdown*", $day->format('D, M j, Y').' · Search ads', ''];
        $total = ['cost' => 0, 'clicks' => 0, 'jobs' => 0];
        $idle = [];

        foreach ($branches as $branch) {
            $cost = $this->ads->forBranch($branch, $day->startOfDay(), $day->endOfDay());
            if ($cost['accounts'] === 0) {
                continue;
            }
            $jobs = ReportJobs::created(ReportJobs::jobs((string) $branch->getKey())->where('j.job_source', MarketingReport::sourceOf('ppc') ?? '')
                ->whereBetween('j.booked_at', [$day->toDateString().' 00:00:00', $day->toDateString().' 23:59:59'])->get())->count();
            $c = $cost['ppc'];
            // A linked account that did nothing is a name on one line, not a row of zeros (Jose, 2026-11-11: "a massive wall of text").
            if ($c['cost_minor'] === 0 && $c['clicks'] === 0 && $jobs === 0) {
                $idle[] = ($this->name)($branch);

                continue;
            }
            $lines[] = self::adLine(($this->name)($branch), $c['cost_minor'], $jobs, self::ppcMore($c['clicks'], $c['conversions']));
            $total['cost'] += $c['cost_minor'];
            $total['clicks'] += $c['clicks'];
            $total['jobs'] += $jobs;
        }

        if (count($lines) === 3 && $idle === []) {
            return $lines[0]."\n".$lines[1]."\n\nNo branch has a Google Ads account linked.";
        }

        return implode("\n", [...$lines, '', self::adLine('TOTAL', $total['cost'], $total['jobs'], self::ppcMore($total['clicks'], null)),
            ...($idle === [] ? [] : ['_No spend: '.implode(', ', $idle).'_'])]);
    }

    /** @return list<string> a branch's clicks and Google's conversions, after its spend and jobs */
    private static function ppcMore(int $clicks, ?float $conversions): array
    {
        return [$clicks.' '.($clicks === 1 ? 'click' : 'clicks'), ...($conversions === null ? [] : [rtrim(rtrim(number_format($conversions, 1), '0'), '.').' conversions'])];
    }

    /**
     * The mapped "marketing" tables (Financials → Settings): the latest day on
     * or before $day. $branches null: the organization's own; else only those branches' tables.
     */
    private function marketingPpc(Organization $organization, CarbonImmutable $day, ?Collection $branches): string
    {
        // A branch's sheet is its company's ads: the rows carry whose (ACE's, 2026-11-10). A company rule
        // reads its branches' sheets; a sub-company's is its own section, under its name (A-1's read ACE's as its own).
        $read = fn (?Franchise $b) => ReportSources::rows((string) $organization->id, 'marketing', $b === null ? null : (string) $b->getKey(),
            $day->subDays(14)->toDateString(), $day->toDateString())->map(fn ($r) => $r + ['company' => $b?->company?->name]);
        $rows = $branches === null ? $read(null) : $branches->flatMap(fn ($b) => $read($b))->values();

        if ($rows->isEmpty()) {
            // Nothing to report is no text: a rule sends none (desk, 2026-11-03).
            return '';
        }

        return implode("\n\n", ["\u{1F4CA} *Marketing PPC · Daily update*\n_".$day->format('D, M j, Y').'_',
            ...$rows->groupBy(fn ($r) => (string) ($r['company'] ?? ''))->sortKeys()
                ->map(fn (Collection $mine, $company) => $this->marketingSection($organization, $day, $mine, $company === '' ? null : (string) $company))
                ->values()->all()]);
    }

    /**
     * One sheet's latest day with spend, by area. A company's jobs are EzBizi's,
     * not the sheet's hand-filled count (Jose, 2026-11-10): the Workiz jobs
     * credited to it, booked that day, by service area; its sheet keeps the spend.
     */
    private function marketingSection(Organization $organization, CarbonImmutable $day, Collection $rows, ?string $company): string
    {
        // The latest day with spend: a sheet can hold a day as zeros before it is entered (ACE's Nov 8, 2026-11-09).
        $latest = $rows->filter(fn ($r) => (float) ($r['amount'] ?? 0) + (float) ($r['calls'] ?? 0) > 0)->max('date') ?? $rows->max('date');
        // A table without calls (ACE's) shows none rather than "Calls: 0".
        $hasCalls = $rows->contains(fn ($r) => ($r['calls'] ?? null) !== null);
        $lines = $company !== null && $this->several ? ["\u{1F3E2} *".$company.'*'] : [];
        $lines[] = '*Data date: '.CarbonImmutable::parse($latest)->format('D, M j').'*';
        if ($latest < $day->subDay()->toDateString()) {
            $lines[] = "\u{26A0}\u{FE0F} Spend last updated ".CarbonImmutable::parse($latest)->format('M j');
        }
        $ours = collect();
        if ($company !== null) {
            $lines[] = '_Spend from the '.$company.' sheet · jobs from EzBizi_';
            $ours = self::sourcedTo((string) $organization->getKey(), $company, $latest.' 00:00:00', $latest.' 23:59:59')
                ->countBy(fn ($j) => trim((string) $j->service_area) ?: 'No area');
        }
        $lines[] = '';
        $areas = $rows->where('date', $latest)->groupBy(fn ($r) => trim((string) ($r['area'] ?? '')) ?: 'No area');
        $same = fn (string $a, string $b) => mb_strtolower($a) === mb_strtolower($b);
        // An area with jobs and nothing on the sheet still shows.
        foreach ($ours as $area => $n) {
            if (! $areas->keys()->contains(fn ($k) => $same((string) $k, (string) $area))) {
                $areas[$area] = collect();
            }
        }
        $total = ['cost' => 0, 'calls' => 0, 'jobs' => 0];
        foreach ($areas->sortBy(fn ($r, $area) => [LocationOrder::areaPlace((string) $area), $area]) as $area => $r) {
            $cost = $r->sum(fn ($x) => AirtableSections::cents($x['amount'] ?? 0));
            $calls = (int) $r->sum(fn ($x) => (float) ($x['calls'] ?? 0));
            $jobs = $company === null ? (int) $r->sum(fn ($x) => (float) ($x['jobs'] ?? 0))
                : (int) $ours->first(fn ($n, $k) => $same((string) $k, (string) $area), 0);
            $lines[] = self::adLine((string) $area, $r->isEmpty() ? null : $cost, $jobs,
                $hasCalls ? [$calls.' calls', 'call-to-job: '.($calls > 0 ? number_format($jobs / $calls * 100, 1).'%' : 'N/A')] : []);
            $total['cost'] += $cost;
            $total['calls'] += $calls;
            $total['jobs'] += $jobs;
        }
        $lines[] = '';
        $lines[] = self::adLine('TOTAL', $total['cost'], $total['jobs'], $hasCalls ? [$total['calls'].' calls'] : []);

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
            $rows = collect($this->performance->for(null, $day->startOfDay(), $day->endOfDay(), $id === '' ? null : (string) $id, $branches))
                ->filter(fn ($p) => ($p['answered'] ?? 0) > 0 || ($p['jobs'] ?? 0) > 0)
                ->sortByDesc(fn ($p) => $p['answered'] ?? 0);
            $title = count($accounts) > 1 ? ['*'.$name.'*'] : [];
            if ($rows->isEmpty()) {
                $sections[] = implode("\n", [...$title, 'No answered calls or jobs created.']);

                continue;
            }
            $lines = [...$title, '*Jobs created: '.$rows->sum('jobs').'*', ''];
            foreach ($rows as $p) {
                // Booked out of the new-client calls they answered, as Performance (Trey, 2026-11-04),
                // less those still Needing Attention (Jose, 2026-11-12) or Awaiting review (2026-11-23).
                $new = (int) ($p['new_client'] ?? 0) - (int) ($p['needs_attention'] ?? 0) - (int) ($p['awaiting_review'] ?? 0);
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
        $skip = ReportJobs::tags('reports.tags_not_in_progress');
        $jobs = DB::table('jobs')->whereIn('franchise_id', $branches->pluck('id'))->whereNull('deleted_at')
            ->whereRaw('lower(status) = ?', ['in progress'])
            ->whereDate('scheduled_for', '>=', $from->toDateString())->whereDate('scheduled_for', '<=', $to->toDateString())
            ->get(['id', 'franchise_id', 'tags'])
            ->reject(fn ($j) => ReportJobs::tagged($j->tags, $skip));
        $crew = DB::table('job_technicians as jt')->join('scheduling_technicians as t', 't.id', '=', 'jt.technician_id')
            ->whereIn('jt.job_id', $jobs->pluck('id'))->get(['jt.job_id', 't.name'])->groupBy('job_id');
        $plural = fn (int $n) => $n.' job'.($n === 1 ? '' : 's');

        $lines = ["\u{1F9F0} *".($from->isSameDay($to) ? "Today's In Progress jobs" : 'In Progress jobs').' by technician*',
            '_'.$this->period($from, $to).' · measured '.now(OrgTimezone::of())->format('g:i A T').' from EzBizi jobs_'];
        if ($skip !== []) {
            $lines[] = '_Excludes jobs tagged '.implode(', ', array_keys($skip)).'._';
        }
        $empty = [];
        $assigned = 0;
        $each = [];
        foreach ($branches as $branch) {
            $mine = $jobs->where('franchise_id', $branch->getKey());
            if ($mine->isEmpty()) {
                if ($this->listed($branch)) {
                    $empty[] = ($this->name)($branch);
                }

                continue;
            }
            $each[] = '';
            $each[] = '*'.($this->name)($branch).' — '.$plural($mine->count()).'*';
            $byTech = $mine->flatMap(fn ($j) => $crew->get($j->id, collect())->pluck('name'))->countBy()->sortKeys();
            foreach ($byTech as $name => $n) {
                $each[] = '• *'.$name.'*: '.$plural($n);
            }
            $unassigned = $mine->filter(fn ($j) => ! $crew->has($j->id))->count();
            if ($unassigned > 0) {
                $each[] = "• *\u{26A0} Unassigned*: ".$plural($unassigned);
            }
            $assigned += $mine->count() - $unassigned;
        }
        if ($empty !== []) {
            $each[] = '';
            $each[] = '_No jobs: '.implode(', ', $empty).'_';
        }

        // The count first, then a technician a line (Jose, 2026-11-11: "not even relaying any information").
        return implode("\n", [...$lines, '', '*TOTAL: '.$jobs->count().' In Progress '.($jobs->count() === 1 ? 'job' : 'jobs').'*',
            $assigned.' assigned · '.($jobs->count() - $assigned).' unassigned', ...$each]);
    }

    /** Each branch's technicians on the jobs scheduled that day: completed, canceled, sales, and deposits on their jobs. */
    private function technicianSales(Collection $branches, CarbonImmutable $day): string
    {
        $lines = [];
        $all = ['done' => 0, 'canceled' => 0, 'gross' => 0.0, 'deposits' => 0.0];
        $everyone = [];

        foreach ($branches as $branch) {
            $f = $this->report->figures($branch, $day->toDateString(), $day->toDateString(), scheduled: true);
            $techs = $f['techs'];
            if ($techs === []) {
                continue;
            }
            // Jobs, not technicians: a job two worked is one (Ohio's 19 done were 17 jobs, 2026-11-10).
            $done = array_sum(array_column($f['closed'], 'done'));
            $all['done'] += $done;
            $all['canceled'] += array_sum(array_column($f['closed'], 'canceled'));
            // The branch's own count and sales on its heading: what a short message keeps (desk, 2026-11-11).
            $lines[] = '*'.($this->name)($branch).' — '.$done.' completed · '.self::money((int) round(array_sum(array_column($techs, 'gross')) * 100)).' sales*';
            foreach ($techs as $t) {
                $lines[] = '• *'.$t['name'].'*: '.$t['done'].' completed · '.$t['canceled'].' canceled · '
                    .self::money((int) round($t['gross'] * 100)).' sales · '.self::money((int) round($t['deposits'] * 100)).' deposits';
                $all['gross'] += $t['gross'];
                $all['deposits'] += $t['deposits'];
                $everyone[] = $t + ['branch' => ($this->name)($branch)];
            }
            $lines[] = '';
        }

        $head = "\u{1F477} *Technician sales*\n_".$this->period($day, $day).'_';
        if ($lines === []) {
            return $head."\n\nNo technician activity.";
        }

        // The total a figure a line, then the top technicians, then each branch (Jose, 2026-11-11: "basically use line breaks").
        // The emoji are the WhatsApp message's; the PDF drops them (Jose, 2026-11-10).
        $top = collect($everyone)->where('gross', '>', 0)->sortByDesc('gross')->take(5)
            ->map(fn (array $t) => '• *'.$t['name'].' · '.$t['branch'].'*: '.self::money((int) round($t['gross'] * 100)).' sales · '.$t['done'].' completed');

        return implode("\n", [$head, '', "\u{1F4B0} *TOTAL*", "\u{2705} ".$all['done'].' completed', "\u{274C} ".$all['canceled'].' canceled',
            "\u{1F4B5} ".self::money((int) round($all['gross'] * 100)).' sales', "\u{1F3E6} ".self::money((int) round($all['deposits'] * 100)).' deposits',
            ...($top->isEmpty() ? [] : ['', '*Top technicians*', ...$top->values()->all()]), '', ...$lines]);
    }

    /** Last Monday-Sunday: the branches whose profit & loss closed negative. */
    private function weeklyLosses(Collection $branches, CarbonImmutable $day): string
    {
        [$from, $to] = [$day->startOfWeek()->subWeek(), $day->startOfWeek()->subDay()];
        $losses = [];
        $pending = [];
        $unmapped = [];

        foreach ($branches as $branch) {
            // No P&L table at all is a different fact from a week not in yet (MA and the sub-companies, 2026-11-10).
            if (! ReportSources::mapped((string) $branch->organization_id, 'pnl', (string) $branch->getKey())) {
                $unmapped[] = ($this->name)($branch);

                continue;
            }
            $pnl = $this->report->profitAndLoss($branch, $from->toDateString(), $to->toDateString());
            if ($pnl['net'] === null) {
                $pending[] = ($this->name)($branch);
            } elseif ($pnl['net'] < 0) {
                $losses[] = [($this->name)($branch), $pnl];
            }
        }

        usort($losses, fn ($a, $b) => $a[1]['net'] <=> $b[1]['net']);
        $lines = ["\u{1F4C9} *Weekly Net Loss Report*", '_'.$from->format('M j').' - '.$to->format('M j, Y').'_', ''];
        // No all-clear on missing data (desk, 2026-11-03): a branch whose week
        // is not complete cannot be judged, and the text says so first.
        $withTable = $branches->count() - count($unmapped);
        $judged = $withTable - count($pending);
        $noTable = $unmapped === [] ? [] : ['', '_No P&L table mapped: '.implode(', ', $unmapped).'_'];
        // Nothing to judge yet sends nothing: the rule runs hourly on Wednesdays until the P&L lands (desk, 2026-11-11).
        if ($judged === 0) {
            return '';
        }
        if ($pending !== []) {
            $lines[] = "\u{26A0}\u{FE0F} Only {$judged} of the {$withTable} branches with a P&L table have a complete week; the rest can't be judged yet.";
        }
        $lines[] = '• *'.count($losses).' '.(count($losses) === 1 ? 'branch' : 'branches').' with negative net profit*';
        $lines[] = '• *Combined losses: '.self::money((int) round(array_sum(array_map(fn ($l) => $l[1]['net'], $losses)) * 100)).'*';
        // A branch a line (Jose, 2026-11-11: "a massive wall of text").
        $lines = [...$lines, ...($losses === [] ? [] : ['']), ...array_map(fn ($l) => '• *'.$l[0].'*: net '.self::money((int) round($l[1]['net'] * 100))
            .' · income '.self::money((int) round($l[1]['income'] * 100)).' · expenses '.self::money((int) round($l[1]['costs'] * 100)), $losses)];
        if ($pending !== []) {
            $lines = [...$lines, '', "\u{26A0}\u{FE0F} *Not complete yet*", ...array_map(fn ($name) => '• '.$name, $pending)];
        }

        return implode("\n", [...$lines, ...$noTable]);
    }

    /**
     * Last Monday-Sunday's net per branch; the PDF is every branch in full (PeriodReport::consolidated).
     * Desk, 2026-11-11 (audit #5): it had never reached anyone; now a rule sends it once the P&L lands.
     */
    private function weeklyCompany(Collection $branches, CarbonImmutable $day): string
    {
        [$from, $to] = [$day->startOfWeek()->subWeek(), $day->startOfWeek()->subDay()];
        $lines = [];
        $pending = [];
        foreach ($branches as $branch) {
            if (! ReportSources::mapped((string) $branch->organization_id, 'pnl', (string) $branch->getKey())) {
                continue;
            }
            $net = $this->report->profitAndLoss($branch, $from->toDateString(), $to->toDateString())['net'];
            if ($net === null) {
                $pending[] = ($this->name)($branch);
            } else {
                $lines[] = '• *'.($this->name)($branch).'*: net '.self::money((int) round($net * 100));
            }
        }
        // As the losses: nothing to judge yet sends nothing, and the hourly rule asks again.
        if ($lines === []) {
            return '';
        }

        return implode("\n", ["\u{1F4D1} *Weekly company report*", '_'.$from->format('M j').' - '.$to->format('M j, Y').'_', '', ...$lines,
            ...($pending === [] ? [] : ['', "\u{26A0}\u{FE0F} *Not in the P&L yet*", ...array_map(fn ($name) => '• '.$name, $pending)]), '', '_Every branch in full is in the PDF._']);
    }

    /**
     * The document a report carries instead of its text printed: the weekly company PDF; null for the rest.
     *
     * @param  list<string>|null  $branches
     */
    public function attachment(string $kind, Organization $organization, CarbonImmutable $day, ?array $branches = null): ?string
    {
        return $kind === 'weekly_company'
            ? $this->report->consolidated($organization, $day->startOfWeek()->subWeek()->toDateString(), $day->startOfWeek()->subDay()->toDateString(), $branches)
            : null;
    }

    /** Last Monday-Sunday: new Google reviews per branch, 4-5 stars positive, 1-3 negative. */
    private function weeklyReviews(Collection $branches, CarbonImmutable $day): string
    {
        [$from, $to] = [$day->startOfWeek()->subWeek(), $day->startOfWeek()->subDay()->endOfDay()];
        // A review Google took down is not one of the week's (audit #12, 2026-11-11: 47 counted, 46 there).
        $counts = DB::table('reviews')->whereIn('franchise_id', $branches->pluck('id'))->whereNull('removed_at')
            ->whereBetween('reviewed_at', [$from->utc(), $to->utc()])
            ->groupBy('franchise_id')
            ->selectRaw('franchise_id, sum(rating >= 4) positive, sum(rating between 1 and 3) negative')
            ->get()->keyBy('franchise_id');

        $lines = ["\u{2B50} *Weekly reviews by branch*", '_'.$from->format('M j').' - '.$to->format('M j, Y').'_', ''];
        $split = fn ($positive, $negative) => "\u{2705} ".(int) $positive.' positive · '."\u{26A0}\u{FE0F} ".(int) $negative.' negative';
        // A branch a line, the total on its own (Jose, 2026-11-11).
        foreach ($branches as $branch) {
            if (($c = $counts->get($branch->getKey())) !== null) {
                $lines[] = '• *'.($this->name)($branch).'*: '.$split($c->positive, $c->negative);
            }
        }
        $lines[] = '';
        $lines[] = '*TOTAL: '.(int) $counts->sum(fn ($c) => $c->positive + $c->negative).' reviews* · '.$split($counts->sum('positive'), $counts->sum('negative'));
        $lines[] = '';
        $lines[] = '_Positive: 4–5★ · Negative: 1–3★. New reviews in this week only._';

        return implode("\n", $lines);
    }

    private static function money(int $cents): string
    {
        return ($cents < 0 ? '-$' : '$').number_format(abs($cents) / 100, 2);
    }
}
