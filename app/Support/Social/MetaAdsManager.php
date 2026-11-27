<?php

namespace App\Support\Social;

use App\Models\SocialConnection;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * A Facebook ad account's campaigns, ad sets and ads, read live and changed
 * live (Jose, 2026-11-04: "we need to be able to control meta campaigns from
 * the app"). Nothing is stored: Meta is the record, and a copy would be
 * stale the moment somebody paused something in Ads Manager.
 *
 * Changing needs ads_management on the connection's grant. A grant from
 * before 2026-11-04 holds only ads_read, and Meta refuses the write with
 * its own message, which reaches the screen unchanged.
 */
final class MetaAdsManager
{
    private const TIMEOUT = 30;

    public const STATUSES = ['ACTIVE', 'PAUSED'];

    public function __construct(private readonly MetaAdInsights $insights) {}

    /**
     * Campaigns, each with its ad sets, each with its ads, and every level's
     * spend, impressions, clicks and leads over the days asked. One insights
     * read at ad level, summed upward, so the three levels always add up.
     *
     * @return list<array<string, mixed>>
     */
    public function tree(SocialConnection $connection, Carbon $from, Carbon $to): array
    {
        $account = (string) $connection->remote_id;
        $fields = 'id,name,status,effective_status,daily_budget,lifetime_budget';

        $figures = [];
        foreach ($this->insights->pages($connection, $account.'/insights', [
            'level' => 'ad',
            'fields' => 'campaign_id,adset_id,ad_id,spend,impressions,clicks,actions',
            'time_range' => json_encode(['since' => $from->toDateString(), 'until' => $to->toDateString()]),
            'limit' => 500,
        ]) as $row) {
            $figure = [
                'spend' => (float) ($row['spend'] ?? 0),
                'impressions' => (int) ($row['impressions'] ?? 0),
                'clicks' => (int) ($row['clicks'] ?? 0),
                // Instant forms only: A-1's pixel "Lead" fired ~5,760 times for 3 real bookings (NJ, 11-06).
                'leads' => MetaAdInsights::leads((array) ($row['actions'] ?? []), 'onsite_conversion.lead_grouped'),
            ];
            foreach (['campaign_id', 'adset_id', 'ad_id'] as $key) {
                $id = (string) ($row[$key] ?? '');
                $figures[$id] = self::add($figures[$id] ?? null, $figure);
            }
        }

        $ads = [];
        foreach ($this->insights->pages($connection, $account.'/ads', ['fields' => 'id,name,status,effective_status,adset_id', 'limit' => 500]) as $ad) {
            $ads[(string) $ad['adset_id']][] = self::node($ad, $figures) + ['level' => 'ad'];
        }

        $adsets = [];
        foreach ($this->insights->pages($connection, $account.'/adsets', ['fields' => $fields.',campaign_id', 'limit' => 500]) as $set) {
            $adsets[(string) $set['campaign_id']][] = self::node($set, $figures) + ['level' => 'adset', 'children' => $ads[(string) $set['id']] ?? []];
        }

        $campaigns = [];
        foreach ($this->insights->pages($connection, $account.'/campaigns', ['fields' => $fields.',objective', 'limit' => 500]) as $campaign) {
            $campaigns[] = self::node($campaign, $figures) + [
                'level' => 'campaign',
                'objective' => $campaign['objective'] ?? null,
                'children' => $adsets[(string) $campaign['id']] ?? [],
            ];
        }

        return $campaigns;
    }

    /** Pause or resume one campaign, ad set or ad of this account. */
    public function setStatus(SocialConnection $connection, string $objectId, string $status): void
    {
        if (! in_array($status, self::STATUSES, true)) {
            throw new RuntimeException('A campaign can only be turned on or paused.');
        }

        $this->ownedObject($connection, $objectId);
        $this->post($connection, $objectId, ['status' => $status]);
    }

    /**
     * A new daily budget, in the account's currency, on the campaign or ad set
     * that holds one. Meta keeps a budget on exactly one of the two levels, so
     * a budget sent to the other is refused here with where it lives.
     *
     * @return int the old daily budget, in minor units, for the audit
     */
    public function setDailyBudget(SocialConnection $connection, string $objectId, float $amount): int
    {
        $object = $this->ownedObject($connection, $objectId, 'daily_budget,lifetime_budget');

        if (! isset($object['daily_budget'])) {
            throw new RuntimeException(isset($object['lifetime_budget'])
                ? 'This one has a lifetime budget, not a daily one. Change it in Ads Manager.'
                : 'This one has no budget of its own: it is set on its campaign or on its ad sets.');
        }

        // ponytail: two-decimal currencies only (USD here); JPY-style offsets need the account's currency offset.
        $this->post($connection, $objectId, ['daily_budget' => (int) round($amount * 100)]);

        return (int) $object['daily_budget'];
    }

    /**
     * The object, read through this connection, refused unless it is in the
     * connection's own ad account: the id comes from the client, and a token
     * that reaches two businesses must not let one tenant pause the other's.
     *
     * @return array<string, mixed>
     */
    private function ownedObject(SocialConnection $connection, string $objectId, string $fields = ''): array
    {
        $object = $this->get($connection, $objectId, ['fields' => ltrim($fields.',account_id', ',')]);

        if ('act_'.($object['account_id'] ?? '') !== (string) $connection->remote_id) {
            throw new RuntimeException('That is not in this ad account.');
        }

        return $object;
    }

    /** @return array<string, mixed> */
    private function get(SocialConnection $connection, string $path, array $query): array
    {
        return $this->call($connection, 'get', $path, $query);
    }

    private function post(SocialConnection $connection, string $path, array $body): void
    {
        $this->call($connection, 'post', $path, $body);
    }

    /** @return array<string, mixed> */
    private function call(SocialConnection $connection, string $method, string $path, array $data): array
    {
        $token = $connection->credentials['access_token'] ?? null;

        if (! is_string($token) || $token === '') {
            throw new RuntimeException('This Facebook Ads connection has no token. Connect it again.');
        }

        try {
            $response = Http::timeout(self::TIMEOUT)->asForm()
                ->{$method}('https://graph.facebook.com/'.FacebookOAuth::GRAPH_VERSION.'/'.$path, $data + ['access_token' => $token]);
        } catch (ConnectionException) {
            throw new RuntimeException('Meta did not answer.');
        }

        if ($response->failed()) {
            // The message, never the URL: the URL carries the token.
            throw new RuntimeException('Meta refused: '.$response->json('error.error_user_msg', $response->json('error.message', 'HTTP '.$response->status())));
        }

        return (array) $response->json();
    }

    /** @return array<string, mixed> */
    private static function node(array $row, array $figures): array
    {
        $figure = $figures[(string) $row['id']] ?? self::add(null, ['spend' => 0.0, 'impressions' => 0, 'clicks' => 0, 'leads' => 0]);

        return [
            'id' => (string) $row['id'],
            'name' => (string) ($row['name'] ?? ''),
            'status' => $row['status'] ?? null,
            'effective_status' => $row['effective_status'] ?? null,
            'daily_budget' => isset($row['daily_budget']) ? ((int) $row['daily_budget']) / 100 : null,
            'lifetime_budget' => isset($row['lifetime_budget']) ? ((int) $row['lifetime_budget']) / 100 : null,
        ] + $figure + [
            'cost_per_lead' => $figure['leads'] > 0 ? round($figure['spend'] / $figure['leads'], 2) : null,
        ];
    }

    /** @return array{spend: float, impressions: int, clicks: int, leads: int} */
    private static function add(?array $a, array $b): array
    {
        return [
            'spend' => round(($a['spend'] ?? 0) + $b['spend'], 2),
            'impressions' => ($a['impressions'] ?? 0) + $b['impressions'],
            'clicks' => ($a['clicks'] ?? 0) + $b['clicks'],
            'leads' => ($a['leads'] ?? 0) + $b['leads'],
        ];
    }
}
