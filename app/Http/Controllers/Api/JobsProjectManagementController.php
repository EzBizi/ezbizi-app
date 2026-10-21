<?php

namespace App\Http\Controllers\Api;

use App\Models\AutomationEvent;
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
            'to' => ['nullable', 'required_if:filter,recently_paid', 'date_format:Y-m-d'],
        ]);

        $money = $user->hasPermission('jobs.financials');
        $watched = (array) $settings->get('jobs.estimate_aging_statuses', $user->organization);
        if ($watched === []) {
            return response()->json(['data' => [], 'meta' => ['watched' => [], 'deadline_days' => EstimateAging::DAYS]]);
        }

        $left = AutomationEvent::query()->where('event_key', 'job.status_changed')->where('subject_type', Job::class)
            ->where('occurred_at', '>=', now()->subDays(self::RECENT_DAYS))
            ->whereIn(\DB::raw("JSON_UNQUOTE(JSON_EXTRACT(payload, '$.before.status'))"), $watched)
            ->pluck('subject_id');

        $jobs = Job::query()
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
            })
            ->when(filled($input['ids'] ?? null),
                fn ($q) => $q->whereIn('id', $input['ids']),
                fn ($q) => $q->where(fn ($w) => $w->whereIn('status', $watched)->orWhereIn('id', $left)))
            ->when(($input['filter'] ?? null) === 'recently_paid', fn ($q) => $q->whereIn('id',
                $this->paymentsIn($input['from'], $input['to'])->select('job_id')))
            ->with(['franchise:id,name,timezone', 'customer:id,name', 'workizJob'])
            ->get();

        $ids = $jobs->modelKeys();
        $estimates = EstimateAging::latestEstimateDates($ids);
        $paid = EstimateAging::paidCents($ids);
        $payments = WorkizRecord::query()->whereIn('job_id', $ids)->where('type', 'payment')->where('status', 'succeeded')
            ->orderBy('starts_at')->get()->groupBy('job_id');
        $events = AutomationEvent::query()->whereIn('subject_id', $ids)->where('subject_type', Job::class)
            ->whereIn('event_key', ['job.status_changed', 'job.updated'])->orderBy('occurred_at')->get()->groupBy('subject_id');

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

        return response()->json(['data' => $rows, 'meta' => ['watched' => $watched, 'deadline_days' => EstimateAging::DAYS]]);
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
        ]);

        $jobs = Job::query()->whereIn('franchise_id', ViewScope::forUser($user)->franchiseIds())
            ->when($input['franchise_id'] ?? null, fn ($q, $id) => $q->where('franchise_id', $id))
            ->select('id');
        [$from, $to] = [$input['from'].' 00:00:00', $input['to'].' 23:59:59'];

        $bucket = fn ($ids) => ['count' => count($ids), 'job_ids' => array_values(array_unique($ids))];
        $changes = fn (string $key) => AutomationEvent::query()->where('event_key', $key)->where('subject_type', Job::class)
            ->whereIn('subject_id', $jobs)->whereBetween('occurred_at', [$from, $to]);

        $payments = $this->paymentsIn($input['from'], $input['to'])->whereIn('job_id', $jobs)->get(['job_id', 'amount', 'payload']);
        $paymentBucket = $bucket($payments->pluck('job_id')->all());
        $paymentBucket['count'] = count($paymentBucket['job_ids']);
        if ($user->hasPermission('jobs.financials')) {
            $paymentBucket['amount_minor'] = (int) $payments->sum(fn (WorkizRecord $p) => round(((float) $p->amount) * 100)
                * (($p->payload['type'] ?? '') === 'refund' ? -1 : 1));
        }

        $statusIds = $changes('job.status_changed')
            ->whereRaw("JSON_UNQUOTE(JSON_EXTRACT(payload, '$.before.status')) <> JSON_UNQUOTE(JSON_EXTRACT(payload, '$.after.status'))")
            ->pluck('subject_id')->all();
        $tagIds = $changes('job.updated')->whereRaw("JSON_CONTAINS_PATH(payload, 'one', '$.after.tags')")->pluck('subject_id')->all();

        // "Cancelled" is the organization's word (jobs.cancelled_statuses); none named, none counted.
        $cancelled = (array) $settings->get('jobs.cancelled_statuses', $user->organization);
        $cancelIds = $cancelled === [] ? [] : $changes('job.status_changed')
            ->whereIn(\DB::raw("JSON_UNQUOTE(JSON_EXTRACT(payload, '$.after.status'))"), $cancelled)->pluck('subject_id')->all();

        // Waiting jobs, nothing paid, whose cancel day falls in the window.
        $watched = (array) $settings->get('jobs.estimate_aging_statuses', $user->organization);
        $waitingIds = $watched === [] ? [] : (clone $jobs)->whereIn('status', $watched)->pluck('id')->all();
        $dates = EstimateAging::latestEstimateDates($waitingIds);
        $paid = EstimateAging::paidCents($waitingIds);
        $dueIds = array_keys(array_filter($dates, function (string $d, string $id) use ($paid, $input) {
            $deadline = Carbon::parse($d)->addDays(EstimateAging::DAYS)->toDateString();

            return ($paid[$id] ?? 0) <= 0 && $deadline >= $input['from'] && $deadline <= $input['to'];
        }, ARRAY_FILTER_USE_BOTH));

        return response()->json(['data' => [
            'from' => $input['from'],
            'to' => $input['to'],
            'payments' => $paymentBucket,
            'status_changes' => $bucket($statusIds),
            'tag_changes' => $bucket($tagIds),
            'cancellations' => $bucket($cancelIds),
            'deadlines_due' => $bucket($dueIds),
        ]]);
    }

    /** Succeeded Workiz payments dated in the window, until EzBizi records its own. */
    private function paymentsIn(string $from, string $to): \Illuminate\Database\Eloquent\Builder
    {
        return WorkizRecord::query()->where('type', 'payment')->where('status', 'succeeded')
            ->whereBetween('starts_at', [$from.' 00:00:00', $to.' 23:59:59']);
    }

    /** One line a person reads, for the drill-down (UI/UX, 2026-10-22). */
    private function what(array $step): string
    {
        return match ($step['kind']) {
            'entered' => 'Entered '.$step['status'],
            'payment' => $step['marks'] === 'full' ? 'Payment received, paid in full' : 'Payment received, partly paid',
            'status' => 'Status '.($step['from'] ?? 'none').' → '.($step['to'] ?? 'none'),
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
        $stage = ! $open ? 'closed' : ($paid <= 0 ? 'waiting' : ($total > 0 && $paid >= $total ? 'full' : 'partial'));

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
                    'to' => $e->payload['after']['status'] ?? null, 'by' => $this->by($e)]);
            } elseif (array_key_exists('tags', $e->payload['after'] ?? [])) {
                $before = (array) ($e->payload['before']['tags'] ?? []);
                $after = (array) $e->payload['after']['tags'];
                $timeline->push(['kind' => 'tags', 'at' => $e->occurred_at->toIso8601String(), 'by' => $this->by($e),
                    'tags' => ['added' => array_values(array_diff($after, $before)), 'removed' => array_values(array_diff($before, $after))]]);
            }
        }

        $today = now($job->franchise?->timezone ?: 'UTC')->startOfDay();

        return [
            'id' => $job->id,
            'number' => $job->number,
            'title' => $job->title,
            'status' => $job->status,
            'tags' => $job->tags ?? [],
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
