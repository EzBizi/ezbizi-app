<?php

namespace App\Http\Controllers\Api;

use App\Models\Franchise;
use App\Support\Files\HardenedPdf;
use App\Support\Reports\Texts\BranchName;
use App\Support\Reports\Texts\PeriodReport;
use App\Support\Scoping\ViewScope;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
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
        ]);
        [$from, $to] = isset($in['year']) ? [$in['year'].'-01-01', $in['year'].'-12-31'] : [$in['from'], $in['to']];
        $view = ViewScope::forUser($request->user());
        $branch = isset($in['franchise_id']) ? Franchise::query()->findOrFail($in['franchise_id']) : null;
        abort_unless($branch === null ? $view->isOrganization() : in_array($branch->id, $view->franchiseIds(), true), 403,
            'That branch is not in your view.');
        $name = ($branch === null ? 'Company' : BranchName::of($branch))." {$from} to {$to}";

        if (($in['format'] ?? 'pdf') === 'xlsx') {
            abort_unless($request->user()->hasPermission('reports.export'), 403);
            $branches = $branch !== null ? collect([$branch]) : Franchise::query()->whereIn('id', $view->franchiseIds())->orderBy('name')->get();

            return $this->file($this->workbook($report, $branches, $from, $to), "{$name}.xlsx",
                'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        }
        $pdf = $branch !== null ? $report->forFranchise($branch, $from, $to)
            : HardenedPdf::render($report->consolidated($request->user()->organization, $from, $to), 'letter');

        return $this->file($pdf, "{$name}.pdf", 'application/pdf');
    }

    /** Three sheets — jobs created, jobs closed, technicians — one row per branch and line. */
    private function workbook(PeriodReport $report, $branches, string $from, string $to): string
    {
        $book = new Spreadsheet;
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
        $book->removeSheetByIndex(0);
        foreach ($rows as $title => $data) {
            $book->createSheet()->setTitle($title)->fromArray($data, null, 'A1', true);
        }
        ob_start();
        (new Xlsx($book))->save('php://output');

        return (string) ob_get_clean();
    }

    private function file(string $bytes, string $name, string $type): Response
    {
        return response($bytes, 200, ['Content-Type' => $type, 'Content-Disposition' => 'attachment; filename="'.str_replace('"', '', $name).'"']);
    }
}
