<?php

namespace App\Http\Controllers\Api;

use App\Models\Franchise;
use App\Models\FranchiseDailyLimit;
use App\Models\ServiceArea;
use App\Support\Scoping\ViewScope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * A franchise's daily limit, per weekday: the most jobs the whole franchise
 * takes that day, whatever its areas have left (Jose, 2026-09-25: "the
 * technicians are shared basically"). Openings enforces it; this only stores
 * it. The limits are read with the areas, in SchedulingAreasController::index.
 */
class SchedulingFranchiseLimitsController extends Controller
{
    /** PUT /api/v1/scheduling/franchises/{franchise}/limits {limits: {"0".."6": int|null}} */
    public function update(Request $request, string $franchise): JsonResponse
    {
        $row = Franchise::query()
            ->whereIn('id', ViewScope::forUser($request->user())->franchiseIds())
            ->findOrFail($franchise);

        $this->authorize('limit', [ServiceArea::class, $row]);

        $data = $request->validate([
            'limits' => ['present', 'array'],
            // Blank is no limit, never a zero — see the migration.
            'limits.*' => ['nullable', 'integer', 'min:1', 'max:9999'],
        ]);

        $limits = [];
        foreach ($data['limits'] as $weekday => $max) {
            abort_unless(in_array((string) $weekday, ['0', '1', '2', '3', '4', '5', '6'], true), 422, 'A weekday is 0 (Sunday) to 6.');
            if ($max !== null) {
                $limits[(int) $weekday] = (int) $max;
            }
        }

        DB::transaction(function () use ($row, $limits) {
            FranchiseDailyLimit::where('franchise_id', $row->id)->delete();

            foreach ($limits as $weekday => $max) {
                FranchiseDailyLimit::create([
                    'franchise_id' => $row->id,
                    'weekday' => $weekday,
                    'max_jobs' => $max,
                ]);
            }
        });

        // An object keyed by weekday: cast, or keys 0..6 in order encode as a
        // JSON array (the bug SchedulingAreasController::week() once shipped).
        return response()->json(['data' => (object) $limits]);
    }
}
