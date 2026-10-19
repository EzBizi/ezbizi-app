<?php

namespace Tests\Feature\Reports;

use App\Models\Franchise;
use App\Models\SocialConnection;
use App\Support\Social\SocialProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/**
 * Attaching one Workiz account to one branch (TEMPORARY).
 *
 * Jose's ruling, 2026-09-14: "connect them to each of their designated
 * franchises." These tests are mostly about that sentence being enforceable
 * rather than aspirational — an account connected at the wrong level is a book
 * with no branch to attribute it to, and the report silently goes back to
 * being organization-wide.
 */
class WorkizConnectTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    // ── the ruling ───────────────────────────────────────────────

    public function test_connecting_from_a_branch_attaches_the_account_to_it(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $branch = $this->branch($tenant, 'New Jersey');
        $this->workizAccepts();

        $this->signedInAs($this->viewing($tenant, $branch))
            ->postJson('/api/v1/reports/experimental/workiz', ['api_token' => 'api_good'])
            ->assertCreated()
            ->assertJsonPath('data.scope_type', SocialConnection::SCOPE_FRANCHISE);

        $row = $this->inTenant($tenant['org'], fn () => SocialConnection::query()
            ->where('provider', SocialProvider::Workiz->value)->first());

        $this->assertSame(SocialConnection::SCOPE_FRANCHISE, $row->scope_type);
        $this->assertSame($branch->id, $row->scope_id);

        // Named after the branch, because Workiz cannot tell us its own
        // account's name - nothing in the v1 payload identifies it.
        $this->assertSame('New Jersey', $row->display_name);
    }

    /**
     * REFUSED AT THE ORGANIZATION, and this is the ruling rather than taste.
     *
     * Mailchimp takes whatever view it is given, and for a mailing account that
     * is right. A Workiz account holds ONE branch's book - the ten are disjoint,
     * measured - so one connected organization-wide is a book with nothing to
     * attribute it to.
     */
    public function test_it_refuses_outside_a_branch_view(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $this->workizAccepts();

        $this->signedInAs($tenant['admin'])
            ->postJson('/api/v1/reports/experimental/workiz', ['api_token' => 'api_good'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('api_token');

        $this->assertSame(0, $this->inTenant($tenant['org'], fn () => SocialConnection::query()
            ->where('provider', SocialProvider::Workiz->value)->count()));
    }

    // ── the token is proved before a row exists ──────────────────

    /**
     * A typo makes a connection that looks made and returns nothing days later.
     * Verified, it is a sentence in the field somebody can act on.
     */
    public function test_a_token_workiz_refuses_writes_no_row(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $branch = $this->branch($tenant, 'New Jersey');

        Http::fake(['api.workiz.com/*' => Http::response([], 401)]);

        $this->signedInAs($this->viewing($tenant, $branch))
            ->postJson('/api/v1/reports/experimental/workiz', ['api_token' => 'api_typo'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('api_token');

        $this->assertSame(0, $this->inTenant($tenant['org'], fn () => SocialConnection::query()
            ->where('provider', SocialProvider::Workiz->value)->count()));
    }

    /** The token is in the URL path, so no refusal may quote it. */
    public function test_a_refusal_never_names_the_token(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $branch = $this->branch($tenant, 'New Jersey');

        Http::fake(['api.workiz.com/*' => Http::response([], 403)]);

        $body = $this->signedInAs($this->viewing($tenant, $branch))
            ->postJson('/api/v1/reports/experimental/workiz', ['api_token' => 'api_sup3rs3cr3t'])
            ->assertStatus(422)
            ->json();

        $this->assertStringNotContainsString('sup3rs3cr3t', json_encode($body));
        $this->assertStringNotContainsString('api.workiz.com', json_encode($body));
    }

    // ── the gate ─────────────────────────────────────────────────

    /**
     * `reports.build` and nothing else, because that is the only screen that
     * reads a Workiz account. Offering Connect to somebody who could never see
     * what it connects is a control that does not work.
     */
    public function test_reports_view_alone_cannot_connect(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $branch = $this->branch($tenant, 'New Jersey');
        $this->workizAccepts();

        $runner = $this->userWithRole($tenant, 'Runner', ['reports.view'], onlyAt: $branch);

        $this->signedInAs($this->asViewer($tenant, $runner, $branch))
            ->postJson('/api/v1/reports/experimental/workiz', ['api_token' => 'api_good'])
            ->assertForbidden();
    }

    // ── what the response gives back ─────────────────────────────

    /** The credential never comes back out through any API path. */
    public function test_the_response_does_not_echo_the_token(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $branch = $this->branch($tenant, 'New Jersey');
        $this->workizAccepts();

        $body = $this->signedInAs($this->viewing($tenant, $branch))
            ->postJson('/api/v1/reports/experimental/workiz', ['api_token' => 'api_good'])
            ->assertCreated()
            ->json();

        $this->assertStringNotContainsString('api_good', json_encode($body));
    }

    /** A new account is queued for its whole history; a reconnect is not queued again. The card names the API. */
    public function test_a_new_account_is_queued_for_its_history_and_the_card_names_the_api(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $branch = $this->branch($tenant, 'New Jersey');
        $this->workizAccepts();
        $viewer = $this->viewing($tenant, $branch);

        $this->signedInAs($viewer)->postJson('/api/v1/reports/experimental/workiz', ['api_token' => 'api_good'])->assertCreated();
        $row = $this->inTenant($tenant['org'], fn () => SocialConnection::query()->where('provider', SocialProvider::Workiz->value)->sole());
        $this->assertSame([$row->id], \Illuminate\Support\Facades\Cache::get(\App\Console\Commands\BackfillWorkizJobs::PENDING));

        \Illuminate\Support\Facades\Cache::forget(\App\Console\Commands\BackfillWorkizJobs::PENDING);
        $this->signedInAs($viewer)->postJson('/api/v1/reports/experimental/workiz', ['api_token' => 'api_good'])->assertCreated();
        $this->assertNull(\Illuminate\Support\Facades\Cache::get(\App\Console\Commands\BackfillWorkizJobs::PENDING), 'a reconnect is not new');

        $this->assertSame('API v1', collect($this->signedInAs($tenant['admin'])->getJson('/api/v1/social/connections?include=data_sources')->assertOk()->json('data'))
            ->firstWhere('provider', 'workiz')['detail']);
    }

    /** Connecting the same branch twice replaces the token rather than duplicating. */
    public function test_reconnecting_a_branch_does_not_make_a_second_row(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $branch = $this->branch($tenant, 'New Jersey');
        $this->workizAccepts();

        $viewer = $this->viewing($tenant, $branch);

        foreach (['api_first', 'api_second'] as $token) {
            $this->signedInAs($viewer)
                ->postJson('/api/v1/reports/experimental/workiz', ['api_token' => $token])
                ->assertCreated();
        }

        $rows = $this->inTenant($tenant['org'], fn () => SocialConnection::query()
            ->where('provider', SocialProvider::Workiz->value)->get());

        $this->assertCount(1, $rows);
        $this->assertSame('api_second', $rows->first()->credentials['api_token']);
    }

    /**
     * The write key rides in with the token: a branch with nothing to copy it
     * from (MA, 2026-10-05) gets one only here. A reconnect that leaves it blank
     * keeps it for the same token -- connect() replaces the credentials whole --
     * and drops it for a new token, which is another account.
     */
    public function test_the_write_key_is_kept_for_the_same_token_and_dropped_for_a_new_one(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $viewer = $this->viewing($tenant, $this->branch($tenant, 'Massachusetts'));
        $this->workizAccepts();
        $secret = fn () => $this->inTenant($tenant['org'], fn () => SocialConnection::query()
            ->where('provider', SocialProvider::Workiz->value)->sole()->credentials['auth_secret'] ?? null);

        $this->signedInAs($viewer)->postJson('/api/v1/social/workiz', ['api_token' => 'api_ma', 'auth_secret' => 'sec_ma'])->assertCreated();
        $this->assertSame('sec_ma', $secret());

        $this->signedInAs($viewer)->postJson('/api/v1/social/workiz', ['api_token' => 'api_ma', 'auth_secret' => ''])->assertCreated();
        $this->assertSame('sec_ma', $secret(), 'the same account keeps its write key');

        $this->signedInAs($viewer)->postJson('/api/v1/social/workiz', ['api_token' => 'api_other'])->assertCreated();
        $this->assertNull($secret(), 'a new token is another account: the old key would be refused');

        $this->assertStringNotContainsString('sec_ma', (string) json_encode(DB::table('audit_events')->pluck('metadata')));
    }

    // ── fixtures ─────────────────────────────────────────────────

    private function branch(array $tenant, string $name): Franchise
    {
        return $this->inTenant($tenant['org'], fn () => Franchise::create([
            'organization_id' => $tenant['org']->id,
            'company_id' => $tenant['company']->id,
            'name' => $name,
            'is_active' => true,
        ]));
    }

    /** The administrator, looking at one branch. */
    private function viewing(array $tenant, Franchise $branch)
    {
        return $this->asViewer($tenant, $tenant['admin'], $branch);
    }

    private function asViewer(array $tenant, $user, Franchise $branch)
    {
        return $this->inTenant($tenant['org'], function () use ($user, $branch) {
            $user->forceFill([
                'preferences' => ['navigation.scope' => 'franchise:'.$branch->id],
            ])->save();

            return $user->fresh();
        });
    }

    private function workizAccepts(): void
    {
        Http::fake(['api.workiz.com/*' => Http::response(['flag' => true, 'data' => []])]);
    }
}
