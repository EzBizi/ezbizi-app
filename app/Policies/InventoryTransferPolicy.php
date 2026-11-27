<?php

namespace App\Policies;

use App\Models\InventoryLocation;
use App\Models\User;
use Illuminate\Auth\Access\Response;

/**
 * Who may post a transfer (spec §8.2).
 *
 * From: the caller can update the location (inventory.edit + write reach).
 * To: visible, and not a sibling company’s warehouse. Organization
 * destinations and General/franchise of the from-location’s company are
 * allowed.
 */
class InventoryTransferPolicy
{
    public function create(User $user, InventoryLocation $from, InventoryLocation $to): Response
    {
        $fromWrite = app(InventoryLocationPolicy::class)->update($user, $from);

        if ($fromWrite->denied()) {
            return $fromWrite;
        }

        $toView = app(InventoryLocationPolicy::class)->view($user, $to);

        if ($toView->denied()) {
            return $toView;
        }

        if ($this->isSiblingCompany($from, $to)) {
            return Response::deny('Stock does not move to a company you do not write.');
        }

        return Response::allow();
    }

    /**
     * B is a sibling when it has a company and that company is not A's.
     * Organization locations (company_id null) are not a sibling company.
     */
    private function isSiblingCompany(InventoryLocation $from, InventoryLocation $to): bool
    {
        // An organization-level source is no sibling of anything: refusing it
        // meant org stock could never reach a company (audit 11-27).
        if ($to->company_id === null || $from->company_id === null) {
            return false;
        }

        return $from->company_id !== $to->company_id;
    }
}
