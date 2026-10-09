<?php

namespace App\Http\Controllers\Api;

use App\Models\Company;
use App\Models\Franchise;
use App\Models\Service;
use App\Models\ServiceGroup;
use App\Models\ServiceLabel;
use App\Models\User;
use App\Support\Automations\EventEmitter;
use App\Support\Inventory\InventoryVisibility;
use App\Support\Numbering\RecordNumberGenerator;
use App\Support\Scoping\PlaceInView;
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
 * Service catalogue — list, create, show, update, destroy (spec).
 *
 * Create is one transaction: row + allocated number. Name is unique inside
 * the owning place. Price is cents. Group and label must be in this tenant
 * and fit the service's place.
 */
class ServicesController extends Controller
{
    /** @var list<string> */
    private const UPDATED_FIELDS = [
        'name', 'description', 'price_minor', 'group_id', 'label_id',
        'company_id', 'franchise_id',
    ];

    public function __construct(
        private readonly RecordNumberGenerator $numbers,
        private readonly TenantContext $tenant,
        private readonly EventEmitter $events,
    ) {}

    private function existsInTenant(string $table): \Illuminate\Validation\Rules\Exists
    {
        return Rule::exists($table, 'id')
            ->where('organization_id', $this->tenant->idOrFail());
    }

    /** GET /api/v1/services */
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Service::class);

        $user = $request->user();
        $scope = ViewScope::forUser($user);

        $query = InventoryVisibility::constrain(Service::query(), $user, $scope)
            ->with(['group:id,name', 'label:id,name', 'company:id,name', 'franchise:id,name']);

        if (filled($request->query('search'))) {
            $term = trim((string) $request->query('search'));
            $like = '%'.$term.'%';
            $query->where(function (Builder $inner) use ($like): void {
                $inner->where('name', 'like', $like)
                    ->orWhere('number', 'like', $like)
                    ->orWhere('description', 'like', $like);
            });
        }

        $services = $query
            ->orderBy('name')
            ->paginate(perPage: min((int) $request->query('per_page', 50), 200));

        return response()->json([
            'data' => collect($services->items())->map(
                fn (Service $service) => $this->present($service),
            ),
            'meta' => $this->pageMeta($services),
        ]);
    }

    /** POST /api/v1/services */
    public function store(Request $request): JsonResponse
    {
        $input = $request->validate($this->rules());

        $company = isset($input['company_id'])
            ? Company::findOrFail($input['company_id'])
            : null;
        $franchise = isset($input['franchise_id'])
            ? Franchise::findOrFail($input['franchise_id'])
            : null;

        PlaceInView::assert($request->user(), $company, $franchise, 'service');
        $this->assertNameAvailable(
            $input['name'],
            $company?->getKey(),
            $franchise?->getKey(),
        );

        if ($company === null) {
            $this->authorize('createOnOrganization', Service::class);
        } else {
            $this->authorize('create', [Service::class, $company, $franchise]);
        }

        $group = $this->taxonomyInPlace(
            isset($input['group_id']) ? ServiceGroup::query()->findOrFail($input['group_id']) : null,
            $company?->getKey(),
            $franchise?->getKey(),
            'group_id',
        );
        $label = $this->taxonomyInPlace(
            isset($input['label_id']) ? ServiceLabel::query()->findOrFail($input['label_id']) : null,
            $company?->getKey(),
            $franchise?->getKey(),
            'label_id',
        );

        $service = DB::transaction(function () use ($request, $input, $company, $franchise, $group, $label) {
            $number = $this->numbers->next(
                RecordNumberGenerator::SERVICES,
                $franchise ?? $company ?? $request->user()->organization,
            );

            $service = Service::create([
                'company_id' => $company?->getKey(),
                'franchise_id' => $franchise?->getKey(),
                'number' => $number,
                'name' => $input['name'],
                'description' => $input['description'] ?? null,
                'price_minor' => $input['price_minor'] ?? null,
                'group_id' => $group?->getKey(),
                'label_id' => $label?->getKey(),
            ]);

            $this->events->emit('service.created', $service, [
                'after' => $this->scalars($service),
            ]);

            return $service;
        });

        return response()->json(
            ['data' => $this->present($service->fresh(['group', 'label', 'company', 'franchise']))],
            Response::HTTP_CREATED,
        );
    }

    /** GET /api/v1/services/{service} */
    public function show(Request $request, Service $service): JsonResponse
    {
        $this->authorize('view', $service);

        return response()->json([
            'data' => $this->present(
                $service->fresh(['group', 'label', 'company', 'franchise', 'attachments']),
            ),
        ]);
    }

    /** PATCH /api/v1/services/{service} */
    public function update(Request $request, Service $service): JsonResponse
    {
        $this->authorize('update', $service);

        $input = $request->validate($this->rules(updating: true));

        $before = $this->scalars($service);

        $companyId = array_key_exists('company_id', $input)
            ? $input['company_id']
            : $service->company_id;
        $franchiseId = array_key_exists('franchise_id', $input)
            ? $input['franchise_id']
            : $service->franchise_id;

        $company = $companyId === null ? null : Company::findOrFail($companyId);
        $franchise = $franchiseId === null ? null : Franchise::findOrFail($franchiseId);

        if (array_key_exists('company_id', $input) || array_key_exists('franchise_id', $input)) {
            PlaceInView::assert($request->user(), $company, $franchise, 'service');
        }

        if (isset($input['name']) || array_key_exists('company_id', $input) || array_key_exists('franchise_id', $input)) {
            $this->assertNameAvailable(
                $input['name'] ?? $service->name,
                $company?->getKey(),
                $franchise?->getKey(),
                $service->getKey(),
            );
        }

        $group = array_key_exists('group_id', $input)
            ? $this->taxonomyInPlace(
                $input['group_id'] === null ? null : ServiceGroup::query()->findOrFail($input['group_id']),
                $company?->getKey(),
                $franchise?->getKey(),
                'group_id',
            )
            : $service->group;
        $label = array_key_exists('label_id', $input)
            ? $this->taxonomyInPlace(
                $input['label_id'] === null ? null : ServiceLabel::query()->findOrFail($input['label_id']),
                $company?->getKey(),
                $franchise?->getKey(),
                'label_id',
            )
            : $service->label;

        $service->fill([
            ...collect($input)->only(['name', 'description', 'price_minor'])->all(),
            'company_id' => $company?->getKey(),
            'franchise_id' => $franchise?->getKey(),
            'group_id' => array_key_exists('group_id', $input) ? $group?->getKey() : $service->group_id,
            'label_id' => array_key_exists('label_id', $input) ? $label?->getKey() : $service->label_id,
        ]);
        DB::transaction(function () use ($service, $before) {
            $service->save();

            $this->events->emit('service.updated', $service, [
                'before' => $before,
                'after' => $this->scalars($service),
            ]);
        });

        return response()->json([
            'data' => $this->present($service->fresh(['group', 'label', 'company', 'franchise'])),
        ]);
    }

    /** DELETE /api/v1/services/{service} */
    public function destroy(Request $request, Service $service): JsonResponse
    {
        $this->authorize('delete', $service);

        $before = $this->scalars($service);

        // The outbox row and the delete commit together or not at all. Emitting
        // first and deleting after — which this did — publishes `service.deleted`
        // for a service that is still in the catalogue when the delete fails, and
        // `ezbizi:dispatch-automation-events` drains the outbox every minute, so
        // a tenant's "when a service is deleted" rule runs against a live record
        // inside the minute. Do not lift either statement back out of here.
        DB::transaction(function () use ($service, $before) {
            $this->events->emit('service.deleted', $service, [
                'before' => $before,
            ]);
            $service->delete();
        });

        return response()->json([], Response::HTTP_NO_CONTENT);
    }

    /** @return array<string, mixed> */
    private function rules(bool $updating = false): array
    {
        $required = $updating ? 'sometimes' : 'required';

        return [
            'name' => [$required, 'string', 'max:191'],
            'description' => ['nullable', 'string'],
            'price_minor' => ['nullable', 'integer', 'min:0'],
            'group_id' => ['nullable', 'uuid', $this->existsInTenant('service_groups')],
            'label_id' => ['nullable', 'uuid', $this->existsInTenant('service_labels')],
            'company_id' => ['nullable', 'uuid', $this->existsInTenant('companies')],
            'franchise_id' => ['nullable', 'uuid', $this->existsInTenant('franchises')],
        ];
    }

    private function assertNameAvailable(
        string $name,
        ?string $companyId,
        ?string $franchiseId,
        ?string $ignoreId = null,
    ): void {
        $taken = Service::query()
            ->where('name', $name)
            ->where('catalogue_key', $this->catalogueKey($companyId, $franchiseId))
            ->when($ignoreId !== null, fn (Builder $q) => $q->whereKeyNot($ignoreId))
            ->exists();

        if ($taken) {
            throw ValidationException::withMessages([
                'name' => 'This place already has a service with that name.',
            ]);
        }
    }

    /**
     * A group/label at org covers everyone; at company covers that company
     * and its franchises; at a franchise only that franchise.
     */
    private function taxonomyInPlace(
        ServiceGroup|ServiceLabel|null $row,
        ?string $companyId,
        ?string $franchiseId,
        string $field,
    ): ServiceGroup|ServiceLabel|null {
        if ($row === null) {
            return null;
        }

        if ($row->company_id === null) {
            return $row;
        }

        if ($companyId === null || $row->company_id !== $companyId) {
            throw ValidationException::withMessages([
                $field => 'That '.$this->taxonomyNoun($row).' is not in this place.',
            ]);
        }

        if ($row->franchise_id === null) {
            return $row;
        }

        if ($franchiseId === null || $row->franchise_id !== $franchiseId) {
            throw ValidationException::withMessages([
                $field => 'That '.$this->taxonomyNoun($row).' is not in this place.',
            ]);
        }

        return $row;
    }

    private function taxonomyNoun(ServiceGroup|ServiceLabel $row): string
    {
        return $row instanceof ServiceGroup ? 'group' : 'label';
    }

    private function catalogueKey(?string $companyId, ?string $franchiseId): string
    {
        return ($companyId ?? 'org').':'.($franchiseId ?? '*');
    }

    /** @return array<string, mixed> */
    private function scalars(Service $service): array
    {
        return $service->only(self::UPDATED_FIELDS);
    }

    /** @return array<string, mixed> */
    private function present(Service $service): array
    {
        $service->loadMissing([
            'group:id,name',
            'label:id,name',
            'company:id,name',
            'franchise:id,name',
            'attachments',
        ]);

        $photo = $service->attachments->first();

        return [
            'id' => $service->id,
            'number' => $service->number,
            'name' => $service->name,
            'description' => $service->description,
            'price_minor' => $service->price_minor,
            'group' => $service->group_id === null ? null : [
                'id' => $service->group_id,
                'name' => $service->group?->name,
            ],
            'label' => $service->label_id === null ? null : [
                'id' => $service->label_id,
                'name' => $service->label?->name,
            ],
            'company' => $service->company_id === null ? null : [
                'id' => $service->company_id,
                'name' => $service->company?->name,
            ],
            'franchise' => $service->franchise_id === null ? null : [
                'id' => $service->franchise_id,
                'name' => $service->franchise?->name,
            ],
            'photo' => $photo === null ? null : [
                'id' => $photo->id,
                'mime' => $photo->mime,
            ],
        ];
    }
}
