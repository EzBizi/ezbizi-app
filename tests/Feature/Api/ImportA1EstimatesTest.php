<?php

namespace Tests\Feature\Api;

use App\Models\AutomationEvent;
use App\Models\Customer;
use App\Models\Estimate;
use App\Models\Job;
use App\Models\WorkizJob;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/**
 * A1 Tools' estimates into EzBizi, read from the file the migration doc names
 * ("Estimates importer: the file it reads", 9484d42f) and joined to customers
 * through the clients import's manifest.
 */
class ImportA1EstimatesTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    private array $alpha;
    private string $customer;
    private string $manifest;

    protected function setUp(): void
    {
        parent::setUp();
        $this->alpha = $this->provisionTenant('Alpha');
        $this->customer = $this->aCustomer();
        $this->manifest = $this->writeManifest([100 => $this->customer]);
    }

    public function test_an_estimate_lands_on_the_manifests_customer_as_a1_history(): void
    {
        $this->import([$this->row()])->assertSuccessful()->expectsOutputToContain('created: 1');

        $e = $this->inTenant($this->alpha['org'], fn () => Estimate::query()->with('lines')->sole());
        $this->assertSame($this->customer, $e->customer_id);
        $this->assertSame($this->alpha['franchise']->id, $e->franchise_id);
        $this->assertSame(['1042', 'Relining and crown repair', 'accepted', 'a1', '5521'],
            [$e->number, $e->title, $e->status, $e->source, $e->external_ref]);
        // The file's total, not the lines': most A1 estimates have no lines.
        $this->assertSame(185000, $e->total_minor);
        $this->assertSame('2025-06-02 13:40:00', $e->getRawOriginal('created_at'));
        $this->assertNotNull($e->accepted_at);
        $this->assertCount(1, $e->lines);
        $this->assertSame(0, $this->inTenant($this->alpha['org'], fn () => AutomationEvent::query()->count()),
            'History raises no automation event.');
    }

    public function test_an_estimate_whose_client_is_not_in_the_manifest_is_skipped_and_counted(): void
    {
        $this->import([$this->row(['a1_client_id' => 999])])->assertSuccessful()
            ->expectsOutputToContain('skipped for no customer: 1');

        $this->assertSame(0, DB::table('estimates')->count());
    }

    public function test_a_rerun_skips_what_is_already_in(): void
    {
        $this->import([$this->row()])->assertSuccessful();
        $this->import([$this->row()])->assertSuccessful()->expectsOutputToContain('already in: 1');

        $this->assertSame(1, DB::table('estimates')->count());
    }

    public function test_a_dry_run_writes_nothing(): void
    {
        $this->import([$this->row()], ['--dry-run' => true])->assertSuccessful()
            ->expectsOutputToContain('to create: 1')->expectsOutputToContain('Dry run');

        $this->assertSame(0, DB::table('estimates')->count());
    }

    public function test_an_unknown_key_refuses_the_whole_run(): void
    {
        $this->import([$this->row(), $this->row(['a1_id' => 5522, 'colour' => 'red'])])->assertFailed();

        $this->assertSame(0, DB::table('estimates')->count(), 'Nothing written, the good row included.');
    }

    public function test_a_null_number_gets_an_ezbizi_number(): void
    {
        $this->import([$this->row(['number' => null])])->assertSuccessful();

        $this->assertStringStartsWith('EST-', DB::table('estimates')->value('number'));
    }

    public function test_the_job_is_linked_only_when_it_is_the_same_customers(): void
    {
        $mine = $this->aWorkizJob('MINE01', $this->customer);
        $this->aWorkizJob('THEIRS', $this->aCustomer());

        $this->import([
            $this->row(['job_workiz_uuid' => 'MINE01']),
            $this->row(['a1_id' => 5522, 'job_workiz_uuid' => 'THEIRS']),
        ])->assertSuccessful()->expectsOutputToContain('job linked: 1')->expectsOutputToContain('job unlinked: 1');

        $this->assertSame($mine, DB::table('estimates')->where('external_ref', '5521')->value('job_id'));
        $this->assertNull(DB::table('estimates')->where('external_ref', '5522')->value('job_id'));
    }

    public function test_a_currency_that_is_not_the_franchises_is_refused_and_counted(): void
    {
        $this->import([$this->row(['currency' => 'CAD'])])->assertSuccessful()
            ->expectsOutputToContain('refused for a currency mismatch: 1');

        $this->assertSame(0, DB::table('estimates')->count());
    }

    public function test_one_audit_row_carries_the_counts(): void
    {
        $this->import([$this->row(), $this->row(['a1_id' => 5522, 'a1_client_id' => 999])])->assertSuccessful();

        $rows = DB::table('audit_events')->where('action', 'estimate.imported')->get();
        $this->assertCount(1, $rows);
        $m = json_decode($rows[0]->metadata, true);
        $this->assertSame([1, 1], [$m['created'], $m['skipped_no_customer']]);
    }

    private function import(array $estimates, array $options = []): \Illuminate\Testing\PendingCommand
    {
        $file = tempnam(sys_get_temp_dir(), 'est');
        file_put_contents($file, json_encode([
            'format' => 'ezbizi.a1-estimates/1',
            'exported_at' => '2026-09-30T14:00:00Z',
            'organization' => 'Alpha',
            'estimates' => $estimates,
        ]));

        return $this->artisan('jobs:import-a1-estimates', ['file' => $file, '--clients-manifest' => $this->manifest, '--wait' => 0] + $options);
    }

    private function row(array $overrides = []): array
    {
        return array_merge([
            'a1_id' => 5521,
            'a1_client_id' => 100,
            'number' => '1042',
            'title' => 'Relining and crown repair',
            'status' => 'accepted',
            'issued_on' => '2025-06-02',
            'accepted_at' => '2025-06-05T15:12:00Z',
            'created_at' => '2025-06-02T13:40:00Z',
            'currency' => 'USD',
            'total_minor' => 185000,
            'note' => null,
            'job_workiz_uuid' => null,
            'lines' => [['name' => 'Chimney liner', 'description' => null, 'quantity' => '1', 'unit_price_minor' => 100000]],
        ], $overrides);
    }

    /** @param array<int, string> $map A1 client id => customer id */
    private function writeManifest(array $map): string
    {
        $file = tempnam(sys_get_temp_dir(), 'man');
        file_put_contents($file, implode('', array_map(
            fn (int $a1, string $customer) => json_encode(['a1_id' => $a1, 'status' => 'created', 'customer_id' => $customer])."\n",
            array_keys($map), $map,
        )));

        return $file;
    }

    private function aCustomer(): string
    {
        return $this->inTenant($this->alpha['org'], fn () => Customer::create([
            'owning_franchise_id' => $this->alpha['franchise']->id,
            'number' => 'C-'.bin2hex(random_bytes(3)),
            'name' => 'A Customer',
        ])->id);
    }

    private function aWorkizJob(string $remoteId, string $customerId): string
    {
        return $this->inTenant($this->alpha['org'], function () use ($remoteId, $customerId) {
            $job = Job::create(['franchise_id' => $this->alpha['franchise']->id, 'customer_id' => $customerId,
                'number' => 'J-'.bin2hex(random_bytes(3)), 'title' => 'A job', 'status' => 'Done', 'currency' => 'USD']);
            $row = WorkizJob::create(['franchise_id' => $this->alpha['franchise']->id, 'remote_id' => $remoteId,
                'status' => 'Done', 'payload' => [], 'synced_at' => now()]);
            $row->job_id = $job->id;
            $row->save();

            return $job->id;
        });
    }
}
