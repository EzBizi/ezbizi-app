<?php

namespace App\Http\Controllers\Api;

use App\Models\CallReportConversion;
use App\Models\CallReportDispatcherTag;
use App\Models\CallReportLabel;
use App\Models\CallReportScore;
use App\Models\CallReportTranscript;
use App\Models\SavedReport;
use App\Models\WorkizCall;
use App\Support\Audit\AuditLogger;
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
 * ── It is gated on its tab, reports.experimental.view ────────────
 *
 * It names every member of staff and how many calls they took, across every
 * branch. It once also asked for organization-level reach, which refused
 * people assigned to one company; Jose, 2026-10-15: they "should be able to
 * see it regardless". Who holds the tab is decided in Roles.
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
    public function __construct(private readonly AuditLogger $audit) {}

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
     * ── EMPTY SINCE 2026-10-06 ──────────────────────────────────
     *
     * Massachusetts got its own Workiz account on the v2 API and is synced
     * like the others (63 jobs on 2026-10-05). The list stays, empty, because
     * the next account without API access is the case it exists for - and a
     * screen still saying "Massachusetts is never included" while counting
     * its jobs is the stale claim this caught.
     *
     * @var list<array{name: string, reason: string}>
     */
    public const UNREACHABLE = [];

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

        $validated = $request->validate([
            'day' => ['nullable', 'date_format:Y-m-d'],
            'period' => ['nullable', 'in:day,week,month'],
            // Any range, or one date (from = to): Jose, 2026-10-06, "From date
            // to date / Or specific date". Up to a quarter, so a range cannot
            // be asked of RingCentral by the year.
            'from' => ['nullable', 'date_format:Y-m-d', 'required_with:to'],
            'to' => ['nullable', 'date_format:Y-m-d', 'required_with:from', 'after_or_equal:from'],
            'timezone' => ['nullable', 'string', 'timezone'],
        ]);

        $zone = $validated['timezone'] ?? (string) config('app.timezone');
        $day = CarbonImmutable::parse($validated['day'] ?? 'today', $zone)->startOfDay();

        /*
         * THE PERIOD the day falls in (Jose, 2026-10-05: "add options to choose
         * period of time"). Weeks run Monday to Sunday, as the Work Hours report
         * does; a month is the calendar month.
         */
        $period = $validated['period'] ?? 'day';
        [$start, $end] = match ($period) {
            'week' => [$day->startOfWeek(CarbonImmutable::MONDAY), $day->endOfWeek(CarbonImmutable::SUNDAY)],
            'month' => [$day->startOfMonth(), $day->endOfMonth()],
            default => [$day, $day->endOfDay()],
        };
        if (isset($validated['from'], $validated['to'])) {
            $period = 'custom';
            $start = CarbonImmutable::parse($validated['from'], $zone);
            $end = CarbonImmutable::parse($validated['to'], $zone);

            if ($start->diffInDays($end) > 92) {
                abort(Status::HTTP_UNPROCESSABLE_ENTITY, 'Choose a range of three months or less.');
            }
        }

        $start = $start->startOfDay();
        $end = $end->endOfDay();

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

        $legs = $calls->forPeriod($start, $end, outbound: true);

        $conversions = CallReportConversion::query()
            ->with('converter:id,name')
            ->whereBetween('call_started_at', [$start->utc(), $end->utc()])
            ->latest()
            ->get();

        $jobs = [];
        // Every account, whoever reads: the tab permission is the gate (Jose,
        // 2026-10-15), not the reader's branch.
        $accounts = $workiz->allAccounts();
        $failed = [];

        $syncedAt = null;

        if ($accounts !== []) {
            // The synced copy, not Workiz live - see WorkizJobs::stored().
            $stored = $workiz->storedAll($start->subDays(self::LOOKBACK_DAYS)->toDateString());
            $jobs = $stored['jobs'];
            $syncedAt = $stored['synced_at'];
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

            // When the synced copy last heard from Workiz, so a stale copy
            // shows rather than reading as a quiet day.
            'workiz_synced_at' => $syncedAt,

            'period' => $period,

            // Workiz's own AI read of its phone calls, per person (Jose, via
            // Grok AI, 2026-10-12): shown beside our call scores. Only the
            // branches Workiz scores (DMV, Illinois, New York) have any.
            // called_at is the account's wall clock, so dates, not instants.
            'workiz_scores' => (object) WorkizCall::query()
                ->whereBetween('called_at', [$start->toDateString().' 00:00:00', $end->toDateString().' 23:59:59'])
                ->whereNotNull('dispatcher_score')
                ->whereNotNull('user_name')
                ->groupBy('user_name')
                ->selectRaw('user_name, count(*) calls, avg(dispatcher_score) score, avg(booked) booked_rate,
                    avg(pricing_discussed) pricing, avg(sentiment in (?, ?)) positive', ['great', 'positive'])
                ->get()
                ->mapWithKeys(fn ($r) => [$r->user_name => [
                    'calls' => (int) $r->calls,
                    'score' => round((float) $r->score),
                    'booked' => $r->booked_rate === null ? null : round($r->booked_rate * 100),
                    'pricing' => $r->pricing === null ? null : round($r->pricing * 100),
                    'positive' => round((float) $r->positive * 100),
                ]])
                ->all(),

            // Who marked which call as booked in this period, newest first:
            // the not-booked-to-booked history.
            'conversions' => $conversions->map(fn (CallReportConversion $c) => [
                'call_id' => $c->call_id,
                'call_started_at' => $c->call_started_at?->toIso8601String(),
                'converted_at' => $c->created_at?->toIso8601String(),
                'converted_by' => $c->converter?->name,
            ])->values()->all(),

            ...$this->withTranscripts($reconcile->forRange(
                $legs,
                $jobs,
                $start->toDateString(),
                $end->toDateString(),
                $conversions->pluck('call_id')->all(),
                CallReportDispatcherTag::query()->pluck('tag', 'person')->all(),
            )),
        ]);
    }

    /**
     * Each call's words, where `ezbizi:transcribe-report-calls` has them. Null
     * means not transcribed (yet, or it could not be done) - never an empty
     * call, which is an empty string.
     */
    private function withTranscripts(array $day): array
    {
        $ids = array_column($day['calls'] ?? [], 'id');

        // Split by speaker when Grok has done it; the raw words until then.
        $words = CallReportTranscript::query()->whereIn('call_id', $ids)
            ->get(['call_id', 'transcript', 'transcript_labelled'])
            ->mapWithKeys(fn ($row) => [$row->call_id => $row->transcript_labelled ?: $row->transcript]);

        // Each provider's label for the call, side by side for comparing.
        $labels = [];
        foreach (CallReportLabel::query()->whereIn('call_id', $ids)->get(['call_id', 'provider', 'label']) as $row) {
            $labels[$row->call_id][$row->provider] = $row->label;
        }

        // AI's coaching read of the call (ezbizi:score-report-calls), if any.
        $scores = CallReportScore::query()->whereIn('call_id', $ids)->whereNotNull('score')
            ->get(['call_id', 'score', 'checks', 'strength', 'improve'])
            ->keyBy('call_id');

        // Our number the call was on, by RingCentral's own label for it
        // ("DFW - LS Ads"): Bar, via Jose, 2026-10-12, wants a missed call's
        // row to say which line was rung.
        $lines = app(RingCentralCallLog::class)->numberNames();

        $day['calls'] = array_map(
            fn (array $call) => $call + [
                'to_name' => $lines[$call['to_number'] ?? ''] ?? $call['queue'] ?? null,
                'transcript' => $words[$call['id']] ?? null,
                'labels' => (object) ($labels[$call['id']] ?? []),
                'score' => isset($scores[$call['id']])
                    ? $scores[$call['id']]->only(['score', 'checks', 'strength', 'improve'])
                    : null,
            ],
            $day['calls'] ?? [],
        );

        return $day;
    }

    /**
     * PUT reports/experimental/sources - which Workiz accounts this person's
     * Reports count (review 2026-10-30, item 12). Per person: a viewing
     * choice that changes nobody else's figures. Stored as the unticked ones,
     * so an account connected later is counted until somebody leaves it out.
     */
    public function sources(Request $request, WorkizJobs $workiz): JsonResponse
    {
        $known = array_column($workiz->allAccounts(), 'id');
        $data = $request->validate([
            'excluded' => ['present', 'array'],
            'excluded.*' => ['string', 'in:'.implode(',', $known)],
        ]);

        $user = $request->user();
        $user->forceFill(['preferences' => [...($user->preferences ?? []), WorkizJobs::EXCLUDED => array_values(array_unique($data['excluded']))]])->save();

        return response()->json(['data' => $workiz->allAccounts()]);
    }

    /**
     * PUT reports/experimental/people/tag - a dispatcher's New / Existing tag,
     * set by hand (Jose, 2026-10-05). A null tag clears it.
     */
    public function tag(Request $request): JsonResponse
    {
        // Writes stay organization-wide (reports.build); reading needs only the tab.
        $this->authorize('createOnOrganization', SavedReport::class);

        $data = $request->validate([
            'person' => ['required', 'string', 'max:191'],
            'tag' => ['nullable', 'in:'.implode(',', CallReportDispatcherTag::TAGS)],
        ]);

        if ($data['tag'] === null) {
            CallReportDispatcherTag::query()->where('person', $data['person'])->delete();
        } else {
            CallReportDispatcherTag::query()->updateOrCreate(
                ['organization_id' => $request->user()->organization_id, 'person' => $data['person']],
                ['tag' => $data['tag']],
            );
        }

        $this->audit->log('call_report.tag_set', null, metadata: $data);

        return response()->json(['data' => ['person' => $data['person'], 'tag' => $data['tag']]]);
    }

    /**
     * POST reports/experimental/calls/{call}/convert - "Convert to booked":
     * a dispatcher marks a not-booked call as booked by hand, and the row is
     * its history line (Jose, 2026-10-05: manual mark + history).
     */
    public function convert(Request $request, string $call): JsonResponse
    {
        // Writes stay organization-wide (reports.build); reading needs only the tab.
        $this->authorize('createOnOrganization', SavedReport::class);

        $data = $request->validate([
            'started_at' => ['nullable', 'date'],
        ]);

        $row = CallReportConversion::query()->firstOrCreate(
            ['organization_id' => $request->user()->organization_id, 'call_id' => $call],
            [
                'call_started_at' => isset($data['started_at']) ? CarbonImmutable::parse($data['started_at']) : null,
                'converted_by' => $request->user()->getKey(),
            ],
        );

        $this->audit->log('call_report.converted', $row, metadata: ['call_id' => $call]);

        return response()->json(['data' => [
            'call_id' => $row->call_id,
            'converted_at' => $row->created_at?->toIso8601String(),
            'converted_by' => $row->converter?->name,
        ]]);
    }
}
