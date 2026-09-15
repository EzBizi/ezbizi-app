<?php

namespace Tests\Feature\Ads;

use App\Models\SocialConnection;
use App\Support\Social\SocialProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/**
 * Spend and performance, and the five fields Google requires with them.
 *
 * R.10 (Customer) and R.20 (Campaign) each mandate clicks, cost_micros,
 * impressions, conversions and all_conversions. They are not a design choice
 * and they are not ours to trim: showing the Campaign level at all is what
 * makes R.20 apply, and a level displayed without its required fields is a
 * policy breach rather than a missing feature.
 *
 * So the assertions below are about the CONTRACT with Google, not about
 * arithmetic. If somebody later drops a column because it looked redundant on
 * screen, this goes red and names the requirement it broke.
 */
class CampaignPerformanceTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    /** The five, exactly as the policy lists them. */
    private const REQUIRED = ['clicks', 'impressions', 'conversions', 'all_conversions', 'cost'];

    private function connected(array $t): SocialConnection
    {
        return $this->inTenant($t['org'], function () use ($t) {
            $row = SocialConnection::create([
                'organization_id' => $t['org']->id,
                'scope_type' => SocialConnection::SCOPE_COMPANY,
                'scope_id' => $t['company']->id,
                'provider' => SocialProvider::GoogleAds->value,
                'remote_id' => '4051729356',
                'display_name' => 'A1 Chimney - Cleveland',
            ]);
            $row->connect(['refresh_token' => 'rt-good']);
            $row->save();

            return $row;
        });
    }

    private function manager(array $t): SocialConnection
    {
        return $this->inTenant($t['org'], function () use ($t) {
            $row = SocialConnection::create([
                'organization_id' => $t['org']->id,
                'scope_type' => SocialConnection::SCOPE_COMPANY,
                'scope_id' => $t['company']->id,
                'provider' => SocialProvider::GoogleAds->value,
                'remote_id' => '9998887776',
                'display_name' => 'A1 Chimney - Ads Manager',
            ]);
            $row->connect(['refresh_token' => 'rt-good']);
            $row->save();

            return $row;
        });
    }

    private function fakeGoogle(array $customerRow, array $campaignRows): void
    {
        config([
            'services.google_ads.client_id' => 'client.apps.googleusercontent.com',
            'services.google_ads.client_secret' => 'secret',
            'services.google_ads.developer_token' => 'dev-token',
            'services.google_ads.version' => 'v24',
        ]);

        // One fake, sequenced: the service asks for the account first and the
        // campaigns second. A second Http::fake() would be ignored - it stacks
        // rather than replaces - and both calls would get the account payload.
        Http::fake([
            'oauth2.googleapis.com/token' => Http::response(['access_token' => 'at', 'expires_in' => 3600], 200),
            'googleads.googleapis.com/*' => Http::sequence()
                ->push(['results' => [$customerRow]], 200)
                ->push(['results' => $campaignRows], 200)
                ->whenEmpty(Http::response(['results' => []], 200)),
        ]);
    }

    private function customerRow(): array
    {
        return [
            // Segmented by day: the account totals are SUMMED from this rather
            // than fetched separately, so a row without segments.date would be
            // dropped from the series and silently absent from the totals.
            'segments' => ['date' => '2026-09-01'],
            'customer' => [
                'id' => '4051729356',
                'descriptiveName' => 'A1 Chimney - Cleveland',
                'currencyCode' => 'USD',
                'timeZone' => 'America/New_York',
            ],
            'metrics' => [
                'clicks' => '412',
                'impressions' => '10500',
                'conversions' => 31.5,
                'allConversions' => 44.25,
                'costMicros' => '1234560000',
            ],
        ];
    }

    public function test_the_account_level_carries_every_field_r10_requires(): void
    {
        $t = $this->provisionTenant('A1-Group');
        $row = $this->connected($t);
        $this->fakeGoogle($this->customerRow(), []);

        $account = $this->signedInAs($t['admin']->fresh())
            ->getJson('/api/v1/ads/performance?connection_id='.$row->getKey())
            ->assertOk()
            ->json('data.0.account');

        foreach (self::REQUIRED as $field) {
            $this->assertArrayHasKey(
                $field,
                $account,
                'R.10 requires '.$field.' at the Account level; a level shown without its '
                .'required fields is a policy breach, not a missing column.',
            );
        }

        $this->assertSame(412, $account['clicks']);
        $this->assertSame(10500, $account['impressions']);
        $this->assertSame(31.5, $account['conversions']);
        $this->assertSame(44.25, $account['all_conversions']);

        // 1,234,560,000 micros is 1,234.56 - not 1,234,560,000, and not 1.23.
        // A client that forgot the divisor would render a plausible number a
        // thousand times too large, which is the kind of wrong that reads as a
        // real figure.
        $this->assertSame(1234.56, $account['cost']);
        $this->assertSame('USD', $account['currency']);
    }

    public function test_the_campaign_level_carries_every_field_r20_requires(): void
    {
        $t = $this->provisionTenant('A1-Group');
        $row = $this->connected($t);

        $this->fakeGoogle($this->customerRow(), [
            [
                'campaign' => [
                    'id' => '111',
                    'name' => 'Chimney - Cleveland - Search',
                    'status' => 'ENABLED',
                    'advertisingChannelType' => 'SEARCH',
                ],
                'metrics' => [
                    'clicks' => '300', 'impressions' => '8000',
                    'conversions' => 20.0, 'allConversions' => 28.0,
                    'costMicros' => '900000000',
                ],
            ],
            [
                // A paused campaign with no activity at all. Google omits the
                // metrics block entirely for these, and a missing "clicks" on
                // screen cannot be told apart from a broken fetch - so the
                // service must render zero rather than null.
                'campaign' => [
                    'id' => '222',
                    'name' => 'Chimney - Cleveland - Display',
                    'status' => 'PAUSED',
                    'advertisingChannelType' => 'DISPLAY',
                ],
            ],
        ]);

        $campaigns = $this->signedInAs($t['admin']->fresh())
            ->getJson('/api/v1/ads/performance?connection_id='.$row->getKey())
            ->assertOk()
            ->json('data.0.campaigns');

        $this->assertCount(2, $campaigns);

        foreach ($campaigns as $campaign) {
            foreach (self::REQUIRED as $field) {
                $this->assertArrayHasKey(
                    $field,
                    $campaign,
                    'R.20 requires '.$field.' at the Campaign level.',
                );
            }

            // Required by R.20 whenever paused or removed campaigns are shown,
            // and this shows them.
            $this->assertArrayHasKey('status', $campaign);
        }

        // assertEquals, not assertSame, and the reason is a live client trap:
        // a whole-number float goes through JSON as `900`, not `900.0`, so the
        // decoded type is int for round figures and float otherwise. Dart's
        // `as double` throws on an int, so the client must read money as
        // `(json['cost'] as num).toDouble()` - asserting the strict type here
        // would pin a guarantee the wire cannot make.
        $this->assertEquals(900.0, $campaigns[0]['cost']);
        $this->assertIsNumeric($campaigns[0]['cost']);

        $this->assertSame(0, $campaigns[1]['clicks'], 'a campaign with no activity reports zero, not null');
        $this->assertEquals(0, $campaigns[1]['cost']);
        $this->assertSame('PAUSED', $campaigns[1]['status']);
    }

    /**
     * The five must be ASKED FOR, not just rendered.
     *
     * The field assertions above cannot catch this on their own, and that is
     * worth stating rather than discovering: `totals()` defaults every metric
     * to 0 so that a campaign with no activity shows zero instead of a blank.
     * That default also manufactures the key for a metric the query never
     * requested - so dropping `conversions` from the GAQL would leave both
     * tests green and put a permanent, plausible 0 on screen.
     *
     * A zero we never asked Google for is worse than a missing column: it is a
     * number somebody will act on.
     */
    public function test_both_queries_actually_request_all_five_metrics(): void
    {
        $t = $this->provisionTenant('A1-Group');
        $row = $this->connected($t);
        $this->fakeGoogle($this->customerRow(), []);

        $this->signedInAs($t['admin']->fresh())->getJson('/api/v1/ads/performance?connection_id='.$row->getKey())->assertOk();

        $queries = [];

        Http::assertSent(function ($request) use (&$queries) {
            if (str_contains($request->url(), 'googleAds:search')) {
                $queries[] = $request->data()['query'] ?? '';
            }

            return true;
        });

        $this->assertCount(2, $queries, 'one query for the Account level, one for the Campaign level');

        foreach ($queries as $query) {
            foreach ([
                'metrics.clicks',
                'metrics.cost_micros',
                'metrics.impressions',
                'metrics.conversions',
                'metrics.all_conversions',
            ] as $metric) {
                $this->assertStringContainsString(
                    $metric,
                    $query,
                    $metric.' is mandatory at every level we display. Without it in the query the '
                    .'screen still shows a 0, which is a fabricated figure rather than a gap.',
                );
            }
        }

        // The account currency, because cost_micros without it is a number with
        // no unit.
        $this->assertStringContainsString('customer.currency_code', $queries[0]);

        // campaign.status, required by R.20 because paused campaigns are shown.
        $this->assertStringContainsString('campaign.status', $queries[1]);
    }

    public function test_it_asks_google_for_no_level_below_campaign(): void
    {
        $t = $this->provisionTenant('A1-Group');
        $row = $this->connected($t);
        $this->fakeGoogle($this->customerRow(), []);

        $this->signedInAs($t['admin']->fresh())->getJson('/api/v1/ads/performance?connection_id='.$row->getKey())->assertOk();

        // The scope IS the compliance position. Showing an ad group, ad or
        // keyword makes R.40, R.50, R.70, R.100 and R.130 apply, each with its
        // own mandatory fields, and obliges two weeks' notice to Google before
        // the change takes effect. A query that quietly reaches one level lower
        // is how that happens without anybody deciding it.
        Http::assertSent(function ($request) {
            if (! str_contains($request->url(), 'googleAds:search')) {
                return true;
            }

            $query = $request->data()['query'] ?? '';

            foreach (['ad_group', 'ad_group_ad', 'keyword_view', 'search_term_view', 'bidding_strategy'] as $lower) {
                $this->assertStringNotContainsString(
                    'FROM '.$lower,
                    $query,
                    'reading '.$lower.' puts a level on screen we have told Google we do not show',
                );
            }

            return true;
        });
    }

    public function test_one_refusing_account_does_not_blank_the_report(): void
    {
        $t = $this->provisionTenant('A1-Group');
        $row = $this->connected($t);

        config([
            'services.google_ads.client_id' => 'client.apps.googleusercontent.com',
            'services.google_ads.client_secret' => 'secret',
            'services.google_ads.developer_token' => 'dev-token',
            'services.google_ads.version' => 'v24',
        ]);

        Http::fake([
            'oauth2.googleapis.com/token' => Http::response(['access_token' => 'at', 'expires_in' => 3600], 200),
            'googleads.googleapis.com/*' => Http::response([
                'error' => ['details' => [['errors' => [['message' => "The customer account can't be accessed because it is not yet enabled or has been deactivated."]]]]],
            ], 403),
        ]);

        $body = $this->signedInAs($t['admin']->fresh())
            ->getJson('/api/v1/ads/performance?connection_id='.$row->getKey())
            ->assertOk()
            ->json();

        $this->assertSame([], $body['data']);
        $this->assertCount(1, $body['refused']);
        $this->assertStringContainsString(
            'deactivated',
            $body['refused'][0]['because'],
            "Google's own sentence, not a status code - A-1 holds accounts Google itself "
            .'calls deactivated and the reader needs to know which problem this is',
        );
    }

    /**
     * Opening the screen must cost Google NOTHING.
     *
     * This is the whole point of the account list existing. The screen used to
     * load every connected account at once: 53 accounts x 2 queries = 106
     * operations of a 15,000/day ceiling, spent before anybody had asked for a
     * single figure, and 47 seconds of spinner. A peer was about to make those
     * calls concurrent, which would have made it fast and cost exactly the same
     * quota.
     *
     * So the assertion is not "it is quicker". It is that nothing reaches
     * Google at all.
     */
    public function test_listing_the_accounts_asks_google_nothing(): void
    {
        $t = $this->provisionTenant('A1-Group');
        $this->connected($t);
        $this->fakeGoogle($this->customerRow(), []);

        $body = $this->signedInAs($t['admin']->fresh())
            ->getJson('/api/v1/ads/accounts')
            ->assertOk()
            ->json();

        $this->assertCount(1, $body['data']);
        $this->assertSame('A1 Chimney - Cleveland', $body['data'][0]['name']);
        $this->assertSame('4051729356', $body['data'][0]['customer_id']);

        Http::assertNothingSent();
    }

    /**
     * And the report refuses to fan out even if somebody asks it to.
     *
     * `connection_id` used to be optional and meant "all of them". An optional
     * fan-out is the kind of default that spends a tenant's whole daily quota
     * because a tab was left open, so its absence is a 422 rather than a
     * helpful default.
     */
    public function test_the_report_will_not_run_without_naming_one_account(): void
    {
        $t = $this->provisionTenant('A1-Group');
        $this->connected($t);
        $this->fakeGoogle($this->customerRow(), []);

        $this->signedInAs($t['admin']->fresh())
            ->getJson('/api/v1/ads/performance')
            ->assertStatus(422);

        Http::assertNothingSent();
    }

    /**
     * The chart's data, and the totals summed from the same rows.
     *
     * One query serves both deliberately. A separate unsegmented query for the
     * totals would cost a third of the quota to produce a number these rows
     * already contain, and the two can disagree - so a header contradicting its
     * own chart would be possible. Here it is not.
     */
    /**
     * The account list says which rows are managers.
     *
     * The whole roll-up hangs off this one boolean: the screen decides which
     * endpoint to call from it, so a list that always says false would quietly
     * show a manager as a screen of zeroes and nobody would see an error.
     *
     * Worked out from the rows themselves - an account is a manager if another
     * connected account was reached THROUGH it - because asking Google would
     * put a network call back into the one screen that must make none.
     */
    public function test_the_account_list_marks_the_managers(): void
    {
        $t = $this->provisionTenant('A1-Group');
        $child = $this->connected($t);
        $manager = $this->manager($t);

        $this->inTenant($t['org'], function () use ($child, $manager) {
            $child->forceFill(['login_customer_id' => $manager->remote_id])->save();
        });

        $body = $this->signedInAs($t['admin']->fresh())
            ->getJson('/api/v1/ads/accounts')
            ->assertOk()
            ->json();

        $rows = collect($body['data'])->keyBy('customer_id');

        $this->assertTrue($rows['9998887776']['is_manager']);
        $this->assertFalse(
            $rows['4051729356']['is_manager'],
            'an account offered a roll-up of itself asks Google for a tree it is not the root of',
        );

        Http::assertNothingSent();
    }

    /**
     * A manager account is an overview of everything beneath it.
     *
     * Jose, 2026-09-15: "the manager accounts like Angi Sweep MCC and A1
     * Chimney - Ads Manager should work as the overview for all the accounts
     * under it". A manager holds no spend of its own, so the per-account
     * report on one is a screen of zeroes.
     *
     * The assertion that matters is the LAST one. Google's tree holds accounts
     * we have no token for, and a total that quietly covers three of five
     * accounts is a smaller number wearing the clothes of a complete one.
     */
    public function test_a_manager_rolls_up_the_accounts_beneath_it(): void
    {
        $t = $this->provisionTenant('A1-Group');
        $child = $this->connected($t);
        $manager = $this->manager($t);

        config([
            'services.google_ads.client_id' => 'client.apps.googleusercontent.com',
            'services.google_ads.client_secret' => 'secret',
            'services.google_ads.developer_token' => 'dev-token',
            'services.google_ads.version' => 'v24',
        ]);

        Http::fake([
            'oauth2.googleapis.com/token' => Http::response(['access_token' => 'at', 'expires_in' => 3600], 200),
            'googleads.googleapis.com/*' => Http::sequence()
                // The tree: the manager itself, one account we hold a token
                // for, one we do not, and a nested manager.
                ->push(['results' => [
                    ['customerClient' => ['id' => '9998887776', 'descriptiveName' => 'A1 Chimney - Ads Manager', 'manager' => true]],
                    ['customerClient' => ['id' => '4051729356', 'descriptiveName' => 'A1 Chimney - Cleveland']],
                    ['customerClient' => ['id' => '1112223334', 'descriptiveName' => 'Somebody Else']],
                    ['customerClient' => ['id' => '5556667778', 'descriptiveName' => 'Nested MCC', 'manager' => true]],
                ]], 200)
                ->push(['results' => [$this->customerRow()]], 200),
        ]);

        $body = $this->signedInAs($t['admin']->fresh())
            ->getJson('/api/v1/ads/rollup?connection_id='.$manager->getKey())
            ->assertOk()
            ->json();

        $this->assertCount(1, $body['data']);
        $this->assertSame($child->getKey(), $body['data'][0]['connection_id']);
        $this->assertEquals(412, $body['data'][0]['clicks']);

        foreach (self::REQUIRED as $field) {
            $this->assertArrayHasKey(
                $field,
                $body['data'][0],
                "R.10 applies to every account row the roll-up displays, and $field is one of the five",
            );
        }

        // Two children, one readable. The manager's own row and the nested
        // manager are not children - counting them would inflate both numbers
        // and imply spend that is already counted underneath.
        $this->assertSame(2, $body['children_total']);
        $this->assertSame(
            1,
            $body['not_connected'],
            'a roll-up that silently omits accounts reads as a complete total',
        );
    }

    /**
     * And the roll-up does not ask for campaigns.
     *
     * One query per child, not two. A manager with forty accounts is forty
     * operations of a 15,000/day ceiling; asking each of them for its campaign
     * breakdown as well would double that to produce rows nothing on the
     * overview draws.
     */
    public function test_the_rollup_costs_one_query_per_child(): void
    {
        $t = $this->provisionTenant('A1-Group');
        $this->connected($t);
        $manager = $this->manager($t);

        config([
            'services.google_ads.client_id' => 'client.apps.googleusercontent.com',
            'services.google_ads.client_secret' => 'secret',
            'services.google_ads.developer_token' => 'dev-token',
            'services.google_ads.version' => 'v24',
        ]);

        Http::fake([
            'oauth2.googleapis.com/token' => Http::response(['access_token' => 'at', 'expires_in' => 3600], 200),
            'googleads.googleapis.com/*' => Http::sequence()
                ->push(['results' => [
                    ['customerClient' => ['id' => '4051729356', 'descriptiveName' => 'A1 Chimney - Cleveland']],
                ]], 200)
                ->push(['results' => [$this->customerRow()]], 200)
                // A third query must be ANSWERED rather than thrown on. An
                // exhausted sequence throws before the request is sent, so the
                // extra call would never be recorded and this test would pass
                // on exactly the fan-out it exists to forbid. It did.
                ->whenEmpty(Http::response(['results' => []], 200)),
        ]);

        $this->signedInAs($t['admin']->fresh())
            ->getJson('/api/v1/ads/rollup?connection_id='.$manager->getKey())
            ->assertOk();

        $queries = [];

        Http::assertSent(function ($request) use (&$queries) {
            if (str_contains($request->url(), 'googleads.googleapis.com')) {
                $queries[] = (string) ($request->data()['query'] ?? '');
            }

            return true;
        });

        $this->assertCount(2, $queries, 'one tree query, then one per child - nothing else');
        $this->assertStringContainsString('FROM customer_client', $queries[0]);
        $this->assertStringContainsString('FROM customer ', $queries[1]);

        foreach ($queries as $query) {
            $this->assertStringNotContainsString(
                'FROM campaign',
                $query,
                'the overview draws no campaign rows, so asking for them spends quota on nothing',
            );
        }
    }

    public function test_the_series_is_daily_and_the_totals_are_its_sum(): void
    {
        $t = $this->provisionTenant('A1-Group');
        $row = $this->connected($t);

        $day = fn (string $date, string $cost, string $clicks) => [
            'segments' => ['date' => $date],
            'customer' => ['id' => '4051729356', 'currencyCode' => 'USD'],
            'metrics' => [
                'costMicros' => $cost, 'clicks' => $clicks,
                'impressions' => '100', 'conversions' => 1.0, 'allConversions' => 1.0,
            ],
        ];

        config([
            'services.google_ads.client_id' => 'client.apps.googleusercontent.com',
            'services.google_ads.client_secret' => 'secret',
            'services.google_ads.developer_token' => 'dev-token',
            'services.google_ads.version' => 'v24',
        ]);

        Http::fake([
            'oauth2.googleapis.com/token' => Http::response(['access_token' => 'at'], 200),
            'googleads.googleapis.com/*' => Http::sequence()
                ->push(['results' => [
                    $day('2026-09-01', '1000000', '10'),
                    $day('2026-09-02', '2500000', '20'),
                ]], 200)
                ->push(['results' => []], 200)
                ->whenEmpty(Http::response(['results' => []], 200)),
        ]);

        $body = $this->signedInAs($t['admin']->fresh())
            ->getJson('/api/v1/ads/performance?connection_id='.$row->getKey())
            ->assertOk()
            ->json('data.0');

        $cost = collect($body['series'])->firstWhere('key', 'cost');

        $this->assertSame('Cost', $cost['label']);
        // assertEquals, not assertSame, and it is the same wire fact as the cost
        // column: round(1.0, 2) is a float in PHP, crosses JSON as `1`, and
        // decodes as an int. A strict comparison here would be pinning a
        // guarantee the wire cannot make - and the CLIENT has to read these as
        // `as num` for exactly that reason.
        $this->assertEquals(
            [['at' => '2026-09-01', 'value' => 1.0], ['at' => '2026-09-02', 'value' => 2.5]],
            $cost['points'],
            'one point per day, in order, in the account currency',
        );

        // 1.00 + 2.50. If the totals came from a second query this could differ.
        $this->assertEquals(3.5, $body['account']['cost']);
        $this->assertSame(30, $body['account']['clicks']);
    }

    public function test_reading_spend_needs_more_than_reading_enquiries(): void
    {
        $t = $this->provisionTenant('A1-Group');
        $row = $this->connected($t);
        $this->fakeGoogle($this->customerRow(), []);

        // Dispatcher holds leads.view and not ads_performance.view. Somebody
        // who may read an enquiry and reply to it may not see what the business
        // pays to receive it.
        $dispatcher = $this->userWithRole($t, 'Dispatcher', ['leads.view']);

        $this->signedInAs($dispatcher->fresh())
            ->getJson('/api/v1/ads/performance?connection_id='.$row->getKey())
            ->assertForbidden();

        // Positive control: the same request, by somebody who does hold it.
        $this->signedInAs($t['admin']->fresh())
            ->getJson('/api/v1/ads/performance?connection_id='.$row->getKey())
            ->assertOk();
    }
}
