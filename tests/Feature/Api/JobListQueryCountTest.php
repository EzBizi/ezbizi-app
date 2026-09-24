<?php

namespace Tests\Feature\Api;

use App\Models\Customer;
use App\Models\Job;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/**
 * The jobs list costs the same number of queries whatever the page size.
 *
 * Measured by UI/UX on 2026-09-24: `present()` called `loadMissing` for
 * `lines`, `assignedRole` and `performedBy` on EVERY row, before it checked
 * whether it was building the detail view — so a 50-row page ran ~150 extra
 * queries for data only the detail panel reads. On a box with 8,000+ jobs that
 * is the whole list paying for a panel nobody has opened.
 *
 * The assertion is that N rows and 3N rows cost the SAME, not a particular
 * number: middleware, auth and tenancy run their own queries and those are
 * not this test's business. Only growth with N is.
 */
class JobListQueryCountTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    public function test_the_list_does_not_run_a_query_per_job(): void
    {
        $alpha = $this->provisionTenant('Alpha');

        // WARM-UP, discarded. The first request pays one-time costs — settings,
        // permissions, auth resolved for the first time — that later requests
        // do not. The first draft of this test measured 3 jobs cold and 9 jobs
        // warm, and after the fix it read "3 cost 11, 9 cost 8": fewer queries
        // for MORE rows, which is warm-up, not the code. Both measurements are
        // taken warm so the only thing that differs between them is N.
        $this->queriesForAListOf($alpha, 1);

        $few  = $this->queriesForAListOf($alpha, 3);
        $many = $this->queriesForAListOf($alpha, 9);

        $this->assertSame($few, $many,
            "3 jobs cost {$few} queries and 9 cost {$many}: the list grows with its rows.");
    }

    /** Queries for one GET /jobs holding exactly $count of this tenant's jobs. */
    private function queriesForAListOf(array $tenant, int $count): int
    {
        $this->inTenant($tenant['org'], function () use ($tenant) {
            Job::query()->forceDelete();
        });

        $this->inTenant($tenant['org'], function () use ($tenant, $count) {
            $customer = Customer::create([
                'owning_franchise_id' => $tenant['franchise']->id,
                'number' => 'C-'.bin2hex(random_bytes(3)),
                'name'   => 'List Customer',
            ]);

            for ($i = 0; $i < $count; $i++) {
                Job::create([
                    'franchise_id' => $tenant['franchise']->id,
                    'customer_id'  => $customer->id,
                    'number'   => 'J-'.bin2hex(random_bytes(3)),
                    'title'    => "Job {$i}",
                    'status'   => 'scheduled',
                    'currency' => 'USD',
                ]);
            }
        });

        $client = $this->signedInAs($tenant['admin']);

        DB::flushQueryLog();
        DB::enableQueryLog();
        $rows = $client->getJson('/api/v1/jobs')->assertOk()->json('data');
        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertCount($count, $rows, 'The control: the page really held that many jobs.');

        return $queries;
    }
}
