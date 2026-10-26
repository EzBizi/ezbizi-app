<?php

namespace App\Http\Controllers\Api;

use App\Models\Vehicle;
use App\Support\Dashboard\DashboardPeriod;
use App\Support\Fleet\FleetSummary;
use App\Support\Scoping\ViewScope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Fleet's General tab, in one request - the same shape as
 * InventorySummaryController so both tabs read alike.
 *
 * `spending` is omitted without `fleet_management.financials`, server-side:
 * a total over payments answers about payments the caller may not see.
 */
class FleetSummaryController extends Controller
{
    /** GET /api/v1/vehicles/summary?period=week|month|quarter|year */
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Vehicle::class);

        $periodKey = (string) $request->query('period', 'month');

        if (! in_array($periodKey, DashboardPeriod::VALUES, true)) {
            return response()->json(['message' => 'That is not a period this dashboard offers.'], 422);
        }

        $user = $request->user();
        $scope = ViewScope::forUser($user);

        $body = [
            'health' => FleetSummary::health($user, $scope),
            'warnings' => FleetSummary::warnings($user, $scope),
            'dates' => FleetSummary::dates($user, $scope),
        ];

        if ($user->hasPermission('fleet_management.financials')) {
            $body['spending'] = FleetSummary::spending($user, $scope, DashboardPeriod::for($user, $periodKey));
        }

        return response()->json($body);
    }
}
