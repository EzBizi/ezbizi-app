<?php

namespace Tests\Feature\Api;

use App\Models\Customer;
use App\Models\InspectionDefinition;
use App\Models\InspectionDefinitionVersion;
use App\Models\InspectionReport;
use App\Models\Job;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/**
 * The inspection reports bound to a job (spec §10), on the job's own payload.
 *
 * ── Gated by INSPECTION's grant, and there is no Jobs tab key ────
 *
 * Unlike Materials, which minted `jobs.materials.view`, this mints nothing.
 * A report is a document Inspection owns, behind `inspection.view` in
 * InspectionReportPolicy. A Jobs tab that surfaced reports to anybody holding
 * the tab would be a back door past that policy — Settings' rule, settled on
 * inbox 0051: a tab is an AND on top of the capability, never an alternative.
 *
 * And once the capability is required anyway, a separate tab key would only let
 * an administrator hide inspections-on-a-job from somebody who may read every
 * inspection elsewhere. Nobody makes that split, so the key would be a
 * checkbox that changes nothing anybody wants changed. The section appears for
 * exactly the people who can open what it lists — no dead links, no leak.
 *
 * Materials differ because what they surface — the item and quantity used on
 * the work — is modelled as a fact about the JOB, with Inventory's sensitive
 * half (cost) excluded by construction. A report is not a fact about the job;
 * it is somebody else's record that happens to point at it.
 */
class JobInspectionsTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    /** The positive control, first. */
    public function test_a_job_lists_the_inspection_reports_submitted_on_it(): void
    {
        $alpha = $this->provisionTenant('Alpha');
        [$job, $definition, $version] = $this->aJobWithADefinition($alpha, 'Level 2 chimney inspection');
        $this->reportOn($alpha, $job, $definition, $version, '2026-09-20 14:00:00');

        $reader = $this->userWithRole($alpha, 'Tech', ['jobs.view', 'inspection.view']);

        $inspections = $this->signedInAs($reader)
            ->getJson("/api/v1/jobs/{$job->id}")->assertOk()->json('data.inspections');

        $this->assertCount(1, $inspections);
        $this->assertSame('Level 2 chimney inspection', $inspections[0]['name']);
        $this->assertStringStartsWith('2026-09-20', $inspections[0]['submitted_at']);
    }

    /**
     * The back door this exists to keep shut.
     *
     * The subject holds `jobs.view` and can open the job — that is the control
     * below — but Inspection would refuse them the report. So the job must not
     * list it: not its name, not its date, not that it exists.
     */
    public function test_somebody_inspection_refuses_gets_no_inspections_on_the_job(): void
    {
        $alpha = $this->provisionTenant('Alpha');
        [$job, $definition, $version] = $this->aJobWithADefinition($alpha, 'Level 2 chimney inspection');
        $this->reportOn($alpha, $job, $definition, $version, '2026-09-20 14:00:00');

        $reader = $this->userWithRole($alpha, 'Dispatcher', ['jobs.view']);

        $data = $this->signedInAs($reader)
            ->getJson("/api/v1/jobs/{$job->id}")->assertOk()->json('data');

        $this->assertSame($job->id, $data['id'], 'The control: the job itself still opens.');
        $this->assertArrayNotHasKey('inspections', $data);
    }

    /**
     * Archived reports are left out, as Inspection's own list leaves them out.
     *
     * InspectionReportsController::index filters `whereNull('archived_at')`
     * unless asked. The job panel is not a second, differently-filtered view of
     * Inspection's data; it follows the owner's default.
     */
    public function test_an_archived_report_is_not_listed(): void
    {
        $alpha = $this->provisionTenant('Alpha');
        [$job, $definition, $version] = $this->aJobWithADefinition($alpha, 'Level 2 chimney inspection');
        $kept = $this->reportOn($alpha, $job, $definition, $version, '2026-09-20 14:00:00');
        $archived = $this->reportOn($alpha, $job, $definition, $version, '2026-09-21 09:00:00');
        $this->inTenant($alpha['org'], fn () => $archived->archive());

        $reader = $this->userWithRole($alpha, 'Tech', ['jobs.view', 'inspection.view']);

        $inspections = $this->signedInAs($reader)
            ->getJson("/api/v1/jobs/{$job->id}")->assertOk()->json('data.inspections');

        $this->assertSame([$kept->id], array_column($inspections, 'id'));
    }

    /**
     * The name is what the inspection was CALLED WHEN IT WAS SUBMITTED.
     *
     * The report snapshots `definition_name`; a join to the live definition
     * would rename every past report the moment somebody renamed the template.
     * Renaming here and asserting the old name is the only fixture that can
     * tell the snapshot from the join.
     */
    public function test_a_report_keeps_the_name_it_was_submitted_under(): void
    {
        $alpha = $this->provisionTenant('Alpha');
        [$job, $definition, $version] = $this->aJobWithADefinition($alpha, 'Level 2 chimney inspection');
        $this->reportOn($alpha, $job, $definition, $version, '2026-09-20 14:00:00');

        $this->inTenant($alpha['org'], fn () => $definition->update(['name' => 'Renamed later']));

        $reader = $this->userWithRole($alpha, 'Tech', ['jobs.view', 'inspection.view']);

        $inspections = $this->signedInAs($reader)
            ->getJson("/api/v1/jobs/{$job->id}")->assertOk()->json('data.inspections');

        $this->assertSame('Level 2 chimney inspection', $inspections[0]['name']);
    }

    /** @return array{0: Job, 1: InspectionDefinition, 2: InspectionDefinitionVersion} */
    private function aJobWithADefinition(array $tenant, string $name): array
    {
        return $this->inTenant($tenant['org'], function () use ($tenant, $name) {
            $customer = Customer::create([
                'owning_franchise_id' => $tenant['franchise']->id,
                'number' => 'C-'.bin2hex(random_bytes(3)),
                'name'   => '14 Maple',
            ]);

            $job = Job::create([
                'franchise_id' => $tenant['franchise']->id,
                'customer_id'  => $customer->id,
                'number'   => 'J-'.bin2hex(random_bytes(3)),
                'title'    => 'Reline',
                'status'   => 'scheduled',
                'currency' => 'USD',
            ]);

            $definition = InspectionDefinition::create([
                'name'         => $name,
                'created_by'   => $tenant['admin']->id,
                'published_at' => now(),
            ]);

            $version = InspectionDefinitionVersion::create([
                'inspection_definition_id' => $definition->id,
                'version'       => 1,
                'layout_preset' => 'step_strip',
                'screens'       => [],
                'published_by'  => $tenant['admin']->id,
            ]);

            $definition->update(['current_version_id' => $version->id]);

            return [$job, $definition->fresh(), $version];
        });
    }

    private function reportOn(
        array $tenant,
        Job $job,
        InspectionDefinition $definition,
        InspectionDefinitionVersion $version,
        string $submittedAt,
    ): InspectionReport {
        return $this->inTenant($tenant['org'], fn () => InspectionReport::create([
            'customer_id' => $job->customer_id,
            'job_id'      => $job->id,
            'inspection_definition_id'         => $definition->id,
            'inspection_definition_version_id' => $version->id,
            'submitted_by'    => $tenant['admin']->id,
            'submitted_at'    => $submittedAt,
            'answers'         => ['crown' => 'ok'],
            'layout_preset'   => 'step_strip',
            'definition_name' => $definition->name,
        ]));
    }
}
