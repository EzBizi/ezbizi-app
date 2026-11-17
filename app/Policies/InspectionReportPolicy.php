<?php

namespace App\Policies;

use App\Models\InspectionReport;
use App\Models\User;
use App\Support\Access\Refusal;
use Illuminate\Auth\Access\Response;

/**
 * Generated Reports. `inspection.view` to read; `inspection.archive` to hide.
 *
 * `view` also applies the franchise rule, InspectionReport::inReachOf (the
 * report's franchise must be in the current view), so a door that relies on
 * the policy alone (GET attachments/{id} for a report's photographs) is held
 * by it too. The controller's assertReach asks the same method.
 */
class InspectionReportPolicy
{
    public function viewAny(User $user): Response
    {
        return $user->hasPermission('inspection.view')
            ? Response::allow()
            : Refusal::permission('see inspection reports');
    }

    public function view(User $user, InspectionReport $report): Response
    {
        if (! $user->hasPermission('inspection.view')) {
            return Refusal::permission('see inspection reports');
        }

        if ($report->organization_id !== $user->organization_id || ! $report->inReachOf($user)) {
            return Response::denyAsNotFound();
        }

        return Response::allow();
    }

    public function update(User $user, InspectionReport $report): Response
    {
        return $this->archive($user, $report);
    }

    public function archive(User $user, InspectionReport $report): Response
    {
        if ($report->organization_id !== $user->organization_id) {
            return Response::denyAsNotFound();
        }

        if (! $user->hasPermission('inspection.archive')) {
            return Refusal::permission('archive an inspection report');
        }

        return Response::allow();
    }
}
