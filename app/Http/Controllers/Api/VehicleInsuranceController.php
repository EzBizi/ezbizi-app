<?php

namespace App\Http\Controllers\Api;

use App\Models\Vehicle;
use App\Models\VehicleInsurancePolicy;
use App\Support\Fleet\VehicleFinancials;
use App\Support\Fleet\VehiclePremiumPeriod;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Start a new insurance policy by closing the open row (spec §6.2).
 *
 * Money keys without financials are ignored, not refused. No automation emit.
 */
class VehicleInsuranceController extends Controller
{
    /** POST /api/v1/vehicles/{vehicle}/insurance */
    public function store(Request $request, Vehicle $vehicle): JsonResponse
    {
        $this->authorize('update', $vehicle);

        $input = $request->validate([
            'carrier' => ['required', 'string', 'max:200'],
            'policy_number' => ['nullable', 'string', 'max:100'],
            'coverage' => ['nullable', 'string', 'max:2000'],
            'started_on' => ['nullable', 'date'],
            'expires_on' => ['nullable', 'date'],
            'premium_cents' => ['nullable', 'integer', 'min:0'],
            'premium_period' => ['nullable', 'string', Rule::in(VehiclePremiumPeriod::values())],
        ]);

        if (! $request->user()->hasPermission('fleet_management.financials')) {
            foreach (VehicleFinancials::keys() as $key) {
                unset($input[$key]);
            }
        }

        $policy = DB::transaction(function () use ($vehicle, $input) {
            $open = $vehicle->openInsurance();
            if ($open !== null) {
                $open->ended_on = now()->toDateString();
                $open->save();
            }

            return VehicleInsurancePolicy::create([
                'vehicle_id' => $vehicle->getKey(),
                'carrier' => $input['carrier'],
                'policy_number' => $input['policy_number'] ?? null,
                'coverage' => $input['coverage'] ?? null,
                'started_on' => $input['started_on'] ?? now()->toDateString(),
                'expires_on' => $input['expires_on'] ?? null,
                'premium_cents' => $input['premium_cents'] ?? null,
                'premium_period' => $input['premium_period'] ?? null,
            ]);
        });

        return response()->json([
            'data' => [
                'insurance' => VehicleFinancials::strip(
                    $this->presentInsurance($policy),
                    $request->user(),
                ),
            ],
        ]);
    }

    /** @return array<string, mixed> */
    private function presentInsurance(VehicleInsurancePolicy $policy): array
    {
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
}
