<?php

namespace App\Http\Controllers\Api;

use App\Models\Company;
use App\Models\Franchise;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleAssignment;
use App\Models\VehicleInsurancePolicy;
use App\Models\VehicleOwnershipPeriod;
use App\Models\VehiclePayment;
use App\Models\VehicleServiceVisit;
use App\Support\Automations\EventEmitter;
use App\Support\Fleet\FleetSummary;
use App\Support\Fleet\VehicleBodyType;
use App\Support\Fleet\VehicleFinancials;
use App\Support\Fleet\VehicleFuelType;
use App\Support\Fleet\VehicleOwnershipKind;
use App\Support\Fleet\VehiclePaymentMethod;
use App\Support\Fleet\VehicleRatePeriod;
use App\Support\Fleet\VehicleRentalTerms;
use App\Support\Fleet\VehicleStatus;
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
 * Fleet register — list, create, show, update, destroy (spec §5, §8, §11).
 *
 * Create is one transaction: vehicle + first ownership period. Sold/returned
 * close the open ownership on the same save; buy-back needs status + ownership
 * together. Money is stripped on every read unless the caller holds
 * fleet_management.financials. List always applies InventoryVisibility.
 */
class VehiclesController extends Controller
{
    /** @var list<string> */
    private const UPDATED_FIELDS = [
        'year', 'make', 'model', 'trim', 'plate', 'plate_state', 'vin', 'colour',
        'body_type', 'fuel_type', 'company_id', 'franchise_id',
        'odometer_miles', 'odometer_updated_at', 'next_service_on',
        'next_service_miles', 'registration_expires_on', 'inspection_expires_on',
        'toll_device_number', 'toll_plan', 'toll_flat_rate_cents', 'notes',
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
    private function existsInTenant(string $table): \Illuminate\Validation\Rules\Exists
    {
        return Rule::exists($table, 'id')
            ->where('organization_id', $this->tenant->idOrFail());
    }

    /** GET /api/v1/vehicles */
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Vehicle::class);

        $user = $request->user();
        $scope = ViewScope::forUser($user);

        $query = InventoryVisibility::constrain(Vehicle::query(), $user, $scope);

        $statuses = $this->requestedStatuses($request);
        $query->whereIn('status', $statuses);

        // The states (franchises) the register spans, so the screen can open
        // one state at a time — Eve, 2026-10-12: "I click Connecticut and I
        // see all of Connecticut". Which states: the whole register, so a chip
        // never vanishes under a filter and strands the screen on it.
        $register = clone $query;

        if (filled($request->query('search'))) {
            $term = trim((string) $request->query('search'));
            $like = '%'.$term.'%';
            $query->where(function (Builder $inner) use ($like): void {
                $inner->where('label', 'like', $like)
                    ->orWhere('plate', 'like', $like)
                    ->orWhere('vin', 'like', $like)
                    ->orWhere('number', 'like', $like)
                    ->orWhereHas('assignments', function (Builder $a) use ($like): void {
                        $a->whereNull('released_at')
                            ->whereHas('user', fn (Builder $u) => $u->where('name', 'like', $like));
                    });
            });
        }

        if (filled($request->query('ownership'))) {
            $kind = (string) $request->query('ownership');
            $query->whereHas('ownershipPeriods', function (Builder $p) use ($kind): void {
                $p->whereNull('ended_on')->where('kind', $kind);
            });
        }

        if ($request->boolean('unassigned')) {
            $query->whereDoesntHave('assignments', fn (Builder $a) => $a->whereNull('released_at'));
        }

        // No open policy: what the General tab's "Add insurance" opens.
        if ($request->boolean('uninsured')) {
            $query->whereDoesntHave('insurancePolicies', fn (Builder $p) => $p->whereNull('ended_on'));
        }

        // The open policy has expired or expires within the due-soon window: "Renew insurance".
        if ($request->boolean('insurance_expiring')) {
            $by = now()->addDays(FleetSummary::soonDays($user))->toDateString();
            $query->whereHas('insurancePolicies', fn (Builder $p) => $p->whereNull('ended_on')
                ->whereNotNull('expires_on')->whereDate('expires_on', '<=', $by));
        }

        if ($request->boolean('due_soon')) {
            $within = now()->addDays(FleetSummary::soonDays($user))->toDateString();
            $margin = FleetSummary::soonMiles($user);
            $query->where(function (Builder $inner) use ($within, $margin): void {
                $inner->where(function (Builder $date) use ($within): void {
                    $date->whereNotNull('next_service_on')
                        ->whereDate('next_service_on', '<=', $within);
                })->orWhere(function (Builder $miles) use ($margin): void {
                    $miles->whereNotNull('next_service_miles')
                        ->whereNotNull('odometer_miles')
                        ->whereRaw('odometer_miles >= next_service_miles - ?', [$margin]);
                });
            });
        }

        // How many: under every filter and the search but not the state, so
        // each number is what tapping that chip shows (Jose, 2026-11-03).
        $places = $this->places($register, clone $query);

        $place = $request->query('franchise_id');
        if (is_string($place) && $place !== '') {
            $place === self::GENERAL_PLACE
                ? $query->whereNull('vehicles.franchise_id')
                : $query->where('vehicles.franchise_id', $place);
        }

        $vehicles = $query
            ->orderBy('label')
            ->paginate(perPage: min((int) $request->query('per_page', 50), 200));

        return response()->json([
            'data' => collect($vehicles->items())->map(
                fn (Vehicle $v) => $this->present($v, $user),
            ),
            'meta' => [...$this->pageMeta($vehicles), 'places' => $places],
        ]);
    }

    /** `franchise_id` value for vehicles tagged to no franchise. */
    private const GENERAL_PLACE = 'general';

    /**
     * One entry per franchise holding a vehicle in the register, by name, with
     * the untagged ones last as General; each counts the filtered query, so a
     * state can read 0. A state with no vehicles at all is not listed.
     *
     * @return list<array{id: string, name: string, count: int}>
     */
    private function places(Builder $register, Builder $filtered): array
    {
        $counts = (clone $filtered)->reorder()
            ->selectRaw('vehicles.franchise_id as place, COUNT(*) as total')
            ->groupBy('vehicles.franchise_id')
            ->pluck('total', 'place');

        $rows = $register->reorder()
            ->selectRaw('vehicles.franchise_id as place, COUNT(*) as total')
            ->groupBy('vehicles.franchise_id')
            ->get();

        $names = Franchise::query()
            ->whereIn('id', $rows->pluck('place')->filter()->all())
            ->pluck('name', 'id');

        $places = [];
        $general = 0;
        $hasGeneral = false;

        foreach ($rows as $row) {
            if ($row->place === null) {
                $general = (int) ($counts[''] ?? 0);
                $hasGeneral = true;

                continue;
            }

            $places[] = [
                'id' => (string) $row->place,
                'name' => (string) ($names[$row->place] ?? 'Unnamed'),
                'count' => (int) ($counts[$row->place] ?? 0),
            ];
        }

        usort($places, fn (array $a, array $b) => strcasecmp($a['name'], $b['name']));

        if ($hasGeneral) {
            $places[] = ['id' => self::GENERAL_PLACE, 'name' => 'General', 'count' => $general];
        }

        return $places;
    }

    /**
     * Status filter from `status[]` or a comma list. When the client sends
     * none, the active fleet only — sold/returned stay hidden.
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
            return [
                VehicleStatus::Active->value,
                VehicleStatus::InShop->value,
                VehicleStatus::OutOfService->value,
            ];
        }

        return $parsed;
    }

    /** POST /api/v1/vehicles */
    public function store(Request $request): JsonResponse
    {
        $input = $request->validate($this->storeRules(), $this->storeMessages());

        $company = isset($input['company_id'])
            ? Company::findOrFail($input['company_id'])
            : null;
        $franchise = isset($input['franchise_id'])
            ? Franchise::findOrFail($input['franchise_id'])
            : null;

        PlaceInView::assert($request->user(), $company, $franchise, 'vehicle');

        if ($company === null) {
            $this->authorize('createOnOrganization', Vehicle::class);
        } else {
            $this->authorize('create', [Vehicle::class, $company, $franchise]);
        }

        if (! $request->user()->hasPermission('fleet_management.financials')) {
            foreach (VehicleFinancials::keys() as $key) {
                unset($input['ownership'][$key]);
            }
        }

        $vehicle = DB::transaction(function () use ($request, $input, $company, $franchise) {
            $number = $this->numbers->next(
                RecordNumberGenerator::VEHICLES,
                $franchise ?? $company ?? $request->user()->organization,
            );

            $vehicle = Vehicle::create([
                'company_id' => $company?->getKey(),
                'franchise_id' => $franchise?->getKey(),
                'number' => $number,
                'year' => $input['year'] ?? null,
                'make' => $input['make'] ?? null,
                'model' => $input['model'] ?? null,
                'trim' => $input['trim'] ?? null,
                'plate' => $input['plate'] ?? null,
                'plate_state' => $input['plate_state'] ?? null,
                'vin' => $input['vin'] ?? null,
                'colour' => $input['colour'] ?? null,
                'body_type' => $input['body_type'],
                'fuel_type' => $input['fuel_type'],
                'odometer_miles' => $input['odometer_miles'] ?? null,
                'odometer_updated_at' => $input['odometer_updated_at'] ?? null,
                'next_service_on' => $input['next_service_on'] ?? null,
                'next_service_miles' => $input['next_service_miles'] ?? null,
                'registration_expires_on' => $input['registration_expires_on'] ?? null,
                'inspection_expires_on' => $input['inspection_expires_on'] ?? null,
                'toll_device_number' => $input['toll_device_number'] ?? null,
                'toll_plan' => $input['toll_plan'] ?? null,
                'toll_flat_rate_cents' => $input['toll_flat_rate_cents'] ?? null,
                'notes' => $input['notes'] ?? null,
                'status' => VehicleStatus::Active,
            ]);

            $ownership = $input['ownership'];
            $period = VehicleOwnershipPeriod::create([
                'vehicle_id' => $vehicle->getKey(),
                'kind' => $ownership['kind'],
                'counterparty' => $ownership['counterparty'] ?? null,
                'registered_owner' => $ownership['registered_owner'] ?? null,
                'started_on' => $ownership['started_on'] ?? now()->toDateString(),
                'purchase_price_cents' => $ownership['purchase_price_cents'] ?? null,
                'lienholder' => $ownership['lienholder'] ?? null,
                'loan_account' => $ownership['loan_account'] ?? null,
                'loan_monthly_payment_cents' => $ownership['loan_monthly_payment_cents'] ?? null,
                'loan_payoff_date' => $ownership['loan_payoff_date'] ?? null,
                'transport_cost_cents' => $ownership['transport_cost_cents'] ?? null,
                'rental_rate_cents' => $ownership['rental_rate_cents'] ?? null,
                'rental_rate_period' => $ownership['rental_rate_period'] ?? null,
                'rental_deposit_cents' => $ownership['rental_deposit_cents'] ?? null,
                'rental_account_number' => $ownership['rental_account_number'] ?? null,
                'rental_payment_terms' => $ownership['rental_payment_terms'] ?? null,
                'rental_payment_method' => $ownership['rental_payment_method'] ?? null,
                'rental_agreement_number' => $ownership['rental_agreement_number'] ?? null,
                'mileage_allowance' => $ownership['mileage_allowance'] ?? null,
            ]);

            $this->events->emit('vehicle.created', $vehicle, [
                'after' => $this->scalars($vehicle->only([
                    'status', 'plate', 'vin', 'year', 'make', 'model', 'colour',
                    'body_type', 'fuel_type', 'franchise_id', 'company_id',
                    'odometer_miles', 'next_service_on', 'next_service_miles',
                ])),
            ]);
            $this->events->emit('vehicle.ownership_started', $vehicle, [
                'after' => $this->ownershipStartedPayload($period),
            ]);

            return $vehicle;
        });

        return response()->json(
            ['data' => $this->present($vehicle->fresh(), $request->user())],
            Response::HTTP_CREATED,
        );
    }

    /** GET /api/v1/vehicles/{vehicle} */
    public function show(Request $request, Vehicle $vehicle): JsonResponse
    {
        $this->authorize('view', $vehicle);

        return response()->json([
            'data' => $this->present($vehicle, $request->user()),
        ]);
    }

    /** PUT/PATCH /api/v1/vehicles/{vehicle} */
    public function update(Request $request, Vehicle $vehicle): JsonResponse
    {
        $this->authorize('update', $vehicle);

        $input = $request->validate($this->updateRules($vehicle), $this->ownershipMessages());

        if (array_key_exists('company_id', $input) || array_key_exists('franchise_id', $input)) {
            $companyId = array_key_exists('company_id', $input)
                ? $input['company_id']
                : $vehicle->company_id;
            $franchiseId = array_key_exists('franchise_id', $input)
                ? $input['franchise_id']
                : $vehicle->franchise_id;

            $company = $companyId === null ? null : Company::findOrFail($companyId);
            $franchise = $franchiseId === null ? null : Franchise::findOrFail($franchiseId);

            PlaceInView::assert($request->user(), $company, $franchise, 'vehicle');

            if ($companyId === null) {
                $input['franchise_id'] = null;
            }
        }

        $currentStatus = $vehicle->status;
        $nextStatus = isset($input['status'])
            ? VehicleStatus::from($input['status'])
            : $currentStatus;
        $ownershipInput = $input['ownership'] ?? null;
        unset($input['ownership']);

        $buyingBack = $currentStatus->isEnded() && $nextStatus->isOnRoad();

        DB::transaction(function () use ($request, $vehicle, $input, $nextStatus, $ownershipInput, $buyingBack) {
            VehicleStatus::apply($vehicle, $nextStatus, $ownershipInput);

            if ($buyingBack && $ownershipInput !== null) {
                $this->copyHttpOwnershipMoney(
                    $vehicle->openOwnership(),
                    $ownershipInput,
                    $request->user(),
                );
            }

            $vehicle->fill($input);

            $changed = $vehicle->getDirty();
            $before = array_intersect_key($vehicle->getOriginal(), $changed);

            $vehicle->save();

            if ($changed === []) {
                return;
            }

            $updated = array_intersect_key($changed, array_flip(self::UPDATED_FIELDS));
            if ($updated !== []) {
                $this->events->emit('vehicle.updated', $vehicle, [
                    'before' => $this->scalars(array_intersect_key($before, $updated)),
                    'after' => $this->scalars($updated),
                ]);
            }

            if (array_key_exists('status', $changed)) {
                $old = $this->scalar($before['status'] ?? null);
                $new = $this->scalar($changed['status']);
                $this->events->emit('vehicle.status_changed', $vehicle, [
                    'old' => $old,
                    'new' => $new,
                    'before' => ['status' => $old],
                    'after' => ['status' => $new],
                ]);
            }

            if ($buyingBack) {
                $period = $vehicle->openOwnership();
                if ($period !== null) {
                    $this->events->emit('vehicle.ownership_started', $vehicle, [
                        'after' => $this->ownershipStartedPayload($period),
                    ]);
                }
            }
        });

        return response()->json([
            'data' => $this->present($vehicle->fresh(), $request->user()),
        ]);
    }

    /** DELETE /api/v1/vehicles/{vehicle} */
    public function destroy(Vehicle $vehicle): Response
    {
        $this->authorize('delete', $vehicle);

        if (! $vehicle->isEmpty()) {
            return response()->json([
                'message' => 'This vehicle has history, so it cannot be removed. Mark it sold or returned instead.',
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $vehicle->delete();

        return response()->noContent();
    }

    /** @return array<string, mixed> */
    private function storeRules(): array
    {
        $vinUnique = Rule::unique('vehicles', 'vin')
            ->where('organization_id', $this->tenant->idOrFail());

        return [
            'company_id' => ['nullable', 'uuid', $this->existsInTenant('companies')],
            'franchise_id' => ['nullable', 'uuid', $this->existsInTenant('franchises')],
            'year' => ['nullable', 'integer', 'min:1900', 'max:2100'],
            'make' => ['nullable', 'string', 'max:100'],
            'model' => ['nullable', 'string', 'max:100'],
            'trim' => ['nullable', 'string', 'max:100'],
            'plate' => ['nullable', 'string', 'max:32'],
            'plate_state' => ['nullable', 'string', 'max:8'],
            'vin' => ['nullable', 'string', 'max:32', $vinUnique],
            'colour' => ['nullable', 'string', 'max:60'],
            'body_type' => ['required', 'string', Rule::enum(VehicleBodyType::class)],
            'fuel_type' => ['required', 'string', Rule::enum(VehicleFuelType::class)],
            'odometer_miles' => ['nullable', 'integer', 'min:0'],
            'odometer_updated_at' => ['nullable', 'date'],
            'next_service_on' => ['nullable', 'date'],
            'next_service_miles' => ['nullable', 'integer', 'min:0'],
            'registration_expires_on' => ['nullable', 'date'],
            'inspection_expires_on' => ['nullable', 'date'],
            'toll_device_number' => ['nullable', 'string', 'max:100'],
            'toll_plan' => ['nullable', 'string', 'max:255'],
            'toll_flat_rate_cents' => ['nullable', 'integer', 'min:0'],
            'notes' => ['nullable', 'string', 'max:5000'],
            'ownership' => ['required', 'array'],
            ...$this->ownershipFieldRules(kindRequired: true),
        ];
    }

    /** @return array<string, mixed> */
    private function updateRules(Vehicle $vehicle): array
    {
        $vinUnique = Rule::unique('vehicles', 'vin')
            ->where('organization_id', $this->tenant->idOrFail())
            ->ignore($vehicle->getKey());

        return [
            'company_id' => ['sometimes', 'nullable', 'uuid', $this->existsInTenant('companies')],
            'franchise_id' => ['sometimes', 'nullable', 'uuid', $this->existsInTenant('franchises')],
            'year' => ['sometimes', 'nullable', 'integer', 'min:1900', 'max:2100'],
            'make' => ['sometimes', 'nullable', 'string', 'max:100'],
            'model' => ['sometimes', 'nullable', 'string', 'max:100'],
            'trim' => ['sometimes', 'nullable', 'string', 'max:100'],
            'plate' => ['sometimes', 'nullable', 'string', 'max:32'],
            'plate_state' => ['sometimes', 'nullable', 'string', 'max:8'],
            'vin' => ['sometimes', 'nullable', 'string', 'max:32', $vinUnique],
            'colour' => ['sometimes', 'nullable', 'string', 'max:60'],
            'body_type' => ['sometimes', 'string', Rule::enum(VehicleBodyType::class)],
            'fuel_type' => ['sometimes', 'string', Rule::enum(VehicleFuelType::class)],
            'odometer_miles' => ['sometimes', 'nullable', 'integer', 'min:0'],
            'odometer_updated_at' => ['sometimes', 'nullable', 'date'],
            'next_service_on' => ['sometimes', 'nullable', 'date'],
            'next_service_miles' => ['sometimes', 'nullable', 'integer', 'min:0'],
            'registration_expires_on' => ['sometimes', 'nullable', 'date'],
            'inspection_expires_on' => ['sometimes', 'nullable', 'date'],
            'toll_device_number' => ['sometimes', 'nullable', 'string', 'max:100'],
            'toll_plan' => ['sometimes', 'nullable', 'string', 'max:255'],
            'toll_flat_rate_cents' => ['sometimes', 'nullable', 'integer', 'min:0'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'status' => ['sometimes', 'string', Rule::enum(VehicleStatus::class)],
            'ownership' => ['sometimes', 'array'],
            ...$this->ownershipFieldRules(kindRequired: false),
        ];
    }

    /** @return array<string, mixed> */
    private function ownershipFieldRules(bool $kindRequired): array
    {
        return [
            'ownership.kind' => [
                $kindRequired ? 'required' : 'required_with:ownership',
                'string',
                Rule::enum(VehicleOwnershipKind::class),
            ],
            'ownership.counterparty' => ['nullable', 'string', 'max:200'],
            'ownership.registered_owner' => ['nullable', 'string', 'max:200'],
            'ownership.started_on' => ['nullable', 'date'],
            'ownership.purchase_price_cents' => ['nullable', 'integer', 'min:0'],
            'ownership.lienholder' => ['nullable', 'string', 'max:200'],
            'ownership.loan_account' => ['nullable', 'string', 'max:100'],
            'ownership.loan_monthly_payment_cents' => ['nullable', 'integer', 'min:0'],
            'ownership.loan_payoff_date' => ['nullable', 'date'],
            'ownership.transport_cost_cents' => ['nullable', 'integer', 'min:0'],
            'ownership.rental_rate_cents' => ['nullable', 'integer', 'min:0'],
            'ownership.rental_rate_period' => ['nullable', 'string', Rule::enum(VehicleRatePeriod::class)],
            'ownership.rental_deposit_cents' => ['nullable', 'integer', 'min:0'],
            'ownership.rental_account_number' => ['nullable', 'string', 'max:100'],
            'ownership.rental_payment_terms' => ['nullable', 'string', Rule::enum(VehicleRentalTerms::class)],
            'ownership.rental_payment_method' => ['nullable', 'string', Rule::enum(VehiclePaymentMethod::class)],
            'ownership.rental_agreement_number' => ['nullable', 'string', 'max:100'],
            'ownership.mileage_allowance' => ['nullable', 'integer', 'min:0'],
        ];
    }

    /** @return array<string, string> */
    private function storeMessages(): array
    {
        return [
            'vin.unique' => 'This organization already has a vehicle with that VIN.',
            ...$this->ownershipMessages(),
        ];
    }

    /** @return array<string, string> */
    private function ownershipMessages(): array
    {
        return [
            'ownership.required' => 'A vehicle has to be owned or rented. Say which, then save.',
            'ownership.kind.required' => 'A vehicle has to be owned or rented. Say which, then save.',
            'ownership.kind.required_with' => 'A vehicle has to be owned or rented. Say which, then save.',
        ];
    }

    /**
     * Apply only writes kind / counterparty / started_on. The HTTP buy-back
     * still takes money through the vehicle validator, so copy those keys here
     * when the caller holds fleet_management.financials.
     *
     * @param  array<string, mixed>  $ownership
     */
    private function copyHttpOwnershipMoney(
        ?VehicleOwnershipPeriod $period,
        array $ownership,
        User $user,
    ): void {
        if ($period === null) {
            return;
        }

        if (! $user->hasPermission('fleet_management.financials')) {
            return;
        }

        $money = [];
        foreach (VehicleFinancials::keys() as $key) {
            if (array_key_exists($key, $ownership)) {
                $money[$key] = $ownership[$key];
            }
        }

        if ($money === []) {
            return;
        }

        $period->fill($money);
        $period->save();
    }

    /** @return array{kind: string, counterparty: ?string, started_on: ?string} */
    private function ownershipStartedPayload(VehicleOwnershipPeriod $period): array
    {
        return [
            'kind' => $period->kind->value,
            'counterparty' => $period->counterparty,
            'started_on' => $period->started_on?->toDateString(),
        ];
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    private function scalars(array $attributes): array
    {
        return array_map(fn (mixed $value) => $this->scalar($value), $attributes);
    }

    private function scalar(mixed $value): mixed
    {
        if ($value instanceof \BackedEnum) {
            return $value->value;
        }

        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d');
        }

        return $value;
    }

    /** @return array<string, mixed> */
    /**
     * How many amber boxes the vehicle's Identity card shows: the empty
     * EXPECTED_FIELDS, plus one when no next service is set at all. A count,
     * never the values, as HrPeopleController::missing, so the list row stays
     * short. Reads only the row — no query per vehicle in the list.
     */
    private function missing(Vehicle $vehicle): int
    {
        $empty = collect(Vehicle::EXPECTED_FIELDS)
            ->filter(fn (string $field) => blank($vehicle->getAttribute($field)))
            ->count();

        // Next service is a day OR a mileage; either one is an answer.
        $noService = $vehicle->next_service_on === null
            && $vehicle->next_service_miles === null;

        return $empty + ($noService ? 1 : 0);
    }

    private function present(Vehicle $vehicle, User $user): array
    {
        $vehicle->loadMissing([
            'company:id,name',
            'franchise:id,name',
            'ownershipPeriods',
            'insurancePolicies',
            'assignments.user:id,name',
            'serviceVisits',
            'payments',
        ]);

        $payload = [
            'id' => $vehicle->id,
            'number' => $vehicle->number,
            'label' => $vehicle->label,
            'year' => $vehicle->year,
            'make' => $vehicle->make,
            'model' => $vehicle->model,
            'trim' => $vehicle->trim,
            'plate' => $vehicle->plate,
            'plate_state' => $vehicle->plate_state,
            'vin' => $vehicle->vin,
            'colour' => $vehicle->colour,
            'body_type' => $vehicle->body_type->value,
            'fuel_type' => $vehicle->fuel_type->value,
            'odometer_miles' => $vehicle->odometer_miles,
            'odometer_updated_at' => $vehicle->odometer_updated_at?->toIso8601String(),
            'next_service_on' => $vehicle->next_service_on?->toDateString(),
            'next_service_miles' => $vehicle->next_service_miles,
            'registration_expires_on' => $vehicle->registration_expires_on?->toDateString(),
            'inspection_expires_on' => $vehicle->inspection_expires_on?->toDateString(),
            'toll_device_number' => $vehicle->toll_device_number,
            'toll_plan' => $vehicle->toll_plan,
            'toll_flat_rate_cents' => $vehicle->toll_flat_rate_cents,
            'notes' => $vehicle->notes,
            'status' => $vehicle->status->value,
            'missing' => $this->missing($vehicle),
            'company' => $vehicle->company_id === null ? null : [
                'id' => $vehicle->company_id,
                'name' => $vehicle->company?->name,
            ],
            'franchise' => $vehicle->franchise_id === null ? null : [
                'id' => $vehicle->franchise_id,
                'name' => $vehicle->franchise?->name,
            ],
            'ownership' => $this->presentOwnership($vehicle->openOwnership()),
            'ownership_periods' => $vehicle->ownershipPeriods
                ->map(fn (VehicleOwnershipPeriod $p) => $this->presentOwnership($p))
                ->values()
                ->all(),
            'insurance' => $this->presentInsurance($vehicle->openInsurance()),
            'insurance_policies' => $vehicle->insurancePolicies
                ->map(fn (VehicleInsurancePolicy $p) => $this->presentInsurance($p))
                ->values()
                ->all(),
            'assignment' => $this->presentAssignment($vehicle->openAssignment()),
            'assignments' => $vehicle->assignments
                ->map(fn (VehicleAssignment $a) => $this->presentAssignment($a))
                ->values()
                ->all(),
            'service_visits' => $vehicle->serviceVisits
                ->map(fn (VehicleServiceVisit $v) => $this->presentServiceVisit($v))
                ->values()
                ->all(),
            'payments' => $vehicle->payments
                ->map(fn (VehiclePayment $p) => $this->presentPayment($p))
                ->values()
                ->all(),
        ];

        return VehicleFinancials::strip($payload, $user);
    }

    /** @return array<string, mixed>|null */
    private function presentOwnership(?VehicleOwnershipPeriod $period): ?array
    {
        if ($period === null) {
            return null;
        }

        return [
            'id' => $period->id,
            'kind' => $period->kind->value,
            'counterparty' => $period->counterparty,
            'registered_owner' => $period->registered_owner,
            'started_on' => $period->started_on?->toDateString(),
            'ended_on' => $period->ended_on?->toDateString(),
            'ended_reason' => $period->ended_reason?->value,
            'purchase_price_cents' => $period->purchase_price_cents,
            'lienholder' => $period->lienholder,
            'loan_account' => $period->loan_account,
            'loan_monthly_payment_cents' => $period->loan_monthly_payment_cents,
            'loan_payoff_date' => $period->loan_payoff_date?->toDateString(),
            'transport_cost_cents' => $period->transport_cost_cents,
            'rental_rate_cents' => $period->rental_rate_cents,
            'rental_rate_period' => $period->rental_rate_period?->value,
            'rental_deposit_cents' => $period->rental_deposit_cents,
            'rental_account_number' => $period->rental_account_number,
            'rental_payment_terms' => $period->rental_payment_terms?->value,
            'rental_payment_method' => $period->rental_payment_method?->value,
        ];
    }

    /** @return array<string, mixed>|null */
    private function presentInsurance(?VehicleInsurancePolicy $policy): ?array
    {
        if ($policy === null) {
            return null;
        }

        return [
            'id' => $policy->id,
            'carrier' => $policy->carrier,
            'policy_number' => $policy->policy_number,
            'coverage' => $policy->coverage,
            'agent' => $policy->agent,
            'agent_phone' => $policy->agent_phone,
            'listed_drivers' => $policy->listed_drivers,
            'started_on' => $policy->started_on?->toDateString(),
            'expires_on' => $policy->expires_on?->toDateString(),
            'ended_on' => $policy->ended_on?->toDateString(),
            'premium_cents' => $policy->premium_cents,
            'premium_period' => $policy->premium_period?->value,
        ];
    }

    /** @return array<string, mixed>|null */
    private function presentAssignment(?VehicleAssignment $assignment): ?array
    {
        if ($assignment === null) {
            return null;
        }

        $assignment->loadMissing('user:id,name');

        return [
            'id' => $assignment->id,
            'user' => $assignment->user_id === null ? null : [
                'id' => $assignment->user_id,
                'name' => $assignment->user?->name,
            ],
            'assigned_at' => $assignment->assigned_at?->toIso8601String(),
            'released_at' => $assignment->released_at?->toIso8601String(),
            'note' => $assignment->note,
        ];
    }

    /** @return array<string, mixed> */
    private function presentServiceVisit(VehicleServiceVisit $visit): array
    {
        return [
            'id' => $visit->id,
            'kind' => $visit->kind->value,
            'occurred_on' => $visit->occurred_on?->toDateString(),
            'odometer_miles' => $visit->odometer_miles,
            'performed_by' => $visit->performed_by,
            'notes' => $visit->notes,
            'next_service_on' => $visit->next_service_on?->toDateString(),
            'next_service_miles' => $visit->next_service_miles,
        ];
    }

    /** @return array<string, mixed> */
    private function presentPayment(VehiclePayment $payment): array
    {
        return [
            'id' => $payment->id,
            'category' => $payment->category->value,
            'amount_cents' => $payment->amount_cents,
            'occurred_on' => $payment->occurred_on?->toDateString(),
            'method' => $payment->method->value,
            'note' => $payment->note,
            'ownership_period_id' => $payment->ownership_period_id,
            'insurance_policy_id' => $payment->insurance_policy_id,
            'service_visit_id' => $payment->service_visit_id,
        ];
    }
}
