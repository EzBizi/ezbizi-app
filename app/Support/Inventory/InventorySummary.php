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

        $orders = InventoryVisibility::constrain(InventoryPurchaseOrder::query(), $user, $scope)
            ->whereIn('status', [
                InventoryPurchaseOrderStatus::Draft,
                InventoryPurchaseOrderStatus::Open,
            ])
            ->toBase()
            ->groupBy('status')
            ->selectRaw('status, COUNT(*) as n')
            ->pluck('n', 'status');
        $openOrders = (int) ($orders[InventoryPurchaseOrderStatus::Open->value] ?? 0);
        $draftOrders = (int) ($orders[InventoryPurchaseOrderStatus::Draft->value] ?? 0);
        $openPurchaseOrders = $openOrders + $draftOrders;

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

            }
        }

        $stockValueMinor = self::stockValueMinor($user, $scope) ?? 0;

        return [
            'items' => $items,
            'locations' => $locations,
            'open_purchase_orders' => $openPurchaseOrders,
            'open_orders' => $openOrders,
            'draft_orders' => $draftOrders,
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
     * @return list<array{key:string,sentence:string,count:int,link?:string}>
     */
    public static function warnings(User $user, ViewScope $scope, array $counts, bool $withMoney): array
    {
        $rows = [];

        // Order is fixed here, not sorted by count: out of stock leads because
        // it is the only one where work has already stopped.
        //
        // No link. An item is out of stock when it has nothing anywhere, and
        // the commonest way to have nothing is to have no balance row at all —
        // so the list this would open is the one place those items are not.
        // Sending a destination that cannot hold them is worse than sending
        // none: the client draws a figure with no link as text.
        $rows[] = [
            'key' => 'out_of_stock',
            'count' => $counts['out'],
            'sentence' => $counts['out'] === 1
                ? '1 item is out of stock.'
                : "{$counts['out']} items are out of stock.",
        ];

        $rows[] = [
            'key' => 'below_reorder',
            'count' => $counts['low'],
            'sentence' => $counts['low'] === 1
                ? '1 item is at or below its reorder point.'
                : "{$counts['low']} items are at or below their reorder point.",
            // low_items, not low: this figure counts ITEMS by their total on
            // hand, and `low=1` answers per balance row. An item held 3 and 3
            // against a reorder point of 5 is not counted here and must not be
            // listed there either.
            'link' => 'stock?low_items=1',
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

    /**
     * Each line of movement() summed over the same span one period back, for
     * the "vs last period" on the General tab's cards.
     *
     * @return array{received: float, issued_sold: float, adjusted: float, moved: float}
     */
    public static function previousTotals(User $user, ViewScope $scope, DashboardPeriod $period): array
    {
        $lines = collect(self::movement($user, $scope, $period->previous())['lines'])
            ->mapWithKeys(fn (array $line) => [$line['key'] => (float) array_sum($line['points'])]);

        return [
            'received' => $lines['in'],
            'issued_sold' => $lines['out'],
            'adjusted' => $lines['adjusted'],
            'moved' => $lines['transferred'],
        ];
    }

    /**
     * What sits where, from the shelves this caller can see: every visible
     * open place with how many items it holds and, with money, their worth;
     * and the five items holding the most (by value with money, by quantity
     * without, so a role without financials cannot read a ranking by worth).
     * A value is null where nothing is priced — never 0.
     *
     * ponytail: loads every positive balance in reach per request; move the
     * sums to SQL if a tenant's balances outgrow that.
     *
     * @return array{by_location: list<array<string, mixed>>, top_items: list<array<string, mixed>>}
     */
    public static function holdings(User $user, ViewScope $scope, bool $withMoney): array
    {
        $places = InventoryVisibility::constrain(InventoryLocation::query(), $user, $scope)
            ->where('status', InventoryLocationStatus::Active)
            ->orderBy('name')
            ->get(['id', 'name']);

        $balances = InventoryBalance::query()
            ->where('qty_on_hand', '>', 0)
            ->whereIn('location_id', $places->pluck('id')->all())
            ->whereHas('item', fn ($q) => InventoryVisibility::constrain($q, $user, $scope))
            ->with('item:id,name,number,last_cost_minor')
            ->get(['id', 'item_id', 'location_id', 'qty_on_hand']);

        $worth = fn (InventoryBalance $b): ?int => $b->item->last_cost_minor === null
            ? null
            : self::shelfValue((string) $b->qty_on_hand, (int) $b->item->last_cost_minor);
        $sum = function (Collection $rows) use ($worth): ?int {
            $values = $rows->map($worth)->filter(fn ($v) => $v !== null);

            return $values->isEmpty() ? null : (int) $values->sum();
        };

        $byPlace = $balances->groupBy('location_id');
        $byLocation = $places->map(function (InventoryLocation $place) use ($byPlace, $sum, $withMoney) {
            $rows = $byPlace->get($place->id, new Collection);
            $row = [
                'location' => ['id' => $place->id, 'name' => $place->name],
                'items' => $rows->pluck('item_id')->unique()->count(),
            ];
            if ($withMoney) {
                $row['value_minor'] = $sum($rows);
            }

            return $row;
        });

        $byItem = $balances->groupBy('item_id')->map(function (Collection $rows) use ($sum, $withMoney) {
            $item = $rows->first()->item;
            $row = [
                'item' => ['id' => $item->id, 'name' => $item->name, 'number' => $item->number],
                'qty' => $rows->reduce(fn (string $c, $b) => bcadd($c, (string) $b->qty_on_hand, 3), '0.000'),
            ];
            if ($withMoney) {
                $row['value_minor'] = $sum($rows);
            }

            return $row;
        });

        // Most valuable first with money; most held without. Ties fall to
        // the larger count, so an unpriced place that holds things outranks
        // an empty one.
        $desc = fn (string $size) => fn (array $a, array $b) => [
            $withMoney ? ($b['value_minor'] ?? -1) : 0, (float) $b[$size],
        ] <=> [
            $withMoney ? ($a['value_minor'] ?? -1) : 0, (float) $a[$size],
        ];

        return [
            'by_location' => $byLocation->sort($desc('items'))->values()->all(),
            'top_items' => $byItem->sort($desc('qty'))->take(5)->values()->all(),
        ];
    }

    /**
     * THE inventory value, the one figure every tab shows (UI/UX audit
     * 2026-11-13 found the tabs a dollar apart: separate sums over different
     * sets of shelves and items).
     *
     * Every shelf holding something, at a place this caller can see, of an
     * item this caller can see whatever its status (a discontinued part on a
     * shelf is still worth its cost), at last cost, rounded per shelf.
     * Locations' per-place values use the same rule, so they add up to this.
     * Null when nothing held is priced; never a counted zero.
     */
    public static function stockValueMinor(User $user, ViewScope $scope): ?int
    {
        $values = InventoryBalance::query()
            ->where('qty_on_hand', '>', 0)
            ->whereHas('item', fn ($q) => InventoryVisibility::constrain($q, $user, $scope)->whereNotNull('last_cost_minor'))
            ->whereHas('location', fn ($q) => InventoryVisibility::constrain($q, $user, $scope))
            ->with('item:id,last_cost_minor')
            ->get(['id', 'item_id', 'qty_on_hand'])
            ->map(fn (InventoryBalance $b) => self::shelfValue((string) $b->qty_on_hand, (int) $b->item->last_cost_minor));

        return $values->isEmpty() ? null : (int) $values->sum();
    }

    /** One shelf's worth in cents, rounded half-up: the only rounding rule. */
    public static function shelfValue(string $qty, int $costMinor): int
    {
        return (int) round(((float) $qty) * $costMinor);
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
