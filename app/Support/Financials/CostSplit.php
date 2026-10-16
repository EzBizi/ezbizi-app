<?php

namespace App\Support\Financials;

use App\Models\FinancialTable;
use Illuminate\Validation\ValidationException;

/**
 * Splits each group's cost across the people who did its jobs, by job count,
 * to the exact cent (largest remainder; ties go alphabetically). The rule A-1's
 * repair-team report uses, generalized: which column names the group, the
 * person, the kind of row, the job key and the cost columns is the view's
 * configuration, not code.
 *
 * Per group, the result is `unavailable` rather than wrong when a job has no
 * key, a key repeats, or a cost is negative or not a number.
 */
final class CostSplit
{
    /**
     * @param  array{group_field:string, person_field:string, cost_fields:list<string>,
     *               kind_field?:?string, job_kind?:?string, cost_kind?:?string,
     *               job_key_field?:?string, date_field?:?string}  $config
     * @return list<array{group:string, status:string, reason:?string, jobs:int,
     *                    total_cents:int, people:list<array{person:string, jobs:int, cents:int}>}>
     */
    public static function run(FinancialTable $table, array $config, ?string $from = null, ?string $to = null): array
    {
        foreach (['group_field', 'person_field'] as $key) {
            if (empty($config[$key])) {
                throw ValidationException::withMessages(['config' => 'Choose the group and person columns in View settings.']);
            }
        }
        if (empty($config['cost_fields'])) {
            throw ValidationException::withMessages(['config' => 'Choose at least one cost column in View settings.']);
        }

        $kind = $config['kind_field'] ?? null;
        $costKind = $config['cost_kind'] ?? null;
        // Without a kind column every row is a job carrying its own costs.
        $isJob = fn (array $v) => $kind === null || ($v[$kind] ?? null) === ($config['job_kind'] ?? 'Job');
        $isCost = fn (array $v) => $kind === null || $costKind === null ? $isJob($v) : ($v[$kind] ?? null) === $costKind;
        $dateField = $config['date_field'] ?? null;
        $inPeriod = function (array $v) use ($dateField, $from, $to): bool {
            if ($dateField === null || ($from === null && $to === null)) {
                return true;
            }
            $date = $v[$dateField] ?? null;

            return $date !== null && ($from === null || $date >= $from) && ($to === null || $date <= $to);
        };

        $groups = [];
        foreach ($table->records()->get(['values']) as $record) {
            $v = (array) $record->values;
            if (! $inPeriod($v)) {
                continue;
            }
            $group = trim((string) ($v[$config['group_field']] ?? ''));
            if ($group === '') {
                continue;
            }
            $g = &$groups[$group];
            $g ??= ['jobs' => [], 'cost' => 0, 'keys' => [], 'reason' => null];

            if ($isCost($v)) {
                foreach ($config['cost_fields'] as $field) {
                    $raw = $v[$field] ?? null;
                    if ($raw === null || $raw === '') {
                        continue;
                    }
                    if (! is_numeric($raw) || $raw < 0) {
                        $g['reason'] ??= 'A cost is negative or not a number.';

                        continue;
                    }
                    $g['cost'] += (int) round($raw * 100);
                }
            }

            if ($isJob($v)) {
                if ($key = $config['job_key_field'] ?? null) {
                    $id = trim((string) ($v[$key] ?? ''));
                    if ($id === '') {
                        $g['reason'] ??= 'A job has no job number.';
                    } elseif (isset($g['keys'][$id])) {
                        $g['reason'] ??= "Job {$id} appears twice.";
                    }
                    $g['keys'][$id] = true;
                }
                $person = trim((string) ($v[$config['person_field']] ?? '')) ?: '(unassigned)';
                $g['jobs'][$person] = ($g['jobs'][$person] ?? 0) + 1;
            }
            unset($g);
        }

        ksort($groups);
        $out = [];
        foreach ($groups as $name => $g) {
            $jobs = array_sum($g['jobs']);
            $status = $g['reason'] !== null ? 'unavailable' : ($jobs === 0 ? 'no_jobs' : 'calculated');
            $out[] = [
                'group' => (string) $name,
                'status' => $status,
                'reason' => $g['reason'],
                'jobs' => $jobs,
                'total_cents' => $status === 'unavailable' ? 0 : $g['cost'],
                'people' => $status === 'calculated' ? self::allocate($g['cost'], $g['jobs']) : [],
            ];
        }

        return $out;
    }

    /**
     * @param  array<string, int>  $jobs  person => job count
     * @return list<array{person:string, jobs:int, cents:int}>
     */
    public static function allocate(int $totalCents, array $jobs): array
    {
        $count = array_sum($jobs);
        $rows = [];
        foreach ($jobs as $person => $n) {
            $rows[] = ['person' => (string) $person, 'jobs' => $n, 'cents' => intdiv($totalCents * $n, $count), 'rem' => ($totalCents * $n) % $count];
        }

        $left = $totalCents - array_sum(array_column($rows, 'cents'));
        $order = array_keys($rows);
        usort($order, fn ($a, $b) => [$rows[$b]['rem'], $rows[$a]['person']] <=> [$rows[$a]['rem'], $rows[$b]['person']]);
        for ($i = 0; $i < $left; $i++) {
            $rows[$order[$i]]['cents']++;
        }

        usort($rows, fn ($a, $b) => strcmp($a['person'], $b['person']));

        return array_map(fn ($r) => ['person' => $r['person'], 'jobs' => $r['jobs'], 'cents' => $r['cents']], $rows);
    }
}
