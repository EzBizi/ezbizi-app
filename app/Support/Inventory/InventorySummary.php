<?php

namespace App\Support\Inventory;

use App\Models\InventoryAdjustment;
use App\Models\InventoryBalance;
use App\Models\InventoryIssueLine;
use App\Models\InventoryItem;
use App\Models\InventoryLocation;
use App\Models\InventoryPurchaseOrder;
use App\Models\InventoryReceiveLine;
use App\Models\InventorySaleFulfillmentLine;
use App\Models\InventoryTransferLine;
use App\Models\User;
use App\Support\Dashboard\DashboardPeriod;
use App\Support\Scoping\ViewScope;
use App\Support\Time\ViewTimezone;
use Illuminate\Support\Carbon;
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
            // The cutoff is a calendar day in the VIEW's timezone (spec), not
            // app.timezone (UTC) — near midnight UTC those disagree about
            // which day it is. Match DashboardPeriod::timezoneFor().
            ->whereNotNull('ordered_on')
            ->whereDate('ordered_on', '<', Carbon::now(ViewTimezone::forUser($user))->subDays(30)->toDateString())
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
     * Time series behind the General tab's movement chart.
     *
     * `transferred` carries internal => true and is never folded into `in`
     * or `out` — moving stock between our own shelves is net zero, not
     * activity. `out` sums two sources (issues and sale fulfilments), which
     * is exactly the kind of thing that can silently lose one and still
     * look right everywhere else.
     *
     * @return array{period:string,buckets:list<string>,lines:list<array{key:string,label:string,internal:bool,points:list<float>}>}
     */
    public static function movement(User $user, ViewScope $scope, DashboardPeriod $period): array
    {
        $buckets = self::bucketAxis($period);
        $slot = array_flip($buckets);

        $visibleItem = fn ($q) => InventoryVisibility::constrain($q, $user, $scope);
        $visibleLocation = fn ($q) => InventoryVisibility::constrain($q, $user, $scope);

        // Another extra hop, same trap as sale fulfilments: a receive line has
        // no item_id of its own — it carries purchase_order_line_id, and the
        // item lives on THAT row. whereHas('item', ...) here would throw
        // (InventoryReceiveLine has no item() relation at all), not silently
        // match nothing, but the join it needs is still one hop further than
        // issue/transfer lines.
        $received = InventoryReceiveLine::query()
            ->join('inventory_receives', 'inventory_receives.id', '=', 'inventory_receive_lines.receive_id')
            ->join('inventory_purchase_order_lines', 'inventory_purchase_order_lines.id', '=', 'inventory_receive_lines.purchase_order_line_id')
            ->whereIn('inventory_purchase_order_lines.item_id', $visibleItem(InventoryItem::query())->select('id'))
            ->whereIn('inventory_receives.location_id', $visibleLocation(InventoryLocation::query())->select('id'))
            ->whereBetween('inventory_receives.received_on', [
                $period->startsOn->toDateString(), $period->endsOn->toDateString(),
            ])
            ->get(['inventory_receives.received_on as on', 'inventory_receive_lines.qty as qty']);

        $issued = InventoryIssueLine::query()
            ->join('inventory_issues', 'inventory_issues.id', '=', 'inventory_issue_lines.issue_id')
            ->whereHas('item', $visibleItem)
            ->whereIn('inventory_issues.location_id', $visibleLocation(InventoryLocation::query())->select('id'))
            ->whereBetween('inventory_issues.issued_on', [
                $period->startsOn->toDateString(), $period->endsOn->toDateString(),
            ])
            ->get(['inventory_issues.issued_on as on', 'inventory_issue_lines.qty as qty']);

        // Sale fulfilments join `out` too, but the shape is different from the
        // other three line tables: this one has no item_id of its own — the
        // item lives one hop further, on the sale LINE it fulfils.
        $fulfilled = InventorySaleFulfillmentLine::query()
            ->join('inventory_sale_fulfillments', 'inventory_sale_fulfillments.id', '=', 'inventory_sale_fulfillment_lines.fulfillment_id')
            ->join('inventory_sale_lines', 'inventory_sale_lines.id', '=', 'inventory_sale_fulfillment_lines.sale_line_id')
            ->whereIn('inventory_sale_lines.item_id', $visibleItem(InventoryItem::query())->select('id'))
            ->whereIn('inventory_sale_fulfillments.location_id', $visibleLocation(InventoryLocation::query())->select('id'))
            ->whereBetween('inventory_sale_fulfillments.fulfilled_on', [
                $period->startsOn->toDateString(), $period->endsOn->toDateString(),
            ])
            ->get([
                'inventory_sale_fulfillments.fulfilled_on as on',
                'inventory_sale_fulfillment_lines.qty as qty',
            ]);

        $transferred = InventoryTransferLine::query()
            ->join('inventory_transfers', 'inventory_transfers.id', '=', 'inventory_transfer_lines.transfer_id')
            ->whereHas('item', $visibleItem)
            ->whereIn('inventory_transfers.from_location_id', $visibleLocation(InventoryLocation::query())->select('id'))
            ->whereBetween('inventory_transfers.transferred_on', [
                $period->startsOn->toDateString(), $period->endsOn->toDateString(),
            ])
            ->get(['inventory_transfers.transferred_on as on', 'inventory_transfer_lines.qty as qty']);

        // Signed, so a downward count reads negative on ONE line rather than
        // being split across in and out — the sign is the information.
        $adjusted = InventoryAdjustment::query()
            ->whereHas('item', $visibleItem)
            ->whereIn('location_id', $visibleLocation(InventoryLocation::query())->select('id'))
            ->whereBetween('adjusted_on', [
                $period->startsOn->toDateString(), $period->endsOn->toDateString(),
            ])
            ->get()
            ->map(fn ($row) => (object) [
                'on' => $row->adjusted_on,
                'qty' => bcsub((string) $row->qty_after, (string) $row->qty_before, 3),
            ]);

        return [
            'period' => $period->key,
            'buckets' => $buckets,
            'lines' => [
                ['key' => 'in', 'label' => 'Received', 'internal' => false,
                    'points' => self::points($received, $period, $slot, count($buckets))],
                ['key' => 'out', 'label' => 'Issued and sold', 'internal' => false,
                    'points' => self::points($issued->concat($fulfilled), $period, $slot, count($buckets))],
                ['key' => 'adjusted', 'label' => 'Adjusted', 'internal' => false,
                    'points' => self::points($adjusted, $period, $slot, count($buckets))],
                // Net zero: stock moved between our own shelves. Never folded
                // into in or out, and flagged so the client cannot either.
                ['key' => 'transferred', 'label' => 'Transferred', 'internal' => true,
                    'points' => self::points($transferred, $period, $slot, count($buckets))],
            ],
        ];
    }

    /** @return list<string> */
    private static function bucketAxis(DashboardPeriod $period): array
    {
        $axis = [];
        $at = $period->startsOn->copy();

        // Walk the whole period so a quiet week returns a full axis rather than
        // an empty chart the client has to invent labels for.
        while ($at->lessThanOrEqualTo($period->endsOn)) {
            $bucket = $period->bucket($at);
            if (! in_array($bucket, $axis, true)) {
                $axis[] = $bucket;
            }
            $at->addDay();
        }

        return $axis;
    }

    /**
     * @param  iterable<object{on:mixed,qty:mixed}>  $rows
     * @param  array<string,int>  $slot
     * @return list<float>
     */
    private static function points(iterable $rows, DashboardPeriod $period, array $slot, int $width): array
    {
        $points = array_fill(0, $width, 0.0);

        foreach ($rows as $row) {
            // These are `date` columns — already calendar days. Parse them IN
            // the view timezone; never round-trip one through UTC, which can
            // move it a day.
            $bucket = $period->bucket(Carbon::parse((string) $row->on, $period->timezone));

            if (isset($slot[$bucket])) {
                $points[$slot[$bucket]] += (float) $row->qty;
            }
        }

        return $points;
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
