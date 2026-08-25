<?php

namespace App\Http\Controllers\Api;

use App\Support\TimeClock\TimeClock;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Punch clock for the signed-in person (Clock in design §8).
 *
 * Bodies are empty on purpose: extra JSON keys, including a client-supplied
 * `clocked_in_at`, are ignored. The server clock is the only time that counts.
 */
class TimeClockController extends Controller
{
    public function __construct(private readonly TimeClock $timeClock) {}

    public function clockIn(Request $request): JsonResponse
    {
        return response()->json([
            'data' => ['time_clock' => $this->timeClock->clockIn($request->user())],
        ]);
    }

    public function clockOut(Request $request): JsonResponse
    {
        return response()->json([
            'data' => ['time_clock' => $this->timeClock->clockOut($request->user())],
        ]);
    }
}
