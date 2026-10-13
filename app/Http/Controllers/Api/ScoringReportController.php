<?php

namespace App\Http\Controllers\Api;

use App\Console\Commands\LabelReportCalls;
use App\Console\Commands\ScoreReportCalls;
use App\Models\WorkizCall;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

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

    public function show(Request $request): JsonResponse
    {
        $q = $request->validate([
            'from' => ['required', 'date_format:Y-m-d'],
            'to' => ['required', 'date_format:Y-m-d', 'after_or_equal:from'],
        ]);

        $zone = (string) config('app.timezone');
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

        $people = $rows->groupBy('answered_by')->map(function ($calls, $person) use ($workiz) {
            $checks = [];
            foreach (ScoreReportCalls::CHECKS as $check) {
                $met = $calls->filter(fn ($c) => (json_decode((string) $c->checks, true)[$check] ?? false) === true)->count();
                $checks[$check] = (int) round($met / $calls->count() * 100);
            }
            $notes = fn (string $field) => $calls->pluck($field)->filter()->unique()->take(3)->values()->all();

            return [
                'person' => $person,
                'calls_scored' => $calls->count(),
                'avg_score' => round($calls->avg('score'), 1),
                'checks' => $checks,
                'strengths' => $notes('strength'),
                'improve' => $notes('improve'),
                'workiz' => isset($workiz[$person])
                    ? ['calls' => (int) $workiz[$person]->calls, 'score' => (int) round($workiz[$person]->score)]
                    : null,
                'rank' => null,
            ];
        })->sortByDesc('avg_score')->values()->all();

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
            'people' => $people,
        ]]);
    }
}
