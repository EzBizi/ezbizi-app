<?php

namespace App\Http\Controllers\Api;

use App\Models\Company;
use App\Models\Franchise;
use App\Models\ServiceGroup;
use App\Models\ServiceLabel;
use App\Support\Inventory\InventoryVisibility;
use App\Support\Scoping\PlaceInView;
use App\Support\Scoping\ViewScope;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Exists;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Groups or labels on the service catalogue (spec).
 *
 * Create is a name at a place the caller can write. Delete is refused while
 * a service still names the row.
 */
abstract class ServiceTaxonomyController extends Controller
{
    public function __construct(
        private readonly TenantContext $tenant,
    ) {}

    /** @return class-string<ServiceGroup|ServiceLabel> */
    abstract protected function modelClass(): string;

    abstract protected function noun(): string;

    /** GET */
    public function index(Request $request): JsonResponse
    {
        $class = $this->modelClass();
        $this->authorize('viewAny', $class);

        $user = $request->user();
        $scope = ViewScope::forUser($user);

        $rows = InventoryVisibility::constrain($class::query(), $user, $scope)
            ->orderBy('name')
            ->get();

        return response()->json([
            'data' => $rows->map(fn (Model $row) => $this->present($row))->values()->all(),
        ]);
    }

    /** POST */
    public function store(Request $request): JsonResponse
    {
        $input = $request->validate([
            'name' => ['required', 'string', 'max:191'],
            'company_id' => ['nullable', 'uuid', $this->existsInTenant('companies')],
            'franchise_id' => ['nullable', 'uuid', $this->existsInTenant('franchises')],
        ]);

        $company = isset($input['company_id'])
            ? Company::findOrFail($input['company_id'])
            : null;
        $franchise = isset($input['franchise_id'])
            ? Franchise::findOrFail($input['franchise_id'])
            : null;

        PlaceInView::assert($request->user(), $company, $franchise, $this->noun());

        $class = $this->modelClass();
        if ($company === null) {
            $this->authorize('createOnOrganization', $class);
        } else {
            $this->authorize('create', [$class, $company, $franchise]);
        }

        $this->assertNameAvailable(
            $input['name'],
            $company?->getKey(),
            $franchise?->getKey(),
        );

        $row = $class::create([
            'company_id' => $company?->getKey(),
            'franchise_id' => $franchise?->getKey(),
            'name' => $input['name'],
        ]);

        return response()->json(
            ['data' => $this->present($row->fresh(['company:id,name', 'franchise:id,name']))],
            Response::HTTP_CREATED,
        );
    }

    /** DELETE */
    public function destroy(Request $request, string $id): JsonResponse
    {
        $class = $this->modelClass();
        $row = $class::query()->findOrFail($id);
        $this->authorize('delete', $row);

        if ($row->services()->exists()) {
            throw ValidationException::withMessages([
                'id' => 'This '.$this->noun().' still has services. Move them first.',
            ]);
        }

        $row->delete();

        return response()->json([], Response::HTTP_NO_CONTENT);
    }

    private function existsInTenant(string $table): Exists
    {
        return Rule::exists($table, 'id')
            ->where('organization_id', $this->tenant->idOrFail());
    }

    private function assertNameAvailable(string $name, ?string $companyId, ?string $franchiseId): void
    {
        $class = $this->modelClass();
        $taken = $class::query()
            ->where('name', $name)
            ->where('catalogue_key', $this->catalogueKey($companyId, $franchiseId))
            ->exists();

        if ($taken) {
            throw ValidationException::withMessages([
                'name' => 'This place already has a '.$this->noun().' with that name.',
            ]);
        }
    }

    private function catalogueKey(?string $companyId, ?string $franchiseId): string
    {
        return ($companyId ?? 'org').':'.($franchiseId ?? '*');
    }

    /** @return array<string, mixed> */
    private function present(Model $row): array
    {
        $row->loadMissing(['company:id,name', 'franchise:id,name']);

        return [
            'id' => $row->id,
            'name' => $row->name,
            'company' => $row->company_id === null ? null : [
                'id' => $row->company_id,
                'name' => $row->company?->name,
            ],
            'franchise' => $row->franchise_id === null ? null : [
                'id' => $row->franchise_id,
                'name' => $row->franchise?->name,
            ],
        ];
    }
}
