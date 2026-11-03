<?php

namespace Tests\Feature\Reports;

use App\Support\Reports\Airtable\AirtablePnl;
use Tests\TestCase;

/**
 * Reading a week of profit and loss out of a spreadsheet-shaped Airtable base.
 *
 * ── THE FIXTURE IS REAL DATA, NOT AN INVENTED SHAPE ─────────────
 *
 * Every row below is A-1's NJ table, week 7-13 Sep 2026, copied from what the
 * API actually returned. That matters more here than usual: this module has
 * been caught before by fixtures that agreed with the code because the same
 * hand wrote both, and the whole difficulty of this format is a layout nobody
 * would invent — each category is its own list down the rows, with its own
 * total on its own row, unrelated to its neighbours'.
 *
 * The numbers are also a CONTROL rather than decoration. The sheet states its
 * own per-category totals and its own net, and this parser reads neither when
 * computing: it sums the detail lines. So the assertions below check an answer
 * derived one way against figures produced another way, by somebody else, in
 * Airtable.
 */
class AirtablePnlTest extends TestCase
{
    private const WEEK = '7 Sep - 13 Sep';

    /** Prod's NJ order, 2026-11-02: the screen opened on the last listed week, 6 Jul. */
    public function test_weeks_come_oldest_first_whatever_order_airtable_sends(): void
    {
        $rows = array_map(fn ($w) => ['Week' => $w], ['28 Sep - 4 Oct', '19 Oct - 25 Oct', '6 Jul - 12 Jul']);

        $this->assertSame(['6 Jul - 12 Jul', '28 Sep - 4 Oct', '19 Oct - 25 Oct'], (new AirtablePnl)->weeks($rows));
    }

    public function test_it_reproduces_a_real_week_the_sheet_already_answered(): void
    {
        $week = (new AirtablePnl)->week($this->realWeek(), self::WEEK);

        $this->assertTrue($week['complete']);

        // Summed from the lines. The sheet's own totals are asserted separately
        // below precisely so that agreeing with them is evidence rather than
        // the definition.
        $this->assertSame(52055.0, $week['income']);
        $this->assertSame(54346.17, $week['costs']);
        $this->assertSame(-2291.17, $week['net']);

        // And the sheet's own net cell, which nothing above consulted.
        $this->assertSame(-2291.17, $week['stated_net']);
        $this->assertSame([], $week['disagreements']);
    }

    /**
     * `Total Balance` IS NOT A LINE OF INCOME, and counting it as one is the
     * trap this format sets.
     *
     * It sits in the Income column, it is numeric, and it is the whole week's
     * profit — so a reader that sums the column gets income minus expenses
     * added back into income. On this week that produces 49,763.83 instead of
     * 52,055: lower, plausible, and wrong in the direction nobody queries.
     */
    public function test_the_net_marker_is_not_counted_as_income(): void
    {
        $week = (new AirtablePnl)->week($this->realWeek(), self::WEEK);

        $this->assertSame(52055.0, $week['income']);
        $this->assertNotEquals(49763.83, $week['income']);
    }

    /**
     * A CATEGORY'S TOTAL CELL IS CHECKED, NEVER PREFERRED.
     *
     * Measured on the live base: of 29 complete weeks across four tenants, 19
     * agree to the penny and 9 differ by under a dollar — the sheet keeps some
     * totals in whole dollars. One differs by $1,613, and on that week the
     * sheet disagrees with ITSELF: its own category totals give 18,750 and its
     * own net cell says 19,831.
     *
     * So neither number can be trusted over the other by rule, and resolving it
     * here would hide the only thing anybody can act on.
     */
    public function test_a_total_that_disagrees_with_its_lines_is_reported_not_resolved(): void
    {
        $rows = $this->realWeek();

        // Make the sheet's Tech Expenses total wrong, the way the July week is.
        foreach ($rows as $i => $row) {
            if (($row['Tech Expenses'] ?? null) === 'Total') {
                $rows[$i]['Tech Expenses Amount'] = 3810;
            }
        }

        $week = (new AirtablePnl)->week($rows, self::WEEK);

        // The LINES still decide.
        $this->assertSame(7068.45, $week['categories']['Tech Expenses']['total']);
        $this->assertSame(3810.0, $week['categories']['Tech Expenses']['stated']);

        $this->assertCount(1, $week['disagreements']);
        $this->assertStringContainsString('Tech Expenses', $week['disagreements'][0]);

        // And the total that is wrong has not moved the answer.
        $this->assertSame(-2291.17, $week['net']);
    }

    /**
     * AN EMPTY WEEK CARRIES NO NUMBER, rather than a zero.
     *
     * "Nobody has filled this in yet" and "nothing happened" are different
     * facts, and a zero income week reads as a catastrophe while a zero cost
     * week reads as a triumph. Both are wrong and neither looks like an error.
     */
    public function test_a_week_with_no_rows_is_incomplete_rather_than_zero(): void
    {
        $week = (new AirtablePnl)->week($this->realWeek(), '1 Jan - 7 Jan');

        $this->assertFalse($week['complete']);
        $this->assertNull($week['income']);
        $this->assertNull($week['net']);
    }

    /** Categories come from the DATA, so a business adding one is not dropped. */
    public function test_categories_are_read_from_the_rows(): void
    {
        $names = (new AirtablePnl)->categories($this->realWeek());

        $this->assertContains('Owner Distributions & Royalitys', $names);
        $this->assertContains('HQ Office Expenses', $names);

        // Including the typo the business actually has in its column name. A
        // hardcoded list spelled correctly would silently read nothing.
        $this->assertNotContains('Owner Distributions & Royalties', $names);
    }

    /**
     * A-1's NJ table, week 7-13 Sep 2026, as the API returned it.
     *
     * Eight rows, nine categories, and nine DIFFERENT rows carrying the totals
     * — row 1 for Income and Tech Expenses, row 6 for Bank Expenses, row 7 for
     * Production Expenses. That scatter is the format, not a quirk of this
     * week, and it is why a reader cannot look for "the totals row".
     *
     * @return list<array<string, mixed>>
     */
    private function realWeek(): array
    {
        $w = self::WEEK;

        return [
            ['Week' => $w, 'Bank Expenses' => 'parts', 'Bank Expenses Amount' => 244, 'Income' => 'Total Balance', 'Income Amount' => -2291.17],
            ['Week' => $w, 'Bank Expenses' => 'parts', 'Bank Expenses Amount' => 280, 'Income' => 'Total', 'Income Amount' => 52055, 'Tech Expenses' => 'Total', 'Tech Expenses Amount' => 7068.45],
            ['Week' => $w, 'Bank Expenses' => 'Iwallet', 'Bank Expenses Amount' => 40, 'Income' => 'ZELLE', 'Income Amount' => 248, 'Tech Expenses' => 'Guy Buganim', 'Tech Expenses Amount' => 2115.8, 'Marketing' => 'Total', 'Marketing Amount' => 11258.83, 'Production Expenses' => 'Kyle', 'Production Expenses Amount' => 750, 'Owner Distributions & Royalitys' => 'Total', 'Owner Distributions & Royalitys Amount' => 3000],
            ['Week' => $w, 'Bank Expenses' => 'USPS', 'Bank Expenses Amount' => 19.48, 'Income' => 'Workiz - Credit card', 'Income Amount' => 32558, 'Tech Expenses' => 'ron paz', 'Tech Expenses Amount' => 61.88, 'Marketing' => 'ACE Google', 'Marketing Amount' => 7939.01, 'Production Expenses' => 'payrolls taxes', 'Production Expenses Amount' => 2375.41, 'External companies' => 'Total', 'External companies Amount' => 450, 'HQ Office Expenses' => 'Total', 'HQ Office Expenses Amount' => 8078.72, 'Owner Distributions & Royalitys' => 'Meni', 'Owner Distributions & Royalitys Amount' => 1500],
            ['Week' => $w, 'Bank Expenses' => 'parts', 'Bank Expenses Amount' => 220, 'Income' => 'Guy cash', 'Income Amount' => 200, 'Tech Expenses' => 'shuki', 'Tech Expenses Amount' => 2892.37, 'Production Expenses' => 'ryan', 'Production Expenses Amount' => 750],
            ['Week' => $w, 'Bank Expenses' => 'chase', 'Bank Expenses Amount' => 14472.63, 'Income' => 'Iwallet - Checks', 'Income Amount' => 18750, 'Tech Expenses' => 'ori avner', 'Tech Expenses Amount' => 1663.33, 'Marketing' => 'HQ (Google Ads)', 'Marketing Amount' => 3319.82, 'Production Expenses' => 'repair team', 'Production Expenses Amount' => 3838.65, 'External companies' => 'chimney force', 'External companies Amount' => 450, 'HQ Office Expenses' => 'HQ ', 'HQ Office Expenses Amount' => 8078.72, 'Owner Distributions & Royalitys' => "Franchise Royalty's", 'Owner Distributions & Royalitys Amount' => 1500],
            ['Week' => $w, 'Bank Expenses' => 'Total', 'Bank Expenses Amount' => 16776.11],
            ['Week' => $w, 'Bank Expenses' => 'Car transportation', 'Bank Expenses Amount' => 1500, 'Income' => 'Yalli cash', 'Income Amount' => 299, 'Tech Expenses' => 'Yalli', 'Tech Expenses Amount' => 335.07, 'Production Expenses' => 'Total', 'Production Expenses Amount' => 7714.06],
        ];
    }
}
