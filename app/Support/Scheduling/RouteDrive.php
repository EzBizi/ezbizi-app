<?php

namespace App\Support\Scheduling;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * A route's real drive, from Google's Routes API: the road path to draw on
 * the in-app map, and road miles and minutes (Jose, 2026-10-14: "i want to
 * see the google maps routing Inside the app, not via an outbound link").
 *
 * Stops are driven in the order Routing put them (time order); Google is not
 * asked to reorder, since the windows decide the order. Cached by the stops'
 * coordinates, so a refresh of the Routing tab costs nothing until a stop
 * changes. Answers null when the key is missing or Google refuses: the
 * screen then draws straight lines, as before.
 */
final class RouteDrive
{
    private const HOURS = 12;

    /**
     * Uncached asks per instance (one Routing request), each at most TIMEOUT
     * seconds, so a first load of a busy day is bounded (desk review,
     * 2026-10-14); later routes come back as null, straight lines, and fill
     * in on the next load.
     */
    private const MAX_ASKS = 8;

    private const TIMEOUT = 8;

    private int $asked = 0;

    /**
     * @param  list<array{0: float, 1: float}>  $points  lat, lng in driving order
     * @return array{miles: float, minutes: int, polyline: string}|null polyline in
     *                                                                  Google's encoded format
     */
    public function for(array $points): ?array
    {
        $key = (string) config('services.google_maps.server_key');
        if ($key === '' || count($points) < 2) {
            return null;
        }

        $cacheKey = 'scheduling:route-drive:'.sha1(json_encode(array_map(fn ($p) => [round($p[0], 5), round($p[1], 5)], $points)));
        $cached = Cache::get($cacheKey);
        if ($cached !== null || $this->asked >= self::MAX_ASKS) {
            return $cached;
        }
        $this->asked++;

        // Null is not cached, so a refusal or a timeout is asked again next load.
        return Cache::remember($cacheKey, now()->addHours(self::HOURS), function () use ($points, $key) {
            $at = fn (array $p) => ['location' => ['latLng' => ['latitude' => $p[0], 'longitude' => $p[1]]]];
            $body = ['origin' => $at($points[0]), 'destination' => $at(end($points)), 'travelMode' => 'DRIVE'];
            if (count($points) > 2) {
                $body['intermediates'] = array_map($at, array_slice($points, 1, -1));
            }

            try {
                $response = Http::timeout(self::TIMEOUT)
                    ->withHeaders([
                        'X-Goog-Api-Key' => $key,
                        'X-Goog-FieldMask' => 'routes.distanceMeters,routes.duration,routes.polyline.encodedPolyline',
                    ])
                    ->post('https://routes.googleapis.com/directions/v2:computeRoutes', $body);
            } catch (\Throwable $e) {
                // A timeout or a dropped connection: straight lines, not a
                // failed Routing tab.
                Log::warning('Scheduling route drive failed', ['error' => $e::class]);

                return null;
            }

            $route = $response->json('routes.0');
            if (! $response->ok() || ! is_array($route)) {
                // The key travels in a header; the body never carries it.
                Log::warning('Scheduling route drive refused', ['http' => $response->status()]);

                return null;
            }

            return [
                'miles' => round(((int) ($route['distanceMeters'] ?? 0)) / 1609.344, 1),
                'minutes' => (int) round(((int) rtrim((string) ($route['duration'] ?? '0s'), 's')) / 60),
                'polyline' => (string) ($route['polyline']['encodedPolyline'] ?? ''),
            ];
        });
    }
}
