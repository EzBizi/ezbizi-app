<?php

namespace App\Policies;

use App\Models\User;
use App\Support\Access\Refusal;
use Illuminate\Auth\Access\Response;

/**
 * Who may read a device's screen captures (2026-08-31-monitoring-screenshots,
 * Task 4).
 *
 * `viewAny` only, same shape as MonitoredDevicePolicy (2026-08-28-monitoring-pc-specs):
 * reach (§3) is enforced by ScreenshotsController's query through
 * `ScopeReach::constrainToVisiblePeople`, not here — the policy answers "may
 * you open this at all", the query answers "which rows are yours to see".
 * `show` calls `authorize('viewAny', MonitoringScreenshot::class)` too, the
 * same class-level gate `index` uses; the per-row reach check a direct id
 * could otherwise bypass is the controller's query, same as `index`'s.
 *
 * Registered by Laravel 13's policy auto-discovery — there is no
 * AuthServiceProvider in this codebase — from the class name
 * `App\Policies\MonitoringScreenshotPolicy` matching `App\Models\MonitoringScreenshot`.
 */
class MonitoringScreenshotPolicy
{
    public function viewAny(User $user): Response
    {
        return $user->hasPermission('monitoring.screenshots.view')
            ? Response::allow()
            : Refusal::permission('see monitoring screenshots');
    }
}
