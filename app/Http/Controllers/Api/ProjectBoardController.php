<?php

namespace App\Http\Controllers\Api;

use App\Models\Company;
use App\Models\Customer;
use App\Models\Franchise;
use App\Models\Job;
use App\Models\JobNote;
use App\Models\JobPart;
use App\Models\User;
use App\Models\WorkizRecord;
use App\Support\Audit\AuditLogger;
use App\Support\Jobs\JobMoney;
use App\Support\Jobs\ScheduleTexts;
use App\Support\Jobs\WorkizExtras;
use App\Support\Scoping\ViewScope;
use App\Support\Settings\SettingRegistry;
use App\Support\Settings\SettingsResolver;
use App\Support\Workiz\WorkizMirror;
use App\Support\Workiz\WorkizTagColours;
use App\Support\Workiz\WorkizTags;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
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

    /**
     * What each sortable column orders by, in SQL, so the sort covers the
     * whole tab and Load more continues it (Jose, 2026-11-10: "this should
     * take into account all the list, not just the loaded ones").
     */
    /** The company's repair tags (empty: the name rule), and what Ready needs. */
    private array $repairTags = [];

    private array $readyRequires = ['service_area', 'technician', 'repair_team', 'attachment', 'note'];

    /** Reads the company's Project Management settings for [user]'s view. */
    private function settingsFor(User $user): Model
    {
        $place = ViewScope::forUser($user)->settingsScope();
        $place = $place instanceof Franchise ? ($place->company ?? $user->organization) : $place;
        $settings = app(SettingsResolver::class);
        $this->repairTags = array_values(array_filter(array_map(fn (array $e) => self::tagKey($e['name']),
            self::repairEntries($settings->get('project_board.repair_tags', $place)))));
        $this->readyRequires = array_values((array) $settings->get('project_board.ready_requires', $place));

        return $place;
    }

    /** A repair-team / contractor tag: the company's list, else the name rule. */
    private function isRepair(string $tag): bool
    {
        return $this->repairTags === [] ? self::isRepairTeam($tag) : in_array(self::tagKey($tag), $this->repairTags, true);
    }

    private const SORTS = [
        'number' => 'jobs.number',
        'workiz_id' => '(SELECT w.serial_id FROM workiz_jobs w WHERE w.job_id = jobs.id LIMIT 1)',
        'customer' => '(SELECT c.name FROM customers c WHERE c.id = jobs.customer_id)',
        'franchise' => '(SELECT f.name FROM franchises f WHERE f.id = jobs.franchise_id)',
        'service_area' => '(SELECT a.name FROM service_areas a WHERE a.id = jobs.service_area_id)',
        'status' => 'jobs.status',
        'job_type' => 'jobs.job_type',
        'technician' => "(SELECT JSON_UNQUOTE(JSON_EXTRACT(w.payload, '$.Team[0].Name')) FROM workiz_jobs w WHERE w.job_id = jobs.id LIMIT 1)",
        'tags' => 'CAST(jobs.tags AS CHAR)',
        'total' => 'jobs.amount_minor',
        'created' => 'jobs.created_at',
        'scheduled' => 'jobs.scheduled_for',
        'payment_date' => "(SELECT MIN(r.starts_at) FROM workiz_records r WHERE r.job_id = jobs.id AND r.type = 'payment')",
        // Days in this state: older first is more days, so it sorts the other way.
        'status_days' => "COALESCE((SELECT MIN(r.starts_at) FROM workiz_records r WHERE r.job_id = jobs.id AND r.type = 'payment'), jobs.created_at)",
        'attachments' => "(SELECT COUNT(*) FROM attachments t WHERE t.attachable_type = 'jobs' AND t.attachable_id = jobs.id)",
    ];

    /** The chosen sort, empty values last; newest first when none. A tie keeps the job's id order, so pages never overlap. */
    private function sorted(Builder $jobs, ?string $key, string $dir): void
    {
        if ($key === null) {
            $jobs->latest()->orderBy('jobs.id');

            return;
        }
        $sql = self::SORTS[$key];
        $dir = $key === 'status_days' ? ($dir === 'asc' ? 'desc' : 'asc') : $dir;
        $jobs->orderByRaw("({$sql}) IS NULL")->orderByRaw("{$sql} {$dir}")->orderBy('jobs.id');
    }

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
            'sort' => ['nullable', 'in:'.implode(',', array_keys(self::SORTS))],
            'dir' => ['nullable', 'in:asc,desc'],
        ]);
        $tab = $input['tab'] ?? self::TABS[0];

        // The Franchise filter lists the franchises in the viewer's reach, in the
        // company they are looking at; a franchise outside it is refused, not widened.
        $reach = ViewScope::forUser($user)->franchiseIds();
        // A switched-off branch is not a filter choice (Jose, 2026-11-10).
        $franchises = Franchise::query()->whereIn('id', $reach)->switchedOn()->with('company:id,name')->orderBy('name')->get(['id', 'name', 'company_id']);
        // Only the companies inside the view (Jose, 2026-11-10: "offer only
        // the available ones"): those owning a franchise listed, and the
        // view's own company and sub-companies; never a switched-off one.
        $visible = $franchises->pluck('company_id')->merge(ViewScope::forUser($user)->companyIds())->unique()->values();
        $companies = Company::query()->whereIn('id', $visible)->switchedOn()->get(['id', 'name', 'parent_company_id']);
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
        $place = $this->settingsFor($user);
        $rules = (array) $settings->get('project_board.tabs', $place);
        $partTags = array_map(fn ($t) => self::tagKey((string) $t), (array) $settings->get('project_board.part_tags', $place));

        // The search box: customer name, job number (ours or Workiz's), address or technician.
        $term = trim((string) ($input['q'] ?? ''));
        $filtered = $this->tab($inView(), $rules[$tab] ?? [])
            ->when($term !== '', fn ($q) => $q->where(fn ($q) => $q
                ->where('number', 'like', "%{$term}%")
                ->orWhereHas('workizJob', fn ($w) => $w->where('serial_id', 'like', "%{$term}%")
                    // The technician (Jose, 2026-11-10): Workiz's Team names.
                    ->orWhereRaw("CAST(JSON_EXTRACT(payload, '$.Team') AS CHAR) LIKE ?", ["%{$term}%"]))
                ->orWhereHas('performedBy', fn ($u) => $u->where('name', 'like', "%{$term}%"))
                ->orWhereHas('customer', fn ($c) => $c->where(fn ($c) => $c
                    ->where('name', 'like', "%{$term}%")
                    ->orWhere('address_line1', 'like', "%{$term}%")
                    ->orWhere('city', 'like', "%{$term}%")
                    ->orWhere('postal_code', 'like', "%{$term}%")
                    ->orWhere(fn ($p) => Customer::phoneLike($p, $term))))));
        $page = (clone $filtered)->with(self::WITH)
            ->tap(fn (Builder $q) => $this->sorted($q, $input['sort'] ?? null, $input['dir'] ?? 'asc'))
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
                'can_edit' => $user->hasPermission('jobs.edit'),
                // Columns a header click can sort by, over the whole tab.
                'sortable' => array_keys(self::SORTS),
                // Over the whole filtered tab, not the page (Jose, 2026-11-10:
                // Total Paid, Total Remaining Balance, Total Amount).
                ...($money !== null ? ['totals' => [
                    'paid' => JobMoney::sum($filtered, 'paid'),
                    'due' => JobMoney::sum($filtered, 'due'),
                    'total' => JobMoney::sum($filtered, 'total'),
                    // Desk, 2026-11-12: paid + remaining overshot the total by the overpaid jobs.
                    'over' => JobMoney::sum($filtered, 'over'),
                    'currency' => 'USD',
                ]] : []),
                'status_days' => (object) $settings->get('project_board.status_days', $place),
                'settings_scope' => ['scope_type' => $place instanceof Company ? 'company' : 'organization', 'scope_id' => $place->getKey()],
            ] + ($page->currentPage() > 1 ? [] : ['counts' => collect(self::TABS)
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
        $defaults = (array) app(SettingRegistry::class)->get('project_board.columns')->default;

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
            'tags' => $tags = $jobs()->whereNotNull('tags')->pluck('tags')->flatten()
                ->map(fn ($t) => trim((string) $t))->filter()->countBy()->sortDesc()->keys()->values(),
            // In their Workiz colours, read from an account inside the view.
            // For a repair team's "user" (Jose, 2026-11-10).
            'repair_colours' => WorkizTags::COLORS,
            'users' => User::query()->where('organization_id', $user->organization_id)->orderBy('name')->get(['id', 'name']),
            // For "Roles that can be picked as Technician" (Jose, 2026-11-16).
            'roles' => \App\Models\Role::query()->orderBy('name')->pluck('name')->unique()->values(),
            'tag_colours' => (object) collect(ViewScope::forUser($user)->switchedOnFranchiseIds())
                ->reduce(fn (array $c, string $f) => $c + app(WorkizTagColours::class)->for($f, $tags->all()), []),
        ]]);
    }

    /**
     * The repair team / contractor list, managed here and pushed to every
     * Workiz account (Jose, 2026-11-10): each a tag with a phone or a user.
     * Older lists were bare tag names.
     *
     * @return list<array{name: string, phone: ?string, user_id: ?string}>
     */
    public static function repairEntries(mixed $value): array
    {
        return array_values(array_filter(array_map(fn ($e) => is_array($e)
            ? ['name' => trim((string) ($e['name'] ?? '')), 'phone' => $e['phone'] ?? null, 'user_id' => $e['user_id'] ?? null]
            : ['name' => trim((string) $e), 'phone' => null, 'user_id' => null], (array) $value), fn ($e) => $e['name'] !== ''));
    }

    /**
     * POST /api/v1/project-board/repair-teams: add, change (rename, phone,
     * user) or delete one, on every Workiz account too. Returns each
     * account's outcome and the new list.
     */
    public function repairTeams(Request $request): JsonResponse
    {
        $user = $request->user();
        abort_unless($user->hasPermission('organizations.edit'), Response::HTTP_FORBIDDEN, 'Changing the list needs settings access.');
        $in = $request->validate([
            'op' => ['required', 'in:create,update,delete'],
            'name' => ['required', 'string', 'max:60'],
            'to' => ['nullable', 'string', 'max:60'],
            'color' => ['nullable', 'in:'.implode(',', WorkizTags::COLORS)],
            'phone' => ['nullable', 'string', 'max:32'],
            'user_id' => ['nullable', 'uuid', 'exists:users,id'],
        ]);
        $place = $this->settingsFor($user);
        $settings = app(SettingsResolver::class);
        $list = collect(self::repairEntries($settings->get('project_board.repair_tags', $place)));
        // A first change starts from the tags already working as repair teams, so none drop off the column.
        if ($list->isEmpty()) {
            $list = Job::query()->whereIn('franchise_id', ViewScope::forUser($user)->franchiseIds())->whereNotNull('tags')->pluck('tags')
                ->flatten()->map(fn ($t) => trim((string) $t))->filter(fn ($t) => self::isRepairTeam($t))->unique(fn ($t) => self::tagKey($t))
                ->map(fn ($t) => ['name' => $t, 'phone' => null, 'user_id' => null])->values();
        }
        $key = self::tagKey($in['name']);
        $to = trim((string) ($in['to'] ?? '')) ?: trim($in['name']);
        $outcomes = match ($in['op']) {
            'create' => WorkizTags::create($in['name'], $in['color'] ?? WorkizTags::COLORS[0]),
            'update' => self::tagKey($to) !== $key ? WorkizTags::rename($in['name'], $to) : [],
            'delete' => WorkizTags::delete($in['name']),
        };
        $list = $list->reject(fn (array $e) => self::tagKey($e['name']) === $key)->values();
        if ($in['op'] !== 'delete') {
            $list->push(['name' => $to, 'phone' => $in['phone'] ?? null, 'user_id' => $in['user_id'] ?? null]);
        }
        $settings->set('project_board.repair_tags', $place, $list->sortBy('name', SORT_NATURAL | SORT_FLAG_CASE)->values()->all());
        app(AuditLogger::class)->log('project_board.repair_team_'.$in['op'], null, ['name' => $in['name'], 'to' => $to], actor: $user);

        return response()->json(['data' => ['outcomes' => (object) $outcomes,
            'list' => self::repairEntries($settings->get('project_board.repair_tags', $place))]]);
    }

    /** @return list<string> the company and every company under it */
    private function descendants(Collection $companies, string $id): array
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
    private function places(Collection $companies, Collection $franchises, ?string $parent = null, int $depth = 0): array
    {
        $out = [];
        // At the top: every company whose parent is outside the view, so a
        // sub-company's view starts at that sub-company.
        $level = $parent === null
            ? $companies->filter(fn ($c) => $c->parent_company_id === null || ! $companies->contains('id', $c->parent_company_id))
            : $companies->where('parent_company_id', $parent);
        foreach ($level->sortBy(fn ($c) => mb_strtolower($c->name)) as $company) {
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
            return ['paid' => [], 'files' => [], 'notes' => [], 'parts' => []];
        }

        return [
            'paid' => WorkizRecord::query()->whereIn('job_id', $ids)->where('type', 'payment')
                ->whereNotNull('starts_at')->groupBy('job_id')->selectRaw('job_id, MIN(starts_at) AS at')->pluck('at', 'job_id')->all(),
            'files' => DB::table('attachments')->where('attachable_type', 'jobs')->whereIn('attachable_id', $ids)->groupBy('attachable_id')->selectRaw('attachable_id, COUNT(*) AS n')->pluck('n', 'attachable_id')->all(),
            'parts' => JobPart::query()->whereIn('job_id', $ids)->orderBy('position')->get()->groupBy('job_id')->all(),
            'notes' => JobNote::query()->whereIn('job_id', $ids)->latest()->get(['job_id', 'body'])
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
        $paid = $paidAt === null ? null : Carbon::parse($paidAt);
        $since = $paid ?? $job->created_at;
        // The one set here first (Jose, 2026-11-12), else Workiz's team.
        $technician = $job->performedBy?->name ?: collect($payload['Team'] ?? [])->pluck('Name')->filter()->implode(', ');
        $workizNote = trim((string) ($payload['JobNotes'] ?? ''));
        $note = $extras['notes'][$job->id] ?? null;
        $files = (int) ($extras['files'][$job->id] ?? 0) + count(WorkizExtras::files(is_array($payload) ? $payload : []));
        // EzBizi's own notes only (Jose, 2026-11-10: "Notes should be clean,
        // not tied to Workiz description"); the panel still shows Workiz's.
        $notes = (int) ($note['count'] ?? 0);
        $row = [
            'service_area' => $job->serviceArea?->name,
            'technician' => $technician ?: null,
            'performed_by_user_id' => $job->performed_by_user_id,
            'repair_team' => collect($job->tags ?? [])->first(fn ($t) => $this->isRepair((string) $t)),
            'repair_options' => $this->repairOptions($job),
            'payment_date' => $paid?->format('Y-m-d H:i'),
            // Days in this state, from the deposit (else the job's creation).
            'status_days' => $since === null ? null : (int) floor($since->diffInDays(now(), true)),
            'attachments' => $files,
            'notes' => $note['latest'] ?? null,
            'schedule_proposal' => $job->schedule_proposal,
            'parts' => collect($extras['parts'][$job->id] ?? [])->map(fn (JobPart $p) => $p->present())->values()->all(),
            'notes_count' => $notes,
        ];
        // Only what the company ticks in Settings (project_board.ready_requires).
        $need = fn (string $key) => in_array($key, $this->readyRequires, true);
        $row['ready_missing'] = array_values(array_filter([
            $need('service_area') && $row['service_area'] === null ? 'Service Area' : null,
            $need('technician') && $row['technician'] === null ? 'Technician' : null,
            $need('repair_team') && $row['repair_team'] === null ? 'Repair Team / Contractor' : null,
            $need('attachment') && $files === 0 ? 'an attachment' : null,
            $need('note') && $notes === 0 ? 'a note' : null,
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

    /**
     * The repair-team and contractor tags the job's own Workiz account has,
     * each in its colour: never another account's (Jose, 2026-11-10).
     *
     * @return list<array{name: string, colour: string}>
     */
    private function repairOptions(Job $job): array
    {
        return array_values(array_filter(
            app(WorkizTagColours::class)->definitions($job->franchise_id),
            fn (array $t) => $this->isRepair($t['name']),
        ));
    }

    /**
     * PATCH /api/v1/project-board/jobs/{job}/repair-team: the job's one
     * repair-team / contractor tag, here and in Workiz. A pick replaces the
     * old one; null unassigns (Jose, 2026-11-10: "there is no way to
     * Unassign"). Workiz takes the whole list, removals through the web
     * login (031349404).
     */
    public function repairTeam(Request $request, Job $job): JsonResponse
    {
        $this->editable($request, $job);
        $this->settingsFor($request->user());
        $input = $request->validate(['repair_team' => ['present', 'nullable', 'string', 'max:120']]);
        $tag = trim((string) ($input['repair_team'] ?? ''));
        abort_if($tag !== '' && ! $this->isRepair($tag), 422, 'That is not a repair team or contractor tag.');
        // One per job (Jose, 2026-11-10: "it should only be one type of repair
        // team"): a pick takes every other repair tag off, here and in Workiz.
        $before = array_values((array) ($job->tags ?? []));
        $after = [
            ...array_values(array_filter($before, fn ($t) => ! $this->isRepair((string) $t))),
            ...($tag !== '' ? [$tag] : []),
        ];
        if ($after !== $before) {
            $job->tags = $after;
            $job->save();
            WorkizMirror::queue($job, 'tags', ['tags' => $job->tags]);
            app(AuditLogger::class)->log($tag === '' ? 'job.repair_team_unassigned' : 'job.repair_team_tagged', $job, actor: $request->user());
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
        $this->settingsFor($request->user());
        $proposal = $this->proposal($request);
        $job->loadMissing(['workizJob:id,job_id,serial_id,payload', 'serviceArea:id,name', 'performedBy:id,name']);
        $missing = $this->unscheduled($job, $this->extras([$job->id]))['ready_missing'];
        abort_if($missing !== [], 422, 'Still needs: '.implode(', ', $missing).'.');
        $job->schedule_proposal = $proposal;
        $tags = array_values((array) ($job->tags ?? []));
        $job->save();
        if (! in_array(self::tagKey(self::READY_TAG), array_map(fn ($t) => self::tagKey((string) $t), $tags), true)) {
            $job->tags = [...$tags, self::READY_TAG];
            $job->save();
            WorkizMirror::queue($job, 'tags', ['tags' => $job->tags]);
            app(AuditLogger::class)->log('job.ready_to_schedule', $job, actor: $request->user());
        }
        // The client gets the times by text and answers YES or NO (Jose, 2026-11-10).
        $text = app(ScheduleTexts::class)->offer($job, $request->user());

        return response()->json(['data' => ['id' => $job->id, 'tags' => $job->tags, 'text_refused' => $text]]);
    }

    /**
     * The four questions "Ready to schedule" asks (Jose, 2026-11-10): a date
     * and time slot, and an alternative of each.
     *
     * @return array{date: string, slot: string, alt_date: string, alt_slot: string}
     */
    private function proposal(Request $request): array
    {
        return $request->validate([
            'date' => ['required', 'date_format:Y-m-d'],
            'slot' => ['required', 'string', 'max:40'],
            'alt_date' => ['required', 'date_format:Y-m-d'],
            'alt_slot' => ['required', 'string', 'max:40'],
        ]);
    }

    /**
     * PUT /api/v1/project-board/jobs/{job}/parts: the job's whole parts list
     * (Jose, 2026-11-10): "Need to Order" item, quantity, price, delivery
     * date; "In Stock" item, quantity, price.
     */
    public function parts(Request $request, Job $job): JsonResponse
    {
        $this->editable($request, $job);
        $input = $request->validate([
            'parts' => ['present', 'array', 'max:50'],
            'parts.*.kind' => ['required', 'in:order,stock'],
            'parts.*.item' => ['required', 'string', 'max:160'],
            'parts.*.quantity' => ['required', 'integer', 'min:1', 'max:100000'],
            'parts.*.price_minor' => ['nullable', 'integer', 'min:0', 'max:100000000000'],
            'parts.*.delivery_on' => ['nullable', 'date_format:Y-m-d'],
            'parts.*.inventory_item_id' => ['nullable', 'uuid', 'exists:inventory_items,id'],
        ]);
        DB::transaction(function () use ($job, $input) {
            JobPart::query()->where('job_id', $job->id)->delete();
            foreach ($input['parts'] as $i => $p) {
                JobPart::query()->create([
                    'organization_id' => $job->organization_id, 'job_id' => $job->id, 'position' => $i,
                    'kind' => $p['kind'], 'item' => trim($p['item']), 'quantity' => $p['quantity'],
                    'price_minor' => $p['price_minor'] ?? null,
                    // A delivery date belongs to a part being ordered.
                    'delivery_on' => $p['kind'] === 'order' ? ($p['delivery_on'] ?? null) : null,
                    'inventory_item_id' => $p['kind'] === 'stock' ? ($p['inventory_item_id'] ?? null) : null,
                ]);
            }
        });
        app(AuditLogger::class)->log('job.parts_set', $job, actor: $request->user());

        return response()->json(['data' => JobPart::query()->where('job_id', $job->id)->orderBy('position')->get()
            ->map(fn (JobPart $p) => $p->present())->values()]);
    }

    /** PATCH /api/v1/project-board/jobs/{job}/proposal: view or change the offered times. */
    public function updateProposal(Request $request, Job $job): JsonResponse
    {
        $this->editable($request, $job);
        $job->forceFill(['schedule_proposal' => $this->proposal($request)])->save();
        app(AuditLogger::class)->log('job.schedule_proposed', $job, actor: $request->user());

        return response()->json(['data' => ['id' => $job->id, 'schedule_proposal' => $job->schedule_proposal]]);
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
            'id' => $job->id,
            'number' => $job->number,
            'workiz_id' => $job->workizJob?->serial_id === null ? null : (string) $job->workizJob->serial_id,
            'customer' => $job->customer?->name,
            'franchise' => $job->franchise?->name,
            'status' => $job->franchise ? Job::labelFor((string) $job->status, $job->franchise) : $job->status,
            'job_type' => $job->job_type,
            // The repair tag has its own column (Jose, 2026-11-10), so not here.
            'tags' => array_values(array_filter((array) ($job->tags ?? []), fn ($t) => ! $this->isRepair((string) $t))),
            // Each tag's Workiz colour, where the mirror knows it (Jose, 2026-11-10).
            'tag_colours' => (object) app(WorkizTagColours::class)->for($job->franchise_id, (array) ($job->tags ?? [])),
            // Part status: the job's tags that the organization names as part
            // tags (project_board.part_tags), e.g. WAITING PARTS.
            'part_status' => collect($job->tags ?? [])->filter(fn ($t) => in_array(self::tagKey((string) $t), $partTags, true))->values()->implode(', ') ?: null,
            'created' => $job->created_at?->copy()->setTimezone($zone)->toDateString(),
            'scheduled' => $job->scheduled_for?->toDateString(),
            // Required: what the job needs done, its line items (Workiz's until
            // EzBizi keeps its own), e.g. "Crown Repair, Damper Cap x2".
            'required' => collect($payload['LineItems'] ?? [])
                ->map(fn ($i) => trim((string) ($i['Name'] ?? '')).((int) ($i['Quantity'] ?? 1) > 1 ? ' x'.(int) $i['Quantity'] : ''))
                ->filter(fn ($s) => $s !== '')->values()->implode("\n") ?: null,
            // Financials only, and absent without it.
            ...($money !== null ? [
                'paid' => $m['paid'],
                'pending' => $m['due'],
                'total' => $m['total'] ?? 0,
                'currency' => $job->currency,
            ] : []),
        ];
    }
}
