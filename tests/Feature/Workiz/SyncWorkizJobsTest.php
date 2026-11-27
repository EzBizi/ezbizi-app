<?php

namespace Tests\Feature\Workiz;

use App\Console\Commands\ProjectWorkizRecords;
use App\Models\AutomationEvent;
use App\Models\Customer;
use App\Models\Franchise;
use App\Models\Job;
use App\Models\Lead;
use App\Models\SocialConnection;
use App\Models\WorkizJob;
use App\Support\Audit\AuditLogger;
use App\Support\Social\SocialProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
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
            // A-1's closed statuses, as its settings say them (Jose 11-04: no status list in code).
            app(\App\Support\Settings\SettingsResolver::class)->set('jobs.earned_statuses', $tenant['org'], ['Done']);
            app(\App\Support\Settings\SettingsResolver::class)->set('jobs.cancelled_statuses', $tenant['org'], ['Canceled']);

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

        $this->artisan('ezbizi:sync-workiz', ['--reconcile' => 2, '--verify' => 0])->assertSuccessful();

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

        $this->artisan('ezbizi:sync-workiz', ['--reconcile' => 1, '--verify' => 0])->assertSuccessful();

        Http::assertSent(fn ($request) => str_contains($request->url(), '/job/get/OPEN01/'));
        Http::assertNotSent(fn ($request) => str_contains($request->url(), '/job/get/CLOSED/'));
    }

    /**
     * The OPEN-FIRST budget never spends on settled history: a job Done or
     * Canceled more than thirty days ago is not what it re-reads. Only the
     * `--verify` slice reaches those (next test), one per branch per run.
     */
    public function test_settled_history_is_not_re_read(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $connection = $this->connect($tenant, $tenant['franchise'], 'NewJersey');
        $this->stale($tenant, $connection, 'ANCIENT', 'Done', '2025-06-01 09:00:00');

        Http::fake(['api.workiz.com/*' => Http::response(['flag' => true, 'data' => []])]);

        $this->artisan('ezbizi:sync-workiz', ['--verify' => 0])->assertSuccessful();

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

    /**
     * "SETTLED" HISTORY IS NOT SETTLED. Measured 2026-09-25 against Workiz's own
     * Done Pending Approval tab for Connecticut: of the first 48 jobs there that
     * the mirror holds, 6 were Done or Canceled in the mirror. A1 Tools' copy,
     * frozen once its window moved on, and never re-read because closed jobs
     * older than thirty days were skipped for ever.
     *
     * So one row per branch per run, of ANY status, least recently read first:
     * every job is checked against Workiz eventually, at a constant cost.
     */
    public function test_settled_history_is_verified_least_recently_read_first(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $connection = $this->connect($tenant, $tenant['franchise'], 'NewJersey');
        $older = $this->stale($tenant, $connection, 'ANCIENT', 'Done', '2025-06-01 09:00:00');
        $this->inTenant($tenant['org'], fn () => $older->forceFill(['synced_at' => now()->subWeek()])->save());
        $this->stale($tenant, $connection, 'ANCNT2', 'Canceled', '2025-06-02 09:00:00');

        Http::fake([
            'api.workiz.com/api/v1/*/job/get/ANCIENT/' => Http::response(['flag' => true, 'data' => [
                $this->payload(['UUID' => 'ANCIENT', 'Status' => 'done pending approval']),
            ]]),
            'api.workiz.com/*' => Http::response(['flag' => true, 'data' => []]),
        ]);

        $this->artisan('ezbizi:sync-workiz')->assertSuccessful();

        Http::assertNotSent(fn ($request) => str_contains($request->url(), '/job/get/ANCNT2/'));
        $this->assertSame(
            'done pending approval',
            $this->inTenant($tenant['org'], fn () => WorkizJob::query()->where('remote_id', 'ANCIENT')->value('status')),
        );
    }

    /**
     * A refusal reaches the LOG, not only the console: the scheduler throws
     * console output away, so without this Workiz's quota cannot be measured.
     * The branch and the status code, never the URL, which carries the token.
     */
    public function test_a_refusal_is_written_to_the_log_without_the_token(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $connection = $this->connect($tenant, $tenant['franchise'], 'NewJersey');
        $token = 'api_NewJersey'; // what connect() stored, and what the URL path carries

        Log::spy();
        Http::fake(['api.workiz.com/*' => Http::response(['error' => true, 'code' => 429, 'msg' => 'Account reached Api Quotas'], 429)]);

        $this->artisan('ezbizi:sync-workiz')->assertSuccessful();

        Log::shouldHaveReceived('warning')->withArgs(fn ($message) => str_contains($message, 'NewJersey')
            && str_contains($message, '429')
            && ! str_contains($message, $token));
    }

    /** The two slices never spend two calls on one job in one run. */
    public function test_a_job_is_not_re_read_twice_in_one_run(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $connection = $this->connect($tenant, $tenant['franchise'], 'NewJersey');
        $this->stale($tenant, $connection, 'ONLY01', 'Submitted');

        Http::fake([
            'api.workiz.com/api/v1/*/job/get/*' => Http::response(['flag' => true, 'data' => [$this->payload(['UUID' => 'ONLY01'])]]),
            'api.workiz.com/*' => Http::response(['flag' => true, 'data' => []]),
        ]);

        $this->artisan('ezbizi:sync-workiz', ['--reconcile' => 2, '--verify' => 1])->assertSuccessful();

        Http::assertSentCount(1 + 1); // the sweep, then ONE re-read of the only job
    }

    // ── a job Workiz deleted ──────────────────────────────────────────

    /** An upcoming open job, projected onto an EzBizi job that still holds what the projection wrote. */
    private function projected(array $tenant, SocialConnection $connection, string $uuid): array
    {
        // Retirement is switched on by hand once MA is connected (services.workiz.retire_deleted).
        config(['services.workiz.retire_deleted' => true]);

        return $this->inTenant($tenant['org'], function () use ($tenant, $connection, $uuid) {
            $customer = Customer::create(['owning_franchise_id' => $connection->scope_id, 'number' => 'C-1', 'name' => 'Pat Rivera', 'is_active' => true]);
            $job = Job::create([
                'franchise_id' => $connection->scope_id, 'customer_id' => $customer->id, 'number' => 'J-1', 'title' => 'Sweep',
                'status' => 'Submitted', 'scheduled_for' => now()->addDays(3)->format('Y-m-d 09:00:00'), 'currency' => 'USD',
            ]);
            $row = $this->stale($tenant, $connection, $uuid, 'Submitted', now()->addDays(3)->format('Y-m-d 09:00:00'));
            $row->forceFill(['job_id' => $job->id, 'projected' => ProjectWorkizRecords::followedValues($job)])->save();

            return [$row, $job];
        });
    }

    /**
     * A-1 moved Massachusetts into its own account on 2026-10-05 by deleting 56
     * upcoming jobs from Nationwide's. Measured: the listing stopped carrying
     * them, and /job/get/ answers 204. The mirror kept all 56 open.
     */
    public function test_a_job_workiz_deleted_leaves_the_mirror_and_its_ezbizi_job_goes(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $connection = $this->connect($tenant, $tenant['franchise'], 'Nationwide');
        [$row, $job] = $this->projected($tenant, $connection, '09RR7R');

        Http::fake([
            'api.workiz.com/api/v1/*/job/get/09RR7R/' => Http::response(null, 204),
            'api.workiz.com/*' => Http::response(['flag' => true, 'data' => []]),
        ]);

        $this->artisan('ezbizi:sync-workiz')->assertSuccessful();

        $this->inTenant($tenant['org'], function () use ($row, $job) {
            $this->assertNull(WorkizJob::query()->find($row->id), 'the board and the openings read this table');
            $this->assertTrue(Job::withTrashed()->find($job->id)->trashed());
            $this->assertSame('deleted', json_decode(DB::table('audit_events')->where('action', 'workiz.job_gone')->sole()->metadata, true)['ezbizi_job']);
        });
    }

    /**
     * Off by default. The 43 Boston North jobs staff moved into MA's account
     * still hold Massachusetts' seats until MA's own jobs arrive, so nothing is
     * retired until the switch is turned on (desk, 2026-10-05).
     */
    public function test_nothing_is_retired_while_the_switch_is_off(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $this->assertFalse(config('services.workiz.retire_deleted'), 'shipped off');
        $connection = $this->connect($tenant, $tenant['franchise'], 'Nationwide');
        [$row, $job] = $this->projected($tenant, $connection, '09RR7R');
        config(['services.workiz.retire_deleted' => false]);

        Http::fake([
            'api.workiz.com/api/v1/*/job/get/09RR7R/' => Http::response(null, 204),
            'api.workiz.com/*' => Http::response(['flag' => true, 'data' => []]),
        ]);

        $this->artisan('ezbizi:sync-workiz')->assertSuccessful();

        $this->inTenant($tenant['org'], function () use ($row, $job) {
            $this->assertNotNull(WorkizJob::query()->find($row->id), 'still holding its seat');
            $this->assertFalse(Job::withTrashed()->find($job->id)->trashed());
        });
    }

    /**
     * A past job deleted in Workiz, on a branch on v2: the 204 from its re-read
     * and v2's search finding no job of that number retire it, as the listing
     * never covers the past (Nationwide, 2026-10-16: 36 of September's jobs).
     * A job the search still finds stays.
     */
    public function test_a_past_job_v2_search_cannot_find_is_retired(): void
    {
        config(['services.workiz.retire_deleted' => true]);
        $tenant = $this->provisionTenant('Alpha');
        $connection = $this->connect($tenant, $tenant['franchise'], 'Nationwide');
        $this->inTenant($tenant['org'], function () use ($connection) {
            $connection->connect(['api_token' => 'v2key', 'refresh_token' => 'v2key', 'api_version' => 'v2', 'v1' => ['api_token' => 'v1tok', 'auth_secret' => 's']]);
            $connection->save();
        });
        $gone = $this->stale($tenant, $connection, 'GONE01', 'Submitted', now()->subMonth()->format('Y-m-d 09:00:00'));
        $kept = $this->stale($tenant, $connection, 'KEPT01', 'Submitted', now()->subMonth()->format('Y-m-d 10:00:00'));
        $this->inTenant($tenant['org'], function () use ($gone, $kept) {
            $gone->forceFill(['serial_id' => 100077680])->save();
            $kept->forceFill(['serial_id' => 100077681])->save();
        });

        Http::fake(function ($r) {
            return match (true) {
                str_contains($r->url(), '/api/v1/') => Http::response(null, 204),
                str_contains($r->url(), '/v2/search') => Http::response(['limit' => 15, 'totalResults' => 0,
                    'data' => str_contains($r->url(), '100077681') ? [['uuid' => 'KEPT01', 'serialId' => 100077681]] : []]),
                default => Http::response(['hasMore' => false, 'data' => []]),
            };
        });

        $this->artisan('ezbizi:sync-workiz', ['--reconcile' => 5, '--verify' => 0])->assertSuccessful();

        $this->inTenant($tenant['org'], function () use ($gone, $kept) {
            $this->assertNull(WorkizJob::query()->find($gone->id));
            $this->assertNotNull(WorkizJob::query()->find($kept->id), 'the search still finds it');
        });
    }

    /** Somebody moved the visit in EzBizi after the projection wrote it: the job is theirs and stays. */
    public function test_a_deleted_job_a_person_changed_keeps_its_ezbizi_job(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $connection = $this->connect($tenant, $tenant['franchise'], 'Nationwide');
        [$row, $job] = $this->projected($tenant, $connection, '09RR7R');
        $this->inTenant($tenant['org'], fn () => $job->forceFill(['status' => 'Pending'])->save());

        Http::fake([
            'api.workiz.com/api/v1/*/job/get/09RR7R/' => Http::response(null, 204),
            'api.workiz.com/*' => Http::response(['flag' => true, 'data' => []]),
        ]);

        $this->artisan('ezbizi:sync-workiz')->assertSuccessful();

        $this->inTenant($tenant['org'], function () use ($row, $job) {
            $this->assertNull(WorkizJob::query()->find($row->id));
            $this->assertFalse(Job::withTrashed()->find($job->id)->trashed());
        });
    }

    /**
     * Two signals, not one: a 204 alone retires nothing. Not when the listing
     * itself failed, and not for a past job the listing never covers -- that
     * history stays, moved to the back of the queue as before.
     */
    public function test_a_204_alone_retires_nothing(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $connection = $this->connect($tenant, $tenant['franchise'], 'Nationwide');
        [$upcoming] = $this->projected($tenant, $connection, '09RR7R');
        $past = $this->stale($tenant, $connection, 'PAST01', 'Submitted', now()->subMonth()->format('Y-m-d 09:00:00'));
        $done = $this->stale($tenant, $connection, 'DONE01', 'Done', now()->format('Y-m-d 08:00:00'));

        // ONE fake: a second Http::fake stacks behind the first and never answers.
        // The listing fails on the first run and comes back whole on the second.
        Http::fake([
            'api.workiz.com/api/v1/*/job/get/*' => Http::response(null, 204),
            'api.workiz.com/api/v1/*/job/all/*' => Http::sequence()
                ->push(['error' => true, 'code' => 429, 'msg' => 'Account reached Api Quotas'], 429)
                ->whenEmpty(Http::response(['flag' => true, 'data' => []])),
        ]);

        $this->artisan('ezbizi:sync-workiz')->assertSuccessful();
        $this->inTenant($tenant['org'], fn () => $this->assertNotNull(WorkizJob::query()->find($upcoming->id), 'the listing failed'));

        // Later than the first run's re-reads: synced_at keeps whole seconds,
        // and a second run in the same second would re-read nothing at all.
        $this->travel(2)->minutes();
        $this->artisan('ezbizi:sync-workiz', ['--reconcile' => 5])->assertSuccessful();

        $this->inTenant($tenant['org'], function () use ($upcoming, $past, $done) {
            $this->assertNull(WorkizJob::query()->find($upcoming->id), 'the control: with the listing whole, the upcoming one goes');
            $this->assertNotNull(WorkizJob::query()->find($past->id), 'outside the listing');
            $this->assertNotNull(WorkizJob::query()->find($done->id), 'finished work is history');
        });
    }

    /**
     * Work recorded against the visit in EzBizi -- a priced line, an estimate,
     * a payment -- keeps it, though nobody touched the fields the projection
     * follows (Customers, 2026-10-05: money must never hang off a deleted job).
     */
    public function test_a_deleted_job_with_work_recorded_against_it_keeps_its_ezbizi_job(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $connection = $this->connect($tenant, $tenant['franchise'], 'Nationwide');
        [$row, $job] = $this->projected($tenant, $connection, '09RR7R');
        // Written past the model, so the job's own amount stays what the projection wrote.
        $this->inTenant($tenant['org'], fn () => DB::table('job_lines')->insert([
            'id' => (string) Str::uuid(), 'organization_id' => $tenant['org']->id, 'job_id' => $job->id,
            'name' => 'Chimney sweep', 'unit_price_minor' => 18900, 'qty' => 1, 'amount_minor' => 18900, 'created_at' => now(), 'updated_at' => now(),
        ]));

        Http::fake([
            'api.workiz.com/api/v1/*/job/get/09RR7R/' => Http::response(null, 204),
            'api.workiz.com/*' => Http::response(['flag' => true, 'data' => []]),
        ]);

        $this->artisan('ezbizi:sync-workiz')->assertSuccessful();

        $this->inTenant($tenant['org'], function () use ($row, $job) {
            $this->assertNull(WorkizJob::query()->find($row->id));
            $this->assertFalse(Job::withTrashed()->find($job->id)->trashed());
            $this->assertSame('kept: something in EzBizi names it', json_decode(DB::table('audit_events')->where('action', 'workiz.job_gone')->sole()->metadata, true)['ezbizi_job']);
        });
    }

    /**
     * 2026-10-05: 45 of the 61 jobs A-1 moved from Nationwide to Massachusetts
     * were named only by a Local Services lead the matcher had linked by phone,
     * or by the event their Workiz status change fired. Neither is anybody's
     * work: the job goes, and the lead is let go so the matcher finds the job
     * it became now -- the Massachusetts copy.
     */
    public function test_a_phone_matched_lead_and_an_automation_event_do_not_keep_a_deleted_job(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $connection = $this->connect($tenant, $tenant['franchise'], 'Nationwide');
        [$row, $job] = $this->projected($tenant, $connection, '09RR7R');
        $lead = $this->inTenant($tenant['org'], function () use ($tenant, $job) {
            AutomationEvent::create([
                'organization_id' => $tenant['org']->id, 'event_key' => 'job.status_changed', 'subject_type' => $job->getMorphClass(),
                'subject_id' => $job->id, 'payload' => [], 'occurred_at' => now(), 'available_at' => now(),
            ]);

            return Lead::create([
                'organization_id' => $tenant['org']->id, 'source' => 'local_services', 'remote_id' => 'L-1', 'phone' => '(617) 555-0100',
                'phone_digits' => '6175550100', 'received_at' => now()->subDays(2), 'job_id' => $job->id, 'job_matched_by' => 'phone',
            ]);
        });

        Http::fake([
            'api.workiz.com/api/v1/*/job/get/09RR7R/' => Http::response(null, 204),
            'api.workiz.com/*' => Http::response(['flag' => true, 'data' => []]),
        ]);

        $this->artisan('ezbizi:sync-workiz')->assertSuccessful();

        $this->inTenant($tenant['org'], function () use ($job, $lead) {
            $this->assertTrue(Job::withTrashed()->find($job->id)->trashed());
            $this->assertNull($lead->fresh()->job_id, 'free for the matcher');
            $this->assertNull($lead->fresh()->job_matched_by);
            $gone = json_decode(DB::table('audit_events')->where('action', 'workiz.job_gone')->sole()->metadata, true);
            $this->assertSame('deleted', $gone['ezbizi_job']);
            $this->assertSame([$lead->id], $gone['leads_released']);
        });
    }

    /** The control: a lead somebody booked as this job, or an edit somebody made (the API audits each), still keeps it. */
    #[DataProvider('somebodysWork')]
    public function test_a_lead_somebody_booked_or_an_audited_edit_keeps_a_deleted_job(string $what): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $connection = $this->connect($tenant, $tenant['franchise'], 'Nationwide');
        [$row, $job] = $this->projected($tenant, $connection, '09RR7R');
        $this->inTenant($tenant['org'], fn () => $what === 'lead'
            ? Lead::create(['organization_id' => $tenant['org']->id, 'source' => 'local_services', 'remote_id' => 'L-1', 'received_at' => now(), 'job_id' => $job->id])
            : app(AuditLogger::class)->log('job.updated', $job, ['notes' => ['before' => null, 'after' => 'Gate code 4411']]));

        Http::fake([
            'api.workiz.com/api/v1/*/job/get/09RR7R/' => Http::response(null, 204),
            'api.workiz.com/*' => Http::response(['flag' => true, 'data' => []]),
        ]);

        $this->artisan('ezbizi:sync-workiz')->assertSuccessful();

        $this->inTenant($tenant['org'], function () use ($job) {
            $this->assertFalse(Job::withTrashed()->find($job->id)->trashed());
            $this->assertSame('kept: something in EzBizi names it', json_decode(DB::table('audit_events')->where('action', 'workiz.job_gone')->sole()->metadata, true)['ezbizi_job']);
        });
    }

    public static function somebodysWork(): array
    {
        return ['a lead booked as the job' => ['lead'], 'an audited edit' => ['edit']];
    }

    /** v2 answers 500 for one New Jersey job (2026-10-05): it goes to the back of the queue, so it cannot stop the branch's re-reads for ever. */
    public function test_a_job_workiz_cannot_answer_for_is_moved_back_not_asked_again_and_again(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $connection = $this->connect($tenant, $tenant['franchise'], 'NewJersey');
        $row = $this->stale($tenant, $connection, 'POISON', 'Submitted');

        Http::fake([
            'api.workiz.com/api/v1/*/job/get/POISON/' => Http::response(['code' => 'INTERNAL_SERVER_ERROR', 'status' => 500], 500),
            'api.workiz.com/*' => Http::response(['flag' => true, 'data' => []]),
        ]);

        $this->artisan('ezbizi:sync-workiz', ['--reconcile' => 1, '--verify' => 0])->assertSuccessful();

        $synced = $this->inTenant($tenant['org'], fn () => WorkizJob::query()->find($row->id)->synced_at);
        $this->assertTrue($synced->gt(now()->subMinute()), 'moved to the back of the queue');
    }
}
