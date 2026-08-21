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
use App\Support\Fleet\VehicleBodyType;
use App\Support\Fleet\VehicleEndedReason;
use App\Support\Fleet\VehicleFinancials;
use App\Support\Fleet\VehicleFuelType;
use App\Support\Fleet\VehicleOwnershipKind;
use App\Support\Fleet\VehiclePaymentMethod;
use App\Support\Fleet\VehicleRatePeriod;
use App\Support\Fleet\VehicleRentalTerms;
use App\Support\Fleet\VehicleStatus;
use App\Support\Numbering\RecordNumberGenerator;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Fleet register — create, show, update, destroy (spec §5, §8).
 *
 * Create is one transaction: vehicle + first ownership period. Sold/returned
 * close the open ownership on the same save; buy-back needs status + ownership
 * together. Money is stripped on every read unless the caller holds
 * fleet_management.financials.
 */
class VehiclesController extends Controller
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

    /** POST /api/v1/vehicles */
    public function store(Request $request): JsonResponse
    {
        $input = $request->validate($this->storeRules(), $this->storeMessages());

        $company = Company::findOrFail($input['company_id']);
        $franchise = isset($input['franchise_id'])
            ? Franchise::findOrFail($input['franchise_id'])
            : null;

        if ($franchise !== null && $franchise->company_id !== $company->getKey()) {
            throw ValidationException::withMessages([
                'franchise_id' => 'That place is not part of this company.',
            ]);
        }

        $this->authorize('create', [Vehicle::class, $company, $franchise]);

        $vehicle = DB::transaction(function () use ($input, $company, $franchise) {
            $number = $this->numbers->next(
                RecordNumberGenerator::VEHICLES,
                $franchise ?? $company,
            );

            $vehicle = Vehicle::create([
                'company_id' => $company->getKey(),
                'franchise_id' => $franchise?->getKey(),
                'number' => $number,
                'year' => $input['year'] ?? null,
                'make' => $input['make'] ?? null,
                'model' => $input['model'] ?? null,
                'plate' => $input['plate'] ?? null,
                'vin' => $input['vin'] ?? null,
                'colour' => $input['colour'] ?? null,
                'body_type' => $input['body_type'],
                'fuel_type' => $input['fuel_type'],
                'odometer_miles' => $input['odometer_miles'] ?? null,
                'next_service_on' => $input['next_service_on'] ?? null,
                'next_service_miles' => $input['next_service_miles'] ?? null,
                'status' => VehicleStatus::Active,
            ]);

            $ownership = $input['ownership'];
            VehicleOwnershipPeriod::create([
                'vehicle_id' => $vehicle->getKey(),
                'kind' => $ownership['kind'],
                'counterparty' => $ownership['counterparty'] ?? null,
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

        if (array_key_exists('franchise_id', $input)) {
            $franchise = $input['franchise_id'] === null
                ? null
                : Franchise::findOrFail($input['franchise_id']);

            if ($franchise !== null && $franchise->company_id !== $vehicle->company_id) {
                throw ValidationException::withMessages([
                    'franchise_id' => 'That place is not part of this company.',
                ]);
            }
        }

        $currentStatus = $vehicle->status;
        $nextStatus = isset($input['status'])
            ? VehicleStatus::from($input['status'])
            : $currentStatus;
        $ownershipInput = $input['ownership'] ?? null;
        unset($input['ownership']);

        $this->assertStatusTransition($vehicle, $currentStatus, $nextStatus, $ownershipInput);

        DB::transaction(function () use ($vehicle, $input, $currentStatus, $nextStatus, $ownershipInput) {
            if ($nextStatus === VehicleStatus::Sold && $currentStatus !== VehicleStatus::Sold) {
                $this->closeOpenOwnership($vehicle, VehicleEndedReason::Sold);
            }

            if ($nextStatus === VehicleStatus::Returned && $currentStatus !== VehicleStatus::Returned) {
                $this->closeOpenOwnership($vehicle, VehicleEndedReason::Returned);
            }

            if ($this->isEndedStatus($currentStatus) && $this->isOnRoadStatus($nextStatus)) {
                /** @var array<string, mixed> $ownershipInput */
                $this->openOwnershipPeriod($vehicle, $ownershipInput);
            }

            $vehicle->fill($input);
            $vehicle->save();
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
            'company_id' => ['required', 'uuid', $this->existsInTenant('companies')],
            'franchise_id' => ['nullable', 'uuid', $this->existsInTenant('franchises')],
            'year' => ['nullable', 'integer', 'min:1900', 'max:2100'],
            'make' => ['nullable', 'string', 'max:100'],
            'model' => ['nullable', 'string', 'max:100'],
            'plate' => ['nullable', 'string', 'max:32'],
            'vin' => ['nullable', 'string', 'max:32', $vinUnique],
            'colour' => ['nullable', 'string', 'max:60'],
            'body_type' => ['required', 'string', Rule::in(VehicleBodyType::values())],
            'fuel_type' => ['required', 'string', Rule::in(VehicleFuelType::values())],
            'odometer_miles' => ['nullable', 'integer', 'min:0'],
            'next_service_on' => ['nullable', 'date'],
            'next_service_miles' => ['nullable', 'integer', 'min:0'],
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
            'franchise_id' => ['sometimes', 'nullable', 'uuid', $this->existsInTenant('franchises')],
            'year' => ['sometimes', 'nullable', 'integer', 'min:1900', 'max:2100'],
            'make' => ['sometimes', 'nullable', 'string', 'max:100'],
            'model' => ['sometimes', 'nullable', 'string', 'max:100'],
            'plate' => ['sometimes', 'nullable', 'string', 'max:32'],
            'vin' => ['sometimes', 'nullable', 'string', 'max:32', $vinUnique],
            'colour' => ['sometimes', 'nullable', 'string', 'max:60'],
            'body_type' => ['sometimes', 'string', Rule::in(VehicleBodyType::values())],
            'fuel_type' => ['sometimes', 'string', Rule::in(VehicleFuelType::values())],
            'odometer_miles' => ['sometimes', 'nullable', 'integer', 'min:0'],
            'next_service_on' => ['sometimes', 'nullable', 'date'],
            'next_service_miles' => ['sometimes', 'nullable', 'integer', 'min:0'],
            'status' => ['sometimes', 'string', Rule::in(VehicleStatus::values())],
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
                Rule::in(VehicleOwnershipKind::values()),
            ],
            'ownership.counterparty' => ['nullable', 'string', 'max:200'],
            'ownership.started_on' => ['nullable', 'date'],
            'ownership.purchase_price_cents' => ['nullable', 'integer', 'min:0'],
            'ownership.lienholder' => ['nullable', 'string', 'max:200'],
            'ownership.loan_account' => ['nullable', 'string', 'max:100'],
            'ownership.loan_monthly_payment_cents' => ['nullable', 'integer', 'min:0'],
            'ownership.loan_payoff_date' => ['nullable', 'date'],
            'ownership.transport_cost_cents' => ['nullable', 'integer', 'min:0'],
            'ownership.rental_rate_cents' => ['nullable', 'integer', 'min:0'],
            'ownership.rental_rate_period' => ['nullable', 'string', Rule::in(VehicleRatePeriod::values())],
            'ownership.rental_deposit_cents' => ['nullable', 'integer', 'min:0'],
            'ownership.rental_account_number' => ['nullable', 'string', 'max:100'],
            'ownership.rental_payment_terms' => ['nullable', 'string', Rule::in(VehicleRentalTerms::values())],
            'ownership.rental_payment_method' => ['nullable', 'string', Rule::in(VehiclePaymentMethod::values())],
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
     * @param  array<string, mixed>|null  $ownershipInput
     */
    private function assertStatusTransition(
        Vehicle $vehicle,
        VehicleStatus $current,
        VehicleStatus $next,
        ?array $ownershipInput,
    ): void {
        $becomingSold = $next === VehicleStatus::Sold && $current !== VehicleStatus::Sold;
        $becomingReturned = $next === VehicleStatus::Returned && $current !== VehicleStatus::Returned;
        $buyingBack = $this->isEndedStatus($current) && $this->isOnRoadStatus($next);
        $ownershipPresent = $ownershipInput !== null;

        if ($becomingSold) {
            $open = $vehicle->openOwnership();
            if ($open === null || $open->kind !== VehicleOwnershipKind::Owned) {
                throw ValidationException::withMessages([
                    'status' => 'Sold is for a vehicle the company owns. Return a rental instead.',
                ]);
            }
        }

        if ($becomingReturned) {
            $open = $vehicle->openOwnership();
            if ($open === null || $open->kind !== VehicleOwnershipKind::Rented) {
                throw ValidationException::withMessages([
                    'status' => 'Return is for a rental. Mark an owned vehicle sold instead.',
                ]);
            }
        }

        if ($buyingBack && ! $ownershipPresent) {
            throw ValidationException::withMessages([
                'ownership' => 'A vehicle has to be owned or rented. Say which, then save.',
            ]);
        }

        if ($ownershipPresent && ! $buyingBack) {
            throw ValidationException::withMessages([
                'ownership' => 'A new ownership period belongs with bringing the vehicle back on the road.',
            ]);
        }
    }

    private function closeOpenOwnership(Vehicle $vehicle, VehicleEndedReason $reason): void
    {
        $open = $vehicle->openOwnership();
        if ($open === null) {
            return;
        }

        $open->ended_on = now()->toDateString();
        $open->ended_reason = $reason;
        $open->save();
    }

    /** @param  array<string, mixed>  $ownership */
    private function openOwnershipPeriod(Vehicle $vehicle, array $ownership): void
    {
        VehicleOwnershipPeriod::create([
            'vehicle_id' => $vehicle->getKey(),
            'kind' => $ownership['kind'],
            'counterparty' => $ownership['counterparty'] ?? null,
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
        ]);
    }

    private function isEndedStatus(VehicleStatus $status): bool
    {
        return $status === VehicleStatus::Sold || $status === VehicleStatus::Returned;
    }

    private function isOnRoadStatus(VehicleStatus $status): bool
    {
        return in_array($status, [
            VehicleStatus::Active,
            VehicleStatus::InShop,
            VehicleStatus::OutOfService,
        ], true);
    }

    /** @return array<string, mixed> */
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
            'plate' => $vehicle->plate,
            'vin' => $vehicle->vin,
            'colour' => $vehicle->colour,
            'body_type' => $vehicle->body_type->value,
            'fuel_type' => $vehicle->fuel_type->value,
            'odometer_miles' => $vehicle->odometer_miles,
            'next_service_on' => $vehicle->next_service_on?->toDateString(),
            'next_service_miles' => $vehicle->next_service_miles,
            'status' => $vehicle->status->value,
            'company' => [
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
