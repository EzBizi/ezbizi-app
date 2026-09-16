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

                if ($label === self::TOTAL) {
                    $stated = (float) $amount;

                    continue;
                }

                // Income's net-profit marker is not one of income's lines. It
                // is the whole week's answer sitting in income's column, and
                // adding it to income would count the expenses twice, with the
                // sign making the result look merely disappointing.
                if ($label === self::NET) {
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

        return [
            'week' => $week,

            // Complete means every category this week mentions has at least one
            // line. A category present in the base and empty this week is the
            // "nobody has filled it in yet" case, and it is why nothing here
            // falls back to zero.
            'complete' => $inWeek !== [] && $income !== null && $costs !== null,

            'income' => $income,
            'costs' => $costs,
            'net' => $income === null || $costs === null ? null : round($income - $costs, 2),
            'stated_net' => $this->statedNet($inWeek),
            'categories' => $categories,
            'disagreements' => $disagreements,
        ];
    }

    /**
     * The week's own net-profit cell, for checking ours against.
     *
     * @param  list<array<string, mixed>>  $rows
     */
    private function statedNet(array $rows): ?float
    {
        foreach ($rows as $row) {
            if (trim((string) ($row['Income'] ?? '')) === self::NET && is_numeric($row['Income Amount'] ?? null)) {
                return (float) $row['Income Amount'];
            }
        }

        return null;
    }
}
