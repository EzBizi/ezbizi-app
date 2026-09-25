<?php

namespace App\Support\Web;

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
