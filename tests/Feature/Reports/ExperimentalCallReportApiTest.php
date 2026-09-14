<?php

namespace Tests\Feature\Reports;

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

        $this->connected();

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

        $this->connected();

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

        $this->connected();

        $body = $this->signedInAs($tenant['admin'])
            ->getJson('/api/v1/reports/experimental/calls?day=2026-09-14')
            ->assertOk()
            ->json();

        $this->assertSame(['Nationwide', 'NJ'], $body['workiz_accounts']);
    }

    /** One refused account must be named, not folded into a smaller number. */
    public function test_a_refused_workiz_account_is_named(): void
    {
        $tenant = $this->provisionTenant('Alpha');

        $this->connected(njRefuses: true);

        $body = $this->signedInAs($tenant['admin'])
            ->getJson('/api/v1/reports/experimental/calls?day=2026-09-14')
            ->assertOk()
            ->json();

        $this->assertArrayHasKey('NJ', $body['workiz_failed']);
        $this->assertArrayNotHasKey('Nationwide', $body['workiz_failed']);

        // And the calls still came through.
        $this->assertSame(1, $body['totals']['answered']);
    }

    // ── fixtures ─────────────────────────────────────────────────

    private function notConnected(): void
    {
        config([
            'experimental_reports.ringcentral.client_id' => null,
            'experimental_reports.ringcentral.client_secret' => null,
            'experimental_reports.ringcentral.jwt' => null,
            'experimental_reports.workiz.accounts' => [],
        ]);
    }

    private function connected(bool $njRefuses = false): void
    {
        config([
            'experimental_reports.ringcentral.server' => 'https://platform.ringcentral.test',
            'experimental_reports.ringcentral.client_id' => 'id',
            'experimental_reports.ringcentral.client_secret' => 'secret',
            'experimental_reports.ringcentral.jwt' => 'jwt',
            'experimental_reports.workiz.accounts' => ['Nationwide' => 'nat', 'NJ' => 'nj'],
        ]);

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

            'api.workiz.com/api/v1/nj/*' => $njRefuses
                ? Http::response([], 403)
                : Http::response(['flag' => true, 'data' => []]),

            'api.workiz.com/*' => Http::response(['flag' => true, 'data' => [
                ['Phone' => '2125551212', 'CreatedBy' => 'Trey Brannan', 'CreatedDate' => '2026-09-14 09:00:00'],
            ]]),
        ]);
    }
}
