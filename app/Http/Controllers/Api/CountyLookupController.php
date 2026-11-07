<?php

namespace App\Http\Controllers\Api;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * GET /api/v1/places/counties?state=Massachusetts[&q=suf]: every county of one
 * US state, for a contract's "County for disputes" once its state is chosen
 * (Jose, 2026-10-01: "state being first so the county adapts to the chosen
 * state").
 *
 * From resources/data/us-counties.json, built from the Census Bureau's 2020
 * county file (national_county2020.txt) with "County", "Parish" and "Borough"
 * dropped. It replaced a Photon search that could not find Suffolk County,
 * Massachusetts (Merav, 2026-11-05: "If I put Massachusetts it will give me
 * the options"). No outside call: the list is the whole answer.
 */
class CountyLookupController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        static $counties = null;
        $counties ??= json_decode((string) file_get_contents(resource_path('data/us-counties.json')), true);

        $state = trim((string) $request->query('state', ''));
        $query = mb_strtolower(trim((string) $request->query('q', '')));
        $names = collect($counties)->first(fn ($list, $name) => strcasecmp($name, $state) === 0) ?? [];

        return response()->json(['data' => array_values(array_filter(
            $names, fn (string $n) => $query === '' || str_contains(mb_strtolower($n), $query),
        ))]);
    }
}
