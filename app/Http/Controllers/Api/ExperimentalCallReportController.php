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
use Symfony\Component\HttpFoundation\Response as Status;

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

    /**
     * Books that exist, are missing from every total, and always will be.
     *
     * Jose, 2026-09-15, asked why Massachusetts' Workiz Developer page refuses
     * him: **"we dont pay the api access there"**. Workiz sells API access per
     * account and A-1 does not buy it for MA. So there is no token because
     * there is no subscription — not a wound-down account, not an access
     * problem somebody will get round to.
     *
     * THAT MAKES THE UNDERCOUNT PERMANENT AND STRUCTURAL. MA has a book, nobody
     * disputes it exists, and this report will never read it. The same people
     * book into every account, so the missing rows do not merely omit a region
     * — they make individual people's numbers LOW, by an unknown amount,
     * forever, with nothing on the screen saying so unless this says it.
     *
     * Which is why it is named rather than simply absent. "Not connected" reads
     * as a task; "no API subscription" retires the question.
     *
     * @var list<array{name: string, reason: string}>
     */
    public const UNREACHABLE = [
        [
            'name' => 'Massachusetts',
            'reason' => 'Workiz API access is not paid for on that account, so it has no token and never will under the current subscription.',
        ],
    ];

    /**
     * GET /api/v1/reports/experimental/calls/{recording}/recording
     *
     * The audio of one call, proxied.
     *
     * ── WHY THIS IS A PROXY AND NOT A LINK ──────────────────────
     *
     * RingCentral's `contentUri` points at media.ringcentral.com and needs our
     * account's bearer token. A browser following it unauthenticated gets 401;
     * putting the token in the URL to fix that would hand every listener the
     * whole account, in a string that lands in browser history and any log
     * that records URLs. So the audio comes through here, where the gate is
     * the same one that guards the report.
     *
     * ── IT CURRENTLY REFUSES, AND THAT IS A FACT ABOUT THE APP ──
     *
     * Measured 2026-09-17: the recordings exist - 175 of 250 inbound calls
     * over three days carry one - and fetching the audio returns 403
     * `ReadCallRecording`. EzBizi's RingCentral app has not been granted that
     * permission. Nothing in code fixes it; somebody adds the scope to the app
     * in RingCentral's console and this starts working unchanged.
     *
     * Until then the refusal SAYS THAT, rather than arriving as a dead player
     * that sends somebody looking for a bug in EzBizi.
     */
    public function recording(Request $request, string $recording, RingCentralCallLog $calls): mixed
    {
        $this->authorize('createOnOrganization', SavedReport::class);

        try {
            $audio = $calls->recording($recording);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], Status::HTTP_BAD_GATEWAY);
        }

        return response($audio['body'], 200, [
            'Content-Type' => $audio['type'],

            // Inline: this is played in place, not downloaded. A download
            // disposition would put customer call audio in the Downloads
            // folder of anybody who clicked once.
            'Content-Disposition' => 'inline',
        ]);
    }

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
        $accounts = $workiz->accounts($request->user());
        $failed = [];

        if ($workiz->configured($request->user())) {
            try {
                $result = $workiz->all(
                    $request->user(),
                    1000,
                    $day->subDays(self::LOOKBACK_DAYS)->toDateString(),
                );
                $jobs = $result['jobs'];
                $failed = $result['failed'];
            } catch (RuntimeException $e) {
                // Every account refused. The calls are still worth showing, and
                // the jobs column being blank must not read as "nobody booked
                // anything" — `workiz_failed` is what the screen says instead.
                // array_column, because accounts() carries {id, name} maps
                // since the disconnect chips needed the id - and array_fill_keys
                // with array keys is a TypeError, not a wrong answer. This arm
                // only runs when EVERY account refuses, which is why nothing
                // caught it: the one test here refuses a single account.
                $failed = array_fill_keys(array_column($accounts, 'name'), $e->getMessage());
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

            // Named, because "not connected" invites somebody to go and connect
            // it and spend an afternoon learning what Jose answered in nine
            // words. See UNREACHABLE.
            'workiz_unreachable' => self::UNREACHABLE,

            ...$reconcile->forDay($legs, $jobs, $day->toDateString()),
        ]);
    }
}
