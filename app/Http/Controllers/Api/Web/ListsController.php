<?php

namespace App\Http\Controllers\Api\Web;

use App\Http\Controllers\Api\Controller;
use App\Models\WebLocation;
use App\Models\WebSite;
use App\Models\WebTeamMember;
use App\Support\Web\SiteLists;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

/**
 * The team and the locations a company's websites draw, edited in the
 * Widgets tab beside the widgets that show them.
 *
 * A save REPLACES the list, like the contact form's questions: the screen
 * holds the whole list and its order, so sending it whole is the only way a
 * reordering and a deletion arrive together without an id dance.
 *
 * Reached through a site because that is where the screen is, but both lists
 * belong to the site's COMPANY — editing the team from nj.a-1chimney.com edits
 * it for a-1chimney.com too, which is what one team means.
 */
class ListsController extends Controller
{
    /** GET /api/v1/web/sites/{site}/team */
    public function team(WebSite $site): JsonResponse
    {
        $this->authorize('view', $site);

        return response()->json(['data' => SiteLists::team($site)]);
    }

    /** PUT /api/v1/web/sites/{site}/team */
    public function saveTeam(Request $request, WebSite $site): JsonResponse
    {
        $this->authorize('update', $site);

        $input = $request->validate([
            'members' => ['present', 'array', 'max:200'],
            'members.*.name' => ['required', 'string', 'max:191'],
            'members.*.role' => ['nullable', 'string', 'max:191'],
            'members.*.bio' => ['nullable', 'string', 'max:4000'],
            'members.*.photo_url' => ['nullable', 'url:https,http', 'max:1000'],
            'members.*.category' => ['nullable', 'string', 'max:191'],
        ]);

        DB::transaction(function () use ($site, $input): void {
            WebTeamMember::query()->where('company_id', $site->company_id)->delete();

            foreach (array_values($input['members']) as $i => $member) {
                WebTeamMember::query()->create([
                    ...Arr::only($member, SiteLists::TEAM_FIELDS),
                    'company_id' => $site->company_id,
                    'sort_order' => $i,
                ]);
            }
        });

        return $this->team($site);
    }

    /** GET /api/v1/web/sites/{site}/locations */
    public function locations(WebSite $site): JsonResponse
    {
        $this->authorize('view', $site);

        return response()->json(['data' => SiteLists::locations($site)]);
    }

    /**
     * PUT /api/v1/web/sites/{site}/locations
     *
     * `scope: site` replaces the branches this website shows; `scope: company`
     * replaces the company's stores. One list per save, so a mistake in one
     * cannot take the other with it.
     */
    public function saveLocations(Request $request, WebSite $site): JsonResponse
    {
        $this->authorize('update', $site);

        $input = $request->validate([
            'scope' => ['required', 'in:site,company'],
            'locations' => ['present', 'array', 'max:300'],
            'locations.*.name' => ['required', 'string', 'max:191'],
            'locations.*.phone' => ['nullable', 'string', 'max:64'],
            'locations.*.email' => ['nullable', 'email', 'max:191'],
            'locations.*.website_url' => ['nullable', 'url:https,http', 'max:500'],
            'locations.*.maps_url' => ['nullable', 'url:https,http', 'max:1000'],
            'locations.*.address_line1' => ['nullable', 'string', 'max:191'],
            'locations.*.address_line2' => ['nullable', 'string', 'max:191'],
            'locations.*.city' => ['nullable', 'string', 'max:191'],
            'locations.*.state' => ['nullable', 'string', 'max:64'],
            'locations.*.postal_code' => ['nullable', 'string', 'max:32'],
            'locations.*.country' => ['nullable', 'string', 'max:64'],
            'locations.*.latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'locations.*.longitude' => ['nullable', 'numeric', 'between:-180,180'],
            'locations.*.image_url' => ['nullable', 'url:https,http', 'max:1000'],
        ]);

        $siteId = $input['scope'] === 'site' ? $site->getKey() : null;

        DB::transaction(function () use ($site, $input, $siteId): void {
            WebLocation::query()
                ->where('company_id', $site->company_id)
                ->when($siteId === null, fn ($q) => $q->whereNull('site_id'), fn ($q) => $q->where('site_id', $siteId))
                ->delete();

            foreach (array_values($input['locations']) as $i => $location) {
                WebLocation::query()->create([
                    ...Arr::only($location, SiteLists::LOCATION_FIELDS),
                    'company_id' => $site->company_id,
                    'site_id' => $siteId,
                    'sort_order' => $i,
                ]);
            }
        });

        return $this->locations($site);
    }
}
