<?php

namespace Tests\Feature\Api;

use App\Models\Customer;
use App\Models\InspectionDefinition;
use App\Models\InspectionDefinitionGrant;
use App\Models\InspectionDefinitionVersion;
use App\Models\Job;
use App\Models\JobLine;
use App\Models\Service;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/**
 * Recommended work on a finding, and the quote it becomes.
 *
 * A1's tool attaches priced items to a failed finding and pushes them to Workiz
 * as an estimate. EzBizi already owns the catalogue and the job, so the same
 * idea lands on `job_lines` — which already snapshots name and price for
 * exactly the reason an inspection report freezes its answers.
 */
class InspectionServicesTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    /**
     * The picks become job lines, and the job total reflects them.
     *
     * The whole point: a technician who fails a finding and recommends a liner
     * has produced a quote by the time they press Submit, without anybody
     * retyping it.
     */
    public function test_recommended_work_becomes_lines_on_the_job(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $tech = $this->tech($tenant);
        $seeded = $this->seedWithService($tenant, $tech);

        $runId = $this->signedInAs($tech)->postJson('/api/v1/inspections/runs', [
            'customer_id' => $seeded['customer']->id,
            'job_id' => $seeded['job']->id,
            'inspection_definition_id' => $seeded['definition']->id,
        ])->assertCreated()->json('data.id');

        $this->signedInAs($tech)->patchJson("/api/v1/inspections/runs/{$runId}", [
            'answers' => [
                'liner' => 'not-ok',
                'liner_work' => [
                    ['service_id' => $seeded['service']->id, 'qty' => 2],
                ],
            ],
        ])->assertOk();

        $this->signedInAs($tech)
            ->postJson("/api/v1/inspections/runs/{$runId}/submit")
            ->assertOk();

        $this->inTenant($tenant['org'], function () use ($seeded) {
            $lines = JobLine::query()->where('job_id', $seeded['job']->id)->get();

            $this->assertCount(1, $lines);
            $this->assertSame('Chimney liner', $lines[0]->name);
            $this->assertSame(319900, $lines[0]->unit_price_minor);
            $this->assertSame(2, $lines[0]->qty);
            $this->assertSame(639800, $lines[0]->amount_minor);

            // The job total is derived from its lines, so it follows.
            $this->assertSame(639800, $seeded['job']->fresh()->amount_minor);
        });
    }

    /**
     * The price is SNAPSHOT, not looked up later.
     *
     * A price raised in September must not rewrite what an August inspection
     * recommended — the same rule JobLine already states for itself, and the
     * same rule the report follows for its answers.
     */
    public function test_the_price_is_frozen_at_submit(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $tech = $this->tech($tenant);
        $seeded = $this->seedWithService($tenant, $tech);

        $runId = $this->signedInAs($tech)->postJson('/api/v1/inspections/runs', [
            'customer_id' => $seeded['customer']->id,
            'job_id' => $seeded['job']->id,
            'inspection_definition_id' => $seeded['definition']->id,
        ])->assertCreated()->json('data.id');

        $this->signedInAs($tech)->patchJson("/api/v1/inspections/runs/{$runId}", [
            'answers' => [
                'liner' => 'not-ok',
                'liner_work' => [['service_id' => $seeded['service']->id, 'qty' => 1]],
            ],
        ])->assertOk();

        $this->signedInAs($tech)->postJson("/api/v1/inspections/runs/{$runId}/submit")->assertOk();

        $this->inTenant($tenant['org'], fn () => $seeded['service']->update(['price_minor' => 999900]));

        $this->inTenant($tenant['org'], function () use ($seeded) {
            $line = JobLine::query()->where('job_id', $seeded['job']->id)->firstOrFail();

            $this->assertSame(319900, $line->unit_price_minor,
                'the recommendation was made at the price of the day');
        });
    }

    /**
     * A customer-only inspection has no job, and must not fail because of it.
     *
     * The recommendation is still on the report — it is what the technician
     * found — and there is simply nowhere to quote it yet.
     */
    public function test_a_customer_only_inspection_submits_without_a_job(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $tech = $this->tech($tenant);
        $seeded = $this->seedWithService($tenant, $tech, withJob: false);

        $runId = $this->signedInAs($tech)->postJson('/api/v1/inspections/runs', [
            'customer_id' => $seeded['customer']->id,
            'inspection_definition_id' => $seeded['definition']->id,
        ])->assertCreated()->json('data.id');

        $this->signedInAs($tech)->patchJson("/api/v1/inspections/runs/{$runId}", [
            'answers' => [
                'liner' => 'not-ok',
                'liner_work' => [['service_id' => $seeded['service']->id, 'qty' => 1]],
            ],
        ])->assertOk();

        $reportId = $this->signedInAs($tech)
            ->postJson("/api/v1/inspections/runs/{$runId}/submit")
            ->assertOk()->json('data.id');

        $this->assertNotNull($reportId);

        $this->inTenant($tenant['org'], fn () => $this->assertSame(0, JobLine::query()->count()));
    }

    /**
     * A service from another organization is refused, not quietly skipped.
     *
     * It would be a price from somebody else's catalogue on this customer's
     * quote.
     */
    public function test_a_service_from_another_organization_is_refused(): void
    {
        $alpha = $this->provisionTenant('Alpha');
        $beta = $this->provisionTenant('Beta');
        $tech = $this->tech($alpha);
        $seeded = $this->seedWithService($alpha, $tech);

        $theirs = $this->inTenant($beta['org'], fn () => Service::create([
            'number' => 'S-'.bin2hex(random_bytes(3)),
            'name' => 'Theirs', 'price_minor' => 100,
        ]));

        $runId = $this->signedInAs($tech)->postJson('/api/v1/inspections/runs', [
            'customer_id' => $seeded['customer']->id,
            'job_id' => $seeded['job']->id,
            'inspection_definition_id' => $seeded['definition']->id,
        ])->assertCreated()->json('data.id');

        $this->signedInAs($tech)->patchJson("/api/v1/inspections/runs/{$runId}", [
            'answers' => [
                'liner' => 'not-ok',
                'liner_work' => [['service_id' => $theirs->id, 'qty' => 1]],
            ],
        ])->assertStatus(422);
    }

    /**
     * The report prices the work it recommends, and totals it.
     *
     * From the SNAPSHOT in the answer, never a fresh catalogue read — the
     * customer was quoted a number on the day and the report has to still say
     * that number.
     */
    public function test_the_report_prices_the_recommended_work(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $tech = $this->tech($tenant);
        $seeded = $this->seedWithService($tenant, $tech);

        $runId = $this->signedInAs($tech)->postJson('/api/v1/inspections/runs', [
            'customer_id' => $seeded['customer']->id,
            'job_id' => $seeded['job']->id,
            'inspection_definition_id' => $seeded['definition']->id,
        ])->assertCreated()->json('data.id');

        $this->signedInAs($tech)->patchJson("/api/v1/inspections/runs/{$runId}", [
            'answers' => [
                'liner' => 'not-ok',
                'liner_work' => [['service_id' => $seeded['service']->id, 'qty' => 2]],
            ],
        ])->assertOk();

        $reportId = $this->signedInAs($tech)
            ->postJson("/api/v1/inspections/runs/{$runId}/submit")
            ->assertOk()->json('data.id');

        $report = $this->inTenant(
            $tenant['org'],
            fn () => \App\Models\InspectionReport::findOrFail($reportId),
        );

        $html = $this->inTenant(
            $tenant['org'],
            fn () => app(\App\Support\Inspection\ReportDocument::class)->html($report),
        );

        $this->assertStringContainsString('Chimney liner', $html);
        $this->assertStringContainsString('6,398.00', $html, 'two at 3,199.00');
        $this->assertStringContainsString('Total', $html);

        // And it converts.
        $this->signedInAs($tech)
            ->get("/api/v1/inspections/reports/{$reportId}/pdf")
            ->assertOk();
    }

    /** @return array<string, mixed> */
    private function seedWithService(array $tenant, $tech, bool $withJob = true): array
    {
        return $this->inTenant($tenant['org'], function () use ($tenant, $tech, $withJob) {
            $customer = Customer::create([
                'owning_franchise_id' => $tenant['franchise']->id,
                'number' => 'C-'.bin2hex(random_bytes(3)),
                'name' => '14 Maple',
            ]);

            $job = $withJob ? Job::create([
                'franchise_id' => $tenant['franchise']->id,
                'customer_id' => $customer->id,
                'number' => 'J-'.bin2hex(random_bytes(3)),
                'title' => 'Work',
                'status' => 'scheduled',
            ]) : null;

            $service = Service::create([
                'number' => 'S-'.bin2hex(random_bytes(3)),
                'name' => 'Chimney liner',
                'price_minor' => 319900,
            ]);

            $screens = [[
                'name' => 'Liner',
                'fields' => [
                    ['key' => 'liner', 'label' => 'Chimney liner', 'type' => 'choice',
                     'options' => [
                         ['value' => 'ok', 'label' => 'Meets standards', 'variant' => 'pass'],
                         ['value' => 'not-ok', 'label' => 'Does not meet standards',
                          'variant' => 'fail'],
                     ]],
                    ['key' => 'liner_work', 'label' => 'Recommended work', 'type' => 'services'],
                ],
            ]];

            $definition = InspectionDefinition::create([
                'name' => 'Level 1',
                'created_by' => $tenant['admin']->id,
                'published_at' => now(),
                'screens' => $screens,
            ]);

            $version = InspectionDefinitionVersion::create([
                'inspection_definition_id' => $definition->id,
                'version' => 1,
                'layout_preset' => 'step_strip',
                'screens' => $screens,
                'published_by' => $tenant['admin']->id,
            ]);

            $definition->update(['current_version_id' => $version->id]);

            InspectionDefinitionGrant::create([
                'inspection_definition_id' => $definition->id,
                'role_id' => $tech->role_id,
            ]);

            return [
                'customer' => $customer,
                'job' => $job,
                'service' => $service,
                'definition' => $definition->fresh(),
                'version' => $version,
            ];
        });
    }

    private function tech(array $tenant, string $name = 'Rodrigo')
    {
        return $this->userWithRole($tenant, $name, [
            'inspection.view', 'inspection.create', 'jobs.view', 'jobs.edit', 'services.view', 'inspection.new.view', 'inspection.reports.view', 'inspection.builder.view'], onlyAt: $tenant['franchise']);
    }
}
