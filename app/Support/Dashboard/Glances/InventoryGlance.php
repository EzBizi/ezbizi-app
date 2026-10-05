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
 * so the board does not grow a line when the first PO is cut. And
 * stock value only for a reader with inventory.financials (Jose, 2026-10-05).
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
                        'sentence' => 'No items to show.',
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
                    $items === 1 ? '1 item listed.' : "{$items} items listed.",
                    $locations === 1 ? '1 location listed.' : "{$locations} locations listed.",
                    $openPos === 1 ? '1 open purchase order.' : "{$openPos} open purchase orders.",
                ],
                // Counts of items, never their worth (see below). A snapshot,
                // so nothing to compare with: previous stays null.
                'figures' => [
                    GlancePayload::figure('items', 'items listed', $items),
                    GlancePayload::figure('low', 'low', $counts['low']),
                    GlancePayload::figure('out', 'out of stock', $counts['out']),
                ],
            ];

            // What needs somebody: out is broken, low is about to be. Counts
            // only; absent when nothing is low or out.
            $attention = array_values(array_filter([
                $counts['out'] > 0 ? GlancePayload::attention('bad', $counts['out'] === 1
                    ? '1 item out of stock'
                    : "{$counts['out']} items out of stock") : null,
                $counts['low'] > 0 ? GlancePayload::attention('caution', $counts['low'] === 1
                    ? '1 item low'
                    : "{$counts['low']} items low") : null,
            ]));
            if ($attention !== []) {
                $data['attention'] = $attention;
            }

            // Stock value only for a reader who holds inventory.financials
            // (Jose, 2026-10-05: money follows the role's "can see
            // financials"). Last, so it is never the headline a Small box
            // shows to a room. USD, as the Inventory tab reports it. A
            // snapshot: no previous.
            if ($user->hasPermission('inventory.financials')) {
                $data['figures'][] = GlancePayload::money('stock_value', 'stock value', $counts['stock_value_minor'], null, 'USD');
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
