<?php

namespace App\Support\Field;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * The Google Maps Platform calls Field makes (geocode, route, tiles and
 * their attribution), and nothing else.
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
     * One drive from $from to $to, with traffic, and the road it takes as
     * Google's encoded polyline.
     *
     * @param  array{lat: float, lng: float}  $from
     * @param  array{lat: float, lng: float}  $to
     * @return array{seconds: int, meters: int, polyline: ?string}|null
     */
    public function drive(array $from, array $to): ?array
    {
        if (! $this->configured()) {
            return null;
        }

        $point = fn (array $p) => ['location' => ['latLng' => ['latitude' => $p['lat'], 'longitude' => $p['lng']]]];

        $response = Http::timeout(15)
            ->withHeaders([
                'X-Goog-Api-Key' => config('services.google_maps.server_key'),
                'X-Goog-FieldMask' => 'routes.duration,routes.distanceMeters,routes.polyline.encodedPolyline',
            ])
            ->post('https://routes.googleapis.com/directions/v2:computeRoutes', [
                'origin' => $point($from),
                'destination' => $point($to),
                'travelMode' => 'DRIVE',
                'routingPreference' => 'TRAFFIC_AWARE',
            ]);

        $route = $response->json('routes.0');

        if (! $response->ok() || ! is_array($route)) {
            Log::warning('Field drive refused', ['http' => $response->status(), 'error' => $response->json('error.message')]);

            return null;
        }

        return [
            'seconds' => (int) rtrim((string) ($route['duration'] ?? '0s'), 's'),
            'meters' => (int) ($route['distanceMeters'] ?? 0),
            'polyline' => $route['polyline']['encodedPolyline'] ?? null,
        ];
    }

    /**
     * Drive time from each origin to one destination, with traffic.
     *
     * @param  array<int, array{lat: float, lng: float}>  $origins
     * @param  array{lat: float, lng: float}  $to
     * @return array<int, array{seconds: int, meters: int}>|null  keyed like $origins; a missing key could not be routed
     */
    public function matrix(array $origins, array $to): ?array
    {
        if ($origins === []) {
            return [];
        }
        if (! $this->configured()) {
            return null;
        }

        $point = fn (array $p) => ['waypoint' => ['location' => ['latLng' => ['latitude' => $p['lat'], 'longitude' => $p['lng']]]]];
        $keys = array_keys($origins);

        $response = Http::timeout(15)
            ->withHeaders([
                'X-Goog-Api-Key' => config('services.google_maps.server_key'),
                'X-Goog-FieldMask' => 'originIndex,duration,distanceMeters,condition',
            ])
            ->post('https://routes.googleapis.com/distanceMatrix/v2:computeRouteMatrix', [
                'origins' => array_map($point, array_values($origins)),
                'destinations' => [$point($to)],
                'travelMode' => 'DRIVE',
                'routingPreference' => 'TRAFFIC_AWARE',
            ]);

        if (! $response->ok() || ! is_array($response->json())) {
            Log::warning('Field matrix refused', ['http' => $response->status()]);

            return null;
        }

        $out = [];
        foreach ($response->json() as $element) {
            if (($element['condition'] ?? null) !== 'ROUTE_EXISTS' || ! isset($element['originIndex'])) {
                continue;
            }
            $out[$keys[(int) $element['originIndex']]] = [
                'seconds' => (int) rtrim((string) ($element['duration'] ?? '0s'), 's'),
                'meters' => (int) ($element['distanceMeters'] ?? 0),
            ];
        }

        return $out;
    }

    /**
     * The quiet map Jose asked for (2026-09-28, pointing at A1 Tools' Positron
     * and Dark Matter): no businesses, no transit, pale land, grey labels, so
     * the people are what stands out. Still Google's tiles and attribution.
     */
    private const STYLES = [
        'light' => [
            ['featureType' => 'poi', 'stylers' => [['visibility' => 'off']]],
            ['featureType' => 'transit', 'stylers' => [['visibility' => 'off']]],
            ['featureType' => 'road', 'elementType' => 'labels.icon', 'stylers' => [['visibility' => 'off']]],
            ['featureType' => 'administrative.land_parcel', 'stylers' => [['visibility' => 'off']]],
            ['featureType' => 'landscape', 'elementType' => 'geometry', 'stylers' => [['color' => '#f4f4f2']]],
            ['featureType' => 'road', 'elementType' => 'geometry', 'stylers' => [['color' => '#ffffff']]],
            ['featureType' => 'road.highway', 'elementType' => 'geometry', 'stylers' => [['color' => '#e3e5e8']]],
            ['featureType' => 'water', 'elementType' => 'geometry', 'stylers' => [['color' => '#d4dde5']]],
            ['elementType' => 'labels.text.fill', 'stylers' => [['color' => '#80868b']]],
            ['elementType' => 'labels.text.stroke', 'stylers' => [['color' => '#f4f4f2']]],
        ],
        'dark' => [
            ['featureType' => 'poi', 'stylers' => [['visibility' => 'off']]],
            ['featureType' => 'transit', 'stylers' => [['visibility' => 'off']]],
            ['featureType' => 'road', 'elementType' => 'labels.icon', 'stylers' => [['visibility' => 'off']]],
            ['featureType' => 'administrative.land_parcel', 'stylers' => [['visibility' => 'off']]],
            ['elementType' => 'geometry', 'stylers' => [['color' => '#1c1f23']]],
            ['featureType' => 'road', 'elementType' => 'geometry', 'stylers' => [['color' => '#2b3035']]],
            ['featureType' => 'road.highway', 'elementType' => 'geometry', 'stylers' => [['color' => '#373d43']]],
            ['featureType' => 'water', 'elementType' => 'geometry', 'stylers' => [['color' => '#101316']]],
            ['elementType' => 'labels.text.fill', 'stylers' => [['color' => '#8a9096']]],
            ['elementType' => 'labels.text.stroke', 'stylers' => [['color' => '#1c1f23']]],
        ],
    ];

    /**
     * A Map Tiles URL template for the client, or null. One styled session
     * per theme, lasting about two weeks and shared by every tenant, since a
     * tile of a street is nobody's data.
     */
    public function tileUrl(string $theme = 'light'): ?string
    {
        $key = (string) config('services.google_maps.tiles_key');
        $theme = $theme === 'dark' ? 'dark' : 'light';

        if ($key === '') {
            return null;
        }

        $session = $this->session($key, $theme);

        return $session === null ? null
            : 'https://tile.googleapis.com/v1/2dtiles/{z}/{x}/{y}?session='.urlencode($session).'&key='.urlencode($key);
    }

    private function session(string $key, string $theme): ?string
    {
        $cacheKey = 'field.tiles_session.'.$theme;
        $session = Cache::get($cacheKey);

        if (is_string($session)) {
            return $session;
        }

        $response = Http::timeout(10)->post('https://tile.googleapis.com/v1/createSession?key='.urlencode($key), [
            'mapType' => 'roadmap',
            'language' => 'en-US',
            'region' => 'US',
            'styles' => self::STYLES[$theme],
        ]);

        $session = $response->json('session');

        if (! $response->ok() || ! is_string($session)) {
            Log::warning('Field tiles session refused', ['http' => $response->status(), 'theme' => $theme]);

            return null;
        }

        // A day short of Google's expiry, so a cached session is never a dead one.
        $expires = (int) $response->json('expiry', time() + 86400 * 14);
        Cache::put($cacheKey, $session, max(3600, $expires - time() - 86400));

        return $session;
    }

    /**
     * The copyright line Google requires beside its tiles for this viewport
     * ("Map data ©2026 Google"), shown in full, bottom right (Map Tiles
     * policies). It changes with the area, so it is asked for, not written.
     * Cached per rounded viewport: panning a street does not change it.
     */
    public function attribution(int $zoom, float $north, float $south, float $east, float $west): ?string
    {
        $key = (string) config('services.google_maps.tiles_key');
        $session = $key === '' ? null : $this->session($key, 'light'); // the copyright is the same for both styles

        if ($session === null) {
            return null;
        }

        $cacheKey = 'field.tiles_copyright.'.md5(json_encode([$zoom, round($north, 1), round($south, 1), round($east, 1), round($west, 1)]));

        return Cache::remember($cacheKey, now()->addHour(), function () use ($key, $session, $zoom, $north, $south, $east, $west) {
            $response = Http::timeout(10)->get('https://tile.googleapis.com/tile/v1/viewport', [
                'session' => $session,
                'key' => $key,
                'zoom' => $zoom, 'north' => $north, 'south' => $south, 'east' => $east, 'west' => $west,
            ]);

            return $response->ok() ? (string) $response->json('copyright', '') : null;
        });
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
