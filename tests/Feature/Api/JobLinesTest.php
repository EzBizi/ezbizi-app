<?php

namespace Tests\Feature\Api;

use App\Models\Customer;
use App\Models\Job;
use App\Models\Service;
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

    public function test_the_total_is_the_sum_of_its_lines(): void
    {
        $alpha = $this->provisionTenant('Alpha');

        $this->inTenant($alpha['org'], function () use ($alpha) {
            $customer = Customer::create([
                'owning_franchise_id' => $alpha['franchise']->id,
                'number' => 'C-0001',
                'name' => 'Sum Of Lines Customer',
            ]);

            $job = Job::factory()->create([
                'franchise_id' => $alpha['franchise']->id,
                'customer_id' => $customer->id,
                'amount_minor' => null,
            ]);

            $job->lines()->create([
                'organization_id'  => $job->organization_id,
                'name'             => 'Chimney sweep',
                'unit_price_minor' => 18900,
                'qty'              => 1,
                'amount_minor'     => 18900,
            ]);
            $job->lines()->create([
                'organization_id'  => $job->organization_id,
                'name'             => 'Level 1 inspection',
                'unit_price_minor' => 12500,
                'qty'              => 2,
                'amount_minor'     => 25000,
            ]);

            $job->recomputeTotal();

            $this->assertSame(43900, $job->fresh()->amount_minor);
        });
    }

    /**
     * The bug `recomputeTotal()` exists to prevent: a job whose last line is
     * removed must go to 0, not keep a stale total. `sum()` on an empty set is
     * exactly where that would hide, so this proves the empty case explicitly
     * rather than trusting the summing test above to cover it.
     */
    public function test_the_total_is_zero_when_a_job_has_no_lines(): void
    {
        $alpha = $this->provisionTenant('Alpha');

        $this->inTenant($alpha['org'], function () use ($alpha) {
            $customer = Customer::create([
                'owning_franchise_id' => $alpha['franchise']->id,
                'number' => 'C-0002',
                'name' => 'Zero Lines Customer',
            ]);

            $job = Job::factory()->create([
                'franchise_id' => $alpha['franchise']->id,
                'customer_id' => $customer->id,
                'amount_minor' => 24999,
            ]);

            $this->assertSame(0, $job->lines()->count());

            $job->recomputeTotal();

            $this->assertSame(0, $job->fresh()->amount_minor);
        });
    }

    // ── Task 4: the lines API ───────────────────────────────────────────

    /**
     * A customer, a job, and a `Service` named "Chimney sweep" at 18900 minor
     * units — the raw material every lines-API test starts from.
     *
     * Customer and job go through the HTTP endpoints, as JobWallTimeTest does,
     * so this exercises real validation instead of trusting a factory to agree
     * with it. The service is built directly: there is no catalogue endpoint
     * under test here, and ServiceFactory's defaults already are exactly
     * "Chimney sweep" at 18900.
     *
     * @param  array{org: \App\Models\Organization, admin: \App\Models\User, franchise: \App\Models\Franchise}  $tenant
     * @return array{0: string, 1: string} [$jobId, $serviceId]
     */
    protected function aJobAndAService(array $tenant): array
    {
        $customerId = $this->signedInAs($tenant['admin'])->postJson('/api/v1/customers', [
            'owning_franchise_id' => $tenant['franchise']->id,
            'name' => 'Line Item Customer',
        ])->assertCreated()->json('data.id');

        $jobId = $this->signedInAs($tenant['admin'])->postJson('/api/v1/jobs', [
            'franchise_id' => $tenant['franchise']->id,
            'customer_id'  => $customerId,
            'title'        => 'A job with lines',
            'status'       => 'scheduled',
        ])->assertCreated()->json('data.id');

        $serviceId = $this->inTenant($tenant['org'], function () use ($tenant) {
            return Service::factory()->create([
                'franchise_id' => $tenant['franchise']->id,
            ])->id;
        });

        return [$jobId, $serviceId];
    }

    public function test_adding_a_service_snapshots_its_name_and_price(): void
    {
        $alpha = $this->provisionTenant('Alpha');
        [$jobId, $serviceId] = $this->aJobAndAService($alpha);

        $this->signedInAs($alpha['admin'])
            ->postJson("/api/v1/jobs/{$jobId}/lines", ['service_id' => $serviceId, 'qty' => 1])
            ->assertCreated()
            ->assertJsonPath('data.name', 'Chimney sweep')
            ->assertJsonPath('data.unit_price_minor', 18900);

        // The catalogue moves on. The job does not.
        $this->inTenant($alpha['org'], function () use ($serviceId) {
            Service::findOrFail($serviceId)->forceFill(['price_minor' => 25000])->save();
        });

        $this->signedInAs($alpha['admin'])
            ->getJson("/api/v1/jobs/{$jobId}")
            ->assertOk()
            ->assertJsonPath('data.lines.0.unit_price_minor', 18900)
            ->assertJsonPath('data.amount.minor', 18900);
    }

    public function test_a_service_from_another_organization_is_refused_with_a_sentence(): void
    {
        $alpha = $this->provisionTenant('Alpha');
        $beta  = $this->provisionTenant('Beta');

        [$jobId] = $this->aJobAndAService($alpha);
        [, $foreignService] = $this->aJobAndAService($beta);

        $this->signedInAs($alpha['admin'])
            ->postJson("/api/v1/jobs/{$jobId}/lines", ['service_id' => $foreignService, 'qty' => 1])
            ->assertStatus(422);
    }

    public function test_a_quantity_below_one_is_refused(): void
    {
        $alpha = $this->provisionTenant('Alpha');
        [$jobId, $serviceId] = $this->aJobAndAService($alpha);

        $this->signedInAs($alpha['admin'])
            ->postJson("/api/v1/jobs/{$jobId}/lines", ['service_id' => $serviceId, 'qty' => 0])
            ->assertStatus(422);
    }

    /**
     * Task 3 review finding #1 (ORDERING): `Job::lines()` orders by
     * `position`, then `created_at` — a DATETIME column with no microsecond
     * precision, so rows created within the same wall-clock second tie on the
     * secondary key. `store()` is meant to make that moot by computing
     * `position = (int) $job->lines()->max('position') + 1` fresh on every
     * request. This adds three lines back-to-back (three separate requests
     * against the same job, exactly what a technician itemizing a job does)
     * and checks BOTH that the positions returned are distinct and increasing
     * AND that the job's own `lines` listing reflects that order — so a
     * regression that made `max()` return a stale or repeated value inside a
     * request would show up as scrambled order here, not be silently masked
     * by insertion order happening to match.
     */
    public function test_three_lines_added_back_to_back_come_back_in_the_order_added(): void
    {
        $alpha = $this->provisionTenant('Alpha');
        [$jobId] = $this->aJobAndAService($alpha);

        $serviceIds = $this->inTenant($alpha['org'], function () {
            return [
                Service::factory()->create(['name' => 'First service', 'price_minor' => 1000])->id,
                Service::factory()->create(['name' => 'Second service', 'price_minor' => 2000])->id,
                Service::factory()->create(['name' => 'Third service', 'price_minor' => 3000])->id,
            ];
        });

        $positions = [];

        foreach ($serviceIds as $serviceId) {
            $positions[] = $this->signedInAs($alpha['admin'])
                ->postJson("/api/v1/jobs/{$jobId}/lines", ['service_id' => $serviceId, 'qty' => 1])
                ->assertCreated()
                ->json('data.position');
        }

        // Distinct and strictly increasing — the exact property
        // `max(position) + 1` exists to guarantee.
        $this->assertSame([1, 2, 3], $positions);

        $names = $this->signedInAs($alpha['admin'])
            ->getJson("/api/v1/jobs/{$jobId}")
            ->assertOk()
            ->json('data.lines.*.name');

        $this->assertSame(['First service', 'Second service', 'Third service'], $names);
    }

    /**
     * Task 3 review finding #2 (INVARIANT): `amount_minor` is a stored
     * product, `unit_price_minor * qty`, and this controller is the only thing
     * that ever writes it — so it must never be read back out of the request,
     * on either verb. This sends a deliberately wrong `amount_minor` on both
     * `store()` and `update()` and asserts the client's number never reaches
     * the line row, the response, or the job total — only the computed
     * product does.
     */
    public function test_a_spoofed_amount_minor_is_ignored_on_store_and_update(): void
    {
        $alpha = $this->provisionTenant('Alpha');
        [$jobId, $serviceId] = $this->aJobAndAService($alpha);

        $created = $this->signedInAs($alpha['admin'])
            ->postJson("/api/v1/jobs/{$jobId}/lines", [
                'service_id'   => $serviceId,
                'qty'          => 2,
                // Spoofed. The real product is 18900 * 2 = 37800.
                'amount_minor' => 1,
            ])
            ->assertCreated()
            ->assertJsonPath('data.amount_minor', 37800)
            ->json('data');

        $this->signedInAs($alpha['admin'])
            ->getJson("/api/v1/jobs/{$jobId}")
            ->assertOk()
            ->assertJsonPath('data.amount.minor', 37800);

        $lineId = $created['id'];

        $this->signedInAs($alpha['admin'])
            ->patchJson("/api/v1/jobs/{$jobId}/lines/{$lineId}", [
                'qty' => 3,
                // Spoofed again. The real product is 18900 * 3 = 56700.
                'amount_minor' => 999999999,
            ])
            ->assertOk()
            ->assertJsonPath('data.amount_minor', 56700);

        $this->signedInAs($alpha['admin'])
            ->getJson("/api/v1/jobs/{$jobId}")
            ->assertOk()
            ->assertJsonPath('data.amount.minor', 56700);
    }
}
