<?php

namespace Tests\Feature\Workiz;

use App\Models\Franchise;
use App\Models\SocialConnection;
use App\Models\WorkizJob;
use App\Support\Social\SocialProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

class SyncWorkizJobsTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    private function connect(array $tenant, Franchise $branch, string $name): SocialConnection
    {
        return $this->inTenant($tenant['org'], function () use ($tenant, $branch, $name) {
            $c = new SocialConnection;
            $c->forceFill([
                'organization_id' => $tenant['org']->id,
                'scope_type' => SocialConnection::SCOPE_FRANCHISE,
                'scope_id' => $branch->id,
                'provider' => SocialProvider::Workiz->value,
                'remote_id' => $branch->id,
                'display_name' => $name,
            ]);
            $c->connect(['api_token' => 'api_'.$name, 'refresh_token' => 'api_'.$name]);
            $c->save();

            return $c;
        });
    }

    private function payload(array $overrides = []): array
    {
        return array_merge(require base_path('tests/Fixtures/workiz/job_payload.php'), $overrides);
    }

    public function test_it_lands_a_job_on_the_connections_franchise(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $branch = $tenant['franchise'];
        $this->connect($tenant, $branch, 'NewJersey');

        Http::fake(['api.workiz.com/*' => Http::response(['flag' => true, 'data' => [$this->payload()]])]);

        $this->artisan('ezbizi:sync-workiz')->assertSuccessful();

        $row = $this->inTenant($tenant['org'], fn () => WorkizJob::query()->first());

        $this->assertSame('DG0KYN', $row->remote_id);
        $this->assertSame($branch->id, $row->franchise_id);
        $this->assertSame($tenant['org']->id, $row->organization_id);
        $this->assertSame('Upstate New York', $row->service_area);
        $this->assertSame('Gas System', $row->payload['system_type']);
    }

    /**
     * IDEMPOTENT. The sweep runs every minute over a rolling window, so the
     * same job is re-read many times a day. A second run must REFRESH the row,
     * not mint a second one — otherwise every count built on this table grows
     * by 1,440 a day per job.
     */
    public function test_running_twice_refreshes_rather_than_duplicates(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $this->connect($tenant, $tenant['franchise'], 'NewJersey');

        /*
         * ONE fake with a SEQUENCE, not two fakes.
         *
         * `Http::fake` STACKS rather than replaces: calling it a second time
         * leaves the first stub registered and it goes on answering. Written as
         * two fakes, this test passed the second run the FIRST run's payload —
         * so the row never changed, and the failure read as "the importer does
         * not apply updates" rather than as a broken test.
         */
        Http::fake(['api.workiz.com/*' => Http::sequence()
            ->push(['flag' => true, 'data' => [$this->payload()]])
            ->push(['flag' => true, 'data' => [$this->payload(['Status' => 'Done', 'SubStatus' => 'Paid'])]]),
        ]);

        $this->artisan('ezbizi:sync-workiz')->assertSuccessful();
        $this->artisan('ezbizi:sync-workiz')->assertSuccessful();

        $rows = $this->inTenant($tenant['org'], fn () => WorkizJob::query()->get());

        $this->assertCount(1, $rows);
        $this->assertSame('Done', $rows->first()->status);
        $this->assertSame('Paid', $rows->first()->sub_status);
    }

    /**
     * A RECONNECT MUST NOT LAND EVERY JOB A SECOND TIME.
     *
     * Disconnecting DELETES the `social_connections` row
     * (`SocialConnectionsController::letGo`), so reconnecting the same branch
     * mints a new uuid. Keyed on the connection, this sweep would treat every
     * job as new and the table would silently double.
     *
     * Not hypothetical: Jobs measured it on leads, where Jose reconnected 37
     * accounts on 2026-09-14 and 5,430 adopted rows fell to 2,547 in eight
     * minutes — with the connection COUNT unchanged, so nothing announced it.
     * Same cause, opposite symptom, because the key is used differently.
     */
    public function test_reconnecting_a_branch_refreshes_rather_than_duplicating(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $branch = $tenant['franchise'];

        $first = $this->connect($tenant, $branch, 'NewJersey');

        Http::fake(['api.workiz.com/*' => Http::response(['flag' => true, 'data' => [$this->payload()]])]);
        $this->artisan('ezbizi:sync-workiz')->assertSuccessful();

        // Disconnect and reconnect: a brand new connection row for the same
        // branch, exactly as the real flow produces.
        $this->inTenant($tenant['org'], fn () => $first->delete());
        $second = $this->connect($tenant, $branch, 'NewJersey');

        $this->assertNotSame($first->getKey(), $second->getKey(), 'the reconnect must mint a new id or this test proves nothing');

        $this->artisan('ezbizi:sync-workiz')->assertSuccessful();

        $rows = $this->inTenant($tenant['org'], fn () => WorkizJob::query()->get());

        $this->assertCount(1, $rows);
        $this->assertSame($second->getKey(), $rows->first()->connection_id, 'the row should follow the live connection');
    }

    /**
     * ONE BRANCH FAILING MUST NOT LOSE THE OTHERS.
     *
     * The accounts are disjoint, so a branch dropped from a run is a branch
     * missing from every number built on this table — and with a 429 quota
     * refusal being a daily event on A-1's real sync, this is the normal case
     * rather than the exceptional one.
     */
    public function test_one_refused_branch_does_not_stop_the_rest(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $good = $tenant['franchise'];
        $bad = $this->extraFranchise($tenant, 'Cleveland');
        $this->connect($tenant, $good, 'NewJersey');
        $this->connect($tenant, $bad, 'Cleveland');

        Http::fake([
            'api.workiz.com/api/v1/api_Cleveland/*' => Http::response(
                ['error' => true, 'code' => 429, 'msg' => 'Account reached Api Quotas'], 429,
            ),
            'api.workiz.com/*' => Http::response(['flag' => true, 'data' => [$this->payload()]]),
        ]);

        $this->artisan('ezbizi:sync-workiz')
            ->expectsOutputToContain('Cleveland')
            ->assertSuccessful();

        $rows = $this->inTenant($tenant['org'], fn () => WorkizJob::query()->get());

        $this->assertCount(1, $rows);
        $this->assertSame($good->id, $rows->first()->franchise_id);
    }

    /**
     * The scheduler is nobody — no request, no user. Without dropping the
     * global scope the connection query returns nothing and the run reports a
     * clean sweep having fetched from no one.
     */
    public function test_it_finds_connections_with_no_signed_in_user(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $this->connect($tenant, $tenant['franchise'], 'NewJersey');

        Http::fake(['api.workiz.com/*' => Http::response(['flag' => true, 'data' => [$this->payload()]])]);

        $this->assertGuest();
        $this->artisan('ezbizi:sync-workiz')->assertSuccessful();

        $this->assertSame(1, $this->inTenant($tenant['org'], fn () => WorkizJob::query()->count()));
    }

    /**
     * THE WINDOW IS WHAT KEEPS THE PER-MINUTE SWEEP INSIDE WORKIZ'S QUOTA, so
     * it has to actually reach the request. A default that silently asked for
     * five years would be 26 refusals a day, measured on A-1's own sync.
     */
    public function test_the_rolling_window_reaches_the_request(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $this->connect($tenant, $tenant['franchise'], 'NewJersey');

        Http::fake(['api.workiz.com/*' => Http::response(['flag' => true, 'data' => []])]);

        $this->artisan('ezbizi:sync-workiz', ['--window' => 2])->assertSuccessful();

        $expected = now()->subDays(2)->toDateString();

        Http::assertSent(fn ($request) => str_contains($request->url(), 'start_date='.$expected));
    }

    /**
     * A job with no id would upsert onto the key '' and collapse every such row
     * onto one. Workiz has not been seen to send one, which is exactly why the
     * guard needs a test rather than a comment.
     */
    public function test_a_job_with_no_id_is_skipped_rather_than_landed(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $this->connect($tenant, $tenant['franchise'], 'NewJersey');

        Http::fake(['api.workiz.com/*' => Http::response(['flag' => true, 'data' => [
            $this->payload(['UUID' => '']),
            $this->payload(['UUID' => 'GOOD01']),
        ]])]);

        $this->artisan('ezbizi:sync-workiz')->assertSuccessful();

        $rows = $this->inTenant($tenant['org'], fn () => WorkizJob::query()->get());

        $this->assertCount(1, $rows);
        $this->assertSame('GOOD01', $rows->first()->remote_id);
    }

    /**
     * A mirror row nobody has refreshed since it was landed, as the A1 Tools
     * history import left 7,822 of them.
     */
    private function stale(array $tenant, SocialConnection $connection, string $uuid, string $status, string $scheduled = '2026-08-25 11:00:00'): WorkizJob
    {
        return $this->inTenant($tenant['org'], fn () => WorkizJob::create([
            'franchise_id' => $connection->scope_id,
            'connection_id' => $connection->getKey(),
            'remote_id' => $uuid,
            'status' => $status,
            'scheduled_for' => $scheduled,
            'payload' => $this->payload(['UUID' => $uuid, 'Status' => $status]),
            'synced_at' => now()->subDay(),
        ]));
    }

    /**
     * THE MIRROR HALF OF THE BUG JOSE REPORTED.
     *
     * `/job/all/` returns only UPCOMING appointments - measured: it ignores
     * start_date and does not page - so once a job's date passes, the sweep
     * never sees it again and it is frozen at whatever status it last had.
     * Measured on production 2026-09-24: 1,323 past-dated rows still "open".
     * Asked Workiz directly about three: UKHSX7 was Done, DQ2W8H done pending
     * approval, and only Y05TKI was genuinely still Submitted.
     *
     * `/job/get/{UUID}/` answers for a past job, so that is how it is re-read.
     */
    public function test_a_past_job_is_re_read_and_its_status_refreshed(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $connection = $this->connect($tenant, $tenant['franchise'], 'NewJersey');
        $this->stale($tenant, $connection, 'UKHSX7', 'Submitted');

        Http::fake([
            'api.workiz.com/api/v1/*/job/get/UKHSX7/' => Http::response(['flag' => true, 'data' => [
                $this->payload(['UUID' => 'UKHSX7', 'Status' => 'Done', 'LastStatusUpdate' => '2026-08-28 11:00:13']),
            ]]),
            'api.workiz.com/*' => Http::response(['flag' => true, 'data' => []]),
        ]);

        $this->artisan('ezbizi:sync-workiz')->assertSuccessful();

        $row = $this->inTenant($tenant['org'], fn () => WorkizJob::query()->where('remote_id', 'UKHSX7')->first());

        $this->assertSame('Done', $row->status);
    }

    /**
     * THE BUDGET IS PER BRANCH, and it is a quota decision: Workiz refuses with
     * 429 "Account reached Api Quotas", and the account is shared with A1 Tools
     * until its sync is switched off.
     */
    public function test_the_re_read_budget_caps_calls_per_branch(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $connection = $this->connect($tenant, $tenant['franchise'], 'NewJersey');

        foreach (['OLD001', 'OLD002', 'OLD003', 'OLD004', 'OLD005'] as $uuid) {
            $this->stale($tenant, $connection, $uuid, 'Submitted');
        }

        Http::fake([
            'api.workiz.com/api/v1/*/job/get/*' => Http::response(['flag' => true, 'data' => [$this->payload(['Status' => 'Done'])]]),
            'api.workiz.com/*' => Http::response(['flag' => true, 'data' => []]),
        ]);

        $this->artisan('ezbizi:sync-workiz', ['--reconcile' => 2])->assertSuccessful();

        Http::assertSentCount(1 + 2); // the upcoming sweep, then two re-reads
    }

    /**
     * Open jobs first. A Done or Canceled job rarely changes; a Submitted one
     * whose date has passed almost certainly has, and it is the one Jose sees
     * as wrong.
     */
    public function test_open_jobs_are_re_read_before_closed_ones(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $connection = $this->connect($tenant, $tenant['franchise'], 'NewJersey');

        // The closed one is OLDER, so least-recently-synced alone would pick it.
        $this->inTenant($tenant['org'], fn () => WorkizJob::create([
            'franchise_id' => $connection->scope_id,
            'connection_id' => $connection->getKey(),
            'remote_id' => 'CLOSED',
            'status' => 'Done',
            'scheduled_for' => now()->subDays(10)->format('Y-m-d H:i:s'),
            'payload' => $this->payload(['UUID' => 'CLOSED']),
            'synced_at' => now()->subWeek(),
        ]));
        $this->stale($tenant, $connection, 'OPEN01', 'Submitted');

        Http::fake([
            'api.workiz.com/api/v1/*/job/get/*' => Http::response(['flag' => true, 'data' => [$this->payload(['Status' => 'Done'])]]),
            'api.workiz.com/*' => Http::response(['flag' => true, 'data' => []]),
        ]);

        $this->artisan('ezbizi:sync-workiz', ['--reconcile' => 1])->assertSuccessful();

        Http::assertSent(fn ($request) => str_contains($request->url(), '/job/get/OPEN01/'));
        Http::assertNotSent(fn ($request) => str_contains($request->url(), '/job/get/CLOSED/'));
    }

    /**
     * Settled history is left alone: a job Done or Canceled more than thirty
     * days ago is not re-read at all, so the steady-state cost is the size of
     * what is still live, not the size of A-1's history.
     */
    public function test_settled_history_is_not_re_read(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $connection = $this->connect($tenant, $tenant['franchise'], 'NewJersey');
        $this->stale($tenant, $connection, 'ANCIENT', 'Done', '2025-06-01 09:00:00');

        Http::fake(['api.workiz.com/*' => Http::response(['flag' => true, 'data' => []])]);

        $this->artisan('ezbizi:sync-workiz')->assertSuccessful();

        Http::assertNotSent(fn ($request) => str_contains($request->url(), '/job/get/'));
    }

    /**
     * A job the sweep refreshed in THIS run is not fetched a second time.
     */
    public function test_a_job_the_sweep_just_refreshed_is_not_re_read(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $connection = $this->connect($tenant, $tenant['franchise'], 'NewJersey');
        $this->stale($tenant, $connection, 'DG0KYN', 'Submitted');

        Http::fake([
            'api.workiz.com/*' => Http::response(['flag' => true, 'data' => [$this->payload()]]),
        ]);

        $this->artisan('ezbizi:sync-workiz')->assertSuccessful();

        Http::assertNotSent(fn ($request) => str_contains($request->url(), '/job/get/DG0KYN/'));
    }

    /**
     * One refusal ends that branch's re-reads for the run. Hammering an account
     * that has already said "quota" spends the allowance the per-minute sweep
     * needs.
     */
    public function test_a_refusal_stops_that_branchs_re_reads(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $connection = $this->connect($tenant, $tenant['franchise'], 'NewJersey');

        foreach (['OLD001', 'OLD002', 'OLD003'] as $uuid) {
            $this->stale($tenant, $connection, $uuid, 'Submitted');
        }

        Http::fake([
            'api.workiz.com/api/v1/*/job/get/*' => Http::response(['error' => true, 'code' => 429, 'msg' => 'Account reached Api Quotas'], 429),
            'api.workiz.com/*' => Http::response(['flag' => true, 'data' => []]),
        ]);

        $this->artisan('ezbizi:sync-workiz', ['--reconcile' => 3])->assertSuccessful();

        Http::assertSentCount(1 + 1); // the sweep, then ONE refused re-read
    }
}
