<?php

namespace App\Http\Controllers\Api;

use App\Models\Customer;
use App\Models\Franchise;
use App\Models\Job;
use App\Support\Audit\AuditLogger;
use App\Support\Automations\EventEmitter;
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

        DB::transaction(function () use ($job, $input) {
            $job->fill($input);

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
        ];
    }

    private function present(Job $job, bool $detailed = false): array
    {
        $job->loadMissing(['franchise:id,name,timezone', 'customer:id,name,number,owning_franchise_id']);

        $base = [
            'id'     => $job->id,
            'number' => $job->number,
            'title'  => $job->title,
            'status' => $job->status,
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
        ];

        if (! $detailed) {
            return $base;
        }

        return $base + [
            'description'      => $job->description,
            'duration_minutes' => $job->duration_minutes,
            'allowed_statuses' => $job->franchise
                ? Job::allowedStatuses($job->franchise)
                : [],
            'created_at' => $job->created_at->toIso8601String(),
        ];
    }
}
