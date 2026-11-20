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
 * GET /api/v1/reports/dispatch?from&to
 *
 * Shoval, 2026-11-18: "Report on dispatch (msg, emails, leads response time,
 * existing report on calls)". One row per dispatcher (the lead's dispatcher;
 * the row with none is the leads nobody took), over the leads received in the
 * dates: how many, how many came as a message (anything but a phone call:
 * texts, chat, forms, bookings, email), how many of those got a reply from
 * us, and how long the first reply took. Calls stay in Performance, which
 * already scores them per dispatcher. A reply is a conversation from us that
 * a person sent (not an automatic one) after the lead came in.
 */
class DispatchReportController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        $q = $request->validate([
            'from' => ['required', 'date_format:Y-m-d'],
            'to' => ['required', 'date_format:Y-m-d', 'after_or_equal:from'],
        ]);
        abort_if(CarbonImmutable::parse($q['from'])->diffInDays(CarbonImmutable::parse($q['to'])) > 92, 422, 'Choose a range of three months or less.');
        $org = app(TenantContext::class)->idOrFail();
        $zone = ReportView::zone();

        $leads = DB::table('leads')->where('organization_id', $org)->where('is_test', false)->whereNull('hidden_at')
            ->when(ReportView::franchiseIds(), fn ($w, $ids) => $w->whereIn('franchise_id', $ids))
            ->whereBetween('received_at', [CarbonImmutable::parse($q['from'], $zone)->utc(), CarbonImmutable::parse($q['to'], $zone)->endOfDay()->utc()])
            ->get(['id', 'dispatcher', 'lead_type', 'received_at']);
        $messages = $leads->filter(fn (object $l) => mb_strtoupper((string) $l->lead_type) !== 'PHONE_CALL');

        // The first reply a person sent to each message lead, after it came in.
        $first = [];
        foreach ($messages->pluck('id')->chunk(1000) as $ids) {
            foreach (DB::table('lead_conversations as c')->join('leads as l', 'l.id', '=', 'c.lead_id')->whereIn('c.lead_id', $ids->all())
                ->where('c.participant', 'ADVERTISER')->where('c.is_automatic', false)->whereColumn('c.occurred_at', '>=', 'l.received_at')
                ->groupBy('c.lead_id')->selectRaw('c.lead_id, min(c.occurred_at) as at')->get() as $r) {
                $first[$r->lead_id] = (string) $r->at;
            }
        }
        $minutes = fn (object $l) => isset($first[$l->id])
            ? CarbonImmutable::parse($l->received_at, 'UTC')->diffInMinutes(CarbonImmutable::parse($first[$l->id], 'UTC')) : null;

        $row = function (Collection $all) use ($minutes) {
            $msgs = $all->filter(fn (object $l) => mb_strtoupper((string) $l->lead_type) !== 'PHONE_CALL');
            $took = $msgs->map($minutes)->filter(fn ($m) => $m !== null)->sort()->values();

            return [
                'leads' => $all->count(),
                'calls' => $all->count() - $msgs->count(),
                'messages' => $msgs->count(),
                'answered' => $took->count(),
                'unanswered' => $msgs->count() - $took->count(),
                'avg_minutes_to_answer' => $took->isEmpty() ? null : (int) round($took->avg()),
                'median_minutes_to_answer' => $took->isEmpty() ? null : (int) round($took->median()),
            ];
        };

        $rows = $leads->groupBy(fn (object $l) => trim((string) $l->dispatcher))
            ->map(fn (Collection $mine, string $name) => ['dispatcher' => $name === '' ? null : $name] + $row($mine))
            ->sortByDesc('leads')->values();

        return response()->json(['data' => [
            'rows' => $rows,
            'totals' => $row($leads),
        ]]);
    }
}
