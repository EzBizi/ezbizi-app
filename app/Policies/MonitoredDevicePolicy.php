<?php

namespace App\Policies;

use App\Models\User;
use App\Support\Access\Refusal;
use Illuminate\Auth\Access\Response;

/**
 * Who may read the devices roster (2026-08-28-monitoring-pc-specs, Task 5),
 * and who may drive one remotely (2026-09-02-monitoring-remote-control).
 *
 * `view` only — nobody creates or edits a device by hand, it is a machine
 * reporting itself (see HeartbeatController). Reach itself is enforced by the
 * controller's query, the same split InventoryItemPolicy and JobPolicy make:
 * the policy answers "may you open this list at all", the query answers
 * "which rows are yours to see" (§3/§4). `specs` is a second, narrower grant
 * checked inline in the controller rather than here, because it gates a FIELD
 * on an otherwise-visible row rather than the row itself.
 *
 * `control` is a THIRD, separate grant, checked class-level the same way
 * `viewAny` is (no model instance needed — reach over a specific device is
 * still the controller's query, same split as above). Deliberately its own
 * permission rather than reusing `monitoring.screenshots.view`: seeing a
 * screen and taking over its mouse and keyboard are different sizes of
 * authority, and RemoteControlController checks this BEFORE resolving the
 * device so an org can withhold control from someone who can still watch.
 */
class MonitoredDevicePolicy
{
    public function viewAny(User $user): Response
    {
        return $user->hasPermission('monitoring.view')
            ? Response::allow()
            : Refusal::permission('see the devices roster');
    }

    public function control(User $user): Response
    {
        return $user->hasPermission('monitoring.control')
            ? Response::allow()
            : Refusal::permission('control this device');
    }
}
