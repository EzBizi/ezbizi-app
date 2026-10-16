<?php

namespace App\Support\Web\Hours;

use App\Models\BusinessLocation;
use App\Models\SocialConnection;
use App\Support\Settings\SettingsResolver;
use App\Support\Social\GoogleToken;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;

/**
 * A Business Profile location's regular hours, as an hours destination.
 *
 * Written only while `services.google_business_profile.hours_write` is true
 * (Jose, 2026-09-28: built, and left off until we may write; 2026-10-16: on,
 * for the A1 Leads takeover).
 *
 * ── The place is the location ──
 *
 * Chosen as a Business Profile location, not found through a website's host:
 * one site can stand for many listings and a listing can name no site at all.
 *
 * ── Live, both ways ──
 *
 * Review and apply read `regularHours` from Google, never the synced copy in
 * `business_locations.hours`, which can be a day old; that copy is refreshed
 * from each read. After a write it reads Google again, and only what Google
 * then says counts as Changed.
 *
 * ── What it will not write ──
 *
 * Split hours (two periods on one day) or a period past midnight are not a
 * week this editor can hold: it reads as null, and a Keep-existing day on it
 * is refused rather than flattened.
 */
final class BusinessProfileHours implements HoursDestination
{
    public const KEY = 'google_business_profile';

    private const API = 'https://mybusinessbusinessinformation.googleapis.com/v1/';

    private const DAYS = ['MONDAY', 'TUESDAY', 'WEDNESDAY', 'THURSDAY', 'FRIDAY', 'SATURDAY', 'SUNDAY'];

    public function key(): string
    {
        return self::KEY;
    }

    public function label(): string
    {
        return 'Google Business Profile';
    }

    public function placeClass(): string
    {
        return BusinessLocation::class;
    }

    /** @param  BusinessLocation  $place */
    public function placeName(Model $place): string
    {
        return (string) ($place->title ?: $place->remote_name);
    }

    /** @param  BusinessLocation  $place */
    public function unavailable(Model $place): ?string
    {
        if (! config('services.google_business_profile.hours_write', false)) {
            return 'Google Business Profile hours are not switched on yet.';
        }

        if (str_starts_with((string) $place->remote_name, 'DEMO-')) {
            return 'This is a demo listing, not one on Google.';
        }

        $connection = $place->connection;

        return $connection === null || $connection->status !== SocialConnection::STATUS_CONNECTED
            ? 'The Business Profile account for this location is not connected.'
            : null;
    }

    /** The branch's time zone, or the company's: a listing carries none of its own. @param  BusinessLocation  $place */
    public function zone(Model $place): string
    {
        $scope = $place->franchise ?? $place->company;

        return $scope === null ? '' : (string) app(SettingsResolver::class)->get('timezone', $scope);
    }

    /** @param  BusinessLocation  $place */
    public function read(Model $place): array
    {
        $hours = $this->client($place)->get(self::API.$place->remote_name, ['readMask' => 'regularHours'])->throw()->json('regularHours') ?? ['periods' => []];

        // The synced copy follows what Google just said.
        $place->forceFill(['hours' => $hours])->save();

        return [
            'week' => self::weekFrom($hours),
            'fingerprint' => sha1(json_encode(self::sorted($hours))."\n".$this->zone($place)),
        ];
    }

    /** @param  BusinessLocation  $place */
    public function write(Model $place, array $week): void
    {
        try {
            $this->client($place)
                ->patch(self::API.$place->remote_name.'?updateMask=regularHours', ['regularHours' => self::regularHours($week)])
                ->throw();
        } catch (RequestException $e) {
            // A 4xx is Google saying no: nothing changed. A 5xx may or may not have landed.
            if ($e->response->clientError()) {
                throw new HoursRefused('Google refused: '.($e->response->json('error.message') ?? 'HTTP '.$e->response->status()), 0, $e);
            }

            throw $e;
        }
    }

    /**
     * Google's `regularHours` as seven days, or null when it holds something
     * a week of one opening per day cannot: split or overnight periods.
     *
     * @param  array<string, mixed>  $hours
     */
    public static function weekFrom(array $hours): ?array
    {
        $week = array_fill(0, 7, ['closed' => true, 'open' => '00:00', 'close' => '00:00']);
        $seen = [];

        foreach ((array) ($hours['periods'] ?? []) as $period) {
            $day = array_search($period['openDay'] ?? '', self::DAYS, true);

            if ($day === false || ($period['closeDay'] ?? '') !== self::DAYS[$day] || isset($seen[$day])) {
                return null;
            }

            $seen[$day] = true;
            $week[$day] = [
                'closed' => false,
                'open' => sprintf('%02d:%02d', $period['openTime']['hours'] ?? 0, $period['openTime']['minutes'] ?? 0),
                'close' => sprintf('%02d:%02d', $period['closeTime']['hours'] ?? 0, $period['closeTime']['minutes'] ?? 0),
            ];
        }

        return $week;
    }

    /** @return array{periods: list<array<string, mixed>>} */
    public static function regularHours(array $week): array
    {
        $periods = [];

        foreach ($week as $i => $day) {
            if ($day['closed']) {
                continue;
            }

            [$oh, $om] = array_map('intval', explode(':', $day['open']));
            [$ch, $cm] = array_map('intval', explode(':', $day['close']));
            $periods[] = [
                'openDay' => self::DAYS[$i], 'openTime' => ['hours' => $oh, 'minutes' => $om],
                'closeDay' => self::DAYS[$i], 'closeTime' => ['hours' => $ch, 'minutes' => $cm],
            ];
        }

        return ['periods' => $periods];
    }

    /** Periods in a fixed order, so the same hours always fingerprint the same. */
    private static function sorted(array $hours): array
    {
        $periods = (array) ($hours['periods'] ?? []);
        usort($periods, fn ($a, $b) => [array_search($a['openDay'] ?? '', self::DAYS, true), json_encode($a)] <=> [array_search($b['openDay'] ?? '', self::DAYS, true), json_encode($b)]);

        return $periods;
    }

    private function client(BusinessLocation $place): PendingRequest
    {
        return Http::withToken(GoogleToken::forConnection($place->connection, 'business_profile'))->acceptJson()->timeout(20);
    }
}
