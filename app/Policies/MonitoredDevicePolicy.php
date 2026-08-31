<?php

namespace App\Policies;

use App\Models\User;
use App\Support\Access\Refusal;
use Illuminate\Auth\Access\Response;

/**
 * Who may read the devices roster (2026-08-28-monitoring-pc-specs, Task 5).
 *
 * `view` only — nobody creates or edits a device by hand, it is a machine
 * reporting itself (see HeartbeatController). Reach itself is enforced by the
 * controller's query, the same split InventoryItemPolicy and JobPolicy make:
 * the policy answers "may you open this list at all", the query answers
 * "which rows are yours to see" (§3/§4). `specs` is a second, narrower grant
 * checked inline in the controller rather than here, because it gates a FIELD
 * on an otherwise-visible row rather than the row itself.
 */
class MonitoredDevicePolicy
{
    public function viewAny(User $user): Response
    {
        return $user->hasPermission('monitoring.view')
            ? Response::allow()
            : Refusal::permission('see the devices roster');
    }
}
