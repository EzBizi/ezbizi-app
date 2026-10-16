<?php

namespace App\Http\Controllers\Api;

use App\Models\Company;
use App\Models\Franchise;
use App\Models\InventoryVendor;
use App\Support\Inventory\InventoryVisibility;
use App\Support\Scoping\PlaceInView;
use App\Support\Scoping\ViewScope;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Exists;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Who we buy from — list, create, show, update, destroy (spec §8.3).
 *
 * Place-write matches items. Hard delete only when no purchase order has
 * named the vendor. List always applies InventoryVisibility.
 */
class InventoryVendorsController extends Controller
{
    public function __construct(
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

    /** GET /api/v1/inventory/vendors */
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', InventoryVendor::class);

        $user = $request->user();
        $scope = ViewScope::forUser($user);

        $query = InventoryVisibility::constrain(InventoryVendor::query(), $user, $scope);

        if (filled($request->query('search'))) {
            $term = trim((string) $request->query('search'));
            $query->where('name', 'like', '%'.$term.'%');
        }

        $vendors = $query
            ->orderBy('name')
            ->paginate(perPage: min((int) $request->query('per_page', 50), 200));

        return response()->json([
            'data' => collect($vendors->items())->map(
                fn (InventoryVendor $vendor) => $this->present($vendor),
            ),
            'meta' => $this->pageMeta($vendors),
        ]);
    }

    /** POST /api/v1/inventory/vendors */
    public function store(Request $request): JsonResponse
    {
        $input = $request->validate($this->storeRules());

        $company = isset($input['company_id'])
            ? Company::findOrFail($input['company_id'])
            : null;
        $franchise = isset($input['franchise_id'])
            ? Franchise::findOrFail($input['franchise_id'])
            : null;

        PlaceInView::assert($request->user(), $company, $franchise, 'vendor');

        if ($company === null) {
            $this->authorize('createOnOrganization', InventoryVendor::class);
        } else {
            $this->authorize('create', [InventoryVendor::class, $company, $franchise]);
        }

        $vendor = InventoryVendor::create([
            'company_id' => $company?->getKey(),
            'franchise_id' => $franchise?->getKey(),
            'name' => $input['name'],
            'phone' => $input['phone'] ?? null,
            'email' => $input['email'] ?? null,
        ]);

        return response()->json(
            ['data' => $this->present($vendor->fresh())],
            Response::HTTP_CREATED,
        );
    }

    /** GET /api/v1/inventory/vendors/{vendor} */
    public function show(InventoryVendor $vendor): JsonResponse
    {
        $this->authorize('view', $vendor);

        return response()->json([
            'data' => $this->present($vendor),
        ]);
    }

    /** PUT/PATCH /api/v1/inventory/vendors/{vendor} */
    public function update(Request $request, InventoryVendor $vendor): JsonResponse
    {
        $this->authorize('update', $vendor);

        $input = $request->validate($this->updateRules());

        if (array_key_exists('company_id', $input) || array_key_exists('franchise_id', $input)) {
            $companyId = array_key_exists('company_id', $input)
                ? $input['company_id']
                : $vendor->company_id;
            $franchiseId = array_key_exists('franchise_id', $input)
                ? $input['franchise_id']
                : $vendor->franchise_id;

            $company = $companyId === null ? null : Company::findOrFail($companyId);
            $franchise = $franchiseId === null ? null : Franchise::findOrFail($franchiseId);

            PlaceInView::assert($request->user(), $company, $franchise, 'vendor');

            if ($companyId === null) {
                $input['franchise_id'] = null;
            }
        }

        $vendor->fill($input);
        $vendor->save();

        return response()->json([
            'data' => $this->present($vendor->fresh()),
        ]);
    }

    /** DELETE /api/v1/inventory/vendors/{vendor} */
    public function destroy(InventoryVendor $vendor): Response
    {
        $this->authorize('delete', $vendor);

        if ($vendor->purchaseOrders()->exists()) {
            return response()->json([
                'message' => 'This vendor has purchase orders, so it cannot be removed.',
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $vendor->delete();

        return response()->noContent();
    }

    /** @return array<string, mixed> */
    private function storeRules(): array
    {
        return [
            'company_id' => ['nullable', 'uuid', $this->existsInTenant('companies')],
            'franchise_id' => ['nullable', 'uuid', $this->existsInTenant('franchises')],
            'name' => ['required', 'string', 'max:191'],
            'phone' => ['nullable', 'string', 'max:40'],
            'email' => ['nullable', 'email', 'max:191'],
        ];
    }

    /** @return array<string, mixed> */
    private function updateRules(): array
    {
        return [
            'company_id' => ['sometimes', 'nullable', 'uuid', $this->existsInTenant('companies')],
            'franchise_id' => ['sometimes', 'nullable', 'uuid', $this->existsInTenant('franchises')],
            'name' => ['sometimes', 'string', 'max:191'],
            'phone' => ['sometimes', 'nullable', 'string', 'max:40'],
            'email' => ['sometimes', 'nullable', 'email', 'max:191'],
        ];
    }

    /** @return array<string, mixed> */
    private function present(InventoryVendor $vendor): array
    {
        $vendor->loadMissing([
            'company:id,name',
            'franchise:id,name',
        ]);

        return [
            'id' => $vendor->id,
            'name' => $vendor->name,
            'phone' => $vendor->phone,
            'email' => $vendor->email,
            'company' => $vendor->company_id === null ? null : [
                'id' => $vendor->company_id,
                'name' => $vendor->company?->name,
            ],
            'franchise' => $vendor->franchise_id === null ? null : [
                'id' => $vendor->franchise_id,
                'name' => $vendor->franchise?->name,
            ],
        ];
    }
}
