<?php

namespace Tests\Feature\Reports;

use App\Models\SocialConnection;
use App\Support\Social\SocialProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/**
 * Connecting a business's own Airtable base.
 *
 * Generic on Jose's instruction, 2026-09-16 — "so it-s not just an a-1chimney
 * thing". Nothing in these tests names a real base id or token, and nothing in
 * the controller does either.
 */
class AirtableConnectTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    private const BASE = 'appWhbwht0xgB5Yut';

    // ── the gate ─────────────────────────────────────────────────

    public function test_somebody_without_reports_build_cannot_connect_a_base(): void
    {
        $tenant = $this->provisionTenant('Alpha');

        $runner = $this->userWithRole($tenant, 'Runner', ['reports.view', 'marketing.edit']);

        $this->signedInAs($runner)
            ->postJson('/api/v1/social/airtable', [
                'api_token' => 'pat_whatever',
                'base_id' => self::BASE,
            ])
            ->assertForbidden();
    }

    // ── nothing is written until Airtable agrees ─────────────────

    /**
     * A typo is a field-level refusal, not a connection that returns nothing
     * on the Monday a report is due. Mailchimp's connect flow verifies for the
     * same reason.
     */
    public function test_a_token_airtable_rejects_writes_no_row(): void
    {
        $tenant = $this->provisionTenant('Alpha');

        Http::fake(['api.airtable.com/*' => Http::response([], 401)]);

        $this->signedInAs($tenant['admin'])
            ->postJson('/api/v1/social/airtable', [
                'api_token' => 'pat_wrong',
                'base_id' => self::BASE,
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('api_token');

        $this->assertSame(0, $this->inTenant(
            $tenant['org'],
            fn () => SocialConnection::query()->where('provider', 'airtable')->count(),
        ));
    }

    /**
     * A TOKEN THAT WORKS AND A BASE IT CANNOT SEE ARE DIFFERENT MISTAKES.
     *
     * Airtable answers 403/404 for the second. Reporting it against
     * `api_token` would send somebody to regenerate a token that was fine.
     */
    public function test_an_unreachable_base_is_reported_against_the_base_field(): void
    {
        $tenant = $this->provisionTenant('Alpha');

        Http::fake(['api.airtable.com/*' => Http::response([], 403)]);

        $this->signedInAs($tenant['admin'])
            ->postJson('/api/v1/social/airtable', [
                'api_token' => 'pat_fine',
                'base_id' => self::BASE,
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('base_id');
    }

    /** A pasted base URL is refused at the field rather than becoming a 404. */
    public function test_something_that_is_not_a_base_id_is_refused_before_any_call(): void
    {
        $tenant = $this->provisionTenant('Alpha');

        Http::fake(['api.airtable.com/*' => Http::response([], 500)]);

        $this->signedInAs($tenant['admin'])
            ->postJson('/api/v1/social/airtable', [
                'api_token' => 'pat_fine',
                'base_id' => 'https://airtable.com/appWhbwht0xgB5Yut/tblX',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('base_id');

        Http::assertNothingSent();
    }

    // ── the connection ───────────────────────────────────────────

    public function test_connecting_stores_the_base_and_never_returns_the_token(): void
    {
        $tenant = $this->provisionTenant('Alpha');

        $this->fakeBase();

        $body = $this->signedInAs($tenant['admin'])
            ->postJson('/api/v1/social/airtable', [
                'api_token' => 'pat_secret_value',
                'base_id' => self::BASE,
            ])
            ->assertCreated()
            ->json('data');

        $this->assertSame(['NJ', 'CT'], $body['tables']);

        // The token is encrypted and masked on every serialisation. A connect
        // response that echoed it back would put it in a log the moment
        // anybody debugged this screen.
        $this->assertStringNotContainsString('pat_secret_value', json_encode($body));

        $row = $this->inTenant($tenant['org'], fn () => SocialConnection::query()
            ->where('provider', SocialProvider::Airtable->value)->firstOrFail());

        $this->assertSame(self::BASE, $row->remote_id);
        $this->assertSame('pat_secret_value', $row->credentials['api_token']);

        // Airtable's own name for it, not its id. The fixture lists another
        // base first, so a reader that took the FIRST name rather than the
        // MATCHING one would call this "Somebody else" and pass otherwise.
        $this->assertSame('Profit And Loss', $row->display_name);
    }

    /**
     * ONE ROW IS ONE BASE, so the same base twice is an update.
     *
     * Duplicating would give a reader two rows that disagree about the same
     * base the day one token is rotated and the other is not.
     */
    public function test_connecting_the_same_base_twice_updates_rather_than_duplicates(): void
    {
        $tenant = $this->provisionTenant('Alpha');

        $this->fakeBase();

        foreach (['pat_first', 'pat_second'] as $token) {
            $this->signedInAs($tenant['admin'])
                ->postJson('/api/v1/social/airtable', [
                    'api_token' => $token,
                    'base_id' => self::BASE,
                ])
                ->assertCreated();
        }

        $rows = $this->inTenant($tenant['org'], fn () => SocialConnection::query()
            ->where('provider', SocialProvider::Airtable->value)->get());

        $this->assertCount(1, $rows);
        $this->assertSame('pat_second', $rows->first()->credentials['api_token']);
    }

    /** A second, different base is a second row — that is the whole point. */
    public function test_a_second_base_is_a_second_connection(): void
    {
        $tenant = $this->provisionTenant('Alpha');

        $this->fakeBase();

        foreach ([self::BASE, 'app6ZZgY9nHX8mMih'] as $base) {
            $this->signedInAs($tenant['admin'])
                ->postJson('/api/v1/social/airtable', [
                    'api_token' => 'pat_fine',
                    'base_id' => $base,
                ])
                ->assertCreated();
        }

        $this->assertSame(2, $this->inTenant(
            $tenant['org'],
            fn () => SocialConnection::query()->where('provider', 'airtable')->count(),
        ));
    }

    // ── Integrations can see it, the composer still cannot ───────

    /**
     * THE OPT-IN, and the control is the default.
     *
     * Integrations and the Social Media composer share `/social/connections`.
     * Data sources are filtered out of it because listing them once put
     * twenty-two Google Ads accounts in the composer as if a post could go to
     * one. So the include is a parameter, and this test pins BOTH sides: with
     * it the base appears, without it nothing changed for the composer.
     */
    public function test_integrations_sees_the_base_only_when_it_asks(): void
    {
        $tenant = $this->provisionTenant('Alpha');

        $this->fakeBase();

        $this->signedInAs($tenant['admin'])
            ->postJson('/api/v1/social/airtable', [
                'api_token' => 'pat_fine',
                'base_id' => self::BASE,
            ])
            ->assertCreated();

        $without = $this->signedInAs($tenant['admin'])
            ->getJson('/api/v1/social/connections')
            ->assertOk()
            ->json('data');

        $this->assertSame([], array_values(array_filter(
            $without,
            fn (array $row) => $row['provider'] === 'airtable',
        )), 'a data source reached the composer payload');

        $with = $this->signedInAs($tenant['admin'])
            ->getJson('/api/v1/social/connections?include=data_sources')
            ->assertOk()
            ->json('data');

        $this->assertNotSame([], array_values(array_filter(
            $with,
            fn (array $row) => $row['provider'] === 'airtable',
        )));
    }

    /**
     * NAMING IT HAS TO BE VISIBLE, or it is a write with no read.
     *
     * `rename` could already set a label; `index` never returned one, so
     * naming a connection changed nothing anybody could see. An Airtable base
     * displays as its id - `appadTMQltMGmGrse` tells nobody anything - which is
     * exactly the case where a name matters most.
     */
    public function test_a_named_base_comes_back_with_its_name(): void
    {
        $tenant = $this->provisionTenant('Alpha');

        $this->fakeBase();

        $id = $this->signedInAs($tenant['admin'])
            ->postJson('/api/v1/social/airtable', [
                'api_token' => 'pat_fine',
                'base_id' => self::BASE,
            ])
            ->assertCreated()
            ->json('data.id');

        $this->signedInAs($tenant['admin'])
            ->patchJson('/api/v1/social/connections/'.$id.'/label', [
                'label' => 'Profit and Loss',
            ])
            ->assertOk();

        $row = collect($this->signedInAs($tenant['admin'])
            ->getJson('/api/v1/social/connections?include=data_sources')
            ->assertOk()
            ->json('data'))
            ->firstWhere('provider', 'airtable');

        $this->assertSame('Profit and Loss', $row['label']);

        // And Airtable's own name survives alongside the tenant's. Three names
        // for one row is not redundancy: `label` is what this business calls
        // it, `display_name` is what Airtable calls it, and `remote_id` is what
        // identifies it when something is wrong.
        $this->assertSame('Profit And Loss', $row['display_name']);
    }

    private function fakeBase(): void
    {
        Http::fake([
            // The BASES endpoint names the base; the TABLES endpoint names its
            // tables. Two different calls, which is the whole point - reading
            // only the second is what made me write "Airtable will not tell us
            // what a base is called" into a docblock.
            'api.airtable.com/v0/meta/bases' => Http::response([
                'bases' => [
                    ['id' => 'appOTHER00000000', 'name' => 'Somebody else'],
                    ['id' => self::BASE, 'name' => 'Profit And Loss'],
                ],
            ]),

            'api.airtable.com/*' => Http::response([
                'tables' => [
                    ['id' => 'tbl1', 'name' => 'NJ'],
                    ['id' => 'tbl2', 'name' => 'CT'],
                ],
            ]),
        ]);
    }
}
