<?php

namespace Tests\Feature\Workiz;

use App\Models\Franchise;
use App\Models\SocialConnection;
use App\Models\WorkizJob;
use App\Support\Social\SocialProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

class ImportWorkizHistoryTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    private string $file;

    protected function tearDown(): void
    {
        if (isset($this->file) && is_file($this->file)) {
            unlink($this->file);
        }

        parent::tearDown();
    }

    private function export(array $rows): string
    {
        $this->file = tempnam(sys_get_temp_dir(), 'wz').'.json';
        file_put_contents($this->file, json_encode($rows));

        return $this->file;
    }

    private function payload(array $overrides = []): array
    {
        return array_merge(require base_path('tests/Fixtures/workiz/job_payload.php'), $overrides);
    }

    private function connect(array $tenant, Franchise $branch): SocialConnection
    {
        return $this->inTenant($tenant['org'], function () use ($tenant, $branch) {
            $c = new SocialConnection;
            $c->forceFill([
                'organization_id' => $tenant['org']->id,
                'scope_type' => SocialConnection::SCOPE_FRANCHISE,
                'scope_id' => $branch->id,
                'provider' => SocialProvider::Workiz->value,
                'remote_id' => $branch->id,
                'display_name' => $branch->name,
            ]);
            $c->connect(['api_token' => 'api_x', 'refresh_token' => 'api_x']);
            $c->save();

            return $c;
        });
    }

    public function test_it_lands_history_on_the_branch_named_in_the_export(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $branch = $tenant['franchise'];
        $this->connect($tenant, $branch);

        $file = $this->export([
            ['branch' => $branch->name, 'payload' => $this->payload(['UUID' => 'HIST01'])],
            ['branch' => $branch->name, 'payload' => $this->payload(['UUID' => 'HIST02'])],
        ]);

        $this->artisan('ezbizi:import-workiz-history', ['file' => $file])->assertSuccessful();

        $rows = $this->inTenant($tenant['org'], fn () => WorkizJob::query()->get());

        $this->assertCount(2, $rows);
        $this->assertSame([$branch->id, $branch->id], $rows->pluck('franchise_id')->all());
        $this->assertSame('Gas System', $rows->first()->payload['system_type']);
    }

    /**
     * IT SHARES THE SWEEP'S KEY, so history and the live window converge on the
     * same rows rather than racing each other into duplicates.
     */
    public function test_a_job_the_sweep_already_mirrored_is_updated_not_duplicated(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $branch = $tenant['franchise'];
        $connection = $this->connect($tenant, $branch);

        $this->inTenant($tenant['org'], fn () => WorkizJob::create([
            'franchise_id' => $branch->id,
            'connection_id' => $connection->getKey(),
            'remote_id' => 'HIST01',
            'status' => 'Submitted',
            'payload' => [],
            'synced_at' => now(),
        ]));

        $file = $this->export([
            ['branch' => $branch->name, 'payload' => $this->payload(['UUID' => 'HIST01', 'Status' => 'Done'])],
        ]);

        $this->artisan('ezbizi:import-workiz-history', ['file' => $file])->assertSuccessful();

        $rows = $this->inTenant($tenant['org'], fn () => WorkizJob::query()->get());

        $this->assertCount(1, $rows);
        $this->assertSame('Done', $rows->first()->status);
    }

    /**
     * A branch nothing recognises is reported and skipped. Attaching a book to
     * whichever franchise happened to be handy would put real work in another
     * branch's roll-up and nothing would say so.
     */
    public function test_an_unmatched_branch_is_reported_and_nothing_is_guessed(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $this->connect($tenant, $tenant['franchise']);

        $file = $this->export([
            ['branch' => 'Atlantis', 'payload' => $this->payload(['UUID' => 'ATL001'])],
            ['branch' => $tenant['franchise']->name, 'payload' => $this->payload(['UUID' => 'OK0001'])],
        ]);

        $this->artisan('ezbizi:import-workiz-history', ['file' => $file])
            ->expectsOutputToContain('Atlantis')
            ->assertSuccessful();

        $rows = $this->inTenant($tenant['org'], fn () => WorkizJob::query()->get());

        $this->assertCount(1, $rows);
        $this->assertSame('OK0001', $rows->first()->remote_id);
    }

    /**
     * THE TWO CASES A NAME MATCH WOULD HAVE LOST, both measured on production.
     *
     * The connection labelled "Washington" is attached to the franchise named
     * "New York", so matching on the label alone refuses A-1's whole New York
     * book — 173 real jobs, reported as an unmatched branch, which looks like a
     * finding rather than a bug. And A1 Tools calls that branch "NY", which
     * matches neither string; Jose ruled it ("NY is clearly New York").
     */
    public function test_it_matches_the_franchise_name_and_the_recorded_alias(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $newYork = $this->extraFranchise($tenant, 'New York');

        $connection = $this->connect($tenant, $newYork);
        $this->inTenant($tenant['org'], function () use ($connection) {
            $connection->display_name = 'Washington';
            $connection->save();
        });

        $file = $this->export([
            ['branch' => 'New York', 'payload' => $this->payload(['UUID' => 'FRAN01'])],
            ['branch' => 'Washington', 'payload' => $this->payload(['UUID' => 'LABEL1'])],
            ['branch' => 'NY', 'payload' => $this->payload(['UUID' => 'ALIAS1'])],
        ]);

        $this->artisan('ezbizi:import-workiz-history', ['file' => $file])->assertSuccessful();

        $rows = $this->inTenant($tenant['org'], fn () => WorkizJob::query()->get());

        $this->assertCount(3, $rows, 'franchise name, connection label and alias must all land');
        $this->assertSame([$newYork->id], $rows->pluck('franchise_id')->unique()->all());
    }

    public function test_branch_names_match_despite_case_and_stray_whitespace(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $branch = $tenant['franchise'];
        $this->connect($tenant, $branch);

        $file = $this->export([
            ['branch' => '  '.mb_strtoupper($branch->name).' ', 'payload' => $this->payload(['UUID' => 'CASE01'])],
        ]);

        $this->artisan('ezbizi:import-workiz-history', ['file' => $file])->assertSuccessful();

        $this->assertSame(1, $this->inTenant($tenant['org'], fn () => WorkizJob::query()->count()));
    }

    public function test_a_dry_run_writes_nothing(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $this->connect($tenant, $tenant['franchise']);

        $file = $this->export([
            ['branch' => $tenant['franchise']->name, 'payload' => $this->payload(['UUID' => 'DRY001'])],
        ]);

        $this->artisan('ezbizi:import-workiz-history', ['file' => $file, '--dry-run' => true])->assertSuccessful();

        $this->assertSame(0, $this->inTenant($tenant['org'], fn () => WorkizJob::query()->count()));
    }

    public function test_a_missing_file_fails_rather_than_reporting_a_clean_run(): void
    {
        $this->provisionTenant('Alpha');

        $this->artisan('ezbizi:import-workiz-history', ['file' => '/no/such/export.json'])->assertFailed();
    }
}
