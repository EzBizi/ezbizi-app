<?php

namespace App\Http\Controllers\Api;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

/**
 * GET /api/v1/places/counties?state=Florida&q=brow: US county names in one
 * state, for a contract's "County for disputes" once its state is chosen
 * (Jose, 2026-10-01: "state being first so the county adapts to the chosen
 * state"). Photon, the source AddressLookupController uses; no key, and the
 * client never talks to it directly.
 */
class CountyLookupController extends Controller
{
    public const UNAVAILABLE = 'County suggestions are not answering right now. Type the county in yourself.';

    public function __invoke(Request $request): JsonResponse
    {
        $state = trim((string) $request->query('state', ''));
        $query = trim((string) $request->query('q', ''));

        if ($state === '' || $query === '') {
            return response()->json(['data' => []]);
        }

        // Photon unreachable or slow: no suggestions and a sentence, never a 500.
        try {
            $response = Http::timeout(4)
                ->withHeaders(['User-Agent' => 'EzBizi/address-lookup (https://ezbizi.com; development@ezbizi.com)'])
                ->get('https://photon.komoot.io/api/', [
                    'q' => "{$query}, {$state}",
                    'layer' => 'county',
                    'limit' => 10,
                    'lang' => 'en',
                ]);
        } catch (ConnectionException|RequestException) {
            return response()->json(['data' => [], 'message' => self::UNAVAILABLE]);
        }

        $names = collect($response->successful() ? ($response->json('features') ?? []) : [])
            ->map(fn ($f) => is_array($f) ? ($f['properties'] ?? []) : [])
            ->filter(fn (array $p) => ($p['countrycode'] ?? '') === 'US'
                && strcasecmp((string) ($p['state'] ?? ''), $state) === 0)
            ->map(fn (array $p) => trim(preg_replace('/\s+(County|Parish|Borough)$/i', '', (string) ($p['name'] ?? ''))))
            ->filter()
            ->unique()
            ->values();

        return response()->json(['data' => $names]);
    }
}
