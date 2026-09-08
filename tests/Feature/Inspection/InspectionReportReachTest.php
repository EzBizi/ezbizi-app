<?php

namespace Tests\Feature\Inspection;

use App\Models\Customer;
use App\Models\InspectionDefinition;
use App\Models\InspectionDefinitionVersion;
use App\Models\InspectionReport;
use App\Models\Job;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/**
 * Which reports a branch can read.
 *
 * ── The hole this closes ────────────────────────────────────────
 *
 * `InspectionReportsController::index` filtered on the job's franchise and
 * then let `job_id IS NULL` through unconditionally:
 *
 *     $q->whereNull('job_id')
 *       ->orWhereHas('job', fn ($jobs) => $jobs->whereIn('franchise_id', $reachable));
 *
 * So a report filed WITHOUT a job was readable by every branch in the
 * organization. That is not a corner: the picker allows starting an inspection
 * against a customer with no job, and `InspectionRun::automationScope()`
 * already treats that as ordinary — it falls back to the customer's
 * `owning_franchise_id` precisely because a customer-only inspection is real.
 *
 * The same filter is copied into `InspectionGlance::constrainToReach`, whose
 * docblock says it matches the controller, so the dashboard counted the same
 * out-of-reach reports.
 *
 * ── Why the fixture gives the customer a franchise ──────────────
 *
 * A customer with no owning franchise would make the fix untestable: both the
 * broken and the fixed query return nothing for it, so the test would pass
 * either way. The customer here belongs to a branch the reader cannot reach,
 * which is the case that separates them.
 */
class InspectionReportReachTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    public function test_a_customer_only_report_is_not_readable_from_another_branch(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $elsewhere = $this->extraFranchise($tenant, 'Second');

        // The reader can act only at the SECOND branch.
        $reader = $this->userWithRole($tenant, 'Tech', ['inspection.view'], onlyAt: $elsewhere);

        $this->inTenant($tenant['org'], function () use ($tenant) {
            $customer = $this->customer($tenant, $tenant['franchise']->id);
            $this->report($tenant, $customer, job: null);
        });

        $body = $this->signedInAs($reader)->getJson('/api/v1/inspections/reports')->assertOk()->json();

        $this->assertSame([], $body['data'], 'a report from another branch must not be listed');
    }

    /** The ordinary case still works: a report from your own branch is listed. */
    public function test_a_customer_only_report_from_your_own_branch_is_readable(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $reader = $this->userWithRole($tenant, 'Tech', ['inspection.view'], onlyAt: $tenant['franchise']);

        $this->inTenant($tenant['org'], function () use ($tenant) {
            $customer = $this->customer($tenant, $tenant['franchise']->id);
            $this->report($tenant, $customer, job: null);
        });

        $body = $this->signedInAs($reader)->getJson('/api/v1/inspections/reports')->assertOk()->json();

        $this->assertCount(1, $body['data'], 'your own branch must still be readable');
    }

    /** A report reached through a job is unchanged — that half already worked. */
    public function test_a_report_on_another_branchs_job_is_still_hidden(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $elsewhere = $this->extraFranchise($tenant, 'Second');
        $reader = $this->userWithRole($tenant, 'Tech', ['inspection.view'], onlyAt: $elsewhere);

        $this->inTenant($tenant['org'], function () use ($tenant) {
            $customer = $this->customer($tenant, $tenant['franchise']->id);
            $job = Job::create([
                'franchise_id' => $tenant['franchise']->id,
                'customer_id' => $customer->id,
                'number' => 'J-'.bin2hex(random_bytes(3)),
                'title' => 'Work',
                'status' => 'scheduled',
            ]);
            $this->report($tenant, $customer, $job);
        });

        $body = $this->signedInAs($reader)->getJson('/api/v1/inspections/reports')->assertOk()->json();

        $this->assertSame([], $body['data']);
    }

    // ── fixtures ─────────────────────────────────────────────────

    private function customer(array $tenant, string $franchiseId): Customer
    {
        return Customer::create([
            'owning_franchise_id' => $franchiseId,
            'number' => 'C-'.bin2hex(random_bytes(3)),
            'name' => '14 Maple',
        ]);
    }

    private function report(array $tenant, Customer $customer, ?Job $job): InspectionReport
    {
        $definition = InspectionDefinition::create([
            'name' => 'Level '.bin2hex(random_bytes(2)),
            'created_by' => $tenant['admin']->id,
            'screens' => [],
        ]);

        $version = InspectionDefinitionVersion::create([
            'inspection_definition_id' => $definition->id,
            'version' => 1,
            'layout_preset' => 'step_strip',
            'screens' => [],
            'published_by' => $tenant['admin']->id,
        ]);

        return InspectionReport::create([
            'customer_id' => $customer->id,
            'job_id' => $job?->id,
            'inspection_definition_id' => $definition->id,
            'inspection_definition_version_id' => $version->id,
            'submitted_by' => $tenant['admin']->id,
            'submitted_at' => now(),
            'answers' => [],
            'layout_preset' => 'step_strip',
            'definition_name' => 'Level 1',
        ]);
    }
}
