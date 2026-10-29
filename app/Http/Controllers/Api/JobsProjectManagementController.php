<?php

namespace App\Http\Controllers\Api;

use App\Models\AutomationEvent;
use App\Models\AutomationRun;
use App\Models\Job;
use App\Support\Jobs\EstimateAging;
use App\Support\Jobs\ProjectStages;
use App\Support\Jobs\JobMoney;
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
 * own event stream and its money (JobMoney), so nothing is A-1-specific.
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
            ->when(filled($input['q'] ?? null), fn ($q) => $this->search($q, $input['q']));
        $unwatched = fn ($q) => $q->where(fn ($w) => $w->whereIn('status', $watched)->orWhereIn('id', $left));

        $jobs = $base()
            // Recently Paid is every job paid in the window, watched or not.
            ->when(isset($input['ids']) || ($input['filter'] ?? null) === 'recently_paid',
                fn ($q) => $q->when(isset($input['ids']), fn ($i) => $i->whereIn('id', $input['ids'])),
                $unwatched)
            ->when(($input['filter'] ?? null) === 'recently_paid', fn ($q) => $q->whereIn('id',
                JobMoney::paidJobIds(...$this->window($input, $user, $settings))))
            ->with(['franchise:id,name,timezone', 'customer:id,name', 'workizJob', 'performedBy:id,name'])
            ->get();

        $ids = $jobs->modelKeys();
        $estimates = EstimateAging::latestEstimateDates($ids);
        $m = JobMoney::of($ids);
        $payments = JobMoney::payments($ids);
        $events = AutomationEvent::query()->whereIn('subject_id', $ids)->where('subject_type', Job::class)
            ->whereIn('event_key', ['job.status_changed', 'job.updated'])->orderBy('occurred_at')->get()->groupBy('subject_id');

        $this->runs = $this->runsFor($ids)->get()->groupBy('job_id');

        // The organization's words for its statuses, for each step's sentence (UI/UX, 2026-10-21).
        $this->labels = collect((array) $settings->get('jobs.statuses', $user->organization))
            ->filter(fn ($s) => is_array($s) && isset($s['value']))->mapWithKeys(fn ($s) => [$s['value'] => $s['label'] ?? $s['value']])->all();

        $rows = $jobs->map(fn (Job $job) => $this->row($job, $watched, $money, $estimates[$job->id] ?? null,
            $m[$job->id] ?? null, $payments[$job->id] ?? collect(), $events[$job->id] ?? collect()))
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
        $mAll = JobMoney::of($watchedJobs->modelKeys());
        $counts = ['waiting' => 0, 'partial' => 0, 'full' => 0, 'closed' => 0];
        foreach ($watchedJobs as $j) {
            $counts[$this->stage($j, $watched, $mAll[$j->id] ?? null)]++;
        }
        // Recently Paid counts what its list shows: every job paid in the window, watched or not.
        $counts = ['recently_paid' => filled($input['from'] ?? null) && filled($input['to'] ?? null)
            ? $daysOk($base()->whereIn('id', JobMoney::paidJobIds(...$this->window($input, $user, $settings)))
                ->with('franchise:id,timezone')->get())->count() : null] + $counts;

        return response()->json(['data' => $rows, 'meta' => ['watched' => $watched, 'deadline_days' => EstimateAging::DAYS, 'counts' => $counts]]);
    }

    /** @param  array{total: ?int, paid: int}|null  $m  the job's JobMoney */
    private function stage(Job $job, array $watched, ?array $m): string
    {
        $paid = $m['paid'] ?? 0;
        $total = $m['total'] ?? 0;

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
            $out['payments']['amount_minor'] = array_sum(JobMoney::paidCents($this->reach($user, $input), $from, $to));
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
        $cancelled = (array) $settings->get('jobs.cancelled_statuses', $this->pmScope($user));

        // Waiting jobs, nothing paid, whose cancel day falls in the window.
        $watched = (array) $settings->get('jobs.estimate_aging_statuses', $user->organization);
        $waitingIds = $watched === [] ? [] : (clone $jobs)->whereIn('status', $watched)->pluck('id')->all();
        $paid = JobMoney::paidCents($waitingIds);
        $due = array_keys(array_filter(EstimateAging::latestEstimateDates($waitingIds), function (string $d, string $id) use ($paid, $input) {
            $deadline = Carbon::parse($d)->addDays(EstimateAging::DAYS)->toDateString();

            return ($paid[$id] ?? 0) <= 0 && $deadline >= $input['from'] && $deadline <= $input['to'];
        }, ARRAY_FILTER_USE_BOTH));

        return [
            'payments' => JobMoney::paidJobIds($from, $to)->whereIn('job_id', $jobs)->distinct()->pluck('job_id')->all(),
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
            'entered' => 'Status '.$this->label($step['from'] ?? null).' → '.$this->label($step['to'] ?? null),
            'action' => $step['what'],
            'payment' => $step['marks'] === 'full' ? 'Payment received, paid in full' : 'Payment received, partly paid',
            'status' => 'Status '.$this->label($step['from'] ?? null).' → '.$this->label($step['to'] ?? null),
            'tags' => trim(implode(' ', [
                ...array_map(fn ($t) => '+'.$t, $step['tags']['added']),
                ...array_map(fn ($t) => '−'.$t, $step['tags']['removed']),
            ])) ?: 'Tags changed',
            default => $step['kind'],
        };
    }

    private function row(Job $job, array $watched, bool $money, ?string $estimateDate, ?array $m, Collection $payments, Collection $events): array
    {
        $total = $m['total'] ?? 0;
        $open = in_array($job->status, $watched, true);
        $stage = $this->stage($job, $watched, $m);

        $entered = $events->last(fn (AutomationEvent $e) => $e->event_key === 'job.status_changed'
            && in_array($e->payload['after']['status'] ?? null, $watched, true)
            && ! in_array($e->payload['before']['status'] ?? null, $watched, true));
        $since = $entered?->occurred_at ?? now()->subDays(self::RECENT_DAYS);

        $timeline = collect();
        if ($entered) {
            // The status change it was, too: a drill-down of today's changes showed "—"
            // for jobs whose only change today was this entry (2026-10-22).
            $timeline->push(['kind' => 'entered', 'at' => $entered->occurred_at->toIso8601String(), 'status' => $entered->payload['after']['status'],
                'from' => $entered->payload['before']['status'] ?? null, 'to' => $entered->payload['after']['status']]
                + $this->attribute($entered, $job) + ($money ? ['total_minor' => $total] : []));
        }

        // Every step EzBizi's rules took on the job, one line each, skips and failures with their reason (Jose, 2026-10-22).
        foreach ($this->runs[$job->id] ?? [] as $r) {
            if ($r->started_at === null || $r->started_at->lt($since)) {
                continue;
            }
            $rule = ['id' => $r->automation_id, 'name' => $r->automation?->name];
            foreach ($this->runLines($r) as $line) {
                $timeline->push(['kind' => 'action', 'at' => ($r->finished_at ?? $r->started_at)->toIso8601String(),
                    'by' => 'EzBizi · '.$rule['name'], 'rule' => $rule] + $line);
            }
        }

        $running = 0;
        foreach ($payments as $p) {
            $amount = $p['cents'];
            $running += $amount;
            if ($p['at'] && $p['at']->gte($since)) {
                $timeline->push(['kind' => 'payment', 'at' => $p['at']->toIso8601String(), 'marks' => $total > 0 && $running >= $total ? 'full' : 'partial']
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
            // Jose, 2026-10-22: "i want search bars". Job, customer, address, phone, tech, or the rule's name.
            'q' => ['nullable', 'string', 'max:100'],
            'outcome' => ['nullable', Rule::in(['succeeded', 'failed', 'skipped', 'manual'])],
        ]);
        [$from, $to] = $this->window($input, $user, $settings);
        $reach = $this->reach($user, $input);
        $this->labels = $this->statusLabels($settings, $user);

        $text = trim((string) ($input['q'] ?? ''));
        $matching = $text === '' ? $reach : $this->search(clone $reach, $text);
        $all = $this->runsFor($reach)
            ->when($text !== '', fn ($q) => $q->where(fn ($w) => $w->whereIn('automation_events.subject_id', $matching)
                ->orWhereHas('automation', fn ($a) => $a->where('name', 'like', '%'.$text.'%'))))
            ->whereBetween('automation_runs.started_at', [$from, $to])
            ->when($input['automation_id'] ?? null, fn ($q, $id) => $q->where('automation_runs.automation_id', $id))
            ->get();
        $runs = $all->when($input['outcome'] ?? null, fn ($c, $o) => $c->where('status', $o));

        $allEdits = empty($input['automation_id'])
            ? AutomationEvent::query()->where('subject_type', Job::class)->whereIn('subject_id', $matching)
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
            // The same lines the job's timeline shows.
            'lines' => $this->runLines($r),
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

    /**
     * GET /api/v1/jobs/project-management/jobs?stage=&…filters&page=
     *
     * The Jobs tab (Jose's overhaul, 2026-10-22): one stage's jobs, a page at
     * a time, with every stage's count under the same filters.
     */
    public function stageJobs(Request $request, SettingsResolver $settings): JsonResponse
    {
        $user = $this->pmUser($request);
        $input = $request->validate($this->stageFilters() + [
            // Or every not-started stage at once, for the Dashboard's "See all" (UI/UX, 2026-10-22).
            // One stage, several (stages[]: the Pipeline tile's four), or none: every
            // mapped stage, so a count's drill lists what it counted (UI/UX audit, 2026-10-23).
            'stage' => ['nullable', Rule::in(ProjectStages::KEYS)],
            'stages' => ['nullable', 'array'],
            'stages.*' => [Rule::in(ProjectStages::KEYS)],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);
        $rules = ProjectStages::forFranchises(\App\Models\Franchise::query()->whereIn('id', ViewScope::forUser($user)->franchiseIds())->get(), $settings);
        $filtered = fn () => $this->stageFiltered($user, $settings, $input);

        $page = (isset($input['stage']) ? ProjectStages::scope($filtered(), $rules[$input['stage']] ?? null)
                : ProjectStages::anyOf($filtered(), $rules, $input['stages'] ?? null))
            ->with(['customer:id,name', 'franchise:id,name', 'serviceArea:id,name', 'performedBy:id,name', 'workizJob'])
            ->orderByDesc('updated_at')->paginate(100, ['*'], 'page', (int) ($input['page'] ?? 1));

        $paidAt = JobMoney::paidDates($page->getCollection()->modelKeys());
        $of = JobMoney::of($page->getCollection()->modelKeys());
        $money = $user->hasPermission('jobs.financials');

        $rows = $page->getCollection()->map(function (Job $j) use ($paidAt, $of, $money) {
            $m = $of[$j->id] ?? null;
            $first = $paidAt[$j->id]['first'] ?? null;

            return [
                'id' => $j->id, 'number' => $j->number, 'title' => $j->title, 'status' => $j->status, 'tags' => $j->tags ?? [],
                'customer' => ['id' => $j->customer_id, 'name' => $j->customer?->name],
                'franchise' => ['id' => $j->franchise_id, 'name' => $j->franchise?->name],
                'service_area' => $j->serviceArea?->name,
                'technician' => $j->performedBy?->name,
                'scheduled_for' => $j->scheduled_for?->toIso8601String(),
                'review_reason' => $j->review_reason,
                'external_company' => $j->external_company,
                'needs_second_visit' => (bool) $j->needs_second_visit,
                'paid_at' => $first?->toDateString(),
                // One clock, from the first payment, as the warning and the dashboard count (audit, 2026-10-23).
                'days_since_first_payment' => isset($paidAt[$j->id]) ? (int) $paidAt[$j->id]['first']->copy()->startOfDay()->diffInDays(now()->startOfDay()) : null,
                'days_since_last_payment' => isset($paidAt[$j->id]) ? (int) $paidAt[$j->id]['last']->copy()->startOfDay()->diffInDays(now()->startOfDay()) : null,
            ] + ($money && isset($m['total']) ? ['total_minor' => $m['total'], 'due_minor' => $m['due']] : []);
        });

        $counts = [];
        foreach (ProjectStages::KEYS as $key) {
            $counts[$key] = isset($rules[$key]) ? ProjectStages::scope($filtered(), $rules[$key])->count() : 0;
        }

        return response()->json(['data' => $rows, 'meta' => [
            'counts' => $counts, 'mapped' => array_keys($rules),
            'page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'total' => $page->total(),
        ]]);
    }

    /**
     * GET /api/v1/jobs/project-management/overview?franchise_id=&service_area_id=&q=
     *
     * The Dashboard's numbers (Jose's overhaul, 2026-10-22), each divisible by
     * service area. Production cost waits for Financials' per-job cost: null.
     */
    public function overview(Request $request, SettingsResolver $settings): JsonResponse
    {
        $user = $this->pmUser($request);
        $input = $request->validate($this->stageFilters());
        $rules = ProjectStages::forFranchises(\App\Models\Franchise::query()->whereIn('id', ViewScope::forUser($user)->franchiseIds())->get(), $settings);
        $in = fn (string $key) => ProjectStages::scope($this->stageFiltered($user, $settings, $input), $rules[$key] ?? null);
        $money = $user->hasPermission('jobs.financials');

        $reasons = collect((array) $settings->get('jobs.review_reasons', $this->pmScope($user)))->filter(fn ($r) => is_array($r) && isset($r['value']));
        $review = $in('waiting_review')->with('serviceArea:id,name')->get(['id', 'review_reason', 'service_area_id']);
        $byReason = fn ($jobs) => $reasons->map(fn ($r) => ['value' => $r['value'], 'label' => $r['label'] ?? $r['value'],
            'count' => $jobs->where('review_reason', $r['value'])->count()])
            ->push(['value' => null, 'label' => 'No reason', 'count' => $jobs->whereNull('review_reason')->count()])->values()->all();

        $redo = (array) $settings->get('jobs.pm_redo_words', $this->pmScope($user));
        $inProgress = $in('in_progress')->count();
        $redoCount = ProjectStages::anyWord($in('in_progress'), $redo)->count();

        $notStarted = collect(ProjectStages::NOT_STARTED)->flatMap(fn ($k) => isset($rules[$k]) ? $in($k)->pluck('id') : [])->unique();
        $firstPaid = collect(JobMoney::paidDates($notStarted))->map(fn ($d) => $d['first']);
        $limit = (int) $settings->get('jobs.pm_warn_days', $this->pmScope($user));
        $waitingJobs = Job::query()->whereIn('id', $firstPaid->keys())->with('customer:id,name')->get(['id', 'number', 'customer_id']);

        $open = Job::query()->where(fn ($w) => collect(ProjectStages::OPEN)->filter(fn ($k) => isset($rules[$k]))
            ->each(fn ($k) => $w->orWhereIn('id', $in($k)->select('id'))))->whereRaw(collect(ProjectStages::OPEN)->contains(fn ($k) => isset($rules[$k])) ? '1 = 1' : '1 = 0');

        $doneWaiting = $in('done_waiting_payment');

        return response()->json(['data' => [
            'mapped' => array_keys($rules),
            // Where the repair-team report turns cost from gross sales red.
            'cost_alert_percent' => (int) $settings->get('jobs.pm_cost_alert_percent', $this->pmScope($user)),
            'waiting_review' => [
                'count' => $review->count(),
                'by_reason' => $byReason($review),
                'by_location' => $review->groupBy(fn ($j) => $j->serviceArea?->name ?? 'No service area')
                    ->map(fn ($jobs, $name) => ['name' => $name, 'count' => $jobs->count(), 'by_reason' => $byReason($jobs)])->sortByDesc('count')->values()->all(),
            ],
            'in_progress' => [
                'count' => $inProgress,
                'ours' => $in('in_progress')->whereNull('external_company')->count(),
                'external' => $in('in_progress')->whereNotNull('external_company')->count(),
                'second_visit' => $in('in_progress')->where('needs_second_visit', true)->count(),
                'redo' => $redoCount,
                'new' => $inProgress - $redoCount,
            ],
            'ready_to_schedule' => [
                'count' => $in('ready_to_schedule')->count(),
                // Since the job moved into its status (its last status change), else since it was made.
                'oldest_days' => ($oldest = $this->enteredAt($in('ready_to_schedule'))) ? (int) Carbon::parse($oldest)->diffInDays(now()) : null,
                'oldest_since' => 'status_change',
            ],
            'scheduled' => [
                'count' => $in('scheduled')->count(),
                'next_7_days' => $in('scheduled')->whereBetween('scheduled_for', [now()->startOfDay(), now()->addDays(7)->endOfDay()])->count(),
            ],
            'done_waiting_payment' => ['count' => $doneWaiting->count()]
                + ($money ? ['total_minor' => JobMoney::sum($in('done_waiting_payment'), 'total'), 'due_minor' => JobMoney::sum($in('done_waiting_payment'), 'due')] : []),
            'stopped_payments' => ProjectStages::stopped(ProjectStages::anyOf($this->stageFiltered($user, $settings, $input), $rules), (int) $settings->get('jobs.pm_stopped_days', $this->pmScope($user)))->count(),
            'per_team' => (clone $open)->with('performedBy:id,name')->get(['id', 'performed_by_user_id'])
                ->groupBy(fn ($j) => $j->performedBy?->name ?? 'Unassigned')->map(fn ($g, $name) => ['name' => $name, 'count' => $g->count()])
                ->sortByDesc('count')->values()->all(),
            'waiting_to_start' => $waitingJobs->map(fn (Job $j) => [
                'job' => ['id' => $j->id, 'number' => $j->number, 'customer' => $j->customer?->name],
                'paid_at' => $firstPaid[$j->id]->toDateString(),
                'days' => (int) $firstPaid[$j->id]->copy()->startOfDay()->diffInDays(now()->startOfDay()),
                'limit' => $limit,
            ])->sortByDesc('days')->values()->all(),
        ] + ($money ? ['pipeline_minor' => JobMoney::sum(clone $open, 'total'), 'avg_production_cost_minor' => null] : [])]);
    }

    /**
     * Where Project Management's settings are read: the place being viewed
     * (Jose, 2026-10-22: "this belongs in A-1 Chimney, not triplex"), a
     * company's own over the organization's.
     */
    private function pmScope($user): \Illuminate\Database\Eloquent\Model
    {
        return ViewScope::forUser($user)->settingsScope();
    }

    /**
     * GET /api/v1/jobs/project-management/cards?franchise_id=&q=
     *
     * The cards Project Management's rules leave (Jose, 2026-10-22: the tab
     * "refers to the action 1 stuff"): every task in the group named by each
     * branch's jobs.pm_task_group, across the branch boards in reach, in the
     * Task Board's own shapes, so its widget draws them and its endpoints edit them.
     */
    public function cards(Request $request, SettingsResolver $settings): JsonResponse
    {
        $user = $this->pmUser($request);
        $input = $request->validate(['franchise_id' => ['nullable', 'uuid'], 'q' => ['nullable', 'string', 'max:100']]);
        $boards = app(\App\Support\Tasks\TaskBoards::class)->visibleTo($user)
            ->whereIn('franchise_id', ViewScope::forUser($user)->franchiseIds())
            ->when($input['franchise_id'] ?? null, fn ($q, $id) => $q->where('franchise_id', $id))
            ->with(['franchise', 'groups', 'columns', 'statuses'])->get();

        $groupIds = $boards->flatMap(function ($b) use ($settings) {
            $name = mb_strtolower(trim((string) $settings->get('jobs.pm_task_group', $b->franchise)));

            return $b->groups->filter(fn ($g) => mb_strtolower(trim($g->name)) === $name)->pluck('id');
        });
        $text = trim((string) ($input['q'] ?? ''));

        $tasks = \App\Models\Task::query()->whereIn('group_id', $groupIds)
            ->when($text !== '', fn ($q) => $q->where(fn ($w) => $w->where('title', 'like', '%'.$text.'%')
                ->orWhere(fn ($a) => $a->where('about_type', 'job')->whereIn('about_id', $this->search(Job::query(), $text)->select('id')))))
            ->with(\App\Http\Controllers\Api\TasksController::WITH)->withCount(\App\Http\Controllers\Api\TasksController::counts())
            ->orderByDesc('created_at')->get();
        $used = $tasks->pluck('board_id')->unique();
        $jobs = Job::query()->whereIn('id', $tasks->where('about_type', 'job')->pluck('about_id'))->get(['id', 'number'])->keyBy('id');
        $tb = app(\App\Support\Tasks\TaskBoards::class);

        return response()->json(['data' => [
            'boards' => $boards->whereIn('id', $used)->map(fn ($b) => [
                'id' => $b->id, 'name' => $b->name, 'franchise_id' => $b->franchise_id,
                'can_edit' => $tb->canEdit($user, $b),
                'statuses' => $b->statuses->map(fn ($x) => TaskBoardSettingsController::presentStatus($x))->all(),
                'columns' => $b->columns->map(fn ($x) => TaskBoardSettingsController::presentColumn($x))->all(),
                'builtin_columns' => $b->resolvedBuiltinColumns(),
                'assignable' => $tb->assignable($b)->map(fn ($u) => ['id' => $u->id, 'name' => $u->name])->all(),
            ])->values()->all(),
            // The job each card is about and its branch, for grouping and the link (UI/UX, 2026-10-22).
            'tasks' => $tasks->map(fn ($t) => \App\Http\Controllers\Api\TasksController::present($t) + [
                'job' => isset($jobs[$t->about_id]) && $t->about_type === 'job' ? ['id' => $t->about_id, 'number' => $jobs[$t->about_id]->number] : null,
                'franchise' => ($f = $boards->firstWhere('id', $t->board_id)?->franchise) ? ['id' => $f->id, 'name' => $f->name] : null,
            ])->all(),
        ]]);
    }

    /**
     * GET /api/v1/jobs/project-management/rules
     *
     * "What triggers what changes" (Jose, 2026-10-23: Project Management is
     * "impossible to understand"). Read live, in sentences: which jobs PM is
     * about and where that is set, each stage's rule and count, the alerts, the
     * rules that act on jobs with their last week, and the fixed server jobs.
     */
    public function rules(Request $request, SettingsResolver $settings): JsonResponse
    {
        $user = $this->pmUser($request);
        $all = \App\Models\Franchise::query()->whereIn('id', ViewScope::forUser($user)->franchiseIds())->with('company')->get();
        // Branches set up alike share one block: each branch is staged by its own resolved settings.
        $keys = ['jobs.pm_stages', 'jobs.pm_scope_statuses', 'jobs.pm_partly_paid_only', 'jobs.pm_warn_days', 'jobs.pm_warning_email',
            'jobs.pm_stopped_days', 'jobs.pm_cost_alert_percent', 'jobs.pm_redo_words', 'jobs.pm_dispute_words', 'jobs.pm_task_group'];
        $groups = $all->groupBy(fn ($f) => json_encode(array_map(fn ($k) => $settings->get($k, $f), $keys)));
        $configs = $groups->map(function ($branches) use ($user, $settings) {
            $companies = $branches->pluck('company_id')->unique();
            $place = $companies->count() === 1 && $branches->first()->company ? $branches->first()->company : $branches->first();

            return ['branches' => $branches->pluck('name')->sort()->values()->all()] + $this->rulesFor($user, $settings, $place, $branches);
        })->values()->all();

        $since = now()->subDays(7);
        $automations = \App\Models\Automation::query()->where('trigger_key', 'like', 'job.%')->with('activeVersion')->get()
            ->map(function (\App\Models\Automation $a) use ($since) {
                $runs = AutomationRun::query()->where('automation_id', $a->id)->where('is_test', false)->where('started_at', '>=', $since);
                $lastSkip = (clone $runs)->where('status', 'skipped')->latest('started_at')->first();

                return [
                    'id' => $a->id, 'name' => $a->name, 'active' => (bool) $a->is_active, 'trigger' => $a->trigger_key,
                    'conditions' => $this->conditionsSentence($a->activeVersion?->conditions),
                    'actions' => array_map(fn ($x) => $this->actionSentence((array) $x), (array) ($a->activeVersion?->actions ?? [])),
                    'runs_7d' => (clone $runs)->whereIn('status', ['succeeded', 'failed'])->count(),
                    'skipped_7d' => (clone $runs)->where('status', 'skipped')->count(),
                    'last_skip_reason' => $lastSkip ? $this->skipReason((array) $lastSkip->condition_trace) : null,
                ];
            })->values()->all();

        return response()->json(['data' => ['configs' => $configs, 'automations' => $automations]]);
    }

    /** One setup's rules, read where it is set ($place), for the branches using it. */
    private function rulesFor($user, SettingsResolver $settings, \Illuminate\Database\Eloquent\Model $place, $reach): array
    {
        $stageRules = ProjectStages::forFranchises($reach, $settings);
        $this->labels = $this->statusLabels($settings, $user);
        $label = fn (array $statuses) => implode(', ', array_map(fn ($s) => $this->label($s), $statuses));
        $setting = fn (string $key) => ['value' => $settings->get($key, $place), 'set_at' => $this->setAt($key, $place)];

        $scopeStatuses = (array) $settings->get('jobs.pm_scope_statuses', $place);
        $partly = (bool) $settings->get('jobs.pm_partly_paid_only', $place);
        $inScope = fn () => ProjectStages::inScope(Job::query(), $reach, $settings);

        $stages = [];
        $seen = [];
        foreach (ProjectStages::KEYS as $key) {
            // A stage's rules, any of which puts a job in it.
            $rules = array_column($stageRules[$key] ?? [], 'rule');
            if ($rules === []) {
                continue;
            }
            foreach ($rules as $rule) {
                foreach ($rule['statuses'] as $st) {
                    $seen[$st][$key] = $rule;
                }
            }
            $stages[] = ['key' => $key, 'statuses' => array_values(array_unique(array_merge(...array_column($rules, 'statuses')))),
                'sentence' => implode(' ', array_map(fn (array $rule, int $i) => ($i ? 'Or status ' : 'Status ').$label($rule['statuses'])
                    .($rule['scheduled'] === null ? '' : ($rule['scheduled'] ? ', scheduled' : ', not scheduled'))
                    .($rule['due'] === null ? '' : ($rule['due'] ? ', money still due' : ', nothing due')).'.', $rules, array_keys($rules))),
                'count' => ProjectStages::scope($inScope(), $stageRules[$key])->count()];
        }

        $warnings = [];
        foreach ($scopeStatuses as $st) {
            if (! isset($seen[$st])) {
                $n = $inScope()->where('status', $st)->count();
                $warnings[] = "\"{$this->label($st)}\" is in scope but in no stage, so its {$n} job(s) show in no tab.";
            }
        }
        // Two stages on one status are fine when schedule or money keeps them apart; warn only when they overlap.
        $apart = fn (array $a, array $b) => ($a['scheduled'] !== null && $b['scheduled'] !== null && $a['scheduled'] !== $b['scheduled'])
            || ($a['due'] !== null && $b['due'] !== null && $a['due'] !== $b['due']);
        foreach ($seen as $st => $byKey) {
            $keys = array_keys($byKey);
            foreach ($keys as $i => $a) {
                foreach (array_slice($keys, $i + 1) as $b) {
                    if (! $apart($byKey[$a], $byKey[$b])) {
                        $warnings[] = "\"{$this->label($st)}\" is in both {$a} and {$b}, so a job can count twice.";
                    }
                }
            }
        }

        $warnDays = (int) $settings->get('jobs.pm_warn_days', $place);
        $email = (string) $settings->get('jobs.pm_warning_email', $place);
        $stoppedDays = (int) $settings->get('jobs.pm_stopped_days', $place);

        return [
            'place' => ['type' => strtolower(class_basename($place)), 'name' => $place->name ?? null],
            'scope' => [
                'statuses' => $scopeStatuses, 'partly_paid_only' => $partly,
                'set_at' => $this->setAt('jobs.pm_scope_statuses', $place),
                'sentence' => 'Project Management shows jobs '.($scopeStatuses === [] ? 'in any status' : 'in '.$label($scopeStatuses))
                    .($partly ? ' that have a payment and still owe money.' : '.'),
                'count' => $inScope()->count(),
            ],
            'stages' => $stages,
            'stages_set_at' => $this->setAt('jobs.pm_stages', $place),
            'warnings' => $warnings,
            'alerts' => [
                'warn_days' => $setting('jobs.pm_warn_days'),
                'warning_email' => $setting('jobs.pm_warning_email'),
                'stopped_days' => $setting('jobs.pm_stopped_days'),
                'cost_alert_percent' => $setting('jobs.pm_cost_alert_percent'),
                'redo_words' => $setting('jobs.pm_redo_words'),
                'dispute_words' => $setting('jobs.pm_dispute_words'),
                'task_group' => $setting('jobs.pm_task_group'),
            ],
            'server_jobs' => [
                ['name' => 'Paid but not started warning', 'when' => 'Every day at 8:20 AM Eastern',
                    'what' => $email === ''
                        ? 'Sends nothing: no warning email is set.'
                        : "Emails {$email} the jobs in Project Management that were first paid {$warnDays} or more days ago and are still waiting review, waiting to be scheduled, or scheduled. Changes nothing on the jobs."],
                ['name' => 'Payments stopped', 'when' => 'Worked out whenever the page loads',
                    'what' => "A job counts when it has a payment, still owes money, and its last payment is more than {$stoppedDays} days old. Changes nothing."],
                ['name' => 'Estimate aging', 'when' => 'Every day at 8:10 AM Eastern',
                    'what' => 'Raises "job.estimate_aging" for jobs in '.$label((array) $settings->get('jobs.estimate_aging_statuses', $user->organization))
                        .' (set for the whole organization), so a rule can act on them. Changes nothing by itself.'],
            ],
        ];
    }

    /** Where a setting's value comes from for this place: franchise, company, parent company, organization, or default. */
    private function setAt(string $key, \Illuminate\Database\Eloquent\Model $place): string
    {
        $chain = match (true) {
            $place instanceof \App\Models\Franchise => array_filter([$place, $place->company, $place->company?->parent, $place->organization]),
            $place instanceof \App\Models\Company => array_filter([$place, $place->parent, $place->organization]),
            default => [$place],
        };
        foreach ($chain as $link) {
            if (\App\Models\Setting::query()->where('key', $key)->where('scope_type', $link->getMorphClass())->where('scope_id', $link->getKey())->exists()
                || \App\Models\Setting::query()->where('key', $key)->where('scope_type', strtolower(class_basename($link)))->where('scope_id', $link->getKey())->exists()) {
                return strtolower(class_basename($link)).': '.($link->name ?? '');
            }
        }

        return 'default';
    }

    private function conditionsSentence(?array $conditions): string
    {
        $parts = [];
        foreach ((array) ($conditions['all'] ?? $conditions['any'] ?? []) as $c) {
            $value = is_array($c['value'] ?? null) ? implode(' or ', $c['value']) : (string) ($c['value'] ?? '');
            $field = str_replace('_', ' ', (string) ($c['field'] ?? 'value'));
            $parts[] = $field.' '.match ($c['op'] ?? 'eq') { 'eq' => 'is', 'neq' => 'is not', 'contains' => 'contains', 'in' => 'is one of', default => $c['op'] }.' '.($field === 'status' ? $this->label($value) : $value);
        }

        return $parts === [] ? 'Always.' : 'When '.implode(isset($conditions['any']) ? ' or ' : ' and ', $parts).'.';
    }

    private function actionSentence(array $a): string
    {
        return match ($a['type'] ?? '') {
            'workiz.job_update' => 'In Workiz: '.implode('; ', array_filter([
                filled($a['status'] ?? null) ? 'set status '.$a['status'] : null,
                filled($a['sub_status'] ?? null) ? 'sub-status '.$a['sub_status'] : null,
                filled($a['tags'] ?? null) ? 'add tags '.(is_array($a['tags']) ? implode(', ', $a['tags']) : $a['tags']) : null,
                ! empty($a['unschedule']) ? 'remove the scheduled time' : null,
            ])).'.',
            'notify' => (! empty($a['to_job_technicians']) ? "Tell the job's technician" : 'Send a notification').': "'.($a['message'] ?? '').'".',
            'task.create' => 'Add a Task Board card "'.($a['title'] ?? '').'"'.(filled($a['group'] ?? null) ? ' to the "'.$a['group'].'" group' : '')
                .' on the job\'s branch board'.(! empty($a['values']) ? ', filling '.implode(', ', array_keys((array) $a['values'])) : '').'.',
            default => ucfirst(str_replace(['.', '_'], ' ', (string) ($a['type'] ?? 'step'))).'.',
        };
    }

    /** The earliest moment any of these jobs entered its current status: its last status change, else its creation. */
    private function enteredAt(\Illuminate\Database\Eloquent\Builder $jobs): ?string
    {
        $rows = $jobs->get(['id', 'created_at']);
        if ($rows->isEmpty()) {
            return null;
        }
        $changed = AutomationEvent::query()->where('event_key', 'job.status_changed')->where('subject_type', Job::class)
            ->whereIn('subject_id', $rows->modelKeys())->groupBy('subject_id')->selectRaw('subject_id, MAX(occurred_at) at')->pluck('at', 'subject_id');

        return $rows->map(fn (Job $j) => (string) ($changed[$j->id] ?? $j->created_at))->min();
    }

    private function pmUser(Request $request)
    {
        $user = $request->user();
        abort_unless($user->hasPermission('project_management.view') && $user->hasPermission('jobs.view'), Response::HTTP_FORBIDDEN, 'Project Management is not in your role.');

        return $user;
    }

    private function stageFilters(): array
    {
        return [
            'franchise_id' => ['nullable', 'uuid'],
            'service_area_id' => ['nullable', 'uuid'],
            'q' => ['nullable', 'string', 'max:100'],
            'redo' => ['nullable', 'boolean'],
            'dispute' => ['nullable', 'boolean'],
            'stopped' => ['nullable', 'boolean'],
            'external' => ['nullable', 'boolean'],
            'second_visit' => ['nullable', 'boolean'],
            // Paid, not started; over_limit: past the warning's days.
            'waiting_to_start' => ['nullable', 'boolean'],
            'over_limit' => ['nullable', 'boolean'],
            // A reason's value, or `none` for no reason picked (UI/UX, 2026-10-23).
            'review_reason' => ['nullable', 'array'],
            'review_reason.*' => ['string', 'max:100'],
        ];
    }

    /** The jobs in reach under the Jobs tab's filters. */
    private function stageFiltered($user, SettingsResolver $settings, array $input): \Illuminate\Database\Eloquent\Builder
    {
        $flag = fn (string $k) => array_key_exists($k, $input) && $input[$k] !== null ? filter_var($input[$k], FILTER_VALIDATE_BOOL) : null;
        $org = $this->pmScope($user);

        $reach = \App\Models\Franchise::query()->whereIn('id', ViewScope::forUser($user)->franchiseIds())
            ->when($input['franchise_id'] ?? null, fn ($q, $id) => $q->whereKey($id))->get();

        return ProjectStages::inScope(Job::query(), $reach, $settings)
            ->when($input['service_area_id'] ?? null, fn ($q, $id) => $q->where('service_area_id', $id))
            ->when(filled($input['q'] ?? null), fn ($q) => $this->search($q, $input['q']))
            ->when($flag('redo') !== null, fn ($q) => $flag('redo')
                ? ProjectStages::anyWord($q, (array) $settings->get('jobs.pm_redo_words', $org))
                : $q->whereNotIn('id', ProjectStages::anyWord(Job::query(), (array) $settings->get('jobs.pm_redo_words', $org))->select('id')))
            ->when($flag('dispute'), fn ($q) => ProjectStages::anyWord($q, (array) $settings->get('jobs.pm_dispute_words', $org)))
            ->when($flag('stopped'), fn ($q) => ProjectStages::stopped($q, (int) $settings->get('jobs.pm_stopped_days', $org)))
            ->when($flag('external') !== null, fn ($q) => $flag('external') ? $q->whereNotNull('external_company') : $q->whereNull('external_company'))
            ->when($flag('second_visit') !== null, fn ($q) => $q->where('needs_second_visit', $flag('second_visit')))
            ->when($input['review_reason'] ?? null, fn ($q, $reasons) => $q->where(fn ($w) => $w
                ->whereIn('review_reason', array_diff($reasons, ['none']))
                ->when(in_array('none', $reasons, true), fn ($n) => $n->orWhereNull('review_reason'))))
            ->when($flag('waiting_to_start'), function ($q) use ($user, $settings, $flag, $org) {
                $rules = ProjectStages::forFranchises(\App\Models\Franchise::query()->whereIn('id', ViewScope::forUser($user)->franchiseIds())->get(), $settings);
                $q->where(fn ($any) => collect(ProjectStages::NOT_STARTED)->each(fn ($k) => $any->orWhere(fn ($one) => ProjectStages::scope($one, $rules[$k] ?? null))))
                    ->whereIn('id', $flag('over_limit')
                        ? JobMoney::firstPaidBy(now()->subDays((int) $settings->get('jobs.pm_warn_days', $org))->endOfDay())
                        : JobMoney::paidJobIds());
            });
    }

    /** Job and Workiz numbers, the customer's name, phone and address, the tech (Jose, 2026-10-21: "search bar"). */
    private function search($q, string $text)
    {
        $like = '%'.trim($text).'%';
        $digits = preg_replace('/\D/', '', $text);

        return $q->where(fn ($w) => $w->where('number', 'like', $like)
            ->orWhereHas('workizJob', fn ($z) => $z->where('serial_id', 'like', $like))
            ->orWhereHas('performedBy', fn ($u) => $u->where('name', 'like', $like))
            ->orWhereHas('customer', fn ($c) => $c->where('name', 'like', $like)
                ->orWhere('address_line1', 'like', $like)->orWhere('city', 'like', $like)->orWhere('postal_code', 'like', $like)
                ->when(strlen($digits) >= 4, fn ($p) => $p->orWhere('phone', 'like', '%'.$digits.'%'))));
    }

    /**
     * One line per step a run took: {outcome, type, what, reason?}. A skipped
     * run is one line with the reason the rule did not match.
     *
     * @return list<array<string, mixed>>
     */
    private function runLines(AutomationRun $r): array
    {
        if ($r->status === 'skipped') {
            return [['outcome' => 'skipped', 'type' => null, 'what' => 'Skipped', 'reason' => $this->skipReason((array) $r->condition_trace)]];
        }
        $lines = [];
        foreach ((array) $r->action_results as $a) {
            $outcome = ! empty($a['skipped']) ? 'skipped' : ($a['state'] ?? (($a['ok'] ?? false) ? 'succeeded' : 'failed'));
            $label = match (true) {
                str_starts_with((string) ($a['type'] ?? ''), 'workiz.') => 'Workiz',
                ($a['type'] ?? null) === 'notify' => 'Notification',
                str_starts_with((string) ($a['type'] ?? ''), 'task.') => 'Task Board',
                in_array($a['type'] ?? null, ['sms.send', 'phone.sms.send', 'email.send', 'whatsapp.send'], true) => 'Message to customer',
                default => ucfirst(str_replace(['.', '_'], ' ', (string) ($a['type'] ?? 'Step'))),
            };
            $lines[] = ['outcome' => $outcome, 'type' => $a['type'] ?? null, 'what' => $label.': '.($a['detail'] ?? $outcome)]
                + ($outcome === 'succeeded' ? [] : ['reason' => $a['detail'] ?? $r->error]);
        }

        return $lines !== [] ? $lines : [['outcome' => $r->status, 'type' => null, 'what' => 'Ran, no steps', 'reason' => $r->error]];
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
        $run = ($this->runs[$job->id] ?? collect())->where('status', 'succeeded')->first(function (AutomationRun $r) use ($e, $after, $norm) {
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
