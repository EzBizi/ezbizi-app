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
            /*
             * 409, not 403, and the difference is not decoration.
             *
             * This is a PLATFORM fact — the organization has Clock in switched
             * off — and 403 is what a permission failure returns. Settings
             * measured the cost while writing their per-tab suite on
             * 2026-09-17: their positive case, a dispatcher granted only Work
             * Hours, came back 403, and they realised a suite of NEGATIVES
             * would every one have passed against a feature that was not
             * working at all. An assertion true for two reasons cannot tell
             * them apart, and permission suites are being written across the
             * fleet this week.
             *
             * Not 501: that says EzBizi cannot do this, which is false — an
             * administrator flipping one switch turns it on. 409 says the
             * request conflicts with the current state of the thing, which is
             * what this is. Pick the code that is true about who can fix it.
             */
            abort(409, 'Clock in is switched off for this organization.');
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
            // 409 rather than 403, for the reason spelled out in index().
            abort(409, 'Clock in is switched off for this organization.');
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
