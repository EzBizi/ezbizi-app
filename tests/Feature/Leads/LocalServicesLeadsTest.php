<?php

namespace Tests\Feature\Leads;

use App\Models\Lead;
use App\Models\SocialConnection;
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

    /** @param array{org: \App\Models\Organization, admin: \App\Models\User, company: \App\Models\Company} $t */
    private function viewingCompany(array $t): void
    {
        $this->inTenant($t['org'], function () use ($t) {
            $t['admin']->forceFill([
                'preferences' => ['navigation.scope' => 'company:'.$t['company']->id],
            ])->save();
        });
    }

    /** @param array{org: \App\Models\Organization, company: \App\Models\Company} $t */
    private function connected(array $t, string $customerId = '4051729356'): SocialConnection
    {
        return $this->inTenant($t['org'], function () use ($t, $customerId) {
            $connection = new SocialConnection;
            $connection->forceFill([
                'organization_id' => $t['org']->id,
                'scope_type'      => SocialConnection::SCOPE_COMPANY,
                'scope_id'        => $t['company']->id,
                'provider'        => SocialProvider::GoogleAds->value,
                'remote_id'       => $customerId,
                'display_name'    => GoogleAdsOAuth::readableId($customerId),
            ]);
            $connection->connect([
                'refresh_token' => 'rt-test',
                'access_token'  => 'at-stale',
            ]);
            $connection->save();

            return $connection;
        });
    }

    private function fakeGoogle(array $searchBody, int $searchStatus = 200): void
    {
        config([
            'services.google_ads.client_id'       => 'client.apps.googleusercontent.com',
            'services.google_ads.client_secret'   => 'client-secret',
            'services.google_ads.developer_token' => 'dev-token',
            'services.google_ads.version'         => 'v24',
        ]);

        Http::fake([
            'oauth2.googleapis.com/token' => Http::response(['access_token' => 'at-fresh', 'expires_in' => 3600], 200),
            'googleads.googleapis.com/*'  => Http::response($searchBody, $searchStatus),
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
                'id'               => 'L-CHARGED',
                'leadType'         => 'PHONE_CALL',
                'leadStatus'       => 'ACTIVE',
                'leadCharged'      => true,
                'creationDateTime' => '2026-09-01 09:00:00',
                'contactDetails'   => ['consumerName' => 'Dana', 'phoneNumber' => '+13055550101'],
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
        $bad  = $this->connected($t, '7061094046');

        config([
            'services.google_ads.client_id'       => 'client.apps.googleusercontent.com',
            'services.google_ads.client_secret'   => 'client-secret',
            'services.google_ads.developer_token' => 'dev-token',
            'services.google_ads.version'         => 'v24',
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
                    'id'               => 'lead-77',
                    'leadType'         => 'MESSAGE',
                    'categoryId'       => 'chimney_sweep',
                    'leadStatus'       => 'NEW',
                    'leadCharged'      => true,
                    'creationDateTime' => '2026-09-01 14:22:00',
                    'contactDetails'   => [
                        'consumerName' => 'Dana Whitfield',
                        'phoneNumber'  => '+1 (555) 123-4567',
                        'email'        => 'dana@example.test',
                    ],
                    'creditDetails'    => ['creditState' => 'CREDITED'],
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

    public function test_a_refused_fetch_puts_googles_reason_on_message_so_the_client_can_show_it(): void
    {
        $t = $this->provisionTenant('A1-Chimney');
        $this->viewingCompany($t);
        $this->connected($t);

        $this->fakeGoogle([
            'error' => [
                'code'    => 404,
                'status'  => 'NOT_FOUND',
                'message' => 'Requested entity was not found.',
            ],
        ], 404);

        $this->signedInAs($t['admin']->fresh())
            ->postJson('/api/v1/leads/sync')
            ->assertStatus(502)
            ->assertJsonPath('message', 'Google refused the lead request (HTTP 404).');
    }
}
