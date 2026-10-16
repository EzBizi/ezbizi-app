<?php

namespace App\Http\Controllers\Api;

use App\Models\TimeClockDay;
use App\Models\TimeClockShift;
use App\Models\User;
use App\Support\TimeClock\TimeClock;
use App\Support\TimeClock\TimeClockStats;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

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
        $week = $this->timeClock->weekList($user, is_string($on) ? $on : null, inView: true);

        // Corrections people asked for, for whoever may approve them (2026-09-30).
        if ($user->hasPermission('work_hours.manage')) {
            $week['correction_requests'] = app(TimeClockCorrectionsController::class)->pendingFor($user);
        }

        return response()->json(['data' => $week]);
    }

    /**
     * Work Hours › Dashboard: on time, late, missed and averages per person
     * over `from`..`to` (org days, inclusive; default this week).
     */
    public function stats(Request $request, TimeClockStats $stats): JsonResponse
    {
        $this->authorize('viewAny', TimeClockShift::class);

        if (! $this->timeClock->organizationEnabled($request->user()->organization)) {
            abort(409, 'Clock in is switched off for this organization.');
        }

        $from = $request->query('from');
        $to = $request->query('to');
        $user = $request->query('user');

        return response()->json(['data' => $stats->for(
            $request->user(),
            is_string($from) ? $from : null,
            is_string($to) ? $to : null,
            // One person, with their days, for the Dashboard's panel.
            is_string($user) && $user !== '' ? $user : null,
        )]);
    }

    public function clockIn(Request $request): JsonResponse
    {
        return response()->json([
            'data' => ['time_clock' => $this->timeClock->clockIn($request->user(), position: $this->position($request))],
        ]);
    }

    /** The device's fix, when the client sent one (only where a clock-in zone applies). */
    private function position(Request $request): ?array
    {
        $data = $request->validate([
            'latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180'],
            'accuracy' => ['nullable', 'numeric', 'min:0'],
        ]);

        return isset($data['latitude'], $data['longitude'])
            ? ['latitude' => (float) $data['latitude'], 'longitude' => (float) $data['longitude'], 'accuracy' => isset($data['accuracy']) ? (float) $data['accuracy'] : null]
            : null;
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
            'data' => ['time_clock' => $this->timeClock->clockOut($request->user(), position: $this->position($request))],
        ]);
    }

    /**
     * PATCH /api/v1/hr/time-clock/shifts/{shift} — fix the times (2026-09-23).
     *
     * Both times are optional and absent means UNCHANGED, which is not the
     * same as null: sending `clocked_out_at: null` for an open shift leaves it
     * open, and for a finished one is refused rather than reopening it.
     *
     * The times arrive as instants, not wall clocks. The screen shows a
     * person's day in the organization's zone and sends back UTC, so a
     * correction made from a laptop in another timezone means the same minute.
     */
    public function update(Request $request, TimeClockShift $shift): JsonResponse
    {
        $this->authorize('correct', TimeClockShift::class);

        $data = $request->validate([
            'clocked_in_at' => ['sometimes', 'date'],
            'clocked_out_at' => ['sometimes', 'nullable', 'date'],
        ]);

        /*
         * Reopening is refused HERE and not in TimeClock::correct(), because
         * this is the only layer that can tell the two cases apart: an absent
         * `clocked_out_at` means "leave it alone" and an explicit null means
         * "take the end off", and both arrive at the domain method as null.
         * Putting the check below would have made it unreachable from the only
         * caller there is.
         */
        if (array_key_exists('clocked_out_at', $data) && $data['clocked_out_at'] === null && $shift->clocked_out_at !== null) {
            throw ValidationException::withMessages([
                'clocked_out_at' => 'A finished shift cannot be reopened. Delete it instead.',
            ]);
        }

        /*
         * Either an instant with its offset ("…Z") or a bare "Y-m-d H:i", which
         * is wall clock in the zone the screen showed it in. Carbon honours an
         * offset in the string and falls back to the zone given only when there
         * is none, so both shapes are one call.
         */
        $tz = $this->timeClock->viewTimezone($request->user());

        $this->timeClock->correct(
            $request->user(),
            $shift,
            isset($data['clocked_in_at']) ? Carbon::parse($data['clocked_in_at'], $tz)->utc() : null,
            isset($data['clocked_out_at']) ? Carbon::parse($data['clocked_out_at'], $tz)->utc() : null,
        );

        return $this->weekAfterCorrection($request);
    }

    /** DELETE /api/v1/hr/time-clock/shifts/{shift} — the punch that should not exist. */
    public function destroy(Request $request, TimeClockShift $shift): JsonResponse
    {
        $this->authorize('correct', TimeClockShift::class);

        $this->timeClock->remove($request->user(), $shift);

        return $this->weekAfterCorrection($request);
    }

    /** POST /api/v1/hr/time-clock/shifts/{shift}/clock-out — Force clock out, at the server's now. */
    public function forceClockOut(Request $request, TimeClockShift $shift): JsonResponse
    {
        $this->authorize('correct', TimeClockShift::class);

        $this->timeClock->forceClockOut($request->user(), $shift);

        return $this->weekAfterCorrection($request);
    }

    /**
     * PUT /api/v1/hr/time-clock/people/{user}/days/{date} — tag, extra
     * minutes, note. Each key optional; absent is unchanged, null clears.
     */
    /**
     * POST /api/v1/hr/time-clock/people/{user}/shifts — a shift nobody punched.
     * Times are wall clock in the screen's zone, as in update(). Always closed.
     */
    public function addShift(Request $request, User $user): JsonResponse
    {
        $this->authorize('correct', TimeClockShift::class);

        $data = $request->validate([
            'clocked_in_at' => ['required', 'date'],
            'clocked_out_at' => ['required', 'date'],
        ]);

        $tz = $this->timeClock->viewTimezone($request->user());
        $this->timeClock->addShift(
            $request->user(),
            $user,
            Carbon::parse($data['clocked_in_at'], $tz)->utc(),
            Carbon::parse($data['clocked_out_at'], $tz)->utc(),
        );

        return $this->weekAfterCorrection($request);
    }

    public function recordDay(Request $request, User $user, string $date): JsonResponse
    {
        $this->authorize('correct', TimeClockShift::class);

        abort_unless((bool) preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) && strtotime($date) !== false, 404);

        $data = $request->validate([
            'tag' => ['sometimes', 'nullable', Rule::in(TimeClockDay::TAGS)],
            'extra_minutes' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:1440'],
            'note' => ['sometimes', 'nullable', 'string', 'max:2000'],
        ]);

        $this->timeClock->recordDay($request->user(), $user, $date, $data);

        return $this->weekAfterCorrection($request);
    }

    /**
     * Both corrections answer with the week the screen is already showing.
     *
     * A correction changes a total, a days-worked count and an average, none
     * of which the client can recompute from the row it sent — so returning
     * the row would leave three numbers on screen that quietly disagree with
     * the database until something else refetched.
     */
    private function weekAfterCorrection(Request $request): JsonResponse
    {
        $on = $request->query('on');

        return response()->json([
            'data' => $this->timeClock->weekList($request->user(), is_string($on) ? $on : null, inView: true),
        ]);
    }
}
