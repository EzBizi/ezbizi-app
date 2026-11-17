<?php

namespace App\Http\Controllers\Api;

use App\Models\Attachment;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Franchise;
use App\Models\InspectionReport;
use App\Models\Job;
use App\Models\JobNote;
use App\Models\JobPart;
use App\Models\User;
use App\Models\WorkizRecord;
use App\Support\Audit\AuditLogger;
use App\Support\Jobs\JobMoney;
use App\Support\Jobs\ScheduleTexts;
use App\Support\Jobs\WorkizExtras;
use App\Support\ProjectBoard\WorkOrderPdf;
use App\Support\Scoping\ViewScope;
use App\Support\Tenancy\TenantContext;
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
use Illuminate\Support\Facades\URL;
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

        return $this->settingsAt($place);
    }

    /** The same, read at [place] (a company or the organization). */
    private function settingsAt(Model $place): Model
    {
        $settings = app(SettingsResolver::class);
        $this->repairTags = array_values(array_filter(array_map(fn (array $e) => self::tagKey($e['name']),
            self::repairEntries($settings->get('project_board.repair_tags', $place)))));
        $this->readyRequires = array_values((array) $settings->get('project_board.ready_requires', $place));

        return $place;
    }

    /**
     * PATCH /api/v1/project-board/parts/{part}/reminder: set, move or clear
     * a part's reminder from the Dashboard (Jose, 2026-11-17: "reschedule
     * reminders").
     */
    public function partReminder(Request $request, JobPart $part): JsonResponse
    {
        $job = Job::query()->findOrFail($part->job_id);
        $this->editable($request, $job);
        $in = $request->validate([
            'remind_at' => ['present', 'nullable', 'date'],
            'remind_note' => ['nullable', 'string', 'max:160'],
        ]);
        $part->remind_at = filled($in['remind_at']) ? Carbon::parse($in['remind_at']) : null;
        if (array_key_exists('remind_note', $in)) {
            $part->remind_note = filled($in['remind_note']) ? trim($in['remind_note']) : null;
        }
        $part->reminded_at = null;
        $part->save();
        app(AuditLogger::class)->log('job.part_reminder_set', $job, ['part_id' => $part->id], actor: $request->user());

        return response()->json(['data' => $part->present()]);
    }

    /**
     * GET /api/v1/project-board/dashboard (Jose, 2026-11-17): how many items
     * and jobs are waiting for parts, the late parts, and the reminders, over
     * the jobs in the viewer's reach.
     */
    public function dashboard(Request $request): JsonResponse
    {
        $user = $request->user();
        abort_unless($user->hasPermission('project_board.view') && $user->hasPermission('jobs.view'),
            Response::HTTP_FORBIDDEN, 'Project Management is not in your role.');
        $franchises = ViewScope::forUser($user)->franchiseIds();
        $parts = fn () => JobPart::query()->whereHas('job', fn (Builder $q) => $q->whereIn('franchise_id', $franchises));
        $waiting = fn () => $parts()->where('kind', '!=', 'stock')->whereNull('received_at');
        $row = fn (JobPart $p) => $p->present() + [
            'job_id' => $p->job_id,
            'job_number' => $p->job?->number,
            'customer' => $p->job?->customer?->name,
        ];
        $with = ['job:id,number,customer_id,franchise_id', 'job.customer:id,name'];

        return response()->json(['data' => [
            'waiting_items' => $waiting()->count(),
            'waiting_jobs' => $waiting()->distinct()->count('job_id'),
            'late' => $waiting()->where('ordered_at', '<=', now()->subDays(JobPart::LATE_AFTER_DAYS))
                ->with($with)->orderBy('ordered_at')->limit(100)->get()->map($row)->values(),
            'reminders' => $parts()->whereNotNull('remind_at')->with($with)->orderBy('remind_at')->limit(100)->get()
                ->map(fn (JobPart $p) => $row($p) + ['due' => $p->remind_at->lte(now())])->values(),
        ]]);
    }

    /**
     * GET /api/v1/project-board/jobs/{job}/work-order: the job's work order
     * as a PDF for the crew (Jose, 2026-11-17). Amounts only for a reader
     * with Financials.
     */
    public function workOrder(Request $request, Job $job): Response
    {
        $user = $request->user();
        abort_unless($user->hasPermission('project_board.view') && $user->hasPermission('jobs.view'),
            Response::HTTP_FORBIDDEN, 'Project Management is not in your role.');
        abort_unless(in_array($job->franchise_id, ViewScope::forUser($user)->franchiseIds(), true), Response::HTTP_NOT_FOUND);
        $this->settingsFor($user);

        return $this->workOrderResponse($job, $user->hasPermission('jobs.financials'));
    }

    /**
     * GET /work-order/{job}?signature=…: the same work order by a signed link
     * that lapses after 7 days, for a repair crew or contractor with no login
     * (Jose, 2026-11-17: "send ... work order, repair crew or contractor").
     * Opens this job's work order and nothing else; with the balance, since
     * the crew collects it.
     */
    public function sharedWorkOrder(string $job): Response
    {
        $row = Job::query()->withoutGlobalScopes()->findOrFail($job);

        return app(TenantContext::class)->run($row->organization, function () use ($row) {
            $row = Job::query()->findOrFail($row->id);
            $this->settingsAt($row->franchise?->company ?? $row->organization);

            return $this->workOrderResponse($row, true);
        });
    }

    /** A link to [job]'s work order that works without signing in, for 7 days. */
    public static function workOrderLink(Job $job): string
    {
        return URL::temporarySignedRoute('work-order.shared', now()->addDays(7), ['job' => $job->id]);
    }

    private function workOrderResponse(Job $job, bool $withMoney): Response
    {
        // Whole rows: the work order needs the client's address and phone.
        $job->load(['customer', 'franchise.company', 'workizJob', 'serviceArea', 'performedBy']);
        $row = $this->unscheduled($job, $this->extras([$job->id]));
        $payload = is_array($job->workizJob?->payload) ? $job->workizJob->payload : [];
        $customer = $job->customer;
        $address = filled($payload['Address'] ?? null)
            ? trim(implode(', ', array_filter([$payload['Address'], $payload['City'] ?? null,
                trim(($payload['State'] ?? '').' '.($payload['PostalCode'] ?? ''))])))
            : $job->customer_address;
        $money = $withMoney ? (JobMoney::of([$job->id])[$job->id] ?? null) : null;
        $usd = fn (?int $cents) => '$'.number_format(($cents ?? 0) / 100, 2);
        // Up to nine photos on the page; the rest are listed by name.
        $files = Attachment::query()->where('attachable_type', $job->getMorphClass())->where('attachable_id', $job->id)
            ->orderBy('created_at')->get();
        $photos = [];
        $listed = [];
        foreach ($files as $file) {
            $isPhoto = str_starts_with((string) $file->mime, 'image/') && in_array($file->mime, ['image/jpeg', 'image/png', 'image/gif'], true);
            $bytes = $isPhoto && count($photos) < 9 && ($file->size_bytes ?? 0) <= 4_000_000 ? $file->bytes() : null;
            if ($bytes !== null) {
                $photos[] = ['name' => (string) $file->original_name, 'data' => 'data:'.$file->mime.';base64,'.base64_encode($bytes)];
            } else {
                $listed[] = (string) $file->original_name;
            }
        }
        // Workiz's own photos (most of a job's pictures): fetched from
        // Workiz's file host only, images only, so the crew sees them.
        foreach (WorkizExtras::files($payload) as $f) {
            $data = count($photos) < 9 ? WorkOrderPdf::fetchPhoto((string) $f['url']) : null;
            if ($data !== null) {
                $photos[] = ['name' => (string) $f['name'], 'data' => $data];
            } else {
                $listed[] = (string) $f['name'];
            }
        }

        $pdf = WorkOrderPdf::render([
            'company' => $job->franchise?->company?->name ?? $job->franchise?->name,
            'number' => $job->number,
            'workiz' => $job->workizJob?->serial_id === null ? null : (string) $job->workizJob->serial_id,
            'client' => $customer?->name,
            'address' => $address,
            'phone' => filled($payload['Phone'] ?? null) ? (string) $payload['Phone'] : $customer?->phone,
            'phone_ext' => filled($payload['PhoneExt'] ?? null) ? (string) $payload['PhoneExt'] : null,
            'technician' => $row['technician'],
            'repair_team' => $row['repair_team'],
            'parts' => $row['parts'],
            'photos' => $photos,
            'files' => $listed,
            'inspections' => InspectionReport::query()->where('job_id', $job->id)->whereNull('archived_at')->orderBy('submitted_at')
                ->get(['definition_name', 'submitted_at'])
                ->map(fn (InspectionReport $r) => trim($r->definition_name.' '.($r->submitted_at?->format('m/d/Y') ?? '')))->all(),
            'payment_date' => $row['payment_date'] === null ? null : Carbon::parse($row['payment_date'])->format('m/d/Y g:i A'),
            'money' => $money === null ? null : [
                'total' => $usd($money['total']), 'paid' => $usd($money['paid']), 'remaining' => $usd($money['due']),
            ],
        ]);

        return response($pdf, Response::HTTP_OK, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="Work order '.preg_replace('/[^A-Za-z0-9 #-]/', '', (string) $job->number).'.pdf"',
        ]);
    }

    /**
     * PUT /api/v1/project-board/column-order: the board's columns in the
     * order given, for everyone on it (Jose, 2026-11-17: "Just let me drag
     * them"). Names, visibility and labels stay as they are; a key not
     * given keeps its place after the rest.
     */
    public function columnOrder(Request $request): JsonResponse
    {
        $user = $request->user();
        abort_unless($user->hasPermission('project_board.view') && $user->hasPermission('jobs.edit'),
            Response::HTTP_FORBIDDEN, 'Changing the board is not in your role.');
        $keys = $request->validate([
            'order' => ['required', 'array', 'max:60'],
            'order.*' => ['required', 'string', 'max:40'],
        ])['order'];
        $place = $this->settingsFor($user);
        $settings = app(SettingsResolver::class);
        $columns = collect($this->columns((array) $settings->get('project_board.columns', $place)));
        $rank = array_flip(array_values(array_unique($keys)));
        $sorted = $columns->sortBy(fn (array $c, int $i) => [$rank[$c['key']] ?? PHP_INT_MAX, $i])->values()->all();
        $settings->set('project_board.columns', $place, $sorted);
        app(AuditLogger::class)->log('project_board.column_order', null, ['order' => array_column($sorted, 'key')], actor: $user);

        return response()->json(['data' => $sorted]);
    }

    /**
     * The company's suppliers as stored (Settings › Project Management):
     * well-formed entries only, so a hand-edited value cannot break a part.
     *
     * @return list<array{id: string, name: string, address: ?string, phone: ?string, kind: string}>
     */
    private static function suppliers(mixed $raw): array
    {
        return collect(is_array($raw) ? $raw : [])
            ->filter(fn ($s) => is_array($s) && filled($s['id'] ?? null) && filled($s['name'] ?? null))
            ->map(fn (array $s) => [
                'id' => (string) $s['id'],
                'name' => trim((string) $s['name']),
                'address' => filled($s['address'] ?? null) ? trim((string) $s['address']) : null,
                'phone' => filled($s['phone'] ?? null) ? trim((string) $s['phone']) : null,
                'kind' => ($s['kind'] ?? null) === 'pickup' ? 'pickup' : 'order',
            ])->values()->all();
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
                // For the parts selector (Jose, 2026-11-17).
                'suppliers' => self::suppliers($settings->get('project_board.suppliers', $place)),
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
        // A first change starts from the teams already there, so none drop off the column.
        $list = $this->repairList($user, $place);
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

    /**
     * GET /api/v1/project-board/repair-teams: the list the Settings card
     * shows (Jose, 2026-11-16: "it should already show the existing repair
     * team - contractors"), in each tag's Workiz colour. Reads only: nothing
     * is saved and no Workiz account is called.
     */
    public function repairTeamList(Request $request): JsonResponse
    {
        $user = $request->user();
        abort_unless($user->hasPermission('project_board.view') && $user->hasPermission('jobs.view'),
            Response::HTTP_FORBIDDEN, 'Project Management is not in your role.');
        $list = $this->repairList($user, $this->settingsFor($user));
        $colours = app(WorkizTagColours::class);

        return response()->json(['data' => ['list' => $list->all(),
            'tag_colours' => (object) collect(ViewScope::forUser($user)->switchedOnFranchiseIds())
                ->reduce(fn (array $c, string $f) => $c + $colours->for($f, $list->pluck('name')->all()), [])]]);
    }

    /**
     * The company's saved list; before its first change, the repair-team and
     * contractor tags already there: on the Workiz accounts' tag lists and on
     * jobs, inside the view.
     *
     * @return Collection<int, array{name: string, phone: ?string, user_id: ?string}>
     */
    private function repairList(User $user, Model $place): Collection
    {
        $saved = collect(self::repairEntries(app(SettingsResolver::class)->get('project_board.repair_tags', $place)));
        if ($saved->isNotEmpty()) {
            return $saved;
        }
        $view = ViewScope::forUser($user);
        $colours = app(WorkizTagColours::class);

        return collect($view->switchedOnFranchiseIds())->flatMap(fn (string $f) => array_column($colours->definitions($f), 'name'))
            ->merge(Job::query()->whereIn('franchise_id', $view->franchiseIds())->whereNotNull('tags')->pluck('tags')->flatten())
            ->map(fn ($t) => trim((string) $t))->filter(fn ($t) => self::isRepairTeam($t))->unique(fn ($t) => self::tagKey($t))
            ->sort(SORT_NATURAL | SORT_FLAG_CASE)->map(fn ($t) => ['name' => $t, 'phone' => null, 'user_id' => null])->values();
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
            'parts.*.id' => ['nullable', 'string', 'max:64'],
            'parts.*.kind' => ['required', 'in:order,stock,pickup'],
            'parts.*.supplier_id' => ['nullable', 'string', 'max:64'],
            'parts.*.destination' => ['nullable', 'in:warehouse,customer,supplier'],
            'parts.*.received' => ['nullable', 'boolean'],
            'parts.*.remind_at' => ['nullable', 'date'],
            'parts.*.remind_note' => ['nullable', 'string', 'max:160'],
            'parts.*.item' => ['required', 'string', 'max:160'],
            'parts.*.quantity' => ['required', 'integer', 'min:1', 'max:100000'],
            'parts.*.price_minor' => ['nullable', 'integer', 'min:0', 'max:100000000000'],
            'parts.*.delivery_on' => ['nullable', 'date_format:Y-m-d'],
            'parts.*.inventory_item_id' => ['nullable', 'uuid', 'exists:inventory_items,id'],
        ]);
        // The company's suppliers (Settings), copied onto the part as picked.
        $suppliers = collect(self::suppliers(app(SettingsResolver::class)
            ->get('project_board.suppliers', $this->settingsFor($request->user()))))->keyBy('id');
        DB::transaction(function () use ($job, $input, $suppliers) {
            // Kept by id: when a part was ordered and received survives the rewrite.
            $before = JobPart::query()->where('job_id', $job->id)->get()->keyBy('id');
            JobPart::query()->where('job_id', $job->id)->delete();
            foreach ($input['parts'] as $i => $p) {
                $old = $before->get($p['id'] ?? '');
                $away = $p['kind'] !== 'stock';
                $supplierId = $p['supplier_id'] ?? null;
                // A supplier picked from the list now, else the one it had
                // (renamed or removed from the list since).
                $supplier = $away ? ($suppliers->get((string) $supplierId)
                    ?? (($old?->supplier['id'] ?? null) === $supplierId ? $old?->supplier : null)) : null;
                JobPart::query()->create([
                    'organization_id' => $job->organization_id, 'job_id' => $job->id, 'position' => $i,
                    'kind' => $p['kind'], 'item' => trim($p['item']), 'quantity' => $p['quantity'],
                    'price_minor' => $p['price_minor'] ?? null,
                    // A delivery date belongs to a part being ordered.
                    'delivery_on' => $p['kind'] === 'order' ? ($p['delivery_on'] ?? null) : null,
                    'inventory_item_id' => $p['kind'] === 'stock' ? ($p['inventory_item_id'] ?? null) : null,
                    'supplier' => $supplier,
                    'destination' => $away ? ($p['destination'] ?? null) : null,
                    // The three-day clock starts when it stops being in stock.
                    'ordered_at' => $ordered = $away ? ($old !== null && $old->kind !== 'stock' && $old->ordered_at ? $old->ordered_at : now()) : null,
                    'received_at' => $away && ($p['received'] ?? false) ? ($old?->received_at ?? now()) : null,
                    // Told once that it was late, for this order.
                    'late_notified_at' => $ordered !== null && $old?->ordered_at?->equalTo($ordered) ? $old->late_notified_at : null,
                    // A manual reminder (Jose, 2026-11-17); a new time is sent anew.
                    'remind_at' => $remind = filled($p['remind_at'] ?? null) ? Carbon::parse($p['remind_at']) : null,
                    'remind_note' => filled($p['remind_note'] ?? null) ? trim($p['remind_note']) : null,
                    'reminded_at' => $remind !== null && $old?->remind_at?->equalTo($remind) ? $old->reminded_at : null,
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
