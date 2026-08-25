<?php

namespace App\Support\Dashboard\Glances;

use App\Models\InventoryBalance;
use App\Models\InventoryItem;
use App\Models\InventoryLocation;
use App\Models\InventoryPurchaseOrder;
use App\Models\User;
use App\Support\Dashboard\DashboardPeriod;
use App\Support\Dashboard\Glance;
use App\Support\Dashboard\GlancePayload;
use App\Support\Inventory\InventoryItemStatus;
use App\Support\Inventory\InventoryPurchaseOrderStatus;
use App\Support\Inventory\InventoryVisibility;
use App\Support\Scoping\ViewScope;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Collection;

/**
 * Inventory box: stock-health pie plus reach / open-PO sentences (snapshot).
 *
 * Active items only on the pie. Items, locations, and open POs stay sentences
 * so the board does not grow a line when the first PO is cut. Stock value is
 * amount_minor only, and only with inventory.financials.
 */
final class InventoryGlance implements Glance
{
    public function key(): string
    {
        return 'inventory';
    }

    public function label(): string
    {
        return 'Inventory';
    }

    public function permission(): string
    {
        return 'inventory.view';
    }

    public function allowedPresentations(): array
    {
        return ['pie', 'bar', 'text'];
    }

    public function defaultPresentation(): string
    {
        return 'pie';
    }

    public function snapshot(): bool
    {
        return true;
    }

    public function resolve(User $user, DashboardPeriod $period, array $options): GlancePayload
    {
        return app(TenantContext::class)->run($user->organization, function () use ($user) {
            $scope = ViewScope::forUser($user);

            $itemQuery = InventoryVisibility::constrain(InventoryItem::query(), $user, $scope);
            $itemsInReach = (int) (clone $itemQuery)->count();
            $locations = (int) InventoryVisibility::constrain(InventoryLocation::query(), $user, $scope)->count();

            if ($itemsInReach === 0 && $locations === 0) {
                return new GlancePayload(
                    key: $this->key(),
                    label: $this->label(),
                    presentation: $this->defaultPresentation(),
                    allowedPresentations: $this->allowedPresentations(),
                    snapshot: $this->snapshot(),
                    comingSoon: false,
                    options: [],
                    data: [
                        'empty' => true,
                        'sentence' => 'No items in reach.',
                    ],
                );
            }

            $openPos = (int) InventoryVisibility::constrain(InventoryPurchaseOrder::query(), $user, $scope)
                ->whereIn('status', [
                    InventoryPurchaseOrderStatus::Draft,
                    InventoryPurchaseOrderStatus::Open,
                ])
                ->count();

            $inStock = 0;
            $low = 0;
            $out = 0;
            $stockValueMinor = 0;

            if ($itemsInReach > 0) {
                $activeItems = (clone $itemQuery)
                    ->where('status', InventoryItemStatus::Active)
                    ->get(['id', 'reorder_at', 'last_cost_minor']);

                $qtyByItem = $this->qtyOnHandByItemId($activeItems->pluck('id'));

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

            $data = [
                'empty' => false,
                'slices' => [
                    ['label' => 'In stock', 'total' => $inStock],
                    ['label' => 'Low', 'total' => $low],
                    ['label' => 'Out of stock', 'total' => $out],
                ],
                'sentences' => [
                    $itemsInReach === 1 ? '1 item in reach.' : "{$itemsInReach} items in reach.",
                    $locations === 1 ? '1 location in reach.' : "{$locations} locations in reach.",
                    $openPos === 1 ? '1 open purchase order.' : "{$openPos} open purchase orders.",
                ],
            ];

            if ($user->hasPermission('inventory.financials')) {
                $data['stock_value'] = [
                    ['currency' => 'USD', 'amount_minor' => $stockValueMinor],
                ];
            }

            return new GlancePayload(
                key: $this->key(),
                label: $this->label(),
                presentation: $this->defaultPresentation(),
                allowedPresentations: $this->allowedPresentations(),
                snapshot: $this->snapshot(),
                comingSoon: false,
                options: [],
                data: $data,
            );
        });
    }

    /**
     * @param  Collection<int, string>  $itemIds
     * @return array<string, string> item id → qty decimal string (scale 3)
     */
    private function qtyOnHandByItemId(Collection $itemIds): array
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
