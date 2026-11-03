<?php

namespace App\Support\Financials;

use App\Models\AirtableRecord;
use App\Models\FinancialParityCheck;
use App\Models\FinancialSource;
use App\Models\FinancialTable;
use Carbon\CarbonImmutable;

/**
 * Airtable copy vs EzBizi's own Financials rows, one Airtable table at a time,
 * through the same field maps the reports use (plan 2026-11-03 section 4):
 * row counts per source, monthly totals per numeric line in cents (within
 * one cent), and, for refunds/disputes/returned checks, the refunds report
 * rendered from each side. Must run inside the tenant's context.
 */
final class AirtableParity
{
    /** Columns a field map names that are not money or counts. */
    private const NOT_NUMBERS = ['date', 'company', 'technician', 'team', 'job_number', 'category', 'kind', 'status', 'reason', 'approval'];

    /** Report roles whose output must be identical from either store. */
    private const REFUND_KINDS = ['refunds' => 'refund', 'disputes' => 'dispute', 'check_returns' => 'check_return'];

    /** Cents two monthly totals may differ by (float to cents rounding of Airtable decimals). */
    public const TOLERANCE_CENTS = 1;

    /** @return list<FinancialParityCheck> one per table of the base, stored for $day */
    public static function runBase(string $baseId, ?string $day = null): array
    {
        $day ??= now()->toDateString();
        $tables = FinancialSource::query()->where('base_id', $baseId)->get()->groupBy('table_id');

        $out = [];
        foreach ($tables as $tableId => $sources) {
            $result = self::compareTable($baseId, (string) $tableId, $sources->all());
            $out[] = FinancialParityCheck::query()->updateOrCreate(
                ['source_base_id' => $baseId, 'source_table_id' => (string) $tableId, 'day' => $day],
                $result,
            );
        }

        return $out;
    }

    /**
     * @param  list<FinancialSource>  $sources  every source reading this one Airtable table
     * @return array{table_name: ?string, green: bool, cut_over: bool, airtable_rows: int, ezbizi_rows: int, diffs: array}
     */
    public static function compareTable(string $baseId, string $tableId, array $sources): array
    {
        $airtable = FinancialSource::airtableRows($baseId, $tableId);
        $table = FinancialTable::query()->where('source_base_id', $baseId)->where('source_table_id', $tableId)->orderBy('created_at')->first();
        $name = $table?->name ?? AirtableRecord::query()->where('base_id', $baseId)->where('table_id', $tableId)->value('table_name');

        if ($table === null) {
            return ['table_name' => $name, 'green' => false, 'cut_over' => false, 'airtable_rows' => count($airtable),
                'ezbizi_rows' => 0, 'diffs' => ['missing_table' => 'No EzBizi table holds this Airtable table yet: run the backfill.']];
        }

        $ezbizi = FinancialSource::ezbiziRows($table);
        $diffs = [];
        foreach ($sources as $source) {
            $a = array_values(array_filter($airtable, fn ($r) => $source->owns($r['fields'])));
            $e = array_values(array_filter($ezbizi, fn ($r) => $source->owns($r['fields'])));
            $key = $source->role.($source->franchise_id ? ':'.$source->franchise_id : ($source->company_id ? ':'.$source->company_id : ''));

            $diff = self::counts($a, $e) + self::months($source, $a, $e) + self::report($source, $a, $e);
            if ($diff !== []) {
                $diffs[$key] = $diff;
            }
        }

        return ['table_name' => $name, 'green' => $diffs === [], 'cut_over' => $table->isCutOver(),
            'airtable_rows' => count($airtable), 'ezbizi_rows' => count($ezbizi), 'diffs' => $diffs];
    }

    private static function counts(array $a, array $e): array
    {
        $aIds = array_column($a, 'id');
        $eIds = array_filter(array_column($e, 'id'), fn ($id) => $id !== null);
        $missing = array_values(array_diff($aIds, $eIds));
        $extra = array_values(array_diff($eIds, $aIds));
        $ezbiziOnly = count($e) - count($eIds);

        if (count($a) === count($e) && $missing === [] && $extra === []) {
            return [];
        }

        return ['rows' => ['airtable' => count($a), 'ezbizi' => count($e), 'missing' => array_slice($missing, 0, 50),
            'extra' => array_slice($extra, 0, 50), 'entered_in_ezbizi' => $ezbiziOnly]];
    }

    /** Per month (of the mapped date column) per numeric line, the sum in cents. */
    private static function months(FinancialSource $source, array $a, array $e): array
    {
        $keys = array_keys(array_filter((array) $source->field_map, fn ($column, $key) => ! in_array($key, self::NOT_NUMBERS, true) && $column, ARRAY_FILTER_USE_BOTH));
        $lines = array_flip(array_unique([...$keys, 'amount']));
        $sum = function (array $rows) use ($lines, $source): array {
            $out = [];
            foreach ($rows as $r) {
                $date = (string) SourceFigures::value($source, $r['fields'], 'date');
                $month = preg_match('/^\d{4}-\d{2}/', $date, $m) ? $m[0] : 'undated';
                foreach (array_keys($lines) as $key) {
                    $v = SourceFigures::value($source, $r['fields'], $key);
                    if (is_numeric($v)) {
                        $out[$month][$key] = ($out[$month][$key] ?? 0.0) + (float) $v * 100;
                    }
                }
            }

            return $out;
        };
        [$sa, $se] = [$sum($a), $sum($e)];

        $bad = [];
        foreach (array_unique([...array_keys($sa), ...array_keys($se)]) as $month) {
            foreach (array_keys($lines) as $key) {
                $x = $sa[$month][$key] ?? null;
                $y = $se[$month][$key] ?? null;
                if ($x === null && $y === null) {
                    continue;
                }
                if ($x === null || $y === null || abs(round($x) - round($y)) > self::TOLERANCE_CENTS) {
                    $bad[] = ['month' => $month, 'line' => $key, 'airtable_cents' => $x === null ? null : (int) round($x), 'ezbizi_cents' => $y === null ? null : (int) round($y)];
                }
            }
        }
        sort($bad);

        return $bad === [] ? [] : ['months' => $bad];
    }

    /** The refunds report for every month either side has, from each store; must be identical. */
    private static function report(FinancialSource $source, array $a, array $e): array
    {
        $kind = self::REFUND_KINDS[$source->role] ?? null;
        if ($kind === null || $source->franchise_id === null) {
            return [];
        }
        $months = collect([...$a, ...$e])->map(fn ($r) => substr((string) SourceFigures::value($source, $r['fields'], 'date'), 0, 7))
            ->filter(fn ($m) => preg_match('/^\d{4}-\d{2}$/', $m))->unique()->sort()->values();

        $bad = [];
        foreach ($months as $month) {
            $from = CarbonImmutable::parse($month.'-01');
            $render = function (string $store) use ($from, $source, $kind) {
                FinancialSource::$readFrom = $store;
                try {
                    $buckets = SourceFigures::refunds($from->toDateString(), $from->endOfMonth()->toDateString(), $source->franchise_id);
                } finally {
                    FinancialSource::$readFrom = null;
                }
                $buckets = array_values(array_filter($buckets, fn ($b) => $b['kind'] === $kind));
                foreach ($buckets as &$b) {
                    usort($b['items'], fn ($x, $y) => strcmp(json_encode($x), json_encode($y)));
                }

                return json_encode($buckets);
            };
            if ($render('airtable') !== $render('ezbizi')) {
                $bad[] = $month;
            }
        }

        return $bad === [] ? [] : ['report' => ['refunds_differ_in' => $bad]];
    }

    /**
     * Green days in a row for a base, counting back from $asOf (today if it
     * has a run, else yesterday). A day is green when it has checks and all
     * are green; a day without a run ends the streak.
     */
    public static function streak(string $baseId, ?string $asOf = null): int
    {
        $days = FinancialParityCheck::query()->where('source_base_id', $baseId)
            ->where('day', '<=', $asOf ?? now()->toDateString())
            ->get(['day', 'green'])->groupBy(fn ($c) => $c->day->toDateString())
            ->map(fn ($checks) => $checks->every(fn ($c) => $c->green));

        $day = CarbonImmutable::parse($asOf ?? now()->toDateString());
        if (! $days->has($day->toDateString())) {
            $day = $day->subDay();
        }
        $n = 0;
        while ($days->get($day->toDateString()) === true) {
            $n++;
            $day = $day->subDay();
        }

        return $n;
    }

    public static function ready(string $baseId): bool
    {
        return self::streak($baseId) >= (int) config('airtable.parity_days', 14);
    }
}
