<?php

namespace App\Support\Reports\Texts;

use App\Models\AirtableRecord;
use Illuminate\Support\Collection;

/**
 * The weekly and monthly report's sections built from the Airtable copy
 * (AirtableSnapshot): refunds, disputes and returned checks; repair teams; HQ
 * office expenses (the A1 Leads takeover, 2026-10-16). Each takes the P&L
 * company code (NJ, CT, ...) and the inclusive Y-m-d dates.
 *
 * HTML fragments in PeriodReport's own table style; money in whole cents so
 * the sums are exact.
 */
final class AirtableSections
{
    /** Repair Teams names Ohio's table in full. */
    private const REPAIR_TABLE = ['OH' => 'OHIO'];

    public function refunds(string $org, string $code, string $from, string $to): string
    {
        $out = '';
        foreach (['tblxCKagahNfmjaSQ' => ['Refunds', 'Refund Amount'], 'tblxkLPCe759GoPzn' => ['Disputes', 'Dispute Amount'], 'tblpTKRRhUrirBRpy' => ['Returned checks', 'Amount of check']] as $table => [$title, $amount]) {
            $rows = $this->rows($org, 'appe1vSSRRvKToShq', $table, $from, $to)
                ->filter(fn ($f) => strtoupper(trim((string) ($f['Company Name'] ?? ''))) === 'A1 CHIMNEY '.$code)
                ->sortBy(fn ($f) => $f['Date'] ?? '')->values();

            $out .= '<h3>'.e($title).'</h3>';
            if ($rows->isEmpty()) {
                $out .= '<p class="na">None in these dates.</p>';

                continue;
            }

            $total = 0;
            $lines = $rows->map(function ($f) use ($amount, &$total) {
                $cents = self::cents($f[$amount] ?? 0);
                $total += $cents;
                $job = self::cents($f['Total Jobs'] ?? 0);

                return [
                    e((string) ($f['Job Number'] ?? '')),
                    e(trim((string) ($f['Reason'] ?? $f['Type'] ?? ''))),
                    self::money($cents),
                    $job > 0 ? ($cents >= $job ? 'Full' : 'Partial') : '—',
                    e((string) ($f['Open/Close Job'] ?? '—')),
                    e((string) ($f['Technician Name'] ?? '')),
                ];
            })->all();
            $lines[] = ['<b>Total</b>', '', '<b>'.self::money($total).'</b>', '', '', ''];

            $out .= PeriodReport::table(['Job #', 'Reason', 'Amount', 'Partial/Full', 'Job', 'Technician'], $lines, raw: true);
        }

        return $out;
    }

    /**
     * One stacked list per repair team: the week's costs from its Weekly
     * Summary row, the shares from its Job rows, and the company's share of
     * the cost (the A1 Leads formulas).
     */
    public function repairTeams(string $org, string $code, string $from, string $to): string
    {
        $tableName = self::REPAIR_TABLE[$code] ?? $code;
        $rows = AirtableRecord::withoutGlobalScopes()->where('organization_id', $org)->where('base_id', 'appzD9I7nNkALmw4l')->where('table_name', $tableName)->whereNull('gone_at')->get()
            ->map(fn ($r) => $r->fields)
            ->filter(fn ($f) => ($f['Date'] ?? '') >= $from && ($f['Date'] ?? '') <= $to);

        if ($rows->isEmpty()) {
            return '<p class="na">No repair team rows in these dates.</p>';
        }

        $out = '';
        foreach ($rows->groupBy(fn ($f) => (string) ($f['Team'] ?? 'No team'))->sortKeys() as $team => $teamRows) {
            $jobs = $teamRows->filter(fn ($f) => ($f['Record type'] ?? 'Job') === 'Job');
            $summary = $teamRows->filter(fn ($f) => ($f['Record type'] ?? '') === 'Weekly Summary');
            $sum = fn (Collection $c, string $field) => $c->sum(fn ($f) => self::cents($f[$field] ?? 0));
            // Ohio files the week's costs on its Job rows.
            $costs = $summary->isNotEmpty() ? $summary : $jobs;

            $paycheck = $sum($costs, 'Employee Cost');
            $helper = $sum($costs, 'Helper Cost');
            $gas = $sum($costs, 'Gas Cost');
            $aveOp = $sum($costs, 'Ave Operation Cost');
            $hours = $costs->sum(fn ($f) => (float) ($f['Total Hours '] ?? $f['Total Hours'] ?? 0));
            $parts = $sum($jobs, 'Parts Cost');
            $amount = $sum($jobs, 'Job Amount');
            $tech = $sum($jobs, 'Total Tech Share');
            $external = $sum($jobs, 'External Company Share');
            $contracting = $sum($jobs, 'Total Contracting Profit');

            $totalCost = $paycheck + $helper + $aveOp + $gas + $parts;
            $company = $totalCost - $tech - $external - $contracting;
            $pct = fn (int $part) => $totalCost > 0 ? self::percent($part / $totalCost) : '—';
            $fromGross = $amount > 0 ? $company / $amount : null;

            $out .= '<h3>'.e($team).'</h3>'.PeriodReport::table(['', ''], [
                ['Total hours', rtrim(rtrim(number_format($hours, 2), '0'), '.')],
                ['Total paycheck', self::money($paycheck)],
                ['Total helper cost', self::money($helper)],
                ['Ave operation cost', self::money($aveOp)],
                ['Gas cost', self::money($gas)],
                ['Parts cost', self::money($parts)],
                ['<b>Total cost</b>', '<b>'.self::money($totalCost).'</b>'],
                ['Total tech share', self::money($tech).' · '.$pct($tech)],
                ['Total external share', self::money($external).' · '.$pct($external)],
                ['Total contracting profit', ReportGoals::mark(self::money($contracting).' · '.$pct($contracting), $contracting < 0)],
                ['Company share', ReportGoals::mark(self::money($company).' · '.$pct($company), $company < 0)],
                ['Total jobs done', (string) $jobs->count()],
                ['Total jobs amount', self::money($amount)],
                ['Cost from gross sales', $fromGross === null ? '—' : ReportGoals::mark(self::percent($fromGross), ReportGoals::for($org, $code)->bad('repair_cost_from_gross', $fromGross))],
            ], raw: true);
        }

        return $out;
    }

    /** HQ only: the office's expenses, rows of one name merged (in cents). */
    public function hqExpenses(string $org, string $from, string $to): string
    {
        $byName = [];
        foreach ($this->rows($org, 'app6ZZgY9nHX8mMih', 'tblmV1sk6qs4QbUWU', $from, $to) as $f) {
            $name = trim((string) ($f['Category Name'] ?? ''));
            // Airtable's own subtotal row is not an expense.
            if ($name === '' || strcasecmp($name, 'Total') === 0) {
                continue;
            }
            $key = mb_strtolower($name);
            $byName[$key] ??= [$name, 0];
            $byName[$key][1] += self::cents($f['Cost'] ?? 0);
        }

        if ($byName === []) {
            return '<p class="na">No office expenses in these dates.</p>';
        }

        ksort($byName);
        $rows = array_map(fn ($r) => [e($r[0]), self::money($r[1])], array_values($byName));
        $rows[] = ['<b>Total</b>', '<b>'.self::money(array_sum(array_column($byName, 1))).'</b>'];

        return PeriodReport::table(['Expense', 'Amount'], $rows, raw: true);
    }

    /** @return Collection<int, array<string, mixed>> fields of live rows dated in the window */
    private function rows(string $org, string $base, string $table, string $from, string $to): Collection
    {
        return AirtableRecord::withoutGlobalScopes()->where('organization_id', $org)->where('base_id', $base)->where('table_id', $table)->whereNull('gone_at')->get()
            ->map(fn ($r) => $r->fields)
            ->filter(fn ($f) => isset($f['Date']) && $f['Date'] >= $from && $f['Date'] <= $to)
            ->values();
    }

    public static function cents(mixed $amount): int
    {
        return is_numeric($amount) ? (int) round((float) $amount * 100) : 0;
    }

    private static function money(int $cents): string
    {
        return ($cents < 0 ? '-$' : '$').number_format(abs($cents) / 100, 2);
    }

    private static function percent(float $share): string
    {
        return number_format($share * 100, 1).'%';
    }
}
