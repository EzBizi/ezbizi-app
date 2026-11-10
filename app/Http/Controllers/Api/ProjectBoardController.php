<?php

namespace App\Http\Controllers\Api;

use App\Models\Company;
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
        'serviceArea:id,name',
        'performedBy:id,name',
    ];

    /** Jose, 2026-11-10: the tag "Ready to schedule" adds; the status stays. */
    public const READY_TAG = 'READY TO SCHEDULE';

    /** GET /api/v1/project-board/jobs?tab=&franchise_id=&q= */
    public function jobs(Request $request, SettingsResolver $settings): JsonResponse
    {
        $user = $request->user();
        abort_unless($user->hasPermission('project_board.view') && $user->hasPermission('jobs.view'),
            Response::HTTP_FORBIDDEN, 'Project Management is not in your role.');
        $input = $request->validate([
            'tab' => ['nullable', 'in:'.implode(',', self::TABS)],
            'franchise_id' => ['nullable', 'uuid'],
            'company_id' => ['nullable', 'uuid'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:200'],
            'q' => ['nullable', 'string', 'max:200'],
        ]);
        $tab = $input['tab'] ?? self::TABS[0];

        // The Franchise filter lists the franchises in the viewer's reach, in the
        // company they are looking at; a franchise outside it is refused, not widened.
        $reach = ViewScope::forUser($user)->franchiseIds();
        // A switched-off branch is not a filter choice (Jose, 2026-11-10).
        $franchises = Franchise::query()->whereIn('id', $reach)->switchedOn()->with('company:id,name')->orderBy('name')->get(['id', 'name', 'company_id']);
        $companies = Company::query()->get(['id', 'name', 'parent_company_id']);
        $chosen = $input['franchise_id'] ?? null;
        abort_if($chosen !== null && ! in_array($chosen, $reach, true), Response::HTTP_FORBIDDEN, 'That franchise is not in your view.');
        // A company picks its own franchises and its sub-companies', in reach.
        $picked = $chosen !== null ? [$chosen] : $reach;
        if (($input['company_id'] ?? null) !== null) {
            $under = $this->descendants($companies, $input['company_id']);
            $picked = $franchises->filter(fn (Franchise $f) => in_array($f->company_id, $under, true))->pluck('id')->all();
            abort_if($picked === [], Response::HTTP_FORBIDDEN, 'That company is not in your view.');
        }
        $inView = fn () => Job::query()->whereIn('franchise_id', $picked);

        // Everything on this screen is the company's (Jose, 2026-11-06): read at
        // the company in view, the organization's where it set nothing.
        $place = ViewScope::forUser($user)->settingsScope();
        $place = $place instanceof Franchise ? ($place->company ?? $user->organization) : $place;
        $rules = (array) $settings->get('project_board.tabs', $place);
        $partTags = array_map(fn ($t) => self::tagKey((string) $t), (array) $settings->get('project_board.part_tags', $place));

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
        $extras = $this->extras($jobs->pluck('id')->all());

        return response()->json([
            'data' => $jobs->map(fn (Job $job) => $this->row($job, $money, $partTags) + $this->unscheduled($job, $extras))->values(),
            'meta' => $this->pageMeta($page) + [
                'places' => $this->places($companies, $franchises),
                'franchises' => $franchises->map(fn (Franchise $f) => ['id' => $f->id, 'name' => $f->name, 'company' => $f->company?->name])->values(),
                // How the table is drawn, and where its Settings sub-tab saves.
                'columns' => $this->columns((array) $settings->get('project_board.columns', $place)),
                'labels' => (object) $settings->get('project_board.labels', $place),
                'repair_teams' => $this->repairTeams($jobs),
                'can_edit' => $user->hasPermission('jobs.edit'),
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
     * The company's saved columns, then any column added since it saved
     * (Tags, 2026-11-09), so a new column reaches companies that customised.
     *
     * @param  list<array{key: string}>  $saved
     * @return list<array<string, mixed>>
     */
    private function columns(array $saved): array
    {
        $keys = array_column($saved, 'key');
        $defaults = (array) app(\App\Support\Settings\SettingRegistry::class)->get('project_board.columns')->default;

        return [...array_values($saved), ...array_values(array_filter($defaults, fn ($c) => ! in_array($c['key'], $keys, true)))];
    }

    /**
     * GET /api/v1/project-board/options: the job statuses and tags in the
     * viewer's reach, most used first, for the Settings pickers (Jose,
     * 2026-11-09: pick real values, not typed text).
     */
    public function options(Request $request): JsonResponse
    {
        $user = $request->user();
        abort_unless($user->hasPermission('project_board.view') && $user->hasPermission('jobs.view'),
            Response::HTTP_FORBIDDEN, 'Project Management is not in your role.');
        $jobs = fn () => Job::query()->whereIn('franchise_id', ViewScope::forUser($user)->franchiseIds());

        return response()->json(['data' => [
            'statuses' => $jobs()->whereNotNull('status')->where('status', '!=', '')
                ->groupBy('status')->orderByRaw('COUNT(*) DESC')->pluck('status')->values(),
            // ponytail: reads every tagged job's tags; a tags table if this gets slow.
            'tags' => $jobs()->whereNotNull('tags')->pluck('tags')->flatten()
                ->map(fn ($t) => trim((string) $t))->filter()->countBy()->sortDesc()->keys()->values(),
        ]]);
    }

    /** @return list<string> the company and every company under it */
    private function descendants(\Illuminate\Support\Collection $companies, string $id): array
    {
        return [$id, ...$companies->where('parent_company_id', $id)->flatMap(fn (Company $c) => $this->descendants($companies, $c->id))->all()];
    }

    /**
     * The Franchise filter in hierarchy order (Jose, 2026-11-09): each company,
     * then its franchises A-Z, then its sub-companies A-Z the same way. Only
     * companies with a franchise in reach somewhere beneath them.
     *
     * @return list<array{value: string, label: string, depth: int}>
     */
    private function places(\Illuminate\Support\Collection $companies, \Illuminate\Support\Collection $franchises, ?string $parent = null, int $depth = 0): array
    {
        $out = [];
        foreach ($companies->where('parent_company_id', $parent)->sortBy(fn ($c) => mb_strtolower($c->name)) as $company) {
            $own = $franchises->where('company_id', $company->id)->sortBy(fn ($f) => mb_strtolower($f->name));
            $subs = $this->places($companies, $franchises, $company->id, $depth + 1);
            if ($own->isEmpty() && $subs === []) {
                continue;
            }
            $out[] = ['value' => 'company:'.$company->id, 'label' => $company->name, 'depth' => $depth];
            foreach ($own as $f) {
                $out[] = ['value' => $f->id, 'label' => $company->name.' · '.$f->name, 'depth' => $depth + 1];
            }
            array_push($out, ...$subs);
        }

        return $out;
    }

    /**
     * A sub-tab's rule: its statuses (any case), and optionally whether the job
     * has a date (scheduled: true / false). No statuses: nothing, never everything.
     *
     * Required tags (Jose, 2026-11-09): the job carries all of them, or any
     * one with tags_mode "any".
     *
     * @param  array{statuses?: list<string>, tags?: list<string>, tags_mode?: string, scheduled?: bool}  $rule
     */
    private function tab(Builder $jobs, array $rule): Builder
    {
        $statuses = array_values(array_filter(array_map(fn ($s) => mb_strtolower(trim((string) $s)), (array) ($rule['statuses'] ?? []))));
        if ($statuses === []) {
            return $jobs->whereRaw('1 = 0');
        }
        $jobs->whereIn(DB::raw('LOWER(TRIM(status))'), $statuses);
        $tags = array_values(array_filter(array_map(fn ($t) => trim((string) $t), (array) ($rule['tags'] ?? []))));
        if ($tags !== []) {
            $any = ($rule['tags_mode'] ?? 'all') === 'any';
            // The stored list, folded the way tagKey() folds a tag, so
            // "Deposit received" matches DEPOSIT RECEIVED (Jose, 2026-11-10).
            $folded = "REPLACE(LOWER(CAST(tags AS CHAR)), ' ', '')";
            $jobs->where(function (Builder $q) use ($tags, $any, $folded) {
                foreach ($tags as $tag) {
                    $like = '%"'.addcslashes(self::tagKey($tag), '%_\\').'"%';
                    $any ? $q->orWhereRaw("{$folded} LIKE ?", [$like]) : $q->whereRaw("{$folded} LIKE ?", [$like]);
                }
            });
        }

        // Must not have (Jose, 2026-11-10: Unscheduled leaves out READY TO SCHEDULE).
        $none = array_values(array_filter(array_map(fn ($t) => trim((string) $t), (array) ($rule['tags_none'] ?? []))));
        foreach ($none as $tag) {
            $like = '%"'.addcslashes(self::tagKey($tag), '%_\\').'"%';
            $jobs->where(fn (Builder $q) => $q->whereNull('tags')
                ->orWhereRaw("REPLACE(LOWER(CAST(tags AS CHAR)), ' ', '') NOT LIKE ?", [$like]));
        }

        return match ($rule['scheduled'] ?? null) {
            true => $jobs->whereNotNull('scheduled_for'),
            false => $jobs->whereNull('scheduled_for'),
            default => $jobs,
        };
    }

    /**
     * A tag as it is compared: any case, no spaces. A different spelling is a
     * different tag (Jose, 2026-11-10: he fixes those in Workiz).
     */
    public static function tagKey(string $tag): string
    {
        return str_replace(' ', '', mb_strtolower(trim($tag)));
    }

    /**
     * What the page's jobs need from other tables, in one query each: the
     * first deposit payment (Workiz's payment record), file counts, and the
     * newest EzBizi note.
     *
     * @param  list<string>  $ids
     */
    private function extras(array $ids): array
    {
        if ($ids === []) {
            return ['paid' => [], 'files' => [], 'notes' => []];
        }

        return [
            'paid' => \App\Models\WorkizRecord::query()->whereIn('job_id', $ids)->where('type', 'payment')
                ->whereNotNull('starts_at')->groupBy('job_id')->selectRaw('job_id, MIN(starts_at) AS at')->pluck('at', 'job_id')->all(),
            'files' => DB::table('attachments')->where('attachable_type', 'jobs')->whereIn('attachable_id', $ids)->groupBy('attachable_id')->selectRaw('attachable_id, COUNT(*) AS n')->pluck('n', 'attachable_id')->all(),
            'notes' => \App\Models\JobNote::query()->whereIn('job_id', $ids)->latest()->get(['job_id', 'body'])
                ->groupBy('job_id')->map(fn ($n) => ['count' => $n->count(), 'latest' => (string) $n->first()->body])->all(),
        ];
    }

    /**
     * Shoval's Unscheduled columns (Jose, 2026-11-10), and what "Ready to
     * schedule" still needs: Service Area, Technician, Repair Team /
     * Contractor, a file and a note.
     */
    private function unscheduled(Job $job, array $extras): array
    {
        $payload = $job->workizJob?->payload ?? [];
        // Payment Date: the first payment on the job, as Workiz stamps it
        // (account-local), e.g. Albert Johnston's deposit, 11/2/2026 11:48 AM.
        $paidAt = $extras['paid'][$job->id] ?? null;
        $paid = $paidAt === null ? null : \Illuminate\Support\Carbon::parse($paidAt);
        $since = $paid ?? $job->created_at;
        $technician = collect($payload['Team'] ?? [])->pluck('Name')->filter()->implode(', ') ?: $job->performedBy?->name;
        $workizNote = trim((string) ($payload['JobNotes'] ?? ''));
        $note = $extras['notes'][$job->id] ?? null;
        $files = (int) ($extras['files'][$job->id] ?? 0) + count((array) ($payload['Attachments'] ?? []));
        $notes = (int) ($note['count'] ?? 0) + ($workizNote !== '' ? 1 : 0);
        $row = [
            'service_area' => $job->serviceArea?->name,
            'technician' => $technician ?: null,
            'repair_team' => collect($job->tags ?? [])->filter(fn ($t) => self::isRepairTeam((string) $t))->values()->implode(', ') ?: null,
            'payment_date' => $paid?->format('Y-m-d H:i'),
            // Days in this state, from the deposit (else the job's creation).
            'status_days' => $since === null ? null : (int) floor($since->diffInDays(now(), true)),
            'attachments' => $files,
            'notes' => $note['latest'] ?? ($workizNote !== '' ? $workizNote : null),
            'notes_count' => $notes,
        ];
        $row['ready_missing'] = array_values(array_filter([
            $row['service_area'] === null ? 'Service Area' : null,
            $row['technician'] === null ? 'Technician' : null,
            $row['repair_team'] === null ? 'Repair Team / Contractor' : null,
            $files === 0 ? 'an attachment' : null,
            $notes === 0 ? 'a note' : null,
        ]));

        return $row;
    }

    /**
     * Repair Team / Contractor is the job's Workiz tag (Jose, 2026-11-10):
     * REPAIR TEAM…, … REPAIR TEAM, CONTRACTOR…, GAS CONTRACTOR, PROGRESS BY
     * SUB CONTRACTOR. By pattern, so a new name is picked up.
     */
    public static function isRepairTeam(string $tag): bool
    {
        return (bool) preg_match('/repair\s*team|contractor/i', $tag);
    }

    /** The repair-team and contractor tags on offer: every account's tag list, and those on the page's jobs. */
    private function repairTeams(\Illuminate\Support\Collection $jobs): array
    {
        return \App\Models\WorkizRecord::query()->where('type', 'tag')->get(['payload'])
            ->map(fn ($r) => trim((string) (($r->payload ?? [])['name'] ?? '')))
            ->concat($jobs->flatMap(fn (Job $j) => (array) ($j->tags ?? [])))
            ->filter(fn ($t) => $t !== '' && self::isRepairTeam($t))
            ->unique(fn ($t) => mb_strtoupper($t))->sort()->values()->all();
    }

    /**
     * PATCH /api/v1/project-board/jobs/{job}/repair-team: adds that tag, here
     * and in Workiz. Workiz's v2 only adds tags, so an earlier pick stays
     * until somebody removes it there; the screen says so.
     */
    public function repairTeam(Request $request, Job $job): JsonResponse
    {
        $this->editable($request, $job);
        $input = $request->validate(['repair_team' => ['required', 'string', 'max:120']]);
        $tag = trim($input['repair_team']);
        abort_unless(self::isRepairTeam($tag), 422, 'That is not a repair team or contractor tag.');
        $tags = array_values((array) ($job->tags ?? []));
        if (! in_array(self::tagKey($tag), array_map(fn ($t) => self::tagKey((string) $t), $tags), true)) {
            $job->tags = [...$tags, $tag];
            $job->save();
            \App\Support\Workiz\WorkizMirror::queue($job, 'tags', ['tags' => $job->tags]);
            app(\App\Support\Audit\AuditLogger::class)->log('job.repair_team_tagged', $job, actor: $request->user());
        }

        return response()->json(['data' => ['id' => $job->id, 'tags' => $job->tags]]);
    }

    /**
     * POST /api/v1/project-board/jobs/{job}/ready: Unscheduled to Ready to
     * Schedule by the READY TO SCHEDULE tag, mirrored to Workiz; refused while
     * anything the row lists as missing is.
     */
    public function ready(Request $request, Job $job): JsonResponse
    {
        $this->editable($request, $job);
        $job->loadMissing(['workizJob:id,job_id,serial_id,payload', 'serviceArea:id,name', 'performedBy:id,name']);
        $missing = $this->unscheduled($job, $this->extras([$job->id]))['ready_missing'];
        abort_if($missing !== [], 422, 'Still needs: '.implode(', ', $missing).'.');
        $tags = array_values((array) ($job->tags ?? []));
        if (! in_array(self::tagKey(self::READY_TAG), array_map(fn ($t) => self::tagKey((string) $t), $tags), true)) {
            $job->tags = [...$tags, self::READY_TAG];
            $job->save();
            \App\Support\Workiz\WorkizMirror::queue($job, 'tags', ['tags' => $job->tags]);
            app(\App\Support\Audit\AuditLogger::class)->log('job.ready_to_schedule', $job, actor: $request->user());
        }

        return response()->json(['data' => ['id' => $job->id, 'tags' => $job->tags]]);
    }

    private function editable(Request $request, Job $job): void
    {
        $user = $request->user();
        abort_unless($user->hasPermission('project_board.view') && $user->hasPermission('jobs.edit'),
            Response::HTTP_FORBIDDEN, 'Changing jobs is not in your role.');
        abort_unless(in_array($job->franchise_id, ViewScope::forUser($user)->franchiseIds(), true),
            Response::HTTP_NOT_FOUND);
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
            'tags'          => array_values((array) ($job->tags ?? [])),
            // Each tag's Workiz colour, where the mirror knows it (Jose, 2026-11-10).
            'tag_colours'   => (object) app(\App\Support\Workiz\WorkizTagColours::class)->for($job->franchise_id, (array) ($job->tags ?? [])),
            // Part status: the job's tags that the organization names as part
            // tags (project_board.part_tags), e.g. WAITING PARTS.
            'part_status'   => collect($job->tags ?? [])->filter(fn ($t) => in_array(self::tagKey((string) $t), $partTags, true))->values()->implode(', ') ?: null,
            'created'       => $job->created_at?->copy()->setTimezone($zone)->toDateString(),
            'scheduled'     => $job->scheduled_for?->toDateString(),
            // Required: what the job needs done, its line items (Workiz's until
            // EzBizi keeps its own), e.g. "Crown Repair, Damper Cap x2".
            'required'      => collect($payload['LineItems'] ?? [])
                ->map(fn ($i) => trim((string) ($i['Name'] ?? '')).((int) ($i['Quantity'] ?? 1) > 1 ? ' x'.(int) $i['Quantity'] : ''))
                ->filter(fn ($s) => $s !== '')->values()->implode("\n") ?: null,
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
