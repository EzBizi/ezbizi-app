<?php

namespace Tests\Feature\Workiz;

use App\Models\WorkizJob;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/**
 * The landing table itself.
 *
 * Two properties, and the second is the one the hourly — now per-minute —
 * sweep depends on: a job that has already landed cannot land again.
 */
class WorkizJobTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    public function test_a_landed_job_is_scoped_to_its_organization(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $branch = $tenant['franchise'];

        $row = $this->inTenant($tenant['org'], fn () => WorkizJob::create([
            'franchise_id' => $branch->id,
            'remote_id' => 'DG0KYN',
            'service_area' => 'New Jersey',
            'postal_code' => '07030',
            'status' => 'Submitted',
            'payload' => ['UUID' => 'DG0KYN', 'pricing' => '$149 - Level 2 Inspection'],
            'synced_at' => now(),
        ]));

        $this->assertSame($tenant['org']->id, $row->organization_id);
        $this->assertSame('New Jersey', $row->service_area);

        // The account's own custom field survives the round trip. It is the
        // reason `payload` exists at all: a fixed schema would drop it and
        // nothing would report the loss.
        $fresh = $this->inTenant($tenant['org'], fn () => WorkizJob::query()->first());
        $this->assertSame('$149 - Level 2 Inspection', $fresh->payload['pricing']);
    }

    /**
     * IDEMPOTENCE IS A DATABASE CONSTRAINT, NOT A HABIT OF THE IMPORTER.
     *
     * The sweep runs every minute. If the unique index is not there, one bad
     * upsert condition mints a second row and a second history, and the count
     * everything downstream is built on quietly doubles.
     */
    public function test_the_same_remote_job_cannot_land_twice_for_one_connection(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $branch = $tenant['franchise'];
        $connectionId = (string) Str::uuid();

        $land = fn () => $this->inTenant($tenant['org'], fn () => WorkizJob::create([
            'franchise_id' => $branch->id,
            'connection_id' => $connectionId,
            'remote_id' => 'DG0KYN',
            'status' => 'Submitted',
            'payload' => [],
            'synced_at' => now(),
        ]));

        $land();

        $this->expectException(QueryException::class);

        $land();
    }

    /**
     * The SAME Workiz job id in two accounts is two different jobs.
     *
     * `UUID` is six characters and unique only within an account, and A-1 runs
     * nine disjoint ones. A unique index on `remote_id` alone would refuse the
     * second branch's genuine job as a duplicate of the first's.
     */
    public function test_two_connections_may_each_hold_the_same_remote_id(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $nj = $tenant['franchise'];
        $ct = $this->extraFranchise($tenant, 'Connecticut');

        $this->inTenant($tenant['org'], function () use ($nj, $ct) {
            foreach ([[$nj, Str::uuid()], [$ct, Str::uuid()]] as [$branch, $connection]) {
                WorkizJob::create([
                    'franchise_id' => $branch->id,
                    'connection_id' => (string) $connection,
                    'remote_id' => 'DG0KYN',
                    'status' => 'Submitted',
                    'payload' => [],
                    'synced_at' => now(),
                ]);
            }
        });

        $this->assertSame(2, $this->inTenant($tenant['org'], fn () => WorkizJob::query()->count()));
    }
}
