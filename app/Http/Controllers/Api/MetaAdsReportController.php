<?php

namespace App\Http\Controllers\Api;

use App\Models\MetaAdCampaign;
use App\Support\Reports\MetaSpend;
use App\Support\Reports\ReportView;
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

        $rows = MetaSpend::rows($q['from'], $q['to'], $q['state'] ?? null, $q['category'] ?? null, $q['region'] ?? null);

        return response()->json(['data' => [
            'rows' => $rows,
            'states' => self::inView()->whereNotNull('state')->distinct()->orderBy('state')->pluck('state'),
            'categories' => DB::table('service_groups')->whereIn('id', self::inView()->select('service_group_id'))->orderBy('name')->get(['id', 'name']),
            'regions' => DB::table('meta_ad_insights')->where('organization_id', app(TenantContext::class)->idOrFail())
                ->where('region', '!=', '')
                ->when(ReportView::franchiseIds() !== null, fn ($w) => $w->whereIn('campaign_id', self::inView()->select('campaign_id')))
                ->distinct()->orderBy('region')->pluck('region'),
        ]]);
    }

    /** GET /api/v1/reports/meta/campaigns: the mapping editor's list. */
    public function campaigns(): JsonResponse
    {
        return response()->json(['data' => self::inView()->orderBy('name')->get()->map(fn ($c) => [
            'id' => $c->id, 'name' => $c->name, 'state' => $c->state,
            'service_group_id' => $c->service_group_id, 'franchise_id' => $c->franchise_id,
            // Grok's guess (ezbizi:suggest-meta-categories); never applied by itself.
            'suggested_service_group_id' => $c->getAttribute('suggested_service_group_id'),
        ])]);
    }

    /** PUT /api/v1/reports/meta/campaigns/{campaign}: staff set state, category, branch. */
    public function update(Request $request, MetaAdCampaign $campaign): JsonResponse
    {
        $ids = ReportView::franchiseIds();
        abort_if($ids !== null && ! in_array($campaign->franchise_id, $ids, true), 403, 'That campaign is outside your view.');
        $data = $request->validate([
            'state' => ['sometimes', 'nullable', 'string', 'size:2'],
            'service_group_id' => ['sometimes', 'nullable', Rule::exists('service_groups', 'id')->where('organization_id', $campaign->organization_id)],
            'franchise_id' => ['sometimes', 'nullable', Rule::exists('franchises', 'id')->where('organization_id', $campaign->organization_id)],
        ]);
        abort_if($ids !== null && array_key_exists('franchise_id', $data) && ! in_array($data['franchise_id'], $ids, true), 403, 'That branch is outside your view.');
        $campaign->update($data);

        return response()->json(['data' => ['id' => $campaign->id]]);
    }

    /** Campaigns of the branches in view; unassigned ones only in the organization view. */
    private static function inView()
    {
        return MetaAdCampaign::query()->when(ReportView::franchiseIds(), fn ($q, $ids) => $q->whereIn('franchise_id', $ids));
    }
}
