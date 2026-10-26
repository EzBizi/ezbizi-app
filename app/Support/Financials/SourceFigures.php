<?php

namespace App\Support\Financials;

use App\Models\AirtableRecord;
use App\Models\FinancialAdjustment;
use App\Models\FinancialRepairWeek;
use App\Models\FinancialSource;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Figures worked straight from the organization's mapped Airtable sources
 * (financial_sources over airtable_records): the repair-team cost split and
 * the refunds / disputes / returned checks per technician. What staff ask the
 * WhatsApp bot for ("repair split for NJ last week"), read through these GETs.
 *
 * Column names come from each source's field map; where it is silent the
 * names Airtable bookkeeping commonly uses stand in (DEFAULTS), so a tenant
 * maps only what differs. Runs inside the tenant.
 */
final class SourceFigures
{
    private const DEFAULTS = [
        'date' => ['Date'],
        'team' => ['Team'],
        'kind' => ['Record type'],
        'technician' => ['Technician', 'Technicians', 'Tech Name', 'Technician Name'],
        'job_number' => ['Job Number'],
        'employee' => ['Employee Cost'],
        'helper' => ['Helper Cost'],
        'gas' => ['Gas Cost'],
        'operation' => ['Ave Operation Cost'],
        'amount' => ['Refund/dispute Amount', 'Amount', 'Cost'],
        'reason' => ['Reason of Refund or Dispute', 'Reason'],
        'status' => ['Open/Close Job', 'Job Status'],
        // Repair report. Airtable's own column names carry a trailing space.
        'hours' => ['Total Hours', 'Total Hours '],
        'parts' => ['Parts Cost'],
        'tech_share' => ['Tech Share'],
        'job_amount' => ['Job Amount'],
        // No defaults: "Total External Share" on A-1's sheets is not the
        // report's external share (the report shows $0 where it holds $2,925),
        // and no sheet has a contracting or approval column. Mapped, they count.
        'external_share' => [],
        'contracting' => [],
        'approval' => [],
    ];

    /** Weekly-summary columns of the repair report, in the order they add to the total cost. */
    private const REPORT_COSTS = ['employee' => 'paycheck', 'helper' => 'helper', 'operation' => 'operation', 'gas' => 'gas'];

    /** Job-row columns of the repair report. */
    private const REPORT_JOB_SUMS = ['parts' => 'parts', 'tech_share' => 'tech_share', 'external_share' => 'external_share', 'contracting' => 'contracting', 'job_amount' => 'jobs_amount'];

    /**
     * The weekly repair-team report per place: one card per team (repair
     * technician) and the teams combined. Each cost line separate, in cents.
     *
     * From the sheet: hours, paycheck (Employee Cost), helper, operation and
     * gas from the team's Weekly Summary row(s); parts, tech share and job
     * amount summed over its Job rows (a summary row's own Tech Share is the
     * sheet's formula residue and is not counted). Computed here:
     *   total cost      = paycheck + helper + operation + gas + parts
     *   company share   = total cost - tech share - external share - contracting
     *   each share %    = share / total cost
     *   cost from gross = company share / jobs amount (what Jose's report shows:
     *                     NJ 10-12..10-18 Darren 2,239.50 / 19,860 = 11.3%)
     * Approval is the mapped 'approval' column of the summary rows, else null.
     *
     * @return list<array>
     */
    public static function repairReport(string $from, string $to, ?string $franchiseId = null, ?string $companyId = null): array
    {
        $out = [];
        foreach (self::sources('repair', $from, $to, $franchiseId) as $source) {
            if ($companyId !== null && $source->company_id !== $companyId) {
                continue;
            }
            $summary = $source->marker('summary') ?? 'Weekly Summary';
            $jobMarker = $source->marker('job') ?? 'Job';
            $teams = [];
            $approvals = [];
            foreach (self::rows($source, $from, $to) as $f) {
                $team = trim((string) self::value($source, $f, 'team'));
                if ($team === '') {
                    continue;
                }
                $kind = (string) self::value($source, $f, 'kind');
                $t = &$teams[$team];
                $t ??= ['hours' => 0.0, 'cents' => array_fill_keys([...array_values(self::REPORT_COSTS), ...array_values(self::REPORT_JOB_SUMS)], 0), 'jobs' => 0, 'reason' => null];

                if (strcasecmp($kind, $summary) === 0) {
                    $t['summary'] = true;
                    $hours = self::value($source, $f, 'hours');
                    if (is_numeric($hours)) {
                        $t['hours'] += (float) $hours;
                    }
                    foreach (self::REPORT_COSTS as $key => $line) {
                        self::addCents($t, $line, self::value($source, $f, $key));
                    }
                    $approval = trim((string) self::value($source, $f, 'approval'));
                    if ($approval !== '') {
                        $approvals[$approval] = true;
                    }
                } elseif (strcasecmp($kind, $jobMarker) === 0) {
                    $t['jobs']++;
                    foreach (self::REPORT_JOB_SUMS as $key => $line) {
                        self::addCents($t, $line, self::value($source, $f, $key));
                    }
                }
                unset($t);
            }
            // A Monday-to-Sunday week also carries its approval in EzBizi and the
            // values typed in where the sheet left one out (RepairReport).
            $week = RepairReport::isWeek($from, $to) && $source->franchise_id
                ? FinancialRepairWeek::query()->where('franchise_id', $source->franchise_id)->whereDate('week_start', $from)->first() : null;
            foreach ((array) ($week?->overrides ?? []) as $team => $values) {
                $teams[$team] ??= ['hours' => 0.0, 'cents' => array_fill_keys([...array_values(self::REPORT_COSTS), ...array_values(self::REPORT_JOB_SUMS)], 0), 'jobs' => 0, 'reason' => null];
                foreach ((array) $values as $line => $dollars) {
                    $line === 'hours' ? $teams[$team]['hours'] = (float) $dollars : $teams[$team]['cents'][$line] = (int) round($dollars * 100);
                    $teams[$team]['typed'][$line] = true;
                }
            }
            ksort($teams);

            $cards = [];
            foreach ($teams as $team => $t) {
                $missing = $t['jobs'] > 0 && empty($t['summary'])
                    ? array_values(array_filter(['hours', 'paycheck', 'operation'], fn ($k) => empty($t['typed'][$k]))) : [];
                $cards[] = ['technician' => $team] + self::reportCard($t['hours'], $t['cents'], $t['jobs']) + ['reason' => $t['reason'], 'missing' => $missing];
            }
            $all = [];
            foreach ($teams as $t) {
                foreach ($t['cents'] as $line => $cents) {
                    $all[$line] = ($all[$line] ?? 0) + $cents;
                }
            }
            $combined = self::reportCard(array_sum(array_column($teams, 'hours')), $all, array_sum(array_column($teams, 'jobs')));

            $approver = $week?->approved_by_user_id ? User::query()->whereKey($week->approved_by_user_id)->value('name') : null;
            $row = [
                'place' => $source->franchise?->name ?? $source->company?->name,
                'week_status' => $week?->approved_at ? 'approved' : 'draft',
                'approved_by' => $approver,
                'approved_at' => $week?->approved_at?->toIso8601String(),
                'company' => $source->company?->name,
                'company_id' => $source->company_id,
                'franchise_id' => $source->franchise_id,
                'from' => $from,
                'to' => $to,
                // One value across the summaries, or null: unmapped, absent, or mixed.
                'approval' => count($approvals) === 1 ? array_key_first($approvals) : null,
                'approval_mapped' => $source->field('approval') !== null,
                'not_in_source' => array_values(array_filter(['external_share', 'contracting', 'approval'], fn ($k) => $source->field($k) === null)),
                'status' => $teams === [] ? 'no_jobs' : (array_filter(array_column($teams, 'reason')) ? 'unavailable' : 'calculated'),
                'technicians' => $cards,
                'combined' => ['count' => count($cards)] + $combined,
            ];
            $out[] = $row + ['text' => RepairReport::text($row)];
        }

        return $out;
    }

    /** @param array<string,int> $c */
    private static function reportCard(float $hours, array $c, int $jobs): array
    {
        $c += array_fill_keys(['paycheck', 'helper', 'operation', 'gas', 'parts', 'tech_share', 'external_share', 'contracting', 'jobs_amount'], 0);
        $total = $c['paycheck'] + $c['helper'] + $c['operation'] + $c['gas'] + $c['parts'];
        $company = $total - $c['tech_share'] - $c['external_share'] - $c['contracting'];
        $pct = fn (int $part, int $whole) => $whole === 0 ? null : round($part * 100 / $whole, 1);

        return [
            'hours' => round($hours, 2),
            'paycheck_cents' => $c['paycheck'],
            'helper_cents' => $c['helper'],
            'operation_cents' => $c['operation'],
            'gas_cents' => $c['gas'],
            'parts_cents' => $c['parts'],
            'total_cost_cents' => $total,
            'tech_share' => ['cents' => $c['tech_share'], 'pct' => $pct($c['tech_share'], $total)],
            'external_share' => ['cents' => $c['external_share'], 'pct' => $pct($c['external_share'], $total)],
            'contracting_profit' => ['cents' => $c['contracting'], 'pct' => $pct($c['contracting'], $total)],
            'company_share' => ['cents' => $company, 'pct' => $pct($company, $total)],
            'jobs' => $jobs,
            'jobs_amount_cents' => $c['jobs_amount'],
            'cost_from_gross_pct' => $pct($company, $c['jobs_amount']),
        ];
    }

    private static function addCents(array &$team, string $line, mixed $raw): void
    {
        if ($raw === null || $raw === '') {
            return;
        }
        is_numeric($raw)
            ? $team['cents'][$line] += (int) round($raw * 100)
            : $team['reason'] ??= "A {$line} value is not a number.";
    }

    /** @return list<array{place:?string, franchise_id:?string, status:string, groups:list<array>}> */
    public static function repairSplit(string $from, string $to, ?string $franchiseId = null): array
    {
        $out = [];
        foreach (self::sources('repair', $from, $to, $franchiseId) as $source) {
            $groups = [];
            foreach (self::rows($source, $from, $to) as $f) {
                $team = trim((string) self::value($source, $f, 'team'));
                if ($team === '') {
                    continue;
                }
                $kind = (string) self::value($source, $f, 'kind');
                $g = &$groups[$team];
                $g ??= ['jobs' => [], 'cost' => 0, 'keys' => [], 'reason' => null];

                if (strcasecmp($kind, $source->marker('summary') ?? 'Weekly Summary') === 0) {
                    foreach (['employee', 'helper', 'gas', 'operation'] as $key) {
                        $raw = self::value($source, $f, $key);
                        if ($raw === null || $raw === '') {
                            continue;
                        }
                        is_numeric($raw) && $raw >= 0
                            ? $g['cost'] += (int) round($raw * 100)
                            : $g['reason'] ??= 'A cost is negative or not a number.';
                    }
                } elseif (strcasecmp($kind, $source->marker('job') ?? 'Job') === 0) {
                    $job = trim((string) self::value($source, $f, 'job_number'));
                    if ($job === '') {
                        $g['reason'] ??= 'A job has no job number.';
                    } elseif (isset($g['keys'][$job])) {
                        $g['reason'] ??= "Job {$job} appears twice.";
                    }
                    $g['keys'][$job] = true;
                    $person = trim((string) self::value($source, $f, 'technician')) ?: '(unassigned)';
                    $g['jobs'][$person] = ($g['jobs'][$person] ?? 0) + 1;
                }
                unset($g);
            }

            ksort($groups);
            $out[] = [
                'place' => $source->franchise?->name ?? $source->company?->name,
                'franchise_id' => $source->franchise_id,
                'status' => $groups === [] ? 'no_jobs' : 'calculated',
                'groups' => array_map(function (string $team, array $g) use ($source, $from, $to) {
                    $jobs = array_sum($g['jobs']);
                    $status = $g['reason'] !== null ? 'unavailable' : ($jobs === 0 ? 'no_jobs' : 'calculated');
                    $people = $status === 'calculated' ? CostSplit::allocate($g['cost'], $g['jobs']) : [];
                    [$people, $moved, $why] = self::applyMoves($people, $source->franchise_id, $team, $from, $to);
                    if ($why !== null) {
                        [$status, $g['reason'], $people] = ['unavailable', $why, []];
                    }

                    return [
                        'team' => $team,
                        'status' => $status,
                        'reason' => $g['reason'],
                        'jobs' => $jobs,
                        'total_cents' => $status === 'unavailable' ? 0 : $g['cost'],
                        'people' => $people,
                        'moves' => $moved,
                    ];
                }, array_keys($groups), $groups),
            ];
        }

        return $out;
    }

    /** @return list<array{place:?string, franchise_id:?string, kind:string, total_cents:int, technicians:list<array>, items:list<array>}> */
    public static function refunds(string $from, string $to, ?string $franchiseId = null): array
    {
        $buckets = [];
        foreach (['refunds' => 'refund', 'disputes' => 'dispute', 'check_returns' => 'check_return'] as $role => $kind) {
            foreach (self::sources($role, $from, $to, $franchiseId) as $source) {
                $bucket = &$buckets[$source->franchise_id.'|'.$kind];
                $bucket ??= ['place' => $source->franchise?->name ?? $source->company?->name, 'franchise_id' => $source->franchise_id, 'kind' => $kind, 'items' => []];
                foreach (self::rows($source, $from, $to) as $f) {
                    $amount = self::value($source, $f, 'amount');
                    if (! is_numeric($amount)) {
                        continue;
                    }
                    $bucket['items'][] = [
                        'date' => (string) self::value($source, $f, 'date'),
                        'technician' => trim((string) self::value($source, $f, 'technician')) ?: 'Unknown',
                        'job_number' => self::value($source, $f, 'job_number'),
                        'reason' => self::value($source, $f, 'reason'),
                        'status' => self::value($source, $f, 'status'),
                        'cents' => (int) round($amount * 100),
                        'source' => 'airtable',
                    ];
                }
                unset($bucket);
            }
        }

        // Recorded in EzBizi (typed, or a confirmed chat command).
        $recorded = FinancialAdjustment::query()->with('franchise:id,name')
            ->whereIn('kind', ['refund', 'dispute', 'check_return'])->whereBetween('date', [$from, $to])
            ->when($franchiseId, fn ($q) => $q->where('franchise_id', $franchiseId))->get();
        foreach ($recorded as $a) {
            $bucket = &$buckets[$a->franchise_id.'|'.$a->kind];
            $bucket ??= ['place' => $a->franchise?->name, 'franchise_id' => $a->franchise_id, 'kind' => $a->kind, 'items' => []];
            $bucket['items'][] = [
                'date' => $a->date->toDateString(), 'technician' => $a->technician, 'job_number' => $a->job_number,
                'reason' => $a->reason, 'status' => null, 'cents' => $a->cents, 'source' => 'ezbizi', 'id' => $a->id,
            ];
            unset($bucket);
        }

        $out = [];
        foreach ($buckets as $bucket) {
            if ($bucket['items'] === []) {
                continue;
            }
            $bucket['total_cents'] = array_sum(array_column($bucket['items'], 'cents'));
            $bucket['technicians'] = collect($bucket['items'])->groupBy('technician')
                ->map(fn (Collection $rows, string $tech) => ['technician' => $tech, 'count' => $rows->count(), 'cents' => $rows->sum('cents')])
                ->sortBy('technician')->values()->all();
            $out[] = $bucket;
        }

        return $out;
    }

    /**
     * Repair-split moves recorded for this place, team and period: cents taken
     * from one technician and given to another. A move that takes more than
     * the technician was given makes the team unavailable rather than negative.
     *
     * @return array{0: list<array>, 1: list<array>, 2: ?string}
     */
    private static function applyMoves(array $people, ?string $franchiseId, string $team, string $from, string $to): array
    {
        $moves = FinancialAdjustment::query()->where('kind', 'repair_move')->where('franchise_id', $franchiseId)
            ->where('team', $team)->whereBetween('date', [$from, $to])->orderBy('created_at')->get();
        if ($moves->isEmpty() || $people === []) {
            return [$people, [], null];
        }

        $by = collect($people)->keyBy('person')->all();
        foreach ($moves as $m) {
            if (($by[$m->from_technician]['cents'] ?? 0) < $m->cents) {
                return [$people, [], "A recorded move takes more from {$m->from_technician} than their share."];
            }
            $by[$m->from_technician]['cents'] -= $m->cents;
            $by[$m->technician] ??= ['person' => $m->technician, 'jobs' => 0, 'cents' => 0];
            $by[$m->technician]['cents'] += $m->cents;
        }
        ksort($by);

        return [array_values($by), $moves->map(fn ($m) => ['id' => $m->id, 'from' => $m->from_technician, 'to' => $m->technician, 'cents' => $m->cents, 'reason' => $m->reason])->all(), null];
    }

    /** @return Collection<int, FinancialSource> */
    public static function sources(string $role, string $from, string $to, ?string $franchiseId): Collection
    {
        return FinancialSource::query()->with('franchise:id,name', 'company:id,name')
            ->where('role', $role)->whereNotNull('franchise_id')
            ->when($franchiseId, fn ($q) => $q->where('franchise_id', $franchiseId))
            ->get()->filter(fn (FinancialSource $s) => $s->overlaps($from, $to))->values();
    }

    /** The source's live copied rows that are its place's and dated inside both the period and its validity. */
    public static function rows(FinancialSource $source, string $from, string $to): \Generator
    {
        $records = AirtableRecord::query()->where('base_id', $source->base_id)->where('table_id', $source->table_id)
            ->whereNull('gone_at')->get(['fields']);
        foreach ($records as $record) {
            $f = (array) $record->fields;
            $date = substr((string) self::value($source, $f, 'date'), 0, 10);
            if (! $source->owns($f) || $date === '' || $date < $from || $date > $to || ! $source->covers($date)) {
                continue;
            }
            yield $f;
        }
    }

    public static function value(FinancialSource $source, array $fields, string $key): mixed
    {
        foreach (array_filter([$source->field($key), ...(self::DEFAULTS[$key] ?? [])]) as $name) {
            if (array_key_exists($name, $fields)) {
                $v = $fields[$name];

                return is_array($v) ? implode(', ', array_map('strval', $v)) : $v;
            }
        }

        return null;
    }
}
