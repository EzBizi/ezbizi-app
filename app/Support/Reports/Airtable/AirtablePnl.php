<?php

namespace App\Support\Reports\Airtable;

/**
 * A week of profit and loss, out of an Airtable base shaped like a spreadsheet.
 *
 * ── THE ROWS ARE NOT RECORDS, THEY ARE GRID CELLS ───────────────
 *
 * This is the thing to understand before reading anything below, and it is the
 * thing that makes a naive read produce a confident wrong number.
 *
 * Airtable's API returns one object per ROW. In this base a row is not a fact —
 * it is a horizontal slice across several INDEPENDENT column pairs, each pair
 * being one category's own list read downwards:
 *
 *     row  Income          Income Amount   Bank Expenses   Bank Expenses Amount
 *     [0]  Total Balance       -2291.17    parts                        244
 *     [1]  Total               52055.00    parts                        280
 *     [2]  ZELLE                 248.00    Iwallet                       40
 *     …
 *     [6]  (blank)                  —      Total                   16776.11
 *
 * So `Income`'s total sits on row 1 and `Bank Expenses`'s on row 6, and nothing
 * about row 1 relates it to row 1 of any other column. Summing a column down
 * every row double-counts its own total; reading "the totals row" finds no such
 * row.
 *
 * Measured on A-1's NJ table, week 7-13 Sep 2026: eight rows, nine categories,
 * nine different total positions.
 *
 * ── WHAT THE LABEL COLUMN SAYS ──────────────────────────────────
 *
 * Each pair is `<Category>` (a label) and `<Category> Amount` (money). The
 * label is either a description of a detail line — "parts", "chase", "ZELLE",
 * a person's name — or one of two markers:
 *
 *   Total          this category's own sum
 *   Total Balance  INCOME ONLY: income minus every expense, i.e. net profit
 *
 * Verified arithmetically on that week rather than assumed:
 * 52055 − (16776.11 + 7068.45 + 11258.83 + 450 + 8078.72 + 7714.06 + 3000)
 * = −2291.17, which is exactly the `Total Balance` cell.
 *
 * ── DETAIL LINES ARE THE AUTHORITY, NOT THE TOTAL CELL ──────────
 *
 * Rodrigo's pipeline established this and it is worth keeping: the detail rows
 * summed are what gets displayed, and Airtable's own Total cell is read only to
 * CHECK them. A person types a total; a person can type it wrong, or edit a
 * detail line and not revisit it. A mismatch is recorded and reported, never
 * silently preferred in either direction.
 *
 * ── AN INCOMPLETE WEEK CARRIES NO NUMBER ────────────────────────
 *
 * Also his, and the reason this class returns `complete` rather than zeros: a
 * category with no rows at all is "nobody has filled this in yet", which is a
 * different fact from "nothing was spent". Coercing it to 0 produces a profit
 * figure that is too high and looks perfectly ordinary — the failure this whole
 * module exists to avoid, in the direction nobody notices.
 */
final class AirtablePnl
{
    public const TOTAL = 'Total';

    public const NET = 'Total Balance';

    /**
     * Every category this base keeps, in the order a report should show them.
     *
     * Read from the DATA rather than declared, because a business adds a
     * category the day it starts paying for something new — and a hardcoded
     * list would silently drop it from the expense side while still counting it
     * in the balance, which is a report that does not add up.
     *
     * @param  list<array<string, mixed>>  $rows
     * @return list<string>
     */
    public function categories(array $rows): array
    {
        $names = [];

        foreach ($rows as $row) {
            foreach (array_keys($row) as $field) {
                if (! str_ends_with($field, ' Amount')) {
                    continue;
                }

                $name = substr($field, 0, -strlen(' Amount'));

                if ($name !== '' && ! in_array($name, $names, true)) {
                    $names[] = $name;
                }
            }
        }

        sort($names);

        return $names;
    }

    /**
     * Every week this table holds, in the order the rows arrive.

     * Not sorted. The labels are free text a person typed — "7 Sep - 13 Sep",
     * with the year nowhere in them — so there is nothing here to sort BY, and
     * imposing alphabetical order would put April first and look deliberate.
     * The sheet's own row order is the only ordering that exists.
     *
     * @param  list<array<string, mixed>>  $rows
     * @return list<string>
     */
    public function weeks(array $rows): array
    {
        $weeks = [];

        foreach ($rows as $row) {
            $week = trim((string) ($row['Week'] ?? ''));

            if ($week !== '' && ! in_array($week, $weeks, true)) {
                $weeks[] = $week;
            }
        }

        // Oldest first by start day: Airtable hands rows in its own order, and the
        // screen opens on the last complete week (2026-11-02: it opened on 6 Jul).
        usort($weeks, fn ($x, $y) => self::startOf($x) <=> self::startOf($y));

        return $weeks;
    }

    /** "28 Sep - 4 Oct" as a sortable day; a start after next week is last year's. */
    private static function startOf(string $week): string
    {
        $start = strtotime(trim(explode('-', $week)[0]));
        if ($start === false) {
            return '';
        }
        if ($start > strtotime('+7 days')) {
            $start = strtotime('-1 year', $start);
        }

        return date('Y-m-d', $start);
    }

    /**
     * One week, read as money rather than as rows.
     *
     * @param  list<array<string, mixed>>  $rows  every row of the table
     * @return array{
     *     week: string,
     *     complete: bool,
     *     income: ?float,
     *     costs: ?float,
     *     net: ?float,
     *     stated_net: ?float,
     *     categories: array<string, array{total: ?float, stated: ?float, lines: int}>,
     *     disagreements: list<string>
     * }
     */
    public function week(array $rows, string $week): array
    {
        $inWeek = array_values(array_filter(
            $rows,
            static fn (array $row): bool => trim((string) ($row['Week'] ?? '')) === $week,
        ));

        $categories = [];
        $disagreements = [];

        foreach ($this->categories($inWeek) as $name) {
            $summed = null;
            $stated = null;
            $lines = 0;

            foreach ($inWeek as $row) {
                $label = trim((string) ($row[$name] ?? ''));
                $amount = $row[$name.' Amount'] ?? null;

                if ($label === '' || ! is_numeric($amount)) {
                    continue;
                }

                if (self::marker($label) === self::TOTAL) {
                    $stated = (float) $amount;

                    continue;
                }

                // Income's net-profit marker is not one of income's lines. It
                // is the whole week's answer sitting in income's column, and
                // adding it to income would count the expenses twice, with the
                // sign making the result look merely disappointing.
                if (self::marker($label) === self::NET) {
                    continue;
                }

                /*
                 * Summed in CENTS. Money in floats is the classic way to get a
                 * total that disagrees with its own parts by a penny and then
                 * to report the penny as a data-entry problem.
                 *
                 * Cast back to float DELIBERATELY: in PHP an int divided by an
                 * int is an int when it divides evenly, so a round-dollar week
                 * returned 52055 and a week with cents returned 54346.17 — the
                 * same field, two types, according to the data. Callers that
                 * compare strictly or check is_float() would behave differently
                 * on Tuesday.
                 */
                $summed = (float) ((int) round(($summed ?? 0) * 100 + (float) $amount * 100) / 100);
                $lines++;
            }

            $categories[$name] = ['total' => $summed, 'stated' => $stated, 'lines' => $lines];

            // Reported, never resolved. Which of the two is right is a question
            // about a spreadsheet somebody maintains, and guessing an answer
            // here would hide the thing they need to look at.
            if ($summed !== null && $stated !== null && abs($summed - $stated) >= 0.01) {
                $disagreements[] = sprintf(
                    '%s: the lines add to %s and the sheet says %s.',
                    $name,
                    number_format($summed, 2),
                    number_format($stated, 2),
                );
            }
        }

        $income = $categories['Income']['total'] ?? null;

        $costs = null;

        foreach ($categories as $name => $category) {
            if ($name === 'Income' || $category['total'] === null) {
                continue;
            }

            $costs = (float) ((int) round(($costs ?? 0) * 100 + $category['total'] * 100) / 100);
        }

        $complete = $inWeek !== [] && $income !== null && $costs !== null;

        /*
         * AN INCOMPLETE WEEK CARRIES NO FIGURES AT ALL, not the half it has.
         *
         * A week with income entered and a cost category still empty has a
         * perfectly readable income number, and handing it out is the failure
         * this class exists to avoid wearing its most convincing disguise: the
         * figure is real, it is correctly summed, and it is half an answer.
         * Anything downstream showing "income $52,055" beside a blank profit
         * invites the subtraction to be done by eye.
         *
         * `categories` still carries the detail, so a caller that genuinely
         * wants to show what HAS been entered can, deliberately and by name.
         */
        if (! $complete) {
            $income = null;
            $costs = null;
        }

        return [
            'week' => $week,

            'complete' => $complete,

            'income' => $income,
            'costs' => $costs,
            'net' => $income === null || $costs === null ? null : round($income - $costs, 2),
            'stated_net' => $this->statedNet($inWeek),
            'categories' => $categories,
            'disagreements' => $disagreements,
        ];
    }

    /** Which marker a label is, however the sheet typed it: any case or spacing, "tota" for Total (measured 2026-11-11). */
    private static function marker(string $label): ?string
    {
        $label = strtolower((string) preg_replace('/\s+/', ' ', trim($label)));

        return match (true) {
            $label === strtolower(self::NET) => self::NET,
            strlen($label) >= 4 && str_starts_with(strtolower(self::TOTAL), $label) => self::TOTAL,
            default => null,
        };
    }

    /**
     * The week's own net-profit cell, for checking ours against.
     *
     * @param  list<array<string, mixed>>  $rows
     */
    private function statedNet(array $rows): ?float
    {
        foreach ($rows as $row) {
            if (self::marker((string) ($row['Income'] ?? '')) === self::NET && is_numeric($row['Income Amount'] ?? null)) {
                return (float) $row['Income Amount'];
            }
        }

        return null;
    }
}
