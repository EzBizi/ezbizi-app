<?php

namespace Tests\Feature\Inspection;

use App\Models\Customer;
use App\Models\InspectionDefinition;
use App\Models\InspectionDefinitionVersion;
use App\Models\InspectionReport;
use App\Support\Database\WrittenOnceRefusal;
use App\Support\Privacy\Anonymiser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

class InspectionWriteOnceTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    public function test_a_published_version_cannot_be_rewritten_through_the_query_builder(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $this->seedPublished($tenant);

        $this->expectException(WrittenOnceRefusal::class);
        InspectionDefinitionVersion::query()->update(['screens' => []]);
    }

    public function test_a_report_refuses_an_edit_to_its_answers(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $report = $this->seedPublished($tenant)['report'];

        $this->expectException(WrittenOnceRefusal::class);
        $this->inTenant($tenant['org'], fn () => $report->update(['answers' => ['tampered' => true]]));
    }

    public function test_a_report_may_be_archived(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $report = $this->seedPublished($tenant)['report'];

        $this->inTenant($tenant['org'], fn () => $report->archive());

        $this->assertNotNull($report->fresh()->archived_at);
    }

    public function test_erasure_can_blank_a_report_that_archive_alone_could_not(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $seeded = $this->seedPublished($tenant);
        $customer = $seeded['customer'];
        $report = $seeded['report'];

        $this->assertSame('Marta Ruiz', $report->answers['customer_name']);

        app(Anonymiser::class)->purge($customer);

        $fresh = $this->inTenant($tenant['org'], fn () => $report->fresh());

        $this->assertNotNull($fresh, 'The report was deleted rather than anonymised.');
        $this->assertSame([], $fresh->answers);
        $this->assertSame('Level 1', $fresh->definition_name);
    }

    /**
     * @param  array{org: \App\Models\Organization, admin: \App\Models\User, franchise: \App\Models\Franchise}  $tenant
     * @return array{customer: Customer, report: InspectionReport}
     */
    private function seedPublished(array $tenant): array
    {
        return $this->inTenant($tenant['org'], function () use ($tenant) {
            $customer = Customer::create([
                'owning_franchise_id' => $tenant['franchise']->id,
                'number' => 'C-'.bin2hex(random_bytes(3)),
                'name' => 'Marta Ruiz',
            ]);

            $definition = InspectionDefinition::create([
                'name' => 'Level 1',
                'created_by' => $tenant['admin']->id,
            ]);

            $version = InspectionDefinitionVersion::create([
                'inspection_definition_id' => $definition->id,
                'version' => 1,
                'layout_preset' => 'step_strip',
                'screens' => [],
                'published_by' => $tenant['admin']->id,
            ]);

            $report = InspectionReport::create([
                'customer_id' => $customer->id,
                'inspection_definition_id' => $definition->id,
                'inspection_definition_version_id' => $version->id,
                'submitted_by' => $tenant['admin']->id,
                'submitted_at' => now(),
                'answers' => ['customer_name' => 'Marta Ruiz'],
                'layout_preset' => 'step_strip',
                'definition_name' => 'Level 1',
            ]);

            return ['customer' => $customer, 'report' => $report];
        });
    }
}
