<?php

namespace App\Http\Controllers\Api;

use App\Models\Review;
use App\Support\CustomerExperience\ReviewLinkReport;
use App\Support\Scoping\ViewScope;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * GET /customer-experience/review-links — the review-link counts for the
 * franchises in view. Behind Reviews' own grant: it is that module's activity.
 * `from`/`to` are instants with an offset, so the client's midnight is used.
 */
class ReviewLinkReportController extends Controller
{
    public function __invoke(Request $request, ReviewLinkReport $report): JsonResponse
    {
        $this->authorize('viewAny', Review::class);
        $input = $request->validate([
            'from' => ['nullable', 'date'],
            'to'   => ['nullable', 'date', 'after:from'],
        ]);
        $to = isset($input['to']) ? CarbonImmutable::parse($input['to']) : CarbonImmutable::now();
        $from = isset($input['from']) ? CarbonImmutable::parse($input['from']) : $to->subDays(30);
        $user = $request->user();

        return response()->json(['data' => $report->build(
            $user, $from->utc(), $to->utc(), ViewScope::forUser($user)->franchiseIds(),
        )]);
    }
}
