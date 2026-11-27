<?php

namespace Tests\Feature\Reports;

use App\Models\Organization;
use App\Support\Reports\Texts\ReportPdf;
use Carbon\CarbonImmutable;
use Tests\TestCase;

/** Jose, 2026-11-09: "if ur going to make the pdf at least give them some formatting". */
class ReportPdfTest extends TestCase
{
    public function test_a_list_is_a_table_with_amounts_right_and_the_total_in_bold(): void
    {
        $html = $this->html("\u{1F4B5} *Revenue collected*\n_Thu, Nov 5, 2026 · payments received_\n\n"
            ."• *A-1 Chimney · New Jersey*: $17,744.11\n• *AngiSweep*: $0.00\n\n*TOTAL: $17,744.11*");

        $this->assertStringContainsString('<td class="org">A1-Group</td>', $html);
        $this->assertStringContainsString('<h1>Revenue collected</h1><p class="sub"><i>Thu, Nov 5, 2026 · payments received</i></p>', $html);
        $this->assertStringContainsString('<tr class=""><td>A-1 Chimney · New Jersey</td><td class="n">$17,744.11</td></tr>', $html);
        $this->assertStringContainsString('<tr class="z"><td>AngiSweep</td><td class="n">$0.00</td></tr>', $html);
        $this->assertStringContainsString('<tr class="total"><td>Total</td><td class="n">$17,744.11</td></tr>', $html);
        $this->assertStringContainsString('EzBizi · generated Nov 9, 2026 8:15 PM EST', $html);
        $this->assertStringNotContainsString("\u{1F4B5}", $html);
    }

    public function test_metrics_become_columns_and_a_heading_over_unnamed_rows_is_one_row(): void
    {
        $sales = $this->html("*Technician sales*\nWed, Nov 4, 2026\n\n*A-1 Chimney · DMV*\n"
            ."• *Ron Levi*: 4 completed · 0 canceled · $7,833.08 sales\n• *Elad Malasa*: 2 completed · 0 canceled · $0.00 sales");
        $this->assertStringContainsString('<h2>A-1 Chimney · DMV</h2>', $sales);
        $this->assertStringContainsString('<th class="n">Completed</th><th class="n">Canceled</th><th class="n">Sales</th>', $sales);
        $this->assertStringContainsString('<td>Ron Levi</td><td class="n">4</td><td class="n">0</td><td class="n">$7,833.08</td>', $sales);

        $ppc = $this->html("*Marketing PPC · Daily update*\nReport: Mon, Nov 9, 2026\n\n*Chicago*\n• Spent: $842.24 · Jobs: 0\n• Cost/job: N/A\n"
            ."*Cleveland*\n• Spent: $252.31 · Jobs: 2\n• Cost/job: $126.15\n\n*TOTAL* · Spent: $1,094.55 · Jobs: 2");
        $this->assertStringContainsString('<th class="n">Spent</th><th class="n">Jobs</th><th class="n">Cost/job</th>', $ppc);
        $this->assertStringContainsString('<td>Cleveland</td><td class="n">$252.31</td><td class="n">2</td><td class="n">$126.15</td>', $ppc);
        $this->assertStringContainsString('<tr class="total"><td>Total</td><td class="n">$1,094.55</td><td class="n">2</td><td></td></tr>', $ppc);
        $this->assertStringNotContainsString('<h2>Chicago</h2>', $ppc);
    }

    public function test_it_renders_a_pdf_with_page_numbers(): void
    {
        $pdf = ReportPdf::render("*Jobs created*\nFri, Nov 6, 2026\n\n".str_repeat("• *A-1 Chimney · Ohio*: 22\n", 80), new Organization(['name' => 'A1-Group']),
            CarbonImmutable::parse('2026-11-09 20:15', 'America/New_York'));

        $this->assertStringStartsWith('%PDF-', $pdf);
        $this->assertGreaterThan(1, preg_match_all('/\/Type\s*\/Page\b/', $pdf));
    }

    private function html(string $body): string
    {
        return ReportPdf::html($body, new Organization(['name' => 'A1-Group']), CarbonImmutable::parse('2026-11-09 20:15', 'America/New_York'));
    }
}
