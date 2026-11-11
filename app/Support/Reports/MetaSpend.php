<?php

namespace App\Support\Reports;

use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;

/**
 * Facebook ad spend per campaign, as the Meta tab shows it; shared with the
 * Grok notes so both read the same numbers. Region '' rows are each day's
 * totals, any other region Meta's breakdown: one or the other, never summed
 * together. Category 'none' is the uncategorised campaigns. A lead is an
 * instant form: Meta's "lead" total counts A-1's pixel, ~5,760 in NJ for 3
 * bookings (10-26..11-05).
 */
final class MetaSpend
{
    /** When the tenant's Meta figures were last written; null before the first sync. */
    public static function syncedAt(): ?string
    {
        return DB::table('meta_ad_insights')->where('organization_id', app(TenantContext::class)->idOrFail())->max('updated_at');
    }

    /** @return list<array{id: string, name: string, state: ?string, category: ?string, branch: ?string, franchise_id: ?string, spend_minor: int, leads: int, cost_per_lead_minor: ?int, clicks: int, impressions: int}> */
    public static function rows(string $from, string $to, ?string $state = null, ?string $category = null, ?string $region = null): array
    {
        return DB::table('meta_ad_insights as i')
            ->join('meta_ad_campaigns as c', fn ($j) => $j->on('c.connection_id', '=', 'i.connection_id')->on('c.campaign_id', '=', 'i.campaign_id'))
            ->leftJoin('service_groups as g', 'g.id', '=', 'c.service_group_id')
            ->leftJoin('franchises as f', 'f.id', '=', 'c.franchise_id')
            ->where('i.organization_id', app(TenantContext::class)->idOrFail())
            ->whereBetween('i.date', [$from, $to])
            ->where('i.region', $region ?? '')
            ->when(ReportView::franchiseIds(), fn ($w, $ids) => $w->whereIn('c.franchise_id', $ids))
            ->when($state, fn ($w, $s) => $w->where('c.state', $s))
            ->when($category, fn ($w, $cat) => $cat === 'none' ? $w->whereNull('c.service_group_id') : $w->where('c.service_group_id', $cat))
            ->groupBy('c.id', 'c.name', 'c.state', 'c.service_group_id', 'g.name', 'f.name', 'c.franchise_id')
            ->selectRaw('c.id, c.name, c.state, c.service_group_id, g.name category, f.name branch, c.franchise_id,
                    round(sum(i.spend) * 100) spend_minor, sum(i.forms) leads, sum(i.clicks) clicks, sum(i.impressions) impressions')
            ->orderByDesc('spend_minor')
            ->get()
            ->map(fn ($r) => [
                'id' => $r->id, 'name' => $r->name, 'state' => $r->state, 'category' => $r->category,
                'branch' => $r->branch, 'franchise_id' => $r->franchise_id, 'spend_minor' => (int) $r->spend_minor, 'leads' => (int) $r->leads,
                'cost_per_lead_minor' => $r->leads > 0 ? intdiv((int) $r->spend_minor, (int) $r->leads) : null,
                'clicks' => (int) $r->clicks, 'impressions' => (int) $r->impressions,
            ])
            ->all();
    }
}
