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
        $reader = $this->userWithRole($tenant, 'Tech', ['inspection.view', 'inspection.new.view', 'inspection.reports.view', 'inspection.builder.view'], onlyAt: $elsewhere);

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
        $reader = $this->userWithRole($tenant, 'Tech', ['inspection.view', 'inspection.new.view', 'inspection.reports.view', 'inspection.builder.view'], onlyAt: $tenant['franchise']);

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
        $reader = $this->userWithRole($tenant, 'Tech', ['inspection.view', 'inspection.new.view', 'inspection.reports.view', 'inspection.builder.view'], onlyAt: $elsewhere);

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

    /**
     * READING ONE BY ID obeys the same boundary as listing.
     *
     * The index filtered and the policy did not: `InspectionReportPolicy::view`
     * checked `organization_id` and nothing else, so any report in the
     * organization could be fetched by id from any branch — customer, address,
     * every answer, and the PDF. The class docblock said otherwise ("A report
     * whose job is outside reach is no such report"), which is exactly why it
     * survived: the promise was written down and never implemented.
     *
     * Not found rather than forbidden, because "there is a report here you may
     * not read" is itself a disclosure about another branch's work.
     */
    public function test_another_branchs_report_cannot_be_fetched_by_id(): void
    {
        [$reader, $report] = $this->readerAndForeignReport();

        $this->signedInAs($reader)
            ->getJson("/api/v1/inspections/reports/{$report->id}")
            ->assertNotFound();
    }

    /** The PDF is the same document by another route, so it obeys the same rule. */
    public function test_another_branchs_report_cannot_be_downloaded_as_a_pdf(): void
    {
        [$reader, $report] = $this->readerAndForeignReport();

        $this->signedInAs($reader)
            ->get("/api/v1/inspections/reports/{$report->id}/pdf")
            ->assertNotFound();
    }

    /** Nor archived — a write on another branch's record is worse than a read. */
    public function test_another_branchs_report_cannot_be_archived(): void
    {
        [$reader, $report] = $this->readerAndForeignReport(['inspection.view', 'inspection.archive', 'inspection.new.view', 'inspection.reports.view', 'inspection.builder.view']);

        $this->signedInAs($reader)
            ->postJson("/api/v1/inspections/reports/{$report->id}/archive")
            ->assertNotFound();

        $this->assertNull(
            $this->inTenant($reader->organization, fn () => $report->fresh()->archived_at),
            'it must not have been archived',
        );
    }

    /**
     * The control. Your OWN branch's report is still readable by id.
     *
     * Without this, a policy that denied everything would pass the three above
     * and the module would be broken in a way the suite called correct.
     */
    public function test_your_own_branchs_report_is_still_readable_by_id(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $reader = $this->userWithRole($tenant, 'Tech', ['inspection.view', 'inspection.new.view', 'inspection.reports.view', 'inspection.builder.view'], onlyAt: $tenant['franchise']);

        $report = $this->inTenant($tenant['org'], function () use ($tenant) {
            $customer = $this->customer($tenant, $tenant['franchise']->id);

            return $this->report($tenant, $customer, job: null);
        });

        $this->signedInAs($reader)
            ->getJson("/api/v1/inspections/reports/{$report->id}")
            ->assertOk();
    }

    /**
     * A CUSTOMER-ONLY report from another branch is reachable by none of the
     * four verbs.
     *
     * This is the case the other tests in this file could not see, and the
     * reason is worth keeping. `assertReach` opened with:
     *
     *     if ($report->job_id === null) { return; }
     *
     * — so a report with no job was reachable by id, by PDF, and by archive,
     * from any branch. The tests above use a JOB-linked report, which I
     * described in their fixture as "the strongest form" because a job names
     * its franchise outright. That was true and it was the wrong fixture: it
     * exercised the half of `assertReach` that worked and was blind to the
     * early return above it.
     *
     * All three verbs go through the same `assertReach`, so a single test can
     * carry them — but the statuses are COLLECTED FIRST and asserted
     * afterwards, so a failing `show` cannot hide a leaking `pdf`.
     */
    public function test_a_customer_only_report_from_another_branch_is_reachable_by_nothing(): void
    {
        [$reader, $report] = $this->readerAndForeignReport(
            ['inspection.view', 'inspection.archive', 'inspection.new.view', 'inspection.reports.view', 'inspection.builder.view'],
            withJob: false,
        );

        $show = $this->signedInAs($reader)->getJson("/api/v1/inspections/reports/{$report->id}")->status();
        $pdf = $this->signedInAs($reader)->get("/api/v1/inspections/reports/{$report->id}/pdf")->status();
        $archive = $this->signedInAs($reader)
            ->postJson("/api/v1/inspections/reports/{$report->id}/archive")
            ->status();

        $stillLive = $this->inTenant($reader->organization, fn () => $report->fresh()->archived_at);

        $this->assertNull($stillLive, 'it must not have been archived');
        $this->assertSame(404, $show, 'show must not reach another branch');
        $this->assertSame(404, $pdf, 'the PDF must not reach another branch');
        $this->assertSame(404, $archive, 'archive must not reach another branch');
    }

    // ── fixtures ─────────────────────────────────────────────────

    /**
     * A reader at one branch, and a report that belongs to another.
     *
     * `withJob` picks WHICH half of the reach rule is under test, and both are
     * needed. With a job, the report names its franchise outright — that half
     * always worked. Without one, the branch comes from the customer, and that
     * is the half `assertReach` skipped entirely. A fixture that only ever
     * built the first kind is how the second went unnoticed.
     *
     * @param  array<int, string>  $permissions
     * @return array{0: \App\Models\User, 1: InspectionReport}
     */
    private function readerAndForeignReport(
        array $permissions = ['inspection.view', 'inspection.new.view', 'inspection.reports.view', 'inspection.builder.view'],
        bool $withJob = true,
    ): array {
        $tenant = $this->provisionTenant('Alpha');
        $elsewhere = $this->extraFranchise($tenant, 'Second');
        $reader = $this->userWithRole($tenant, 'Tech', $permissions, onlyAt: $elsewhere);

        $report = $this->inTenant($tenant['org'], function () use ($tenant, $withJob) {
            $customer = $this->customer($tenant, $tenant['franchise']->id);

            $job = $withJob ? Job::create([
                'franchise_id' => $tenant['franchise']->id,
                'customer_id' => $customer->id,
                'number' => 'J-'.bin2hex(random_bytes(3)),
                'title' => 'Work',
                'status' => 'scheduled',
            ]) : null;

            return $this->report($tenant, $customer, $job);
        });

        return [$reader, $report];
    }

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
