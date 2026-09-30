<?php

namespace App\Http\Controllers\Api;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

/**
 * Fill-in for a street address, so Settings is not a box of one line.
 *
 * Photon (OpenStreetMap) is the lookup: no product key, and the client never
 * talks to it directly. Suggestions are a starting point — the structured
 * fields are what we store, same shape as a company and a customer.
 */
class AddressLookupController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $query = trim((string) $request->query('q', ''));

        if (mb_strlen($query) < 3) {
            return response()->json(['data' => []]);
        }

        $response = Http::timeout(4)
            ->withHeaders([
                'User-Agent' => 'EzBizi/address-lookup (https://ezbizi.com; development@ezbizi.com)',
            ])
            ->get('https://photon.komoot.io/api/', [
                'q'     => $query,
                'limit' => 6,
                'lang'  => 'en',
            ]);

        if (! $response->successful()) {
            return response()->json(['data' => []]);
        }

        $features = $response->json('features');

        if (! is_array($features)) {
            return response()->json(['data' => []]);
        }

        $data = [];

        foreach ($features as $feature) {
            if (! is_array($feature)) {
                continue;
            }

            $mapped = $this->mapFeature($feature);

            if ($mapped !== null) {
                $data[] = $mapped;
            }
        }

        return response()->json(['data' => $data]);
    }

    /**
     * @param  array<string, mixed>  $feature
     * @return array{label: string, address_line1: ?string, city: ?string, state: ?string, postal_code: ?string, country: ?string}|null
     */
    private function mapFeature(array $feature): ?array
    {
        $properties = $feature['properties'] ?? null;

        if (! is_array($properties)) {
            return null;
        }

        $street = trim((string) ($properties['street'] ?? ''));
        $number = trim((string) ($properties['housenumber'] ?? ''));
        $name   = trim((string) ($properties['name'] ?? ''));

        $line1 = trim($number.' '.$street);
        if ($line1 === '') {
            $line1 = $name;
        }

        $city   = $this->firstString($properties, ['city', 'town', 'village', 'district']);
        $state  = $this->stateCode($properties);
        $postal = $this->firstString($properties, ['postcode']);

        $label = $this->firstString($properties, ['name'])
            ?? collect([$line1, $city, $state, $postal])->filter()->implode(', ');

        if ($label === '') {
            return null;
        }

        return [
            'label'         => $label,
            'address_line1' => $line1 === '' ? null : $line1,
            'city'          => $city,
            'state'         => $state,
            'postal_code'   => $postal,
            // The country's name as the lookup gives it ("United States"), for
            // a person's home address (2026-09-30). A company and a customer
            // have no country and ignore it.
            'country'       => $this->firstString($properties, ['country']),
        ];
    }

    /** @param  array<string, mixed>  $properties */
    private function stateCode(array $properties): ?string
    {
        $code = $this->firstString($properties, ['statecode']);

        if ($code !== null && strlen($code) === 2) {
            return strtoupper($code);
        }

        $state = $this->firstString($properties, ['state']);

        if ($state !== null && strlen($state) === 2) {
            return strtoupper($state);
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $properties
     * @param  list<string>  $keys
     */
    private function firstString(array $properties, array $keys): ?string
    {
        foreach ($keys as $key) {
            $value = $properties[$key] ?? null;

            if (is_string($value) && trim($value) !== '') {
                return trim($value);
            }
        }

        return null;
    }
}
