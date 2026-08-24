<?php

namespace App\Http\Controllers\Api;

use App\Models\InventoryBalance;
use App\Models\InventoryItem;
use App\Support\Inventory\InventoryVisibility;
use App\Support\Scoping\ViewScope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * On-hand at a place — the Stock chip (spec §7, §13).
 *
 * Rows are balances, not items. Both the item and the location must be
 * visible. `low=1` is on-hand at or below the item’s reorder_at when that
 * is set. `item_id` is one item’s balances, so a pane is not page 1 of
 * the whole register.
 */
class InventoryStockController extends Controller
{
    /** GET /api/v1/inventory/stock */
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

        if (filled($request->query('item_id'))) {
            $query->where('inventory_balances.item_id', (string) $request->query('item_id'));
        }

        $balances = $query
            ->orderBy('inventory_items.name')
            ->orderBy('inventory_locations.name')
            ->with(['item', 'location'])
            ->paginate(perPage: min((int) $request->query('per_page', 50), 200));

        return response()->json([
            'data' => collect($balances->items())->map(
                fn (InventoryBalance $balance) => $this->present($balance),
            ),
            'meta' => [
                'total' => $balances->total(),
                'per_page' => $balances->perPage(),
                'current_page' => $balances->currentPage(),
                'last_page' => $balances->lastPage(),
            ],
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
