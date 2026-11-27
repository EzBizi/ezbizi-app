<?php

namespace App\Http\Controllers\Api;

use App\Support\Reports\ReportView;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * GET /api/v1/reports/recruiting?from&to
 *
 * Shoval, 2026-11-18: "Recruiting report - expenses per rule, inquiries,
 * response time, interviews, relevant candidates. In person interviews,
 * hiring amounts, cost per position field". One row per position, on HR's
 * recruiting records (HR, 2026-11-20): the applicants who applied in the
 * dates, how fast the first response came, their interviews (held), how many
 * were judged relevant and how many were hired; the recruiting spend booked
 * for the months the dates touch; cost per hire. An applicant or a spend line
 * with no branch is the organization's: only its view counts it.
 */
class RecruitingReportController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        $q = $request->validate([
            'from' => ['required', 'date_format:Y-m-d'],
            'to' => ['required', 'date_format:Y-m-d', 'after_or_equal:from'],
        ]);
        $org = app(TenantContext::class)->idOrFail();
        $view = ReportView::franchiseIds();
        $zone = ReportView::zone();
        $range = [CarbonImmutable::parse($q['from'], $zone)->utc(), CarbonImmutable::parse($q['to'], $zone)->endOfDay()->utc()];

        $applicants = DB::table('applicants')->where('organization_id', $org)->when($view, fn ($w, $ids) => $w->whereIn('franchise_id', $ids))
            ->whereBetween('applied_at', $range)->get(['id', 'position', 'stage', 'relevant', 'applied_at', 'first_response_at', 'hired_user_id']);
        $interviews = DB::table('applicant_interviews')->whereIn('applicant_id', $applicants->pluck('id'))->where('held', true)
            ->get(['applicant_id', 'kind'])->groupBy('applicant_id');
        // Spend is booked by month: every month the dates touch.
        $spend = DB::table('recruiting_spend')->where('organization_id', $org)->when($view, fn ($w, $ids) => $w->whereIn('franchise_id', $ids))
            ->whereBetween('month', [CarbonImmutable::parse($q['from'])->startOfMonth()->toDateString(), CarbonImmutable::parse($q['to'])->toDateString()])
            ->groupBy('position')->selectRaw('position, sum(amount) as amount')->pluck('amount', 'position');

        $row = function (Collection $mine, float $spent) use ($interviews) {
            $hours = $mine->filter(fn (object $a) => $a->first_response_at !== null)
                ->map(fn (object $a) => CarbonImmutable::parse($a->applied_at, 'UTC')->diffInMinutes(CarbonImmutable::parse($a->first_response_at, 'UTC')) / 60);
            $held = $mine->flatMap(fn (object $a) => $interviews[$a->id] ?? []);
            $hires = $mine->filter(fn (object $a) => $a->stage === 'hired' || $a->hired_user_id !== null)->count();

            return [
                'spend_minor' => (int) round($spent * 100),
                'inquiries' => $mine->count(),
                'avg_hours_to_respond' => $hours->isEmpty() ? null : round($hours->avg(), 1),
                'not_responded' => $mine->count() - $hours->count(),
                'interviews' => $held->count(),
                'in_person' => $held->where('kind', 'in_person')->count(),
                'relevant' => $mine->filter(fn (object $a) => (bool) $a->relevant)->count(),
                'hires' => $hires,
                'cost_per_hire_minor' => $hires === 0 ? null : (int) round($spent * 100 / $hires),
            ];
        };

        $positions = $applicants->pluck('position')->concat($spend->keys())->map(fn ($p) => (string) $p)->unique()->sort()->values();
        $rows = $positions->map(fn (string $p) => ['position' => $p === '' ? null : $p]
            + $row($applicants->filter(fn (object $a) => (string) $a->position === $p), (float) ($spend[$p] ?? 0)))->values();

        return response()->json(['data' => [
            'rows' => $rows,
            'totals' => $row($applicants, (float) $spend->sum()),
        ]]);
    }
}
