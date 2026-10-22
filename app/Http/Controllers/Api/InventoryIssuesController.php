<?php

namespace App\Http\Controllers\Api;

use App\Models\InventoryIssue;
use App\Models\InventoryItem;
use App\Models\InventoryLocation;
use App\Models\Job;
use App\Support\Inventory\InventoryMover;
use App\Support\Inventory\InventoryVisibility;
use App\Support\Scoping\ViewScope;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Exists;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Stock leaving a location, posted on save (spec §8.4).
 *
 * Location `update`. Each line's item must be visible. Job XOR a note:
 * the job must appear in the caller's GET /jobs view. Naming it does
 * not require jobs.edit and does not write jobs columns.
 */
class InventoryIssuesController extends Controller
{
    public function __construct(
        private readonly InventoryMover $mover,
        private readonly TenantContext $tenant,
    ) {}

    /**
     * An `exists` rule scoped to this organization.
     *
     * Laravel's plain `exists` runs a raw query with no tenant scope, so naming
     * another organization's id passes validation and only fails later as a 404.
     * Two different answers for two different ids is an existence oracle — it
     * tells a caller which ids are real somewhere else on the platform.
     */
    private function existsInTenant(string $table): Exists
    {
        return Rule::exists($table, 'id')
            ->where('organization_id', $this->tenant->idOrFail());
    }

    /** GET /api/v1/inventory/issues */
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', InventoryIssue::class);

        $user = $request->user();
        $scope = ViewScope::forUser($user);

        $query = InventoryIssue::query()
            ->whereHas(
                'location',
                fn (Builder $q) => InventoryVisibility::constrain($q, $user, $scope),
            );

        $term = trim((string) ($request->validate(['q' => ['nullable', 'string', 'max:200']])['q'] ?? ''));
        if ($term !== '') {
            $like = '%'.addcslashes($term, '%_\\').'%';
            $query->where(fn (Builder $w) => $w->where('number', 'like', $like)
                ->orWhere('note', 'like', $like)
                ->orWhereHas('location', fn (Builder $l) => $l->where('name', 'like', $like))
                ->orWhereHas('job', fn (Builder $j) => $j->where('number', 'like', $like)->orWhere('title', 'like', $like)));
        }

        $issues = $query
            ->with(['location', 'job', 'lines.item'])
            ->orderByDesc('issued_on')
            ->orderByDesc('created_at')
            ->paginate(perPage: min((int) $request->query('per_page', 50), 200));

        return response()->json([
            'data' => collect($issues->items())->map(
                fn (InventoryIssue $issue) => $this->present($issue),
            ),
            'meta' => $this->pageMeta($issues),
        ]);
    }

    /** POST /api/v1/inventory/issues */
    public function store(Request $request): JsonResponse
    {
        $input = $request->validate([
            'location_id' => ['required', 'uuid', $this->existsInTenant('inventory_locations')],
            'job_id' => ['nullable', 'uuid'],
            'note' => ['nullable', 'string'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.item_id' => ['required', 'uuid', $this->existsInTenant('inventory_items')],
            'lines.*.qty' => ['required', 'numeric', 'gt:0'],
        ]);

        $note = isset($input['note']) ? trim((string) $input['note']) : '';
        if ($note === '') {
            $note = null;
        }

        $jobId = $input['job_id'] ?? null;

        if ($jobId === null && $note === null) {
            throw ValidationException::withMessages([
                'job_id' => 'Say which job this is for, or why it left.',
            ]);
        }

        $location = InventoryLocation::query()->findOrFail($input['location_id']);

        $this->authorize('create', [InventoryIssue::class, $location]);

        $job = $jobId === null ? null : $this->jobInView($request, $jobId);

        $lines = [];
        foreach ($input['lines'] as $line) {
            $item = InventoryItem::query()->findOrFail($line['item_id']);
            $this->authorize('view', $item);
            $lines[] = [
                'item' => $item,
                'qty' => (string) $line['qty'],
            ];
        }

        $issue = $this->mover->issue($location, $lines, $request->user(), $job, $note);

        return response()->json(
            ['data' => $this->present($issue->fresh(['lines.item', 'location', 'job']))],
            Response::HTTP_CREATED,
        );
    }

    /**
     * Same franchise filter as GET /jobs — ViewScope ∩ reach, excluding
     * deleted. Missing, foreign, or out-of-view ids share one sentence.
     */
    private function jobInView(Request $request, string $jobId): Job
    {
        $reachable = ViewScope::forUser($request->user())->franchiseIds();

        $job = Job::query()
            ->whereIn('franchise_id', $reachable)
            ->find($jobId);

        if ($job === null) {
            throw ValidationException::withMessages([
                'job_id' => 'That job is not in this view.',
            ]);
        }

        return $job;
    }

    /** @return array<string, mixed> */
    private function present(InventoryIssue $issue): array
    {
        return [
            'id' => $issue->id,
            'number' => $issue->number,
            'issued_on' => $issue->issued_on?->toDateString(),
            'note' => $issue->note,
            'job' => $issue->job === null ? null : [
                'id' => $issue->job->id,
                'number' => $issue->job->number,
                'title' => $issue->job->title,
            ],
            'location' => $issue->location === null ? null : [
                'id' => $issue->location->id,
                'name' => $issue->location->name,
            ],
            'lines' => $issue->lines->map(static function ($line): array {
                return [
                    'id' => $line->id,
                    'qty' => (string) $line->qty,
                    'item' => $line->item === null ? null : [
                        'id' => $line->item->id,
                        'name' => $line->item->name,
                    ],
                ];
            })->values()->all(),
        ];
    }
}
