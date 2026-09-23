<?php

namespace App\Http\Controllers\Api;

use App\Models\Customer;
use App\Models\Franchise;
use App\Models\InventoryIssueLine;
use App\Models\Job;
use App\Models\JobLine;
use App\Models\User;
use App\Support\Audit\AuditLogger;
use App\Support\Automations\EventEmitter;
use App\Support\Jobs\EarnedStatuses;
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

    /** GET /api/v1/jobs */
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Job::class);

        // The current view, already narrowed to what §4 allows.
        $reachable = collect(\App\Support\Scoping\ViewScope::forUser($request->user())->franchiseIds());

        $jobs = Job::query()
            ->with(['franchise:id,name', 'customer:id,name,number,owning_franchise_id'])
            // The roll-up, and the boundary. whereIn on an empty list matches
            // nothing, which is the correct answer for a user with no
            // assignments — not "everything".
            ->whereIn('franchise_id', $reachable)
            ->when($request->query('franchise_id'), fn ($q, $id) => $q->where('franchise_id', $id))
            ->when($request->query('customer_id'), fn ($q, $id) => $q->where('customer_id', $id))
            ->when($request->query('status'), fn ($q, $s) => $q->where('status', $s))
            ->when($request->query('assigned_role_id'), fn ($q, $id) => $q->where('assigned_role_id', $id))
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
            // COALESCE, not scheduled_for alone. MySQL sorts NULLs last under
            // DESC, and a job created without a date has a null one — so a user
            // who had just created one watched it fail to appear.
            ->orderByRaw('COALESCE(scheduled_for, created_at) DESC')
            ->orderByDesc('created_at')
            ->paginate(perPage: min((int) $request->query('per_page', 50), 200));

        return response()->json([
            'data' => collect($jobs->items())->map(fn (Job $j) => $this->present($j)),
            'meta' => [
                'total'        => $jobs->total(),
                'per_page'     => $jobs->perPage(),
                'current_page' => $jobs->currentPage(),
                'last_page'    => $jobs->lastPage(),
            ],
        ]);
    }

    /** GET /api/v1/jobs/{job} */
    public function show(Job $job): JsonResponse
    {
        $this->authorize('view', $job);

        return response()->json(['data' => $this->present($job, detailed: true)]);
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

        $franchise = Franchise::findOrFail($input['franchise_id']);
        $customer  = Customer::findOrFail($input['customer_id']);

        $this->authorize('create', [Job::class, $franchise]);

        $this->assertStatusAllowed($input['status'], $franchise);
        $this->assertPerformerReach($input['performed_by_user_id'] ?? null, $franchise);

        $job = DB::transaction(function () use ($input, $franchise, $customer) {
            $job = Job::create($input + [
                'number' => $this->numbers->next(RecordNumberGenerator::JOBS, $franchise),
                // From the franchise, so the amount and its currency can never
                // disagree with where the work happened.
                'currency' => strtoupper($franchise->currency ?: 'USD'),
            ]);

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
            ['data' => $this->present($job->fresh(), detailed: true)],
            Response::HTTP_CREATED
        );
    }

    /** PATCH /api/v1/jobs/{job} */
    public function update(Request $request, Job $job): JsonResponse
    {
        $this->authorize('update', $job);

        $input = $request->validate($this->rules(forUpdate: true));

        if (isset($input['status'])) {
            $this->assertStatusAllowed($input['status'], $job->franchise);
        }

        if (array_key_exists('performed_by_user_id', $input)) {
            $this->assertPerformerReach($input['performed_by_user_id'], $job->franchise);
        }

        DB::transaction(function () use ($job, $input) {
            $job->fill($input);

            // The money is earned once, at the first transition into an earned
            // status. Not re-stamped on a later correction: a closed month has
            // to stay closed (§8.1).
            if (array_key_exists('status', $job->getDirty())
                && $job->earned_at === null
                && EarnedStatuses::isEarned($job->status, $job->franchise)) {
                $job->earned_at = now();
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

        return response()->json(['data' => $this->present($job->fresh(), detailed: true)]);
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
        ];
    }

    private function present(Job $job, bool $detailed = false): array
    {
        $job->loadMissing([
            'franchise:id,name,timezone', 'customer:id,name,number,owning_franchise_id', 'lines',
            'assignedRole:id,name', 'performedBy:id,name',
        ]);

        $base = [
            'id'     => $job->id,
            'number' => $job->number,
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
            'customer' => [
                'id'     => $job->customer_id,
                'name'   => $job->customer?->name,
                'number' => $job->customer?->number,
                // So a technician can see at a glance that this job is for
                // another franchise's customer, before they change anything.
                'owned_by_this_franchise' =>
                    $job->customer?->owning_franchise_id === $job->franchise_id,
            ],
            'amount' => [
                'minor'    => $job->amount_minor,
                'currency' => $job->currency,
                'display'  => $job->amountForDisplay(),
            ],
            // In the base payload too, not just detailed — the list needs it
            // for its own column.
            'assigned_role' => $job->assignedRole
                ? ['id' => $job->assigned_role_id, 'name' => $job->assignedRole->name]
                : null,
        ];

        if (! $detailed) {
            return $base;
        }

        return $base + [
            'description'      => $job->description,
            'duration_minutes' => $job->duration_minutes,
            'allowed_statuses' => $job->franchise
                ? Job::statusEntries($job->franchise)
                : [],
            'lines' => $job->lines->map(fn (JobLine $line) => [
                'id'               => $line->id,
                'service_id'       => $line->service_id,
                'name'             => $line->name,
                'unit_price_minor' => $line->unit_price_minor,
                'qty'              => $line->qty,
                'amount_minor'     => $line->amount_minor,
            ])->all(),
            'performed_by' => $job->performedBy
                ? ['id' => $job->performed_by_user_id, 'name' => $job->performedBy->name]
                : null,
            'earned_at'  => $job->earned_at?->toIso8601String(),
            'created_at' => $job->created_at->toIso8601String(),
        ] + $this->materialsFor($job);
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
