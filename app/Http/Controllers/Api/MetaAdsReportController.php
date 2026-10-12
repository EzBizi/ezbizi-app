<?php

namespace App\Http\Controllers\Api;

use App\Models\MetaAdCampaign;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Facebook ad spend per campaign (Jose, 2026-10-12: "Facebook Financial
 * report + Retro filter by state and category"), from the Meta module's
 * daily insights (meta_ad_insights, meta_ad_campaigns).
 *
 * Region = '' rows are each day's totals; the others are Meta's "where
 * viewers were" breakdown. One or the other is summed, never both, or every
 * figure doubles.
 */
class MetaAdsReportController extends Controller
{
    /** GET /api/v1/reports/meta?from&to[&state&category&region] */
    public function show(Request $request): JsonResponse
    {
        $q = $request->validate([
            'from' => ['required', 'date_format:Y-m-d'],
            'to' => ['required', 'date_format:Y-m-d', 'after_or_equal:from'],
            'state' => ['nullable', 'string', 'size:2'],
            'category' => ['nullable', 'string', 'max:64'],
            'region' => ['nullable', 'string', 'max:64'],
        ]);

        $rows = DB::table('meta_ad_insights as i')
            ->join('meta_ad_campaigns as c', fn ($j) => $j->on('c.connection_id', '=', 'i.connection_id')->on('c.campaign_id', '=', 'i.campaign_id'))
            ->leftJoin('service_groups as g', 'g.id', '=', 'c.service_group_id')
            ->leftJoin('franchises as f', 'f.id', '=', 'c.franchise_id')
            ->where('i.organization_id', app(TenantContext::class)->idOrFail())
            ->whereBetween('i.date', [$q['from'], $q['to']])
            ->where('i.region', $q['region'] ?? '')
            ->when($q['state'] ?? null, fn ($w, $s) => $w->where('c.state', $s))
            ->when($q['category'] ?? null, fn ($w, $cat) => $cat === 'none' ? $w->whereNull('c.service_group_id') : $w->where('c.service_group_id', $cat))
            ->groupBy('c.id', 'c.name', 'c.state', 'c.service_group_id', 'g.name', 'f.name')
            ->selectRaw('c.id, c.name, c.state, c.service_group_id, g.name category, f.name branch,
                round(sum(i.spend) * 100) spend_minor, sum(i.leads) leads, sum(i.clicks) clicks, sum(i.impressions) impressions')
            ->orderByDesc('spend_minor')
            ->get()
            ->map(fn ($r) => [
                'id' => $r->id, 'name' => $r->name, 'state' => $r->state, 'category' => $r->category,
                'branch' => $r->branch, 'spend_minor' => (int) $r->spend_minor, 'leads' => (int) $r->leads,
                'cost_per_lead_minor' => $r->leads > 0 ? intdiv((int) $r->spend_minor, (int) $r->leads) : null,
                'clicks' => (int) $r->clicks, 'impressions' => (int) $r->impressions,
            ]);

        return response()->json(['data' => [
            'rows' => $rows,
            'states' => MetaAdCampaign::query()->whereNotNull('state')->distinct()->orderBy('state')->pluck('state'),
            'categories' => DB::table('service_groups')->whereIn('id', MetaAdCampaign::query()->select('service_group_id'))->orderBy('name')->get(['id', 'name']),
            'regions' => DB::table('meta_ad_insights')->where('organization_id', app(TenantContext::class)->idOrFail())
                ->where('region', '!=', '')->distinct()->orderBy('region')->pluck('region'),
        ]]);
    }

    /** GET /api/v1/reports/meta/campaigns: the mapping editor's list. */
    public function campaigns(): JsonResponse
    {
        return response()->json(['data' => MetaAdCampaign::query()->orderBy('name')->get()->map(fn ($c) => [
            'id' => $c->id, 'name' => $c->name, 'state' => $c->state,
            'service_group_id' => $c->service_group_id, 'franchise_id' => $c->franchise_id,
            // Grok's guess (ezbizi:suggest-meta-categories); never applied by itself.
            'suggested_service_group_id' => $c->getAttribute('suggested_service_group_id'),
        ])]);
    }

    /** PUT /api/v1/reports/meta/campaigns/{campaign}: staff set state, category, branch. */
    public function update(Request $request, MetaAdCampaign $campaign): JsonResponse
    {
        $campaign->update($request->validate([
            'state' => ['sometimes', 'nullable', 'string', 'size:2'],
            'service_group_id' => ['sometimes', 'nullable', Rule::exists('service_groups', 'id')->where('organization_id', $campaign->organization_id)],
            'franchise_id' => ['sometimes', 'nullable', Rule::exists('franchises', 'id')->where('organization_id', $campaign->organization_id)],
        ]));

        return response()->json(['data' => ['id' => $campaign->id]]);
    }
}
