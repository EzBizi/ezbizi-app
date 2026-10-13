<?php

namespace App\Services\Ads;

use App\Models\SocialConnection;
use App\Support\Social\GoogleAdsOAuth;
use App\Support\Social\GoogleToken;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * What a connected Google Ads account spent, and what it got for it.
 *
 * ── Two levels, on purpose ───────────────────────────────────────
 *
 * Google's Reporting Minimum Functionality is conditional on WHICH levels of
 * the hierarchy a tool displays: "a Reporting-only Google Ads API Client must
 * show Reporting Functionality for each of the levels of the Google Ads
 * hierarchy displayed in its reporting interface", where a level is one of
 * Account, Campaign, Ad Group, Ad or Keyword.
 *
 * This shows Account and Campaign and nothing below. That is a deliberate
 * scope, not an omission: it makes R.10 and R.20 apply and leaves R.40 (Ad
 * Group Ad), R.50 (Keyword View), R.70 (Search Term View), R.100 (DSA Search
 * Term View) and R.130 (Bidding Strategy) genuinely not applicable. Adding an
 * ad group column later is not a column - it is five more mandatory fields and
 * a fresh submission to Google.
 *
 * ── The five fields are not ours to choose ───────────────────────
 *
 * R.10 and R.20 both require clicks, cost_micros, impressions, conversions and
 * all_conversions. All five are selected below and all five must reach the
 * screen. `campaign.status` is required whenever paused or removed campaigns
 * are shown, and this shows them, so it is selected too.
 *
 * ── Currency is part of the number ───────────────────────────────
 *
 * `cost_micros` is millionths of the ACCOUNT's currency, not of anything
 * global. A figure rendered without `customer.currency_code` is a number with
 * no unit, and A-1's accounts are not guaranteed to agree with each other. The
 * same trap as the Local Services timestamps, which are account-local and were
 * read as UTC until somebody noticed the hours were wrong.
 *
 * ── Read live, never stored ──────────────────────────────────────
 *
 * The opposite decision from LocalServicesLeads, and for the reason stated
 * there: a lead is acted on over days, gets a status only this organization
 * knows, and must survive the account being disconnected. A spend figure is
 * none of those things. It is true of a date range at the moment it is asked
 * for, it is restated by Google whenever conversions are attributed late, and a
 * stored copy would go quietly wrong rather than visibly missing.
 */
class CampaignPerformance
{
    private const TIMEOUT = 20;

    /**
     * The five R.10 / R.20 metrics, named once.
     *
     * Kept as a constant rather than inlined in both queries because the
     * requirement is that the SAME set appears at both levels, and two inlined
     * lists are two chances to drift apart - at which point one level silently
     * stops meeting the policy we told Google we meet.
     */
    private const REQUIRED_METRICS = 'metrics.clicks, metrics.cost_micros, metrics.impressions, '
        .'metrics.conversions, metrics.all_conversions';

    /**
     * The OPTIONAL metrics, which the policy says must be "made available, as
     * an option for users" beside the required ones.
     *
     * ── Why these six and not "all" ──────────────────────────────────
     *
     * Two constraints, both measured on 2026-09-24 against A1 Chimney -
     * Atlanta rather than read off the field reference:
     *
     *  - every one of them must be valid on BOTH `customer` and `campaign`.
     *    One incompatible field fails the whole query with a 400, which would
     *    take the five REQUIRED metrics down with it - an optional column
     *    breaking R.10 is the worst trade available here. `phone_calls` and
     *    `phone_impressions` are the proof: valid on campaign, refused on
     *    customer ("could not support requested resources: 'CUSTOMER'").
     *  - every one of them must be ADDITIVE, because account totals are summed
     *    from day-segmented rows (see forConnection). A click-through rate or
     *    an impression share summed across thirty days is a wrong number that
     *    looks right, so ratios are DERIVED from these sums in `totals()` and
     *    impression-share metrics, which cannot be derived at all, are left out.
     *
     * Selected in the same two queries as the required fields, so offering
     * them costs no extra API operations.
     */
    private const OPTIONAL_METRICS = 'metrics.interactions, metrics.conversions_value, '
        .'metrics.all_conversions_value, metrics.view_through_conversions, '
        .'metrics.engagements, metrics.invalid_clicks';

    /**
     * @return array{
     *     account: array<string, mixed>,
     *     campaigns: list<array<string, mixed>>,
     *     currency: ?string,
     *     from: string,
     *     to: string,
     * }
     */
    private const CHANNEL_LABELS = [
        'LOCAL_SERVICES' => 'Local Services',
        'SEARCH' => 'Search (PPC)',
        'PERFORMANCE_MAX' => 'Performance Max',
        'DISPLAY' => 'Display',
        'VIDEO' => 'Video',
        'SMART' => 'Smart',
    ];

    public function forConnection(
        SocialConnection $connection,
        Carbon $from,
        Carbon $to,
        bool $withCampaigns = true,
        ?string $token = null,
    ): array {
        // A manager's token reads its clients too; the roll-up passes it for a
        // client with no row of its own.
        $token ??= GoogleToken::forConnection($connection, 'google_ads');

        // Dates are the ACCOUNT's, not the server's. segments.date is evaluated
        // in the customer's own time zone, so a range built from a UTC "today"
        // silently asks for a different day than the one the user picked.
        $window = sprintf(
            "segments.date BETWEEN '%s' AND '%s'",
            $from->format('Y-m-d'),
            $to->format('Y-m-d'),
        );

        /*
         * Segmented by DAY, and the account totals are summed from it.
         *
         * A third query for the totals would cost a third of the quota to
         * produce a number this data already contains, and the two could
         * disagree - Google computes an unsegmented total slightly differently
         * when a conversion is attributed across a day boundary, and a screen
         * whose header contradicts its own chart is worse than one with no
         * chart at all.
         */
        $account = $this->search($connection, $token, sprintf(
            'SELECT segments.date, customer.id, customer.descriptive_name, '
            .'customer.currency_code, customer.time_zone, customer.manager, %s, %s FROM customer WHERE %s',
            self::REQUIRED_METRICS,
            self::OPTIONAL_METRICS,
            $window,
        ));

        /*
         * The manager roll-up asks for account totals only, and skipping the
         * campaign query HALVES what a forty-account roll-up costs. It is the
         * difference between eighty operations and forty on a 15,000/day
         * ceiling that the lead sync also draws on.
         */
        $campaigns = ! $withCampaigns ? [] : $this->search($connection, $token, sprintf(
            'SELECT campaign.id, campaign.name, campaign.status, '
            .'campaign.advertising_channel_type, %s, %s FROM campaign WHERE %s',
            self::REQUIRED_METRICS,
            self::OPTIONAL_METRICS,
            $window,
        ));

        $currency = $account[0]['customer']['currencyCode'] ?? null;

        return [
            'series' => $this->series($account),
            'account' => $this->totals($account, $currency, [
                'id' => $account[0]['customer']['id'] ?? $connection->remote_id,
                'name' => $account[0]['customer']['descriptiveName'] ?? $connection->display_name,
                'time_zone' => $account[0]['customer']['timeZone'] ?? null,
            ]),
            'campaigns' => array_map(
                fn (array $row) => $this->totals([$row], $currency, [
                    'id' => $row['campaign']['id'] ?? null,
                    'name' => $row['campaign']['name'] ?? null,
                    'status' => $row['campaign']['status'] ?? null,
                    'channel' => $row['campaign']['advertisingChannelType'] ?? null,
                ]),
                $campaigns,
            ),
            // Spend by kind of campaign - Local Services beside Search (PPC),
            // Performance Max and the rest - as A1 Leads' Channel Performance
            // table showed it (Jose, 2026-09-30).
            'channels' => collect($campaigns)
                ->groupBy(fn (array $r) => (string) ($r['campaign']['advertisingChannelType'] ?? 'UNKNOWN'))
                ->map(fn ($rows, string $channel) => $this->totals($rows->all(), $currency, [
                    'channel' => $channel,
                    'label' => self::CHANNEL_LABELS[$channel] ?? ucwords(strtolower(str_replace('_', ' ', $channel))),
                ]))
                ->values()
                ->all(),
            'currency' => $currency,
            'is_manager' => ($account[0]['customer']['manager'] ?? false) === true,
            'from' => $from->format('Y-m-d'),
            'to' => $to->format('Y-m-d'),
        ];
    }

    /**
     * One row's five required metrics, plus whatever identifies it.
     *
     * `cost_micros` is divided here rather than on the client because the
     * divisor is a fact about the API (a million) and not a display choice, and
     * because a client that forgot it would render a plausible number a
     * thousand times too large - the kind of wrong that looks like a real
     * figure.
     *
     * Every metric defaults to 0 rather than null. Google omits a metric for a
     * row with no activity, and a missing "clicks" on screen is indistinguishable
     * from a broken fetch; zero says what actually happened.
     *
     * @param  list<array<string, mixed>>  $rows
     * @param  array<string, mixed>  $identity
     * @return array<string, mixed>
     */
    private function totals(array $rows, ?string $currency, array $identity): array
    {
        $sum = fn (string $field) => array_sum(array_map(
            fn (array $r) => (float) ($r['metrics'][$field] ?? 0),
            $rows,
        ));

        $clicks = $sum('clicks');
        $impressions = $sum('impressions');
        $conversions = $sum('conversions');
        $cost = $sum('costMicros') / 1_000_000;
        $interactions = $sum('interactions');
        $value = $sum('conversionsValue');

        return [
            ...$identity,
            'clicks' => (int) $clicks,
            'impressions' => (int) $impressions,
            'conversions' => round($conversions, 2),
            'all_conversions' => round($sum('allConversions'), 2),
            'cost' => round($cost, 2),
            'currency' => $currency,

            // Optional, additive: summed like the required five.
            'interactions' => (int) $interactions,
            'conversions_value' => round($value, 2),
            'all_conversions_value' => round($sum('allConversionsValue'), 2),
            'view_through_conversions' => (int) $sum('viewThroughConversions'),
            'engagements' => (int) $sum('engagements'),
            'invalid_clicks' => (int) $sum('invalidClicks'),

            // Optional, DERIVED from the sums above and never summed
            // themselves. Null rather than 0 when the denominator is zero: a
            // click-through rate of 0% on an account with no impressions says
            // something false, and "no rate" says what is true.
            'ctr' => self::ratio($clicks, $impressions, 4),
            'average_cpc' => self::ratio($cost, $clicks, 2),
            'average_cpm' => self::ratio($cost * 1000, $impressions, 2),
            'cost_per_conversion' => self::ratio($cost, $conversions, 2),
            'conversion_rate' => self::ratio($conversions, $interactions, 4),
            'interaction_rate' => self::ratio($interactions, $impressions, 4),
            'value_per_conversion' => self::ratio($value, $conversions, 2),
            'conversion_value_per_cost' => self::ratio($value, $cost, 2),
        ];
    }

    private static function ratio(float $top, float $bottom, int $places): ?float
    {
        return $bottom > 0 ? round($top / $bottom, $places) : null;
    }

    /**
     * Pause or enable ONE campaign. Google's M.110, and the only write this
     * class can make.
     *
     * ── Why status and nothing else ──────────────────────────────────
     *
     * Jose asked, 2026-09-24, for campaigns to be paused and enabled from
     * EzBizi. That is the whole of it: `updateMask` is the single field
     * `status`, so this cannot touch a budget, a bid, a name or a setting even
     * if a caller wanted it to. REMOVED is refused before the request is built
     * - it is permanent in Google Ads, there is no un-remove, and it was not
     * asked for.
     *
     * ── Google's words, not ours ─────────────────────────────────────
     *
     * A Local Services campaign, a suspended account or a campaign already in
     * the state asked for can each be refused. The message returned is
     * Google's own sentence, because "the campaign type does not allow this"
     * and "the account is not enabled" are different problems for the person
     * who pressed the button, and both arrive as a 400 or 403.
     *
     * @throws RuntimeException
     */
    public function setStatus(SocialConnection $connection, string $campaignId, string $status): void
    {
        if (! in_array($status, ['ENABLED', 'PAUSED'], true)) {
            throw new RuntimeException('A campaign can only be paused or enabled here.');
        }

        if (! ctype_digit($campaignId)) {
            throw new RuntimeException('That is not a Google Ads campaign id.');
        }

        $token = GoogleToken::forConnection($connection, 'google_ads');
        $customerId = preg_replace('/\D/', '', (string) $connection->remote_id) ?? '';

        // ponytail: third copy of the request headers beside search() and
        // LocalServicesLeads; extract when a fourth caller appears.
        try {
            $response = Http::withToken($token)
                ->withHeaders([
                    'developer-token' => (string) config('services.google_ads.developer_token'),
                    'login-customer-id' => $connection->login_customer_id ?: $customerId,
                ])
                ->timeout(self::TIMEOUT)
                ->post(GoogleAdsOAuth::endpoint().'/customers/'.$customerId.'/campaigns:mutate', [
                    'operations' => [[
                        'update' => [
                            'resourceName' => 'customers/'.$customerId.'/campaigns/'.$campaignId,
                            'status' => $status,
                        ],
                        'updateMask' => 'status',
                    ]],
                ]);
        } catch (ConnectionException) {
            throw new RuntimeException('Google did not answer while changing this campaign.');
        }

        if ($response->failed()) {
            throw new RuntimeException((string) ($response->json('error.details.0.errors.0.message')
                ?? $response->json('error.message')
                ?? 'Google refused the change (HTTP '.$response->status().').'));
        }
    }

    /**
     * Cost, clicks and conversions split LOCAL_SERVICES / everything else,
     * read at campaign-channel level and summed: one query per account
     * (Reports' ad cost vs Workiz jobs, 2026-10-12: "LS Ads" and "PPC").
     *
     * @return array{currency: ?string, lsa: array{cost_minor: int, clicks: int, conversions: float}, ppc: array{cost_minor: int, clicks: int, conversions: float}}
     *
     * @throws RuntimeException
     */
    public function channelCost(SocialConnection $connection, Carbon $from, Carbon $to): array
    {
        $rows = $this->search($connection, GoogleToken::forConnection($connection, 'google_ads'), sprintf(
            "SELECT customer.currency_code, campaign.advertising_channel_type, metrics.cost_micros, "
            ."metrics.clicks, metrics.conversions FROM campaign WHERE segments.date BETWEEN '%s' AND '%s'",
            $from->format('Y-m-d'),
            $to->format('Y-m-d'),
        ));

        $out = ['currency' => null];
        foreach (['lsa', 'ppc'] as $k) {
            $out[$k] = ['cost_minor' => 0, 'clicks' => 0, 'conversions' => 0.0];
        }
        foreach ($rows as $row) {
            $out['currency'] ??= $row['customer']['currencyCode'] ?? null;
            $k = ($row['campaign']['advertisingChannelType'] ?? '') === 'LOCAL_SERVICES' ? 'lsa' : 'ppc';
            $m = $row['metrics'] ?? [];
            // micros to cents: 10,000 micros a cent.
            $out[$k]['cost_minor'] += intdiv((int) ($m['costMicros'] ?? 0), 10_000);
            $out[$k]['clicks'] += (int) ($m['clicks'] ?? 0);
            $out[$k]['conversions'] += (float) ($m['conversions'] ?? 0);
        }

        return $out;
    }

    /**
     * The accounts beneath a manager, as GOOGLE understands them.
     *
     * Not from our own `login_customer_id` column, and the difference matters.
     * That column records the manager an account was reached THROUGH when it
     * was connected, so an account connected directly has it null even when it
     * really does sit under a manager. Rolling up from our rows would silently
     * omit those and produce a total that is wrong rather than partial - and a
     * wrong total on a spend report is worse than no total.
     *
     * `customer_client` is one query and is authoritative. What we then report
     * on is the intersection with the accounts we actually hold a token for,
     * which is a smaller set and is stated on screen rather than implied.
     *
     * @return list<array{id: string, name: string, status: string}>
     */
    public function childrenOf(SocialConnection $connection): array
    {
        $token = GoogleToken::forConnection($connection, 'google_ads');

        $rows = $this->search($connection, $token,
            'SELECT customer_client.client_customer, customer_client.id, '
            .'customer_client.descriptive_name, customer_client.manager, '
            .'customer_client.status FROM customer_client WHERE customer_client.level <= 2'
        );

        $out = [];

        foreach ($rows as $row) {
            $client = $row['customerClient'] ?? [];
            $id = (string) ($client['id'] ?? '');

            // Skip the manager's own row - customer_client includes SELF at
            // level 0 - and skip other managers, which hold no spend of their
            // own and would double-count everything beneath them.
            if ($id === '' || $id === preg_replace('/\D/', '', (string) $connection->remote_id)) {
                continue;
            }

            if (($client['manager'] ?? false) === true) {
                continue;
            }

            $out[] = [
                'id' => $id,
                'name' => (string) ($client['descriptiveName'] ?? $id),
                'status' => (string) ($client['status'] ?? 'ENABLED'),
            ];
        }

        return $out;
    }

    /**
     * One point per day, for the chart.
     *
     * Three series rather than one, because they do not share a scale: spend
     * is money, clicks are a count in the hundreds, conversions are a count in
     * the tens. Drawn together on one axis the conversions line would be flat
     * against the bottom and say nothing.
     *
     * Days Google returns nothing for are simply absent rather than zero-filled
     * here. A gap in the line is honest about a day the account did not run;
     * inventing a zero would draw a crash to the floor and back that never
     * happened.
     *
     * @param  list<array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    private function series(array $rows): array
    {
        $byDay = [];

        foreach ($rows as $row) {
            $day = (string) ($row['segments']['date'] ?? '');

            if ($day === '') {
                continue;
            }

            $m = $row['metrics'] ?? [];
            $byDay[$day] ??= ['cost' => 0.0, 'clicks' => 0.0, 'conversions' => 0.0];
            $byDay[$day]['cost'] += (float) ($m['costMicros'] ?? 0) / 1_000_000;
            $byDay[$day]['clicks'] += (float) ($m['clicks'] ?? 0);
            $byDay[$day]['conversions'] += (float) ($m['conversions'] ?? 0);
        }

        ksort($byDay);

        $points = fn (string $key) => array_values(array_map(
            fn (string $day) => ['at' => $day, 'value' => round($byDay[$day][$key], 2)],
            array_keys($byDay),
        ));

        return [
            ['label' => 'Cost', 'key' => 'cost', 'points' => $points('cost')],
            ['label' => 'Clicks', 'key' => 'clicks', 'points' => $points('clicks')],
            ['label' => 'Conversions', 'key' => 'conversions', 'points' => $points('conversions')],
        ];
    }

    /**
     * @return list<array<string, mixed>>
     *
     * @throws RuntimeException
     */
    private function search(SocialConnection $connection, string $token, string $query): array
    {
        $customerId = preg_replace('/\D/', '', (string) $connection->remote_id) ?? '';

        /*
         * ponytail: a second, smaller copy of the transport in
         * LocalServicesLeads::search(), deliberately not extracted yet.
         *
         * The two facts that copy paid for are carried, not re-derived:
         * `login-customer-id` is the connection's own account rather than an
         * installation-wide id, and NO pageSize - googleAds:search answers
         * PAGE_SIZE_NOT_SUPPORTED and a 400 looks like every other failure on
         * screen. What is NOT carried is paging, because this asks for one
         * account and its campaigns rather than a lead history.
         *
         * Extract both into one place if a third caller appears. Refactoring a
         * 900-line live file to save twenty lines, in the same change that adds
         * a feature, is how a working path gets broken for tidiness.
         */
        try {
            $response = Http::withToken($token)
                ->withHeaders([
                    'developer-token' => (string) config('services.google_ads.developer_token'),
                    'login-customer-id' => $connection->login_customer_id ?: $customerId,
                ])
                ->timeout(self::TIMEOUT)
                ->post(
                    GoogleAdsOAuth::endpoint().'/customers/'.$customerId.'/googleAds:search',
                    ['query' => $query],
                );
        } catch (ConnectionException) {
            throw new RuntimeException('Google did not answer while reading this account\'s performance.');
        }

        if ($response->failed()) {
            // Google's own sentence, not a status code. "Not enabled or
            // deactivated" and "quota exhausted" are different problems for
            // whoever is looking at the screen, and both arrive as 403.
            $message = (string) ($response->json('error.details.0.errors.0.message')
                ?? $response->json('error.message')
                ?? 'Google refused the performance request (HTTP '.$response->status().').');

            throw new RuntimeException($message.$this->scopeOfThrottle($response->json()));
        }

        return array_values((array) $response->json('results', []));
    }

    /**
     * Whether a throttle is this ACCOUNT or the whole developer token.
     *
     * "Too many requests. Retry in 4413 seconds." reads, on a screen with one
     * account open, as a problem with that account. Google sends the field
     * that settles it - quotaErrorDetails.rateScope - and until 2026-09-16 we
     * read the message and threw the rest away, which left the one question
     * worth asking unanswerable: is every other account fine, or is nothing
     * going to work for the next hour?
     *
     * The difference is not academic. ACCOUNT means click a different account
     * and carry on. DEVELOPER means the whole fleet is waiting, including the
     * lead sync, and there is nothing to do but wait it out.
     *
     * @param  mixed  $body
     */
    private function scopeOfThrottle($body): string
    {
        $details = data_get($body, 'error.details.0.errors.0.details.quotaErrorDetails');

        if (! is_array($details)) {
            return '';
        }

        return match ($details['rateScope'] ?? '') {
            'ACCOUNT' => ' This limit is on this account alone - other connected accounts are unaffected.',
            'DEVELOPER' => " This limit is on EzBizi's whole Google Ads connection, so every account "
                .'is waiting on the same clock.',
            default => '',
        };
    }
}
