<?php

namespace Tests\Feature\Leads;

use App\Models\Lead;
use App\Models\SocialConnection;
use App\Support\Social\SocialProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/**
 * Placing a connected account at a franchise, and fetching without a person.
 *
 * Two facts about a connection are kept apart on purpose. `scope_type`/`scope_id`
 * says who can SEE it; `leads_franchise_id` says where its LEADS land. Merging
 * them would have hidden an account from the company that just assigned it,
 * because inView() makes a franchise-scoped connection invisible to the company
 * above.
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
}
