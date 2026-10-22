<?php

namespace App\Http\Controllers\Api;

use App\Models\AutomationEvent;
use App\Models\AutomationRun;
use App\Models\Job;
use App\Models\WorkizRecord;
use App\Support\Jobs\EstimateAging;
use App\Support\Jobs\WorkizMoney;
use App\Support\Scoping\ViewScope;
use App\Support\Settings\SettingsResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\Response;

/**
 * Project Management (Jose, 2026-10-21: "build it, but don't activate it yet").
 *
 * Every job in a status the organization says waits on an estimate
 * (jobs.estimate_aging_statuses), and the ones that left such a status in the
 * last 60 days, each as a timeline: when it entered, payments, tag and status
 * changes with who or what made them, and what comes next. Read from the job's
 * own event stream and Workiz's payment records, so nothing is A-1-specific.
 * Amounts only with Jobs Financials.
 */
class JobsProjectManagementController extends Controller
{
    private const RECENT_DAYS = 60;

    /** GET /api/v1/jobs/project-management */
    public function index(Request $request, SettingsResolver $settings): JsonResponse
    {
        $user = $request->user();
        abort_unless($user->hasPermission('project_management.view') && $user->hasPermission('jobs.view'), Response::HTTP_FORBIDDEN, 'Project Management is not in your role.');

        $input = $request->validate([
            'stage' => ['nullable', Rule::in(['waiting', 'partial', 'full', 'closed'])],
            'franchise_id' => ['nullable', 'uuid'],
            'min_days' => ['nullable', 'integer', 'min:0'],
            'q' => ['nullable', 'string', 'max:100'],
            // A dashboard stat's drill-down: these jobs, wherever they stand.
            'ids' => ['nullable', 'array', 'max:500'],
            'ids.*' => ['uuid'],
            // Jose, 2026-10-22: "Recently Paid", jobs with a payment in the window.
            'filter' => ['nullable', Rule::in(['pending', 'recently_paid'])],
            'from' => ['nullable', 'required_if:filter,recently_paid', 'date_format:Y-m-d'],
            'to' => ['nullable', 'required_if:filter,recently_paid', 'required_with:event', 'date_format:Y-m-d'],
            // A stat's drill-down by name, so 514 jobs need no 514 ids in the URL (Jose, 2026-10-22).
            'event' => ['nullable', Rule::in(['payments', 'status_changes', 'tag_changes', 'cancellations', 'deadlines_due'])],
            'timezone' => ['nullable', 'timezone'],
        ]);
        if (filled($input['event'] ?? null)) {
            $request->validate(['from' => ['required']]);
            $input['ids'] = $this->buckets($user, $settings, $input)[$input['event']] ?: ['00000000-0000-0000-0000-000000000000'];
        }

        $money = $user->hasPermission('jobs.financials');
        $watched = (array) $settings->get('jobs.estimate_aging_statuses', $user->organization);
        // Only the stage lists need watched statuses; a stat's jobs and Recently Paid
        // stand without them (2026-10-22: a cleared setting emptied every drill-down).
        if ($watched === [] && ! isset($input['ids']) && ($input['filter'] ?? null) !== 'recently_paid') {
            return response()->json(['data' => [], 'meta' => ['watched' => [], 'deadline_days' => EstimateAging::DAYS]]);
        }

        $left = AutomationEvent::query()->where('event_key', 'job.status_changed')->where('subject_type', Job::class)
            ->where('occurred_at', '>=', now()->subDays(self::RECENT_DAYS))
            ->whereIn(\DB::raw("JSON_UNQUOTE(JSON_EXTRACT(payload, '$.before.status'))"), $watched)
            ->pluck('subject_id');

        $base = fn () => Job::query()
            ->whereIn('franchise_id', ViewScope::forUser($user)->franchiseIds())
            ->when($input['franchise_id'] ?? null, fn ($q, $id) => $q->where('franchise_id', $id))
            // Jose, 2026-10-21: "search bar ... to find specific stuff". Job and
            // Workiz numbers, the customer's name, phone and address, the tech.
            ->when(filled($input['q'] ?? null), function ($q) use ($input) {
                $like = '%'.trim($input['q']).'%';
                $digits = preg_replace('/\D/', '', $input['q']);
                $q->where(fn ($w) => $w->where('number', 'like', $like)
                    ->orWhereHas('workizJob', fn ($z) => $z->where('serial_id', 'like', $like))
                    ->orWhereHas('performedBy', fn ($u) => $u->where('name', 'like', $like))
                    ->orWhereHas('customer', fn ($c) => $c->where('name', 'like', $like)
                        ->orWhere('address_line1', 'like', $like)->orWhere('city', 'like', $like)->orWhere('postal_code', 'like', $like)
                        ->when(strlen($digits) >= 4, fn ($p) => $p->orWhere('phone', 'like', '%'.$digits.'%'))));
            });
        $unwatched = fn ($q) => $q->where(fn ($w) => $w->whereIn('status', $watched)->orWhereIn('id', $left));

        $jobs = $base()
            // Recently Paid is every job paid in the window, watched or not.
            ->when(isset($input['ids']) || ($input['filter'] ?? null) === 'recently_paid',
                fn ($q) => $q->when(isset($input['ids']), fn ($i) => $i->whereIn('id', $input['ids'])),
                $unwatched)
            ->when(($input['filter'] ?? null) === 'recently_paid', fn ($q) => $q->whereIn('id',
                $this->paymentsIn(...$this->window($input, $user, $settings))->select('job_id')))
            ->with(['franchise:id,name,timezone', 'customer:id,name', 'workizJob', 'performedBy:id,name'])
            ->get();

        $ids = $jobs->modelKeys();
        $estimates = EstimateAging::latestEstimateDates($ids);
        $paid = EstimateAging::paidCents($ids);
        $payments = WorkizRecord::query()->whereIn('job_id', $ids)->where('type', 'payment')->where('status', 'succeeded')
            ->orderBy('starts_at')->get()->groupBy('job_id');
        $events = AutomationEvent::query()->whereIn('subject_id', $ids)->where('subject_type', Job::class)
            ->whereIn('event_key', ['job.status_changed', 'job.updated'])->orderBy('occurred_at')->get()->groupBy('subject_id');

        $this->runs = $this->runsFor($ids)->where('automation_runs.status', 'succeeded')->get()->groupBy('job_id');

        // The organization's words for its statuses, for each step's sentence (UI/UX, 2026-10-21).
        $this->labels = collect((array) $settings->get('jobs.statuses', $user->organization))
            ->filter(fn ($s) => is_array($s) && isset($s['value']))->mapWithKeys(fn ($s) => [$s['value'] => $s['label'] ?? $s['value']])->all();

        $rows = $jobs->map(fn (Job $job) => $this->row($job, $watched, $money, $estimates[$job->id] ?? null,
            $paid[$job->id] ?? 0, $payments[$job->id] ?? collect(), $events[$job->id] ?? collect()))
            ->filter(fn (array $r) => ($input['stage'] ?? null) === null || $r['stage'] === $input['stage'])
            ->filter(fn (array $r) => ($input['min_days'] ?? null) === null || ($r['waiting']['days_waiting'] ?? -1) >= (int) $input['min_days'])
            ->when(($input['filter'] ?? null) === 'recently_paid',
                // Newest payment first.
                fn ($rows) => $rows->sortByDesc(fn (array $r) => collect($r['timeline'])->where('kind', 'payment')->max('at') ?? ''),
                // Closest to the cancel first; stopped and closed clocks after.
                fn ($rows) => $rows->sortBy(fn (array $r) => $r['waiting']['deadline_at'] ?? '9999'))
            ->values();

        // Every chip's count, whichever is selected (UI/UX, 2026-10-22), under the
        // same branch, search and min_days as its list, so a chip's number is its list's length.
        $daysOk = function ($jobs) use ($input) {
            if (($input['min_days'] ?? null) === null) {
                return $jobs;
            }
            $dates = EstimateAging::latestEstimateDates($jobs->modelKeys());

            return $jobs->filter(function (Job $j) use ($dates, $input) {
                $today = now($j->franchise?->timezone ?: 'UTC')->startOfDay();

                return isset($dates[$j->id]) && Carbon::parse($dates[$j->id], $today->timezone)->diffInDays($today) >= (int) $input['min_days'];
            });
        };
        $watchedJobs = $watched === [] ? new \Illuminate\Database\Eloquent\Collection
            : $daysOk($unwatched($base())->with(['workizJob', 'franchise:id,timezone'])->get());
        $paidAll = EstimateAging::paidCents($watchedJobs->modelKeys());
        $counts = ['waiting' => 0, 'partial' => 0, 'full' => 0, 'closed' => 0];
        foreach ($watchedJobs as $j) {
            $counts[$this->stage($j, $watched, $paidAll[$j->id] ?? 0)]++;
        }
        // Recently Paid counts what its list shows: every job paid in the window, watched or not.
        $counts = ['recently_paid' => filled($input['from'] ?? null) && filled($input['to'] ?? null)
            ? $daysOk($base()->whereIn('id', $this->paymentsIn(...$this->window($input, $user, $settings))->select('job_id'))
                ->with('franchise:id,timezone')->get())->count() : null] + $counts;

        return response()->json(['data' => $rows, 'meta' => ['watched' => $watched, 'deadline_days' => EstimateAging::DAYS, 'counts' => $counts]]);
    }

    private function stage(Job $job, array $watched, int $paid): string
    {
        $total = WorkizMoney::for($job)['total'] ?? ($job->amount_minor ?? 0);

        return ! in_array($job->status, $watched, true) ? 'closed' : ($paid <= 0 ? 'waiting' : ($total > 0 && $paid >= $total ? 'full' : 'partial'));
    }

    /**
     * GET /api/v1/jobs/project-management/stats?from=&to=&franchise_id=
     *
     * What happened in the window (Jose, 2026-10-22: nothing told him a payment
     * arrived, a status changed, tags changed). Each bucket is {count, job_ids}
     * for the drill-down; payments adds amount_minor with Jobs Financials.
     */
    public function stats(Request $request, SettingsResolver $settings): JsonResponse
    {
        $user = $request->user();
        abort_unless($user->hasPermission('project_management.view') && $user->hasPermission('jobs.view'), Response::HTTP_FORBIDDEN, 'Project Management is not in your role.');

        $input = $request->validate([
            'from' => ['required', 'date_format:Y-m-d'],
            'to' => ['required', 'date_format:Y-m-d', 'after_or_equal:from'],
            'franchise_id' => ['nullable', 'uuid'],
            'timezone' => ['nullable', 'timezone'],
        ]);

        $buckets = $this->buckets($user, $settings, $input);
        $out = ['from' => $input['from'], 'to' => $input['to']];
        foreach ($buckets as $key => $ids) {
            $out[$key] = ['count' => count($ids), 'job_ids' => $ids];
        }
        if ($user->hasPermission('jobs.financials')) {
            [$from, $to] = $this->window($input, $user, $settings);
            $out['payments']['amount_minor'] = (int) $this->paymentsIn($from, $to)->whereIn('job_id', $this->reach($user, $input))
                ->get(['amount', 'payload'])->sum(fn (WorkizRecord $p) => round(((float) $p->amount) * 100) * (($p->payload['type'] ?? '') === 'refund' ? -1 : 1));
        }

        return response()->json(['data' => $out]);
    }

    /**
     * The jobs behind each dashboard stat, distinct, in the window. The window's
     * days are the viewer's (`timezone`, else the organization's default zone):
     * Jose, 2026-10-22, "Today" counted last evening's Eastern changes, which
     * are already tomorrow in UTC, and the list then showed them as yesterday.
     *
     * @return array<string, list<string>>
     */
    private function buckets($user, SettingsResolver $settings, array $input): array
    {
        $jobs = $this->reach($user, $input);
        [$from, $to] = $this->window($input, $user, $settings);
        $distinct = fn ($q) => $q->distinct()->pluck('subject_id')->all();
        $changes = fn (string $key) => AutomationEvent::query()->where('event_key', $key)->where('subject_type', Job::class)
            ->whereIn('subject_id', $jobs)->whereBetween('occurred_at', [$from, $to]);

        // "Cancelled" is the organization's word (jobs.cancelled_statuses); none named, none counted.
        $cancelled = (array) $settings->get('jobs.cancelled_statuses', $user->organization);

        // Waiting jobs, nothing paid, whose cancel day falls in the window.
        $watched = (array) $settings->get('jobs.estimate_aging_statuses', $user->organization);
        $waitingIds = $watched === [] ? [] : (clone $jobs)->whereIn('status', $watched)->pluck('id')->all();
        $paid = EstimateAging::paidCents($waitingIds);
        $due = array_keys(array_filter(EstimateAging::latestEstimateDates($waitingIds), function (string $d, string $id) use ($paid, $input) {
            $deadline = Carbon::parse($d)->addDays(EstimateAging::DAYS)->toDateString();

            return ($paid[$id] ?? 0) <= 0 && $deadline >= $input['from'] && $deadline <= $input['to'];
        }, ARRAY_FILTER_USE_BOTH));

        return [
            'payments' => $this->paymentsIn($from, $to)->whereIn('job_id', $jobs)->distinct()->pluck('job_id')->all(),
            'status_changes' => $distinct($changes('job.status_changed')
                ->whereRaw("JSON_UNQUOTE(JSON_EXTRACT(payload, '$.before.status')) <> JSON_UNQUOTE(JSON_EXTRACT(payload, '$.after.status'))")),
            'tag_changes' => $distinct($changes('job.updated')->whereRaw("JSON_CONTAINS_PATH(payload, 'one', '$.after.tags')")),
            'cancellations' => $cancelled === [] ? [] : $distinct($changes('job.status_changed')
                ->whereIn(\DB::raw("JSON_UNQUOTE(JSON_EXTRACT(payload, '$.after.status'))"), $cancelled)),
            'deadlines_due' => $due,
        ];
    }

    private function reach($user, array $input): \Illuminate\Database\Eloquent\Builder
    {
        return Job::query()->whereIn('franchise_id', ViewScope::forUser($user)->franchiseIds())
            ->when($input['franchise_id'] ?? null, fn ($q, $id) => $q->where('franchise_id', $id))
            ->select('id');
    }

    /** @return array{0: Carbon, 1: Carbon} the window's first and last instant, in UTC */
    private function window(array $input, $user, SettingsResolver $settings): array
    {
        $zone = $input['timezone'] ?? null;
        $zone = is_string($zone) && $zone !== '' ? $zone : ((string) ($settings->get('timezone', $user->organization) ?: config('app.timezone')));

        return [Carbon::parse($input['from'], $zone)->startOfDay()->utc(), Carbon::parse($input['to'], $zone)->endOfDay()->utc()];
    }

    /** Succeeded Workiz payments dated in the window, until EzBizi records its own. */
    private function paymentsIn(Carbon $from, Carbon $to): \Illuminate\Database\Eloquent\Builder
    {
        return WorkizRecord::query()->where('type', 'payment')->where('status', 'succeeded')->whereBetween('starts_at', [$from, $to]);
    }

    /** @var array<string, string> status key => the organization's label */
    private array $labels = [];

    private function label(?string $status): string
    {
        return $status === null ? 'none' : ($this->labels[$status] ?? $status);
    }

    /** One line a person reads, for the drill-down (UI/UX, 2026-10-22). */
    private function what(array $step): string
    {
        return match ($step['kind']) {
            'entered' => 'Entered '.$this->label($step['status']),
            'payment' => $step['marks'] === 'full' ? 'Payment received, paid in full' : 'Payment received, partly paid',
            'status' => 'Status '.$this->label($step['from'] ?? null).' → '.$this->label($step['to'] ?? null),
            'tags' => trim(implode(' ', [
                ...array_map(fn ($t) => '+'.$t, $step['tags']['added']),
                ...array_map(fn ($t) => '−'.$t, $step['tags']['removed']),
            ])) ?: 'Tags changed',
            default => $step['kind'],
        };
    }

    private function row(Job $job, array $watched, bool $money, ?string $estimateDate, int $paid, Collection $payments, Collection $events): array
    {
        $total = WorkizMoney::for($job)['total'] ?? ($job->amount_minor ?? 0);
        $open = in_array($job->status, $watched, true);
        $stage = $this->stage($job, $watched, $paid);

        $entered = $events->last(fn (AutomationEvent $e) => $e->event_key === 'job.status_changed'
            && in_array($e->payload['after']['status'] ?? null, $watched, true)
            && ! in_array($e->payload['before']['status'] ?? null, $watched, true));
        $since = $entered?->occurred_at ?? now()->subDays(self::RECENT_DAYS);

        $timeline = collect();
        if ($entered) {
            $timeline->push(['kind' => 'entered', 'at' => $entered->occurred_at->toIso8601String(), 'status' => $entered->payload['after']['status'],
                'by' => $this->by($entered)] + ($money ? ['total_minor' => $total] : []));
        }

        $running = 0;
        foreach ($payments as $p) {
            $amount = (int) round(((float) $p->amount) * 100) * (($p->payload['type'] ?? '') === 'refund' ? -1 : 1);
            $running += $amount;
            if ($p->starts_at && $p->starts_at->gte($since)) {
                $timeline->push(['kind' => 'payment', 'at' => $p->starts_at->toIso8601String(), 'marks' => $total > 0 && $running >= $total ? 'full' : 'partial']
                    + ($money ? ['amount_minor' => $amount] : []));
            }
        }

        foreach ($events as $e) {
            if ($e === $entered || $e->occurred_at->lt($since)) {
                continue;
            }
            if ($e->event_key === 'job.status_changed') {
                $timeline->push(['kind' => 'status', 'at' => $e->occurred_at->toIso8601String(), 'from' => $e->payload['before']['status'] ?? null,
                    'to' => $e->payload['after']['status'] ?? null] + $this->attribute($e, $job));
            } elseif (array_key_exists('tags', $e->payload['after'] ?? [])) {
                $before = (array) ($e->payload['before']['tags'] ?? []);
                $after = (array) $e->payload['after']['tags'];
                $timeline->push(['kind' => 'tags', 'at' => $e->occurred_at->toIso8601String(),
                    'tags' => ['added' => array_values(array_diff($after, $before)), 'removed' => array_values(array_diff($before, $after))]]
                    + $this->attribute($e, $job));
            }
        }

        $today = now($job->franchise?->timezone ?: 'UTC')->startOfDay();

        return [
            'id' => $job->id,
            'number' => $job->number,
            'title' => $job->title,
            'status' => $job->status,
            'tags' => $job->tags ?? [],
            // Jose, 2026-10-22: the tech on the job, if any.
            'technician' => $job->performedBy?->name,
            'franchise' => ['id' => $job->franchise_id, 'name' => $job->franchise?->name],
            'customer' => ['id' => $job->customer_id, 'name' => $job->customer?->name],
            'stage' => $stage,
            'waiting' => $estimateDate === null ? null : [
                'estimate_date' => $estimateDate,
                'days_waiting' => (int) Carbon::parse($estimateDate, $today->timezone)->diffInDays($today),
                // The cancel clock (Jose, 2026-10-21: "a timer on all the done
                // pending approval jobs"): runs only while nothing is paid,
                // since the rule cancels on "nothing paid", and stops at the
                // first payment. deadline_at is that day's start in the
                // branch's zone, for a countdown in days and hours.
                'deadline' => $stage === 'waiting' ? Carbon::parse($estimateDate)->addDays(EstimateAging::DAYS)->toDateString() : null,
                'deadline_at' => $stage === 'waiting'
                    ? Carbon::parse($estimateDate, $today->timezone)->addDays(EstimateAging::DAYS)->toIso8601String() : null,
                'timer' => match (true) { ! $open => 'closed', $stage === 'waiting' => 'running', default => 'stopped' },
            ],
            'timeline' => $timeline->sortBy('at')->map(fn (array $s) => $s + ['what' => $this->what($s)])->values()->all(),
        ];
    }

    /**
     * GET /api/v1/jobs/project-management/logs?from=&to=&timezone=&franchise_id=&automation_id=&outcome=
     *
     * Everything EzBizi did to a job in the window (Jose, 2026-10-22: "every
     * step says Workiz, I can't tell whether Action 1 ever ran"): each rule's
     * run on a job, with what its actions did or why it skipped, and each edit
     * a person made in EzBizi. Newest first.
     */
    public function logs(Request $request, SettingsResolver $settings): JsonResponse
    {
        $user = $request->user();
        abort_unless($user->hasPermission('project_management.view') && $user->hasPermission('jobs.view'), Response::HTTP_FORBIDDEN, 'Project Management is not in your role.');

        $input = $request->validate([
            'from' => ['required', 'date_format:Y-m-d'],
            'to' => ['required', 'date_format:Y-m-d', 'after_or_equal:from'],
            'franchise_id' => ['nullable', 'uuid'],
            'timezone' => ['nullable', 'timezone'],
            'automation_id' => ['nullable', 'uuid'],
            'outcome' => ['nullable', Rule::in(['succeeded', 'failed', 'skipped', 'manual'])],
        ]);
        [$from, $to] = $this->window($input, $user, $settings);
        $reach = $this->reach($user, $input);
        $this->labels = $this->statusLabels($settings, $user);

        $all = $this->runsFor($reach)
            ->whereBetween('automation_runs.started_at', [$from, $to])
            ->when($input['automation_id'] ?? null, fn ($q, $id) => $q->where('automation_runs.automation_id', $id))
            ->get();
        $runs = $all->when($input['outcome'] ?? null, fn ($c, $o) => $c->where('status', $o));

        $allEdits = empty($input['automation_id'])
            ? AutomationEvent::query()->where('subject_type', Job::class)->whereIn('subject_id', $reach)
                ->whereIn('event_key', ['job.updated', 'job.status_changed', 'job.created'])->whereNotNull('actor_id')
                ->whereBetween('occurred_at', [$from, $to])->get()
            : collect();
        $edits = in_array($input['outcome'] ?? 'manual', ['manual'], true) ? $allEdits : collect();

        // Counts for every outcome, and skipped runs folded per rule (UI/UX, 2026-10-22: 128 skips).
        $meta = ['counts' => [
            'succeeded' => $all->where('status', 'succeeded')->count(),
            'failed' => $all->where('status', 'failed')->count(),
            'skipped' => $all->where('status', 'skipped')->count(),
            'manual' => $allEdits->count(),
        ], 'skipped_by_rule' => $all->where('status', 'skipped')->groupBy('automation_id')->map(fn ($g) => [
            'rule' => ['id' => $g->first()->automation_id, 'name' => $g->first()->automation?->name],
            'count' => $g->count(),
            'reasons' => $g->groupBy(fn (AutomationRun $r) => $this->skipReason((array) $r->condition_trace))
                ->map(fn ($rs, $reason) => ['reason' => $reason, 'count' => $rs->count()])->sortByDesc('count')->values()->all(),
        ])->sortByDesc('count')->values()->all()];

        $jobs = Job::query()->whereIn('id', $runs->pluck('job_id')->merge($edits->pluck('subject_id'))->unique())
            ->with(['customer:id,name', 'franchise:id,name'])->get()->keyBy('id');
        $job = fn (string $id) => ['id' => $id, 'number' => $jobs[$id]?->number, 'title' => $jobs[$id]?->title,
            'customer' => $jobs[$id]?->customer?->name, 'franchise' => $jobs[$id]?->franchise?->name];

        $rows = $runs->map(fn (AutomationRun $r) => [
            'kind' => 'automation',
            'at' => $r->started_at?->toIso8601String(),
            'job' => $job($r->job_id),
            'rule' => ['id' => $r->automation_id, 'name' => $r->automation?->name],
            'outcome' => $r->status,
            'changes' => collect((array) $r->action_results)->map(fn ($a) => [
                'type' => $a['type'] ?? null, 'state' => $a['state'] ?? null, 'detail' => $a['detail'] ?? null,
            ])->values()->all(),
            'reason' => $r->status === 'skipped' ? $this->skipReason((array) $r->condition_trace) : $r->error,
        ])->concat($edits->map(fn (AutomationEvent $e) => [
            'kind' => 'manual',
            'at' => $e->occurred_at->toIso8601String(),
            'job' => $job($e->subject_id),
            'by' => $this->by($e),
            'outcome' => 'manual',
            'changes' => [['type' => $e->event_key, 'state' => 'succeeded', 'detail' => $this->editDetail($e)]],
        ]))->sortByDesc('at')->values();

        return response()->json(['data' => $rows, 'meta' => $meta]);
    }

    /** Runs about jobs, each with job_id, newest first; tests and other subjects left out. */
    private function runsFor($jobIds): \Illuminate\Database\Eloquent\Builder
    {
        return AutomationRun::query()->join('automation_events', 'automation_events.id', '=', 'automation_runs.event_id')
            ->where('automation_events.subject_type', Job::class)->whereIn('automation_events.subject_id', $jobIds)
            ->where('automation_runs.is_test', false)
            ->select('automation_runs.*', 'automation_events.subject_id as job_id')
            ->with('automation:id,name')->orderByDesc('automation_runs.started_at');
    }

    /** "Status was Pending; the rule wants Done pending approval", from the first unmatched condition. */
    private function skipReason(array $trace): string
    {
        $stack = [$trace];
        while ($node = array_shift($stack)) {
            if (($node['type'] ?? null) === 'condition' && ($node['matched'] ?? true) === false) {
                $field = str_replace('_', ' ', (string) ($node['field'] ?? 'value'));
                $show = fn ($v) => is_array($v) ? implode(', ', array_map(fn ($x) => $this->label((string) $x), $v))
                    : ($v === null || $v === '' ? 'empty' : ($field === 'status' ? $this->label((string) $v) : (string) $v));

                return ucfirst($field).' was '.$show($node['actual'] ?? null).'; the rule wants '.$show($node['expected'] ?? null).'.';
            }
            array_push($stack, ...(array) ($node['children'] ?? []));
        }

        return 'The rule did not match.';
    }

    private function editDetail(AutomationEvent $e): string
    {
        $before = (array) ($e->payload['before'] ?? []);
        $after = (array) ($e->payload['after'] ?? []);
        if ($e->event_key === 'job.created') {
            return 'Created the job';
        }
        $parts = [];
        foreach ($after as $field => $value) {
            $old = $before[$field] ?? null;
            $parts[] = $field === 'status'
                ? 'Status '.$this->label(is_string($old) ? $old : null).' → '.$this->label(is_string($value) ? $value : null)
                : str_replace('_', ' ', ucfirst((string) $field)).': '.(is_array($value) ? implode(', ', $value) : json_encode($value));
        }

        return implode('; ', $parts) ?: 'Edited the job';
    }

    /** @var Collection<string, Collection<int, AutomationRun>> succeeded runs by job id */
    private Collection $runs;

    /**
     * Who made a step. Workiz's sync reports a change EzBizi asked Workiz for
     * as Workiz's own; it is EzBizi's when a rule's run on this job, finished
     * shortly before, set that status or those tags (Jose, 2026-10-22).
     */
    private function attribute(AutomationEvent $e, Job $job): array
    {
        $by = $this->by($e);
        if ($by !== 'Workiz') {
            return ['by' => $by];
        }

        $after = $e->payload['after'] ?? [];
        $norm = fn ($v) => mb_strtolower(trim((string) $v));
        $run = ($this->runs[$job->id] ?? collect())->first(function (AutomationRun $r) use ($e, $after, $norm) {
            // ponytail: 30 minutes covers the Workiz sync's lag; widen if a sync runs slower.
            if (! $r->finished_at || $r->finished_at->gt($e->occurred_at) || $r->finished_at->lt($e->occurred_at->copy()->subMinutes(30))) {
                return false;
            }
            foreach ((array) $r->actions_snapshot as $a) {
                if (($a['type'] ?? null) !== 'workiz.job_update') {
                    continue;
                }
                if (isset($after['status']) && filled($a['status'] ?? null) && $norm($a['status']) === $norm($after['status'])) {
                    return true;
                }
                $set = array_filter(array_map($norm, explode(',', (string) ($a['tags'] ?? ''))));
                if (isset($after['tags']) && $set !== [] && array_diff($set, array_map($norm, (array) $after['tags'])) === []) {
                    return true;
                }
            }

            return false;
        });

        return $run === null ? ['by' => $by]
            : ['by' => 'EzBizi · '.$run->automation?->name, 'rule' => ['id' => $run->automation_id, 'name' => $run->automation?->name]];
    }

    /** @return array<string, string> */
    private function statusLabels(SettingsResolver $settings, $user): array
    {
        return collect((array) $settings->get('jobs.statuses', $user->organization))
            ->filter(fn ($s) => is_array($s) && isset($s['value']))->mapWithKeys(fn ($s) => [$s['value'] => $s['label'] ?? $s['value']])->all();
    }

    /** A person by name, a rule, or Workiz's sync. */
    private function by(AutomationEvent $e): string
    {
        return match (true) {
            $e->actor_id !== null => trim(preg_replace('/\s*<[^>]*>$/', '', (string) $e->actor_label)),
            $e->causation_id !== null => 'Automation',
            default => 'Workiz',
        };
    }
}
