<?php

namespace Tests\Feature\Reports;

use App\Models\Customer;
use App\Models\Franchise;
use App\Models\Job;
use App\Support\Reports\ReportRunner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/**
 * The runner's tests are scope tests wearing a reporting hat. Each one is a
 * way this could leak or lose rows, written down before it could.
 */
class ReportRunnerTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    private function runReport(array $definition, $user): \App\Support\Reports\ReportResult
    {
        return app(ReportRunner::class)->run($definition, $user);
    }

    private function titlesOnly(): array
    {
        return [
            'root'    => 'jobs',
            'columns' => [['resource' => 'jobs', 'column' => 'title']],
            'filters' => [],
        ];
    }

    /**
     * Both tables are stricter than they look: jobs.customer_id and
     * customers.owning_franchise_id are NOT NULL with no default, so every
     * fixture needs a real customer on a real franchise. Discovered by the
     * database refusing the insert, not by reading the model.
     */
    private function makeJob(array $tenant, array $attributes = [], ?Franchise $at = null): Job
    {
        $franchise = $at ?? $tenant['franchise'];

        return $this->inTenant($tenant['org'], function () use ($tenant, $attributes, $franchise) {
            $customer = $attributes['customer'] ?? Customer::factory()->create([
                'owning_franchise_id' => $franchise->id,
            ]);

            unset($attributes['customer']);

            return Job::factory()->create(array_merge([
                'franchise_id' => $franchise->id,
                'customer_id'  => $customer->id,
            ], $attributes));
        });
    }

    private function makeCustomer(array $tenant, array $attributes = []): Customer
    {
        return $this->inTenant($tenant['org'], fn () => Customer::factory()->create(array_merge([
            'owning_franchise_id' => $tenant['franchise']->id,
        ], $attributes)));
    }

    public function test_it_returns_the_columns_asked_for(): void
    {
        $tenant = $this->provisionTenant('Alpha');

        $this->makeJob($tenant, ['title' => 'Sweep']);

        $result = $this->runReport($this->titlesOnly(), $tenant['admin']);

        $this->assertSame(['jobs.title'], array_column($result->columns, 'key'));
        $this->assertSame('Title', $result->columns[0]['label']);
        $this->assertSame('Sweep', $result->rows[0]['jobs.title']);
        $this->assertSame(1, $result->count);
    }

    public function test_it_joins_along_a_declared_relation(): void
    {
        $tenant = $this->provisionTenant('Alpha');

        $this->makeJob($tenant, [
            'title'    => 'Sweep',
            'customer' => $this->makeCustomer($tenant, ['name' => 'Ada Lovelace']),
        ]);

        $result = $this->runReport([
            'root'    => 'jobs',
            'columns' => [
                ['resource' => 'jobs', 'column' => 'title'],
                ['resource' => 'customers', 'column' => 'name'],
            ],
            'filters' => [],
        ], $tenant['admin']);

        $this->assertSame('Sweep', $result->rows[0]['jobs.title']);
        $this->assertSame('Ada Lovelace', $result->rows[0]['customers.name']);
    }

    public function test_a_soft_deleted_row_is_not_reported(): void
    {
        $tenant = $this->provisionTenant('Alpha');

        $this->makeJob($tenant, ['title' => 'Live']);
        $gone = $this->makeJob($tenant, ['title' => 'Gone']);
        $this->inTenant($tenant['org'], fn () => $gone->delete());

        $result = $this->runReport($this->titlesOnly(), $tenant['admin']);

        $this->assertSame(['Live'], array_column($result->rows, 'jobs.title'));
    }

    /**
     * THE CASE THAT WAS UNEXPRESSIBLE UNTIL THE performed_by EDGE EXISTED.
     *
     * `jobs.performed_by_user_id` is NULLABLE, so a job nobody has been
     * credited with is a real row. It must still appear in a report carrying a
     * technician column, with the name blank — if the join's conditions were in
     * WHERE instead of ON, the row would vanish and the report would quietly
     * under-count the work done.
     *
     * The docblock below explains why this could previously only be tested
     * through a soft-deleted customer: `jobs.customer_id` is NOT NULL, so a job
     * with no customer cannot exist at all.
     */
    public function test_a_row_with_nothing_to_join_to_is_still_returned(): void
    {
        $tenant = $this->provisionTenant('Alpha');

        $this->makeJob($tenant, ['title' => 'Nobody credited', 'performed_by_user_id' => null]);

        $result = $this->runReport([
            'root'    => 'jobs',
            'columns' => [
                ['resource' => 'jobs', 'column' => 'title'],
                ['resource' => 'users', 'column' => 'name'],
            ],
            'filters' => [],
        ], $tenant['admin']);

        $this->assertCount(1, $result->rows, 'a job with no technician was dropped by the join');
        $this->assertSame('Nobody credited', $result->rows[0]['jobs.title']);
        $this->assertNull($result->rows[0]['users.name']);
    }

    /** The positive half: a job that DOES name somebody reports the name. */
    public function test_a_job_reports_the_person_who_performed_it(): void
    {
        $tenant = $this->provisionTenant('Alpha');

        $this->makeJob($tenant, [
            'title'                => 'Credited',
            'performed_by_user_id' => $tenant['admin']->id,
        ]);

        $result = $this->runReport([
            'root'    => 'jobs',
            'columns' => [
                ['resource' => 'jobs', 'column' => 'title'],
                ['resource' => 'users', 'column' => 'name'],
            ],
            'filters' => [],
        ], $tenant['admin']);

        $this->assertSame('Credited', $result->rows[0]['jobs.title']);
        $this->assertNotNull($result->rows[0]['users.name']);
    }

    /**
     * THE LEFT JOIN MUST STAY A LEFT JOIN, and this is the case that proves it.
     *
     * Scope conditions on a joined table belong in the ON clause. Put them in
     * WHERE and the join silently becomes an INNER one: the unmatched row has
     * NULL there, NULL fails the comparison, and the owning row disappears.
     *
     * The obvious way to test that would be a job with no customer at all —
     * except `jobs.customer_id` is NOT NULL with no default, so such a job
     * cannot exist. (Found by the database refusing the insert; the model does
     * not say so.) A soft-deleted customer is the same mechanism and is real:
     * the ON clause stops matching, and the job must survive with a blank
     * customer rather than vanish from the report.
     */
    public function test_a_soft_deleted_joined_row_reads_as_absent_rather_than_dropping_its_owner(): void
    {
        $tenant = $this->provisionTenant('Alpha');

        $customer = $this->makeCustomer($tenant, ['name' => 'Erased Person']);
        $this->makeJob($tenant, ['title' => 'Sweep', 'customer' => $customer]);
        $this->inTenant($tenant['org'], fn () => $customer->delete());

        $result = $this->runReport([
            'root'    => 'jobs',
            'columns' => [
                ['resource' => 'jobs', 'column' => 'title'],
                ['resource' => 'customers', 'column' => 'name'],
            ],
            'filters' => [],
        ], $tenant['admin']);

        $this->assertCount(1, $result->rows, 'the job vanished because its customer was deleted');
        $this->assertSame('Sweep', $result->rows[0]['jobs.title']);
        $this->assertNull($result->rows[0]['customers.name'], 'a deleted customer was still named');
    }

    public function test_it_refuses_a_resource_that_is_not_in_the_map(): void
    {
        $tenant = $this->provisionTenant('Alpha');

        $this->expectException(InvalidArgumentException::class);

        $this->runReport([
            'root'    => 'password_reset_tokens',
            'columns' => [['resource' => 'password_reset_tokens', 'column' => 'email']],
            'filters' => [],
        ], $tenant['admin']);
    }

    public function test_it_refuses_a_column_that_is_not_declared(): void
    {
        $tenant = $this->provisionTenant('Alpha');

        $this->expectException(InvalidArgumentException::class);

        $this->runReport([
            'root'    => 'jobs',
            'columns' => [['resource' => 'jobs', 'column' => 'performed_by_user_id']],
            'filters' => [],
        ], $tenant['admin']);
    }

    /**
     * Re-pointed on 2026-09-10 when the `performed_by` edge made jobs -> users
     * reachable. It was the second tripwire to fire on that one line, which is
     * what both were for: a change in reach must be a deliberate edit here, not
     * a silent widening. `customers` declares no outgoing relations, so nothing
     * can be reached from it.
     */
    public function test_it_refuses_a_resource_it_cannot_reach_by_a_declared_edge(): void
    {
        $tenant = $this->provisionTenant('Alpha');

        $this->expectException(InvalidArgumentException::class);

        $this->runReport([
            'root'    => 'customers',
            'columns' => [
                ['resource' => 'customers', 'column' => 'name'],
                ['resource' => 'jobs', 'column' => 'title'],
            ],
            'filters' => [],
        ], $tenant['admin']);
    }

    public function test_a_report_with_no_columns_is_refused(): void
    {
        $tenant = $this->provisionTenant('Alpha');

        $this->expectException(InvalidArgumentException::class);

        $this->runReport(['root' => 'jobs', 'columns' => [], 'filters' => []], $tenant['admin']);
    }

    /**
     * THE TEST THAT ACTUALLY PINS `organization_id`.
     *
     * The jobs version below does not, and that was measured rather than
     * assumed: with the organization_id filter commented out, the jobs test
     * still passed, because `franchiseIds()` had already excluded the other
     * tenant's franchises. It was proving the franchise filter twice and the
     * tenant filter not at all.
     *
     * `customers` declares no scope columns — shared organization-wide by
     * design — so organization_id is the ONLY thing standing between Alpha and
     * Beta's customer book. Sabotage that line and this test goes red, which
     * is what makes it worth having.
     */
    public function test_another_organizations_rows_are_never_returned_for_an_organization_wide_resource(): void
    {
        $alpha = $this->provisionTenant('Alpha');
        $beta  = $this->provisionTenant('Beta');

        $this->makeCustomer($alpha, ['name' => 'Alpha Person']);
        $this->makeCustomer($beta, ['name' => 'Beta Person']);

        $result = $this->runReport([
            'root'    => 'customers',
            'columns' => [['resource' => 'customers', 'column' => 'name']],
            'filters' => [],
        ], $alpha['admin']);

        $this->assertSame(['Alpha Person'], array_column($result->rows, 'customers.name'));
    }

    public function test_another_organizations_rows_are_never_returned(): void
    {
        $alpha = $this->provisionTenant('Alpha');
        $beta  = $this->provisionTenant('Beta');

        $this->makeJob($beta, ['title' => 'Beta work']);

        $result = $this->runReport($this->titlesOnly(), $alpha['admin']);

        $this->assertSame([], $result->rows);
    }

    /**
     * The positive control for the test above. Without it, a runner that
     * returned nothing at all would pass the isolation test and look correct.
     */
    public function test_this_organizations_rows_are_returned(): void
    {
        $alpha = $this->provisionTenant('Alpha');
        $beta  = $this->provisionTenant('Beta');

        $this->makeJob($alpha, ['title' => 'Alpha work']);

        $this->makeJob($beta, ['title' => 'Beta work']);

        $result = $this->runReport($this->titlesOnly(), $alpha['admin']);

        $this->assertSame(['Alpha work'], array_column($result->rows, 'jobs.title'));
    }

    /**
     * Reach, not just tenancy. franchiseIds() is already intersected with what
     * this person may act on, so a job in a franchise they hold no assignment
     * to must not appear even in the organization view.
     */
    public function test_a_franchise_outside_this_persons_reach_is_not_reported(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $other = $this->extraFranchise($tenant, 'Alpha F2');

        $this->makeJob($tenant, ['title' => 'Mine']);
        $this->makeJob($tenant, ['title' => 'Not mine'], at: $other);

        $limited = $this->userWithRole(
            $tenant,
            'One Franchise',
            ['reports.view', 'jobs.view'],
            onlyAt: $tenant['franchise'],
        );

        $result = $this->runReport($this->titlesOnly(), $limited);

        $this->assertSame(['Mine'], array_column($result->rows, 'jobs.title'));
    }

    public function test_a_filter_narrows_the_rows(): void
    {
        $tenant = $this->provisionTenant('Alpha');

        $this->makeJob($tenant, ['title' => 'Keep', 'status' => 'open']);
        $this->makeJob($tenant, ['title' => 'Drop', 'status' => 'done']);

        $result = $this->runReport([
            'root'    => 'jobs',
            'columns' => [['resource' => 'jobs', 'column' => 'title']],
            'filters' => [
                ['resource' => 'jobs', 'column' => 'status', 'op' => 'eq', 'value' => 'open'],
            ],
        ], $tenant['admin']);

        $this->assertSame(['Keep'], array_column($result->rows, 'jobs.title'));
    }

    public function test_an_unknown_filter_operator_is_refused_rather_than_ignored(): void
    {
        $tenant = $this->provisionTenant('Alpha');

        $this->expectException(InvalidArgumentException::class);

        $this->runReport([
            'root'    => 'jobs',
            'columns' => [['resource' => 'jobs', 'column' => 'title']],
            'filters' => [
                ['resource' => 'jobs', 'column' => 'status', 'op' => 'drop table', 'value' => 'x'],
            ],
        ], $tenant['admin']);
    }

    public function test_the_row_cap_holds_and_says_so(): void
    {
        $tenant = $this->provisionTenant('Alpha');

        // A bulk insert rather than 5001 factory calls, which would take
        // minutes and prove the same thing.
        $customer = $this->makeCustomer($tenant);

        $this->inTenant($tenant['org'], function () use ($tenant, $customer) {
            $now = now();
            $rows = [];

            for ($i = 0; $i <= ReportRunner::ROW_CAP; $i++) {
                $rows[] = [
                    'id'              => (string) Str::uuid(),
                    'organization_id' => $tenant['org']->id,
                    'franchise_id'    => $tenant['franchise']->id,
                    'customer_id'     => $customer->id,
                    'number'          => 'J-cap-'.$i,
                    'title'           => 'Row '.$i,
                    'status'          => 'scheduled',
                    'created_at'      => $now,
                    'updated_at'      => $now,
                ];
            }

            foreach (array_chunk($rows, 1000) as $chunk) {
                DB::table('jobs')->insert($chunk);
            }
        });

        $result = $this->runReport($this->titlesOnly(), $tenant['admin']);

        $this->assertCount(ReportRunner::ROW_CAP, $result->rows);
        $this->assertSame(ReportRunner::ROW_CAP, $result->count);
        $this->assertTrue($result->capped, 'a truncated result must admit it was truncated');
    }

    public function test_a_result_inside_the_cap_is_not_reported_as_capped(): void
    {
        $tenant = $this->provisionTenant('Alpha');

        $this->makeJob($tenant, ['title' => 'Only one']);

        $this->assertFalse($this->runReport($this->titlesOnly(), $tenant['admin'])->capped);
    }

    /**
     * The timeout must not survive the call. Connections are pooled, so a
     * leaked session variable would cap every later query on this connection,
     * writes included — a fault that would surface far from here.
     */
    public function test_the_statement_timeout_is_cleared_afterwards(): void
    {
        $tenant = $this->provisionTenant('Alpha');

        $this->runReport($this->titlesOnly(), $tenant['admin']);

        $this->assertSame(
            0,
            (int) DB::selectOne('SELECT @@SESSION.max_execution_time AS t')->t,
        );
    }
}
