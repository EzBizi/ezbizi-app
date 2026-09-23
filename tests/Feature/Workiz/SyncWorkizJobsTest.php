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
}
