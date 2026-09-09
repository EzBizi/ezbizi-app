<?php

namespace App\Support\Inventory;

use App\Models\InventoryBalance;
use App\Models\InventoryItem;
use App\Models\InventoryLocation;
use App\Models\InventoryPurchaseOrder;
use App\Models\User;
use App\Support\Scoping\ViewScope;
use Illuminate\Support\Collection;

/**
 * The facts behind the Dashboard's Inventory box and the General tab.
 *
 * Returns numbers, not payloads: each caller shapes them its own way. That is
 * deliberate — the box wants pie slices and sentences, the tab wants a flat
 * block, and the thing they must agree on is what "low" means, not how it is
 * drawn.
 *
 * Does NOT enter TenantContext and does NOT consult permissions. Callers do
 * both, the same contract as InventoryVisibility, so that the decision to
 * withhold money is made once and next to the response.
 */
final class InventorySummary
{
    /**
     * @return array{items:int,locations:int,open_purchase_orders:int,in_stock:int,low:int,out:int,stock_value_minor:int}
     */
    public static function counts(User $user, ViewScope $scope): array
    {
        $itemQuery = InventoryVisibility::constrain(InventoryItem::query(), $user, $scope);

        $items = (int) (clone $itemQuery)->count();
        $locations = (int) InventoryVisibility::constrain(InventoryLocation::query(), $user, $scope)->count();

        $openPurchaseOrders = (int) InventoryVisibility::constrain(InventoryPurchaseOrder::query(), $user, $scope)
            ->whereIn('status', [
                InventoryPurchaseOrderStatus::Draft,
                InventoryPurchaseOrderStatus::Open,
            ])
            ->count();

        $inStock = 0;
        $low = 0;
        $out = 0;
        $stockValueMinor = 0;

        if ($items > 0) {
            $activeItems = (clone $itemQuery)
                ->where('status', InventoryItemStatus::Active)
                ->get(['id', 'reorder_at', 'last_cost_minor']);

            $qtyByItem = self::qtyOnHandByItemId($activeItems->pluck('id'));

            foreach ($activeItems as $item) {
                $qty = $qtyByItem[$item->id] ?? '0.000';

                if (bccomp($qty, '0', 3) <= 0) {
                    $out++;
                } elseif ($item->reorder_at !== null
                    && bccomp($qty, (string) $item->reorder_at, 3) <= 0) {
                    $low++;
                } else {
                    $inStock++;
                }

                if ($item->last_cost_minor !== null) {
                    // Keep the fractional on-hand; round the product to nearest
                    // minor unit (half-up) rather than truncating qty first.
                    $stockValueMinor += (int) round(((float) $qty) * (int) $item->last_cost_minor);
                }
            }
        }

        return [
            'items' => $items,
            'locations' => $locations,
            'open_purchase_orders' => $openPurchaseOrders,
            'in_stock' => $inStock,
            'low' => $low,
            'out' => $out,
            'stock_value_minor' => $stockValueMinor,
        ];
    }

    /**
     * Rows the General tab draws under "needs attention".
     *
     * Takes $counts rather than recomputing it: the controller already ran
     * counts() for the health block, and this runs in the same request, so
     * a second full aggregation would be wasted work for no different answer.
     *
     * @param  array{items:int,locations:int,open_purchase_orders:int,in_stock:int,low:int,out:int,stock_value_minor:int}  $counts
     * @return list<array{key:string,sentence:string,count:int,link:string}>
     */
    public static function warnings(User $user, ViewScope $scope, array $counts, bool $withMoney): array
    {
        $rows = [];

        // Order is fixed here, not sorted by count: out of stock leads because
        // it is the only one where work has already stopped.
        $rows[] = [
            'key' => 'out_of_stock',
            'count' => $counts['out'],
            'sentence' => $counts['out'] === 1
                ? '1 item is out of stock.'
                : "{$counts['out']} items are out of stock.",
            'link' => 'stock',
        ];

        $rows[] = [
            'key' => 'below_reorder',
            'count' => $counts['low'],
            'sentence' => $counts['low'] === 1
                ? '1 item is at or below its reorder point.'
                : "{$counts['low']} items are at or below their reorder point.",
            'link' => 'stock?low=1',
        ];

        $oldOrders = (int) InventoryVisibility::constrain(InventoryPurchaseOrder::query(), $user, $scope)
            ->whereIn('status', [
                InventoryPurchaseOrderStatus::Draft,
                InventoryPurchaseOrderStatus::Open,
            ])
            // A null ordered_on cannot be aged, so it is excluded rather than
            // treated as today — which would make every undated order fine.
            ->whereNotNull('ordered_on')
            ->whereDate('ordered_on', '<', now()->subDays(30)->toDateString())
            ->count();

        $rows[] = [
            'key' => 'purchase_order_open_a_long_time',
            'count' => $oldOrders,
            'sentence' => $oldOrders === 1
                ? '1 purchase order has been open more than 30 days.'
                : "{$oldOrders} purchase orders have been open more than 30 days.",
            'link' => 'orders',
        ];

        $emptyLocations = (int) InventoryVisibility::constrain(InventoryLocation::query(), $user, $scope)
            ->whereDoesntHave('balances', fn ($q) => $q->where('qty_on_hand', '>', 0))
            ->count();

        $rows[] = [
            'key' => 'location_holding_nothing',
            'count' => $emptyLocations,
            'sentence' => $emptyLocations === 1
                ? '1 location is holding nothing.'
                : "{$emptyLocations} locations are holding nothing.",
            'link' => 'locations',
        ];

        if ($withMoney) {
            $noCost = (int) InventoryVisibility::constrain(InventoryItem::query(), $user, $scope)
                ->where('status', InventoryItemStatus::Active)
                ->whereNull('last_cost_minor')
                ->count();

            $rows[] = [
                'key' => 'no_cost_recorded',
                'count' => $noCost,
                'sentence' => $noCost === 1
                    ? '1 item has no cost recorded.'
                    : "{$noCost} items have no cost recorded.",
                'link' => 'items',
            ];
        }

        return array_values(array_filter($rows, fn (array $row) => $row['count'] > 0));
    }

    /**
     * @param  Collection<int, string>  $itemIds
     * @return array<string, string> item id → qty decimal string (scale 3)
     */
    private static function qtyOnHandByItemId(Collection $itemIds): array
    {
        if ($itemIds->isEmpty()) {
            return [];
        }

        return InventoryBalance::query()
            ->whereIn('item_id', $itemIds->all())
            ->groupBy('item_id')
            ->selectRaw('item_id, SUM(qty_on_hand) as qty_on_hand')
            ->pluck('qty_on_hand', 'item_id')
            ->map(fn ($qty) => number_format((float) $qty, 3, '.', ''))
            ->all();
    }
}
