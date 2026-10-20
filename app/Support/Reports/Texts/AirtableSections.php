<?php

namespace App\Support\Reports\Texts;

use App\Support\Reports\Airtable\ReportSources;
use Illuminate\Support\Collection;

/**
 * The weekly and monthly report's sections built from the organization's own
 * mapped Airtable tables (ReportSources over the hourly copy): refunds,
 * disputes and returned checks; repair teams; HQ office expenses. Each takes
 * the organization (reports run outside a tenant), the branch and the
 * inclusive Y-m-d dates. A part nobody mapped says so.
 *
 * HTML fragments in PeriodReport's table style; money in whole cents so sums
 * are exact.
 */
final class AirtableSections
{
    public function refunds(string $org, string $branch, string $from, string $to): string
    {
        $out = '';
        foreach (['refunds' => 'Refunds', 'disputes' => 'Disputes', 'check_returns' => 'Returned checks'] as $role => $title) {
            $out .= '<h3>'.e($title).'</h3>';
            if (! ReportSources::mapped($org, $role, $branch)) {
                $out .= '<p class="na">No table is mapped for this branch (Financials → Settings).</p>';

                continue;
            }

            $rows = ReportSources::rows($org, $role, $branch, $from, $to)->sortBy('date')->values();
            if ($rows->isEmpty()) {
                $out .= '<p class="na">None in these dates.</p>';

                continue;
            }

            $total = 0;
            $lines = $rows->map(function ($r) use (&$total) {
                $cents = self::cents($r['amount']);
                $total += $cents;
                $job = self::cents($r['total']);

                return [
                    e((string) $r['job_number']),
                    e(trim((string) $r['reason'])),
                    self::money($cents),
                    $job > 0 ? ($cents >= $job ? 'Full' : 'Partial') : '—',
                    e((string) ($r['status'] ?? '—')),
                    e((string) $r['technician']),
                ];
            })->all();
            $lines[] = ['<b>Total</b>', '', '<b>'.self::money($total).'</b>', '', '', ''];

            $out .= PeriodReport::table(['Job #', 'Reason', 'Amount', 'Partial/Full', 'Job', 'Technician'], $lines, raw: true);
        }

        return $out;
    }

    /**
     * One stacked list per repair team: the week's costs from its summary
     * row (or its job rows, where a team files them there), the shares from
     * its job rows, and the company's share of the cost.
     */
    public function repairTeams(string $org, string $branch, string $from, string $to, array $goalOverrides = []): string
    {
        if (! ReportSources::mapped($org, 'repair', $branch)) {
            return '<p class="na">No repair team table is mapped for this branch (Financials → Settings).</p>';
        }

        $rows = ReportSources::rows($org, 'repair', $branch, $from, $to);

        if ($rows->isEmpty()) {
            return '<p class="na">No repair team rows in these dates.</p>';
        }

        // The value of the kind column on a team's weekly summary row; with
        // none set every row is a job row and carries the week's costs.
        $summaryType = ReportSources::marker($org, 'repair', $branch, 'summary');
        $goals = ReportGoals::for($org, $branch, $goalOverrides);
        $out = '';

        foreach ($rows->groupBy(fn ($r) => (string) ($r['team'] ?? 'No team'))->sortKeys() as $team => $teamRows) {
            $summary = $summaryType === '' ? collect() : $teamRows->filter(fn ($r) => $r['kind'] === $summaryType);
            $jobs = $summaryType === '' ? $teamRows : $teamRows->reject(fn ($r) => $r['kind'] === $summaryType);
            $sum = fn (Collection $c, string $field) => $c->sum(fn ($r) => self::cents($r[$field]));
            $costs = $summary->isNotEmpty() ? $summary : $jobs;

            $paycheck = $sum($costs, 'employee');
            $helper = $sum($costs, 'helper');
            $gas = $sum($costs, 'gas');
            $operation = $sum($costs, 'operation');
            $hours = $costs->sum(fn ($r) => (float) $r['hours']);
            $parts = $sum($jobs, 'parts');
            $amount = $sum($jobs, 'amount');
            $tech = $sum($jobs, 'tech_share');
            $external = $sum($jobs, 'external_share');
            $contracting = $sum($jobs, 'contracting');

            $totalCost = $paycheck + $helper + $operation + $gas + $parts;
            $company = $totalCost - $tech - $external - $contracting;
            $pct = fn (int $part) => $totalCost > 0 ? self::percent($part / $totalCost) : '—';
            $fromGross = $amount > 0 ? $company / $amount : null;

            $out .= '<h3>'.e($team).'</h3>'.PeriodReport::table(['', ''], [
                ['Total hours', rtrim(rtrim(number_format($hours, 2), '0'), '.')],
                ['Total paycheck', self::money($paycheck)],
                ['Total helper cost', self::money($helper)],
                ['Ave operation cost', self::money($operation)],
                ['Gas cost', self::money($gas)],
                ['Parts cost', self::money($parts)],
                ['<b>Total cost</b>', '<b>'.self::money($totalCost).'</b>'],
                ['Total tech share', self::money($tech).' · '.$pct($tech)],
                ['Total external share', self::money($external).' · '.$pct($external)],
                ['Total contracting profit', ReportGoals::mark(self::money($contracting).' · '.$pct($contracting), $contracting < 0)],
                ['Company share', ReportGoals::mark(self::money($company).' · '.$pct($company), $company < 0)],
                ['Total jobs done', (string) $jobs->count()],
                ['Total jobs amount', self::money($amount)],
                ['Cost from gross sales', $fromGross === null ? '—' : ReportGoals::mark(self::percent($fromGross), $goals->bad('repair_cost_from_gross', $fromGross))],
            ], raw: true);
        }

        return $out;
    }

    /** The organization-wide office expenses, rows of one name merged (in cents). */
    public function hqExpenses(string $org, string $from, string $to): string
    {
        if (! ReportSources::mapped($org, 'hq_expenses', null)) {
            return '<p class="na">No office expenses table is mapped (Financials → Settings).</p>';
        }

        $skip = ReportSources::marker($org, 'hq_expenses', null, 'skip');
        $byName = [];

        foreach (ReportSources::rows($org, 'hq_expenses', null, $from, $to) as $r) {
            $name = trim((string) $r['category']);
            // A subtotal row the sheet keeps is not an expense.
            if ($name === '' || ($skip !== '' && strcasecmp($name, $skip) === 0)) {
                continue;
            }
            $key = mb_strtolower($name);
            $byName[$key] ??= [$name, 0];
            $byName[$key][1] += self::cents($r['amount']);
        }

        if ($byName === []) {
            return '<p class="na">No office expenses in these dates.</p>';
        }

        ksort($byName);
        $rows = array_map(fn ($r) => [e($r[0]), self::money($r[1])], array_values($byName));
        $rows[] = ['<b>Total</b>', '<b>'.self::money(array_sum(array_column($byName, 1))).'</b>'];

        return PeriodReport::table(['Expense', 'Amount'], $rows, raw: true);
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
