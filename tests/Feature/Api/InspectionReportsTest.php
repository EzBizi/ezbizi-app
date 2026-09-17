<?php

namespace Tests\Feature\Api;

use App\Models\AutomationEvent;
use App\Models\Customer;
use App\Models\InspectionDefinition;
use App\Models\InspectionDefinitionVersion;
use App\Models\InspectionReport;
use App\Models\Job;
use App\Support\Database\WrittenOnceRefusal;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/**
 * Generated Reports: frozen submitted work, search, and an Archived filter.
 *
 * The version pin is the promise: changing Builder must not restyle a report
 * that already exists. Soft-deleted customers still list and still open.
 */
class InspectionReportsTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    public function test_archive_hides_from_the_default_list_and_the_filter_returns_it(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $viewer = $this->viewer($tenant);
        $seeded = $this->seedReport($tenant, customerName: 'Marta Ruiz');

        $this->signedInAs($viewer)
            ->getJson('/api/v1/inspections/reports?search=Marta')
            ->assertOk()
            ->assertJsonPath('data.0.id', $seeded['report']->id);

        $this->signedInAs($viewer)
            ->postJson("/api/v1/inspections/reports/{$seeded['report']->id}/archive")
            ->assertOk()
            ->assertJsonPath('data.archived', true);

        $this->signedInAs($viewer)
            ->getJson('/api/v1/inspections/reports?search=Marta')
            ->assertOk()
            ->assertJsonPath('data', []);

        $this->signedInAs($viewer)
            ->getJson('/api/v1/inspections/reports?search=Marta&archived=1')
            ->assertOk()
            ->assertJsonPath('data.0.id', $seeded['report']->id);

        $this->inTenant($tenant['org'], function () {
            $this->assertNotNull(
                AutomationEvent::query()->where('event_key', 'inspection.archived')->first(),
            );
        });
    }

    public function test_updating_answers_after_submit_is_refused(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $viewer = $this->viewer($tenant);
        $seeded = $this->seedReport($tenant);

        $this->signedInAs($viewer)
            ->patchJson("/api/v1/inspections/reports/{$seeded['report']->id}", [
                'answers' => ['tampered' => true],
            ])
            ->assertStatus(422);

        $this->inTenant($tenant['org'], function () use ($seeded) {
            $this->expectException(WrittenOnceRefusal::class);
            $seeded['report']->update(['answers' => ['tampered' => true]]);
        });
    }

    public function test_bound_scheduled_for_on_the_report_is_already_rendered_text(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $viewer = $this->viewer($tenant);
        $seeded = $this->seedReport($tenant, answers: [
            'scheduled_for' => 'Tue 18 Aug, 9:00 AM',
        ]);

        $body = $this->signedInAs($viewer)
            ->getJson("/api/v1/inspections/reports/{$seeded['report']->id}")
            ->assertOk()
            ->json('data');

        $this->assertIsString($body['answers']['scheduled_for']);
        $this->assertSame('Tue 18 Aug, 9:00 AM', $body['answers']['scheduled_for']);
        $this->assertFalse(
            is_numeric($body['answers']['scheduled_for']),
            'A bound scheduled_for must already be text, not an instant that would re-render.',
        );
    }

    public function test_a_report_keeps_the_preset_it_was_submitted_with_after_a_later_publish(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $editor = $this->userWithRole($tenant, 'Designer', [
            'inspection.view', 'inspection.edit', 'inspection.new.view', 'inspection.reports.view', 'inspection.builder.view']);
        $viewer = $this->viewer($tenant);

        $seeded = $this->seedReport($tenant, preset: 'step_strip');

        $this->inTenant($tenant['org'], function () use ($seeded, $tenant) {
            InspectionDefinitionVersion::create([
                'inspection_definition_id' => $seeded['definition']->id,
                'version' => 2,
                'layout_preset' => 'document',
                'screens' => [],
                'published_by' => $tenant['admin']->id,
            ]);
            $seeded['definition']->update([
                'current_version_id' => InspectionDefinitionVersion::query()
                    ->where('version', 2)
                    ->value('id'),
            ]);
        });

        $this->signedInAs($editor)
            ->getJson("/api/v1/inspections/{$seeded['definition']->id}")
            ->assertOk();

        $this->signedInAs($viewer)
            ->getJson("/api/v1/inspections/reports/{$seeded['report']->id}")
            ->assertOk()
            ->assertJsonPath('data.layout_preset', 'step_strip')
            ->assertJsonPath('data.definition_name', 'Level 1');
    }

    public function test_a_report_whose_customer_was_soft_deleted_still_lists_and_opens(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $viewer = $this->viewer($tenant);
        $seeded = $this->seedReport($tenant, customerName: 'Marta Ruiz');

        $this->inTenant($tenant['org'], fn () => $seeded['customer']->delete());

        $this->signedInAs($viewer)
            ->getJson('/api/v1/inspections/reports?search=Marta')
            ->assertOk()
            ->assertJsonPath('data.0.id', $seeded['report']->id)
            ->assertJsonPath('data.0.customer_name', 'Marta Ruiz');

        $this->signedInAs($viewer)
            ->getJson("/api/v1/inspections/reports/{$seeded['report']->id}")
            ->assertOk()
            ->assertJsonPath('data.id', $seeded['report']->id)
            ->assertJsonPath('data.customer_name', 'Marta Ruiz');
    }

    public function test_a_job_shaped_miss_says_job_not_found(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $viewer = $this->viewer($tenant);

        $this->signedInAs($viewer)
            ->getJson('/api/v1/inspections/reports?search=J-1042')
            ->assertOk()
            ->assertJsonPath('message', 'Job not found')
            ->assertJsonPath('data', []);
    }

    public function test_a_name_shaped_miss_says_client_not_found(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $viewer = $this->viewer($tenant);

        $this->signedInAs($viewer)
            ->getJson('/api/v1/inspections/reports?search=Marta')
            ->assertOk()
            ->assertJsonPath('message', 'Client not found')
            ->assertJsonPath('data', []);
    }

    public function test_search_by_job_number_finds_the_report(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $viewer = $this->viewer($tenant);
        $seeded = $this->seedReport($tenant, jobNumber: 'J-1042');

        $this->signedInAs($viewer)
            ->getJson('/api/v1/inspections/reports?search=J-1042')
            ->assertOk()
            ->assertJsonPath('data.0.id', $seeded['report']->id)
            ->assertJsonPath('data.0.job_number', 'J-1042');
    }

    private function viewer(array $tenant)
    {
        return $this->userWithRole($tenant, 'Reader', [
            'inspection.view', 'inspection.archive', 'inspection.new.view', 'inspection.reports.view', 'inspection.builder.view']);
    }

    /**
     * @return array{
     *     customer: Customer,
     *     definition: InspectionDefinition,
     *     report: InspectionReport
     * }
     */
    private function seedReport(
        array $tenant,
        string $customerName = '14 Maple',
        string $preset = 'step_strip',
        array $answers = ['crown' => 'ok'],
        ?string $jobNumber = null,
    ): array {
        return $this->inTenant($tenant['org'], function () use (
            $tenant, $customerName, $preset, $answers, $jobNumber,
        ) {
            $customer = Customer::create([
                'owning_franchise_id' => $tenant['franchise']->id,
                'number' => 'C-'.bin2hex(random_bytes(3)),
                'name' => $customerName,
            ]);

            $job = null;
            if ($jobNumber !== null) {
                $job = Job::create([
                    'franchise_id' => $tenant['franchise']->id,
                    'customer_id' => $customer->id,
                    'number' => $jobNumber,
                    'title' => 'Work',
                    'status' => 'scheduled',
                ]);
            }

            $definition = InspectionDefinition::create([
                'name' => 'Level 1',
                'created_by' => $tenant['admin']->id,
                'published_at' => now(),
            ]);

            $version = InspectionDefinitionVersion::create([
                'inspection_definition_id' => $definition->id,
                'version' => 1,
                'layout_preset' => $preset,
                'screens' => [],
                'published_by' => $tenant['admin']->id,
            ]);

            $definition->update(['current_version_id' => $version->id]);

            $report = InspectionReport::create([
                'customer_id' => $customer->id,
                'job_id' => $job?->id,
                'inspection_definition_id' => $definition->id,
                'inspection_definition_version_id' => $version->id,
                'submitted_by' => $tenant['admin']->id,
                'submitted_at' => now(),
                'answers' => $answers,
                'layout_preset' => $preset,
                'definition_name' => 'Level 1',
            ]);

            return [
                'customer' => $customer,
                'definition' => $definition->fresh(),
                'report' => $report,
            ];
        });
    }
}
