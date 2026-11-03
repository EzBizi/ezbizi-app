<?php

namespace App\Http\Controllers\Api;

use App\Models\AutomationEvent;
use App\Models\Job;
use App\Models\ProjectManagementSubmission;
use App\Models\User;
use App\Support\Audit\AuditLogger;
use App\Support\Automations\EventEmitter;
use App\Support\Jobs\JobMoney;
use App\Support\Scoping\ViewScope;
use App\Support\Workiz\WorkizMirror;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
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

    public const SUBMITTED = 'submitted';

    public const TABS = ['pending', 'ready', 'submitted'];

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
        $input = $request->validate(['q' => ['nullable', 'string', 'max:200'], 'tab' => ['nullable', 'in:'.implode(',', self::TABS)]]);
        $term = trim((string) ($input['q'] ?? ''));
        $tab = $input['tab'] ?? 'pending';

        // q is the search box: customer name, job ID (Workiz's or ours) or address.
        $page = $this->tab($this->inView($user), $tab)
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
            // Each tab's count, for its label, without the search. First page only:
            // "Load more" changes no count, and the three cost ~370 ms (2026-11-02).
            'meta' => $this->pageMeta($page) + ($page->currentPage() > 1 ? [] : ['counts' => collect(self::TABS)
                ->mapWithKeys(fn (string $t) => [$t => $this->tab($this->inView($user), $t)->count()])->all()]),
        ]);
    }

    /**
     * POST /api/v1/project-management-2/jobs/{id}/submit (Jose, 2026-10-28):
     * a paid job done pending approval becomes Submitted, in EzBizi and, a
     * few seconds later through the mirror, in Workiz. The same trail as an
     * edit in the job panel: the audit row and the events, so rules and the
     * Submitted tab see it.
     */
    public function submit(Request $request, string $id, AuditLogger $audit, EventEmitter $events): JsonResponse
    {
        $user = $this->allowed($request);
        $job = $this->inView($user)->with(self::WITH)->findOrFail($id);
        $this->authorize('update', $job);
        abort_unless(mb_strtolower(trim((string) $job->status)) === self::STATUS, Response::HTTP_CONFLICT,
            'This job is no longer done pending approval.');
        abort_unless((JobMoney::paidCents([$job->id])[$job->id] ?? 0) > 0, Response::HTTP_CONFLICT,
            'Nothing has been paid on this job yet.');

        $before = $job->status;
        // The organization's own word for it (A-1's is Workiz's, "Submitted").
        $job->status = collect(Job::allowedStatuses($job->franchise))
            ->first(fn (string $s) => strcasecmp(trim($s), self::SUBMITTED) === 0) ?? 'Submitted';
        $change = ['before' => ['status' => $before], 'after' => ['status' => $job->status]];

        DB::transaction(function () use ($job, $change, $audit, $events) {
            $audit->logChange('job.updated', $job);
            $job->save();
            $events->emit('job.updated', $job, $change);
            $moved = $events->emit('job.status_changed', $job, $change);
            // On the Submitted tab at once, not at the next minute's copy.
            ProjectManagementSubmission::create(['job_id' => $job->id, 'automation_event_id' => $moved->id, 'submitted_at' => $moved->occurred_at]);
            WorkizMirror::queue($job, 'status', ['status' => 'Submitted']);
        });

        return response()->json(['data' => $this->row($job, $user->hasPermission('jobs.financials') ? JobMoney::of([$job->id]) : null)]);
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
        // The job's own birth and who opened it (Jobs, ebc76a0e8; Workiz's backfilled for a mirrored job).
        $timeline = collect([[
            'kind' => 'created',
            'at'   => $at($job->created_at),
            'what' => 'Job created',
            'by'   => $job->created_by_label,
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

    /**
     * Jose's three tabs (2026-10-28). Pending: every job done pending
     * approval. Ready for Submission: those with money paid, partly or in
     * full. Submitted: jobs now Submitted that came from done pending
     * approval, by this tab's button or in Workiz, latest first.
     */
    private function tab(Builder $jobs, string $tab): Builder
    {
        $dpa = fn (Builder $q) => $q->whereRaw('LOWER(TRIM(status)) = ?', [self::STATUS]);

        return match ($tab) {
            'pending' => $dpa($jobs),
            // ponytail: any succeeded payment row counts, so a job paid and refunded in full stays; net it here if that turns up.
            'ready' => $dpa($jobs)->whereIn('id', JobMoney::paidJobIds()),
            // Read from the stored submissions, kept for good (Jose, 2026-11-02), not the
            // events, which expire in 3 months. One grouped pass, joined: a subquery per
            // row took 2.1 s for this tab on 2026-11-02.
            'submitted' => $jobs->whereRaw('LOWER(TRIM(jobs.status)) = ?', [self::SUBMITTED])
                ->joinSub(ProjectManagementSubmission::query()->groupBy('job_id')->selectRaw('job_id, MAX(submitted_at) AS submitted_at'),
                    'submitted', 'submitted.job_id', '=', 'jobs.id')
                ->select('jobs.*')
                ->orderByDesc('submitted.submitted_at'),
        };
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
            // The job's own birth, on the branch's calendar. Workiz has no edit
            // time, so modified is its last status change.
            'created'    => $job->created_at?->copy()->setTimezone($job->franchise?->timezone ?: 'UTC')->toDateString(),
            'modified'   => $job->workizJob?->last_status_update?->toDateString()
                ?? $job->updated_at?->toDateString(),
            // Financials only, and ABSENT without it, so the client draws no zero.
            // No price reads $0 (Jose, 2026-11-02: "i'd rather see 0").
            ...($money !== null ? [
                'pending'  => $m['due'],
                'paid'     => $m['paid'],
                'total'    => $m['total'] ?? 0,
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
