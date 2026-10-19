<?php

namespace App\Support\Financials;

use App\Models\AirtableRecord;
use App\Models\FinancialSource;
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
    ];

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
                'groups' => array_map(function (string $team, array $g) {
                    $jobs = array_sum($g['jobs']);
                    $status = $g['reason'] !== null ? 'unavailable' : ($jobs === 0 ? 'no_jobs' : 'calculated');

                    return [
                        'team' => $team,
                        'status' => $status,
                        'reason' => $g['reason'],
                        'jobs' => $jobs,
                        'total_cents' => $status === 'unavailable' ? 0 : $g['cost'],
                        'people' => $status === 'calculated' ? CostSplit::allocate($g['cost'], $g['jobs']) : [],
                    ];
                }, array_keys($groups), $groups),
            ];
        }

        return $out;
    }

    /** @return list<array{place:?string, franchise_id:?string, kind:string, total_cents:int, technicians:list<array>, items:list<array>}> */
    public static function refunds(string $from, string $to, ?string $franchiseId = null): array
    {
        $out = [];
        foreach (['refunds' => 'refund', 'disputes' => 'dispute', 'check_returns' => 'check_return'] as $role => $kind) {
            foreach (self::sources($role, $from, $to, $franchiseId) as $source) {
                $items = [];
                foreach (self::rows($source, $from, $to) as $f) {
                    $amount = self::value($source, $f, 'amount');
                    if (! is_numeric($amount)) {
                        continue;
                    }
                    $items[] = [
                        'date' => (string) self::value($source, $f, 'date'),
                        'technician' => trim((string) self::value($source, $f, 'technician')) ?: 'Unknown',
                        'job_number' => self::value($source, $f, 'job_number'),
                        'reason' => self::value($source, $f, 'reason'),
                        'status' => self::value($source, $f, 'status'),
                        'cents' => (int) round($amount * 100),
                    ];
                }
                if ($items === []) {
                    continue;
                }
                $byTech = collect($items)->groupBy('technician')
                    ->map(fn (Collection $rows, string $tech) => ['technician' => $tech, 'count' => $rows->count(), 'cents' => $rows->sum('cents')])
                    ->sortBy('technician')->values()->all();
                $out[] = [
                    'place' => $source->franchise?->name ?? $source->company?->name,
                    'franchise_id' => $source->franchise_id,
                    'kind' => $kind,
                    'total_cents' => array_sum(array_column($items, 'cents')),
                    'technicians' => $byTech,
                    'items' => $items,
                ];
            }
        }

        return $out;
    }

    /** @return Collection<int, FinancialSource> */
    private static function sources(string $role, string $from, string $to, ?string $franchiseId): Collection
    {
        return FinancialSource::query()->with('franchise:id,name', 'company:id,name')
            ->where('role', $role)->whereNotNull('franchise_id')
            ->when($franchiseId, fn ($q) => $q->where('franchise_id', $franchiseId))
            ->get()->filter(fn (FinancialSource $s) => $s->overlaps($from, $to))->values();
    }

    /** The source's live copied rows that are its place's and dated inside both the period and its validity. */
    private static function rows(FinancialSource $source, string $from, string $to): \Generator
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

    private static function value(FinancialSource $source, array $fields, string $key): mixed
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
