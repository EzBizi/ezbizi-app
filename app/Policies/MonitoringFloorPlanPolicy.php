<?php

namespace App\Policies;

use App\Models\User;
use App\Support\Access\Refusal;
use Illuminate\Auth\Access\Response;

/**
 * Who may read and configure the org's floor plans — the Monitoring "General"
 * tab (docs/superpowers/specs/2026-09-09-monitoring-general-map-design.md).
 *
 * Two grants, mirroring MonitoringCameraPolicy's shape but splitting the read
 * from the existing module-wide gate: `monitoring.view` for the list and a
 * single plan (the same grant the devices roster and screenshots read side
 * use), `monitoring.map.manage` for create/update/delete — one write grant
 * rather than three, because nothing in the product separates "may add a plan"
 * from "may edit or remove one". The write methods are defined now though the
 * write endpoints land in Task 4; the policy is complete so those routes only
 * have to `authorize()` against it.
 *
 * Reach is NOT a policy concern here — the policy answers "does this role hold
 * the grant", and WHICH plans that role may then touch is decided by
 * `ScopeReach::constrain` in the controller (a plan outside the caller's reach
 * reads as 404, never 403, mirroring ScreenshotsController). So there is no
 * per-model reach check in these methods, the same division cameras uses.
 *
 * Registered by Laravel 13's policy auto-discovery from the class name
 * `App\Policies\MonitoringFloorPlanPolicy` matching `App\Models\MonitoringFloorPlan`.
 */
class MonitoringFloorPlanPolicy
{
    public function viewAny(User $user): Response
    {
        return $user->hasPermission('monitoring.view')
            ? Response::allow()
            : Refusal::permission("see this organization's floor plans");
    }

    public function create(User $user): Response
    {
        return $user->hasPermission('monitoring.map.manage')
            ? Response::allow()
            : Refusal::permission('add a floor plan');
    }

    public function update(User $user): Response
    {
        return $user->hasPermission('monitoring.map.manage')
            ? Response::allow()
            : Refusal::permission('change this floor plan');
    }

    public function delete(User $user): Response
    {
        return $user->hasPermission('monitoring.map.manage')
            ? Response::allow()
            : Refusal::permission('delete this floor plan');
    }
}
