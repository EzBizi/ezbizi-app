<?php

namespace App\Policies;

use App\Models\InspectionReport;
use App\Models\User;
use App\Support\Access\Refusal;
use Illuminate\Auth\Access\Response;

/**
 * Generated Reports. `inspection.view` to read; `inspection.archive` to hide.
 *
 * ── THE FRANCHISE RULE IS NOT IN THIS FILE ──────────────────────
 *
 * This policy answers permission and TENANT only. "Which branch may read this
 * report" is `InspectionReportsController::assertReach`, which every one of
 * show, pdf, archive and unarchive calls straight after `authorize`, and which
 * asks `InspectionReport::automationScope()` — the job's franchise, then the
 * customer's.
 *
 * This docblock used to state the franchise rule as though the policy applied
 * it ("A report whose job is outside reach is no such report"). It was true of
 * the module and not of this file, and a reader checking the claim here found
 * it asserted rather than implemented. Saying where the rule actually lives is
 * the whole of this note; if the reach check is ever moved INTO the policy,
 * delete it rather than leaving two places that both look authoritative.
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

        if ($report->organization_id !== $user->organization_id) {
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
