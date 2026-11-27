<?php

namespace App\Http\Controllers\Api;

use App\Models\InventoryBalance;
use App\Models\InventoryItem;
use App\Models\InventoryLocation;
use App\Support\Inventory\InventoryStockItems;
use App\Support\Inventory\InventoryVisibility;
use App\Support\Scoping\ViewScope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * On-hand at a place — the Stock chip (spec §7, §13).
 *
 * Rows are balances, not items. Both the item and the location must be
 * visible. `low=1` is on-hand at or below the item’s reorder_at when that
 * is set. `item_id` is one item’s balances, so a pane is not page 1 of
 * the whole register.
 *
 * `low_items=1` asks the same question of the ITEM rather than the row: its
 * on-hand summed over every visible location, at or below reorder_at. The two
 * disagree, and both answers are wanted — three in one van and three in the
 * shop is two low rows to whoever is restocking a shelf, and nothing to order
 * to whoever is buying. The Stock chip ships `low`; the General tab's low
 * figure counts items, so its link spends `low_items` to open the same set it
 * just claimed.
 */
class InventoryStockController extends Controller
{
    /** GET /api/v1/inventory/stock */
    /**
     * GET /api/v1/inventory/stock/items — one row per item. See InventoryStockItems.
     *
     * Same gate as `index` (the Stock tab). Orders and money are decided here,
     * once, from the caller's grants, and passed down rather than re-asked.
     */
    public function items(Request $request): JsonResponse
    {
        $this->authorize('viewAny', InventoryItem::class);

        $user = $request->user();
        $tile = $request->query('tile');

        $built = InventoryStockItems::build(
            $user,
            ViewScope::forUser($user),
            withOrders: $user->hasPermission('inventory.orders.view'),
            withMoney: $user->hasPermission('inventory.financials'),
            locationId: filled($request->query('location_id')) ? (string) $request->query('location_id') : null,
            search: filled($request->query('search')) ? trim((string) $request->query('search')) : null,
            tile: in_array($tile, InventoryStockItems::TILES, true) ? $tile : null,
        );

        $perPage = min(max((int) $request->query('per_page', 50), 1), 200);
        $page = max((int) $request->query('page', 1), 1);
        $total = count($built['rows']);

        return response()->json([
            'data' => array_slice($built['rows'], ($page - 1) * $perPage, $perPage),
            'meta' => [
                'total' => $total,
                'per_page' => $perPage,
                'current_page' => $page,
                'last_page' => max(1, (int) ceil($total / $perPage)),
                'tiles' => $built['tiles'],
            ],
        ], options: JSON_PRESERVE_ZERO_FRACTION);
    }

    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', InventoryItem::class);

        $user = $request->user();
        $scope = ViewScope::forUser($user);

        $query = InventoryBalance::query()
            ->join('inventory_items', 'inventory_items.id', '=', 'inventory_balances.item_id')
            ->join('inventory_locations', 'inventory_locations.id', '=', 'inventory_balances.location_id')
            ->select('inventory_balances.*')
            ->whereHas('item', fn (Builder $q) => InventoryVisibility::constrain($q, $user, $scope))
            ->whereHas('location', fn (Builder $q) => InventoryVisibility::constrain($q, $user, $scope));

        if ((string) $request->query('low') === '1') {
            $query->whereNotNull('inventory_items.reorder_at')
                ->whereColumn('inventory_balances.qty_on_hand', '<=', 'inventory_items.reorder_at');
        }

        if ((string) $request->query('low_items') === '1') {
            // Asks the ITEM, not the row: on-hand summed over every location
            // this caller can see, at or below the item's reorder_at. Grouped
            // rather than correlated so it runs once, not once per row.
            $visibleLocations = InventoryVisibility::constrain(
                InventoryLocation::query(), $user, $scope,
            )->select('inventory_locations.id');

            $lowItemIds = DB::table('inventory_balances')
                ->join('inventory_items', 'inventory_items.id', '=', 'inventory_balances.item_id')
                ->whereNotNull('inventory_items.reorder_at')
                ->whereIn('inventory_balances.location_id', $visibleLocations)
                ->groupBy('inventory_balances.item_id', 'inventory_items.reorder_at')
                ->havingRaw('sum(inventory_balances.qty_on_hand) <= inventory_items.reorder_at')
                ->select('inventory_balances.item_id');

            $query->whereIn('inventory_balances.item_id', $lowItemIds);
        }

        if (filled($request->query('item_id'))) {
            $query->where('inventory_balances.item_id', (string) $request->query('item_id'));
        }

        $balances = $query
            ->orderBy('inventory_items.name')
            ->orderBy('inventory_locations.name')
            ->with(['item', 'location'])
            ->paginate(perPage: max(1, min((int) $request->query('per_page', 50), 200)));

        return response()->json([
            'data' => collect($balances->items())->map(
                fn (InventoryBalance $balance) => $this->present($balance),
            ),
            'meta' => $this->pageMeta($balances),
        ]);
    }

    /** @return array<string, mixed> */
    private function present(InventoryBalance $balance): array
    {
        $item = $balance->item;
        $location = $balance->location;

        return [
            'id' => $balance->id,
            'qty_on_hand' => (string) $balance->qty_on_hand,
            'item' => $item === null ? null : [
                'id' => $item->id,
                'number' => $item->number,
                'sku' => $item->sku,
                'name' => $item->name,
                'unit' => $item->unit->value,
                'status' => $item->status->value,
                'reorder_at' => $item->reorder_at,
            ],
            'location' => $location === null ? null : [
                'id' => $location->id,
                'name' => $location->name,
                'kind' => $location->kind->value,
                'status' => $location->status->value,
            ],
        ];
    }
}
