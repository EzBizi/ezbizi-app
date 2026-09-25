<?php

namespace App\Support\Field;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * The three Google Maps Platform calls Field makes, and nothing else.
 *
 * Every method answers null when the key is missing or Google refuses, so a
 * screen can say "not configured" instead of throwing. Nothing here knows
 * about tenants: the caller decides what to ask about.
 */
final class FieldMaps
{
    public function configured(): bool
    {
        return (string) config('services.google_maps.server_key') !== '';
    }

    /**
     * @return array{lat: float, lng: float}|false|null  false when Google says the
     *     address does not exist; null when we could not ask
     */
    public function geocode(string $address): array|false|null
    {
        if (! $this->configured()) {
            return null;
        }

        $response = Http::timeout(10)->get('https://maps.googleapis.com/maps/api/geocode/json', [
            'address' => $address,
            'key' => config('services.google_maps.server_key'),
        ]);

        $status = $response->json('status');

        if ($status === 'ZERO_RESULTS') {
            return false;
        }

        if (! $response->ok() || $status !== 'OK') {
            // The key is in the query string, so never log the URL.
            Log::warning('Field geocode refused', ['status' => $status, 'http' => $response->status()]);

            return null;
        }

        $location = $response->json('results.0.geometry.location');

        return ['lat' => (float) $location['lat'], 'lng' => (float) $location['lng']];
    }

    /**
     * Visit every stop, starting at $origin and ending at $destination, in the
     * order Google finds fastest.
     *
     * @param  array{lat: float, lng: float}  $origin
     * @param  array<int, array{lat: float, lng: float}>  $intermediates
     * @param  array{lat: float, lng: float}  $destination
     * @return array{order: array<int, int>, legs: array<int, array{seconds: int, meters: int}>}|null
     *     `order` indexes $intermediates; `legs` has one more entry than it
     */
    public function optimise(array $origin, array $intermediates, array $destination): ?array
    {
        if (! $this->configured()) {
            return null;
        }

        $point = fn (array $p) => ['location' => ['latLng' => ['latitude' => $p['lat'], 'longitude' => $p['lng']]]];

        $response = Http::timeout(15)
            ->withHeaders([
                'X-Goog-Api-Key' => config('services.google_maps.server_key'),
                'X-Goog-FieldMask' => 'routes.optimizedIntermediateWaypointIndex,routes.legs.duration,routes.legs.distanceMeters',
            ])
            ->post('https://routes.googleapis.com/directions/v2:computeRoutes', [
                'origin' => $point($origin),
                'destination' => $point($destination),
                'intermediates' => array_map($point, $intermediates),
                'travelMode' => 'DRIVE',
                'routingPreference' => 'TRAFFIC_AWARE',
                'optimizeWaypointOrder' => count($intermediates) > 1,
            ]);

        $route = $response->json('routes.0');

        if (! $response->ok() || ! is_array($route)) {
            Log::warning('Field route refused', ['http' => $response->status(), 'error' => $response->json('error.message')]);

            return null;
        }

        return [
            // Google omits the index when nothing was reordered.
            'order' => $route['optimizedIntermediateWaypointIndex'] ?? array_keys($intermediates),
            'legs' => array_map(fn (array $leg) => [
                'seconds' => (int) rtrim((string) ($leg['duration'] ?? '0s'), 's'),
                'meters' => (int) ($leg['distanceMeters'] ?? 0),
            ], $route['legs'] ?? []),
        ];
    }

    /**
     * A Map Tiles URL template for the client, or null. Sessions last about
     * two weeks; one is shared by every tenant, since a tile of a street is
     * nobody's data.
     */
    public function tileUrl(): ?string
    {
        $key = (string) config('services.google_maps.tiles_key');

        if ($key === '') {
            return null;
        }

        $session = Cache::get('field.tiles_session');

        if (! is_string($session)) {
            $response = Http::timeout(10)->post('https://tile.googleapis.com/v1/createSession?key='.urlencode($key), [
                'mapType' => 'roadmap',
                'language' => 'en-US',
                'region' => 'US',
            ]);

            $session = $response->json('session');

            if (! $response->ok() || ! is_string($session)) {
                Log::warning('Field tiles session refused', ['http' => $response->status()]);

                return null;
            }

            // A day short of Google's expiry, so a cached session is never a dead one.
            $expires = (int) $response->json('expiry', time() + 86400 * 14);
            Cache::put('field.tiles_session', $session, max(3600, $expires - time() - 86400));
        }

        return 'https://tile.googleapis.com/v1/2dtiles/{z}/{x}/{y}?session='.urlencode($session).'&key='.urlencode($key);
    }

    /** Great-circle distance in metres. */
    public static function metres(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $r = 6371000.0;
        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);
        $a = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;

        return 2 * $r * asin(min(1.0, sqrt($a)));
    }
}
