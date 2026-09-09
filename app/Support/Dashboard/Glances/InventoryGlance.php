<?php

namespace App\Support\Dashboard\Glances;

use App\Models\User;
use App\Support\Dashboard\DashboardPeriod;
use App\Support\Dashboard\DescribedGlance;
use App\Support\Dashboard\GlancePayload;
use App\Support\Inventory\InventorySummary;
use App\Support\Scoping\ViewScope;
use App\Support\Tenancy\TenantContext;

/**
 * Inventory box: stock-health pie plus reach / open-PO sentences (snapshot).
 *
 * Active items only on the pie. Items, locations, and open POs stay sentences
 * so the board does not grow a line when the first PO is cut. Stock value is
 * amount_minor only, and only with inventory.financials.
 */
final class InventoryGlance extends DescribedGlance
{
    protected const KEY = 'inventory';
    protected const LABEL = 'Inventory';
    protected const PERMISSION = 'inventory.view';
    protected const PRESENTATIONS = ['pie', 'bar', 'text'];
    protected const DEFAULT_PRESENTATION = 'pie';
    protected const SNAPSHOT = true;

    public function resolve(User $user, DashboardPeriod $period, array $options): GlancePayload
    {
        return app(TenantContext::class)->run($user->organization, function () use ($user) {
            $scope = ViewScope::forUser($user);
            $counts = InventorySummary::counts($user, $scope);

            if ($counts['items'] === 0 && $counts['locations'] === 0) {
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

            $items = $counts['items'];
            $locations = $counts['locations'];
            $openPos = $counts['open_purchase_orders'];

            $data = [
                'empty' => false,
                'slices' => [
                    ['label' => 'In stock', 'total' => $counts['in_stock']],
                    ['label' => 'Low', 'total' => $counts['low']],
                    ['label' => 'Out of stock', 'total' => $counts['out']],
                ],
                'sentences' => [
                    $items === 1 ? '1 item in reach.' : "{$items} items in reach.",
                    $locations === 1 ? '1 location in reach.' : "{$locations} locations in reach.",
                    $openPos === 1 ? '1 open purchase order.' : "{$openPos} open purchase orders.",
                ],
            ];

            if ($user->hasPermission('inventory.financials')) {
                $data['stock_value'] = [
                    ['currency' => 'USD', 'amount_minor' => $counts['stock_value_minor']],
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
}
