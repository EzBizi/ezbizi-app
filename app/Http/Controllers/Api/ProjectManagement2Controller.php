<?php

namespace App\Http\Controllers\Api;

use App\Models\Job;
use App\Models\WorkizRecord;
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
        $term = trim((string) ($request->validate(['q' => ['nullable', 'string', 'max:200']])['q'] ?? ''));

        // q is the search box: customer name, job ID (Workiz's or ours) or address.
        $page = Job::query()
            ->whereIn('franchise_id', ViewScope::forUser($user)->franchiseIds())
            ->whereRaw('LOWER(TRIM(status)) = ?', [self::STATUS])
            ->when($term !== '', fn ($q) => $q->where(fn ($q) => $q
                ->where('number', 'like', "%{$term}%")
                ->orWhereHas('workizJob', fn ($w) => $w->where('serial_id', 'like', "%{$term}%"))
                ->orWhereHas('customer', fn ($c) => $c->where(fn ($c) => $c
                    ->where('name', 'like', "%{$term}%")
                    ->orWhere('address_line1', 'like', "%{$term}%")
                    ->orWhere('city', 'like', "%{$term}%")
                    ->orWhere('postal_code', 'like', "%{$term}%")))))
            ->with([
                'franchise.company:id,name',
                'customer:id,name,address_line1,city,state,postal_code',
                'workizJob:id,job_id,serial_id,last_status_update,payload',
            ])
            ->latest()
            ->paginate(perPage: min((int) $request->query('per_page', 50), 200));
        $jobs = $page->getCollection();

        // Workiz's job total is empty on almost every one of these (36 of A-1's
        // 1,711 on 2026-10-26); the price waiting on approval is on its estimate
        // (909). The latest estimate still in play, not a sum: several are often
        // options for one job.
        $estimates = $money ? WorkizRecord::query()
            ->whereIn('job_id', $jobs->pluck('id'))
            ->where('type', 'estimate')
            ->where(fn ($q) => $q->whereNull('status')->orWhereNotIn('status', ['Archived', 'Declined']))
            ->orderBy('starts_at')
            ->get(['job_id', 'amount'])
            ->keyBy('job_id') : collect();

        $labels = [];

        return response()->json(['data' => $jobs->map(function (Job $job) use ($money, $estimates, &$labels) {
            $m = $money ? WorkizMoney::for($job) : null;

            return [
                'id'         => $job->id,
                'job_number' => $job->workizJob?->serial_id ?? $job->number,
                'customer'   => $job->customer?->name,
                'branch'     => $job->franchise?->name,
                'company'    => $job->franchise?->company?->name,
                'status'     => $labels[$job->franchise_id.'|'.$job->status] ??= $job->franchise
                    ? Job::labelFor($job->status, $job->franchise)
                    : Job::humanizeStatus($job->status),
                'tags'       => $job->tags ?? [],
                'address'    => $job->customer_address,
                // Workiz's own dates: jobs.created_at is when the sync copied the
                // job in. Workiz has no edit time, so modified is its last status change.
                'created'    => substr((string) ($job->workizJob?->payload['CreatedDate'] ?? ''), 0, 10)
                    ?: $job->created_at?->toDateString(),
                'modified'   => $job->workizJob?->last_status_update?->toDateString()
                    ?? $job->updated_at?->toDateString(),
                // Financials only, and ABSENT without it, so the client draws no zero.
                // Pending is the estimate waiting on approval; owed is what is still due.
                ...($money ? [
                    'money_pending' => $estimates->has($job->id)
                        ? (int) round((float) $estimates[$job->id]->amount * 100)
                        : ($m['total'] ?? $job->amount_minor),
                    'money_owed'    => $m['due'] ?? null,
                    'currency'      => $job->currency,
                ] : []),
            ];
        })->values(), 'meta' => $this->pageMeta($page)]);
    }
}
