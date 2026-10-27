<?php

namespace App\Http\Controllers\Api;

use App\Models\AutomationEvent;
use App\Models\Job;
use App\Models\User;
use App\Support\Jobs\JobMoney;
use App\Support\Scoping\ViewScope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Symfony\Component\HttpFoundation\Response;

/**
 * Project Management - 2 (Jose, 2026-10-26): Project Management rebuilt from
 * scratch beside the first one, so the two can be compared. Step one is one
 * list: every job "done pending approval", in his columns, and a job opened
 * from it with what happened to it (2026-10-27).
 */
class ProjectManagement2Controller extends Controller
{
    public const STATUS = 'done pending approval';

    private const WITH = [
        'franchise.company:id,name',
        'customer:id,name,address_line1,city,state,postal_code',
        'workizJob:id,job_id,serial_id,last_status_update,payload',
    ];

    /** @var array<string, string> status labels, per branch */
    private array $labels = [];

    /** GET /api/v1/project-management-2/jobs */
    public function jobs(Request $request): JsonResponse
    {
        $user = $this->allowed($request);
        $term = trim((string) ($request->validate(['q' => ['nullable', 'string', 'max:200']])['q'] ?? ''));

        // q is the search box: customer name, job ID (Workiz's or ours) or address.
        $page = $this->inView($user)
            ->whereRaw('LOWER(TRIM(status)) = ?', [self::STATUS])
            ->when($term !== '', fn ($q) => $q->where(fn ($q) => $q
                ->where('number', 'like', "%{$term}%")
                ->orWhereHas('workizJob', fn ($w) => $w->where('serial_id', 'like', "%{$term}%"))
                ->orWhereHas('customer', fn ($c) => $c->where(fn ($c) => $c
                    ->where('name', 'like', "%{$term}%")
                    ->orWhere('address_line1', 'like', "%{$term}%")
                    ->orWhere('city', 'like', "%{$term}%")
                    ->orWhere('postal_code', 'like', "%{$term}%")))))
            ->with(self::WITH)
            ->latest()
            ->paginate(perPage: min((int) $request->query('per_page', 50), 200));
        $jobs = $page->getCollection();
        $money = $user->hasPermission('jobs.financials') ? JobMoney::of($jobs->pluck('id')) : null;

        return response()->json([
            'data' => $jobs->map(fn (Job $job) => $this->row($job, $money))->values(),
            'meta' => $this->pageMeta($page),
        ]);
    }

    /**
     * GET /api/v1/project-management-2/jobs/{id}: the row again, and its
     * timeline, oldest first. Any job in view, not only the ones done pending
     * approval: the list it was opened from may be a minute old.
     */
    public function job(Request $request, string $id): JsonResponse
    {
        $user = $this->allowed($request);
        $job = $this->inView($user)->with(self::WITH)->findOrFail($id);
        $money = $user->hasPermission('jobs.financials') ? JobMoney::of([$job->id]) : null;

        $at = fn (?Carbon $t) => $t?->toIso8601String();
        // ponytail: Workiz's payload until Jobs stores the job's own created date and creator (asked 2026-10-27).
        $created = $job->workizJob?->payload['CreatedDate'] ?? null;
        $timeline = collect([[
            'kind' => 'created',
            'at'   => $created ? Carbon::parse($created, $job->franchise?->timezone ?: 'UTC')->toIso8601String() : $at($job->created_at),
            'what' => 'Job created',
            'by'   => is_string($job->workizJob?->payload['CreatedBy'] ?? null) ? $job->workizJob->payload['CreatedBy'] : null,
        ]]);

        // EzBizi's own estimates and Workiz's, and the payments, as JobMoney reads them.
        $amount = fn (array $r) => $money === null ? [] : ['amount' => $r['cents']];
        foreach (JobMoney::estimates([$job->id])[$job->id] ?? [] as $e) {
            $timeline->push(['kind' => 'estimate', 'at' => $at($e['at']),
                'what' => trim('Estimate '.ucfirst((string) $e['status']))] + $amount($e));
        }
        foreach (JobMoney::payments([$job->id])[$job->id] ?? [] as $p) {
            $timeline->push(['kind' => 'payment', 'at' => $at($p['at']),
                'what' => $p['cents'] < 0 ? 'Refund' : 'Payment'] + $amount($p));
        }

        $events = AutomationEvent::query()
            ->where('subject_type', Job::class)
            ->where('subject_id', $job->id)
            ->whereIn('event_key', ['job.status_changed', 'job.updated'])
            ->orderBy('occurred_at')
            ->get();
        foreach ($events as $e) {
            $before = $e->payload['before'] ?? [];
            $after = $e->payload['after'] ?? [];
            if ($e->event_key === 'job.status_changed') {
                $what = 'Status '.$this->label($before['status'] ?? null, $job).' → '.$this->label($after['status'] ?? null, $job);
            } elseif (array_key_exists('tags', $after)) {
                $added = array_diff((array) $after['tags'], (array) ($before['tags'] ?? []));
                $removed = array_diff((array) ($before['tags'] ?? []), (array) $after['tags']);
                $what = trim('Tags '.implode(' ', [...array_map(fn ($t) => "+{$t}", $added), ...array_map(fn ($t) => "−{$t}", $removed)]));
            } else {
                continue;
            }
            $timeline->push(['kind' => $e->event_key === 'job.status_changed' ? 'status' : 'tags',
                'at' => $at($e->occurred_at), 'what' => $what, 'by' => $e->actor_label]);
        }

        return response()->json(['data' => $this->row($job, $money) + [
            'timeline' => $timeline->filter(fn ($s) => $s['at'] !== null)->sortBy('at')->values(),
        ]]);
    }

    private function allowed(Request $request): User
    {
        $user = $request->user();
        abort_unless(
            $user->hasPermission('project_management_2.view') && $user->hasPermission('jobs.view'),
            Response::HTTP_FORBIDDEN,
            'Project Management - 2 is not in your role.',
        );

        return $user;
    }

    private function inView(User $user): Builder
    {
        return Job::query()->whereIn('franchise_id', ViewScope::forUser($user)->franchiseIds());
    }

    private function row(Job $job, ?array $money): array
    {
        // Total, Paid and Due from JobMoney, the one definition both Project
        // Managements read (Jose, 2026-10-27: "Pending, Paid, Total").
        $m = $money[$job->id] ?? ['total' => null, 'paid' => 0, 'due' => 0];

        return [
            'id'         => $job->id,
            'job_number' => $job->workizJob?->serial_id ?? $job->number,
            'customer'   => $job->customer?->name,
            'branch'     => $job->franchise?->name,
            'company'    => $job->franchise?->company?->name,
            'status'     => $this->label($job->status, $job),
            'tags'       => $job->tags ?? [],
            'address'    => $job->customer_address,
            // Workiz's own dates: jobs.created_at is when the sync copied the
            // job in. Workiz has no edit time, so modified is its last status change.
            'created'    => substr((string) ($job->workizJob?->payload['CreatedDate'] ?? ''), 0, 10)
                ?: $job->created_at?->toDateString(),
            'modified'   => $job->workizJob?->last_status_update?->toDateString()
                ?? $job->updated_at?->toDateString(),
            // Financials only, and ABSENT without it, so the client draws no zero.
            // Null where there is no price, so it draws no zero there either.
            ...($money !== null ? [
                'pending'  => $m['total'] === null ? null : $m['due'],
                'paid'     => $m['total'] === null && $m['paid'] === 0 ? null : $m['paid'],
                'total'    => $m['total'],
                'currency' => $job->currency,
            ] : []),
        ];
    }

    private function label(?string $status, Job $job): string
    {
        if ($status === null || $status === '') {
            return '—';
        }

        return $this->labels[$job->franchise_id.'|'.$status] ??= $job->franchise
            ? Job::labelFor($status, $job->franchise)
            : Job::humanizeStatus($status);
    }
}
