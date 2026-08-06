<?php

namespace App\Http\Controllers\Api;

use App\Models\Customer;
use App\Models\Franchise;
use App\Models\Job;
use App\Support\Audit\AuditLogger;
use App\Support\Numbering\RecordNumberGenerator;
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
    ) {}

    /** GET /api/v1/jobs */
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Job::class);

        $reachable = $request->user()->reachableFranchises()->pluck('id');

        $jobs = Job::query()
            ->with(['franchise:id,name', 'customer:id,name,number,owning_franchise_id'])
            // The roll-up, and the boundary. whereIn on an empty list matches
            // nothing, which is the correct answer for a user with no
            // assignments — not "everything".
            ->whereIn('franchise_id', $reachable)
            ->when($request->query('franchise_id'), fn ($q, $id) => $q->where('franchise_id', $id))
            ->when($request->query('customer_id'), fn ($q, $id) => $q->where('customer_id', $id))
            ->when($request->query('status'), fn ($q, $s) => $q->where('status', $s))
            ->orderByDesc('scheduled_for')
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

            $this->audit->logChange('job.updated', $job);

            $job->save();
        });

        return response()->json(['data' => $this->present($job->fresh(), detailed: true)]);
    }

    /** DELETE /api/v1/jobs/{job} */
    public function destroy(Job $job): JsonResponse
    {
        $this->authorize('delete', $job);

        DB::transaction(function () use ($job) {
            $this->audit->log('job.deleted', $job);
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
            'franchise_id' => $forUpdate
                ? ['prohibited']
                : ['required', 'uuid', Rule::exists('franchises', 'id')],
            'customer_id'  => $forUpdate
                ? ['prohibited']
                : ['required', 'uuid', Rule::exists('customers', 'id')],

            'title'            => [$required, 'string', 'max:200'],
            'status'           => [$required, 'string', 'max:60'],
            'description'      => ['nullable', 'string', 'max:10000'],
            'scheduled_for'    => ['nullable', 'date'],
            'duration_minutes' => ['nullable', 'integer', 'min:1', 'max:100000'],
            // Minor units, integer (§7). Accepting a decimal here is how floats
            // get into money — the client sends cents or nothing.
            'amount_minor'     => ['nullable', 'integer', 'min:0'],
            'currency'         => ['nullable', 'string', 'size:3'],
        ];
    }

    private function present(Job $job, bool $detailed = false): array
    {
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
