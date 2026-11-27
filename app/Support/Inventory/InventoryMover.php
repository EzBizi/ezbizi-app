<?php

namespace App\Support\Inventory;

use App\Models\Franchise;
use App\Models\InventoryAdjustment;
use App\Models\InventoryBalance;
use App\Models\InventoryIssue;
use App\Models\InventoryIssueLine;
use App\Models\InventoryItem;
use App\Models\InventoryLocation;
use App\Models\InventoryPurchaseOrder;
use App\Models\InventoryPurchaseOrderLine;
use App\Models\InventoryReceive;
use App\Models\InventoryReceiveLine;
use App\Models\InventorySale;
use App\Models\InventorySaleFulfillment;
use App\Models\InventorySaleFulfillmentLine;
use App\Models\InventorySaleLine;
use App\Models\InventoryTransfer;
use App\Models\InventoryTransferLine;
use App\Models\Job;
use App\Models\User;
use App\Support\Automations\EventEmitter;
use App\Support\Numbering\RecordNumberGenerator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Post a movement onto balances in one transaction (spec §8).
 *
 * Adjust is counted-as: the new on-hand, not a signed delta. A missing
 * balance row is zero. insertOrIgnore then lockForUpdate so two first
 * counts wait rather than both inserting.
 *
 * Transfer is A→B, posted on save. The number is allocated in the same
 * transaction as the balance writes so a refused line does not consume T-.
 *
 * Receive posts onto on-hand against an open PO. Over-receive and a closed
 * location are refused; partial leaves the PO open, full marks received.
 *
 * Fulfill takes stock off a location against an open sale. Over-fulfill
 * and a closed location are refused; partial leaves the sale open,
 * full marks fulfilled.
 *
 * Issue takes stock off a location. Job XOR a note; discontinued items
 * and closed locations are refused. Transfer refuses discontinued items
 * and closed from/onto. Receive refuses discontinued items. Sale
 * fulfillment refuses discontinued items. The number
 * is allocated in the same transaction so a refused line does not consume
 * IS- or T-.
 *
 * Each posted movement emits the matching item.* event on the InventoryItem
 * in the same transaction. last_cost on receive is not item.updated.
 */
final class InventoryMover
{
    public function __construct(
        private readonly RecordNumberGenerator $numbers,
        private readonly EventEmitter $events,
    ) {}

    public function adjust(
        InventoryLocation $location,
        InventoryItem $item,
        string $countedAs,
        string $reason,
        User $user,
    ): InventoryAdjustment {
        $counted = $this->scale($countedAs);

        if (bccomp($counted, '0', 3) < 0) {
            throw ValidationException::withMessages([
                'counted_as' => 'The counted as must be at least 0.',
            ]);
        }

        return DB::transaction(function () use ($location, $item, $counted, $reason, $user) {
            $this->ensureBalanceRow($item, $location);

            $balance = InventoryBalance::query()
                ->where('item_id', $item->getKey())
                ->where('location_id', $location->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            $before = $this->scale((string) $balance->qty_on_hand);

            if (bccomp($counted, $before, 3) === 0) {
                throw ValidationException::withMessages([
                    'counted_as' => 'On-hand is already that quantity.',
                ]);
            }

            $balance->qty_on_hand = $counted;
            $balance->save();

            $adjustment = InventoryAdjustment::create([
                'location_id' => $location->getKey(),
                'item_id' => $item->getKey(),
                'qty_before' => $before,
                'qty_after' => $counted,
                'reason' => $reason,
                'adjusted_on' => $this->calendarDayOn($location),
                'user_id' => $user->getKey(),
            ]);

            $this->events->emit('item.adjusted', $item, [
                'before' => ['qty_on_hand' => $before],
                'after' => ['qty_on_hand' => $counted],
            ]);

            return $adjustment;
        });
    }

    /**
     * Move qty of each line from A to B. Whole call rolls back if any
     * line cannot be covered.
     *
     * @param  list<array{item: InventoryItem, qty: string}>  $lines
     */
    public function transfer(
        InventoryLocation $from,
        InventoryLocation $to,
        array $lines,
        User $user,
    ): InventoryTransfer {
        return DB::transaction(function () use ($from, $to, $lines, $user) {
            $this->assertLocationOpen($from, 'from_location_id');
            $this->assertLocationOpen($to, 'to_location_id');

            $scope = $from->franchise ?? $from->company ?? $user->organization;
            $number = $this->numbers->next(RecordNumberGenerator::TRANSFERS, $scope);

            $transfer = InventoryTransfer::create([
                'from_location_id' => $from->getKey(),
                'to_location_id' => $to->getKey(),
                'number' => $number,
                'transferred_on' => $this->calendarDayOn($from),
                'user_id' => $user->getKey(),
            ]);

            foreach ($lines as $line) {
                $qty = $this->scale($line['qty']);

                if (bccomp($qty, '0', 3) <= 0) {
                    throw ValidationException::withMessages([
                        'qty' => 'The qty must be greater than 0.',
                    ]);
                }

                $this->assertItemCanMove($line['item']);
                $this->moveQty($line['item'], $from, $to, $qty);

                InventoryTransferLine::create([
                    'transfer_id' => $transfer->getKey(),
                    'item_id' => $line['item']->getKey(),
                    'qty' => $qty,
                ]);

                $this->events->emit('item.transferred', $line['item'], [
                    'after' => [
                        'qty' => $qty,
                        'from_location_id' => $from->getKey(),
                        'to_location_id' => $to->getKey(),
                    ],
                ]);
            }

            return $transfer;
        });
    }

    /**
     * Post qty against open PO lines into one location. Whole call rolls
     * back if any line would exceed what the order still owes.
     *
     * @param  list<array{line: InventoryPurchaseOrderLine, qty: string}>  $lines
     */
    public function receive(
        InventoryPurchaseOrder $order,
        InventoryLocation $location,
        array $lines,
        User $user,
    ): InventoryReceive {
        return DB::transaction(function () use ($order, $location, $lines, $user) {
            $order = InventoryPurchaseOrder::query()->lockForUpdate()->findOrFail($order->getKey());
            $this->assertLocationOpen($location);

            if ($order->status !== InventoryPurchaseOrderStatus::Open) {
                throw ValidationException::withMessages([
                    'status' => 'This order is not open.',
                ]);
            }

            $lockedLines = InventoryPurchaseOrderLine::query()
                ->where('purchase_order_id', $order->getKey())
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->keyBy(fn (InventoryPurchaseOrderLine $line) => (string) $line->getKey());

            $receive = InventoryReceive::create([
                'purchase_order_id' => $order->getKey(),
                'location_id' => $location->getKey(),
                'received_on' => $this->calendarDayOn($location),
                'received_by' => $user->getKey(),
            ]);

            foreach ($lines as $entry) {
                $qty = $this->scale($entry['qty']);

                if (bccomp($qty, '0', 3) <= 0) {
                    throw ValidationException::withMessages([
                        'qty' => 'The qty must be greater than 0.',
                    ]);
                }

                $lineId = (string) $entry['line']->getKey();
                $poLine = $lockedLines->get($lineId);

                if ($poLine === null) {
                    throw ValidationException::withMessages([
                        'purchase_order_line_id' => 'The selected purchase order line id is invalid.',
                    ]);
                }

                $ordered = $this->scale((string) $poLine->qty_ordered);
                $already = $this->scale((string) $poLine->qty_received);
                $remaining = bcsub($ordered, $already, 3);

                if (bccomp($qty, $remaining, 3) > 0) {
                    throw ValidationException::withMessages([
                        'qty' => 'This order does not have that many left to receive.',
                    ]);
                }

                $poLine->loadMissing('item');
                $item = $poLine->item ?? $poLine->item()->firstOrFail();
                $this->assertItemCanMove($item);
                $this->addQty($item, $location, $qty);

                $poLine->qty_received = bcadd($already, $qty, 3);
                $poLine->save();

                if ($poLine->unit_cost_minor !== null) {
                    $item->last_cost_minor = $poLine->unit_cost_minor;
                    $item->save();
                }

                InventoryReceiveLine::create([
                    'receive_id' => $receive->getKey(),
                    'purchase_order_line_id' => $poLine->getKey(),
                    'qty' => $qty,
                ]);

                $this->events->emit('item.received', $item, [
                    'after' => [
                        'qty' => $qty,
                        'location_id' => $location->getKey(),
                        'purchase_order_id' => $order->getKey(),
                    ],
                ]);
            }

            $stillOwes = $lockedLines->contains(
                function (InventoryPurchaseOrderLine $line) {
                    return bccomp(
                        $this->scale((string) $line->qty_received),
                        $this->scale((string) $line->qty_ordered),
                        3,
                    ) < 0;
                },
            );

            if (! $stillOwes) {
                $order->status = InventoryPurchaseOrderStatus::Received;
                $order->save();

                $seen = [];
                foreach ($lockedLines as $poLine) {
                    $poLine->loadMissing('item');
                    $item = $poLine->item ?? $poLine->item()->firstOrFail();
                    $id = $item->getKey();
                    if (isset($seen[$id])) {
                        continue;
                    }
                    $seen[$id] = true;
                    $this->events->emit('item.purchase_received', $item, [
                        'after' => [
                            'purchase_order_id' => $order->getKey(),
                        ],
                    ]);
                }
            }

            return $receive;
        });
    }

    /**
     * Take qty of each line off a location. Whole call rolls back if any
     * line cannot be covered. Does not write jobs columns.
     *
     * @param  list<array{item: InventoryItem, qty: string}>  $lines
     */
    public function issue(
        InventoryLocation $location,
        array $lines,
        User $user,
        ?Job $job = null,
        ?string $note = null,
    ): InventoryIssue {
        return DB::transaction(function () use ($location, $lines, $user, $job, $note) {
            $this->assertLocationOpen($location);

            $note = $note === null ? null : trim($note);
            if ($note === '') {
                $note = null;
            }

            if ($job === null && $note === null) {
                throw ValidationException::withMessages([
                    'job_id' => 'Say which job this is for, or why it left.',
                ]);
            }

            $scope = $location->franchise ?? $location->company ?? $user->organization;
            $number = $this->numbers->next(RecordNumberGenerator::ISSUES, $scope);

            $issue = InventoryIssue::create([
                'location_id' => $location->getKey(),
                'job_id' => $job?->getKey(),
                'note' => $note,
                'number' => $number,
                'issued_on' => $this->calendarDayOn($location),
                'user_id' => $user->getKey(),
            ]);

            foreach ($lines as $line) {
                $qty = $this->scale($line['qty']);

                if (bccomp($qty, '0', 3) <= 0) {
                    throw ValidationException::withMessages([
                        'qty' => 'The qty must be greater than 0.',
                    ]);
                }

                $item = $line['item'];

                $this->assertItemCanMove($item);
                $this->removeQty($item, $location, $qty);

                InventoryIssueLine::create([
                    'issue_id' => $issue->getKey(),
                    'item_id' => $item->getKey(),
                    'qty' => $qty,
                ]);

                $this->events->emit('item.issued', $item, [
                    'after' => [
                        'qty' => $qty,
                        'location_id' => $location->getKey(),
                        'job_id' => $job?->getKey(),
                        'note' => $note,
                    ],
                ]);
            }

            return $issue;
        });
    }

    /**
     * Fulfil qty of each sale line from a location. Whole call rolls back
     * if any line cannot be covered or would over-fulfil.
     *
     * @param  list<array{line: InventorySaleLine, qty: string}>  $lines
     */
    public function fulfillSale(
        InventorySale $sale,
        InventoryLocation $location,
        array $lines,
        User $user,
    ): InventorySaleFulfillment {
        return DB::transaction(function () use ($sale, $location, $lines, $user) {
            $sale = InventorySale::query()->lockForUpdate()->findOrFail($sale->getKey());
            $this->assertLocationOpen($location);

            if (! in_array($sale->status, [
                InventorySaleStatus::Open,
                InventorySaleStatus::Partial,
            ], true)) {
                throw ValidationException::withMessages([
                    'status' => 'This sale is not open.',
                ]);
            }

            $lockedLines = InventorySaleLine::query()
                ->where('sale_id', $sale->getKey())
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->keyBy(fn (InventorySaleLine $line) => (string) $line->getKey());

            $fulfillment = InventorySaleFulfillment::create([
                'sale_id' => $sale->getKey(),
                'location_id' => $location->getKey(),
                'fulfilled_on' => $this->calendarDayOn($location),
                'fulfilled_by' => $user->getKey(),
            ]);

            foreach ($lines as $entry) {
                $qty = $this->scale($entry['qty']);

                if (bccomp($qty, '0', 3) <= 0) {
                    throw ValidationException::withMessages([
                        'qty' => 'The qty must be greater than 0.',
                    ]);
                }

                $lineId = (string) $entry['line']->getKey();
                $saleLine = $lockedLines->get($lineId);

                if ($saleLine === null) {
                    throw ValidationException::withMessages([
                        'sale_line_id' => 'The selected sale line id is invalid.',
                    ]);
                }

                $ordered = $this->scale((string) $saleLine->qty_ordered);
                $already = $this->scale((string) $saleLine->qty_fulfilled);
                $remaining = bcsub($ordered, $already, 3);

                if (bccomp($qty, $remaining, 3) > 0) {
                    throw ValidationException::withMessages([
                        'qty' => 'This sale does not have that many left to fulfill.',
                    ]);
                }

                $saleLine->loadMissing('item');
                $item = $saleLine->item ?? $saleLine->item()->firstOrFail();
                $this->assertItemCanMove($item);
                $this->removeQty($item, $location, $qty);

                $saleLine->qty_fulfilled = bcadd($already, $qty, 3);
                $saleLine->save();

                InventorySaleFulfillmentLine::create([
                    'fulfillment_id' => $fulfillment->getKey(),
                    'sale_line_id' => $saleLine->getKey(),
                    'qty' => $qty,
                ]);

                $this->events->emit('item.sold', $item, [
                    'after' => [
                        'qty' => $qty,
                        'location_id' => $location->getKey(),
                        'sale_id' => $sale->getKey(),
                    ],
                ]);
            }

            $stillOwes = $lockedLines->contains(
                function (InventorySaleLine $line) {
                    return bccomp(
                        $this->scale((string) $line->qty_fulfilled),
                        $this->scale((string) $line->qty_ordered),
                        3,
                    ) < 0;
                },
            );

            $sale->status = $stillOwes
                ? InventorySaleStatus::Partial
                : InventorySaleStatus::Fulfilled;
            $sale->save();

            if (! $stillOwes) {
                $seen = [];
                foreach ($lockedLines as $saleLine) {
                    $saleLine->loadMissing('item');
                    $item = $saleLine->item ?? $saleLine->item()->firstOrFail();
                    $id = $item->getKey();
                    if (isset($seen[$id])) {
                        continue;
                    }
                    $seen[$id] = true;
                    $this->events->emit('item.sale_fulfilled', $item, [
                        'after' => [
                            'sale_id' => $sale->getKey(),
                        ],
                    ]);
                }
            }

            return $fulfillment;
        });
    }

    private function assertItemCanMove(InventoryItem $item): void
    {
        if ($item->status === InventoryItemStatus::Discontinued) {
            throw ValidationException::withMessages([
                'item_id' => 'This item is discontinued, so it cannot go out.',
            ]);
        }
    }

    private function assertLocationOpen(InventoryLocation $location, string $field = 'location_id'): void
    {
        if ($location->status === InventoryLocationStatus::Closed) {
            throw ValidationException::withMessages([
                $field => 'This location is closed.',
            ]);
        }
    }

    private function addQty(InventoryItem $item, InventoryLocation $location, string $qty): void
    {
        $this->ensureBalanceRow($item, $location);

        $balance = InventoryBalance::query()
            ->where('item_id', $item->getKey())
            ->where('location_id', $location->getKey())
            ->lockForUpdate()
            ->firstOrFail();

        $balance->qty_on_hand = bcadd($this->scale((string) $balance->qty_on_hand), $qty, 3);
        $balance->save();
    }

    private function removeQty(InventoryItem $item, InventoryLocation $location, string $qty): void
    {
        $this->ensureBalanceRow($item, $location);

        $balance = InventoryBalance::query()
            ->where('item_id', $item->getKey())
            ->where('location_id', $location->getKey())
            ->lockForUpdate()
            ->firstOrFail();

        $onHand = $this->scale((string) $balance->qty_on_hand);

        if (bccomp($onHand, $qty, 3) < 0) {
            throw ValidationException::withMessages([
                'qty' => 'There is not enough at this location.',
            ]);
        }

        $balance->qty_on_hand = bcsub($onHand, $qty, 3);
        $balance->save();
    }

    private function moveQty(
        InventoryItem $item,
        InventoryLocation $from,
        InventoryLocation $to,
        string $qty,
    ): void {
        $this->ensureBalanceRow($item, $from);
        $this->ensureBalanceRow($item, $to);

        $locationIds = [$from->getKey(), $to->getKey()];
        sort($locationIds);

        $locked = [];
        foreach ($locationIds as $locationId) {
            $locked[$locationId] = InventoryBalance::query()
                ->where('item_id', $item->getKey())
                ->where('location_id', $locationId)
                ->lockForUpdate()
                ->firstOrFail();
        }

        $fromBalance = $locked[$from->getKey()];
        $toBalance = $locked[$to->getKey()];
        $onHand = $this->scale((string) $fromBalance->qty_on_hand);

        if (bccomp($onHand, $qty, 3) < 0) {
            throw ValidationException::withMessages([
                'qty' => 'There is not enough at this location.',
            ]);
        }

        $fromBalance->qty_on_hand = bcsub($onHand, $qty, 3);
        $fromBalance->save();

        $toBalance->qty_on_hand = bcadd($this->scale((string) $toBalance->qty_on_hand), $qty, 3);
        $toBalance->save();
    }

    /** A missing (item, location) row is zero, not a gap that two writers can both insert. */
    private function ensureBalanceRow(InventoryItem $item, InventoryLocation $location): void
    {
        DB::table('inventory_balances')->insertOrIgnore([
            'id' => (string) Str::uuid7(),
            'organization_id' => $item->organization_id,
            'item_id' => $item->getKey(),
            'location_id' => $location->getKey(),
            'qty_on_hand' => '0.000',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function scale(string $qty): string
    {
        return bcadd($qty, '0', 3);
    }

    /**
     * Movement dates are the calendar day in the place’s timezone (spec §15):
     * the location’s franchise, else that company’s first franchise, else the
     * tenant’s first franchise. Not UTC midnight of `now()`.
     */
    private function calendarDayOn(InventoryLocation $location): string
    {
        $location->loadMissing('franchise');

        $tz = $location->franchise?->timezone;

        if (! filled($tz) && $location->company_id !== null) {
            $tz = Franchise::query()
                ->where('company_id', $location->company_id)
                ->orderBy('created_at')
                ->value('timezone');
        }

        if (! filled($tz)) {
            $tz = Franchise::query()->orderBy('created_at')->value('timezone');
        }

        return now(filled($tz) ? $tz : \App\Support\Settings\OrgTimezone::of())->toDateString();
    }
}
