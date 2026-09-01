<?php

namespace App\Policies;

use App\Models\User;
use App\Support\Access\Refusal;
use Illuminate\Auth\Access\Response;

/**
 * Who may read and configure the org's camera roster — generic-protocol v1
 * (docs/superpowers/specs/2026-09-01-monitoring-cameras-design.md).
 *
 * Two grants: `cameras.view` for the list, `cameras.manage` for
 * create/update/delete — one write grant rather than three, because nothing
 * in the product decision separates "may register a camera" from "may fix
 * or remove one". Same shape as MonitoringScreenshotPolicy's `viewAny`.
 *
 * No reach check (contrast ServiceAreaPolicy::within, which confirms the
 * caller can act on the row's franchise): a camera is an organization
 * asset, not data scoped to a person or a place beneath it, so the tenant
 * scope BelongsToTenant already applies is the only boundary — see
 * MonitoringCamera's class docblock.
 *
 * Registered by Laravel 13's policy auto-discovery (no AuthServiceProvider
 * in this codebase) from the class name `App\Policies\MonitoringCameraPolicy`
 * matching `App\Models\MonitoringCamera`.
 */
class MonitoringCameraPolicy
{
    public function viewAny(User $user): Response
    {
        return $user->hasPermission('monitoring.cameras.view')
            ? Response::allow()
            : Refusal::permission("see this organization's cameras");
    }

    public function create(User $user): Response
    {
        return $user->hasPermission('monitoring.cameras.manage')
            ? Response::allow()
            : Refusal::permission('add a camera');
    }

    public function update(User $user): Response
    {
        return $user->hasPermission('monitoring.cameras.manage')
            ? Response::allow()
            : Refusal::permission('change this camera');
    }

    public function delete(User $user): Response
    {
        return $user->hasPermission('monitoring.cameras.manage')
            ? Response::allow()
            : Refusal::permission('delete this camera');
    }
}
