<?php

namespace App\Http\Controllers\Api;

use App\Models\Company;
use App\Models\Franchise;
use App\Models\InventoryLocation;
use App\Models\User;
use App\Models\Vehicle;
use App\Support\Fleet\VehicleVisibility;
use App\Support\Inventory\InventoryLocationKind;
use App\Support\Inventory\InventoryLocationStatus;
use App\Support\Inventory\InventoryVisibility;
use App\Support\Scoping\PlaceInView;
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
 * Inventory places — list, create, show, update, destroy (spec §6).
 *
 * Closed is a status, not a delete. Hard delete only when the location holds
 * no stock and no movement still names it. A van names a Fleet vehicle that
 * is visible and not already a location. List always applies InventoryVisibility.
 */
class InventoryLocationsController extends Controller
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

    /** GET /api/v1/inventory/locations */
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', InventoryLocation::class);

        $user = $request->user();
        $scope = ViewScope::forUser($user);

        $query = InventoryVisibility::constrain(InventoryLocation::query(), $user, $scope);

        $statuses = $this->requestedStatuses($request);
        $query->whereIn('status', $statuses);

        if (filled($request->query('search'))) {
            $term = trim((string) $request->query('search'));
            $like = '%'.$term.'%';
            $query->where('name', 'like', $like);
        }

        $locations = $query
            ->orderBy('name')
            ->paginate(perPage: min((int) $request->query('per_page', 50), 200));

        return response()->json([
            'data' => collect($locations->items())->map(
                fn (InventoryLocation $location) => $this->present($location),
            ),
            'meta' => [
                'total' => $locations->total(),
                'per_page' => $locations->perPage(),
                'current_page' => $locations->currentPage(),
                'last_page' => $locations->lastPage(),
            ],
        ]);
    }

    /**
     * Status filter from `status[]` or a comma list. When the client sends
     * none, open places only — closed stays hidden.
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
            return [InventoryLocationStatus::Active->value];
        }

        return $parsed;
    }

    /** POST /api/v1/inventory/locations */
    public function store(Request $request): JsonResponse
    {
        $input = $request->validate($this->storeRules($request), $this->storeMessages());

        $company = isset($input['company_id'])
            ? Company::findOrFail($input['company_id'])
            : null;
        $franchise = isset($input['franchise_id'])
            ? Franchise::findOrFail($input['franchise_id'])
            : null;

        PlaceInView::assert($request->user(), $company, $franchise, 'location');
        $this->assertNameAvailable(
            $input['name'],
            $company?->getKey(),
            $franchise?->getKey(),
        );

        if ($company === null) {
            $this->authorize('createOnOrganization', InventoryLocation::class);
        } else {
            $this->authorize('create', [InventoryLocation::class, $company, $franchise]);
        }

        $vehicle = $this->assertVehicle(
            $request->user(),
            $input['kind'],
            $input['vehicle_id'] ?? null,
            $company,
            $franchise,
        );

        $location = InventoryLocation::create([
            'company_id' => $company?->getKey(),
            'franchise_id' => $franchise?->getKey(),
            'name' => $input['name'],
            'kind' => $input['kind'],
            'status' => InventoryLocationStatus::Active,
            'vehicle_id' => $vehicle?->getKey(),
        ]);

        return response()->json(
            ['data' => $this->present($location->fresh())],
            Response::HTTP_CREATED,
        );
    }

    /** GET /api/v1/inventory/locations/{location} */
    public function show(InventoryLocation $location): JsonResponse
    {
        $this->authorize('view', $location);

        return response()->json([
            'data' => $this->present($location),
        ]);
    }

    /** PUT/PATCH /api/v1/inventory/locations/{location} */
    public function update(Request $request, InventoryLocation $location): JsonResponse
    {
        $this->authorize('update', $location);

        $input = $request->validate($this->updateRules($request, $location), $this->storeMessages());

        if (array_key_exists('status', $input)
            && $input['status'] === InventoryLocationStatus::Closed->value
            && ! $location->isEmpty()) {
            return response()->json([
                'message' => 'This location still has stock. Move or adjust it to zero first.',
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        if (array_key_exists('company_id', $input)
            && $input['company_id'] !== $location->company_id
            && ! $location->isEmpty()) {
            return response()->json([
                'message' => 'Move the stock out first.',
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $companyId = array_key_exists('company_id', $input)
            ? $input['company_id']
            : $location->company_id;
        $franchiseId = array_key_exists('franchise_id', $input)
            ? $input['franchise_id']
            : $location->franchise_id;

        if (array_key_exists('company_id', $input) || array_key_exists('franchise_id', $input)) {
            $company = $companyId === null ? null : Company::findOrFail($companyId);
            $franchise = $franchiseId === null ? null : Franchise::findOrFail($franchiseId);

            PlaceInView::assert($request->user(), $company, $franchise, 'location');
            $this->assertNameAvailable(
                array_key_exists('name', $input) ? $input['name'] : $location->name,
                $company?->getKey(),
                $franchise?->getKey(),
                $location->getKey(),
            );

            if ($companyId === null) {
                $input['franchise_id'] = null;
                $franchiseId = null;
            }
        } elseif (array_key_exists('name', $input)) {
            $this->assertNameAvailable(
                $input['name'],
                $location->company_id,
                $location->franchise_id,
                $location->getKey(),
            );
        }

        $kind = array_key_exists('kind', $input) ? $input['kind'] : $location->kind->value;
        $vehicleId = $kind === InventoryLocationKind::Van->value
            ? (array_key_exists('vehicle_id', $input) ? $input['vehicle_id'] : $location->vehicle_id)
            : null;

        $company = $companyId === null ? null : Company::find($companyId);
        $franchise = $franchiseId === null ? null : Franchise::find($franchiseId);

        $vehicle = $this->assertVehicle(
            $request->user(),
            $kind,
            $vehicleId,
            $company,
            $franchise,
            $location->getKey(),
        );

        $input['vehicle_id'] = $vehicle?->getKey();

        $location->fill($input);
        $location->save();

        return response()->json([
            'data' => $this->present($location->fresh()),
        ]);
    }

    /** DELETE /api/v1/inventory/locations/{location} */
    public function destroy(InventoryLocation $location): Response
    {
        $this->authorize('delete', $location);

        if (! $location->isEmpty()) {
            return response()->json([
                'message' => 'This location still has stock. Move or adjust it to zero first.',
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        if ($location->hasHistory()) {
            return response()->json([
                'message' => 'This location has history, so it cannot be removed.',
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $location->delete();

        return response()->noContent();
    }

    private function assertNameAvailable(
        string $name,
        ?string $companyId,
        ?string $franchiseId,
        ?string $ignoreId = null,
    ): void {
        $taken = InventoryLocation::query()
            ->where('name', $name)
            ->where('catalogue_key', $this->catalogueKey($companyId, $franchiseId))
            ->when($ignoreId !== null, fn (Builder $q) => $q->whereKeyNot($ignoreId))
            ->exists();

        if ($taken) {
            throw ValidationException::withMessages([
                'name' => 'The name has already been taken.',
            ]);
        }
    }

    /**
     * kind=van names a visible vehicle that is not already a location and sits
     * on this place or a more general one. Other kinds may not name a vehicle.
     */
    private function assertVehicle(
        User $user,
        string $kind,
        ?string $vehicleId,
        ?Company $company,
        ?Franchise $franchise,
        ?string $ignoreLocationId = null,
    ): ?Vehicle {
        if ($kind !== InventoryLocationKind::Van->value) {
            if (filled($vehicleId)) {
                throw ValidationException::withMessages([
                    'vehicle_id' => 'The vehicle id field is prohibited unless kind is van.',
                ]);
            }

            return null;
        }

        if (! filled($vehicleId)) {
            throw ValidationException::withMessages([
                'vehicle_id' => 'The vehicle id field is required when kind is van.',
            ]);
        }

        $vehicle = Vehicle::query()->whereKey($vehicleId)->first();

        if ($vehicle === null
            || ! $this->vehicleIsVisible($user, $vehicle)
            || ! $this->vehicleFitsPlace($vehicle, $company?->getKey(), $franchise?->getKey())) {
            throw ValidationException::withMessages([
                'vehicle_id' => 'The selected vehicle id is invalid.',
            ]);
        }

        $taken = InventoryLocation::query()
            ->where('vehicle_id', $vehicle->getKey())
            ->when($ignoreLocationId !== null, fn (Builder $q) => $q->whereKeyNot($ignoreLocationId))
            ->exists();

        if ($taken) {
            throw ValidationException::withMessages([
                'vehicle_id' => 'That vehicle already is a location.',
            ]);
        }

        return $vehicle;
    }

    private function vehicleIsVisible(User $user, Vehicle $vehicle): bool
    {
        return VehicleVisibility::constrain(
            Vehicle::query()->whereKey($vehicle->getKey()),
            $user,
            ViewScope::forUser($user),
        )->exists();
    }

    /**
     * The vehicle sits on this place, that company’s General, or the organization.
     */
    private function vehicleFitsPlace(Vehicle $vehicle, ?string $companyId, ?string $franchiseId): bool
    {
        if ($vehicle->company_id === null) {
            return true;
        }

        if ($companyId === null || $vehicle->company_id !== $companyId) {
            return false;
        }

        if ($vehicle->franchise_id === null) {
            return true;
        }

        return $vehicle->franchise_id === $franchiseId;
    }

    /** @return array<string, mixed> */
    private function storeRules(Request $request): array
    {
        return [
            'company_id' => ['nullable', 'uuid', $this->existsInTenant('companies')],
            'franchise_id' => ['nullable', 'uuid', $this->existsInTenant('franchises')],
            'name' => $this->nameRules($request),
            'kind' => ['required', 'string', Rule::in(InventoryLocationKind::values())],
            'vehicle_id' => $this->vehicleIdRules($request),
        ];
    }

    /** @return array<string, mixed> */
    private function updateRules(Request $request, InventoryLocation $location): array
    {
        return [
            'company_id' => ['sometimes', 'nullable', 'uuid', $this->existsInTenant('companies')],
            'franchise_id' => ['sometimes', 'nullable', 'uuid', $this->existsInTenant('franchises')],
            'name' => $this->nameRules($request, $location),
            'kind' => ['sometimes', 'string', Rule::in(InventoryLocationKind::values())],
            'status' => ['sometimes', 'string', Rule::in(InventoryLocationStatus::values())],
            'vehicle_id' => $this->vehicleIdRules($request, $location),
        ];
    }

    /** @return list<mixed> */
    private function nameRules(Request $request, ?InventoryLocation $location = null): array
    {
        $rules = $location === null
            ? ['required', 'string', 'max:191']
            : ['sometimes', 'string', 'max:191'];

        if (! filled($request->input('name'))) {
            return $rules;
        }

        $companyId = $request->exists('company_id')
            ? $request->input('company_id')
            : $location?->company_id;
        $franchiseId = $request->exists('franchise_id')
            ? $request->input('franchise_id')
            : $location?->franchise_id;

        $unique = Rule::unique('inventory_locations', 'name')
            ->where('organization_id', $this->tenant->idOrFail())
            ->where('catalogue_key', $this->catalogueKey(
                is_string($companyId) ? $companyId : null,
                is_string($franchiseId) ? $franchiseId : null,
            ));

        if ($location !== null) {
            $unique->ignore($location->getKey());
        }

        $rules[] = $unique;

        return $rules;
    }

    /** @return list<mixed> */
    private function vehicleIdRules(Request $request, ?InventoryLocation $location = null): array
    {
        $kind = $request->exists('kind')
            ? $request->input('kind')
            : $location?->kind->value;

        $rules = [
            Rule::requiredIf($kind === InventoryLocationKind::Van->value
                && ($location === null || $request->exists('vehicle_id') || $location->vehicle_id === null)),
            'nullable',
            'uuid',
            $this->existsInTenant('vehicles'),
        ];

        $unique = Rule::unique('inventory_locations', 'vehicle_id')
            ->where('organization_id', $this->tenant->idOrFail());

        if ($location !== null) {
            $unique->ignore($location->getKey());
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
            'vehicle_id.unique' => 'That vehicle already is a location.',
            'vehicle_id.required' => 'The vehicle id field is required when kind is van.',
        ];
    }

    /** @return array<string, mixed> */
    private function present(InventoryLocation $location): array
    {
        $location->loadMissing([
            'company:id,name',
            'franchise:id,name',
            'vehicle:id,label',
        ]);

        return [
            'id' => $location->id,
            'name' => $location->name,
            'kind' => $location->kind->value,
            'status' => $location->status->value,
            'vehicle' => $location->vehicle_id === null ? null : [
                'id' => $location->vehicle_id,
                'label' => $location->vehicle?->label,
            ],
            'company' => $location->company_id === null ? null : [
                'id' => $location->company_id,
                'name' => $location->company?->name,
            ],
            'franchise' => $location->franchise_id === null ? null : [
                'id' => $location->franchise_id,
                'name' => $location->franchise?->name,
            ],
        ];
    }
}
