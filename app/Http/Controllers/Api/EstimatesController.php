<?php

namespace App\Http\Controllers\Api;

use App\Models\Estimate;
use App\Models\EstimateLine;
use App\Models\Franchise;
use App\Models\Job;
use App\Support\Audit\AuditLogger;
use App\Support\Automations\EventEmitter;
use App\Support\Numbering\RecordNumberGenerator;
use App\Support\Scoping\ViewScope;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Estimates, inside Jobs (spec docs/superpowers/specs/2026-09-29-jobs-billing-design.md §4).
 *
 * Money, so EstimatePolicy asks for Jobs' Financials toggle on every action.
 * A status moves only along NEXT; its lines change only while it is undecided;
 * only a draft is deleted, and anything further along is archived instead.
 */
class EstimatesController extends Controller
{
    /** Where each status may go. Sending the status it already has is a no-op. */
    private const NEXT = [
        'draft'    => ['sent', 'archived'],
        'sent'     => ['accepted', 'declined', 'archived'],
        'accepted' => ['archived'],
        'declined' => ['archived'],
        'archived' => [],
    ];

    /** Lines may change while the customer has not decided. */
    private const OPEN = ['draft', 'sent'];

    public function __construct(
        private readonly RecordNumberGenerator $numbers,
        private readonly AuditLogger $audit,
        private readonly TenantContext $tenant,
        private readonly EventEmitter $events,
    ) {}

    /** GET /api/v1/estimates */
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Estimate::class);

        $request->validate([
            'customer_id' => ['nullable', 'uuid'],
            'job_id'      => ['nullable', 'uuid'],
            'status'      => ['nullable', Rule::in(Estimate::STATUSES)],
            'search'      => ['nullable', 'string', 'max:100'],
        ]);

        $reach = ViewScope::forUser($request->user())->franchiseIds();

        // Every filter, the status OPTIONAL: the header below leaves it out, the
        // way the jobs chips do, so a chip says what tapping it would show.
        $filtered = fn (bool $withStatus = true) => Estimate::query()
            // Reach and the current view, as for jobs: an estimate is one
            // franchise's, and whereIn on nothing is nothing.
            ->whereIn('franchise_id', $reach)
            ->when($request->query('customer_id'), fn ($q, $id) => $q->where('customer_id', $id))
            ->when($request->query('job_id'), fn ($q, $id) => $q->where('job_id', $id))
            ->when($withStatus ? $request->query('status') : null, fn ($q, $s) => $q->where('status', $s))
            // "Number or customer" (queue 32), the way the jobs list searches.
            ->when(filled($request->query('search')), function ($q) use ($request) {
                $like = '%'.trim((string) $request->query('search')).'%';
                $q->where(fn ($w) => $w->where('number', 'like', $like)
                    ->orWhereHas('customer', fn ($c) => $c->where('name', 'like', $like)->orWhere('number', 'like', $like)));
            });

        $page = $filtered()
            ->with(['franchise', 'customer', 'job'])
            ->orderByDesc('created_at')
            ->paginate(perPage: min((int) $request->query('per_page', 50), 200));

        $meta = $this->pageMeta($page);

        // The list's header, on the first page only (queue 32): each status's
        // count, and what was quoted and accepted per currency -- never summed
        // across currencies, and OBJECTS even when empty (an empty PHP array
        // encodes as []).
        if ($page->currentPage() === 1) {
            $counts = $filtered(withStatus: false)->toBase()
                ->select('status', DB::raw('count(*) as n'))->groupBy('status')->pluck('n', 'status');
            $meta['status_counts'] = array_combine(Estimate::STATUSES,
                array_map(fn (string $s) => (int) ($counts[$s] ?? 0), Estimate::STATUSES));

            $meta['totals'] = (object) $filtered(withStatus: false)->toBase()
                ->selectRaw("currency, SUM(total_minor) AS quoted, SUM(CASE WHEN status = 'accepted' THEN total_minor ELSE 0 END) AS accepted")
                ->groupBy('currency')->get()
                ->mapWithKeys(fn ($r) => [$r->currency => ['quoted' => (int) $r->quoted, 'accepted' => (int) $r->accepted]])
                ->all();
        }

        return response()->json([
            'data' => collect($page->items())->map(fn (Estimate $e) => $this->present($e)),
            'meta' => $meta,
        ]);
    }

    /** GET /api/v1/estimates/{estimate} */
    public function show(Estimate $estimate): JsonResponse
    {
        $this->authorize('view', $estimate);

        return response()->json(['data' => $this->present($estimate, detailed: true)]);
    }

    /** POST /api/v1/estimates */
    public function store(Request $request): JsonResponse
    {
        $input = $request->validate([
            'franchise_id' => ['required', 'uuid', $this->existsInTenant('franchises')],
            'customer_id'  => ['required', 'uuid', $this->existsInTenant('customers')],
            'job_id'       => ['nullable', 'uuid', $this->existsInTenant('jobs')],
            'title'        => ['nullable', 'string', 'max:191'],
            'note'         => ['nullable', 'string', 'max:10000'],
            'issued_on'    => ['nullable', 'date'],
            ...$this->lineRules(),
        ]);

        $franchise = Franchise::findOrFail($input['franchise_id']);
        $this->authorize('create', [Estimate::class, $franchise]);
        $this->assertJobFits($input['job_id'] ?? null, $input['customer_id'], $franchise->getKey());

        $estimate = DB::transaction(function () use ($input, $franchise) {
            $estimate = Estimate::create([
                'franchise_id' => $franchise->getKey(),
                'customer_id'  => $input['customer_id'],
                'job_id'       => $input['job_id'] ?? null,
                'number'       => $this->numbers->next(RecordNumberGenerator::ESTIMATES, $franchise),
                'title'        => $input['title'] ?? null,
                'status'       => 'draft',
                'issued_on'    => $input['issued_on'] ?? null,
                'note'         => $input['note'] ?? null,
                // From the franchise, so an amount and its currency cannot disagree.
                'currency'     => strtoupper($franchise->currency ?: 'USD'),
            ]);

            $this->replaceLines($estimate, $input['lines'] ?? []);
            $estimate->save();

            $this->audit->log('estimate.created', $estimate, metadata: ['number' => $estimate->number]);

            return $estimate;
        });

        return response()->json(['data' => $this->present($estimate->fresh(), detailed: true)], Response::HTTP_CREATED);
    }

    /** PATCH /api/v1/estimates/{estimate} */
    public function update(Request $request, Estimate $estimate): JsonResponse
    {
        $this->authorize('update', $estimate);

        $input = $request->validate([
            'status'    => ['sometimes', Rule::in(Estimate::STATUSES)],
            'title'     => ['sometimes', 'nullable', 'string', 'max:191'],
            'note'      => ['sometimes', 'nullable', 'string', 'max:10000'],
            'issued_on' => ['sometimes', 'nullable', 'date'],
            ...$this->lineRules(),
        ]);

        $before = $estimate->status;
        $after = $input['status'] ?? $before;

        if ($after !== $before && ! in_array($after, self::NEXT[$before], true)) {
            throw ValidationException::withMessages(['status' => "An estimate cannot go from {$before} to {$after}."]);
        }

        if (array_key_exists('lines', $input) && ! in_array($before, self::OPEN, true)) {
            throw ValidationException::withMessages(['lines' => "A decided estimate's lines are fixed."]);
        }

        DB::transaction(function () use ($estimate, $input, $before, $after) {
            $estimate->fill(array_intersect_key($input, array_flip(['title', 'note', 'issued_on'])));
            $estimate->status = $after;

            $accepted = $after === 'accepted' && $before !== 'accepted';
            if ($accepted) {
                $estimate->accepted_at = now();
            }

            if (array_key_exists('lines', $input)) {
                $this->replaceLines($estimate, $input['lines']);
            }

            // After the lines, so a changed total is part of what is audited.
            $this->audit->logChange('estimate.updated', $estimate);
            $estimate->save();

            if ($accepted) {
                $this->events->emit('estimate.accepted', $estimate, [
                    'before' => ['status' => $before],
                    'after'  => ['status' => 'accepted'],
                ]);
            }
        });

        return response()->json(['data' => $this->present($estimate->fresh(), detailed: true)]);
    }

    /** DELETE /api/v1/estimates/{estimate} */
    public function destroy(Estimate $estimate): JsonResponse
    {
        $this->authorize('delete', $estimate);

        if ($estimate->status !== 'draft') {
            throw ValidationException::withMessages(['status' => 'Only a draft can be deleted; archive it instead.']);
        }

        DB::transaction(function () use ($estimate) {
            $this->audit->log('estimate.deleted', $estimate, metadata: ['number' => $estimate->number]);
            $estimate->delete();
        });

        return response()->json(null, Response::HTTP_NO_CONTENT);
    }

    /** An `exists` rule scoped to this organization, as in JobsController. */
    private function existsInTenant(string $table): \Illuminate\Validation\Rules\Exists
    {
        return Rule::exists($table, 'id')->where('organization_id', $this->tenant->idOrFail());
    }

    private function lineRules(): array
    {
        return [
            'lines'                    => ['sometimes', 'array', 'max:200'],
            'lines.*.service_id'       => ['nullable', 'uuid', $this->existsInTenant('services')],
            'lines.*.name'             => ['required', 'string', 'max:191'],
            'lines.*.description'      => ['nullable', 'string', 'max:2000'],
            'lines.*.quantity'         => ['required', 'numeric', 'min:0.01', 'max:99999'],
            'lines.*.unit_price_minor' => ['required', 'integer', 'min:0'],
        ];
    }

    /** The whole list, replaced: an estimate is edited as one document. The total is set, not saved. */
    private function replaceLines(Estimate $estimate, array $lines): void
    {
        $estimate->lines()->delete();

        foreach (array_values($lines) as $position => $line) {
            $estimate->lines()->create([
                'service_id'       => $line['service_id'] ?? null,
                'name'             => $line['name'],
                'description'      => $line['description'] ?? null,
                'quantity'         => $line['quantity'],
                'unit_price_minor' => $line['unit_price_minor'],
                'position'         => $position,
            ]);
        }

        $estimate->total_minor = $estimate->computeTotal();
    }

    /** A job named on an estimate must be the same customer's, at the same franchise. */
    private function assertJobFits(?string $jobId, string $customerId, string $franchiseId): void
    {
        if ($jobId === null) {
            return;
        }

        $job = Job::query()->find($jobId);

        if ($job === null || $job->customer_id !== $customerId || $job->franchise_id !== $franchiseId) {
            throw ValidationException::withMessages(['job_id' => "That job is another customer's or another franchise's."]);
        }
    }

    private function present(Estimate $e, bool $detailed = false): array
    {
        $e->loadMissing(['franchise', 'customer', 'job']);

        $base = [
            'id'          => $e->id,
            'number'      => $e->number,
            'title'       => $e->title,
            'status'      => $e->status,
            'source'      => $e->source,
            'franchise'   => ['id' => $e->franchise_id, 'name' => $e->franchise?->name],
            'customer'    => ['id' => $e->customer_id, 'name' => $e->customer?->name, 'number' => $e->customer?->number],
            'job'         => $e->job ? ['id' => $e->job_id, 'number' => $e->job->number] : null,
            'issued_on'   => $e->issued_on?->toDateString(),
            'accepted_at' => $e->accepted_at?->toIso8601String(),
            // A1's own date on an imported row, not the import's: the importer keeps it.
            'created_at'  => $e->created_at?->toIso8601String(),
            'currency'    => $e->currency,
            'total_minor' => $e->total_minor,
            'note'        => $e->note,
        ];

        if (! $detailed) {
            return $base;
        }

        $e->loadMissing('lines');

        return $base + [
            'lines' => $e->lines->map(fn (EstimateLine $l) => [
                'id'               => $l->id,
                'service_id'       => $l->service_id,
                'name'             => $l->name,
                'description'      => $l->description,
                'quantity'         => (float) $l->quantity,
                'unit_price_minor' => $l->unit_price_minor,
            ])->all(),
        ];
    }
}
