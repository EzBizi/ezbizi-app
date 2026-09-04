<?php

namespace Tests\Feature\Inspection;

use App\Models\Customer;
use App\Models\InspectionDefinition;
use App\Models\InspectionDefinitionVersion;
use App\Models\InspectionReport;
use App\Models\InspectionRun;
use App\Models\Job;
use App\Support\Automations\CarriesAutomationScope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/**
 * Where an inspection event says it happened.
 *
 * ── The disclosure this closes ───────────────────────────────────
 *
 * `NotificationDispatcher::audienceFor` falls through to EVERY ACTIVE USER in
 * the organization when the record an event is about cannot say where it sits —
 * no `CarriesAutomationScope`, no `franchise_id`, no `owning_franchise_id`.
 * Neither a run nor a report has either column, so all four of this module's
 * triggers were in that fallback, and `notify` may be attached to any of them.
 *
 * ── Why the run triggers are the sharp ones ──────────────────────
 *
 * A run is PERSONAL. `InspectionRunPolicy` answers "no such run" to anybody but
 * the person who started it — not "not allowed", because a draft's existence is
 * itself private. So a notify on `inspection.started` reaching the whole
 * organization does not merely reach the wrong people: it tells them a named
 * technician started a named inspection for a named customer, which is exactly
 * the fact the policy refuses to a direct request.
 *
 * A report is less sharp — anybody with `inspection.view` may read one — but it
 * is still reachable only through its job's franchise, so the fallback was
 * wrong about it too.
 *
 * ── Every scope is resolved INSIDE the tenant ────────────────────
 *
 * `automationScope()` reads Job and Customer, which are tenant-scoped, and
 * `AutomationEngine::deliver` already runs inside `TenantContext::run`.
 * Asserting from outside would test a call the engine never makes — and the
 * tenant guard rightly refuses it.
 */
class InspectionAutomationScopeTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    public function test_both_records_can_say_where_they_sit(): void
    {
        $this->assertInstanceOf(CarriesAutomationScope::class, new InspectionRun);
        $this->assertInstanceOf(CarriesAutomationScope::class, new InspectionReport);
    }

    /** A job names a franchise outright, and is the stronger answer. */
    public function test_a_run_on_a_job_is_scoped_to_that_jobs_franchise(): void
    {
        $tenant = $this->provisionTenant('Alpha');

        [$scope, $franchiseId] = $this->inTenant($tenant['org'], function () use ($tenant) {
            $customer = $this->customer($tenant);
            $job = $this->job($tenant, $customer);

            return [
                $this->makeRun($tenant, $customer, $job)->automationScope(),
                $tenant['franchise']->id,
            ];
        });

        $this->assertSame(['franchise', $franchiseId], $scope);
    }

    /**
     * With no job, the customer's owning franchise answers.
     *
     * A customer-only inspection is a real case — the picker allows starting
     * one against a client with no job — and answering "no scope" would leave
     * it in the very fallback this exists to close.
     */
    public function test_a_customer_only_run_is_scoped_to_the_customers_franchise(): void
    {
        $tenant = $this->provisionTenant('Alpha');

        [$scope, $franchiseId] = $this->inTenant($tenant['org'], function () use ($tenant) {
            return [
                $this->makeRun($tenant, $this->customer($tenant), null)->automationScope(),
                $tenant['franchise']->id,
            ];
        });

        $this->assertSame(['franchise', $franchiseId], $scope);
    }

    public function test_a_report_is_scoped_the_same_way(): void
    {
        $tenant = $this->provisionTenant('Alpha');

        [$scope, $franchiseId] = $this->inTenant($tenant['org'], function () use ($tenant) {
            $customer = $this->customer($tenant);

            return [
                $this->report($tenant, $customer, $this->job($tenant, $customer))->automationScope(),
                $tenant['franchise']->id,
            ];
        });

        $this->assertSame(['franchise', $franchiseId], $scope);
    }

    /**
     * A soft-deleted job still answers, and answers with the JOB's franchise.
     *
     * The two franchises are deliberately different, and that is the whole
     * test. A customer owned by one branch can have work done by another — the
     * report belongs where the work happened. With them equal, dropping
     * `withTrashed()` from the job lookup changes nothing: the customer
     * fallback returns the same id and the test passes against the bug. Proven
     * by removing it and watching this stay green, which is why it now does
     * not.
     *
     * A job soft-deletes and a report outlives it, so this is the ordinary
     * case rather than an exotic one.
     */
    public function test_a_report_whose_job_was_deleted_still_reports_the_jobs_franchise(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $elsewhere = $this->extraFranchise($tenant, 'Second');

        [$scope, $jobFranchiseId, $customerFranchiseId] = $this->inTenant(
            $tenant['org'],
            function () use ($tenant, $elsewhere) {
                // Customer belongs to the first franchise; the work was done by
                // the second.
                $customer = $this->customer($tenant);

                $job = Job::create([
                    'franchise_id' => $elsewhere->id,
                    'customer_id' => $customer->id,
                    'number' => 'J-'.bin2hex(random_bytes(3)),
                    'title' => 'Work',
                    'status' => 'scheduled',
                ]);

                $report = $this->report($tenant, $customer, $job);
                $job->delete();

                return [
                    $report->fresh()->automationScope(),
                    $elsewhere->id,
                    $tenant['franchise']->id,
                ];
            },
        );

        $this->assertSame(['franchise', $jobFranchiseId], $scope);
        $this->assertNotSame(
            ['franchise', $customerFranchiseId],
            $scope,
            'falling through to the customer would name the wrong branch',
        );
    }

    /**
     * A soft-deleted CUSTOMER still answers, the same as a deleted job.
     *
     * The `[null, null]` branch in `automationScope` is deliberately
     * unreachable from here: `inspection_reports.customer_id` is NOT NULL, so
     * every report has a customer, and §9 anonymises a customer in place rather
     * than removing the row. The guard stays because a nullable column or a
     * future hard delete would otherwise fall through to the organization-wide
     * audience silently — but what is worth PINNING is the case that can
     * actually happen, which is a customer in the recycle bin.
     */
    public function test_a_report_whose_customer_was_deleted_still_knows_its_franchise(): void
    {
        $tenant = $this->provisionTenant('Alpha');

        [$scope, $franchiseId] = $this->inTenant($tenant['org'], function () use ($tenant) {
            $customer = $this->customer($tenant);
            $report = $this->report($tenant, $customer, null);

            $customer->delete();

            return [$report->fresh()->automationScope(), $tenant['franchise']->id];
        });

        $this->assertSame(['franchise', $franchiseId], $scope);
    }

    // ── fixtures ─────────────────────────────────────────────────

    private function customer(array $tenant): Customer
    {
        return Customer::create([
            'owning_franchise_id' => $tenant['franchise']->id,
            'number' => 'C-'.bin2hex(random_bytes(3)),
            'name' => '14 Maple',
        ]);
    }

    private function job(array $tenant, Customer $customer): Job
    {
        return Job::create([
            'franchise_id' => $tenant['franchise']->id,
            'customer_id' => $customer->id,
            'number' => 'J-'.bin2hex(random_bytes(3)),
            'title' => 'Work',
            'status' => 'scheduled',
        ]);
    }

    private function definition(array $tenant): InspectionDefinition
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

        $definition->update(['current_version_id' => $version->id]);

        return $definition->fresh();
    }

    private function report(array $tenant, Customer $customer, ?Job $job): InspectionReport
    {
        $definition = $this->definition($tenant);

        return InspectionReport::create([
            'customer_id' => $customer->id,
            'job_id' => $job?->id,
            'inspection_definition_id' => $definition->id,
            'inspection_definition_version_id' => $definition->current_version_id,
            'submitted_by' => $tenant['admin']->id,
            'submitted_at' => now(),
            'answers' => [],
            'layout_preset' => 'step_strip',
            'definition_name' => 'Level 1',
        ]);
    }

    private function makeRun(array $tenant, Customer $customer, ?Job $job): InspectionRun
    {
        $definition = $this->definition($tenant);

        return InspectionRun::create([
            'user_id' => $tenant['admin']->id,
            'customer_id' => $customer->id,
            'job_id' => $job?->id,
            'inspection_definition_id' => $definition->id,
            'inspection_definition_version_id' => $definition->current_version_id,
            'scope_key' => $customer->id.':'.($job?->id ?? ''),
            'answers' => [],
        ]);
    }
}
