<?php

namespace App\Http\Controllers\Api;

use App\Models\SavedReport;
use App\Support\Reports\Experimental\CallReconciliation;
use App\Support\Reports\Experimental\RingCentralCallLog;
use App\Support\Reports\Experimental\WorkizJobs;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * One day of A-1's calls against the jobs they became (TEMPORARY).
 *
 * This whole controller goes when EzBizi's own Phone and Jobs modules answer
 * the question. It reads two outside vendors and nothing in EzBizi's database,
 * which is why it has no scope rules: there is nothing tenant-shaped to scope.
 *
 * ── It is gated on `reports.build`, not on the module ────────────
 *
 * The payload names every member of staff and how many calls they took. That
 * is the disclosure this module already got wrong once by reasoning about
 * where a record lives instead of who may see it. `reports.build` is the
 * permission that already means "may see across the whole organization", so it
 * is the honest gate — running a saved report is a weaker right and must not
 * open this.
 *
 * ── A DAY IS A TIMEZONE, and a wrong one moves every number ──────
 *
 * There is no organization timezone column, so the zone comes from the request
 * and falls back to the application's. It is ECHOED BACK in the payload: the
 * boundary that produced these counts has to be visible, because "Monday" an
 * hour out silently moves calls between days and nothing about the result
 * would look wrong.
 */
class ExperimentalCallReportController extends Controller
{
    /**
     * A booking can be entered AFTER the appointment, so the window reaches back.
     *
     * Workiz's `start_date` filters the appointment date, not the booking date,
     * so a day's bookings have to be found inside a window of appointments.
     * Measured across all nine accounts: six jobs have an appointment earlier
     * than their booking, the worst by 13 days. 30 gives room and costs 17
     * requests for ~840 rows.
     */
    public const LOOKBACK_DAYS = 30;

    public function show(Request $request, RingCentralCallLog $calls, WorkizJobs $workiz, CallReconciliation $reconcile): JsonResponse
    {
        // Organization-wide by nature: it names everyone and belongs to no
        // company or franchise, so this is the organization gate, not the
        // place-scoped one.
        $this->authorize('createOnOrganization', SavedReport::class);

        $validated = $request->validate([
            'day' => ['nullable', 'date_format:Y-m-d'],
            'timezone' => ['nullable', 'string', 'timezone'],
        ]);

        $zone = $validated['timezone'] ?? (string) config('app.timezone');
        $day = CarbonImmutable::parse($validated['day'] ?? 'today', $zone)->startOfDay();

        // Both vendors are optional and their absence is a STATE. An empty
        // report and a missing connection look identical and mean opposite
        // things, so the screen is told which it is holding.
        if (! $calls->configured()) {
            return response()->json([
                'connected' => false,
                'missing' => 'RingCentral',
                'day' => $day->toDateString(),
                'timezone' => $zone,
            ]);
        }

        $legs = $calls->forDate($day->toIso8601String(), $day->endOfDay()->toIso8601String());

        $jobs = [];
        $accounts = $workiz->accounts();
        $failed = [];

        if ($workiz->configured()) {
            try {
                $result = $workiz->all(1000, $day->subDays(self::LOOKBACK_DAYS)->toDateString());
                $jobs = $result['jobs'];
                $failed = $result['failed'];
            } catch (RuntimeException $e) {
                // Every account refused. The calls are still worth showing, and
                // the jobs column being blank must not read as "nobody booked
                // anything" — `workiz_failed` is what the screen says instead.
                $failed = array_fill_keys($accounts, $e->getMessage());
            }
        }

        return response()->json([
            'connected' => true,
            'timezone' => $zone,

            // Which Workiz accounts this number covers, BY NAME. The accounts
            // are disjoint and the same people book into all of them, so a
            // report missing one gets that person's job count wrong rather
            // than merely missing a region.
            'workiz_accounts' => $accounts,
            'workiz_failed' => (object) $failed,

            ...$reconcile->forDay($legs, $jobs, $day->toDateString()),
        ]);
    }
}
