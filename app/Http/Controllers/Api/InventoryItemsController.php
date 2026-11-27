<?php

namespace App\Http\Controllers\Api;

use App\Models\Company;
use App\Models\Franchise;
use App\Models\InventoryBalance;
use App\Models\InventoryItem;
use App\Models\User;
use App\Support\Automations\EventEmitter;
use App\Support\Inventory\InventoryFinancials;
use App\Support\Inventory\InventoryItemStatus;
use App\Support\Inventory\InventoryStockItems;
use App\Support\Inventory\InventoryUnit;
use App\Support\Inventory\InventoryVisibility;
use App\Support\Numbering\RecordNumberGenerator;
use App\Support\Scoping\PlaceInView;
use App\Support\Scoping\ViewScope;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Exists;
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
    /** @var list<string> */
    private const UPDATED_FIELDS = [
        'sku', 'name', 'unit', 'status', 'reorder_at', 'company_id', 'franchise_id',
    ];

    public function __construct(
        private readonly RecordNumberGenerator $numbers,
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
    private function existsInTenant(string $table): Exists
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
        $mayMeasure = $user->hasPermission('inventory.stock.view');
        $stock = $mayMeasure ? $this->stockStatuses($user, $scope) : null;

        $query->whereIn('status', $this->requestedStatuses($request));

        $stockFilter = $request->validate(['stock' => ['nullable', Rule::in(self::STOCK_FILTERS)]])['stock'] ?? null;
        if ($stockFilter !== null) {
            // The filtered count is the very stock fact the null tile withholds.
            abort_unless($mayMeasure, Response::HTTP_FORBIDDEN, 'Stock levels are hidden for your role.');
            $query->whereIn('id', array_keys($stock, $stockFilter, true));
        }

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
            ->paginate(perPage: max(1, min((int) $request->query('per_page', 50), 200)));

        $onHand = $this->onHandFor(collect($items->items()), $user, $scope);

        return response()->json([
            'data' => collect($items->items())->map(
                fn (InventoryItem $item) => [
                    ...$this->present($item, $user),
                    // Null without the Stock tab: a quantity this role was not
                    // shown, never a zero it would read as "holds nothing".
                    'on_hand' => $onHand === null ? null : ($onHand[$item->id] ?? '0.000'),
                ],
            ),
            'meta' => [
                ...$this->pageMeta($items),
                'tiles' => $this->tiles($user, $scope, $stock),
            ],
        ]);
    }

    private const STOCK_FILTERS = ['below_reorder', 'out_of_stock'];

    /**
     * The tile row: the whole visible catalogue, before search or filter, so a
     * tapped tile lands on a list as long as the number it showed. The two
     * stock tiles are null for a role without the Stock tab, never zero.
     *
     * @param  array<string, string>|null  $stock
     * @return array<string, int|null>
     */
    private function tiles(User $user, ViewScope $scope, ?array $stock): array
    {
        $counts = InventoryVisibility::constrain(InventoryItem::query(), $user, $scope)
            ->toBase()
            ->groupBy('status')
            ->selectRaw('status, COUNT(*) as n')
            ->pluck('n', 'status');

        $measured = $stock === null ? null : array_count_values($stock);

        return [
            'items' => (int) ($counts[InventoryItemStatus::Active->value] ?? 0),
            'below_reorder' => $measured === null ? null : ($measured['below_reorder'] ?? 0),
            'out_of_stock' => $measured === null ? null : ($measured['out_of_stock'] ?? 0),
            'discontinued' => (int) ($counts[InventoryItemStatus::Discontinued->value] ?? 0),
        ];
    }

    /**
     * Stock status of every visible ACTIVE item, by its total across the places
     * this caller can see — the Stock tab's rule (InventoryStockItems::status).
     * A discontinued item is not one to reorder, so it is never low or out.
     *
     * ponytail: classifies the whole active catalogue in PHP per request, like
     * InventoryStockItems; move to SQL if a catalogue outgrows it.
     *
     * @return array<string, string> item id => in_stock|below_reorder|out_of_stock
     */
    private function stockStatuses(User $user, ViewScope $scope): array
    {
        $items = InventoryVisibility::constrain(InventoryItem::query(), $user, $scope)
            ->where('status', InventoryItemStatus::Active)
            ->get(['id', 'reorder_at']);

        $totals = $this->onHandFor($items, $user, $scope) ?? [];

        return $items->mapWithKeys(fn (InventoryItem $item) => [
            $item->id => InventoryStockItems::status($totals[$item->id] ?? '0.000', $item->reorder_at),
        ])->all();
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

        PlaceInView::assert($request->user(), $company, $franchise, 'item');
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

            $item = InventoryItem::create([
                'company_id' => $company?->getKey(),
                'franchise_id' => $franchise?->getKey(),
                'number' => $number,
                'sku' => $input['sku'] ?? null,
                'name' => $input['name'],
                'unit' => $input['unit'],
                'status' => InventoryItemStatus::Active,
                'reorder_at' => $input['reorder_at'] ?? null,
            ]);

            $this->events->emit('item.created', $item, [
                'after' => $this->scalars($item->only([
                    'sku', 'name', 'unit', 'status', 'reorder_at',
                    'company_id', 'franchise_id',
                ])),
            ]);

            return $item;
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

            PlaceInView::assert($request->user(), $company, $franchise, 'item');
            $this->assertSkuAvailable(
                array_key_exists('sku', $input) ? ($input['sku'] ?? null) : $item->sku,
                $company?->getKey(),
                $franchise?->getKey(),
                $item->getKey(),
            );

            if ($companyId === null) {
                $input['franchise_id'] = null;
                $franchiseId = null;
                $franchise = null;
            }

            $placeChanged = $companyId !== $item->company_id
                || $franchiseId !== $item->franchise_id;

            if ($placeChanged) {
                if ($item->balances()->where('qty_on_hand', '!=', 0)->exists()) {
                    return response()->json([
                        'message' => 'Move the stock out first.',
                    ], Response::HTTP_UNPROCESSABLE_ENTITY);
                }

                if ($company === null) {
                    $this->authorize('createOnOrganization', InventoryItem::class);
                } else {
                    $this->authorize('create', [InventoryItem::class, $company, $franchise]);
                }
            }
        } elseif (array_key_exists('sku', $input)) {
            $this->assertSkuAvailable(
                $input['sku'] ?? null,
                $item->company_id,
                $item->franchise_id,
                $item->getKey(),
            );
        }

        DB::transaction(function () use ($item, $input) {
            $item->fill($input);

            $changed = $item->getDirty();
            $before = array_intersect_key($item->getOriginal(), $changed);

            $item->save();

            $updated = array_intersect_key($changed, array_flip(self::UPDATED_FIELDS));
            if ($updated === []) {
                return;
            }

            $this->events->emit('item.updated', $item, [
                'before' => $this->scalars(array_intersect_key($before, $updated)),
                'after' => $this->scalars($updated),
            ]);
        });

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

    /** @return array<string, mixed> */
    private function storeRules(Request $request): array
    {
        return [
            'company_id' => ['nullable', 'uuid', $this->existsInTenant('companies')],
            'franchise_id' => ['nullable', 'uuid', $this->existsInTenant('franchises')],
            'sku' => $this->skuRules($request),
            'name' => ['required', 'string', 'max:191'],
            'unit' => ['required', 'string', Rule::enum(InventoryUnit::class)],
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
            'unit' => ['sometimes', 'string', Rule::enum(InventoryUnit::class)],
            'status' => ['sometimes', 'string', Rule::enum(InventoryItemStatus::class)],
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
    /**
     * On hand per item on this page, summed over the places the caller can see —
     * the same rule as `low_items` and the grouped Stock read, so the three
     * cannot disagree about one item. One query for the page, not one per row.
     *
     * Null when the caller lacks `inventory.stock.view`: this endpoint is
     * ungated reference data, so the quantity has to carry its own gate.
     *
     * @param  Collection<int, InventoryItem>  $items
     * @return array<string, string>|null
     */
    private function onHandFor(Collection $items, User $user, ViewScope $scope): ?array
    {
        if (! $user->hasPermission('inventory.stock.view')) {
            return null;
        }

        if ($items->isEmpty()) {
            return [];
        }

        return InventoryBalance::query()
            ->whereIn('item_id', $items->pluck('id')->all())
            ->whereHas('location', fn (Builder $q) => InventoryVisibility::constrain($q, $user, $scope))
            ->groupBy('item_id')
            ->selectRaw('item_id, SUM(qty_on_hand) as on_hand')
            ->pluck('on_hand', 'item_id')
            ->map(fn ($qty) => bcadd((string) $qty, '0', 3))
            ->all();
    }

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

    /** @param  array<string, mixed>  $attributes */
    private function scalars(array $attributes): array
    {
        return array_map(fn (mixed $value) => $this->scalar($value), $attributes);
    }

    private function scalar(mixed $value): mixed
    {
        if ($value instanceof \BackedEnum) {
            return $value->value;
        }

        return $value;
    }
}
