<?php

namespace App\Http\Controllers\Api;

use App\Models\Company;
use App\Models\Franchise;
use App\Models\InventoryItem;
use App\Models\User;
use App\Support\Inventory\InventoryFinancials;
use App\Support\Inventory\InventoryItemStatus;
use App\Support\Inventory\InventoryUnit;
use App\Support\Inventory\InventoryVisibility;
use App\Support\Numbering\RecordNumberGenerator;
use App\Support\Scoping\ViewScope;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Inventory catalogue — list, create, show, update, destroy (spec §5).
 *
 * Create is one transaction: item + allocated number. Discontinue is a status,
 * not a delete. Hard delete only when the item has never moved. Last cost is
 * stripped on every read unless the caller holds inventory.financials. List
 * always applies InventoryVisibility.
 */
class InventoryItemsController extends Controller
{
    public function __construct(
        private readonly RecordNumberGenerator $numbers,
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
    private function existsInTenant(string $table): \Illuminate\Validation\Rules\Exists
    {
        return Rule::exists($table, 'id')
            ->where('organization_id', $this->tenant->idOrFail());
    }

    /** GET /api/v1/inventory/items */
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', InventoryItem::class);

        $user = $request->user();
        $scope = ViewScope::forUser($user);

        $query = InventoryVisibility::constrain(InventoryItem::query(), $user, $scope);

        $statuses = $this->requestedStatuses($request);
        $query->whereIn('status', $statuses);

        if (filled($request->query('search'))) {
            $term = trim((string) $request->query('search'));
            $like = '%'.$term.'%';
            $query->where(function (Builder $inner) use ($like): void {
                $inner->where('name', 'like', $like)
                    ->orWhere('sku', 'like', $like)
                    ->orWhere('number', 'like', $like);
            });
        }

        $items = $query
            ->orderBy('name')
            ->paginate(perPage: min((int) $request->query('per_page', 50), 200));

        return response()->json([
            'data' => collect($items->items())->map(
                fn (InventoryItem $item) => $this->present($item, $user),
            ),
            'meta' => [
                'total' => $items->total(),
                'per_page' => $items->perPage(),
                'current_page' => $items->currentPage(),
                'last_page' => $items->lastPage(),
            ],
        ]);
    }

    /**
     * Status filter from `status[]` or a comma list. When the client sends
     * none, the live catalogue only — discontinued stays hidden.
     *
     * @return list<string>
     */
    private function requestedStatuses(Request $request): array
    {
        $raw = $request->query('status');

        $parsed = match (true) {
            $raw === null, $raw === '', $raw === [] => [],
            is_string($raw) => array_values(array_filter(array_map('trim', explode(',', $raw)))),
            is_array($raw) => array_values(array_filter(array_map(
                static fn ($s) => is_string($s) ? trim($s) : '',
                $raw,
            ))),
            default => [],
        };

        if ($parsed === []) {
            return [InventoryItemStatus::Active->value];
        }

        return $parsed;
    }

    /** POST /api/v1/inventory/items */
    public function store(Request $request): JsonResponse
    {
        $input = $request->validate($this->storeRules($request), $this->storeMessages());

        $company = isset($input['company_id'])
            ? Company::findOrFail($input['company_id'])
            : null;
        $franchise = isset($input['franchise_id'])
            ? Franchise::findOrFail($input['franchise_id'])
            : null;

        $this->assertPlace($company, $franchise);
        $this->assertSkuAvailable(
            $input['sku'] ?? null,
            $company?->getKey(),
            $franchise?->getKey(),
        );

        if ($company === null) {
            $this->authorize('createOnOrganization', InventoryItem::class);
        } else {
            $this->authorize('create', [InventoryItem::class, $company, $franchise]);
        }

        $item = DB::transaction(function () use ($request, $input, $company, $franchise) {
            $number = $this->numbers->next(
                RecordNumberGenerator::ITEMS,
                $franchise ?? $company ?? $request->user()->organization,
            );

            return InventoryItem::create([
                'company_id' => $company?->getKey(),
                'franchise_id' => $franchise?->getKey(),
                'number' => $number,
                'sku' => $input['sku'] ?? null,
                'name' => $input['name'],
                'unit' => $input['unit'],
                'status' => InventoryItemStatus::Active,
                'reorder_at' => $input['reorder_at'] ?? null,
            ]);
        });

        return response()->json(
            ['data' => $this->present($item->fresh(), $request->user())],
            Response::HTTP_CREATED,
        );
    }

    /** GET /api/v1/inventory/items/{item} */
    public function show(Request $request, InventoryItem $item): JsonResponse
    {
        $this->authorize('view', $item);

        return response()->json([
            'data' => $this->present($item, $request->user()),
        ]);
    }

    /** PUT/PATCH /api/v1/inventory/items/{item} */
    public function update(Request $request, InventoryItem $item): JsonResponse
    {
        $this->authorize('update', $item);

        $input = $request->validate($this->updateRules($request, $item), $this->storeMessages());

        if (array_key_exists('company_id', $input) || array_key_exists('franchise_id', $input)) {
            $companyId = array_key_exists('company_id', $input)
                ? $input['company_id']
                : $item->company_id;
            $franchiseId = array_key_exists('franchise_id', $input)
                ? $input['franchise_id']
                : $item->franchise_id;

            $company = $companyId === null ? null : Company::findOrFail($companyId);
            $franchise = $franchiseId === null ? null : Franchise::findOrFail($franchiseId);

            $this->assertPlace($company, $franchise);
            $this->assertSkuAvailable(
                array_key_exists('sku', $input) ? ($input['sku'] ?? null) : $item->sku,
                $company?->getKey(),
                $franchise?->getKey(),
                $item->getKey(),
            );

            if ($companyId === null) {
                $input['franchise_id'] = null;
            }
        } elseif (array_key_exists('sku', $input)) {
            $this->assertSkuAvailable(
                $input['sku'] ?? null,
                $item->company_id,
                $item->franchise_id,
                $item->getKey(),
            );
        }

        $item->fill($input);
        $item->save();

        return response()->json([
            'data' => $this->present($item->fresh(), $request->user()),
        ]);
    }

    /** DELETE /api/v1/inventory/items/{item} */
    public function destroy(InventoryItem $item): Response
    {
        $this->authorize('delete', $item);

        if (! $item->isEmpty()) {
            return response()->json([
                'message' => 'This item has history, so it cannot be removed. Mark it discontinued instead.',
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $item->delete();

        return response()->noContent();
    }

    private function assertSkuAvailable(
        ?string $sku,
        ?string $companyId,
        ?string $franchiseId,
        ?string $ignoreId = null,
    ): void {
        if (! filled($sku)) {
            return;
        }

        $taken = InventoryItem::query()
            ->where('sku', $sku)
            ->where('catalogue_key', $this->catalogueKey($companyId, $franchiseId))
            ->when($ignoreId !== null, fn (Builder $q) => $q->whereKeyNot($ignoreId))
            ->exists();

        if ($taken) {
            throw ValidationException::withMessages([
                'sku' => 'This place already has an item with that SKU.',
            ]);
        }
    }

    private function assertPlace(?Company $company, ?Franchise $franchise): void
    {
        if ($company === null && $franchise !== null) {
            throw ValidationException::withMessages([
                'franchise_id' => 'A place belongs to a company. File the item on the company, or leave it on the group as General.',
            ]);
        }

        if ($franchise !== null && $franchise->company_id !== $company->getKey()) {
            throw ValidationException::withMessages([
                'franchise_id' => 'That place is not part of this company.',
            ]);
        }
    }

    /** @return array<string, mixed> */
    private function storeRules(Request $request): array
    {
        return [
            'company_id' => ['nullable', 'uuid', $this->existsInTenant('companies')],
            'franchise_id' => ['nullable', 'uuid', $this->existsInTenant('franchises')],
            'sku' => $this->skuRules($request),
            'name' => ['required', 'string', 'max:191'],
            'unit' => ['required', 'string', Rule::in(InventoryUnit::values())],
            'reorder_at' => ['nullable', 'numeric', 'min:0'],
        ];
    }

    /** @return array<string, mixed> */
    private function updateRules(Request $request, InventoryItem $item): array
    {
        return [
            'company_id' => ['sometimes', 'nullable', 'uuid', $this->existsInTenant('companies')],
            'franchise_id' => ['sometimes', 'nullable', 'uuid', $this->existsInTenant('franchises')],
            'sku' => $this->skuRules($request, $item),
            'name' => ['sometimes', 'string', 'max:191'],
            'unit' => ['sometimes', 'string', Rule::in(InventoryUnit::values())],
            'status' => ['sometimes', 'string', Rule::in(InventoryItemStatus::values())],
            'reorder_at' => ['sometimes', 'nullable', 'numeric', 'min:0'],
        ];
    }

    /** @return list<mixed> */
    private function skuRules(Request $request, ?InventoryItem $item = null): array
    {
        $rules = ['nullable', 'string', 'max:64'];

        if (! filled($request->input('sku'))) {
            return $rules;
        }

        $companyId = $request->exists('company_id')
            ? $request->input('company_id')
            : $item?->company_id;
        $franchiseId = $request->exists('franchise_id')
            ? $request->input('franchise_id')
            : $item?->franchise_id;

        $unique = Rule::unique('inventory_items', 'sku')
            ->where('organization_id', $this->tenant->idOrFail())
            ->where('catalogue_key', $this->catalogueKey(
                is_string($companyId) ? $companyId : null,
                is_string($franchiseId) ? $franchiseId : null,
            ));

        if ($item !== null) {
            $unique->ignore($item->getKey());
        }

        $rules[] = $unique;

        return $rules;
    }

    private function catalogueKey(?string $companyId, ?string $franchiseId): string
    {
        return ($companyId ?? 'org').':'.($franchiseId ?? '*');
    }

    /** @return array<string, string> */
    private function storeMessages(): array
    {
        return [
            'sku.unique' => 'This place already has an item with that SKU.',
        ];
    }

    /** @return array<string, mixed> */
    private function present(InventoryItem $item, User $user): array
    {
        $item->loadMissing([
            'company:id,name',
            'franchise:id,name',
        ]);

        $row = [
            'id' => $item->id,
            'number' => $item->number,
            'sku' => $item->sku,
            'name' => $item->name,
            'unit' => $item->unit->value,
            'status' => $item->status->value,
            'reorder_at' => $item->reorder_at,
            'last_cost_minor' => $item->last_cost_minor,
            'company' => $item->company_id === null ? null : [
                'id' => $item->company_id,
                'name' => $item->company?->name,
            ],
            'franchise' => $item->franchise_id === null ? null : [
                'id' => $item->franchise_id,
                'name' => $item->franchise?->name,
            ],
        ];

        if ($user->hasPermission('inventory.financials')) {
            return $row;
        }

        return InventoryFinancials::withoutCosts($row);
    }
}
