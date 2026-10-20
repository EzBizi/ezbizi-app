<?php

namespace App\Http\Controllers\Api;

use App\Models\Customer;
use App\Models\Franchise;
use App\Models\InspectionReport;
use App\Models\InventoryIssueLine;
use App\Models\Job;
use App\Models\JobLine;
use App\Models\ServiceArea;
use App\Models\User;
use App\Support\Audit\AuditLogger;
use App\Support\Automations\EventEmitter;
use App\Support\Jobs\EarnedStatuses;
use App\Support\Jobs\WorkizExtras;
use App\Support\Jobs\WorkizMoney;
use App\Support\Numbering\RecordNumberGenerator;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Jobs (plan §5.2) — the second half of the vertical slice.
 *
 * The opposite shape to customers. Jobs are never shared, so the list IS scoped
 * to the caller's reach — and reach is a subtree, not an exact match, so a
 * company-level user sees every franchise beneath them including their Sub
 * Companies' (§4). That is the roll-up the plan asks the slice to prove.
 */
class JobsController extends Controller
{
    public function __construct(
        private readonly RecordNumberGenerator $numbers,
        private readonly AuditLogger $audit,
        private readonly TenantContext $tenant,
        private readonly EventEmitter $events,
    ) {}

    /**
     * An `exists` rule scoped to this organization.
     *
     * Laravel's plain `exists` runs a raw query with no tenant scope, so naming
     * another organization's id passes validation and only fails later as a 404.
     * Two different answers for two different ids is an existence oracle — it
     * tells a caller which ids are real somewhere else on the platform.
     */
    private function existsInTenant(string $table): \Illuminate\Validation\Rules\Exists
    {
        return Rule::exists($table, 'id')
            ->where('organization_id', $this->tenant->idOrFail());
    }

    /** Jose, 2026-09-29: money in Jobs is a financials toggle on the role. */
    private const MONEY_HIDDEN = 'Money on jobs is hidden for your role.';

    // Workiz's money in its mirror row: dollars as JSON numbers, and the due
    // date as a naive 'Y-m-d 00:00:00' wall date. See WorkizMoney.
    private const DUE    = "CAST(JSON_UNQUOTE(JSON_EXTRACT(workiz_jobs.payload, '$.JobAmountDue')) AS DECIMAL(12,2))";
    private const TOTAL  = "CAST(JSON_UNQUOTE(JSON_EXTRACT(workiz_jobs.payload, '$.JobTotalPrice')) AS DECIMAL(12,2))";
    private const DUE_ON = "LEFT(JSON_UNQUOTE(JSON_EXTRACT(workiz_jobs.payload, '$.PaymentDueDate')), 10)";

    /** WorkizMoney::state(), in SQL, plus `overdue`, which takes the reader's today. */
    private const MONEY_STATES = [
        'credit'  => self::DUE.' < 0',
        'paid'    => self::TOTAL.' > 0 AND '.self::DUE.' = 0',
        'partial' => self::DUE.' > 0 AND '.self::DUE.' < '.self::TOTAL,
        'unpaid'  => self::DUE.' > 0 AND '.self::DUE.' >= '.self::TOTAL,
        'overdue' => self::DUE.' > 0 AND '.self::DUE_ON.' < ?',
    ];

    /**
     * Whether this reader holds the Financials toggle. Asked once per request
     * and passed down, never kept on the controller: a route keeps its
     * controller instance across requests in tests.
     */
    private function seesMoney(Request $request): bool
    {
        return $request->user()->hasPermission('jobs.financials');
    }

    /** GET /api/v1/jobs */
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Job::class);

        $money = $this->seesMoney($request);

        $request->validate([
            'when'  => ['nullable', Rule::in(['upcoming', 'past', 'all'])],
            // REQUIRED with a window, never defaulted. The server's own date is
            // UTC, and from about 8pm Eastern that is already tomorrow — a job
            // at 10pm tonight would file as Past while it is still ahead.
            'today' => ['nullable', 'required_if:when,upcoming,past', 'required_if:money,overdue', 'date_format:Y-m-d'],
            'money' => ['nullable', Rule::in(array_keys(self::MONEY_STATES))],
            // One word, or `tag[]=` / `job_type[]=` lists: any of them (queue 118).
            'tag' => ['nullable', 'max:30'],
            'tag.*' => ['string', 'max:100'],
            'job_type' => ['nullable', 'max:30'],
            'job_type.*' => ['string', 'max:100'],
        ]);

        abort_if($request->filled('money') && ! $money, Response::HTTP_FORBIDDEN, self::MONEY_HIDDEN);

        // The current view, already narrowed to what §4 allows.
        $reachable = collect(\App\Support\Scoping\ViewScope::forUser($request->user())->franchiseIds());

        $when = $request->query('when', 'all');

        /*
         * Where "upcoming" starts: midnight, as a NAIVE wall clock.
         *
         * scheduled_for is stored as wall time in the franchise's own zone
         * (FranchiseWallTime), so the boundary has to be wall time too — an
         * instant would move it by each franchise's offset. And it is the
         * READER's date, sent by the client, because "today" belongs to the
         * person looking and the server has no clock of theirs. There is no
         * fallback on purpose: the one available, the server's date, is UTC and
         * wrong every US evening (Jobs' review of 11f50399). Null when the
         * caller sent no date — then there is no window, and no window counts.
         *
         * One reader's date is applied to every franchise's wall clock. Right
         * while an organization sits in one zone (A-1's branches are all
         * Eastern); an organization spanning zones would see a branch's late
         * evening filed a day early or late. The fix then is per-franchise
         * midnights, not a different default here.
         */
        $today = $request->query('today');
        $midnight = $today === null ? null : $today.' 00:00:00';

        /*
         * "Done", for a job with no date: one of its organization's or company's
         * earned statuses, the one setting that says the work is finished. An
         * undated job is work still to arrange UNLESS it is done (Jose's UI
         * review, 2026-10-08: "34 upcoming" held completed jobs while Past read
         * 0). Nothing names "cancelled", so an undated cancelled job stays
         * upcoming. Grouped by list, since companies may differ.
         */
        $earned = $reachable->groupBy(fn ($id) => json_encode(EarnedStatuses::for(Franchise::findOrFail($id))));
        $done = function ($q) use ($earned) {
            $q->whereRaw('1 = 0');
            foreach ($earned as $list => $ids) {
                if (json_decode($list) !== []) {
                    $q->orWhere(fn ($w) => $w->whereIn('franchise_id', $ids->all())->whereIn('status', json_decode($list)));
                }
            }
        };

        /*
         * Every filter, with the status, the window and the Where pair
         * (franchise, service area) each OPTIONAL.
         *
         * The chips count "how many of each status would I see", so their
         * counts leave the status filter out and keep everything else — a count
         * that applied its own filter would show zero on every chip but the
         * lit one. The Upcoming/Past counts do the same for the window, and the
         * Where picker's unplaced count for the Where pair.
         */
        $filtered = function (?string $status, string $window, bool $where = true, bool $withMoney = true) use ($request, $reachable, $midnight, $today, $done) {
            return Job::query()
                // The roll-up, and the boundary. whereIn on an empty list matches
                // nothing, which is the correct answer for a user with no
                // assignments — not "everything".
                ->whereIn('franchise_id', $reachable)
                ->when($where ? $request->query('franchise_id') : null, fn ($q, $id) => $q->where('franchise_id', $id))
                ->when($request->query('customer_id'), fn ($q, $id) => $q->where('customer_id', $id))
                ->when($status, fn ($q, $s) => $q->where('status', $s))
                ->when((array) $request->query('tag', []), fn ($q, $tags) => $q->where(function ($any) use ($tags) {
                    foreach ($tags as $t) {
                        $any->orWhereJsonContains('tags', (string) $t);
                    }
                }))
                ->when((array) $request->query('job_type', []), fn ($q, $types) => $q->whereIn('job_type', $types))
                ->when($request->query('assigned_role_id'), fn ($q, $id) => $q->where('assigned_role_id', $id))
                // `none`: the jobs no area holds, the picker's "Not placed".
                ->when($where ? $request->query('service_area_id') : null, fn ($q, $id) => $id === 'none'
                    ? $q->whereNull('service_area_id')
                    : $q->where('service_area_id', $id))
                // Correlated, not a join: workiz_jobs has its own franchise_id,
                // status and scheduled_for, which the filters here name unqualified.
                ->when($withMoney ? $request->query('money') : null, fn ($q, $state) => $q->whereExists(
                    fn ($w) => $w->selectRaw('1')->from('workiz_jobs')
                        ->whereColumn('workiz_jobs.job_id', 'jobs.id')
                        ->whereRaw(self::MONEY_STATES[$state], $state === 'overdue' ? [$today] : []),
                ))
                ->when(filled($request->query('search')), function ($q) use ($request) {
                    $term = trim((string) $request->query('search'));
                    $like = '%'.$term.'%';

                    $q->where(function ($inner) use ($like) {
                        $inner->where('title', 'like', $like)
                            ->orWhere('number', 'like', $like)
                            ->orWhereHas('customer', function ($customers) use ($like) {
                                $customers->where('name', 'like', $like)
                                    ->orWhere('number', 'like', $like);
                            });
                    });
                })
                // A job with no date yet is work still to arrange, so it is
                // upcoming, unless it is done ($done above): then it is past.
                ->when($window === 'upcoming', fn ($q) => $q->where(
                    fn ($w) => $w->where('scheduled_for', '>=', $midnight)
                        ->orWhere(fn ($u) => $u->whereNull('scheduled_for')->whereNot($done)),
                ))
                ->when($window === 'past', fn ($q) => $q->where(
                    fn ($w) => $w->where('scheduled_for', '<', $midnight)
                        ->orWhere(fn ($u) => $u->whereNull('scheduled_for')->where($done)),
                ));
        };

        $status = $request->query('status');

        $query = $filtered($status, $when)
            // Everything present() reads for a LIST row, loaded once for the
            // page. `timezone` because FranchiseWallTime reads it to give
            // scheduled_for its offset — loaded without it, the list shipped
            // every time with a UTC offset. `assignedRole` because the base
            // payload carries the role column, `serviceArea` the area column.
            ->with([
                'franchise:id,name,timezone,company_id', 'franchise.company:id,name',
                'customer:id,name,number,owning_franchise_id',
                'assignedRole:id,name',
                'serviceArea',
                // The row's money with the toggle; its Workiz job number always.
                // Only the number without the toggle: the payload is the money.
                ...($money ? ['workizJob'] : ['workizJob:id,job_id,serial_id']),
            ]);

        match ($when) {
            // Soonest first: the next job is the one somebody is about to go
            // to. Undated last, because they have no place in a day yet.
            'upcoming' => $query->orderByRaw('scheduled_for IS NULL')
                ->orderBy('scheduled_for')
                ->orderBy('created_at'),
            'past' => $query->orderByDesc('scheduled_for')->orderByDesc('created_at'),
            // COALESCE, not scheduled_for alone. MySQL sorts NULLs last under
            // DESC, and a job created without a date has a null one — so a user
            // who had just created one watched it fail to appear.
            default => $query->orderByRaw('COALESCE(scheduled_for, created_at) DESC')
                ->orderByDesc('created_at'),
        };

        $jobs = $query->paginate(perPage: min((int) $request->query('per_page', 50), 200));

        $meta = $this->pageMeta($jobs);

        // On the first page only. "Load more" changes no count, and each one
        // is a query of its own.
        if ($jobs->currentPage() === 1) {
            // An OBJECT even when empty: an empty PHP array encodes as [], and
            // a client reading a map would then be handed a list.
            $meta['status_counts'] = (object) $filtered(null, $when)
                ->toBase()
                ->select('status', DB::raw('count(*) as n'))
                ->groupBy('status')
                ->pluck('n', 'status')
                ->map(fn ($n) => (int) $n)
                ->all();

            // Only with the reader's date: without it there is no boundary to
            // count either side of.
            if ($midnight !== null) {
                $meta['when_counts'] = [
                    'upcoming' => $filtered($status, 'upcoming')->count(),
                    'past'     => $filtered($status, 'past')->count(),
                ];
            }

            // The Where picker (Jose's layout 1, 2026-09-25): every area in
            // reach, by franchise name then area name, and the jobs no area
            // holds. Areas come from here because Scheduling's list needs
            // scheduling.view.
            $franchiseNames = Franchise::query()->whereIn('id', $reachable)->pluck('name', 'id');
            $meta['service_areas'] = ServiceArea::query()->whereIn('franchise_id', $reachable)
                ->get(['id', 'name', 'franchise_id'])
                ->sortBy(fn (ServiceArea $a) => ($franchiseNames[$a->franchise_id] ?? '')."\0".$a->name)
                ->map(fn (ServiceArea $a) => ['id' => $a->id, 'name' => $a->name, 'franchise_id' => $a->franchise_id])
                ->values()->all();
            $meta['unplaced_count'] = $filtered($status, $when, where: false)->whereNull('service_area_id')->count();

            // The words this organization's jobs carry, for the pickers: its own
            // vocabulary, as Workiz keeps one per account (2026-10-20).
            $meta['labels'] = $this->labelsIn($reachable->all());

            // Workiz's money over the same jobs, per currency and never summed
            // across them, with the money filter left out of its own totals.
            if ($money) {
                $overdue = $today === null ? '0'
                    : 'SUM(CASE WHEN '.self::DUE.' > 0 AND '.self::DUE_ON.' < ? THEN '.self::DUE.' ELSE 0 END)';

                // An OBJECT even when empty: an empty PHP array encodes as [].
                $meta['money_totals'] = (object) DB::table('workiz_jobs')
                    ->joinSub($filtered($status, $when, withMoney: false)->toBase()->select('jobs.id', 'jobs.currency'),
                        'j', 'j.id', '=', 'workiz_jobs.job_id')
                    ->selectRaw('j.currency, ROUND(SUM(GREATEST('.self::TOTAL.', 0)) * 100) AS total, '
                        .'ROUND(SUM(GREATEST('.self::DUE.', 0)) * 100) AS due, ROUND('.$overdue.' * 100) AS overdue',
                        $today === null ? [] : [$today])
                    ->groupBy('j.currency')
                    ->get()
                    ->mapWithKeys(fn ($r) => [$r->currency => [
                        'total' => (int) $r->total,
                        'paid' => max((int) $r->total - (int) $r->due, 0),
                        'due' => (int) $r->due,
                        'overdue' => (int) $r->overdue,
                    ]])
                    ->all();

                // The money chips' counts, faceted the same way. Counts, unlike
                // money, may be added across currencies. `overdue` overlaps
                // `unpaid` and `partial`: it is a date on top of a state.
                $counts = [];
                $bindings = [];
                foreach (self::MONEY_STATES as $state => $sql) {
                    if ($state === 'overdue' && $today === null) {
                        $counts[] = '0 AS overdue';

                        continue;
                    }
                    $counts[] = "SUM(CASE WHEN {$sql} THEN 1 ELSE 0 END) AS {$state}";
                    if ($state === 'overdue') {
                        $bindings[] = $today;
                    }
                }
                $row = DB::table('workiz_jobs')
                    ->joinSub($filtered($status, $when, withMoney: false)->toBase()->select('jobs.id'),
                        'j', 'j.id', '=', 'workiz_jobs.job_id')
                    ->selectRaw(implode(', ', $counts), $bindings)
                    ->first();
                $meta['money_counts'] = array_map(
                    fn (string $state) => (int) ($row->{$state} ?? 0),
                    ['unpaid' => 'unpaid', 'partial' => 'partial', 'overdue' => 'overdue', 'paid' => 'paid', 'credit' => 'credit'],
                );
            }
        }

        /*
         * Each day heading's total, for the days on THIS page — every page, since
         * each brings days of its own. A page that stops halfway through a
         * Thursday must still head it "38 jobs", not the 12 it happened to hold.
         * DATE() of a naive wall clock IS the wall date, the day the heading
         * names (FranchiseWallTime). Keyed `Y-m-d`, and `none` for undated.
         */
        $days = collect($jobs->items())
            ->map(fn (Job $j) => $j->getRawOriginal('scheduled_for'))
            ->map(fn ($raw) => $raw === null ? null : substr((string) $raw, 0, 10))
            ->unique();
        $dated = $days->filter()->values()->all();

        $dayCounts = $dated === [] ? [] : $filtered($status, $when)
            ->toBase()
            ->selectRaw('DATE(scheduled_for) as d, count(*) as n')
            ->whereIn(DB::raw('DATE(scheduled_for)'), $dated)
            ->groupBy('d')
            ->pluck('n', 'd')
            ->map(fn ($n) => (int) $n)
            ->all();

        if ($days->contains(null)) {
            $dayCounts['none'] = $filtered($status, $when)->whereNull('scheduled_for')->count();
        }

        $meta['day_counts'] = (object) $dayCounts;

        return response()->json([
            'data' => collect($jobs->items())->map(fn (Job $j) => $this->present($j, money: $money)),
            'meta' => $meta,
        ]);
    }

    /** GET /api/v1/jobs/{job} */
    public function show(Request $request, Job $job): JsonResponse
    {
        $this->authorize('view', $job);

        return response()->json(['data' => $this->present($job, detailed: true, money: $this->seesMoney($request))]);
    }

    /**
     * POST /api/v1/jobs
     *
     * Any franchise may create a job for a shared customer. Doing so adds them to
     * that customer's "served by" list automatically — derived, so it needs no
     * write here — and does NOT change who owns the customer (§5.2).
     */
    public function store(Request $request): JsonResponse
    {
        $input = $request->validate($this->rules());

        $money = $this->seesMoney($request);
        abort_if(! $money && array_key_exists('amount_minor', $input), Response::HTTP_FORBIDDEN, self::MONEY_HIDDEN);

        $franchise = Franchise::findOrFail($input['franchise_id']);
        $customer  = Customer::findOrFail($input['customer_id']);

        $this->authorize('create', [Job::class, $franchise]);

        $this->assertStatusAllowed($input['status'], $franchise);
        $this->assertPerformerReach($input['performed_by_user_id'] ?? null, $franchise);
        $this->assertAreaOfFranchise($input['service_area_id'] ?? null, $franchise);

        $job = DB::transaction(function () use ($input, $franchise, $customer) {
            $job = Job::make($input + [
                'number' => $this->numbers->next(RecordNumberGenerator::JOBS, $franchise),
                // From the franchise, so the amount and its currency can never
                // disagree with where the work happened.
                'currency' => strtoupper($franchise->currency ?: 'USD'),
            ]);
            // Not fillable: only the server says a person chose the area.
            $job->service_area_chosen_at = filled($input['service_area_id'] ?? null) ? now() : null;
            $job->save();

            $this->audit->log('job.created', $job, metadata: [
                'franchise' => $franchise->name,
                'customer'  => $customer->name,
                'number'    => $job->number,
                // Worth recording explicitly: this is the moment a franchise
                // joins someone else's customer's "served by" list, and the
                // derived list itself keeps no history of when.
                'customer_owned_by_other_franchise' =>
                    $customer->owning_franchise_id !== $franchise->getKey(),
            ]);

            // Same transaction as the change (5.5 transactional outbox): roll
            // back and the event goes with it, commit and it is already durable.
            $this->events->emit('job.created', $job, ['after' => $job->only([
                'status', 'title', 'franchise_id', 'customer_id', 'amount_minor',
            ])]);

            if ($job->scheduled_for !== null) {
                $this->events->emit('job.scheduled', $job, [
                    'after' => ['scheduled_for' => $job->scheduled_for->toIso8601String()],
                ]);
            }

            return $job;
        });

        return response()->json(
            ['data' => $this->present($job->fresh(), detailed: true, money: $money)],
            Response::HTTP_CREATED
        );
    }

    /** PATCH /api/v1/jobs/{job} */
    public function update(Request $request, Job $job): JsonResponse
    {
        $this->authorize('update', $job);

        $input = $request->validate($this->rules(forUpdate: true));

        $money = $this->seesMoney($request);
        abort_if(! $money && array_key_exists('amount_minor', $input), Response::HTTP_FORBIDDEN, self::MONEY_HIDDEN);

        // Validated when it CHANGES, not when it is merely present.
        //
        // The detail panel sends `status` on every save, so the strict version
        // refused a job whose status the organization does not list even when
        // the caller was changing the DATE — a refusal naming a field they had
        // not touched. Measured on production 2026-09-23: 550 of 575 jobs were
        // in that state, carrying the Workiz mirror's own words while A-1's
        // list was still the platform default. Not one of the six matched;
        // `In progress` misses `in_progress` by a space and a capital.
        //
        // An unchanged value is already in the database. Refusing it does not
        // remove it, it only blocks the edit somebody actually asked for — so
        // the strict form validated history rather than the request. It is not
        // a Workiz problem either: renaming a status in Settings strands every
        // job holding the old word in exactly the same way.
        //
        // The NEW value is still checked whenever it differs, so a job cannot
        // be moved onto an unlisted status, nor traded from one unlisted word
        // to another — and a job that arrived as `Done` can still be tidied
        // onto `completed`, which is the way out of the state.
        if (isset($input['status']) && $input['status'] !== $job->status) {
            $this->assertStatusAllowed($input['status'], $job->franchise);
        }

        if (array_key_exists('performed_by_user_id', $input)) {
            $this->assertPerformerReach($input['performed_by_user_id'], $job->franchise);
        }

        // On a CHANGE, for the status's reason above: the panel sends the area
        // with every save, and an area re-homed to another franchise since
        // would otherwise refuse an edit to something else entirely.
        if (array_key_exists('service_area_id', $input) && $input['service_area_id'] !== $job->service_area_id) {
            $this->assertAreaOfFranchise($input['service_area_id'], $job->franchise);
        }

        DB::transaction(function () use ($job, $input) {
            $job->fill($input);

            // The money is earned once, at the first transition into an earned
            // status (§8.1). WHETHER to stamp is decided here — only when the
            // status is actually changing, so correcting the title of an old
            // completed job cannot stamp it into this month. WHAT to stamp is
            // Job::markEarnedIfDue()'s, the one rule the Workiz projection
            // calls too.
            if (array_key_exists('status', $job->getDirty())) {
                $job->markEarnedIfDue();
            }

            // A person decided the area, clearing included, so the Workiz
            // projection leaves it alone from now on. On a CHANGE only: the
            // panel may send the field with every save, as it does the status.
            if ($job->isDirty('service_area_id')) {
                $job->service_area_chosen_at = now();
            }

            $changed = $job->getDirty();
            $before  = array_intersect_key($job->getOriginal(), $changed);

            $this->audit->logChange('job.updated', $job);

            $job->save();

            if ($changed === []) {
                return;
            }

            $this->events->emit('job.updated', $job, ['before' => $before, 'after' => $changed]);

            // Its own trigger, not a field-change condition on job.updated:
            // "when a job is completed, email the customer" is the canonical
            // rule, and burying it means nobody ever writes it.
            if (array_key_exists('status', $changed)) {
                $this->events->emit('job.status_changed', $job, [
                    'before' => ['status' => $before['status'] ?? null],
                    'after'  => ['status' => $changed['status']],
                ]);
            }

            if (array_key_exists('scheduled_for', $changed)) {
                $this->events->emit('job.scheduled', $job, [
                    'before' => ['scheduled_for' => $before['scheduled_for'] ?? null],
                    'after'  => ['scheduled_for' => $changed['scheduled_for']],
                ]);
            }

            if (array_key_exists('assigned_role_id', $changed)) {
                $this->events->emit('job.assigned', $job, [
                    'before' => ['assigned_role_id' => $before['assigned_role_id'] ?? null],
                    'after'  => ['assigned_role_id' => $changed['assigned_role_id']],
                ]);
            }

            // Distinct from status_changed because "when the work is done" is
            // the rule people actually write, and making them hand-pick
            // statuses in every rule duplicates a setting already made once.
            if (array_key_exists('status', $changed)
                && EarnedStatuses::isEarned($job->status, $job->franchise)
                && ! EarnedStatuses::isEarned((string) ($before['status'] ?? ''), $job->franchise)) {
                $this->events->emit('job.completed', $job, [
                    'before' => ['status' => $before['status'] ?? null],
                    'after'  => ['status' => $job->status],
                ]);
            }
        });

        return response()->json(['data' => $this->present($job->fresh(), detailed: true, money: $money)]);
    }

    /** DELETE /api/v1/jobs/{job} */
    public function destroy(Job $job): JsonResponse
    {
        $this->authorize('delete', $job);

        DB::transaction(function () use ($job) {
            $this->audit->log('job.deleted', $job);
            $this->events->emit('job.deleted', $job, ['before' => $job->only(['status', 'title'])]);
            $job->delete();
        });

        return response()->json(['message' => 'Job deleted.']);
    }

    // ── shared ───────────────────────────────────────────────────

    /**
     * The permitted statuses are tenant configuration (§2.1 category 5), so this
     * cannot be an `in:` rule — the list is not known until the organization's
     * settings have been resolved.
     */
    private function assertStatusAllowed(string $status, Franchise $scope): void
    {
        $allowed = Job::allowedStatuses($scope);

        if (! in_array($status, $allowed, strict: true)) {
            throw ValidationException::withMessages([
                'status' => sprintf(
                    'Not one of this organization\'s job statuses (%s). Change the list in Settings.',
                    implode(', ', $allowed)
                ),
            ]);
        }
    }

    /**
     * `performed_by_user_id`'s `exists` rule only proves the id is a real user
     * IN THIS ORGANIZATION — it cannot express reach, which depends on the
     * job's franchise (§4, `User::canActOnFranchise`). Same shape as
     * assertStatusAllowed() above and for the same reason: it needs a resolved
     * Franchise, which isn't available until after `$request->validate()`
     * returns, so it runs as a second pass rather than as a rule. One copy of
     * the reach logic — this defers to `canActOnFranchise` rather than
     * re-deriving it — and the refusal still comes back as a 422 on the field,
     * not a 403, because naming somebody who can't reach the job is a bad
     * INPUT, not an authorization failure of the caller's own.
     */
    private function assertPerformerReach(?string $userId, Franchise $scope): void
    {
        if ($userId === null) {
            return;
        }

        $user = User::find($userId);

        if ($user === null || ! $user->canActOnFranchise($scope)) {
            throw ValidationException::withMessages([
                'performed_by_user_id' => 'That person is not assigned anywhere this job can be seen.',
            ]);
        }
    }

    /**
     * A job's area must be one of its OWN franchise's: an area is a part of a
     * franchise, and a job cannot be in a part of somebody else's -- the rule
     * JobServiceArea applies to Workiz, applied to a person's choice. `exists`
     * could only prove it is an area somewhere in the organization. ServiceArea
     * is tenant-scoped, so another organization's id gets this same answer.
     */
    private function assertAreaOfFranchise(?string $areaId, Franchise $franchise): void
    {
        if ($areaId !== null && ! ServiceArea::query()->whereKey($areaId)
            ->where('franchise_id', $franchise->getKey())->exists()) {
            throw ValidationException::withMessages([
                'service_area_id' => 'That service area is not one of this franchise\'s.',
            ]);
        }
    }

    private function rules(bool $forUpdate = false): array
    {
        $required = $forUpdate ? 'sometimes' : 'required';

        return [
            // Neither may move after creation. A job that changes franchise
            // rewrites two franchises' history at once, and one that changes
            // customer detaches work from the person it was done for — both are
            // "create a new one and cancel this", not an edit.
            // `missing`, not `prohibited`: prohibited is satisfied by a
            // present-but-null value, which then reached fill() and violated a
            // NOT NULL column as a 500 instead of a refusal.
            'franchise_id' => $forUpdate
                ? ['missing']
                : ['required', 'uuid', $this->existsInTenant('franchises')],
            'customer_id'  => $forUpdate
                ? ['missing']
                : ['required', 'uuid', $this->existsInTenant('customers')],

            'title'            => [$required, 'string', 'max:200'],
            'status'           => [$required, 'string', 'max:60'],
            'description'      => ['nullable', 'string', 'max:10000'],
            'scheduled_for'    => ['nullable', 'date'],
            'duration_minutes' => ['nullable', 'integer', 'min:1', 'max:100000'],
            // Minor units, integer (§7). Accepting a decimal here is how floats
            // get into money — the client sends cents or nothing.
            'amount_minor'     => ['nullable', 'integer', 'min:0'],
            // Currency is NOT accepted from the client. The column is NOT NULL
            // and v1 is single-currency (§7), so the only thing a caller could
            // send is either 'USD' or a mistake — and `nullable` against a NOT
            // NULL column turned an explicit null into a 500 rather than a 422.
            // It comes from the franchise below instead.
            'currency'         => ['missing'],

            // Whose trade this is. Roles are organization-global (§4), so this
            // is scoped to the tenant, not the franchise.
            'assigned_role_id' => ['nullable', 'uuid', $this->existsInTenant('roles')],

            // Who did it. Scoped the same way; whether they can REACH this
            // franchise is checked below, because `exists` cannot express it.
            'performed_by_user_id' => ['nullable', 'uuid', $this->existsInTenant('users')],

            // Which of the franchise's service areas the work is in. Its
            // franchise is checked below, for the same reason as the performer.
            'service_area_id' => ['nullable', 'uuid'],

            // The organization's own words (2026-10-20); Job tidies them.
            'job_type'   => ['nullable', 'string', 'max:100'],
            'sub_status' => ['nullable', 'string', 'max:100'],
            'tags'       => ['nullable', 'array', 'max:30'],
            'tags.*'     => ['nullable', 'string', 'max:100'],
        ];
    }

    /**
     * Distinct types, sub-statuses and tags on the jobs in reach, sorted.
     *
     * @param  list<string>  $reach
     * @return array{job_types: list<string>, sub_statuses: list<string>, tags: list<string>}
     */
    private function labelsIn(array $reach): array
    {
        $distinct = fn (string $column) => Job::query()->whereIn('franchise_id', $reach)->whereNotNull($column)
            ->distinct()->orderBy($column)->pluck($column)->all();

        // Tags live in a JSON list: JSON_TABLE spreads them into rows.
        $tags = $reach === [] ? [] : array_column(DB::select(
            'SELECT DISTINCT t.tag FROM jobs, JSON_TABLE(jobs.tags, \'$[*]\' COLUMNS (tag VARCHAR(100) PATH \'$\')) t
             WHERE jobs.organization_id = ? AND jobs.deleted_at IS NULL AND jobs.franchise_id IN ('.implode(',', array_fill(0, count($reach), '?')).')
             ORDER BY t.tag',
            [$this->tenant->idOrFail(), ...$reach],
        ), 'tag');

        return ['job_types' => $distinct('job_type'), 'sub_statuses' => $distinct('sub_status'), 'tags' => $tags];
    }

    /** @return array{total: int, due: int, state: string, due_on: ?string, currency: string}|null */
    private function moneySummary(Job $job): ?array
    {
        $m = WorkizMoney::for($job);

        // `currency` so a list row can say its amounts without a second key to
        // look in: a Due column formatted in the wrong currency reads as right.
        return $m === null ? null
            : ['total' => $m['total'], 'due' => $m['due'], 'state' => $m['state'], 'due_on' => $m['due_on'], 'currency' => $m['currency']];
    }

    private function present(Job $job, bool $detailed = false, bool $money = false): array
    {
        // Only what the BASE payload reads. `lines` and `performedBy` are
        // detail-only and are loaded in that branch below: loading them here
        // ran on every list row, for a panel nobody had opened (UI/UX measured
        // it, 2026-09-24; JobListQueryCountTest pins it).
        $job->loadMissing([
            'franchise:id,name,timezone,company_id', 'franchise.company:id,name', 'customer:id,name,number,owning_franchise_id',
            'assignedRole:id,name', 'serviceArea',
        ]);

        $base = [
            'id'     => $job->id,
            'number' => $job->number,
            // Workiz's own job number, its "Job #", beside EzBizi's (Jose,
            // 2026-09-29). Null for a job Workiz does not have.
            'workiz_job_number' => $job->workizJob?->serial_id,
            'title'  => $job->title,
            'status' => $job->status,
            'status_label' => $job->franchise
                ? Job::labelFor($job->status, $job->franchise)
                : Job::humanizeStatus($job->status),
            'scheduled_for' => $job->scheduled_for?->toIso8601String(),
            'franchise' => [
                'id'   => $job->franchise_id,
                'name' => $job->franchise?->name,
            ],
            // Through the franchise: the list's Company column at organization
            // level (Jose, 2026-10-19).
            'company' => [
                'id'   => $job->franchise?->company_id,
                'name' => $job->franchise?->company?->name,
            ],
            'customer' => [
                'id'     => $job->customer_id,
                'name'   => $job->customer?->name,
                'number' => $job->customer?->number,
                // So a technician can see at a glance that this job is for
                // another franchise's customer, before they change anything.
                'owned_by_this_franchise' =>
                    $job->customer?->owning_franchise_id === $job->franchise_id,
            ],
            // A list row's view of Workiz's money; the detail carries all of it
            // below. Not on the detail: `$base + [...]` would keep this one.
            ...($money && ! $detailed ? ['money' => $this->moneySummary($job)] : []),
            // Only with the Financials toggle: ABSENT without it, never null,
            // so the client draws nothing rather than a zero.
            ...($money ? ['amount' => [
                'minor'    => $job->amount_minor,
                'currency' => $job->currency,
                'display'  => $job->amountForDisplay(),
            ]] : []),
            // In the base payload too, not just detailed — the list needs it
            // for its own column.
            'assigned_role' => $job->assignedRole
                ? ['id' => $job->assigned_role_id, 'name' => $job->assignedRole->name]
                : null,
            // Null when nothing placed the job (JobServiceArea) and nobody chose.
            'service_area' => $job->serviceArea
                ? ['id' => $job->service_area_id, 'name' => $job->serviceArea->name]
                : null,
            'job_type'   => $job->job_type,
            'sub_status' => $job->sub_status,
            'tags'       => $job->tags ?? [],
        ];

        if (! $detailed) {
            return $base;
        }

        $job->loadMissing(['lines', 'performedBy:id,name', 'workizJob']);

        return $base + [
            'description'      => $job->description,
            'duration_minutes' => $job->duration_minutes,
            'allowed_statuses' => $job->franchise
                ? Job::statusEntries($job->franchise)
                : [],
            // The choices for the area, here rather than from Scheduling's
            // endpoint, which is gated by a permission a job editor may lack.
            // The job's own area always, even another franchise's: one
            // re-homed under it (Boston North to MA, 2026-10-05) otherwise drew
            // as "Not placed" and invited a person to move the job out of it.
            'allowed_service_areas' => ServiceArea::query()
                ->where(fn ($q) => $q->where('franchise_id', $job->franchise_id)->orWhere('id', $job->service_area_id))
                ->orderBy('name')
                ->get(['id', 'name'])->map(fn (ServiceArea $a) => ['id' => $a->id, 'name' => $a->name])->all(),
            // Workiz's own money for the job, read live from the mirror, which
            // is where A-1's invoices live (Jose, decision 2). Null for a job
            // Workiz does not have. Financials only.
            ...($money ? ['money' => WorkizMoney::for($job)] : []),
            // The rest of what Workiz v2 has on the job (WorkizExtras): fields and
            // files for anybody here, its invoices and payments with Financials.
            'workiz' => WorkizExtras::for($job, $money),
            // Newest first, with who wrote each (step c, 2026-10-20).
            'notes' => $job->notes()->with('user:id,name')->latest('created_at')->latest('id')->get()
                ->map(fn (\App\Models\JobNote $n) => JobNotesController::present($n))->all(),
            // The lines ARE the money breakdown: Financials only.
            ...($money ? ['lines' => $job->lines->map(fn (JobLine $line) => [
                'id'               => $line->id,
                'service_id'       => $line->service_id,
                'name'             => $line->name,
                'unit_price_minor' => $line->unit_price_minor,
                'qty'              => $line->qty,
                'amount_minor'     => $line->amount_minor,
            ])->all()] : []),
            'performed_by' => $job->performedBy
                ? ['id' => $job->performed_by_user_id, 'name' => $job->performedBy->name]
                : null,
            'earned_at'  => $job->earned_at?->toIso8601String(),
            'created_at' => $job->created_at->toIso8601String(),
        ] + $this->materialsFor($job) + $this->inspectionsFor($job);
    }

    /**
     * The inspection reports submitted on this job (spec §10) — or nothing.
     *
     * Gated by INSPECTION's own grant, `inspection.view`, and deliberately NOT
     * by a Jobs tab key. A report is a document Inspection owns behind
     * InspectionReportPolicy; a Jobs tab that listed reports to anybody holding
     * the tab would be a way past that policy. Settings' rule from inbox 0051:
     * a tab is an AND on top of the capability, never an alternative. And with
     * the capability required anyway, a tab key would only let an administrator
     * hide inspections-on-a-job from somebody who may read every inspection
     * elsewhere, which is not a split anybody makes — so no key was minted.
     *
     * The org check the policy also makes is satisfied by construction here:
     * InspectionReport uses BelongsToTenant, and the job is already this
     * tenant's.
     *
     * Archived reports are left out because Inspection's own list leaves them
     * out (InspectionReportsController::index); this is not a second,
     * differently-filtered view of their data. `definition_name` is the
     * report's own SNAPSHOT of what the inspection was called when it was
     * submitted, so renaming the template later does not rename past reports.
     *
     * @return array{inspections?: list<array{id: string, name: ?string, submitted_at: ?string}>}
     */
    private function inspectionsFor(Job $job): array
    {
        if (! request()->user()?->hasPermission('inspection.view')) {
            return [];
        }

        return [
            'inspections' => InspectionReport::query()
                ->where('job_id', $job->getKey())
                ->whereNull('archived_at')
                ->orderBy('submitted_at')
                ->get(['id', 'definition_name', 'submitted_at'])
                ->map(fn (InspectionReport $report) => [
                    'id'           => $report->id,
                    'name'         => $report->definition_name,
                    'submitted_at' => $report->submitted_at?->toIso8601String(),
                ])->all(),
        ];
    }

    /**
     * What Inventory issued against this job — or nothing at all (spec §9).
     *
     * ── Why the gate is HERE and not on the route ────────────────────
     *
     * `GET /jobs/{job}` feeds every pane of the detail panel, so middleware on
     * it would refuse the whole job to somebody who may read it. Inbox 0051's
     * corrected step 3 is explicit: route middleware belongs where a route
     * serves exactly one tab, and where it serves several the split goes beside
     * the one already inside the controller. This is that split.
     *
     * The key is ABSENT rather than an empty list when the grant is missing, so
     * a client cannot read "no materials were used on this job" out of "you may
     * not see them". Those are different sentences and only one of them is true.
     *
     * ── And there is no money here, for anybody ──────────────────────
     *
     * Not even for a caller holding `inventory.financials`. This is modelling
     * rather than a permission strip: a job's money is what the CUSTOMER is
     * charged, which is the service lines above; what the parts cost the
     * business belongs to Inventory. A surface carrying both publishes a margin
     * nobody agreed to publish. If a cost ever appears here the fix is to stop
     * sending it, never to gate it.
     *
     * @return array{materials?: list<array{item: ?string, qty: float, issued_on: ?string}>}
     */
    private function materialsFor(Job $job): array
    {
        if (! request()->user()?->hasPermission('jobs.materials.view')) {
            return [];
        }

        return [
            'materials' => InventoryIssueLine::query()
                ->whereHas('issue', fn ($q) => $q->where('job_id', $job->getKey()))
                ->with(['item:id,name', 'issue:id,issued_on'])
                ->get()
                ->map(fn (InventoryIssueLine $line) => [
                    'item'      => $line->item?->name,
                    'qty'       => (float) $line->qty,
                    'issued_on' => $line->issue?->issued_on?->toDateString(),
                ])->all(),
        ];
    }
}
