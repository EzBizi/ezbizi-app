<?php

namespace Tests\Feature\Leads;

use App\Models\Company;
use App\Models\Lead;
use App\Models\Organization;
use App\Models\SocialConnection;
use App\Models\User;
use App\Support\Social\GoogleAdsOAuth;
use App\Support\Social\SocialProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/**
 * Fetching Local Services enquiries talks to the Ads API the way Google
 * actually serves it.
 *
 * The previous URL — GET customers/{id}/localServices:searchDetailedLeadReports
 * — is not a method on googleads.googleapis.com. Production 2026-09-03: Jose
 * connected A-1 Chimney's Ads account, Fetch returned 502, and the log was
 * empty because the catch threw a status number and swallowed Google's body.
 */
class LocalServicesLeadsTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    /** @param array{org: Organization, admin: User, company: Company} $t */
    private function viewingCompany(array $t): void
    {
        $this->inTenant($t['org'], function () use ($t) {
            $t['admin']->forceFill([
                'preferences' => ['navigation.scope' => 'company:'.$t['company']->id],
            ])->save();
        });
    }

    /** @param array{org: Organization, company: Company} $t */
    private function connected(array $t, string $customerId = '4051729356'): SocialConnection
    {
        return $this->inTenant($t['org'], function () use ($t, $customerId) {
            $connection = new SocialConnection;
            $connection->forceFill([
                'organization_id' => $t['org']->id,
                'scope_type' => SocialConnection::SCOPE_COMPANY,
                'scope_id' => $t['company']->id,
                'provider' => SocialProvider::GoogleAds->value,
                'remote_id' => $customerId,
                'display_name' => GoogleAdsOAuth::readableId($customerId),
            ]);
            $connection->connect([
                'refresh_token' => 'rt-test',
                'access_token' => 'at-stale',
            ]);
            $connection->save();

            return $connection;
        });
    }

    private function fakeGoogle(array $searchBody, int $searchStatus = 200): void
    {
        config([
            'services.google_ads.client_id' => 'client.apps.googleusercontent.com',
            'services.google_ads.client_secret' => 'client-secret',
            'services.google_ads.developer_token' => 'dev-token',
            'services.google_ads.version' => 'v24',
        ]);

        Http::fake([
            'oauth2.googleapis.com/token' => Http::response(['access_token' => 'at-fresh', 'expires_in' => 3600], 200),
            'googleads.googleapis.com/*' => Http::response($searchBody, $searchStatus),
        ]);
    }

    public function test_fetch_posts_gaql_to_googleads_search_not_the_invented_lead_reports_url(): void
    {
        $t = $this->provisionTenant('A1-Chimney');
        $this->viewingCompany($t);
        $this->connected($t);

        $this->fakeGoogle(['results' => []]);

        $this->signedInAs($t['admin']->fresh())
            ->postJson('/api/v1/leads/sync')
            ->assertOk();

        Http::assertSent(function ($request) {
            if (! str_contains($request->url(), 'googleads.googleapis.com')) {
                return false;
            }

            $this->assertSame('POST', $request->method());
            $this->assertStringContainsString('/customers/4051729356/googleAds:search', $request->url());
            $this->assertStringNotContainsString('searchDetailedLeadReports', $request->url());
            $this->assertStringContainsString('FROM local_services_lead', (string) $request['query']);
            $this->assertSame('dev-token', $request->header('developer-token')[0] ?? null);
            $this->assertSame('4051729356', $request->header('login-customer-id')[0] ?? null);

            return true;
        });
    }

    public function test_a_lead_google_charged_for_is_not_recorded_as_credited(): void
    {
        $t = $this->provisionTenant('A1-Chimney');
        $this->viewingCompany($t);
        $this->connected($t);

        /*
         * `lead_charged` and `credit_details.credit_state` are opposite facts.
         * Charged means Google billed for the lead. CREDITED means the charge
         * was given back. Mapping the first onto the second marks every lead
         * the customer paid for as refunded, in the module whose whole purpose
         * is matching spend to the work it produced - and charge_status is
         * handed to the client verbatim, so the wrong word reaches a screen.
         */
        $this->fakeGoogle(['results' => [[
            'localServicesLead' => [
                'id' => 'L-CHARGED',
                'leadType' => 'PHONE_CALL',
                'leadStatus' => 'ACTIVE',
                'leadCharged' => true,
                'creationDateTime' => '2026-09-01 09:00:00',
                'contactDetails' => ['consumerName' => 'Dana', 'phoneNumber' => '+13055550101'],
            ],
        ]]]);

        $this->signedInAs($t['admin']->fresh())
            ->postJson('/api/v1/leads/sync')
            ->assertOk();

        $lead = $this->inTenant($t['org'], fn () => Lead::where('remote_id', 'L-CHARGED')->first());

        $this->assertNotNull($lead);
        $this->assertNotSame(
            'CREDITED',
            $lead->charge_status,
            'A lead Google charged for must not be recorded as one it credited back.',
        );
    }

    public function test_one_failing_account_does_not_turn_an_honest_empty_result_into_an_error(): void
    {
        $t = $this->provisionTenant('A1-Chimney');
        $this->viewingCompany($t);

        $good = $this->connected($t, '4051729356');
        $bad = $this->connected($t, '7061094046');

        config([
            'services.google_ads.client_id' => 'client.apps.googleusercontent.com',
            'services.google_ads.client_secret' => 'client-secret',
            'services.google_ads.developer_token' => 'dev-token',
            'services.google_ads.version' => 'v24',
        ]);

        /*
         * A-1 Chimney has 24 connected Ads accounts on production. The common
         * shape is not "everything failed" - it is most accounts answering
         * honestly with nothing and one being suspended. Reporting that as 502
         * shows a hard error for a week in which the true answer was "no
         * enquiries yet".
         */
        Http::fake([
            'oauth2.googleapis.com/token' => Http::response(['access_token' => 'at-fresh', 'expires_in' => 3600], 200),
            'googleads.googleapis.com/v24/customers/4051729356/*' => Http::response(['results' => []], 200),
            'googleads.googleapis.com/v24/customers/7061094046/*' => Http::response(
                ['error' => ['code' => 403, 'status' => 'PERMISSION_DENIED', 'message' => 'suspended']],
                403,
            ),
        ]);

        $this->signedInAs($t['admin']->fresh())
            ->postJson('/api/v1/leads/sync')
            ->assertOk()
            ->assertJsonPath('data.fetched', 0)
            ->assertJsonCount(1, 'data.failures');
    }

    public function test_every_account_failing_is_still_reported_as_a_failure(): void
    {
        $t = $this->provisionTenant('A1-Chimney');
        $this->viewingCompany($t);
        $this->connected($t);

        // The guard must not swallow the case it was NOT written for: when
        // nothing could be read at all, the screen has to say so.
        $this->fakeGoogle(
            ['error' => ['code' => 403, 'status' => 'PERMISSION_DENIED', 'message' => 'nope']],
            403,
        );

        $this->signedInAs($t['admin']->fresh())
            ->postJson('/api/v1/leads/sync')
            ->assertStatus(502);
    }

    public function test_the_request_does_not_set_a_page_size_because_search_refuses_one(): void
    {
        $t = $this->provisionTenant('A1-Chimney');
        $this->viewingCompany($t);
        $this->connected($t);

        $this->fakeGoogle(['results' => []]);

        $this->signedInAs($t['admin']->fresh())
            ->postJson('/api/v1/leads/sync')
            ->assertOk();

        /*
         * googleAds:search rejects pageSize outright:
         *
         *   PAGE_SIZE_NOT_SUPPORTED - "Setting the page size is not supported.
         *   Search Responses will have fixed page size of '10000' rows."
         *
         * Measured against A-1 Chimney's live account on 2026-09-03 while
         * checking the endpoint fix before it shipped. Sending it turns a
         * working call into HTTP 400, which on the Leads screen is the same
         * blank "something went wrong" the wrong URL produced - a different
         * fault wearing the identical symptom.
         */
        Http::assertSent(function ($request) {
            if (! str_contains($request->url(), 'googleads.googleapis.com')) {
                return false;
            }

            $this->assertArrayNotHasKey(
                'pageSize',
                $request->data(),
                'googleAds:search refuses pageSize; sending it makes every fetch a 400.',
            );

            return true;
        });
    }

    public function test_a_lead_from_search_is_stored_under_the_connected_company(): void
    {
        $t = $this->provisionTenant('A1-Chimney');
        $this->viewingCompany($t);
        $this->connected($t);

        $this->fakeGoogle([
            'results' => [[
                'localServicesLead' => [
                    'id' => 'lead-77',
                    'leadType' => 'MESSAGE',
                    'categoryId' => 'chimney_sweep',
                    'leadStatus' => 'NEW',
                    'leadCharged' => true,
                    'creationDateTime' => '2026-09-01 14:22:00',
                    'contactDetails' => [
                        'consumerName' => 'Dana Whitfield',
                        'phoneNumber' => '+1 (555) 123-4567',
                        'email' => 'dana@example.test',
                    ],
                    'creditDetails' => ['creditState' => 'CREDITED'],
                ],
            ]],
        ]);

        $this->signedInAs($t['admin']->fresh())
            ->postJson('/api/v1/leads/sync')
            ->assertOk()
            ->assertJsonPath('data.created', 1)
            ->assertJsonPath('data.fetched', 1);

        $lead = $this->inTenant($t['org'], fn () => Lead::query()->first());

        $this->assertNotNull($lead);
        $this->assertSame('lead-77', $lead->remote_id);
        $this->assertSame('Dana Whitfield', $lead->contact_name);
        $this->assertSame($t['company']->id, $lead->company_id);
        $this->assertSame(Lead::STATUS_NEW, $lead->status);
    }

    /** 2026-10-13: a booking Google sends without a customer keeps the one EzBizi filled in. */
    public function test_a_resync_without_a_name_keeps_the_customer_ezbizi_filled_in(): void
    {
        $t = $this->provisionTenant('A1-Chimney');
        $this->viewingCompany($t);
        $this->connected($t);
        $this->fakeGoogle(['results' => [['localServicesLead' => [
            'id' => 'lead-88', 'leadType' => 'BOOKING', 'leadStatus' => 'BOOKED',
            'creationDateTime' => '2026-09-01 14:22:00', 'contactDetails' => [],
        ]]]]);

        $this->signedInAs($t['admin']->fresh())->postJson('/api/v1/leads/sync')->assertOk();
        $this->inTenant($t['org'], fn () => Lead::query()->firstOrFail()->forceFill([
            'contact_name' => 'Mary Ciccolini', 'phone' => '+13306063475', 'phone_digits' => '13306063475',
        ])->save());

        $this->signedInAs($t['admin']->fresh())->postJson('/api/v1/leads/sync')->assertOk();

        $lead = $this->inTenant($t['org'], fn () => Lead::query()->firstOrFail());
        $this->assertSame('Mary Ciccolini', $lead->contact_name);
        $this->assertSame('+13306063475', $lead->phone);
    }

    /**
     * A reconnected account must reclaim the enquiries it lost.
     *
     * Disconnecting deletes the social_connections row, and the foreign key
     * sets `leads.connection_id` to NULL rather than deleting the lead - the
     * right choice, because these are the business's own customer enquiries and
     * some have already become jobs. But the column was only ever written on
     * CREATE, so reconnecting re-read the same leads, updated them, and left
     * them orphaned permanently.
     *
     * Orphaned is not merely untidy. `canReply()` requires a connection, so an
     * orphaned MESSAGE enquiry could not be answered even with the account
     * back; and `since()` takes its watermark from leads carrying the
     * connection id, so every reconnect restarted from FIRST_RUN_DAYS.
     *
     * Jose's "Disconnect all" on 2026-09-14 orphaned 41,006 of 41,015 leads.
     */
    public function test_a_reconnected_account_adopts_the_leads_it_was_disconnected_from(): void
    {
        $t = $this->provisionTenant('A1-Chimney');
        $this->viewingCompany($t);

        $google = [
            'results' => [[
                'localServicesLead' => [
                    'id' => 'lead-77',
                    'leadType' => 'MESSAGE',
                    'categoryId' => 'chimney_sweep',
                    'leadStatus' => 'NEW',
                    'creationDateTime' => '2026-09-01 14:22:00',
                    'contactDetails' => ['consumerName' => 'Dana Whitfield'],
                ],
            ]],
        ];

        // 1. Connected, and the lead arrives owned.
        $first = $this->connected($t);
        $this->fakeGoogle($google);
        $this->signedInAs($t['admin']->fresh())->postJson('/api/v1/leads/sync')->assertOk();

        $lead = $this->inTenant($t['org'], fn () => Lead::query()->firstOrFail());
        $this->assertSame($first->getKey(), $lead->connection_id, 'positive control: a live sync owns its lead');

        // 2. Disconnected. The lead survives, cut loose - the production shape.
        $this->inTenant($t['org'], fn () => $first->delete());

        $lead = $this->inTenant($t['org'], fn () => Lead::query()->firstOrFail());
        $this->assertNull($lead->connection_id, 'the FK must null it rather than delete the enquiry');
        $this->assertSame($t['company']->id, $lead->company_id, 'and must not take the placement with it');

        // 3. Reconnected. Google returns the same lead, so this is an UPDATE.
        $second = $this->connected($t);
        $this->fakeGoogle($google);
        $this->signedInAs($t['admin']->fresh())
            ->postJson('/api/v1/leads/sync')
            ->assertOk()
            ->assertJsonPath('data.created', 0);

        $lead = $this->inTenant($t['org'], fn () => Lead::query()->firstOrFail());

        $this->assertSame(
            $second->getKey(),
            $lead->connection_id,
            'the update branch must adopt an orphan, not just refresh its fields',
        );
        $this->assertSame(1, $this->inTenant($t['org'], fn () => Lead::query()->count()), 'and not by creating a second row');
    }

    /**
     * A reconnect must take its enquiries back WITHOUT asking Google.
     *
     * Adoption-on-re-read was the first fix and it is not enough: it only
     * reaches leads Google still returns, so anything outside the window stays
     * detached for good, and each reclaim costs quota. `remote_customer_id` is
     * the fact that survives a disconnect, so the re-link is local and exact.
     *
     * The window is what makes this test different from the adoption one. Here
     * the lead is OLD - outside anything a sync would fetch - and Google is
     * faked returning nothing at all. If the reclaim needed Google, this fails.
     */
    public function test_a_reconnect_reclaims_old_leads_without_google_returning_them(): void
    {
        $t = $this->provisionTenant('A1-Chimney');
        $this->viewingCompany($t);

        $first = $this->connected($t);

        // ONE fake, sequenced. Http::fake() stacks rather than replaces, so a
        // second call later in this test would be ignored and Google would
        // still be feeding the sync the lead - which is exactly what the
        // reclaim is supposed to work without.
        config([
            'services.google_ads.client_id' => 'client.apps.googleusercontent.com',
            'services.google_ads.client_secret' => 'client-secret',
            'services.google_ads.developer_token' => 'dev-token',
            'services.google_ads.version' => 'v24',
        ]);

        Http::fake([
            'oauth2.googleapis.com/token' => Http::response(['access_token' => 'at-fresh', 'expires_in' => 3600], 200),
            'googleads.googleapis.com/*' => Http::sequence()
                ->push(['results' => [[
                    'localServicesLead' => [
                        'id' => 'lead-ancient',
                        'leadType' => 'MESSAGE',
                        'leadStatus' => 'NEW',
                        'creationDateTime' => '2024-08-11 09:00:00',
                        'contactDetails' => ['consumerName' => 'Dana Whitfield'],
                    ],
                ]]], 200)
                ->pushStatus(200)
                ->whenEmpty(Http::response(['results' => []], 200)),
        ]);

        $this->signedInAs($t['admin']->fresh())->postJson('/api/v1/leads/sync')->assertOk();

        $lead = $this->inTenant($t['org'], fn () => Lead::query()->firstOrFail());
        $this->assertSame(
            $first->remote_id,
            $lead->remote_customer_id,
            'the account id must be written down while we still know it',
        );

        // Disconnect. The enquiry survives; its connection does not.
        $this->inTenant($t['org'], fn () => $first->delete());
        $this->assertNull($this->inTenant($t['org'], fn () => Lead::query()->firstOrFail())->connection_id);

        // Reconnect, and Google returns NOTHING. A reclaim that needed the API
        // would have nothing to work with.
        $second = $this->connected($t);

        $this->signedInAs($t['admin']->fresh())
            ->postJson('/api/v1/leads/sync')
            ->assertOk()
            ->assertJsonPath('data.fetched', 0);

        $lead = $this->inTenant($t['org'], fn () => Lead::query()->firstOrFail());

        $this->assertSame(
            $second->getKey(),
            $lead->connection_id,
            'the reclaim must re-link from the stored customer id, with no help from Google',
        );
    }

    /**
     * And it must not reclaim somebody else's.
     *
     * The positive case above passes just as well for a reclaim that takes
     * every orphan it can see. A-1 Chimney runs 37 Google Ads accounts under
     * one company, so "every orphan in this company" would attach a customer's
     * enquiry to the wrong account - worse than leaving it orphaned, and
     * invisible until a reply goes out through a stranger's account.
     */
    public function test_the_reclaim_leaves_another_accounts_orphan_alone(): void
    {
        $t = $this->provisionTenant('A1-Chimney');
        $this->viewingCompany($t);

        $mine = $this->connected($t, '4051729356');

        $orphan = $this->inTenant($t['org'], fn () => Lead::query()->create([
            'organization_id' => $t['org']->id,
            'company_id' => $t['company']->id,
            'source' => Lead::SOURCE_LOCAL_SERVICES,
            'remote_id' => 'lead-belongs-elsewhere',
            'remote_customer_id' => '9999999999',   // a different account entirely
            'lead_type' => 'MESSAGE',
            'status' => Lead::STATUS_NEW,
            'received_at' => now()->subYear(),
        ]));

        $this->fakeGoogle(['results' => []]);
        $this->signedInAs($t['admin']->fresh())->postJson('/api/v1/leads/sync')->assertOk();

        $this->assertNull(
            $this->inTenant($t['org'], fn () => $orphan->fresh())->connection_id,
            'an orphan from another account must stay orphaned, whatever company it sits in',
        );

        // Positive control in the same test: the reclaim is running at all.
        $own = $this->inTenant($t['org'], fn () => Lead::query()->create([
            'organization_id' => $t['org']->id,
            'company_id' => $t['company']->id,
            'source' => Lead::SOURCE_LOCAL_SERVICES,
            'remote_id' => 'lead-is-mine',
            'remote_customer_id' => $mine->remote_id,
            'lead_type' => 'MESSAGE',
            'status' => Lead::STATUS_NEW,
            'received_at' => now()->subYear(),
        ]));

        $this->signedInAs($t['admin']->fresh())->postJson('/api/v1/leads/sync')->assertOk();

        $this->assertSame(
            $mine->getKey(),
            $this->inTenant($t['org'], fn () => $own->fresh())->connection_id,
            'and one that does match must be taken back, or the test above proves nothing',
        );
    }

    public function test_a_refused_fetch_puts_googles_reason_on_message_so_the_client_can_show_it(): void
    {
        $t = $this->provisionTenant('A1-Chimney');
        $this->viewingCompany($t);
        $this->connected($t);

        $this->fakeGoogle([
            'error' => [
                'code' => 404,
                'status' => 'NOT_FOUND',
                'message' => 'Requested entity was not found.',
            ],
        ], 404);

        $this->signedInAs($t['admin']->fresh())
            ->postJson('/api/v1/leads/sync')
            ->assertStatus(502)
            ->assertJsonPath('message', 'Google refused the lead request (HTTP 404).');
    }
}
