<?php

namespace Tests\Feature\Leads;

use App\Models\Lead;
use App\Models\SocialConnection;
use App\Support\Social\SocialProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/**
 * Placing a connected account at a franchise, and fetching without a person.
 *
 * Two facts about a connection are kept apart. `scope_type`/`scope_id` says who
 * can SEE and ACT ON it; `leads_franchise_id` says where its LEADS land.
 *
 * This docblock used to add "merging them would have hidden an account from the
 * company that just assigned it". That was measured on 2026-09-14 and is false —
 * inView()'s COMPANY branch has included franchise-scoped connections since
 * d3f17b70 on 2026-08-21, and canActOnFranchise() lets a company-level
 * assignment act on its franchises. The migration carries the full correction.
 * What the tests below actually pin is the behaviour, which is unchanged and
 * still worth pinning; only the reason given for it was wrong.
 */
class LeadPlacementAndScheduledSyncTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    /** @param array{org: \App\Models\Organization, company: \App\Models\Company} $t */
    private function connection(array $t, string $customerId = '4051729356'): SocialConnection
    {
        return $this->inTenant($t['org'], function () use ($t, $customerId) {
            $c = new SocialConnection;
            $c->forceFill([
                'organization_id' => $t['org']->id,
                'scope_type'      => SocialConnection::SCOPE_COMPANY,
                'scope_id'        => $t['company']->id,
                'provider'        => SocialProvider::GoogleAds->value,
                'remote_id'       => $customerId,
                'display_name'    => $customerId,
            ]);
            $c->connect(['refresh_token' => 'rt-'.$customerId]);
            $c->save();

            return $c;
        });
    }

    private function fakeGoogle(array $leadIds = []): void
    {
        config([
            'services.google_ads.client_id'       => 'client.apps.googleusercontent.com',
            'services.google_ads.client_secret'   => 'client-secret',
            'services.google_ads.developer_token' => 'dev-token',
            'services.google_ads.version'         => 'v24',
        ]);

        $results = array_map(fn (string $id) => [
            'localServicesLead' => [
                'id'               => $id,
                'leadType'         => 'PHONE_CALL',
                'leadStatus'       => 'ACTIVE',
                'creationDateTime' => '2026-09-08 09:00:00',
                'contactDetails'   => ['phoneNumber' => '+13055550101'],
            ],
        ], $leadIds);

        Http::fake([
            'oauth2.googleapis.com/token' => Http::response(['access_token' => 'at-fresh', 'expires_in' => 3600], 200),
            'googleads.googleapis.com/*'  => Http::response(['results' => $results], 200),
        ]);
    }

    private function place(array $t, SocialConnection $c, ?string $franchiseId)
    {
        return $this->signedInAs($t['admin'])->patchJson(
            "/api/v1/social/connections/{$c->getKey()}/leads-franchise",
            ['leads_franchise_id' => $franchiseId],
        );
    }

    private function rename(array $t, SocialConnection $c, ?string $label)
    {
        return $this->signedInAs($t['admin'])->patchJson(
            "/api/v1/social/connections/{$c->getKey()}/label",
            ['label' => $label],
        );
    }

    public function test_an_account_can_be_named_and_the_name_reaches_the_leads_screen(): void
    {
        $t = $this->provisionTenant('A1-Chimney');
        $c = $this->connection($t);

        $this->rename($t, $c, 'Connecticut')->assertOk()->assertJsonPath('data.label', 'Connecticut');

        $row = $this->signedInAs($t['admin'])
            ->getJson('/api/v1/leads')
            ->assertOk()
            ->json('meta.connections.0');

        // The name a person chose, beside the number Google gave. Both, because
        // the label is how you find the row and display_name is how you check
        // it against the account in Google Ads.
        $this->assertSame('Connecticut', $row['label']);
        $this->assertSame('4051729356', $row['display_name']);
    }

    public function test_an_unnamed_account_reports_null_rather_than_an_empty_string(): void
    {
        $t = $this->provisionTenant('A1-Chimney');
        $this->connection($t);

        $row = $this->signedInAs($t['admin'])
            ->getJson('/api/v1/leads')
            ->assertOk()
            ->json('meta.connections.0');

        $this->assertNull($row['label']);
    }

    public function test_a_blank_name_clears_it_instead_of_storing_an_empty_string(): void
    {
        $t = $this->provisionTenant('A1-Chimney');
        $c = $this->connection($t);

        $this->rename($t, $c, 'Connecticut')->assertOk();

        // "Unnamed" must have exactly one representation, or the screen has to
        // decide what '' means every time it draws a row.
        $this->rename($t, $c, '   ')->assertOk()->assertJsonPath('data.label', null);

        $this->assertNull($this->inTenant($t['org'], fn () => $c->fresh()->label));
        $this->assertDatabaseHas('audit_events', ['action' => 'social.renamed']);
    }

    public function test_naming_answers_to_the_module_that_reads_the_account(): void
    {
        $t = $this->provisionTenant('A1-Chimney');
        $c = $this->connection($t);

        // Same gate as connecting, disconnecting and placing. Marketing alone
        // must not rename an account it is deliberately kept away from.
        $poster = $this->userWithRole($t, 'Poster', ['marketing.view', 'marketing.edit']);

        $this->signedInAs($poster)
            ->patchJson("/api/v1/social/connections/{$c->getKey()}/label", ['label' => 'Mine now'])
            ->assertForbidden();
    }

    public function test_name_accounts_replaces_digits_with_googles_name_and_leaves_named_rows_alone(): void
    {
        $t = $this->provisionTenant('A1-Chimney');

        // Connected before names existed: stored under its digits.
        $unnamed = $this->connection($t, '4941850746');

        // Already named - by an earlier run, or by Google at connect time.
        $named = $this->connection($t, '2976318162');
        $this->inTenant($t['org'], fn () => $named->forceFill(['display_name' => 'A1 Chimney - Akron'])->save());

        config([
            'services.google_ads.client_id'       => 'client.apps.googleusercontent.com',
            'services.google_ads.client_secret'   => 'client-secret',
            'services.google_ads.developer_token' => 'dev-token',
            'services.google_ads.version'         => 'v24',
        ]);

        Http::fake([
            'oauth2.googleapis.com/token' => Http::response(['access_token' => 'at-fresh', 'expires_in' => 3600], 200),
            'googleads.googleapis.com/v24/customers/4941850746/googleAds:search' => Http::response(
                ['results' => [['customer' => ['id' => '4941850746', 'descriptiveName' => 'A1 Chimney - Cleveland']]]],
                200,
            ),
            'googleads.googleapis.com/v24/customers/2976318162/googleAds:search' => Http::response(
                ['results' => [['customer' => ['id' => '2976318162', 'descriptiveName' => 'SHOULD NOT BE ASKED']]]],
                200,
            ),
        ]);

        $this->artisan('leads:name-accounts')->assertSuccessful();

        $this->assertSame(
            'A1 Chimney - Cleveland',
            $this->inTenant($t['org'], fn () => $unnamed->fresh()->display_name),
        );
        $this->assertSame(
            'A1 Chimney - Akron',
            $this->inTenant($t['org'], fn () => $named->fresh()->display_name),
            'A row that already has a real name must not be re-asked or overwritten.',
        );

        // Idempotent and quota-cheap: the named row was never asked.
        Http::assertNotSent(fn ($request) => str_contains($request->url(), '2976318162'));
    }

    public function test_the_connections_payload_carries_placement_and_a_lead_count(): void
    {
        $t = $this->provisionTenant('A1-Chimney');
        $c = $this->connection($t);

        $this->fakeGoogle(['L-1', 'L-2']);
        $this->signedInAs($t['admin'])->postJson('/api/v1/leads/sync')->assertOk();

        $this->place($t, $c, $t['franchise']->id)->assertOk();

        $row = $this->signedInAs($t['admin'])
            ->getJson('/api/v1/leads')
            ->assertOk()
            ->json('meta.connections.0');

        // Without these the screen cannot draw an account's placement on load,
        // and cannot tell the two busy accounts from the twenty silent ones.
        $this->assertSame($t['franchise']->id, $row['leads_franchise_id']);
        $this->assertSame($t['franchise']->name, $row['leads_franchise_name']);
        $this->assertSame(2, $row['leads_count']);
    }

    public function test_an_account_at_company_level_reports_no_placement_and_still_counts(): void
    {
        $t = $this->provisionTenant('A1-Chimney');
        $this->connection($t);

        $this->fakeGoogle(['L-1']);
        $this->signedInAs($t['admin'])->postJson('/api/v1/leads/sync')->assertOk();

        $row = $this->signedInAs($t['admin'])
            ->getJson('/api/v1/leads')
            ->assertOk()
            ->json('meta.connections.0');

        $this->assertNull($row['leads_franchise_id']);
        $this->assertNull($row['leads_franchise_name']);
        $this->assertSame(1, $row['leads_count']);
    }

    public function test_placing_reports_how_many_leads_it_moved(): void
    {
        $t = $this->provisionTenant('A1-Chimney');
        $c = $this->connection($t);

        $this->fakeGoogle(['L-1', 'L-2', 'L-3']);
        $this->signedInAs($t['admin'])->postJson('/api/v1/leads/sync')->assertOk();

        // The number somebody looks for to believe a retroactive move worked.
        $this->place($t, $c, $t['franchise']->id)
            ->assertOk()
            ->assertJsonPath('data.leads_moved', 3);
    }

    public function test_a_lead_from_a_placed_account_lands_at_the_franchise_and_stays_visible_to_the_company(): void
    {
        $t = $this->provisionTenant('A1-Chimney');
        $c = $this->connection($t);

        $this->place($t, $c, $t['franchise']->id)->assertOk();

        $this->fakeGoogle(['L-1']);

        $this->signedInAs($t['admin'])->postJson('/api/v1/leads/sync')->assertOk();

        $lead = $this->inTenant($t['org'], fn () => Lead::where('remote_id', 'L-1')->firstOrFail());

        $this->assertSame($t['franchise']->id, $lead->franchise_id);

        // The company column is untouched, which is what keeps the row visible
        // to the company view. Placing an account must not hide its leads from
        // the people who placed it.
        $this->assertSame($t['company']->id, $lead->company_id);
    }

    public function test_placing_an_account_moves_the_leads_it_already_produced(): void
    {
        $t = $this->provisionTenant('A1-Chimney');
        $c = $this->connection($t);

        $this->fakeGoogle(['L-OLD']);
        $this->signedInAs($t['admin'])->postJson('/api/v1/leads/sync')->assertOk();

        $before = $this->inTenant($t['org'], fn () => Lead::where('remote_id', 'L-OLD')->firstOrFail());
        $this->assertNull($before->franchise_id, 'Fixture should start at company level.');

        $this->place($t, $c, $t['franchise']->id)
            ->assertOk()
            ->assertJsonPath('data.leads_franchise_id', $t['franchise']->id);

        $after = $this->inTenant($t['org'], fn () => Lead::where('remote_id', 'L-OLD')->firstOrFail());

        // Retroactive, on Jose's ruling: a manager setting this up expects the
        // branch to see its own history rather than an empty list.
        $this->assertSame($t['franchise']->id, $after->franchise_id);

        $this->assertDatabaseHas('audit_events', ['action' => 'social.leads_placed']);
    }

    public function test_placing_back_at_company_level_returns_the_leads(): void
    {
        $t = $this->provisionTenant('A1-Chimney');
        $c = $this->connection($t);

        $this->fakeGoogle(['L-1']);
        $this->signedInAs($t['admin'])->postJson('/api/v1/leads/sync')->assertOk();

        $this->place($t, $c, $t['franchise']->id)->assertOk();
        $this->place($t, $c, null)->assertOk();

        $lead = $this->inTenant($t['org'], fn () => Lead::where('remote_id', 'L-1')->firstOrFail());

        $this->assertNull($lead->franchise_id);
    }

    public function test_a_franchise_from_another_organization_is_refused(): void
    {
        $mine     = $this->provisionTenant('A1-Chimney');
        $stranger = $this->provisionTenant('Someone-Else');

        $c = $this->connection($mine);

        /*
         * A foreign key proves the row exists; it says nothing about whose it
         * is. Without the ownership check this is a cross-tenant write dressed
         * as a dropdown choice — one customer's enquiries routed into another
         * customer's list.
         */
        $this->place($mine, $c, $stranger['franchise']->id)->assertNotFound();

        $this->assertNull(
            $this->inTenant($mine['org'], fn () => $c->fresh()->leads_franchise_id),
        );
    }

    public function test_the_scheduled_sync_reaches_connections_in_more_than_one_organization(): void
    {
        $a = $this->provisionTenant('A1-Chimney');
        $b = $this->provisionTenant('Premier-Garage');

        $this->connection($a, '1111111111');
        $this->connection($b, '2222222222');

        $this->fakeGoogle(['L-SHARED']);

        /*
         * The trap this exists for: the scheduler is nobody. Without
         * withoutGlobalScopes() to find the work and TenantContext::run() to do
         * it, the tenant scope filters every connection to nothing and the
         * command reports a clean run having fetched from no one — which a
         * single-tenant test would never catch.
         */
        $this->artisan('ezbizi:sync-leads')->assertSuccessful();

        foreach ([$a, $b] as $t) {
            $this->assertSame(
                1,
                $this->inTenant($t['org'], fn () => Lead::query()->count()),
                'The scheduled sync skipped an organization.',
            );
        }
    }

    /**
     * Quota refusals stop the run; one account's own failure does not.
     *
     * Production, 2026-09-16: 2,033 refusals between 09:25 and 12:35, unbroken,
     * about 690 an hour against a sustained rate of roughly 600 - essentially
     * every call refused, and the command kept making them. The 15,000/day
     * ceiling belongs to the DEVELOPER TOKEN, so once it is spent asking the
     * every remaining account cannot succeed either.
     *
     * What it costs is not politeness. While the window rolls over, the few
     * operations that free up were being spent instantly by this command, so
     * nothing else could get a call through - including the Ads report Jose
     * had to film for Google.
     *
     * Asserted on the CALLS rather than on a log line, because a command that
     * logs "stopping" and carries on would satisfy the log.
     */
    public function test_a_run_stops_once_google_is_refusing_on_quota(): void
    {
        $t = $this->provisionTenant('A1-Chimney');

        foreach (['1111111111', '2222222222', '3333333333', '4444444444', '5555555555'] as $id) {
            $this->connection($t, $id);
        }

        config([
            'services.google_ads.client_id'       => 'client.apps.googleusercontent.com',
            'services.google_ads.client_secret'   => 'client-secret',
            'services.google_ads.developer_token' => 'dev-token',
            'services.google_ads.version'         => 'v24',
        ]);

        Http::fake([
            'oauth2.googleapis.com/token' => Http::response(['access_token' => 'at-fresh', 'expires_in' => 3600], 200),
            'googleads.googleapis.com/*' => Http::response([
                'error' => [
                    'code'    => 429,
                    'status'  => 'RESOURCE_EXHAUSTED',
                    'message' => 'Resource has been exhausted (e.g. check quota).',
                ],
            ], 429),
        ]);

        $this->artisan('ezbizi:sync-leads')->assertSuccessful();

        $calls = 0;

        Http::assertSent(function ($request) use (&$calls) {
            if (str_contains($request->url(), 'googleads.googleapis.com')) {
                $calls++;
            }

            return true;
        });

        $this->assertSame(
            3,
            $calls,
            'five accounts, all refused on quota: the run must stop after three in a row '
            .'rather than ask every one of them and then ask them all again for conversations',
        );
    }

    /**
     * And the sign is not flipped: ONE account failing still leaves the rest read.
     *
     * The bug this guards is the obvious over-correction - treating any refusal
     * as "the token is spent" and abandoning forty-nine healthy accounts
     * because one is suspended. Three DIFFERENT accounts refused back to back
     * is the evidence, and a success in between resets it.
     */
    public function test_one_quota_refusal_between_healthy_accounts_stops_nothing(): void
    {
        $t = $this->provisionTenant('A1-Chimney');

        foreach (['1111111111', '2222222222', '3333333333'] as $id) {
            $this->connection($t, $id);
        }

        config([
            'services.google_ads.client_id'       => 'client.apps.googleusercontent.com',
            'services.google_ads.client_secret'   => 'client-secret',
            'services.google_ads.developer_token' => 'dev-token',
            'services.google_ads.version'         => 'v24',
        ]);

        $ok = fn (string $leadId) => Http::response(['results' => [[
            'localServicesLead' => [
                'id'               => $leadId,
                'leadType'         => 'PHONE_CALL',
                'leadStatus'       => 'ACTIVE',
                'creationDateTime' => '2026-09-08 09:00:00',
                'contactDetails'   => ['phoneNumber' => '+13055550101'],
            ],
        ]]], 200);

        Http::fake([
            'oauth2.googleapis.com/token' => Http::response(['access_token' => 'at-fresh', 'expires_in' => 3600], 200),
            'googleads.googleapis.com/v24/customers/2222222222/*' => Http::response([
                'error' => ['code' => 429, 'status' => 'RESOURCE_EXHAUSTED'],
            ], 429),
            'googleads.googleapis.com/v24/customers/1111111111/*' => $ok('L-ONE'),
            'googleads.googleapis.com/v24/customers/3333333333/*' => $ok('L-THREE'),
        ]);

        $this->artisan('ezbizi:sync-leads')->assertSuccessful();

        $this->assertSame(
            2,
            $this->inTenant($t['org'], fn () => Lead::query()->count()),
            'a single quota refusal in the middle must not abandon the account after it',
        );
    }

    /**
     * THREE SUSPENDED ACCOUNTS IN A ROW MUST NOT ABANDON THE FOURTH.
     *
     * The over-correction, and the one a sabotage caught: counting ANY failure
     * towards the quota tally passes every test above, because none of them
     * has three non-quota failures in a row. A-1 holds accounts Google calls
     * deactivated, and three of them landing together is an ordinary Tuesday.
     *
     * Asserted on which customers were ASKED rather than on a count, so the
     * order the run happens to choose cannot make it pass by luck.
     */
    public function test_three_non_quota_failures_in_a_row_do_not_stop_the_run(): void
    {
        $t = $this->provisionTenant('A1-Chimney');

        $ids = ['1111111111', '2222222222', '3333333333', '4444444444'];

        foreach ($ids as $id) {
            $this->connection($t, $id);
        }

        config([
            'services.google_ads.client_id'       => 'client.apps.googleusercontent.com',
            'services.google_ads.client_secret'   => 'client-secret',
            'services.google_ads.developer_token' => 'dev-token',
            'services.google_ads.version'         => 'v24',
        ]);

        Http::fake([
            'oauth2.googleapis.com/token' => Http::response(['access_token' => 'at-fresh', 'expires_in' => 3600], 200),
            'googleads.googleapis.com/*' => Http::response([
                'error' => ['code' => 403, 'status' => 'PERMISSION_DENIED'],
            ], 403),
        ]);

        $this->artisan('ezbizi:sync-leads')->assertSuccessful();

        $asked = [];

        Http::assertSent(function ($request) use (&$asked) {
            if (preg_match('#/customers/(\d+)/#', $request->url(), $m)) {
                $asked[$m[1]] = true;
            }

            return true;
        });

        foreach ($ids as $id) {
            $this->assertArrayHasKey(
                $id,
                $asked,
                "account $id was never asked: a permission failure is about one account "
                .'and must not be counted towards the developer token being spent',
            );
        }
    }

    /**
     * A refused fetch records WHOSE limit it was.
     *
     * 2026-09-16 cost an afternoon to this: 2,033 refusals in three hours and
     * nothing in the log said whether the ceiling was on one account or on the
     * developer token. Those have opposite remedies, and the scope had to be
     * recovered by holding the scheduler's mutex and asking a person to load a
     * screen by hand.
     *
     * Google sends it in the response. This asserts it reaches the log, because
     * a field read and dropped is the same as a field never sent.
     */
    public function test_a_quota_refusal_is_logged_with_its_scope(): void
    {
        $t = $this->provisionTenant('A1-Chimney');
        $this->connection($t, '1111111111');

        config([
            'services.google_ads.client_id'       => 'client.apps.googleusercontent.com',
            'services.google_ads.client_secret'   => 'client-secret',
            'services.google_ads.developer_token' => 'dev-token',
            'services.google_ads.version'         => 'v24',
        ]);

        Http::fake([
            'oauth2.googleapis.com/token' => Http::response(['access_token' => 'at-fresh', 'expires_in' => 3600], 200),
            'googleads.googleapis.com/*' => Http::response([
                'error' => [
                    'code' => 429,
                    'status' => 'RESOURCE_EXHAUSTED',
                    'message' => 'Resource has been exhausted (e.g. check quota).',
                    'details' => [['errors' => [['details' => ['quotaErrorDetails' => [
                        'rateScope' => 'DEVELOPER',
                        'retryDelay' => '4413s',
                    ]]]]]],
                ],
            ], 429),
        ]);

        $logged = [];

        Log::listen(function ($message) use (&$logged) {
            if (str_contains($message->message, 'Google refused a Local Services')) {
                $logged[] = $message->context;
            }
        });

        $this->artisan('ezbizi:sync-leads')->assertSuccessful();

        $this->assertNotEmpty($logged, 'the refusal was not logged at all');
        $this->assertSame('DEVELOPER', $logged[0]['rate_scope'] ?? null);
        $this->assertSame('4413s', $logged[0]['retry_delay'] ?? null);
    }

    /**
     * AN ACCOUNT-SCOPED 429 IS NOT EVIDENCE ABOUT THE TOKEN.
     *
     * The brake stops a run after three 429s in a row because three DIFFERENT
     * accounts refused back to back is a fact about the developer token. That
     * reasoning only holds if the limit belongs to the token. If each account
     * is hitting its own, stopping starves every account behind them - every
     * run, silently, with the log saying "stopped on quota" each time while
     * nothing was wrong with the quota.
     *
     * Google is the only one who knows which, and it says so in
     * quotaErrorDetails.rateScope. Five accounts, all ACCOUNT-scoped: all five
     * must still be asked.
     */
    public function test_account_scoped_rate_limits_do_not_stop_the_run(): void
    {
        $t = $this->provisionTenant('A1-Chimney');

        $ids = ['1111111111', '2222222222', '3333333333', '4444444444', '5555555555'];

        foreach ($ids as $id) {
            $this->connection($t, $id);
        }

        config([
            'services.google_ads.client_id'       => 'client.apps.googleusercontent.com',
            'services.google_ads.client_secret'   => 'client-secret',
            'services.google_ads.developer_token' => 'dev-token',
            'services.google_ads.version'         => 'v24',
        ]);

        Http::fake([
            'oauth2.googleapis.com/token' => Http::response(['access_token' => 'at-fresh', 'expires_in' => 3600], 200),
            'googleads.googleapis.com/*' => Http::response([
                'error' => [
                    'code' => 429,
                    'status' => 'RESOURCE_EXHAUSTED',
                    'details' => [['errors' => [['details' => ['quotaErrorDetails' => [
                        'rateScope' => 'ACCOUNT',
                        'retryDelay' => '30s',
                    ]]]]]],
                ],
            ], 429),
        ]);

        $this->artisan('ezbizi:sync-leads')->assertSuccessful();

        $asked = [];

        Http::assertSent(function ($request) use (&$asked) {
            if (preg_match('#/customers/(\d+)/#', $request->url(), $m)) {
                $asked[$m[1]] = true;
            }

            return true;
        });

        foreach ($ids as $id) {
            $this->assertArrayHasKey(
                $id,
                $asked,
                "account $id was never asked: its neighbours' OWN rate limits are not "
                .'evidence that the developer token is spent',
            );
        }
    }

    public function test_one_failing_account_does_not_stop_the_others(): void
    {
        $t = $this->provisionTenant('A1-Chimney');
        $this->connection($t, '1111111111');
        $this->connection($t, '2222222222');

        config([
            'services.google_ads.client_id'       => 'client.apps.googleusercontent.com',
            'services.google_ads.client_secret'   => 'client-secret',
            'services.google_ads.developer_token' => 'dev-token',
            'services.google_ads.version'         => 'v24',
        ]);

        Http::fake([
            'oauth2.googleapis.com/token' => Http::response(['access_token' => 'at-fresh', 'expires_in' => 3600], 200),
            'googleads.googleapis.com/v24/customers/1111111111/*' => Http::response(
                ['error' => ['code' => 403, 'status' => 'PERMISSION_DENIED']],
                403,
            ),
            'googleads.googleapis.com/v24/customers/2222222222/*' => Http::response([
                'results' => [[
                    'localServicesLead' => [
                        'id'               => 'L-GOOD',
                        'leadType'         => 'PHONE_CALL',
                        'creationDateTime' => '2026-09-08 09:00:00',
                        'contactDetails'   => ['phoneNumber' => '+13055550101'],
                    ],
                ]],
            ], 200),
        ]);

        $this->artisan('ezbizi:sync-leads')->assertSuccessful();

        $this->assertSame(
            1,
            $this->inTenant($t['org'], fn () => Lead::query()->count()),
            'A suspended account stopped the healthy one beside it.',
        );
    }

    /**
     * Quota tiering. Forty-five accounts every five minutes is past Basic
     * Access's 15,000 a day and a day over the limit drops the evening's
     * leads. A quiet account (no lead in thirty days) is read once per half
     * hour; a producing one every tick, with conversations every other tick.
     */
    public function test_quiet_accounts_rest_between_half_hours_and_conversations_read_every_ten_minutes(): void
    {
        $t = $this->provisionTenant('A1-Chimney');
        $producing = $this->connection($t, '4941850746');
        $quiet     = $this->connection($t, '3747134697');

        // Both connected yesterday; a connection made in the last half hour is
        // due at once whatever the clock, and the clock below moves to a
        // fixed morning, so the stamp must sit well before it.
        $this->inTenant($t['org'], function () use ($producing, $quiet) {
            $producing->forceFill(['created_at' => '2026-09-08 09:00:00'])->save();
            $quiet->forceFill(['created_at' => '2026-09-08 09:00:00'])->save();
        });

        $this->inTenant($t['org'], fn () => Lead::create([
            'organization_id' => $t['org']->id, 'company_id' => $t['company']->id,
            'connection_id' => $producing->getKey(), 'source' => Lead::SOURCE_LOCAL_SERVICES,
            'remote_id' => 'L-1', 'status' => Lead::STATUS_NEW, 'received_at' => now()->subDay(),
        ]));

        $this->fakeGoogle();

        $isLeadRead = fn ($r, string $id) => str_contains($r->url(), "/customers/{$id}/") && str_contains((string) ($r->data()['query'] ?? ''), 'FROM local_services_lead ');
        $isConvRead = fn ($r, string $id) => str_contains($r->url(), "/customers/{$id}/") && str_contains((string) ($r->data()['query'] ?? ''), 'FROM local_services_lead_conversation');

        // 12:07 - neither a quarter hour nor a ten-minute mark.
        \Illuminate\Support\Carbon::setTestNow('2026-09-09 12:07:00');
        $this->artisan('ezbizi:sync-leads')->assertSuccessful();
        Http::assertSent(fn ($r) => $isLeadRead($r, '4941850746'));
        Http::assertNotSent(fn ($r) => $isConvRead($r, '4941850746'));
        Http::assertNotSent(fn ($r) => str_contains($r->url(), '/customers/3747134697/'));

        // 12:10 - a ten-minute mark: the producing account's conversations too.
        \Illuminate\Support\Carbon::setTestNow('2026-09-09 12:10:00');
        $this->artisan('ezbizi:sync-leads')->assertSuccessful();
        Http::assertSent(fn ($r) => $isConvRead($r, '4941850746'));
        Http::assertNotSent(fn ($r) => str_contains($r->url(), '/customers/3747134697/'));

        /*
         * 12:15 - a quarter hour but NOT a half, which is the case that
         * discriminates, and it must now REST.
         *
         * Every other clock in this test passes under either rule: 12:07 rests
         * both ways, 12:30 is read both ways. Only a quarter-hour mark that is
         * not a half-hour one can tell them apart, so without this the cadence
         * could be moved either direction with nothing going red. It asserted
         * the opposite between 2026-09-11 and 2026-09-16, when the quarter
         * hour was deliberate; see SyncLeads for why it was raised and why it
         * came back.
         *
         * Ordering matters: nothing has read the quiet account yet, so this
         * assertNotSent is about THIS tick rather than about the fake's
         * whole history.
         */
        \Illuminate\Support\Carbon::setTestNow('2026-09-09 12:15:00');
        $this->artisan('ezbizi:sync-leads')->assertSuccessful();
        Http::assertNotSent(fn ($r) => str_contains($r->url(), '/customers/3747134697/'));

        // 12:30 - a half hour: the quiet account is read, once.
        \Illuminate\Support\Carbon::setTestNow('2026-09-09 12:30:00');
        $this->artisan('ezbizi:sync-leads')->assertSuccessful();
        Http::assertSent(fn ($r) => $isLeadRead($r, '3747134697'));
        Http::assertNotSent(fn ($r) => $isConvRead($r, '3747134697'));

        // --days reads everything, every time.
        \Illuminate\Support\Carbon::setTestNow('2026-09-09 12:37:00');
        $this->artisan('ezbizi:sync-leads', ['--days' => 3])->assertSuccessful();
        Http::assertSent(fn ($r) => $isLeadRead($r, '3747134697'));
    }

    /**
     * Every account's leads before any account's messages.
     *
     * Both halves spend the same quota and they are not worth the same. In one
     * pass per account, the day the 15,000 ceiling is reached the accounts
     * late in the list lose BOTH — their enquiries stop arriving because an
     * earlier account's message history spent the last of it. The fleet
     * reached sixty accounts on 2026-09-10 and Google's own counter put the
     * hour at 730 requests, so this stopped being hypothetical.
     */
    public function test_leads_are_read_for_every_account_before_any_conversations_are(): void
    {
        $t = $this->provisionTenant('A1-Chimney');
        $first  = $this->connection($t, '4941850746');
        $second = $this->connection($t, '3747134697');

        foreach ([$first, $second] as $i => $connection) {
            $this->inTenant($t['org'], fn () => Lead::create([
                'organization_id' => $t['org']->id, 'company_id' => $t['company']->id,
                'connection_id' => $connection->getKey(), 'source' => Lead::SOURCE_LOCAL_SERVICES,
                'remote_id' => 'L-order-'.$i, 'status' => Lead::STATUS_NEW, 'received_at' => now()->subDay(),
            ]));
        }

        $this->fakeGoogle();

        // A ten-minute mark, so both accounts are due for conversations too.
        \Illuminate\Support\Carbon::setTestNow('2026-09-10 12:10:00');
        $this->artisan('ezbizi:sync-leads')->assertSuccessful();

        $kinds = [];

        foreach (Http::recorded() as [$request]) {
            $query = (string) ($request->data()['query'] ?? '');

            if (str_contains($query, 'FROM local_services_lead ')) {
                $kinds[] = 'lead';
            } elseif (str_contains($query, 'FROM local_services_lead_conversation')) {
                $kinds[] = 'conversation';
            }
        }

        $this->assertSame(
            ['lead', 'lead', 'conversation', 'conversation'],
            $kinds,
            'Discovery for the whole fleet must come before detail for any of it.',
        );
    }

    /**
     * At the ceiling, the ORDER of the fleet decides who is served at all.
     *
     * From 2026-09-11 the quota runs out on purpose, so some of each tick's
     * requests are refused. Unordered, the list came back the same way every
     * time and the same accounts spent the surviving budget — the ones at the
     * end were not read slower, they were not read. Longest unread first makes
     * the refusals rotate, which turns "never" into "late".
     */
    public function test_the_longest_unread_account_is_asked_first(): void
    {
        $t = $this->provisionTenant('A1-Chimney');

        // Created in this order; reached in the opposite one. Insertion order
        // and fairness order therefore disagree, which is what makes this
        // able to fail.
        $first  = $this->connection($t, '4941850746');
        $second = $this->connection($t, '3747134697');
        $third  = $this->connection($t, '1726694743');

        $this->inTenant($t['org'], function () use ($first, $second, $third) {
            $first->forceFill(['last_reached_at' => now()->subMinutes(1)])->save();
            $second->forceFill(['last_reached_at' => now()->subHours(3)])->save();
            $third->forceFill(['last_reached_at' => null])->save();

            foreach ([$first, $second, $third] as $i => $connection) {
                Lead::create([
                    'organization_id' => $this->tenantOrgId($connection),
                    'connection_id' => $connection->getKey(),
                    'source' => Lead::SOURCE_LOCAL_SERVICES,
                    'remote_id' => 'L-fair-'.$i, 'status' => Lead::STATUS_NEW,
                    'received_at' => now()->subDay(),
                ]);
            }
        });

        $this->fakeGoogle();

        \Illuminate\Support\Carbon::setTestNow('2026-09-11 12:07:00');
        $this->artisan('ezbizi:sync-leads')->assertSuccessful();

        $order = [];

        foreach (Http::recorded() as [$request]) {
            if (! str_contains((string) ($request->data()['query'] ?? ''), 'FROM local_services_lead ')) {
                continue;
            }

            foreach (['1726694743', '3747134697', '4941850746'] as $id) {
                if (str_contains($request->url(), "/customers/{$id}/")) {
                    $order[] = $id;
                }
            }
        }

        // Never reached, then three hours ago, then a minute ago.
        $this->assertSame(['1726694743', '3747134697', '4941850746'], $order);
    }

    private function tenantOrgId(\App\Models\SocialConnection $connection): string
    {
        return (string) $connection->organization_id;
    }
}
