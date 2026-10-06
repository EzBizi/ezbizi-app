<?php

namespace App\Support\Hr;

use App\Support\Hr\OnboardingCatalog\FieldLibrary;

/**
 * The same pay terms on every contract, each one on its own line so it can be
 * filled, marked "To be determined" or turned off (Eve via Jose, 2026-10-06:
 * "Same options for all contracts in commission type salary etc, so she can
 * enable or disable them ... without having to accustom herself to each type
 * of contract").
 *
 * A contract keeps its own wording everywhere else. In its COMPENSATION
 * section the pay subsections (Base Pay, Compensation Method, Overtime,
 * Scheduled Pay Increases) give way to one standard "(a) Pay" subsection, and
 * the role's own subsections (bonuses, payment schedule, holidays) follow,
 * lettered again. The hourly/salary lines in the employment details become
 * one line each. Pure functions, so a dry run and the tests see exactly what
 * the write would store.
 */
final class StandardPayTerms
{
    /** The pay questions every contract carries, in form order. */
    public const KEYS = [
        'comp_method', 'hourly_rate', 'salary_amount', 'salary_period', 'daily_deduction',
        'commission_pct', 'commission_stages', 'raise_amount', 'raise_period',
    ];

    /** Retired: the choice between hourly and salary is now which lines are on. */
    public const RETIRED = ['pay_basis'];

    private const PAY_BLOCK = <<<'TXT'
(a) Pay

The Employee shall be compensated on the following basis: {{comp_method}}.

Base Hourly Rate: ${{hourly_rate}} per hour.

Salary: ${{salary_amount}} per {{salary_period}}.

Absence Deduction: If the Employee is absent from work on any scheduled workday, ${{daily_deduction}} per day will be deducted from the Employee's salary for that pay period.

Commission Rate: {{commission_pct}}% on total completed sales.

Tiered Commission — the Employee's commission is based on total weekly completed sales, paid at the single rate for the highest sales bracket reached that week (no commission applies below the first threshold): {{commission_stages}}.

{{#commission_pct}}Commission is calculated and paid only on jobs that have been fully completed. The weekly sales report is based exclusively on done jobs. If a deposit is collected for a job that is not completed within the same week of payment, that deposit will not be included in the weekly sales report until the job is fully completed. Once the job is done, the full sale amount (including any prior deposit) will be reflected in the sales report for the week in which the job is completed.{{/commission_pct}}

{{#hourly_rate}}Overtime: In accordance with the Fair Labor Standards Act (FLSA) and applicable state law, any hours worked in excess of forty (40) hours per workweek shall be compensated at one and one-half (1.5x) times the Employee's regular hourly rate. Overtime must be pre-approved by a supervisor unless required by operational necessity.{{/hourly_rate}}

Scheduled Pay Increases: The Employee's base pay shall increase by ${{raise_amount}} every {{raise_period}} during the first twelve (12) months of employment, provided the Employee remains in good standing and meets performance expectations. Increases are cumulative and take effect on the first full pay period following each interval. Any increase beyond the first twelve (12) months shall be at the sole discretion of the Employer based on performance reviews.

All compensation is subject to applicable federal and state tax withholdings and deductions.
TXT;

    private const HEADER_LINES = "Hourly Rate: \${{hourly_rate}} per hour\n\nSalary: \${{salary_amount}} per {{salary_period}}";

    /** Subsections the standard block replaces. */
    private const REPLACED = '/^(pay|base pay|compensation method|overtime|scheduled pay increases?)$/i';

    /** The new body, or null when the contract has no COMPENSATION section to standardise. */
    public static function body(string $body): ?string
    {
        $body = str_replace("\r\n", "\n", $body);
        if (! preg_match('/^\d+\.\s+COMPENSATION[^\n]*$/m', $body, $head, PREG_OFFSET_CAPTURE)) {
            return null;
        }
        $start = $head[0][1] + strlen($head[0][0]);
        $rest = substr($body, $start);
        $end = preg_match('/^\d+\.\s+[A-Z][A-Z &\/,\'-]+\s*$/m', $rest, $next, PREG_OFFSET_CAPTURE)
            ? $start + $next[0][1] : strlen($body);

        $section = self::unwrap(substr($body, $start, $end - $start));
        $paragraphs = array_values(array_filter(array_map('trim', preg_split('/\n[ \t]*\n/', $section) ?: []), fn ($p) => $p !== ''));

        $intro = [];
        $subsections = [];
        foreach ($paragraphs as $p) {
            if (preg_match('/^\([a-z]\)\s+(.+)$/', $p, $m) && ! str_contains($p, "\n")) {
                $subsections[] = ['title' => trim($m[1]), 'paragraphs' => []];

                continue;
            }
            if (self::isPayParagraph($p)) {
                continue;
            }
            if ($subsections === []) {
                $intro[] = $p;
            } else {
                $subsections[count($subsections) - 1]['paragraphs'][] = $p;
            }
        }
        $kept = array_values(array_filter($subsections, fn ($s) => ! preg_match(self::REPLACED, $s['title'])));

        // The heredoc is in this file's line endings, which a Windows checkout
        // makes CRLF; bodies are compared with LF, so a second run would never
        // match (MCP / API, 2026-10-07).
        $out = [...$intro, str_replace("\r\n", "\n", self::PAY_BLOCK)];
        foreach ($kept as $i => $s) {
            $out[] = '('.chr(ord('b') + $i).') '.$s['title'];
            array_push($out, ...$s['paragraphs']);
        }

        $before = rtrim(substr($body, 0, $start))."\n\n";
        $after = substr($body, $end);
        $body = $before.implode("\n\n", $out)."\n\n".ltrim($after);

        return self::header(self::rewrapElsewhere($body));
    }

    /**
     * The pay questions in form order: a contract's own definition where it
     * has one (its label stays), the library's where not; each required so it
     * is answered, marked "To be determined" or turned off, and shown
     * whatever else is chosen. The pay-basis choice goes.
     *
     * @param  list<array<string, mixed>>  $fields
     * @return list<array<string, mixed>>
     */
    public static function fields(array $fields): array
    {
        $own = [];
        $at = null;
        $others = [];
        foreach ($fields as $field) {
            $key = (string) ($field['key'] ?? '');
            if (in_array($key, self::KEYS, true) || in_array($key, self::RETIRED, true)) {
                $own[$key] = $field;
                $at ??= count($others);

                continue;
            }
            $others[] = $field;
        }

        $pay = [];
        foreach (self::KEYS as $key) {
            $field = $own[$key] ?? FieldLibrary::definition($key);
            unset($field['shown_when']);
            $field['required'] = true;
            $pay[] = $field;
        }

        // Before the signature lines when the contract asked nothing about pay.
        if ($at === null) {
            $at = count($others);
            foreach ($others as $i => $field) {
                if (($field['type'] ?? '') === 'signature') {
                    $at = $i;

                    break;
                }
            }
        }

        return [...array_slice($others, 0, $at), ...$pay, ...array_slice($others, $at)];
    }

    /** Take the hourly/salary/raise wrappers off; what they held is decided line by line now. */
    private static function unwrap(string $text): string
    {
        return preg_replace('/\{\{[#\/](pay_basis\.(hourly|salary)|raise_amount)\}\}/', '', $text) ?? $text;
    }

    /** A paragraph the standard block carries in its own words. */
    private static function isPayParagraph(string $p): bool
    {
        foreach (self::KEYS as $key) {
            if (preg_match('/\{\{[#\/]?\s*'.$key.'\s*\}\}/', $p)) {
                return true;
            }
        }

        return str_starts_with($p, 'Commission Calculation')
            || str_starts_with($p, 'Commission is calculated')
            || str_starts_with($p, 'In accordance with the Fair Labor Standards Act')
            || $p === 'All compensation is subject to applicable federal and state tax withholdings and deductions.';
    }

    /** Wrappers outside the section follow the line they belong to (same-day overtime is hourly). */
    private static function rewrapElsewhere(string $body): string
    {
        return strtr($body, [
            '{{#pay_basis.hourly}}' => '{{#hourly_rate}}', '{{/pay_basis.hourly}}' => '{{/hourly_rate}}',
            '{{#pay_basis.salary}}' => '{{#salary_amount}}', '{{/pay_basis.salary}}' => '{{/salary_amount}}',
        ]);
    }

    /** One hourly line and one salary line in the employment details, where it states pay. */
    private static function header(string $body): string
    {
        $line = '/^(?:\{\{#(?:hourly_rate|salary_amount)\}\})?(?:Hourly Rate: \$\{\{hourly_rate\}\} per hour|Salary: \$\{\{salary_amount\}\} per \{\{salary_period\}\})(?:\{\{\/(?:hourly_rate|salary_amount)\}\})?$\n*/m';
        if (! preg_match($line, $body, $m, PREG_OFFSET_CAPTURE)) {
            return $body;
        }
        $first = $m[0][1];
        $body = preg_replace($line, '', $body) ?? $body;

        return rtrim(substr($body, 0, $first), "\n")."\n\n".self::HEADER_LINES."\n\n".ltrim(substr($body, $first), "\n");
    }
}
