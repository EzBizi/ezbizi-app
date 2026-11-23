<?php

namespace App\Support\Inventory;

use App\Models\InventoryBalance;
use App\Models\InventoryItem;
use App\Models\InventoryPurchaseOrder;
use App\Models\User;
use App\Support\Scoping\ViewScope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * One row per item for the Stock tab: `client/lib/screens/inventory/stock_join.dart`
 * moved to the server (UI queue 43, spec from UI/UX 2026-10-01).
 *
 * The client used to fetch every page of `/inventory/stock`, `/inventory/items`
 * and `/inventory/purchase-orders` and join them. Two of those are tab-gated
 * and one strips cost, so every list had to re-derive the same permission
 * rules — and three of them got it wrong in the same way, reading a refused
 * read as an empty one. Doing the join here enforces them once.
 *
 * ── Two rules that look like one ─────────────────────────────────
 *
 * STATUS is judged by the item's total across every place: three in the van
 * and three in the shop against a reorder point of five is not low. ON-HAND
 * follows the place filter: filtered to the van, that item holds three. The
 * client's `status()` and `onHand(at:)` already split this way, and merging
 * them would make one shelf decide whether the business is short.
 *
 * ── The tiles describe the catalogue, not the page ───────────────
 *
 * They are computed over every visible item before any search, place or tile
 * filter, because tapping a tile must land on a list whose length is the number
 * the tile showed.
 *
 * ── A zero is a measurement ──────────────────────────────────────
 *
 * Without `inventory.orders.view` the order figures are null and the order
 * tiles absent; without `inventory.financials` there is no cost and no value.
 * Neither is ever sent as zero, because zero claims something was counted.
 *
 * ponytail: loads the whole visible catalogue, its balances and its open and
 * draft order lines per request, then filters and pages in PHP — the same work
 * the client was doing across 25 requests, now in a handful of queries. If an
 * organization's catalogue outgrows that, move the per-item sums into SQL.
 */
final class InventoryStockItems
{
    public const TILES = ['low', 'out', 'ordered'];

    /**
     * @return array{rows: list<array<string, mixed>>, tiles: array<string, mixed>}
     */
    public static function build(
        User $user,
        ViewScope $scope,
        bool $withOrders,
        bool $withMoney,
        ?string $locationId,
        ?string $search,
        ?string $tile,
    ): array {
        $balances = InventoryBalance::query()
            ->whereHas('item', fn (Builder $q) => InventoryVisibility::constrain($q, $user, $scope))
            ->whereHas('location', fn (Builder $q) => InventoryVisibility::constrain($q, $user, $scope))
            ->with('location')
            ->get();

        $byItem = $balances->groupBy('item_id');

        /*
         * Active items, plus any item still holding a balance row whatever its
         * status. A discontinued item on a shelf is still on the shelf; one
         * held nowhere has nothing left to report. This is the union the
         * client built from `/items` (active by default) and the stock rows.
         */
        $items = InventoryVisibility::constrain(InventoryItem::query(), $user, $scope)
            ->where(fn (Builder $q) => $q
                ->where('status', InventoryItemStatus::Active)
                ->orWhereIn('id', $byItem->keys()->all()))
            ->orderBy('name')
            ->get();

        [$onOrder, $inDraft, $numbers, $openOrders] = $withOrders
            ? self::orders($user, $scope)
            : [[], [], [], 0];

        $facts = [];
        foreach ($items as $item) {
            $rows = $byItem->get($item->id, new Collection);
            $total = self::sum($rows);

            $facts[] = [
                'item' => $item,
                'rows' => $rows,
                'total' => $total,
                'status' => self::status($total, $item->reorder_at),
                'on_order' => $withOrders ? ($onOrder[$item->id] ?? '0.000') : null,
                'in_draft' => $withOrders ? ($inDraft[$item->id] ?? '0.000') : null,
                'numbers' => $withOrders ? array_values($numbers[$item->id] ?? []) : [],
            ];
        }

        $rows = [];
        foreach ($facts as $fact) {
            if (self::passes($fact, $locationId, $search, $tile)) {
                $rows[] = self::present($fact, $locationId, $withMoney);
            }
        }

        return [
            'rows' => $rows,
            'tiles' => self::tiles($facts, $withOrders, $withMoney, $openOrders, $user, $scope),
        ];
    }

    /**
     * Still to arrive on OPEN orders, and ordered on DRAFT ones, per item.
     *
     * @return array{0: array<string,string>, 1: array<string,string>, 2: array<string,array<string,string>>, 3: int}
     */
    private static function orders(User $user, ViewScope $scope): array
    {
        $orders = InventoryVisibility::constrain(InventoryPurchaseOrder::query(), $user, $scope)
            ->whereIn('status', [
                InventoryPurchaseOrderStatus::Open,
                InventoryPurchaseOrderStatus::Draft,
            ])
            ->with('lines')
            ->get();

        $onOrder = [];
        $inDraft = [];
        $numbers = [];
        $openOrders = 0;

        foreach ($orders as $order) {
            $open = $order->status === InventoryPurchaseOrderStatus::Open;
            $open && $openOrders++;

            foreach ($order->lines as $line) {
                // Never negative: an over-receipt does not make an order owe us.
                $outstanding = bcsub((string) $line->qty_ordered, (string) $line->qty_received, 3);
                if (bccomp($outstanding, '0', 3) < 0) {
                    $outstanding = '0.000';
                }

                if ($open) {
                    $onOrder[$line->item_id] = bcadd($onOrder[$line->item_id] ?? '0.000', $outstanding, 3);
                    // Keyed by number so an item on two lines of one order is named once.
                    $numbers[$line->item_id][$order->number] = $order->number;
                } else {
                    $inDraft[$line->item_id] = bcadd($inDraft[$line->item_id] ?? '0.000', $outstanding, 3);
                }
            }
        }

        return [$onOrder, $inDraft, $numbers, $openOrders];
    }

    private static function sum(Collection $rows): string
    {
        return $rows->reduce(
            fn (string $carry, InventoryBalance $row) => bcadd($carry, (string) $row->qty_on_hand, 3),
            '0.000',
        );
    }

    public static function status(string $total, mixed $reorderAt): string
    {
        if (bccomp($total, '0', 3) <= 0) {
            return 'out_of_stock';
        }

        if ($reorderAt !== null && bccomp($total, (string) $reorderAt, 3) <= 0) {
            return 'below_reorder';
        }

        return 'in_stock';
    }

    /** @param  array<string, mixed>  $fact */
    private static function passes(array $fact, ?string $locationId, ?string $search, ?string $tile): bool
    {
        // A place filter shows what has a row THERE, not everything at zero.
        if ($locationId !== null && ! $fact['rows']->contains('location_id', $locationId)) {
            return false;
        }

        if ($search !== null) {
            $item = $fact['item'];
            $hit = collect([$item->name, $item->sku, $item->number])
                ->contains(fn ($v) => $v !== null && stripos((string) $v, $search) !== false);
            if (! $hit) {
                return false;
            }
        }

        return match ($tile) {
            'low' => $fact['status'] === 'below_reorder',
            'out' => $fact['status'] === 'out_of_stock',
            'ordered' => $fact['on_order'] !== null && bccomp($fact['on_order'], '0', 3) > 0,
            default => true,
        };
    }

    /**
     * @param  array<string, mixed>  $fact
     * @return array<string, mixed>
     */
    private static function present(array $fact, ?string $locationId, bool $withMoney): array
    {
        $item = $fact['item'];

        $onHand = $locationId === null
            ? $fact['total']
            : self::sum($fact['rows']->where('location_id', $locationId));

        $presented = [
            'id' => $item->id,
            'number' => $item->number,
            'sku' => $item->sku,
            'name' => $item->name,
            'unit' => $item->unit->value,
            'status' => $item->status->value,
            'reorder_at' => $item->reorder_at,
        ];

        if ($withMoney) {
            $presented['last_cost_minor'] = $item->last_cost_minor;
        }

        $row = [
            'item' => $presented,
            'on_hand' => $onHand,
            'status' => $fact['status'],
            'places' => $fact['rows']
                ->sortBy(fn (InventoryBalance $b) => $b->location?->name)
                ->map(fn (InventoryBalance $b) => [
                    'location' => $b->location === null ? null : [
                        'id' => $b->location->id,
                        'name' => $b->location->name,
                        'kind' => $b->location->kind?->value,
                        'status' => $b->location->status?->value,
                    ],
                    'qty_on_hand' => (string) $b->qty_on_hand,
                ])
                ->values()
                ->all(),
            'on_order' => $fact['on_order'],
            'in_draft' => $fact['in_draft'],
            'order_numbers' => $fact['numbers'],
        ];

        if ($withMoney) {
            // Null, not zero, when nothing has been bought yet: there is no cost
            // to multiply. Absent entirely without financials.
            $row['value_minor'] = $item->last_cost_minor === null
                ? null
                : InventorySummary::shelfValue((string) $onHand, (int) $item->last_cost_minor);
        }

        return $row;
    }

    /**
     * @param  list<array<string, mixed>>  $facts
     * @return array<string, mixed>
     */
    private static function tiles(array $facts, bool $withOrders, bool $withMoney, int $openOrders, User $user, ViewScope $scope): array
    {
        $low = array_values(array_filter($facts, fn ($f) => $f['status'] === 'below_reorder'));
        $out = array_values(array_filter($facts, fn ($f) => $f['status'] === 'out_of_stock'));

        $tiles = [
            'items' => count($facts),
            'below_reorder' => count($low),
            'out_of_stock' => count($out),
            'first_out_of_stock_name' => $out[0]['item']->name ?? null,
        ];

        if ($withMoney) {
            $tiles['value_minor'] = InventorySummary::stockValueMinor($user, $scope);
        }

        if ($withOrders) {
            // Covered: what is coming would lift it back above its reorder point.
            $tiles['covered_by_order'] = count(array_filter($low, fn ($f) => bccomp(
                bcadd($f['total'], $f['on_order'], 3),
                (string) $f['item']->reorder_at,
                3,
            ) > 0));
            $tiles['on_order_items'] = count(array_filter(
                $facts,
                fn ($f) => bccomp($f['on_order'], '0', 3) > 0,
            ));
            $tiles['open_orders'] = $openOrders;
        }

        return $tiles;
    }
}
