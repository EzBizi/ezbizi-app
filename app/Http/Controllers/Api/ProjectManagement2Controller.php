<?php

namespace App\Http\Controllers\Api;

use App\Models\Job;
use App\Support\Jobs\WorkizMoney;
use App\Support\Scoping\ViewScope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Project Management - 2 (Jose, 2026-10-26): Project Management rebuilt from
 * scratch beside the first one, so the two can be compared. Step one is one
 * list: every job "done pending approval", in his columns.
 */
class ProjectManagement2Controller extends Controller
{
    public const STATUS = 'done pending approval';

    /** GET /api/v1/project-management-2/jobs */
    public function jobs(Request $request): JsonResponse
    {
        $user = $request->user();
        abort_unless(
            $user->hasPermission('project_management_2.view') && $user->hasPermission('jobs.view'),
            Response::HTTP_FORBIDDEN,
            'Project Management - 2 is not in your role.',
        );
        $money = $user->hasPermission('jobs.financials');

        // ponytail: no paging, like Sales' list of the same jobs. Page when it hurts.
        $jobs = Job::query()
            ->whereIn('franchise_id', ViewScope::forUser($user)->franchiseIds())
            ->whereRaw('LOWER(TRIM(status)) = ?', [self::STATUS])
            ->with([
                'franchise',
                'customer:id,name,address_line1,city,state,postal_code',
                'workizJob:id,job_id,serial_id,payload',
            ])
            ->latest()
            ->get();

        $labels = [];

        return response()->json(['data' => $jobs->map(function (Job $job) use ($money, &$labels) {
            $m = $money ? WorkizMoney::for($job) : null;

            return [
                'id'         => $job->id,
                'job_number' => $job->workizJob?->serial_id ?? $job->number,
                'customer'   => $job->customer?->name,
                'status'     => $labels[$job->franchise_id.'|'.$job->status] ??= $job->franchise
                    ? Job::labelFor($job->status, $job->franchise)
                    : Job::humanizeStatus($job->status),
                'tags'       => $job->tags ?? [],
                'address'    => $job->customer_address,
                // Financials only, and ABSENT without it, so the client draws no zero.
                // Pending is the job's total waiting on approval; owed is what is still due.
                ...($money ? [
                    'money_pending' => $m['total'] ?? $job->amount_minor,
                    'money_owed'    => $m['due'] ?? null,
                    'currency'      => $job->currency,
                ] : []),
            ];
        })->values()]);
    }
}
