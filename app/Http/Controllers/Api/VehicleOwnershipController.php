<?php

namespace App\Http\Controllers\Api;

use App\Models\Vehicle;
use App\Models\VehicleOwnershipPeriod;
use App\Support\Automations\EventEmitter;
use App\Support\Fleet\VehicleEndedReason;
use App\Support\Fleet\VehicleFinancials;
use App\Support\Fleet\VehicleOwnershipKind;
use App\Support\Fleet\VehiclePaymentMethod;
use App\Support\Fleet\VehicleRatePeriod;
use App\Support\Fleet\VehicleRentalTerms;
use App\Support\Fleet\VehicleStatus;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\Response;

/**
 * Start a new ownership period by closing the open row (spec §6.1).
 *
 * Sold/returned vehicles must buy back via vehicle update (Task 7), not here.
 * Money keys without financials are ignored, not refused.
 */
class VehicleOwnershipController extends Controller
{
    public function __construct(
        private readonly EventEmitter $events,
    ) {}

    /** POST /api/v1/vehicles/{vehicle}/ownership */
    public function store(Request $request, Vehicle $vehicle): JsonResponse
    {
        $this->authorize('update', $vehicle);

        if ($vehicle->status === VehicleStatus::Sold || $vehicle->status === VehicleStatus::Returned) {
            return response()->json([
                'message' => 'A new ownership period belongs with bringing the vehicle back on the road.',
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $input = $request->validate([
            'kind' => ['required', 'string', Rule::enum(VehicleOwnershipKind::class)],
            'counterparty' => ['nullable', 'string', 'max:200'],
            'started_on' => ['nullable', 'date'],
            'ended_reason' => ['nullable', 'string', Rule::enum(VehicleEndedReason::class)],
            'purchase_price_cents' => ['nullable', 'integer', 'min:0'],
            'lienholder' => ['nullable', 'string', 'max:200'],
            'loan_account' => ['nullable', 'string', 'max:100'],
            'loan_monthly_payment_cents' => ['nullable', 'integer', 'min:0'],
            'loan_payoff_date' => ['nullable', 'date'],
            'transport_cost_cents' => ['nullable', 'integer', 'min:0'],
            'rental_rate_cents' => ['nullable', 'integer', 'min:0'],
            'rental_rate_period' => ['nullable', 'string', Rule::enum(VehicleRatePeriod::class)],
            'rental_deposit_cents' => ['nullable', 'integer', 'min:0'],
            'rental_account_number' => ['nullable', 'string', 'max:100'],
            'rental_payment_terms' => ['nullable', 'string', Rule::enum(VehicleRentalTerms::class)],
            'rental_payment_method' => ['nullable', 'string', Rule::enum(VehiclePaymentMethod::class)],
        ], [
            'kind.required' => 'A vehicle has to be owned or rented. Say which, then save.',
        ]);

        $closeReason = VehicleEndedReason::tryFrom($input['ended_reason'] ?? '')
            ?? VehicleEndedReason::Replaced;
        unset($input['ended_reason']);

        if (! $request->user()->hasPermission('fleet_management.financials')) {
            foreach (VehicleFinancials::keys() as $key) {
                unset($input[$key]);
            }
        }

        $period = DB::transaction(function () use ($vehicle, $input, $closeReason) {
            $open = $vehicle->openOwnership();
            if ($open !== null) {
                $open->ended_on = now()->toDateString();
                $open->ended_reason = $closeReason;
                $open->save();
            }

            $period = VehicleOwnershipPeriod::create([
                'vehicle_id' => $vehicle->getKey(),
                'kind' => $input['kind'],
                'counterparty' => $input['counterparty'] ?? null,
                'started_on' => $input['started_on'] ?? now()->toDateString(),
                'purchase_price_cents' => $input['purchase_price_cents'] ?? null,
                'lienholder' => $input['lienholder'] ?? null,
                'loan_account' => $input['loan_account'] ?? null,
                'loan_monthly_payment_cents' => $input['loan_monthly_payment_cents'] ?? null,
                'loan_payoff_date' => $input['loan_payoff_date'] ?? null,
                'transport_cost_cents' => $input['transport_cost_cents'] ?? null,
                'rental_rate_cents' => $input['rental_rate_cents'] ?? null,
                'rental_rate_period' => $input['rental_rate_period'] ?? null,
                'rental_deposit_cents' => $input['rental_deposit_cents'] ?? null,
                'rental_account_number' => $input['rental_account_number'] ?? null,
                'rental_payment_terms' => $input['rental_payment_terms'] ?? null,
                'rental_payment_method' => $input['rental_payment_method'] ?? null,
            ]);

            $this->events->emit('vehicle.ownership_started', $vehicle, [
                'after' => [
                    'kind' => $period->kind->value,
                    'counterparty' => $period->counterparty,
                    'started_on' => $period->started_on?->toDateString(),
                ],
            ]);

            return $period;
        });

        return response()->json([
            'data' => [
                'ownership' => VehicleFinancials::strip(
                    $this->presentOwnership($period),
                    $request->user(),
                ),
            ],
        ]);
    }

    /** @return array<string, mixed> */
    private function presentOwnership(VehicleOwnershipPeriod $period): array
    {
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
}
