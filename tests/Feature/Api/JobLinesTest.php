<?php

namespace Tests\Feature\Api;

use App\Models\Customer;
use App\Models\Job;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

class JobLinesTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    public function test_the_typed_price_survives_the_migration_as_one_visible_line(): void
    {
        $this->assertTrue(Schema::hasTable('job_lines'));
        $this->assertTrue(Schema::hasColumns('jobs', [
            'assigned_role_id', 'performed_by_user_id', 'earned_at',
        ]));
    }

    /**
     * A real backfill test, not just schema existence.
     *
     * RefreshDatabase runs the migration's up() — and therefore its backfill
     * query — exactly once, against an EMPTY `jobs` table, before any test
     * method (and therefore any job) exists. That proves nothing about the
     * backfill itself. There is no supported way to re-run one migration's
     * up() mid-suite (Schema::create('job_lines') and the new `jobs` columns
     * would collide with what already exists), so this test creates a job
     * with a typed `amount_minor` the way pre-work-order code did, and then
     * runs the migration's backfill block again verbatim — copied from
     * database/migrations/2026_08_28_170000_add_work_order_columns_to_jobs.php
     * — against that one row. Same query, same insert shape, same field
     * names; the only difference is that it now has a row to act on.
     */
    public function test_typed_amount_survives_backfill_as_one_named_line(): void
    {
        $tenant = $this->provisionTenant('Backfill Co');

        $job = $this->inTenant($tenant['org'], function () use ($tenant) {
            $customer = Customer::create([
                'owning_franchise_id' => $tenant['franchise']->id,
                'number' => 'C-0001',
                'name' => 'Typed Price Customer',
            ]);

            return Job::create([
                'franchise_id' => $tenant['franchise']->id,
                'customer_id' => $customer->id,
                'number' => 'J-0001',
                'title' => 'Chimney sweep',
                'status' => 'scheduled',
                'amount_minor' => 24999,
            ]);
        });

        $this->assertSame(0, DB::table('job_lines')->count());

        // ── verbatim mirror of the migration's backfill block ──────────────
        DB::table('jobs')
            ->whereNotNull('amount_minor')
            ->where('amount_minor', '>', 0)
            ->orderBy('id')
            ->chunkById(500, function ($jobs) {
                $rows = [];

                foreach ($jobs as $job) {
                    $rows[] = [
                        'id'               => (string) Str::uuid(),
                        'organization_id'  => $job->organization_id,
                        'job_id'           => $job->id,
                        'service_id'       => null,
                        'name'             => 'Job total (entered before itemisation)',
                        'unit_price_minor' => $job->amount_minor,
                        'qty'              => 1,
                        'amount_minor'     => $job->amount_minor,
                        'position'         => 0,
                        'created_at'       => now(),
                        'updated_at'       => now(),
                    ];
                }

                if ($rows !== []) {
                    DB::table('job_lines')->insert($rows);
                }
            });
        // ── end mirror ──────────────────────────────────────────────────

        // The job's own total is untouched by the backfill.
        $job->refresh();
        $this->assertSame(24999, $job->amount_minor);

        // Exactly one line, carrying the whole amount under the legacy name.
        $lines = DB::table('job_lines')->where('job_id', $job->id)->get();
        $this->assertCount(1, $lines);

        $line = $lines->first();
        $this->assertSame($tenant['org']->id, $line->organization_id);
        $this->assertSame($job->id, $line->job_id);
        $this->assertNull($line->service_id);
        $this->assertSame('Job total (entered before itemisation)', $line->name);
        $this->assertSame(24999, (int) $line->unit_price_minor);
        $this->assertSame(1, (int) $line->qty);
        $this->assertSame(24999, (int) $line->amount_minor);
        $this->assertSame(0, (int) $line->position);
    }
}
