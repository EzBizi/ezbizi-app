<?php

namespace App\Support\Web;

use App\Models\Review;
use App\Models\WebItem;
use App\Models\WebLocation;
use App\Models\WebSite;
use App\Models\WebTeamMember;

/**
 * The team and the locations, shaped once for the editor and for the plugin.
 *
 * One shape for both, because the plugin renders exactly what the screen
 * shows; two presenters would drift the day somebody added a column to one.
 */
final class SiteLists
{
    public const TEAM_FIELDS = ['name', 'role', 'bio', 'photo_url', 'category'];

    public const ITEM_FIELDS = [
        'title', 'body', 'subtitle', 'image_url', 'image2_url', 'link_url',
        'phone', 'icon', 'category', 'rating', 'dated',
    ];

    public const LOCATION_FIELDS = [
        'name', 'phone', 'email', 'website_url', 'maps_url',
        'address_line1', 'address_line2', 'city', 'state', 'postal_code', 'country',
        'latitude', 'longitude', 'image_url',
    ];

    /** @return list<array<string, mixed>> */
    public static function team(WebSite $site): array
    {
        return WebTeamMember::query()
            ->where('company_id', $site->company_id)
            ->orderBy('sort_order')
            ->get()
            ->map(fn (WebTeamMember $m) => $m->only(self::TEAM_FIELDS))
            ->values()
            ->all();
    }

    /**
     * The company's FAQs, services, before-and-after pairs and featured
     * reviews, by kind, in their order.
     *
     * @return array<string, list<array<string, mixed>>>
     */
    public static function items(WebSite $site, ?string $only = null): array
    {
        $rows = WebItem::query()
            ->where('company_id', $site->company_id)
            ->when($only !== null, fn ($q) => $q->where('kind', $only))
            ->orderBy('sort_order')
            ->get();

        $out = array_fill_keys($only === null ? WebItem::KINDS : [$only], []);

        foreach ($rows as $item) {
            $out[$item->kind][] = array_map(
                fn ($v) => $v instanceof \DateTimeInterface ? $v->format('Y-m-d') : $v,
                $item->only(self::ITEM_FIELDS),
            );
        }

        return $out;
    }

    /**
     * The company's Google reviews as EzBizi syncs them, newest first, for
     * [ezbizi_reviews] when no review is hand-picked. Read, never written:
     * they belong to Leads.
     *
     * @return list<array<string, mixed>>
     */
    /**
     * Every rated review of the site's company, across all its Google
     * locations: what [ezbizi_reviews] prints and its aggregateRating says.
     * Cached for an hour; the plugin purges its pages when it changes.
     *
     * @return array{count: int, average: float}
     */
    public static function reviewSummary(WebSite $site): array
    {
        return \Illuminate\Support\Facades\Cache::remember('web-review-summary:'.$site->company_id, 3600, function () use ($site): array {
            $row = Review::query()
                ->where('company_id', $site->company_id)
                ->whereNotNull('rating')
                ->selectRaw('count(*) as n, avg(rating) as a')
                ->first();

            return ['count' => (int) ($row->n ?? 0), 'average' => round((float) ($row->a ?? 0), 1)];
        });
    }

    public static function reviews(WebSite $site): array
    {
        return Review::query()
            ->where('company_id', $site->company_id)
            ->whereNotNull('rating')
            ->orderByDesc('reviewed_at')
            ->limit(50)
            ->get(['reviewer_name', 'reviewer_photo_url', 'rating', 'comment', 'reviewed_at', 'location_name'])
            ->map(fn (Review $r) => [
                'title' => (string) $r->reviewer_name,
                'body' => (string) $r->comment,
                'image_url' => $r->reviewer_photo_url,
                'rating' => (int) $r->rating,
                'dated' => $r->reviewed_at?->format('Y-m-d'),
                'subtitle' => 'Google',
            ])
            ->values()
            ->all();
    }

    /**
     * `site`: the branches this website shows. `company`: the company's stores.
     *
     * @return array{site: list<array<string, mixed>>, company: list<array<string, mixed>>}
     */
    public static function locations(WebSite $site): array
    {
        $rows = WebLocation::query()
            ->where('company_id', $site->company_id)
            ->where(fn ($q) => $q->whereNull('site_id')->orWhere('site_id', $site->getKey()))
            ->orderBy('sort_order')
            ->get();

        $shape = fn ($set) => $set->map(fn (WebLocation $l) => $l->only(self::LOCATION_FIELDS))->values()->all();

        return [
            'site' => $shape($rows->where('site_id', $site->getKey())),
            'company' => $shape($rows->whereNull('site_id')),
        ];
    }
}
