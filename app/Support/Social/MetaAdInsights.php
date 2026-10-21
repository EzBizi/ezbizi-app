<?php

namespace App\Support\Social;

use App\Models\MetaAdCampaign;
use App\Models\MetaAdInsight;
use App\Models\Organization;
use App\Models\ServiceGroup;
use App\Models\SocialConnection;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Reads a Facebook ad account's campaigns and daily ad-set results into
 * meta_ad_campaigns / meta_ad_insights, for the Meta tab in Reports.
 *
 * Every method runs inside the connection's tenant.
 */
final class MetaAdInsights
{
    /** How far back Meta answers: totals 37 months, breakdowns (region) 13. */
    public const TOTALS_MONTHS = 37;

    public const REGION_MONTHS = 13;

    private const TIMEOUT = 60;

    public const STATES = [
        'AL' => 'Alabama', 'AK' => 'Alaska', 'AZ' => 'Arizona', 'AR' => 'Arkansas', 'CA' => 'California',
        'CO' => 'Colorado', 'CT' => 'Connecticut', 'DE' => 'Delaware', 'DC' => 'District of Columbia',
        'FL' => 'Florida', 'GA' => 'Georgia', 'HI' => 'Hawaii', 'ID' => 'Idaho', 'IL' => 'Illinois',
        'IN' => 'Indiana', 'IA' => 'Iowa', 'KS' => 'Kansas', 'KY' => 'Kentucky', 'LA' => 'Louisiana',
        'ME' => 'Maine', 'MD' => 'Maryland', 'MA' => 'Massachusetts', 'MI' => 'Michigan', 'MN' => 'Minnesota',
        'MS' => 'Mississippi', 'MO' => 'Missouri', 'MT' => 'Montana', 'NE' => 'Nebraska', 'NV' => 'Nevada',
        'NH' => 'New Hampshire', 'NJ' => 'New Jersey', 'NM' => 'New Mexico', 'NY' => 'New York',
        'NC' => 'North Carolina', 'ND' => 'North Dakota', 'OH' => 'Ohio', 'OK' => 'Oklahoma', 'OR' => 'Oregon',
        'PA' => 'Pennsylvania', 'RI' => 'Rhode Island', 'SC' => 'South Carolina', 'SD' => 'South Dakota',
        'TN' => 'Tennessee', 'TX' => 'Texas', 'UT' => 'Utah', 'VT' => 'Vermont', 'VA' => 'Virginia',
        'WA' => 'Washington', 'WV' => 'West Virginia', 'WI' => 'Wisconsin', 'WY' => 'Wyoming',
    ];

    /** Campaigns, upserted. Service and state are guessed from the name ONCE; staff edits stand. */
    public function campaigns(SocialConnection $connection): int
    {
        $groups = ServiceGroup::query()->get(['id', 'name']);
        $count = 0;

        foreach ($this->pages($connection, $connection->remote_id.'/campaigns', [
            'fields' => 'id,name,objective', 'limit' => 200,
        ]) as $row) {
            $name = (string) ($row['name'] ?? '');
            $campaign = MetaAdCampaign::query()->firstOrNew([
                'connection_id' => $connection->getKey(),
                'campaign_id' => (string) $row['id'],
            ]);

            if (! $campaign->exists) {
                $campaign->forceFill([
                    'organization_id' => $connection->organization_id,
                    'service_group_id' => $groups->first(fn ($g) => Str::contains($name, $g->name, ignoreCase: true))?->id,
                    'state' => self::stateIn($name),
                ]);
            }

            $campaign->forceFill(['name' => $name, 'objective' => $row['objective'] ?? null])->save();
            $count++;
        }

        return $count;
    }

    /** Daily ad-set rows from $from to $to, totals and (when asked) by region. */
    public function sync(SocialConnection $connection, Carbon $from, Carbon $to, bool $regions): int
    {
        $written = 0;

        // 30-day slices: one GET per slice stays well inside Meta's timeout.
        for ($start = $from->copy()->startOfDay(); $start->lte($to); $start->addDays(30)) {
            $end = $start->copy()->addDays(29)->min($to);

            foreach ($regions ? [false, true] : [false] as $byRegion) {
                $rows = [];

                foreach ($this->pages($connection, $connection->remote_id.'/insights', array_filter([
                    'level' => 'adset',
                    'fields' => 'campaign_id,adset_id,adset_name,spend,impressions,clicks,actions,account_currency',
                    'time_range' => json_encode(['since' => $start->toDateString(), 'until' => $end->toDateString()]),
                    'time_increment' => 1,
                    'breakdowns' => $byRegion ? 'region' : null,
                    'limit' => 500,
                ])) as $row) {
                    $rows[] = [
                        'id' => (string) Str::uuid7(),
                        'organization_id' => $connection->organization_id,
                        'connection_id' => $connection->getKey(),
                        'date' => (string) $row['date_start'],
                        'campaign_id' => (string) $row['campaign_id'],
                        'adset_id' => (string) $row['adset_id'],
                        'adset_name' => $row['adset_name'] ?? null,
                        'region' => $byRegion ? (string) ($row['region'] ?? 'Unknown') : '',
                        'spend' => (float) ($row['spend'] ?? 0),
                        'impressions' => (int) ($row['impressions'] ?? 0),
                        'clicks' => (int) ($row['clicks'] ?? 0),
                        'leads' => self::leads((array) ($row['actions'] ?? [])),
                        'currency' => $row['account_currency'] ?? null,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ];
                }

                foreach (array_chunk($rows, 500) as $chunk) {
                    MetaAdInsight::query()->upsert($chunk, ['connection_id', 'date', 'adset_id', 'region'],
                        ['adset_name', 'campaign_id', 'spend', 'impressions', 'clicks', 'leads', 'currency', 'updated_at']);
                }

                $written += count($rows);
            }
        }

        return $written;
    }

    /**
     * Reports' read: one row per day x campaign, the day's totals only.
     *
     * @return list<array<string, mixed>>
     */
    public function rows(Organization $org, string $from, string $to): array
    {
        return $this->read($org, $from, $to, false);
    }

    /**
     * Reports' "where viewers were": one row per day x campaign x region. Never
     * add these to rows() - they are the same money split another way. Only
     * about the last 13 months exist; older dates return nothing, not zeros.
     *
     * @return list<array<string, mixed>>
     */
    public function regionRows(Organization $org, string $from, string $to): array
    {
        return $this->read($org, $from, $to, true);
    }

    /** @return list<array<string, mixed>> */
    private function read(Organization $org, string $from, string $to, bool $byRegion): array
    {
        return DB::table('meta_ad_insights as i')
            ->join('social_connections as c', 'c.id', '=', 'i.connection_id')
            ->leftJoin('meta_ad_campaigns as m', fn ($j) => $j->on('m.connection_id', '=', 'i.connection_id')->on('m.campaign_id', '=', 'i.campaign_id'))
            ->leftJoin('service_groups as g', 'g.id', '=', 'm.service_group_id')
            ->where('i.organization_id', $org->getKey())
            ->whereBetween('i.date', [$from, $to])
            ->where('i.region', $byRegion ? '!=' : '=', '')
            ->groupBy('i.date', 'c.remote_id', 'i.campaign_id', 'm.name', 'm.franchise_id', 'm.state', 'g.name', 'i.region', 'i.currency')
            ->orderBy('i.date')
            ->selectRaw('i.date, c.remote_id as ad_account_id, i.campaign_id, m.name as campaign_name, m.franchise_id as branch_id, '
                .'m.state, g.name as category, i.region, i.currency, ROUND(SUM(i.spend) * 100) as spend_minor, SUM(i.leads) as results, '
                .'SUM(i.clicks) as clicks, SUM(i.impressions) as impressions')
            ->get()
            ->map(fn ($r) => [
                'date' => (string) $r->date,
                'ad_account_id' => $r->ad_account_id,
                'campaign_id' => $r->campaign_id,
                'campaign_name' => $r->campaign_name,
                'branch_id' => $r->branch_id,
                'state' => $r->state,
                'category' => $r->category,
                'region' => $byRegion ? $r->region : null,
                'currency' => $r->currency,
                'spend_minor' => (int) $r->spend_minor,
                'results' => (int) $r->results,
                'result_type' => 'lead',
                'clicks' => (int) $r->clicks,
                'impressions' => (int) $r->impressions,
            ])->all();
    }

    /** A two-letter state named in a campaign name: "Texas" or a standalone "TX". */
    public static function stateIn(string $name): ?string
    {
        // Full names first: a standalone "IN" or "OR" is likelier a word than Indiana.
        foreach (self::STATES as $code => $state) {
            if (preg_match('/\b'.preg_quote($state, '/').'\b/i', $name)) {
                return $code;
            }
        }

        foreach (array_keys(self::STATES) as $code) {
            if (preg_match('/(?<![A-Za-z])'.$code.'(?![A-Za-z])/', $name)) {
                return $code;
            }
        }

        return null;
    }

    /** Meta's "lead" action is the total of every lead kind (form, Messenger, website). */
    private static function leads(array $actions): int
    {
        foreach ($actions as $action) {
            if (is_array($action) && ($action['action_type'] ?? null) === 'lead') {
                return (int) ($action['value'] ?? 0);
            }
        }

        return 0;
    }

    /**
     * Every row of a paged Graph edge.
     *
     * @param  array<string, mixed>  $query
     * @return \Generator<array<string, mixed>>
     */
    private function pages(SocialConnection $connection, string $path, array $query): \Generator
    {
        $token = $connection->credentials['access_token'] ?? null;

        if (! is_string($token) || $token === '') {
            throw new RuntimeException('This Facebook Ads connection has no token. Connect it again.');
        }

        $url = 'https://graph.facebook.com/'.FacebookOAuth::GRAPH_VERSION.'/'.$path;
        $query['access_token'] = $token;

        while ($url !== null) {
            try {
                $response = Http::timeout(self::TIMEOUT)->get($url, $query);
            } catch (ConnectionException) {
                throw new RuntimeException('Meta did not answer the ads read.');
            }

            if ($response->failed()) {
                // The message, never the URL: the URL carries the token.
                throw new RuntimeException('Meta refused the ads read: '.$response->json('error.message', 'HTTP '.$response->status()));
            }

            yield from (array) $response->json('data', []);

            $next = $response->json('paging.next');
            $url = is_string($next) ? $next : null;
            $query = [];
        }
    }
}
