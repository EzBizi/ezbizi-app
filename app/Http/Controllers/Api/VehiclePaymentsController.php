<?php

namespace App\Http\Controllers\Api;

use App\Models\Vehicle;
use App\Models\VehiclePayment;
use App\Support\Automations\EventEmitter;
use App\Support\Fleet\VehiclePaymentCategory;
use App\Support\Fleet\VehiclePaymentMethod;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Record and list payments on a vehicle (spec §6.5, §8).
 *
 * Authorize financials (list omitted entirely without it). Optional link to
 * exactly one of ownership / insurance / service on this vehicle.
 */
class VehiclePaymentsController extends Controller
{
    public function __construct(
        private readonly EventEmitter $events,
    ) {}

    /** GET /api/v1/vehicles/{vehicle}/payments */
    public function index(Request $request, Vehicle $vehicle): JsonResponse
    {
        $this->authorize('view', $vehicle);
        $this->authorize('financials', $vehicle);

        $payments = $vehicle->payments()
            ->orderByDesc('occurred_on')
            ->orderByDesc('created_at')
            ->get()
            ->map(fn (VehiclePayment $payment) => $this->present($payment));

        return response()->json(['data' => ['payments' => $payments]]);
    }

    /** POST /api/v1/vehicles/{vehicle}/payments */
    public function store(Request $request, Vehicle $vehicle): JsonResponse
    {
        $this->authorize('create', [VehiclePayment::class, $vehicle]);

        $input = $request->validate([
            'category' => ['required', 'string', Rule::enum(VehiclePaymentCategory::class)],
            'amount_cents' => ['required', 'integer', 'min:0'],
            'occurred_on' => ['required', 'date'],
            'method' => ['required', 'string', Rule::enum(VehiclePaymentMethod::class)],
            'note' => ['nullable', 'string', 'max:5000'],
            'ownership_period_id' => [
                'nullable', 'uuid',
                Rule::exists('vehicle_ownership_periods', 'id')
                    ->where('vehicle_id', $vehicle->getKey()),
            ],
            'insurance_policy_id' => [
                'nullable', 'uuid',
                Rule::exists('vehicle_insurance_policies', 'id')
                    ->where('vehicle_id', $vehicle->getKey()),
            ],
            'service_visit_id' => [
                'nullable', 'uuid',
                Rule::exists('vehicle_service_visits', 'id')
                    ->where('vehicle_id', $vehicle->getKey()),
            ],
        ]);

        $linkKeys = ['ownership_period_id', 'insurance_policy_id', 'service_visit_id'];
        $setLinks = collect($linkKeys)
            ->filter(fn (string $key) => filled($input[$key] ?? null))
            ->values();

        if ($setLinks->count() > 1) {
            throw ValidationException::withMessages([
                $setLinks[1] => ['A payment links to at most one ownership, insurance, or service row.'],
            ]);
        }

        $payment = DB::transaction(function () use ($vehicle, $input) {
            $payment = VehiclePayment::create([
                'vehicle_id' => $vehicle->getKey(),
                'category' => $input['category'],
                'amount_cents' => $input['amount_cents'],
                'occurred_on' => $input['occurred_on'],
                'method' => $input['method'],
                'note' => $input['note'] ?? null,
                'ownership_period_id' => $input['ownership_period_id'] ?? null,
                'insurance_policy_id' => $input['insurance_policy_id'] ?? null,
                'service_visit_id' => $input['service_visit_id'] ?? null,
            ]);

            $this->events->emit('vehicle.payment_recorded', $vehicle, [
                'after' => [
                    'category' => $payment->category->value,
                    'amount_cents' => $payment->amount_cents,
                    'occurred_on' => $payment->occurred_on?->toDateString(),
                    'method' => $payment->method->value,
                ],
            ]);

            return $payment;
        });

        return response()->json([
            'data' => [
                'payment' => $this->present($payment),
            ],
        ]);
    }

    /** @return array<string, mixed> */
    private function present(VehiclePayment $payment): array
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
