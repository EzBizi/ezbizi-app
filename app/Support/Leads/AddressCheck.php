<?php

namespace App\Support\Leads;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Is the address an enquirer wrote a real one, and what is its ZIP? Asked of
 * Google's Geocoding API with the server key Field already uses. Jose,
 * 2026-09-28: Rodrigo fixes addresses through USPS, but USPS's free API is
 * licensed for shipping only (see the usps memory note), so Google instead.
 *
 * Only the street address LeadDetails read out of the messages is sent,
 * with the ZIP when there is one - nothing else from the conversation. That
 * is what /privacy/ says (7cf26ffd); keep it that way.
 *
 * Free up to 10,000 lookups a month (Google, from 2025-03-01); each address
 * is asked once and remembered for 30 days. The key rides in the query
 * string, so a failure is swallowed rather than reported: an exception would
 * carry the URL, and the key with it, into a log.
 */
final class AddressCheck
{
    /**
     * @return array{address: string, zip: ?string, exact: bool}|null  null when unknown
     */
    public static function check(string $address, ?string $zip = null): ?array
    {
        $place = self::place($address, $zip);

        // The shape Leads and Scheduling read; coordinates are geocode's.
        // line1, city and state fill the Book form's fields (Jose, 2026-11-05).
        return $place === null ? null : array_intersect_key($place, array_flip(['address', 'zip', 'exact', 'line1', 'city', 'state']));
    }

    /**
     * check() plus where it is (GeocodeController).
     *
     * @return array{address: string, zip: ?string, exact: bool, lat: ?float, lng: ?float}|null  null when unknown
     */
    public static function place(string $address, ?string $zip = null): ?array
    {
        $key = (string) config('services.google_maps.server_key');
        $query = trim($address.($zip !== null && ! str_contains($address, $zip) ? ' '.$zip : ''));
        if ($key === '' || $query === '') {
            return null;
        }

        return Cache::remember('address-check:v3:'.sha1(strtolower($query)), now()->addDays(30), function () use ($key, $query) {
            try {
                $response = Http::timeout(8)->get('https://maps.googleapis.com/maps/api/geocode/json', [
                    'address' => $query,
                    'components' => 'country:US',
                    'key' => $key,
                ]);
            } catch (Throwable) {
                return null;
            }

            $result = $response->json('results.0');
            if (! $response->ok() || $response->json('status') !== 'OK' || ! is_array($result)) {
                return null;
            }

            $part = fn (string $type) => collect($result['address_components'] ?? [])
                ->first(fn ($c) => in_array($type, $c['types'] ?? [], true))['short_name'] ?? null;
            $zip = $part('postal_code');

            return [
                'address' => (string) ($result['formatted_address'] ?? ''),
                'zip' => $zip,
                'line1' => trim(($part('street_number') ?? '').' '.($part('route') ?? '')) ?: null,
                'city' => $part('locality') ?? $part('sublocality') ?? $part('postal_town'),
                'state' => $part('administrative_area_level_1'),
                'lat' => isset($result['geometry']['location']['lat']) ? (float) $result['geometry']['location']['lat'] : null,
                'lng' => isset($result['geometry']['location']['lng']) ? (float) $result['geometry']['location']['lng'] : null,
                // A street address Google placed exactly, not a town centre or
                // a street picked because the number did not match.
                'exact' => in_array($result['geometry']['location_type'] ?? '', ['ROOFTOP', 'RANGE_INTERPOLATED'], true)
                    && ! ($result['partial_match'] ?? false),
            ];
        });
    }
}
