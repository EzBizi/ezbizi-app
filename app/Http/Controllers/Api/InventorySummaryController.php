<?php

namespace App\Http\Controllers\Api;

use App\Models\InventoryItem;
use App\Support\Dashboard\DashboardPeriod;
use App\Support\Inventory\InventorySummary;
use App\Support\Scoping\ViewScope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The General chip's one request (spec 2026-09-09, §6).
 *
 * One response rather than three, because the screen needs all three blocks
 * and three requests would draw the tab in three stages.
 *
 * Money is omitted here, not in the client: a total over costs answers about
 * rows the caller may not see, so `inventory.financials` has to be spent
 * before the number is computed into the payload, never after it is sent.
 */
class InventorySummaryController extends Controller
{
    /** GET /api/v1/inventory/summary */
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', InventoryItem::class);

        $periodKey = (string) $request->query('period', 'month');

        if (! in_array($periodKey, DashboardPeriod::VALUES, true)) {
            return response()->json([
                'message' => 'That is not a period this dashboard offers.',
            ], 422);
        }

        $user = $request->user();
        $scope = ViewScope::forUser($user);
        $counts = InventorySummary::counts($user, $scope);
        $withMoney = $user->hasPermission('inventory.financials');

        $period = DashboardPeriod::for($user, $periodKey);

        return response()->json([
            'health' => $this->health($counts, $withMoney),
            'warnings' => InventorySummary::warnings($user, $scope, $counts, $withMoney),
            'movement' => [
                ...InventorySummary::movement($user, $scope, $period),
                'previous' => InventorySummary::previousTotals($user, $scope, $period),
            ],
            ...InventorySummary::holdings($user, $scope, $withMoney),
            // movement.points are quantities, always meant as decimals — an
            // integral point (0, or a whole 10) must still arrive as a float
            // so the client never has to guess a mixed-type array's shape.
        ], 200, [], JSON_PRESERVE_ZERO_FRACTION);
    }

    /**
     * @param  array<string, int>  $counts
     * @return array<string, mixed>
     */
    private function health(array $counts, bool $withMoney): array
    {
        if ($counts['items'] === 0 && $counts['locations'] === 0) {
            return [
                'empty' => true,
                'sentence' => 'No items to show.',
            ];
        }

        $health = [
            'empty' => false,
            'in_stock' => $counts['in_stock'],
            'low' => $counts['low'],
            'out' => $counts['out'],
            'items' => $counts['items'],
            'locations' => $counts['locations'],
            'open_purchase_orders' => $counts['open_purchase_orders'],
            'purchase_orders' => ['open' => $counts['open_orders'], 'draft' => $counts['draft_orders']],
            // Each figure carries the list that produced it, so the client links
            // rather than guessing. A figure with no entry here is drawn as text.
            'links' => [
                // low_items, not low — see InventorySummary::warnings(). `out`
                // has no entry at all: nothing on the stock list can be it.
                'low' => 'stock?low_items=1',
                'items' => 'items',
                'locations' => 'locations',
                'open_purchase_orders' => 'orders',
            ],
        ];

        if ($withMoney) {
            $health['value'] = [
                'currency' => 'USD',
                'amount_minor' => $counts['stock_value_minor'],
            ];
        }

        return $health;
    }
}
