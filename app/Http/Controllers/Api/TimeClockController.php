<?php

namespace App\Http\Controllers\Api;

use App\Models\TimeClockShift;
use App\Support\TimeClock\TimeClock;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Punch clock for the signed-in person (Clock in design §8) and the HR
 * day list (design §9).
 *
 * Punch bodies are empty on purpose: extra JSON keys, including a
 * client-supplied `clocked_in_at`, are ignored. The server clock is the
 * only time that counts. The list never reads a device timezone; `?on=`
 * is a calendar date in the current view zone.
 */
class TimeClockController extends Controller
{
    public function __construct(private readonly TimeClock $timeClock) {}

    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', TimeClockShift::class);

        $user = $request->user();

        if (! $this->timeClock->organizationEnabled($user->organization)) {
            abort(403, 'Clock in is switched off for this organization.');
        }

        $on = $request->query('on');

        return response()->json([
            'data' => $this->timeClock->dayList($user, is_string($on) ? $on : null),
        ]);
    }

    /**
     * GET /api/v1/hr/time-clock/week — the Work Hours screen (2026-09-17).
     *
     * Same two gates as the day list: `hr.view` and the organization switch.
     */
    public function week(Request $request): JsonResponse
    {
        $this->authorize('viewAny', TimeClockShift::class);

        $user = $request->user();

        if (! $this->timeClock->organizationEnabled($user->organization)) {
            abort(403, 'Clock in is switched off for this organization.');
        }

        $on = $request->query('on');

        return response()->json([
            'data' => $this->timeClock->weekList($user, is_string($on) ? $on : null),
        ]);
    }

    public function clockIn(Request $request): JsonResponse
    {
        return response()->json([
            'data' => ['time_clock' => $this->timeClock->clockIn($request->user())],
        ]);
    }

    /** The `/me` block on its own, for the 60-second poll (design 2026-09-08 §3.12). */
    public function me(Request $request): JsonResponse
    {
        return response()->json([
            'data' => ['time_clock' => $this->timeClock->payload($request->user())],
        ]);
    }

    public function extend(Request $request): JsonResponse
    {
        return response()->json([
            'data' => ['time_clock' => $this->timeClock->extend($request->user())],
        ]);
    }

    public function clockOut(Request $request): JsonResponse
    {
        return response()->json([
            'data' => ['time_clock' => $this->timeClock->clockOut($request->user())],
        ]);
    }
}
