<?php

namespace Tests\Feature\Workiz;

use App\Models\Customer;
use App\Models\Job;
use App\Models\WorkizClient;
use App\Models\WorkizJob;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/**
 * Jose, 2026-09-29: "right now we are getting our own ezbizi id's but we should
 * also get the workiz id's too". A job carries Workiz's job number (the SerialId
 * Workiz shows as "Job #"), a customer every Workiz client number it holds.
 */
class WorkizNumbersOnJobsAndCustomersTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    private array $t;

    private array $made = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->t = $this->provisionTenant('Alpha');
        $franchise = $this->t['franchise']->id;

        $this->made = $this->inTenant($this->t['org'], function () use ($franchise) {
            $customer = fn (string $number) => Customer::create(['owning_franchise_id' => $franchise, 'number' => $number, 'name' => "Customer {$number}"]);
            $job = fn (Customer $c, string $number) => Job::create([
                'franchise_id' => $franchise, 'customer_id' => $c->id, 'number' => $number,
                'title' => 'Sweep', 'status' => 'Submitted', 'currency' => 'USD',
            ]);

            $fromWorkiz = $customer('C-00001');
            $workizJob = $job($fromWorkiz, 'J-00001');

            // Two Workiz client records for one person, which the A1 import keeps together.
            foreach ([4512, 4600] as $clientId) {
                WorkizClient::create(['franchise_id' => $franchise, 'client_id' => $clientId, 'customer_id' => $fromWorkiz->id, 'synced_at' => now()]);
            }

            WorkizJob::create([
                'franchise_id' => $franchise, 'connection_id' => (string) Str::uuid(), 'remote_id' => 'DG0KYN',
                'serial_id' => 12345, 'client_id' => 4512, 'job_id' => $workizJob->id,
                'status' => 'Submitted', 'payload' => ['UUID' => 'DG0KYN', 'SerialId' => 12345], 'synced_at' => now(),
            ]);

            // Typed into EzBizi: Workiz has never heard of either.
            $typed = $customer('C-00002');

            return ['customer' => $fromWorkiz, 'job' => $workizJob, 'typed_customer' => $typed, 'typed_job' => $job($typed, 'J-00002')];
        });
    }

    public function test_a_job_shows_its_workiz_job_number_on_the_list_and_on_its_page(): void
    {
        $client = $this->signedInAs($this->t['admin']);
        $rows = collect($client->getJson('/api/v1/jobs?when=all')->assertOk()->json('data'))->keyBy('id');

        $this->assertSame(12345, $rows[$this->made['job']->id]['workiz_job_number']);
        $this->assertNull($rows[$this->made['typed_job']->id]['workiz_job_number']);

        $this->assertSame(12345, $client->getJson('/api/v1/jobs/'.$this->made['job']->id)->assertOk()->json('data.workiz_job_number'));
    }

    public function test_a_customer_shows_every_workiz_client_number_it_holds(): void
    {
        $client = $this->signedInAs($this->t['admin']);
        $rows = collect($client->getJson('/api/v1/customers')->assertOk()->json('data'))->keyBy('id');

        $this->assertSame([4512, 4600], $rows[$this->made['customer']->id]['workiz_client_ids']);
        $this->assertSame([], $rows[$this->made['typed_customer']->id]['workiz_client_ids']);

        $this->assertSame([4512, 4600], $client->getJson('/api/v1/customers/'.$this->made['customer']->id)->assertOk()->json('data.workiz_client_ids'));
    }

    /**
     * Without the Financials toggle the list loads the Workiz number alone. The
     * Jobs module's own query-count test signs in as an administrator, who holds
     * the toggle, so it never reaches this branch: this is the one that does.
     */
    public function test_without_the_financials_toggle_more_jobs_cost_no_more_queries(): void
    {
        $client = $this->signedInAs($this->userWithRole($this->t, 'Viewer', ['jobs.view']));

        $measure = function () use ($client) {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $rows = $client->getJson('/api/v1/jobs?when=all')->assertOk()->json('data');
            $queries = count(DB::getQueryLog());
            DB::disableQueryLog();

            return [$rows, $queries];
        };

        $measure(); // warm-up: first-request costs are not the list's
        [$few, $fewQueries] = $measure();

        $this->inTenant($this->t['org'], function () {
            foreach (range(1, 6) as $i) {
                $job = Job::create([
                    'franchise_id' => $this->t['franchise']->id, 'customer_id' => $this->made['customer']->id,
                    'number' => "J-1000{$i}", 'title' => 'Sweep', 'status' => 'Submitted', 'currency' => 'USD',
                ]);
                WorkizJob::create([
                    'franchise_id' => $this->t['franchise']->id, 'connection_id' => (string) Str::uuid(), 'remote_id' => "UU{$i}",
                    'serial_id' => 20000 + $i, 'client_id' => 4512, 'job_id' => $job->id,
                    'status' => 'Submitted', 'payload' => ['UUID' => "UU{$i}"], 'synced_at' => now(),
                ]);
            }
        });

        [$many, $manyQueries] = $measure();

        $this->assertCount(count($few) + 6, $many, 'the control: the page really grew');
        $this->assertSame($fewQueries, $manyQueries, "{$fewQueries} queries for ".count($few)." jobs, {$manyQueries} for ".count($many));
        $this->assertContains(20006, array_column($many, 'workiz_job_number'));
        $this->assertArrayNotHasKey('money', $many[0], 'and still no money without the toggle');
    }
}
