<?php

namespace App\Support\Inventory;

use App\Models\Franchise;
use App\Models\InventoryAdjustment;
use App\Models\InventoryBalance;
use App\Models\InventoryItem;
use App\Models\InventoryLocation;
use App\Models\InventoryTransfer;
use App\Models\InventoryTransferLine;
use App\Models\User;
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
 */
final class InventoryMover
{
    public function __construct(
        private readonly RecordNumberGenerator $numbers,
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

            return InventoryAdjustment::create([
                'location_id' => $location->getKey(),
                'item_id' => $item->getKey(),
                'qty_before' => $before,
                'qty_after' => $counted,
                'reason' => $reason,
                'adjusted_on' => $this->calendarDayOn($location),
                'user_id' => $user->getKey(),
            ]);
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

                $this->moveQty($line['item'], $from, $to, $qty);

                InventoryTransferLine::create([
                    'transfer_id' => $transfer->getKey(),
                    'item_id' => $line['item']->getKey(),
                    'qty' => $qty,
                ]);
            }

            return $transfer;
        });
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

        return now(filled($tz) ? $tz : 'America/New_York')->toDateString();
    }
}
