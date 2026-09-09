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
