<?php

namespace App\Services\BusinessProfile;

use App\Models\BusinessLocation;
use App\Support\Social\GoogleToken;
use App\Support\Social\Publishing\GoogleBusinessProfilePublisher;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * One listing's Business Profile data beyond the profile itself, for Customer
 * Experience and Marketing (A1 Leads takeover, 2026-10-19): performance,
 * search terms, photos, categories and services, and its standing with Google.
 *
 * Official APIs only, through the listing's own connection (each company links
 * its own profile - Jose, 2026-10-16), so a paused business is refused by
 * GoogleToken like every other Google call. Reads are free to use. The one
 * write, categories and services, is off until
 * services.google_business_profile.write is on: no Business Profile writes
 * while another system still manages A-1's profiles.
 */
final class BusinessProfileApi
{
    private const INFO = 'https://mybusinessbusinessinformation.googleapis.com/v1/';

    private const PERFORMANCE = 'https://businessprofileperformance.googleapis.com/v1/';

    private const VERIFICATIONS = 'https://mybusinessverifications.googleapis.com/v1/';

    private const V4 = 'https://mybusiness.googleapis.com/v4/';

    /** Daily counts Google keeps per listing; the screen picks which to draw. */
    public const METRICS = [
        'CALL_CLICKS', 'WEBSITE_CLICKS', 'BUSINESS_DIRECTION_REQUESTS', 'BUSINESS_CONVERSATIONS',
        'BUSINESS_IMPRESSIONS_DESKTOP_SEARCH', 'BUSINESS_IMPRESSIONS_MOBILE_SEARCH',
        'BUSINESS_IMPRESSIONS_DESKTOP_MAPS', 'BUSINESS_IMPRESSIONS_MOBILE_MAPS',
    ];

    /**
     * Daily counts, inclusive dates: metric => [Y-m-d => count].
     *
     * @return array<string, array<string, int>>
     */
    public function performance(BusinessLocation $location, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $query = ['dailyRange.startDate.year' => $from->year, 'dailyRange.startDate.month' => $from->month, 'dailyRange.startDate.day' => $from->day,
            'dailyRange.endDate.year' => $to->year, 'dailyRange.endDate.month' => $to->month, 'dailyRange.endDate.day' => $to->day];
        $url = self::PERFORMANCE.self::v1Name($location).':fetchMultiDailyMetricsTimeSeries?'
            .http_build_query($query).'&'.implode('&', array_map(fn ($m) => 'dailyMetrics='.$m, self::METRICS));

        $out = [];
        foreach ((array) $this->client($location)->get($url)->throw()->json('multiDailyMetricTimeSeries') as $group) {
            foreach ((array) ($group['dailyMetricTimeSeries'] ?? []) as $series) {
                $metric = (string) ($series['dailyMetric'] ?? '');
                foreach ((array) ($series['timeSeries']['datedValues'] ?? []) as $v) {
                    $d = $v['date'] ?? [];
                    $out[$metric][sprintf('%04d-%02d-%02d', $d['year'] ?? 0, $d['month'] ?? 0, $d['day'] ?? 0)] = (int) ($v['value'] ?? 0);
                }
            }
        }

        return $out;
    }

    /**
     * What people searched to find the listing, for a month range: term => impressions
     * (Google reports a floor, "under 15", as a threshold).
     *
     * @return array<string, int>
     */
    public function searchTerms(BusinessLocation $location, CarbonImmutable $fromMonth, CarbonImmutable $toMonth): array
    {
        $rows = $this->client($location)->get(self::PERFORMANCE.self::v1Name($location).'/searchkeywords/impressions/monthly', [
            'monthlyRange.startMonth.year' => $fromMonth->year, 'monthlyRange.startMonth.month' => $fromMonth->month,
            'monthlyRange.endMonth.year' => $toMonth->year, 'monthlyRange.endMonth.month' => $toMonth->month,
            'pageSize' => 100,
        ])->throw()->json('searchKeywordsCounts') ?? [];

        $out = [];
        foreach ($rows as $row) {
            $v = $row['insightsValue'] ?? [];
            $out[(string) ($row['searchKeyword'] ?? '')] = (int) ($v['value'] ?? $v['threshold'] ?? 0);
        }

        return $out;
    }

    /** @return list<array{name: string, category: ?string, format: ?string, url: ?string, thumbnail: ?string, created: ?string}> */
    public function media(BusinessLocation $location): array
    {
        $items = $this->client($location)->get(self::V4.GoogleBusinessProfilePublisher::v4Name($location, $location->connection).'/media',
            ['pageSize' => 100])->throw()->json('mediaItems') ?? [];

        return array_map(fn ($m) => [
            'name' => (string) ($m['name'] ?? ''),
            'category' => $m['locationAssociation']['category'] ?? null,
            'format' => $m['mediaFormat'] ?? null,
            'url' => $m['googleUrl'] ?? null,
            'thumbnail' => $m['thumbnailUrl'] ?? null,
            'created' => $m['createTime'] ?? null,
        ], $items);
    }

    /** Adds a photo or video to a listing from a public URL (copying between listings). Refused while writes are off. */
    public function addMedia(BusinessLocation $location, string $sourceUrl, ?string $category, string $format = 'PHOTO'): void
    {
        self::writable();
        $this->client($location)->post(self::V4.GoogleBusinessProfilePublisher::v4Name($location, $location->connection).'/media', [
            'mediaFormat' => $format,
            'locationAssociation' => ['category' => $category ?: 'ADDITIONAL'],
            'sourceUrl' => $sourceUrl,
        ])->throw();
    }

    /** @return array{primary: ?array, additional: list<array>, services: list<array>} */
    public function categoriesAndServices(BusinessLocation $location): array
    {
        $json = $this->client($location)->get(self::INFO.self::v1Name($location), ['readMask' => 'categories,serviceItems'])->throw()->json();

        return [
            'primary' => $json['categories']['primaryCategory'] ?? null,
            'additional' => $json['categories']['additionalCategories'] ?? [],
            'services' => $json['serviceItems'] ?? [],
        ];
    }

    /**
     * The whole service list, replaced (Google's update replaces it: preview,
     * apply, then read back). Refused while writes are off.
     *
     * @param  list<array<string, mixed>>  $services  Google's serviceItems shape
     */
    public function replaceServices(BusinessLocation $location, array $services): array
    {
        self::writable();
        $this->client($location)->patch(self::INFO.self::v1Name($location).'?updateMask=serviceItems', ['serviceItems' => $services])->throw();

        return $this->categoriesAndServices($location)['services'];
    }

    /**
     * Google's standing for the listing: verified, published, may it be
     * managed, and what Google says to do when not.
     *
     * @return array{has_voice_of_merchant: bool, has_business_authority: bool, verify: bool, resolve_ownership_conflict: bool, complies_with_guidelines: ?string, verifications: list<array{method: ?string, state: ?string, created: ?string}>}
     */
    public function standing(BusinessLocation $location): array
    {
        $vom = $this->client($location)->get(self::VERIFICATIONS.self::v1Name($location).':getVoiceOfMerchantState')->throw()->json() ?? [];
        $list = $this->client($location)->get(self::VERIFICATIONS.self::v1Name($location).'/verifications')->json('verifications') ?? [];

        return [
            'has_voice_of_merchant' => (bool) ($vom['hasVoiceOfMerchant'] ?? false),
            'has_business_authority' => (bool) ($vom['hasBusinessAuthority'] ?? false),
            'verify' => isset($vom['verify']),
            'resolve_ownership_conflict' => isset($vom['resolveOwnershipConflict']),
            'complies_with_guidelines' => $vom['complyWithGuidelines']['recommendationReason'] ?? null,
            'verifications' => array_map(fn ($v) => ['method' => $v['method'] ?? null, 'state' => $v['state'] ?? null, 'created' => $v['createTime'] ?? null], $list),
        ];
    }

    public static function writable(): void
    {
        if (! config('services.google_business_profile.write', false)) {
            throw new RuntimeException('Changing Business Profile listings is not switched on yet.');
        }
    }

    /** "locations/L", whichever way the listing's name was stored. */
    private static function v1Name(BusinessLocation $location): string
    {
        $name = trim((string) $location->remote_name, '/');
        $at = strpos($name, 'locations/');

        return $at === false ? 'locations/'.$name : substr($name, $at);
    }

    private function client(BusinessLocation $location): PendingRequest
    {
        if ($location->connection === null) {
            throw new RuntimeException('This listing is no longer connected.');
        }

        return Http::withToken(GoogleToken::forConnection($location->connection, 'business_profile'))->acceptJson()->timeout(20);
    }
}
