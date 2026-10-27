<?php

namespace App\Http\Controllers\Api;

use App\Models\Franchise;
use App\Support\Files\HardenedPdf;
use App\Support\Reports\Airtable\ReportSources;
use App\Support\Reports\Texts\BranchName;
use App\Support\Reports\Texts\PeriodReport;
use App\Support\Reports\Xlsx;
use App\Support\Scoping\ViewScope;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\Response;

/**
 * GET /api/v1/reports/period: the weekly/monthly report for any dates, on
 * request — a branch's PDF, or with no branch the organization's consolidated
 * PDF; format=xlsx gives the same figures as a workbook; year=YYYY is the
 * yearly report (takeover rows 35 and 50). Follows the reader's view.
 */
class ReportPeriodController extends Controller
{
    public function show(Request $request, PeriodReport $report): Response
    {
        $in = $request->validate([
            'franchise_id' => ['nullable', 'uuid', Rule::exists('franchises', 'id')->where('organization_id', app(TenantContext::class)->idOrFail())],
            'year' => ['required_without:from', 'integer', 'between:2000,2100'],
            'from' => ['required_without:year', 'date_format:Y-m-d'],
            'to' => ['required_with:from', 'date_format:Y-m-d', 'after_or_equal:from'],
            'format' => ['sometimes', 'in:pdf,xlsx'],
            'report' => ['sometimes', 'in:period,job_profitability'],
        ]);
        [$from, $to] = isset($in['year']) ? [$in['year'].'-01-01', $in['year'].'-12-31'] : [$in['from'], $in['to']];
        $view = ViewScope::forUser($request->user());
        $branch = isset($in['franchise_id']) ? Franchise::query()->findOrFail($in['franchise_id']) : null;
        abort_unless($branch === null ? $view->isOrganization() : in_array($branch->id, $view->franchiseIds(), true), 403,
            'That branch is not in your view.');
        $name = ($branch === null ? 'Company' : BranchName::of($branch))." {$from} to {$to}";

        $xlsx = ($in['format'] ?? 'pdf') === 'xlsx';
        abort_if($xlsx && ! $request->user()->hasPermission('reports.export'), 403);
        $branches = $branch !== null ? collect([$branch]) : Franchise::query()->whereIn('id', $view->franchiseIds())->orderBy('name')->get();

        if (($in['report'] ?? 'period') === 'job_profitability') {
            $rows = $this->profitability((string) $request->user()->organization_id, $branches, $from, $to);
            $name = "Job profitability {$name}";
            if ($xlsx) {
                return $this->xlsx(['Jobs' => $rows], $name);
            }
            $html = '<!doctype html><meta charset="utf-8"><style>body{font:11px sans-serif}td,th{border:1px solid #ccc;padding:3px}table{border-collapse:collapse}</style><h2>'.e($name).'</h2>'
                .PeriodReport::table(array_shift($rows), $rows);

            return $this->file(HardenedPdf::render($html, 'letter'), "{$name}.pdf", 'application/pdf');
        }
        if ($xlsx) {
            return $this->xlsx($this->workbook($report, $branches, $from, $to), $name);
        }
        $pdf = $branch !== null ? $report->forFranchise($branch, $from, $to)
            : HardenedPdf::render($report->consolidated($request->user()->organization, $from, $to), 'letter');

        return $this->file($pdf, "{$name}.pdf", 'application/pdf');
    }

    /** Three sheets — jobs created, jobs closed, technicians — one row per branch and line. */
    private function workbook(PeriodReport $report, $branches, string $from, string $to): array
    {
        $sheets = [
            'Created' => ['Branch', 'Source', 'Jobs', 'Done', 'Open', 'Canceled', 'Gross'],
            'Closed' => ['Branch', 'Source', 'Done', 'Canceled', 'Gross'],
            'Technicians' => ['Branch', 'Technician', 'Done', 'Canceled', 'Gross', 'Deposits'],
        ];
        $rows = array_map(fn ($head) => [$head], $sheets);
        foreach ($branches as $b) {
            $f = $report->figures($b, $from, $to);
            $n = BranchName::of($b);
            foreach ($f['created'] as $s => $r) {
                $rows['Created'][] = [$n, $s, $r['all'], $r['done'], $r['open'], $r['canceled'], round($r['gross'], 2)];
            }
            foreach ($f['closed'] as $s => $r) {
                $rows['Closed'][] = [$n, $s, $r['done'], $r['canceled'], round($r['gross'], 2)];
            }
            foreach ($f['techs'] as $t) {
                $rows['Technicians'][] = [$n, $t['name'], $t['done'], $t['canceled'], round($t['gross'], 2), round($t['deposits'], 2)];
            }
        }

        return $rows;
    }

    /**
     * Takeover row 42: per job, total - parts - tech share - external share,
     * from each branch's mapped repair tables' job rows (not the summary
     * rows). ponytail: the costs live only in those tables until Jobs carries
     * its own; then this reads ReportJobs.
     *
     * @return list<list<mixed>> a header row, then one row per job
     */
    private function profitability(string $org, $branches, string $from, string $to): array
    {
        $out = [['Branch', 'Date', 'Job #', 'Team', 'Total', 'Parts', 'Tech share', 'External share', 'Profit', 'Margin']];
        $n = fn ($v) => round((float) str_replace([',', '$'], '', (string) $v), 2);
        foreach ($branches as $b) {
            $summary = ReportSources::marker($org, 'repair', (string) $b->id, 'summary');
            foreach (ReportSources::rows($org, 'repair', (string) $b->id, $from, $to)->sortBy('date') as $r) {
                if ($summary !== '' && ($r['kind'] ?? null) === $summary) {
                    continue;
                }
                $total = $n($r['amount'] ?? 0);
                [$parts, $tech, $external] = [$n($r['parts'] ?? 0), $n($r['tech_share'] ?? 0), $n($r['external_share'] ?? 0)];
                $profit = round($total - $parts - $tech - $external, 2);
                $out[] = [BranchName::of($b), $r['date'], (string) ($r['job_number'] ?? ''), (string) ($r['team'] ?? ''),
                    $total, $parts, $tech, $external, $profit, $total > 0 ? round($profit / $total * 100).'%' : '-'];
            }
        }

        return $out;
    }

    /** @param array<string, list<list<mixed>>> $sheets title => rows, header first */
    private function xlsx(array $sheets, string $name): Response
    {
        return $this->file(Xlsx::write($sheets), "{$name}.xlsx", 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    }

    private function file(string $bytes, string $name, string $type): Response
    {
        return response($bytes, 200, ['Content-Type' => $type, 'Content-Disposition' => 'attachment; filename="'.str_replace('"', '', $name).'"']);
    }
}
