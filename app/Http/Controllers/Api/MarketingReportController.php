<?php

namespace App\Http\Controllers\Api;

use App\Models\Franchise;
use App\Support\Reports\MarketingReport;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response as Status;

/**
 * GET /api/v1/reports/marketing?from=Y-m-d&to=Y-m-d
 *
 * Behind `profit_loss.view`, as Profit & Loss is: the profit columns are the
 * P&L's margin applied to sales.
 */
class MarketingReportController extends Controller
{
    public function show(Request $request, MarketingReport $report): JsonResponse
    {
        abort_unless(
            $request->user()?->hasPermission('profit_loss.view'),
            Status::HTTP_FORBIDDEN,
            'The marketing report needs the Profit & Loss permission.',
        );

        $dates = $request->validate([
            'from' => ['required', 'date_format:Y-m-d'],
            'to' => ['required', 'date_format:Y-m-d', 'after_or_equal:from', 'before:'.now()->addDay()->toDateString()],
        ]);

        $branches = Franchise::query()
            ->where('is_active', true)
            ->whereIn('id', DB::table('workiz_jobs')->select('franchise_id'))
            ->orderBy('name')
            ->get();

        $rows = [];
        foreach ($branches as $branch) {
            array_push($rows, ...$report->forBranch($branch, $dates['from'], $dates['to']));
        }

        return response()->json(['data' => ['from' => $dates['from'], 'to' => $dates['to'], 'rows' => $rows]]);
    }
}
