<?php

namespace App\Http\Controllers\Api;

use App\Support\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * GET /api/v1/reports/thumbtack?from&to[&category&branch]
 *
 * Thumbtack lead spend per category and branch (Jose, 2026-10-13: "add the
 * Thumbtack financials as well"), from the price and charge state the Leads
 * module keeps on each lead. Spend counts CHARGED leads only; a CREDITED one
 * was refunded. Only leads EzBizi received are here: Thumbtack cannot list
 * the rest, so this is not the whole Thumbtack bill (the screen says so).
 */
class ThumbtackReportController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        $q = $request->validate([
            'from' => ['required', 'date_format:Y-m-d'],
            'to' => ['required', 'date_format:Y-m-d', 'after_or_equal:from'],
            'category' => ['nullable', 'string', 'max:191'],
            'branch' => ['nullable', 'string', 'max:64'],
            'state' => ['nullable', 'string', 'size:2'],
        ]);

        $leads = fn () => DB::table('leads')
            ->where('leads.organization_id', app(TenantContext::class)->idOrFail())
            ->where('leads.source', 'thumbtack')
            ->where('leads.is_test', false);

        $rows = $leads()
            ->leftJoin('franchises as f', 'f.id', '=', 'leads.franchise_id')
            ->whereBetween('leads.received_at', [$q['from'].' 00:00:00', $q['to'].' 23:59:59'])
            ->when($q['category'] ?? null, fn ($w, $c) => $w->where('leads.category', $c))
            ->when($q['state'] ?? null, fn ($w, $s) => $w->where('leads.service_state', $s))
            ->when($q['branch'] ?? null, fn ($w, $b) => $b === 'none' ? $w->whereNull('leads.franchise_id') : $w->where('leads.franchise_id', $b))
            ->groupBy('leads.category', 'leads.service_state', 'f.name')
            ->selectRaw("leads.category, leads.service_state state, f.name branch, count(*) leads,
                sum(leads.charge_status = 'CHARGED') charged,
                sum(leads.charge_status = 'CREDITED') credited,
                sum(case when leads.charge_status = 'CHARGED' then leads.lead_cost_minor else 0 end) spend_minor,
                sum(leads.job_id is not null) booked")
            ->orderByDesc('spend_minor')
            ->get()
            ->map(fn ($r) => [
                'category' => $r->category,
                'state' => $r->state,
                'branch' => $r->branch,
                'leads' => (int) $r->leads,
                'charged' => (int) $r->charged,
                'credited' => (int) $r->credited,
                'spend_minor' => (int) $r->spend_minor,
                'cost_per_lead_minor' => $r->charged > 0 ? intdiv((int) $r->spend_minor, (int) $r->charged) : null,
                'booked' => (int) $r->booked,
                'cost_per_booked_minor' => $r->booked > 0 ? intdiv((int) $r->spend_minor, (int) $r->booked) : null,
            ]);

        return response()->json(['data' => [
            'rows' => $rows,
            'states' => $leads()->whereNotNull('service_state')->distinct()->orderBy('service_state')->pluck('service_state'),
            'categories' => $leads()->whereNotNull('category')->distinct()->orderBy('category')->pluck('category'),
            'branches' => $leads()->join('franchises as f', 'f.id', '=', 'leads.franchise_id')
                ->distinct()->orderBy('f.name')->get(['f.id', 'f.name']),
        ]]);
    }
}
