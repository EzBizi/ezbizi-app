<?php

namespace App\Http\Controllers\Api;

use App\Models\Vehicle;
use App\Models\VehicleServiceVisit;
use App\Support\Automations\EventEmitter;
use App\Support\Fleet\VehicleServiceKind;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Log a service visit; higher odometer and next-due write through (spec §6.4).
 *
 * Authorize update. Odometer / next-due write-through does not raise vehicle.updated.
 */
class VehicleServiceController extends Controller
{
    public function __construct(
        private readonly EventEmitter $events,
    ) {}

    /** GET /api/v1/vehicles/{vehicle}/service */
    public function index(Request $request, Vehicle $vehicle): JsonResponse
    {
        $this->authorize('view', $vehicle);

        $visits = $vehicle->serviceVisits()
            ->orderByDesc('occurred_on')
            ->orderByDesc('created_at')
            ->get()
            ->map(fn (VehicleServiceVisit $visit) => $this->present($visit));

        return response()->json(['data' => ['service' => $visits]]);
    }

    /** POST /api/v1/vehicles/{vehicle}/service */
    public function store(Request $request, Vehicle $vehicle): JsonResponse
    {
        $this->authorize('update', $vehicle);

        $input = $request->validate([
            'kind' => ['required', 'string', Rule::enum(VehicleServiceKind::class)],
            'occurred_on' => ['required', 'date'],
            'odometer_miles' => ['nullable', 'integer', 'min:0'],
            'performed_by' => ['nullable', 'string', 'max:200'],
            'notes' => ['nullable', 'string', 'max:5000'],
            'next_service_on' => ['nullable', 'date'],
            'next_service_miles' => ['nullable', 'integer', 'min:0'],
        ]);

        $visit = DB::transaction(function () use ($vehicle, $input) {
            $visit = VehicleServiceVisit::create([
                'vehicle_id' => $vehicle->getKey(),
                'kind' => $input['kind'],
                'occurred_on' => $input['occurred_on'],
                'odometer_miles' => $input['odometer_miles'] ?? null,
                'performed_by' => $input['performed_by'] ?? null,
                'notes' => $input['notes'] ?? null,
                'next_service_on' => $input['next_service_on'] ?? null,
                'next_service_miles' => $input['next_service_miles'] ?? null,
            ]);

            $dirty = false;

            // Only the latest visit speaks for the vehicle's next service: an
            // older visit logged late must not overwrite it (audit 2026-11-27).
            $latest = ! VehicleServiceVisit::query()
                ->where('vehicle_id', $vehicle->getKey())
                ->whereKeyNot($visit->getKey())
                ->whereDate('occurred_on', '>', $input['occurred_on'])
                ->exists();

            if (array_key_exists('odometer_miles', $input) && $input['odometer_miles'] !== null) {
                $visitMiles = (int) $input['odometer_miles'];
                if ($vehicle->odometer_miles === null || $visitMiles > $vehicle->odometer_miles) {
                    $vehicle->odometer_miles = $visitMiles;
                    $dirty = true;
                }
            }

            if ($latest && array_key_exists('next_service_on', $input) && $input['next_service_on'] !== null) {
                $vehicle->next_service_on = $input['next_service_on'];
                $dirty = true;
            }

            if ($latest && array_key_exists('next_service_miles', $input) && $input['next_service_miles'] !== null) {
                $vehicle->next_service_miles = $input['next_service_miles'];
                $dirty = true;
            }

            if ($dirty) {
                $vehicle->save();
            }

            $this->events->emit('vehicle.service_logged', $vehicle, [
                'after' => [
                    'kind' => $visit->kind->value,
                    'occurred_on' => $visit->occurred_on?->toDateString(),
                    'odometer_miles' => $visit->odometer_miles,
                    'performed_by' => $visit->performed_by,
                ],
            ]);

            return $visit;
        });

        return response()->json([
            'data' => [
                'service' => $this->present($visit),
            ],
        ]);
    }

    /** @return array<string, mixed> */
    private function present(VehicleServiceVisit $visit): array
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
}
