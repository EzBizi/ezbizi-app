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
        abort_unless($user->hasPermission('project_management.view'), Response::HTTP_FORBIDDEN, 'Project Management is not in your role.');

        $input = $request->validate([
            'stage' => ['nullable', Rule::in(['waiting', 'partial', 'full', 'closed'])],
            'franchise_id' => ['nullable', 'uuid'],
            'min_days' => ['nullable', 'integer', 'min:0'],
            'q' => ['nullable', 'string', 'max:100'],
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
            ->where(fn ($q) => $q->whereIn('status', $watched)->orWhereIn('id', $left))
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
            // Closest to the cancel first; stopped and closed clocks after.
            ->sortBy(fn (array $r) => $r['waiting']['deadline_at'] ?? '9999')
            ->values();

        return response()->json(['data' => $rows, 'meta' => ['watched' => $watched, 'deadline_days' => EstimateAging::DAYS]]);
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
            'timeline' => $timeline->sortBy('at')->values()->all(),
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
