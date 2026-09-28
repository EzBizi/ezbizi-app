<?php

namespace App\Support\Web\Hours;

use App\Models\BusinessLocation;
use App\Models\WebSite;
use App\Support\Social\GoogleToken;
use App\Support\Web\HoursWeek;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * A website's Google Business Profile listing, as an hours destination.
 *
 * BUILT AND SWITCHED OFF. Jose, 2026-09-28: "we should build the system for
 * when we have GBP access of our own ... but obviously leave that side
 * inactive". It answers "not switched on" until
 * `services.google_business_profile.hours_write` is true, and then writes
 * `regularHours` through the Business Information API.
 *
 * ── Which listing is a website's ──
 *
 * The synced listing (`business_locations`, from BusinessProfileLocations) in
 * the same company whose website is this site's host. No match, or more than
 * one, and it says so rather than choosing.
 *
 * ── What it will not write ──
 *
 * A listing with split hours (two periods on one day) or a period that runs
 * past midnight is not a week this editor can hold, so it reads as null and a
 * Keep-existing day on it is refused rather than flattened.
 */
final class BusinessProfileHours implements HoursDestination
{
    public const KEY = 'google_business_profile';

    private const DAYS = ['MONDAY', 'TUESDAY', 'WEDNESDAY', 'THURSDAY', 'FRIDAY', 'SATURDAY', 'SUNDAY'];

    public function key(): string
    {
        return self::KEY;
    }

    public function label(): string
    {
        return 'Google Business Profile';
    }

    public function unavailable(WebSite $site): ?string
    {
        if (! config('services.google_business_profile.hours_write', false)) {
            return 'Google Business Profile hours are not switched on yet.';
        }

        $found = $this->listings($site);

        if ($found === 0) {
            return 'No Google Business Profile listing has this website.';
        }

        return $found > 1 ? 'More than one Google Business Profile listing has this website.' : null;
    }

    public function read(WebSite $site): array
    {
        $location = $this->location($site);
        $hours = $location?->hours;

        return [
            'week' => is_array($hours) ? self::weekFrom($hours) : null,
            'fingerprint' => sha1(json_encode([$hours, $location?->synced_at?->toIso8601String()])),
        ];
    }

    public function write(WebSite $site, array $week): void
    {
        $location = $this->location($site) ?? throw new RuntimeException('No listing for this website.');
        $hours = self::regularHours($week);

        Http::withToken(GoogleToken::forConnection($location->connection, 'business_profile'))
            ->timeout(20)
            ->patch('https://mybusinessbusinessinformation.googleapis.com/v1/'.$location->remote_name.'?updateMask=regularHours', [
                'regularHours' => $hours,
            ])
            ->throw();

        // What we wrote is what the next read compares against.
        $location->forceFill(['hours' => $hours])->save();
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

    private function location(WebSite $site): ?BusinessLocation
    {
        return $this->listings($site) === 1 ? $this->matching($site)->first() : null;
    }

    private function listings(WebSite $site): int
    {
        return $this->matching($site)->count();
    }

    private function matching(WebSite $site)
    {
        $host = preg_replace('/^www\./', '', strtolower((string) $site->host));

        return BusinessLocation::query()
            ->where('company_id', $site->company_id)
            ->whereNotNull('website')
            ->get()
            ->filter(fn (BusinessLocation $l) => preg_replace('/^www\./', '', strtolower((string) parse_url($l->website, PHP_URL_HOST))) === $host)
            ->values();
    }
}
