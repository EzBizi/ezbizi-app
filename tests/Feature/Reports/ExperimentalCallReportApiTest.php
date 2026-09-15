<?php

namespace Tests\Feature\Reports;

use App\Models\Franchise;
use App\Models\SocialConnection;
use App\Support\Social\SocialProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/**
 * The endpoint behind the Experimental tab (TEMPORARY).
 *
 * The payload names every member of staff and how many calls each one took, so
 * the gate is the point of this file — more than the arithmetic, which
 * ExperimentalCallReportTest covers. This module has already disclosed people
 * organization-wide once by reasoning about where a record lives instead of
 * who may see it.
 */
class ExperimentalCallReportApiTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    // ── the gate ─────────────────────────────────────────────────

    /** Running saved reports is a weaker right and must not open this. */
    public function test_reports_view_alone_cannot_read_it(): void
    {
        $tenant = $this->provisionTenant('Alpha');

        $runner = $this->userWithRole($tenant, 'Runner', ['reports.view']);

        $this->signedInAs($runner)
            ->getJson('/api/v1/reports/experimental/calls')
            ->assertForbidden();
    }

    /** Somebody with no Reports permission at all gets nothing. */
    public function test_a_person_with_no_reports_permission_cannot_read_it(): void
    {
        $tenant = $this->provisionTenant('Alpha');

        $nobody = $this->userWithRole($tenant, 'Technician', ['jobs.view']);

        $this->signedInAs($nobody)
            ->getJson('/api/v1/reports/experimental/calls')
            ->assertForbidden();
    }

    /** `reports.build` is the permission that already means organization-wide. */
    public function test_reports_build_can_read_it(): void
    {
        $tenant = $this->provisionTenant('Alpha');

        $builder = $this->userWithRole($tenant, 'Analyst', ['reports.build', 'reports.view']);

        $this->notConnected();

        $this->signedInAs($builder)
            ->getJson('/api/v1/reports/experimental/calls')
            ->assertOk();
    }

    // ── an absent vendor is a state, not an empty report ─────────

    /**
     * "Nobody called today" and "nothing is plugged in" look identical on a
     * screen and mean opposite things.
     */
    public function test_a_missing_ringcentral_reads_as_not_connected(): void
    {
        $tenant = $this->provisionTenant('Alpha');

        $this->notConnected();

        $body = $this->signedInAs($tenant['admin'])
            ->getJson('/api/v1/reports/experimental/calls')
            ->assertOk()
            ->json();

        $this->assertFalse($body['connected']);
        $this->assertSame('RingCentral', $body['missing']);
        $this->assertArrayNotHasKey('people', $body);
    }

    // ── the payload ──────────────────────────────────────────────

    public function test_it_returns_the_day_per_person(): void
    {
        $tenant = $this->provisionTenant('Alpha');

        $this->connected($tenant);

        $body = $this->signedInAs($tenant['admin'])
            ->getJson('/api/v1/reports/experimental/calls?day=2026-09-14&timezone=America/New_York')
            ->assertOk()
            ->json();

        $this->assertTrue($body['connected']);
        $this->assertSame('2026-09-14', $body['day']);
        $this->assertSame(1, $body['totals']['answered']);
        $this->assertSame('Trey Brannan', $body['people'][0]['person']);
        $this->assertSame(1, $body['people'][0]['answered']);
    }

    /**
     * THE BOUNDARY IS PART OF THE ANSWER. A day an hour out moves calls
     * between days and nothing about the result would look wrong, so the zone
     * that produced the counts is echoed back rather than assumed.
     */
    public function test_the_timezone_the_day_was_cut_in_is_in_the_payload(): void
    {
        $tenant = $this->provisionTenant('Alpha');

        $this->connected($tenant);

        $body = $this->signedInAs($tenant['admin'])
            ->getJson('/api/v1/reports/experimental/calls?day=2026-09-14&timezone=America/Chicago')
            ->assertOk()
            ->json();

        $this->assertSame('America/Chicago', $body['timezone']);
    }

    public function test_an_unparseable_timezone_is_refused_rather_than_guessed(): void
    {
        $tenant = $this->provisionTenant('Alpha');

        $this->signedInAs($tenant['admin'])
            ->getJson('/api/v1/reports/experimental/calls?timezone=Pacific/Nowhere')
            ->assertStatus(422);
    }

    /**
     * The accounts are disjoint and the same people book into all of them, so
     * a missing account gets a person's job count WRONG rather than merely
     * omitting a region. The screen has to be able to name what it covers.
     */
    public function test_it_names_the_workiz_accounts_it_covers(): void
    {
        $tenant = $this->provisionTenant('Alpha');

        $this->connected($tenant);

        $body = $this->signedInAs($tenant['admin'])
            ->getJson('/api/v1/reports/experimental/calls?day=2026-09-14')
            ->assertOk()
            ->json();

        $this->assertSame(
            ['Nationwide', 'New Jersey'],
            array_column($body['workiz_accounts'], 'name'),
        );

        // And each one carries the id that disconnects it. Nothing else
        // publishes it - a data-source provider is excluded from the shared
        // connections list on purpose.
        foreach ($body['workiz_accounts'] as $account) {
            $this->assertNotEmpty($account['id']);
        }
    }

    /** One refused account must be named, not folded into a smaller number. */
    public function test_a_refused_workiz_account_is_named(): void
    {
        $tenant = $this->provisionTenant('Alpha');

        $this->connected($tenant, njRefuses: true);

        $body = $this->signedInAs($tenant['admin'])
            ->getJson('/api/v1/reports/experimental/calls?day=2026-09-14')
            ->assertOk()
            ->json();

        $this->assertArrayHasKey('New Jersey', $body['workiz_failed']);
        $this->assertArrayNotHasKey('Nationwide', $body['workiz_failed']);

        // And the calls still came through.
        $this->assertSame(1, $body['totals']['answered']);
    }

    /**
     * EVERY account refusing is a different arm from one refusing.
     *
     * It builds the failure map itself rather than receiving it, and it did so
     * with `array_fill_keys($accounts, ...)` after `accounts()` started
     * carrying {id, name} maps for the disconnect chips — array keys, which is
     * a TypeError rather than a wrong answer. Nothing caught it because the
     * other refusal test refuses exactly one account.
     */
    public function test_every_workiz_account_refusing_still_returns_the_calls(): void
    {
        $tenant = $this->provisionTenant('Alpha');

        $this->connected($tenant, allRefuse: true);

        $body = $this->signedInAs($tenant['admin'])
            ->getJson('/api/v1/reports/experimental/calls?day=2026-09-14')
            ->assertOk()
            ->json();

        $this->assertArrayHasKey('Nationwide', $body['workiz_failed']);
        $this->assertArrayHasKey('New Jersey', $body['workiz_failed']);

        // The calls survive. A blank jobs column must not read as "nobody
        // booked anything".
        $this->assertSame(1, $body['totals']['answered']);
    }

    /**
     * A BOOK WE WILL NEVER READ, named rather than absent.
     *
     * Jose, 2026-09-15: "we dont pay the api access there". Massachusetts has
     * no Workiz API subscription, so the undercount is permanent rather than a
     * configuration somebody will get round to — and since the same people book
     * into every account, it makes individual people's numbers low rather than
     * omitting a region. "Not connected" would read as a task.
     */
    public function test_an_account_with_no_api_subscription_is_named_as_unreachable(): void
    {
        $tenant = $this->provisionTenant('Alpha');

        $this->connected($tenant);

        $body = $this->signedInAs($tenant['admin'])
            ->getJson('/api/v1/reports/experimental/calls?day=2026-09-14')
            ->assertOk()
            ->json();

        $this->assertSame('Massachusetts', $body['workiz_unreachable'][0]['name']);
        $this->assertStringContainsString(
            'not paid for',
            $body['workiz_unreachable'][0]['reason'],
        );
    }

    /**
     * ROUNDS, not one-account-at-a-time, and a short page is what drops out.
     *
     * `all()` fans out concurrently now: every still-hungry branch asks for its
     * next page in one round, and a branch returning fewer than PAGE rows stops
     * being asked. Nine live branches over ~840 rows settle in two rounds
     * instead of seventeen sequential requests (measured 9.2s in series).
     *
     * The loop is the part worth pinning. A branch with a FULL first page must
     * be asked again, and one with a short page must not - get that wrong and
     * the report either loses rows silently or loops forever against an API
     * that ignores the offset.
     */
    public function test_a_branch_with_a_full_page_is_asked_for_the_next_one(): void
    {
        $tenant = $this->provisionTenant('Alpha');

        config([
            'experimental_reports.ringcentral.server' => 'https://platform.ringcentral.test',
            'experimental_reports.ringcentral.client_id' => 'id',
            'experimental_reports.ringcentral.client_secret' => 'secret',
            'experimental_reports.ringcentral.jwt' => 'jwt',
        ]);

        $this->workizBranch($tenant, 'Nationwide', 'nat');
        $this->workizBranch($tenant, 'New Jersey', 'nj');

        // Nationwide: a FULL page, then a short one. New Jersey: short at once.
        $full = array_fill(0, 100, [
            'Phone' => '2125551212',
            'CreatedBy' => 'Trey Brannan',
            'CreatedDate' => '2026-09-14 09:00:00',
        ]);

        Http::fake([
            '*/oauth/token' => Http::response(['access_token' => 'tok', 'expires_in' => 3600]),
            '*/call-log*' => Http::response(['paging' => ['totalPages' => 1], 'records' => []]),

            'api.workiz.com/api/v1/nat/*' => Http::sequence()
                ->push(['flag' => true, 'data' => $full])
                ->push(['flag' => true, 'data' => [[
                    'Phone' => '2125559999',
                    'CreatedBy' => 'Trey Brannan',
                    'CreatedDate' => '2026-09-14 10:00:00',
                ]]]),

            'api.workiz.com/api/v1/nj/*' => Http::response(['flag' => true, 'data' => []]),
        ]);

        $body = $this->signedInAs($tenant['admin'])
            ->getJson('/api/v1/reports/experimental/calls?day=2026-09-14')
            ->assertOk()
            ->json();

        // 100 + 1. The second page is only fetched if the first being full put
        // Nationwide back into the next round.
        $this->assertSame(101, $body['totals']['jobs']);

        // And New Jersey, short on its first page, was not asked twice - which
        // a sequence with nothing left to push would have turned into a failure.
        $this->assertSame([], (array) $body['workiz_failed']);
    }

    // ── fixtures ─────────────────────────────────────────────────

    private function notConnected(): void
    {
        config([
            'experimental_reports.ringcentral.client_id' => null,
            'experimental_reports.ringcentral.client_secret' => null,
            'experimental_reports.ringcentral.jwt' => null,
        ]);
    }

    private function connected(array $tenant, bool $njRefuses = false, bool $allRefuse = false): void
    {
        config([
            'experimental_reports.ringcentral.server' => 'https://platform.ringcentral.test',
            'experimental_reports.ringcentral.client_id' => 'id',
            'experimental_reports.ringcentral.client_secret' => 'secret',
            'experimental_reports.ringcentral.jwt' => 'jwt',
        ]);

        $this->workizBranch($tenant, 'Nationwide', 'nat');
        $this->workizBranch($tenant, 'New Jersey', 'nj');

        Http::fake([
            '*/oauth/token' => Http::response(['access_token' => 'tok', 'expires_in' => 3600]),

            '*/call-log*' => Http::response([
                'paging' => ['totalPages' => 1],
                'records' => [[
                    'id' => '1',
                    'direction' => 'Inbound',
                    'result' => 'Accepted',
                    'startTime' => '2026-09-14T13:21:11.795Z',
                    'from' => ['phoneNumber' => '+17579553189', 'name' => 'PERRON,NICOLE'],
                    'to' => ['phoneNumber' => '+18888602433', 'name' => 'Main - IVR'],
                    'legs' => [
                        ['legType' => 'Accept', 'result' => 'Accepted', 'to' => ['name' => 'Main - IVR']],
                        ['legType' => 'Accept', 'result' => 'Accepted', 'to' => ['name' => 'A1 - New Client']],
                        ['legType' => 'PstnToSip', 'result' => 'Call connected', 'from' => ['name' => 'Trey Brannan'], 'to' => ['extensionNumber' => '748']],
                    ],
                ]],
            ]),

            'api.workiz.com/api/v1/nj/*' => ($njRefuses || $allRefuse)
                ? Http::response([], 403)
                : Http::response(['flag' => true, 'data' => []]),

            'api.workiz.com/*' => $allRefuse ? Http::response([], 403) : Http::response(['flag' => true, 'data' => [
                ['Phone' => '2125551212', 'CreatedBy' => 'Trey Brannan', 'CreatedDate' => '2026-09-14 09:00:00'],
            ]]),
        ]);
    }

    /**
     * A branch with a Workiz account attached, which is where the tokens live
     * now — `scope_type = franchise`, per Jose's ruling.
     */
    private function workizBranch(array $tenant, string $name, string $token): void
    {
        $this->inTenant($tenant['org'], function () use ($tenant, $name, $token) {
            $franchise = Franchise::create([
                'organization_id' => $tenant['org']->id,
                'company_id' => $tenant['company']->id,
                'name' => $name,
                'is_active' => true,
            ]);

            $connection = new SocialConnection;
            $connection->forceFill([
                'organization_id' => $tenant['org']->id,
                'scope_type' => SocialConnection::SCOPE_FRANCHISE,
                'scope_id' => $franchise->id,
                'provider' => SocialProvider::Workiz->value,
                'remote_id' => $franchise->id,
                'display_name' => $name,
            ]);
            $connection->connect(['refresh_token' => $token, 'api_token' => $token]);
            $connection->save();
        });
    }
}
