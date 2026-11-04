<?php

namespace App\Http\Controllers\Api;

use App\Console\Commands\LabelReportCalls;
use App\Console\Commands\ScoreReportCalls;
use App\Models\CallReportOverride;
use App\Models\CallReportPersonReview;
use App\Models\ReportScoringWeight;
use App\Models\WorkizCall;
use App\Support\Reports\Experimental\PerformancePeople;
use App\Support\Reports\Experimental\RingCentralCallLog;
use App\Support\Reports\Experimental\WorkizJobs;
use App\Support\Reports\ReportView;
use App\Support\Reports\Scoring\ScoringWeights;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * GET /api/v1/reports/scoring?from=Y-m-d&to=Y-m-d
 *
 * Each person's AI call scores over the dates, ranked (Jose, 2026-10-13: "the
 * Scoring and Performance of each employee ... as cards ... a ranking
 * system"). Read from the scores and the person stored with each transcript,
 * so it needs no RingCentral call. Nothing before the AI start day.
 */
class ScoringReportController extends Controller
{
    /** Fewer scored calls than this and a person is shown but not ranked. */
    public const MIN_CALLS = 3;

    /**
     * GET /api/v1/reports/scoring/calls?person=&from=&to=[&q=]
     *
     * One person's scored calls, newest first (Jose, 2026-10-14: "the latest
     * 3 calls for each user, with a search bar ... and the individual score of
     * said call"). Three without a search; up to 50 matching one, by the
     * caller's number (digits) or by words said on the call.
     */
    public function calls(Request $request): JsonResponse
    {
        if (ReportView::franchiseIds() !== null && ! app(RingCentralCallLog::class)->configured()) {
            return response()->json(['data' => []]);
        }

        $q = $request->validate([
            'person' => ['required', 'string', 'max:191'],
            'from' => ['required', 'date_format:Y-m-d'],
            'to' => ['required', 'date_format:Y-m-d', 'after_or_equal:from'],
            'q' => ['nullable', 'string', 'max:100'],
        ]);

        $zone = ReportView::zone();
        $from = max(CarbonImmutable::parse($q['from'], $zone)->startOfDay(), LabelReportCalls::aiSince());
        $to = CarbonImmutable::parse($q['to'], $zone)->endOfDay();
        $search = trim((string) ($q['q'] ?? ''));
        $digits = preg_replace('/\D/', '', $search);

        $base = fn () => DB::table('call_report_scores as s')
            ->join('call_report_transcripts as t', fn ($j) => $j->on('t.organization_id', '=', 's.organization_id')->on('t.call_id', '=', 's.call_id'))
            ->where('s.organization_id', app(TenantContext::class)->idOrFail())
            ->whereNotNull('s.score')
            ->where('t.answered_by', $q['person'])
            ->whereBetween('t.call_started_at', [$from->utc(), $to->utc()])
            ->select(['t.call_id', 't.call_started_at', 't.caller_number', 't.recording_id', 's.score', 's.strength', 's.improve', 't.transcript_labelled', 't.transcript']);

        if ($search !== '') {
            $picked = ['match' => $base()
                ->when(strlen($digits) >= 3,
                    fn ($w) => $w->where('t.caller_number', 'like', '%'.$digits.'%'),
                    fn ($w) => $w->where(fn ($x) => $x->where('t.transcript', 'like', '%'.$search.'%')->orWhere('t.transcript_labelled', 'like', '%'.$search.'%')))
                ->orderByDesc('t.call_started_at')->limit(50)->get()];
        } else {
            // The latest three, and the best and worst in the dates to compare
            // them with (Jose, 2026-10-14).
            $picked = [
                'latest' => $base()->orderByDesc('t.call_started_at')->limit(3)->get(),
                'best' => $base()->orderByDesc('s.score')->orderByDesc('t.call_started_at')->limit(1)->get(),
                'worst' => $base()->orderBy('s.score')->orderByDesc('t.call_started_at')->limit(1)->get(),
            ];
        }

        // One row per call; a call that is both latest and best carries both tags.
        $calls = [];
        foreach ($picked as $tag => $rows) {
            foreach ($rows as $r) {
                $calls[$r->call_id] ??= [
                    'call_id' => $r->call_id,
                    'started_at' => CarbonImmutable::parse($r->call_started_at, 'UTC')->toIso8601String(),
                    'caller_number' => $r->caller_number,
                    'recording_id' => $r->recording_id,
                    'score' => (int) $r->score,
                    'strength' => $r->strength,
                    'improve' => $r->improve,
                    'transcript' => $r->transcript_labelled ?: $r->transcript,
                    'tags' => [],
                ];
                $calls[$r->call_id]['tags'][] = $tag;
            }
        }
        // What was set by hand on the call (design 179): call id => field => value, by.
        $set = CallReportOverride::forCalls(array_keys($calls));
        $calls = array_map(fn ($c) => $c + ['set' => $set[$c['call_id']] ?? null], array_values($calls));

        return response()->json(['data' => $calls]);
    }

    /** GET /api/v1/reports/scoring/weights: what each metric counts for, summing to 100. */
    public function weights(): JsonResponse
    {
        return response()->json(['data' => [
            'weights' => ScoringWeights::current(),
            'defaults' => ScoringWeights::DEFAULTS,
        ]]);
    }

    /** PUT /api/v1/reports/scoring/weights {weights: {metric: percent}}: refused unless they sum to 100. */
    public function saveWeights(Request $request): JsonResponse
    {
        $rules = ['weights' => ['required', 'array:'.implode(',', ScoringWeights::metrics())]];
        foreach (ScoringWeights::metrics() as $metric) {
            $rules["weights.$metric"] = ['required', 'integer', 'between:0,100'];
        }
        $weights = $request->validate($rules)['weights'];

        if (array_sum($weights) !== 100) {
            throw ValidationException::withMessages(['weights' => 'The weights must add up to 100%; they add up to '.array_sum($weights).'%.']);
        }

        ReportScoringWeight::query()->updateOrCreate(
            ['organization_id' => app(TenantContext::class)->idOrFail()],
            ['weights' => $weights],
        );

        return $this->weights();
    }

    public function show(Request $request, PerformancePeople $performance): JsonResponse
    {
        // The calls are the RingCentral account's company's: a view outside it
        // has none (2026-10-19).
        if (ReportView::franchiseIds() !== null && ! app(RingCentralCallLog::class)->configured()) {
            return response()->json(['data' => ['from' => $request->query('from'), 'to' => $request->query('to'),
                'min_calls_to_rank' => self::MIN_CALLS, 'weights' => ScoringWeights::current(), 'people' => []]]);
        }

        $q = $request->validate([
            'from' => ['required', 'date_format:Y-m-d'],
            'to' => ['required', 'date_format:Y-m-d', 'after_or_equal:from'],
        ]);

        $zone = ReportView::zone();
        $from = max(CarbonImmutable::parse($q['from'], $zone)->startOfDay(), LabelReportCalls::aiSince());
        $to = CarbonImmutable::parse($q['to'], $zone)->endOfDay();

        $rows = DB::table('call_report_scores as s')
            ->join('call_report_transcripts as t', fn ($j) => $j->on('t.organization_id', '=', 's.organization_id')->on('t.call_id', '=', 's.call_id'))
            ->where('s.organization_id', app(TenantContext::class)->idOrFail())
            ->whereNotNull('s.score')
            ->whereNotNull('t.answered_by')
            ->whereBetween('t.call_started_at', [$from->utc(), $to->utc()])
            ->orderByDesc('t.call_started_at')
            ->get(['t.answered_by', 't.call_id', 't.call_started_at', 's.score', 's.checks', 's.strength', 's.improve']);

        $workiz = WorkizCall::query()
            ->whereBetween('called_at', [$from->toDateString().' 00:00:00', $to->toDateString().' 23:59:59'])
            ->whereNotNull('dispatcher_score')->whereNotNull('user_name')
            ->groupBy('user_name')
            ->selectRaw('user_name, count(*) calls, avg(dispatcher_score) score')
            ->get()->keyBy('user_name');

        // Grok's latest daily and weekly read of each person ending by the
        // last day shown (ezbizi:ai-person-reviews).
        $reviews = CallReportPersonReview::query()
            ->where('to', '<=', $to->toDateString())
            ->orderByDesc('to')
            ->get(['person', 'period', 'from', 'to', 'calls', 'avg_score', 'summary', 'strengths', 'work_on', 'trend'])
            ->groupBy('person');
        $review = fn ($person, $period) => ($r = $reviews->get($person)?->firstWhere('period', $period)) === null ? null : [
            'from' => $r->from->toDateString(), 'to' => $r->to->toDateString(), 'calls' => $r->calls,
            'avg_score' => $r->avg_score, 'summary' => $r->summary, 'strengths' => $r->strengths,
            'work_on' => $r->work_on, 'trend' => $r->trend,
        ];

        // Performance's own People rows, so the conversion rate here is the one
        // Performance shows for the same dates (Jose, 2026-10-14).
        $perf = $performance->for($request->user(), $from, $to);
        $weights = ScoringWeights::current();

        $people = $rows->groupBy('answered_by')->map(function ($calls, $person) use ($workiz, $review, $perf, $weights) {
            $checks = [];
            foreach (ScoreReportCalls::CHECKS as $check) {
                $met = $calls->filter(fn ($c) => (json_decode((string) $c->checks, true)[$check] ?? false) === true)->count();
                $checks[$check] = (int) round($met / $calls->count() * 100);
            }
            $notes = fn (string $field) => $calls->pluck($field)->filter()->unique()->take(3)->values()->all();
            $newClient = (int) ($perf[$person]['new_client'] ?? 0);
            $jobs = (int) ($perf[$person]['jobs'] ?? 0);
            // Booked out of the new-client calls they answered, as Performance's
            // booking rate (Jose, 2026-11-04). Every job they created over those
            // calls ranked Existing-client staff first: 6 jobs on 1 call, 600%.
            // Not judged on it when they handle Existing, or answered too few new
            // calls for a rate: their weight goes to the other measures.
            $judged = ($perf[$person]['tag'] ?? null) !== 'existing' && $newClient >= self::MIN_CALLS;
            $conversion = $judged ? (int) round(($perf[$person]['booked_new'] ?? 0) / $newClient * 100) : null;

            return [
                'person' => $person,
                'calls_scored' => $calls->count(),
                'avg_score' => round($calls->avg('score'), 1),
                // The ranking's number: the weighted metrics, out of 10.
                'score' => ScoringWeights::score(['conversion' => $conversion, 'quality' => $calls->avg('score') * 10, ...$checks], $weights),
                'conversion' => $conversion,
                'new_client' => $newClient,
                'jobs' => $jobs,
                'checks' => $checks,
                'strengths' => $notes('strength'),
                'improve' => $notes('improve'),
                'workiz' => isset($workiz[$person])
                    ? ['calls' => (int) $workiz[$person]->calls, 'score' => (int) round($workiz[$person]->score)]
                    : null,
                'review_daily' => $review($person, 'daily'),
                'review_weekly' => $review($person, 'weekly'),
                'rank' => null,
            ];
        })->sortByDesc(fn ($p) => [$p['score'] ?? -1, $p['avg_score']])->values()->all();

        $rank = 0;
        foreach ($people as &$p) {
            if ($p['calls_scored'] >= self::MIN_CALLS) {
                $p['rank'] = ++$rank;
            }
        }

        return response()->json(['data' => [
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'min_calls_to_rank' => self::MIN_CALLS,
            'weights' => $weights,
            'people' => $people,
            // Its jobs are read from these, the reader's ticked ones only, so
            // the page can say which are left out (UI-UX, 2026-10-30).
            'workiz_accounts' => app(WorkizJobs::class)->allAccounts(),
        ]]);
    }
}
