<?php

namespace App\Http\Controllers\Api;

use App\Models\Franchise;
use App\Models\Job;
use App\Models\User;
use App\Support\Jobs\JobMoney;
use App\Support\Scoping\ViewScope;
use App\Support\Settings\SettingsResolver;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

/**
 * Project Management (Jose, 2026-11-06): one table of jobs on six sub-tabs, a
 * job's place in its life from unscheduled to complete. Which statuses make
 * each sub-tab is the organization's (project_board.tabs), not this file's.
 */
class ProjectBoardController extends Controller
{
    public const TABS = ['unscheduled', 'ready_to_schedule', 'scheduled', 'in_progress', 'done_waiting_payment', 'complete'];

    private const WITH = [
        'franchise:id,name,timezone',
        'customer:id,name',
        'workizJob:id,job_id,serial_id,payload',
    ];

    /** GET /api/v1/project-board/jobs?tab=&franchise_id=&q= */
    public function jobs(Request $request, SettingsResolver $settings): JsonResponse
    {
        $user = $request->user();
        abort_unless($user->hasPermission('project_board.view') && $user->hasPermission('jobs.view'),
            Response::HTTP_FORBIDDEN, 'Project Management is not in your role.');
        $input = $request->validate([
            'tab' => ['nullable', 'in:'.implode(',', self::TABS)],
            'franchise_id' => ['nullable', 'uuid'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:200'],
            'q' => ['nullable', 'string', 'max:200'],
        ]);
        $tab = $input['tab'] ?? self::TABS[0];

        // The Franchise filter lists the franchises in the viewer's reach, in the
        // company they are looking at; a franchise outside it is refused, not widened.
        $reach = ViewScope::forUser($user)->franchiseIds();
        $franchises = Franchise::query()->whereIn('id', $reach)->with('company:id,name')->orderBy('name')->get(['id', 'name', 'company_id']);
        $chosen = $input['franchise_id'] ?? null;
        abort_if($chosen !== null && ! in_array($chosen, $reach, true), Response::HTTP_FORBIDDEN, 'That franchise is not in your view.');
        $inView = fn () => Job::query()->whereIn('franchise_id', $chosen !== null ? [$chosen] : $reach);

        // Everything on this screen is the company's (Jose, 2026-11-06): read at
        // the company in view, the organization's where it set nothing.
        $place = ViewScope::forUser($user)->settingsScope();
        $place = $place instanceof Franchise ? ($place->company ?? $user->organization) : $place;
        $rules = (array) $settings->get('project_board.tabs', $place);
        $partTags = array_map(fn ($t) => mb_strtolower(trim((string) $t)), (array) $settings->get('project_board.part_tags', $place));

        // The search box: customer name, job number (ours or Workiz's) or address.
        $term = trim((string) ($input['q'] ?? ''));
        $page = $this->tab($inView(), $rules[$tab] ?? [])
            ->when($term !== '', fn ($q) => $q->where(fn ($q) => $q
                ->where('number', 'like', "%{$term}%")
                ->orWhereHas('workizJob', fn ($w) => $w->where('serial_id', 'like', "%{$term}%"))
                ->orWhereHas('customer', fn ($c) => $c->where(fn ($c) => $c
                    ->where('name', 'like', "%{$term}%")
                    ->orWhere('address_line1', 'like', "%{$term}%")
                    ->orWhere('city', 'like', "%{$term}%")
                    ->orWhere('postal_code', 'like', "%{$term}%")))))
            ->with(self::WITH)->latest()
            ->paginate(perPage: (int) ($input['per_page'] ?? 50));
        $jobs = $page->getCollection();
        $money = $user->hasPermission('jobs.financials') ? JobMoney::of($jobs->pluck('id')) : null;

        return response()->json([
            'data' => $jobs->map(fn (Job $job) => $this->row($job, $money, $partTags))->values(),
            'meta' => $this->pageMeta($page) + [
                'franchises' => $franchises->map(fn (Franchise $f) => ['id' => $f->id, 'name' => $f->name, 'company' => $f->company?->name])->values(),
                // How the table is drawn, and where its Settings sub-tab saves.
                'columns' => array_values((array) $settings->get('project_board.columns', $place)),
                'labels' => (object) $settings->get('project_board.labels', $place),
                'settings_scope' => ['scope_type' => $place instanceof \App\Models\Company ? 'company' : 'organization', 'scope_id' => $place->getKey()],
            ] +($page->currentPage() > 1 ? [] : ['counts' => collect(self::TABS)
                ->mapWithKeys(fn (string $t) => [$t => $this->tab($inView(), $rules[$t] ?? [])->count()])->all()]),
        ]);
    }

    /**
     * Jobs per sub-tab in the viewer's reach, the board's own counts, for the
     * dashboard glance so the two cannot disagree.
     *
     * @return array<string, int>
     */
    public function countsFor(User $user, SettingsResolver $settings): array
    {
        $reach = ViewScope::forUser($user)->franchiseIds();
        $place = ViewScope::forUser($user)->settingsScope();
        $place = $place instanceof Franchise ? ($place->company ?? $user->organization) : $place;
        $rules = (array) $settings->get('project_board.tabs', $place);

        return collect(self::TABS)->mapWithKeys(fn (string $t) => [$t => $this->tab(Job::query()->whereIn('franchise_id', $reach), $rules[$t] ?? [])->count()])->all();
    }

    /**
     * A sub-tab's rule: its statuses (any case), and optionally whether the job
     * has a date (scheduled: true / false). No statuses: nothing, never everything.
     *
     * @param  array{statuses?: list<string>, scheduled?: bool}  $rule
     */
    private function tab(Builder $jobs, array $rule): Builder
    {
        $statuses = array_values(array_filter(array_map(fn ($s) => mb_strtolower(trim((string) $s)), (array) ($rule['statuses'] ?? []))));
        if ($statuses === []) {
            return $jobs->whereRaw('1 = 0');
        }
        $jobs->whereIn(DB::raw('LOWER(TRIM(status))'), $statuses);

        return match ($rule['scheduled'] ?? null) {
            true => $jobs->whereNotNull('scheduled_for'),
            false => $jobs->whereNull('scheduled_for'),
            default => $jobs,
        };
    }

    /** @param  list<string>  $partTags */
    private function row(Job $job, ?array $money, array $partTags): array
    {
        $m = $money[$job->id] ?? ['total' => null, 'paid' => 0, 'due' => 0];
        $zone = $job->franchise?->timezone ?: 'UTC';
        $payload = $job->workizJob?->payload ?? [];

        return [
            'id'            => $job->id,
            'number'        => $job->number,
            'workiz_id'     => $job->workizJob?->serial_id === null ? null : (string) $job->workizJob->serial_id,
            'customer'      => $job->customer?->name,
            'franchise'     => $job->franchise?->name,
            'status'        => $job->franchise ? Job::labelFor((string) $job->status, $job->franchise) : $job->status,
            'job_type'      => $job->job_type,
            // Part status: the job's tags that the organization names as part
            // tags (project_board.part_tags), e.g. WAITING PARTS.
            'part_status'   => collect($job->tags ?? [])->filter(fn ($t) => in_array(mb_strtolower(trim((string) $t)), $partTags, true))->values()->implode(', ') ?: null,
            'created'       => $job->created_at?->copy()->setTimezone($zone)->toDateString(),
            'scheduled'     => $job->scheduled_for?->toDateString(),
            // Required: what the job needs done, its line items (Workiz's until
            // EzBizi keeps its own), e.g. "Crown Repair, Damper Cap x2".
            'required'      => collect($payload['LineItems'] ?? [])
                ->map(fn ($i) => trim((string) ($i['Name'] ?? '')).((int) ($i['Quantity'] ?? 1) > 1 ? ' x'.(int) $i['Quantity'] : ''))
                ->filter(fn ($s) => $s !== '')->values()->implode(', ') ?: null,
            // Financials only, and absent without it.
            ...($money !== null ? [
                'paid'     => $m['paid'],
                'pending'  => $m['due'],
                'total'    => $m['total'] ?? 0,
                'currency' => $job->currency,
            ] : []),
        ];
    }
}
