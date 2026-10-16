<?php

namespace App\Http\Controllers\Api;

use App\Models\InventoryAdjustment;
use App\Models\InventoryIssueLine;
use App\Models\InventoryItem;
use App\Models\InventoryLocation;
use App\Models\InventoryReceiveLine;
use App\Models\InventorySaleFulfillmentLine;
use App\Models\InventoryTransferLine;
use App\Models\User;
use App\Support\Inventory\InventoryVisibility;
use App\Support\Scoping\ViewScope;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * One item's History: every receive, issue, transfer, adjustment and sale
 * fulfilment that moved it, newest first (UI queue 43, gallery 1 "An item
 * open"; spec from UI/UX 2026-10-01).
 *
 * Gated on `inventory.stock.view` by the route — this is quantities, the same
 * fact `on_hand` carries — and on the item being in reach by the policy.
 *
 * ── Five tables, five shapes ─────────────────────────────────────
 *
 * Receives reach their item through the purchase-order line and sale
 * fulfilments through the sale line; issues and transfers carry it on the
 * line; an adjustment carries it on the header and records a before and an
 * after rather than a quantity. Each is read on its own terms and mapped into
 * one row shape, rather than unioned in SQL across tables that disagree.
 *
 * ── Places the caller cannot open ────────────────────────────────
 *
 * A movement is listed when its place is visible. A TRANSFER touches two, so it
 * is listed when either end is, and the end the caller cannot open is sent as
 * null: dropping the row would make stock appear in a visible place from
 * nowhere, and naming the hidden end would leak a place the role was not given.
 *
 * ponytail: reads one item's whole history and pages it in PHP. One item's
 * movements are small; move the merge into SQL if an item's history is not.
 */
class InventoryMovementsController extends Controller
{
    /** GET /api/v1/inventory/items/{item}/movements */
    public function index(Request $request, InventoryItem $item): JsonResponse
    {
        $this->authorize('view', $item);

        $user = $request->user();
        $scope = ViewScope::forUser($user);
        $withMoney = $user->hasPermission('inventory.financials');

        $visible = InventoryVisibility::constrain(InventoryLocation::query(), $user, $scope)
            ->pluck('id')
            ->flip();

        $rows = [
            ...$this->received($item, $visible, $withMoney),
            ...$this->issued($item, $visible),
            ...$this->transferred($item, $visible),
            ...$this->adjusted($item, $visible),
            ...$this->sold($item, $visible),
        ];

        // Newest day first; within a day, the one recorded last first.
        usort($rows, fn (array $a, array $b) => [$b['occurred_at'], $b['_recorded']] <=> [$a['occurred_at'], $a['_recorded']]);
        $rows = array_map(function (array $row) {
            unset($row['_recorded']);

            return $row;
        }, $rows);

        $perPage = min(max((int) $request->query('per_page', 50), 1), 200);
        $page = max((int) $request->query('page', 1), 1);
        $total = count($rows);

        return response()->json([
            'data' => array_slice($rows, ($page - 1) * $perPage, $perPage),
            'meta' => [
                'total' => $total,
                'per_page' => $perPage,
                'current_page' => $page,
                'last_page' => max(1, (int) ceil($total / $perPage)),
            ],
        ]);
    }

    /** @param  \Illuminate\Support\Collection<string, int>  $visible */
    private function received(InventoryItem $item, $visible, bool $withMoney): array
    {
        return InventoryReceiveLine::query()
            ->whereHas('purchaseOrderLine', fn (Builder $q) => $q->where('item_id', $item->id))
            ->whereHas('receive', fn (Builder $q) => $q->whereIn('location_id', $visible->keys()))
            ->with(['receive.location', 'receive.purchaseOrder', 'receive.receivedBy', 'purchaseOrderLine'])
            ->get()
            ->map(function (InventoryReceiveLine $line) use ($withMoney) {
                $receive = $line->receive;
                $po = $receive->purchaseOrder;

                $row = $this->row(
                    kind: 'received',
                    on: $receive->received_on,
                    recorded: $receive->created_at,
                    qty: bcadd((string) $line->qty, '0', 3),
                    location: $receive->location,
                    reference: $po === null ? null : ['type' => 'purchase_order', 'id' => $po->id, 'number' => $po->number],
                    note: null,
                    by: $receive->receivedBy,
                );

                if ($withMoney) {
                    $row['unit_cost_minor'] = $line->purchaseOrderLine?->unit_cost_minor;
                }

                return $row;
            })
            ->all();
    }

    private function issued(InventoryItem $item, $visible): array
    {
        return InventoryIssueLine::query()
            ->where('item_id', $item->id)
            ->whereHas('issue', fn (Builder $q) => $q->whereIn('location_id', $visible->keys()))
            ->with(['issue.location', 'issue.job', 'issue.user'])
            ->get()
            ->map(function (InventoryIssueLine $line) {
                $issue = $line->issue;
                $job = $issue->job;

                return $this->row(
                    kind: 'issued',
                    on: $issue->issued_on,
                    recorded: $issue->created_at,
                    qty: bcsub('0', (string) $line->qty, 3),
                    location: $issue->location,
                    // Issued to a job names the job; issued with a note names nothing
                    // and carries the note instead.
                    reference: $job === null ? null : ['type' => 'job', 'id' => $job->id, 'number' => $job->number],
                    note: $issue->note,
                    by: $issue->user,
                );
            })
            ->all();
    }

    private function transferred(InventoryItem $item, $visible): array
    {
        $ids = $visible->keys();

        return InventoryTransferLine::query()
            ->where('item_id', $item->id)
            ->whereHas('transfer', fn (Builder $q) => $q->where(fn (Builder $either) => $either
                ->whereIn('from_location_id', $ids)
                ->orWhereIn('to_location_id', $ids)))
            ->with(['transfer.fromLocation', 'transfer.toLocation', 'transfer.user'])
            ->get()
            ->map(function (InventoryTransferLine $line) use ($visible) {
                $transfer = $line->transfer;

                $row = $this->row(
                    kind: 'transferred',
                    on: $transfer->transferred_on,
                    recorded: $transfer->created_at,
                    qty: bcadd((string) $line->qty, '0', 3),
                    location: $visible->has($transfer->from_location_id) ? $transfer->fromLocation : null,
                    reference: ['type' => 'transfer', 'id' => $transfer->id, 'number' => $transfer->number],
                    note: null,
                    by: $transfer->user,
                );
                $row['to_location'] = $visible->has($transfer->to_location_id)
                    ? $this->place($transfer->toLocation)
                    : null;

                return $row;
            })
            ->all();
    }

    private function adjusted(InventoryItem $item, $visible): array
    {
        return InventoryAdjustment::query()
            ->where('item_id', $item->id)
            ->whereIn('location_id', $visible->keys())
            ->with(['location', 'user'])
            ->get()
            ->map(fn (InventoryAdjustment $adjustment) => $this->row(
                kind: 'adjusted',
                on: $adjustment->adjusted_on,
                recorded: $adjustment->created_at,
                // Signed: a count that came in low reads as a negative change.
                qty: bcsub((string) $adjustment->qty_after, (string) $adjustment->qty_before, 3),
                location: $adjustment->location,
                // An adjustment has no number to show; the id still opens it.
                reference: ['type' => 'adjustment', 'id' => $adjustment->id, 'number' => null],
                note: $adjustment->reason,
                by: $adjustment->user,
            ))
            ->all();
    }

    private function sold(InventoryItem $item, $visible): array
    {
        return InventorySaleFulfillmentLine::query()
            ->whereHas('saleLine', fn (Builder $q) => $q->where('item_id', $item->id))
            ->whereHas('fulfillment', fn (Builder $q) => $q->whereIn('location_id', $visible->keys()))
            ->with(['fulfillment.location', 'fulfillment.sale', 'fulfillment.user'])
            ->get()
            ->map(function (InventorySaleFulfillmentLine $line) {
                $fulfillment = $line->fulfillment;
                $sale = $fulfillment->sale;

                return $this->row(
                    kind: 'sold',
                    on: $fulfillment->fulfilled_on,
                    recorded: $fulfillment->created_at,
                    qty: bcsub('0', (string) $line->qty, 3),
                    location: $fulfillment->location,
                    reference: $sale === null ? null : ['type' => 'sale', 'id' => $sale->id, 'number' => $sale->number],
                    note: null,
                    by: $fulfillment->user,
                );
            })
            ->all();
    }

    /** @return array<string, mixed> */
    private function row(
        string $kind,
        mixed $on,
        mixed $recorded,
        string $qty,
        ?InventoryLocation $location,
        ?array $reference,
        ?string $note,
        ?User $by,
    ): array {
        return [
            // The date the business recorded, as a calendar day. These columns
            // are `date`, so there is no instant to convert and no zone to cross.
            'occurred_at' => $on === null ? null : Carbon::parse($on)->toDateString(),
            '_recorded' => $recorded === null ? '' : Carbon::parse($recorded)->toIso8601String(),
            'kind' => $kind,
            'qty' => $qty,
            'location' => $this->place($location),
            'to_location' => null,
            'reference' => $reference,
            'note' => $note,
            'by' => $by === null ? null : ['id' => $by->id, 'name' => $by->name],
        ];
    }

    /** @return array{id:string,name:string,kind:?string}|null */
    private function place(?InventoryLocation $location): ?array
    {
        return $location === null ? null : [
            'id' => $location->id,
            'name' => $location->name,
            'kind' => $location->kind?->value,
        ];
    }
}
