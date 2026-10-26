<?php

namespace Tests\Feature\Api;

use App\Models\AutomationEvent;
use App\Models\Customer;
use App\Models\InspectionDefinition;
use App\Models\InspectionDefinitionGrant;
use App\Models\InspectionDefinitionVersion;
use App\Models\InspectionReport;
use App\Models\InspectionRun;
use App\Models\Job;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

class InspectionRunsTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    public function test_a_job_shaped_miss_says_job_not_found(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $tech = $this->tech($tenant);

        $this->signedInAs($tech)
            ->getJson('/api/v1/inspections/search?q=J-1042')
            ->assertOk()
            ->assertJsonPath('message', 'Job not found')
            ->assertJsonPath('data', []);
    }

    public function test_a_name_shaped_miss_says_client_not_found(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $tech = $this->tech($tenant);

        $this->signedInAs($tech)
            ->getJson('/api/v1/inspections/search?q=Marta')
            ->assertOk()
            ->assertJsonPath('message', 'Client not found');
    }

    /**
     * A phone typed any way finds its client on digits, and each suggestion
     * carries the one-line address and the branch for "Company · Branch".
     * A name that shares no digits is not matched by the phone path.
     */
    public function test_search_finds_a_client_by_phone_and_says_where_they_are(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $tech = $this->tech($tenant);

        $this->inTenant($tenant['org'], function () use ($tenant) {
            \App\Models\Customer::create(['owning_franchise_id' => $tenant['franchise']->id, 'number' => 'C-1',
                'name' => 'Rosa Diaz', 'phone' => '+13057242199', 'address_line1' => '14 Maple St',
                'city' => 'Miami', 'state' => 'FL', 'postal_code' => '33180']);
            \App\Models\Customer::create(['owning_franchise_id' => $tenant['franchise']->id, 'number' => 'C-2',
                'name' => 'Other Person', 'phone' => '+12125550000']);
        });

        $data = $this->signedInAs($tech)
            ->getJson('/api/v1/inspections/search?q='.urlencode('(305) 724-2199'))
            ->assertOk()
            ->json('data');

        $this->assertCount(1, $data);
        $this->assertSame('Rosa Diaz', $data[0]['customer_name']);
        $this->assertSame('14 Maple St, Miami, FL 33180', $data[0]['address']);
        $this->assertSame(['id' => $tenant['franchise']->id, 'name' => $tenant['franchise']->name], $data[0]['franchise']);

        $byName = $this->signedInAs($tech)->getJson('/api/v1/inspections/search?q=Rosa')->json('data');
        $this->assertSame('14 Maple St, Miami, FL 33180', $byName[0]['address'] ?? null);
    }

    /**
     * The Builder's Testing tab picks a test client through search, so a
     * Builder-only role may search; it still may not start a real run.
     */
    public function test_a_builder_only_role_may_search_but_not_start(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $builder = $this->userWithRole($tenant, 'Builder', ['inspection.view', 'inspection.builder.view']);
        $nobody = $this->userWithRole($tenant, 'Viewer', ['inspection.view']);

        $this->signedInAs($builder)->getJson('/api/v1/inspections/search?q=Marta')->assertOk();
        $this->signedInAs($builder)->postJson('/api/v1/inspections/runs', [])->assertForbidden();
        $this->signedInAs($nobody)->getJson('/api/v1/inspections/search?q=Marta')->assertForbidden();
    }

    public function test_a_second_start_offers_resume_instead_of_a_unique_index_error(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $tech = $this->tech($tenant);
        $seeded = $this->seedOffered($tenant, $tech);

        $first = $this->signedInAs($tech)->postJson('/api/v1/inspections/runs', [
            'customer_id' => $seeded['customer']->id,
            'inspection_definition_id' => $seeded['definition']->id,
        ])->assertCreated();

        $this->assertSame([], $first->json('data.answers'));

        $again = $this->signedInAs($tech)->postJson('/api/v1/inspections/runs', [
            'customer_id' => $seeded['customer']->id,
            'inspection_definition_id' => $seeded['definition']->id,
        ]);

        $again->assertStatus(409)
            ->assertJsonPath('data.choice', 'resume')
            ->assertJsonPath('data.run.id', $first->json('data.id'));

        $this->inTenant($tenant['org'], fn () => $this->assertSame(1, InspectionRun::count()));
    }

    public function test_carlos_starting_the_same_job_gets_his_own_run(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $rodrigo = $this->tech($tenant, 'Rodrigo');
        $carlos = $this->tech($tenant, 'Carlos');
        $seeded = $this->seedOffered($tenant, $rodrigo, jobNumber: 'J-1042');
        $this->grant($tenant, $seeded['definition'], $carlos->role_id);

        $a = $this->signedInAs($rodrigo)->postJson('/api/v1/inspections/runs', [
            'customer_id' => $seeded['customer']->id,
            'job_id' => $seeded['job']->id,
            'inspection_definition_id' => $seeded['definition']->id,
        ])->assertCreated()->json('data.id');

        $b = $this->signedInAs($carlos)->postJson('/api/v1/inspections/runs', [
            'customer_id' => $seeded['customer']->id,
            'job_id' => $seeded['job']->id,
            'inspection_definition_id' => $seeded['definition']->id,
        ])->assertCreated()->json('data.id');

        $this->assertNotSame($a, $b);
        $this->signedInAs($carlos)->getJson("/api/v1/inspections/runs/{$a}")->assertNotFound();
    }

    public function test_submit_after_unpublish_still_works_and_start_again_is_refused(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $tech = $this->tech($tenant);
        $seeded = $this->seedOffered($tenant, $tech, screens: [[
            'name' => 'Roof',
            'fields' => [[
                'key' => 'crown',
                'label' => 'Crown',
                'type' => 'text',
                'required' => true,
            ]],
        ]]);

        $runId = $this->signedInAs($tech)->postJson('/api/v1/inspections/runs', [
            'customer_id' => $seeded['customer']->id,
            'inspection_definition_id' => $seeded['definition']->id,
        ])->assertCreated()->json('data.id');

        $this->signedInAs($tech)->patchJson("/api/v1/inspections/runs/{$runId}", [
            'answers' => ['crown' => 'Sound'],
        ])->assertOk();

        $this->inTenant($tenant['org'], fn () => $seeded['definition']->update(['published_at' => null]));

        $this->signedInAs($tech)
            ->postJson("/api/v1/inspections/runs/{$runId}/submit")
            ->assertOk()
            ->assertJsonPath('data.definition_name', 'Level 1');

        $this->inTenant($tenant['org'], function () {
            $this->assertSame(0, InspectionRun::count());
            $this->assertSame(1, InspectionReport::count());
            $this->assertNotNull(
                AutomationEvent::query()->where('event_key', 'inspection.submitted')->first(),
            );
        });

        $this->signedInAs($tech)->postJson('/api/v1/inspections/runs', [
            'customer_id' => $seeded['customer']->id,
            'inspection_definition_id' => $seeded['definition']->id,
        ])->assertStatus(422);
    }

    public function test_submit_refuses_an_empty_required_field_by_name(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $tech = $this->tech($tenant);
        $seeded = $this->seedOffered($tenant, $tech, screens: [[
            'name' => 'Roof',
            'fields' => [[
                'key' => 'crown',
                'label' => 'Crown',
                'type' => 'text',
                'required' => true,
            ]],
        ]]);

        $runId = $this->signedInAs($tech)->postJson('/api/v1/inspections/runs', [
            'customer_id' => $seeded['customer']->id,
            'inspection_definition_id' => $seeded['definition']->id,
        ])->assertCreated()->json('data.id');

        $response = $this->signedInAs($tech)
            ->postJson("/api/v1/inspections/runs/{$runId}/submit")
            ->assertStatus(422);

        $flat = strtolower($response->json('message').json_encode($response->json('errors')));
        $this->assertStringContainsString('crown', $flat);
    }

    public function test_bound_scheduled_for_freezes_as_text_on_the_report(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $tech = $this->tech($tenant);
        $seeded = $this->seedOffered($tenant, $tech, jobNumber: 'J-1042', screens: [[
            'name' => 'When',
            'fields' => [[
                'key' => 'when',
                'label' => 'When',
                'type' => 'text',
                'bind' => 'job.scheduled_for',
            ]],
        ]]);

        $this->inTenant($tenant['org'], function () use ($seeded) {
            $seeded['job']->update(['scheduled_for' => '2026-08-18 09:00:00']);
        });

        $runId = $this->signedInAs($tech)->postJson('/api/v1/inspections/runs', [
            'customer_id' => $seeded['customer']->id,
            'job_id' => $seeded['job']->id,
            'inspection_definition_id' => $seeded['definition']->id,
        ])->assertCreated()->json('data.id');

        $report = $this->signedInAs($tech)
            ->postJson("/api/v1/inspections/runs/{$runId}/submit")
            ->assertOk()
            ->json('data');

        $this->assertIsString($report['answers']['when']);
        $this->assertNotSame('', $report['answers']['when']);
        $this->assertFalse(is_numeric($report['answers']['when']));
    }

    /**
     * Starting a run fills in what the client and job already know.
     *
     * A DATE field gets the job's day as `YYYY-MM-DD`: the date box reads
     * nothing else, so the old "Tue 18 Aug, 9:00 AM" arrived and showed an
     * empty box (Jose, 2026-10-06). Contact and address fields get the client's.
     */
    public function test_starting_a_run_prefills_the_client_and_the_job_day(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $tech = $this->tech($tenant);
        $seeded = $this->seedOffered($tenant, $tech, jobNumber: 'J-1043', screens: [[
            'name' => 'Client',
            'fields' => [
                ['key' => 'email', 'label' => 'Email', 'type' => 'email', 'bind' => 'customer.email'],
                ['key' => 'city', 'label' => 'City', 'type' => 'text', 'bind' => 'customer.city'],
                ['key' => 'zip', 'label' => 'ZIP', 'type' => 'text', 'bind' => 'customer.postal_code'],
                ['key' => 'day', 'label' => 'Date', 'type' => 'date', 'bind' => 'job.scheduled_for'],
            ],
        ]]);

        $this->inTenant($tenant['org'], function () use ($seeded) {
            $seeded['customer']->update(['email' => 'maple@example.test', 'city' => 'Miami', 'postal_code' => '33180']);
            $seeded['job']->update(['scheduled_for' => '2026-08-18 15:00:00']);
        });

        $answers = $this->signedInAs($tech)->postJson('/api/v1/inspections/runs', [
            'customer_id' => $seeded['customer']->id,
            'job_id' => $seeded['job']->id,
            'inspection_definition_id' => $seeded['definition']->id,
        ])->assertCreated()->json('data.answers');

        $this->assertSame('maple@example.test', $answers['email'] ?? null);
        $this->assertSame('Miami', $answers['city'] ?? null);
        $this->assertSame('33180', $answers['zip'] ?? null);
        $this->assertSame('2026-08-18', $answers['day'] ?? null);
    }

    public function test_start_again_after_unpublish_is_refused(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $tech = $this->tech($tenant, extra: ['inspection.delete', 'inspection.new.view', 'inspection.reports.view', 'inspection.builder.view']);
        $seeded = $this->seedOffered($tenant, $tech);

        $runId = $this->signedInAs($tech)->postJson('/api/v1/inspections/runs', [
            'customer_id' => $seeded['customer']->id,
            'inspection_definition_id' => $seeded['definition']->id,
        ])->assertCreated()->json('data.id');

        $this->inTenant($tenant['org'], fn () => $seeded['definition']->update(['published_at' => null]));

        $this->signedInAs($tech)
            ->postJson("/api/v1/inspections/runs/{$runId}/restart")
            ->assertStatus(422);
    }

    /**
     * Scope narrows; the grant still gates. Both, and in that order.
     *
     * This definition is GRANTED to the technician's role and published — so
     * every rule that existed before scope says they may run it — and it belongs
     * to a franchise that is not theirs. If scope were merely advisory the
     * picker would offer it and the start would succeed.
     */
    public function test_the_picker_omits_an_inspection_scoped_to_another_franchise(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $tech = $this->tech($tenant);
        $seeded = $this->seedOffered($tenant, $tech);
        $elsewhere = $this->extraFranchise($tenant, 'Second');

        $this->inTenant($tenant['org'], fn () => $seeded['definition']->update([
            'scope_type' => InspectionDefinition::SCOPE_FRANCHISE,
            'scope_id' => $elsewhere->id,
        ]));

        $response = $this->signedInAs($tech)
            ->getJson('/api/v1/inspections/startable?customer_id='.$seeded['customer']->id)
            ->assertOk();

        $this->assertSame([], $response->json('data'),
            'a definition scoped elsewhere must not be offered, grant or no grant');
    }

    /**
     * Refused at the API, not merely hidden in the picker.
     *
     * A thing that is hidden but still starts when its id is posted is not
     * scoped at all — it is decorated.
     */
    public function test_starting_an_inspection_scoped_elsewhere_is_refused(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $tech = $this->tech($tenant);
        $seeded = $this->seedOffered($tenant, $tech);
        $elsewhere = $this->extraFranchise($tenant, 'Second');

        $this->inTenant($tenant['org'], fn () => $seeded['definition']->update([
            'scope_type' => InspectionDefinition::SCOPE_FRANCHISE,
            'scope_id' => $elsewhere->id,
        ]));

        $this->signedInAs($tech)->postJson('/api/v1/inspections/runs', [
            'customer_id' => $seeded['customer']->id,
            'inspection_definition_id' => $seeded['definition']->id,
        ])->assertStatus(422);
    }

    private function tech(array $tenant, string $name = 'Rodrigo', array $extra = [])
    {
        return $this->userWithRole(
            $tenant, $name, ['inspection.view', 'inspection.create', ...$extra, 'inspection.new.view', 'inspection.reports.view', 'inspection.builder.view'],
            // Pinned to the tenant's own franchise. The default is ORGANIZATION
            // level, which reaches every franchise — and a scope test written
            // against that passes whether or not scope works at all.
            onlyAt: $tenant['franchise'],
        );
    }

    private function grant(array $tenant, InspectionDefinition $definition, string $roleId): void
    {
        $this->inTenant($tenant['org'], fn () => InspectionDefinitionGrant::create([
            'inspection_definition_id' => $definition->id,
            'role_id' => $roleId,
        ]));
    }

    /**
     * @return array{
     *     customer: Customer,
     *     job: ?Job,
     *     definition: InspectionDefinition,
     *     version: InspectionDefinitionVersion
     * }
     */
    private function seedOffered(
        array $tenant,
        $tech,
        ?string $jobNumber = null,
        array $screens = [],
    ): array {
        return $this->inTenant($tenant['org'], function () use ($tenant, $tech, $jobNumber, $screens) {
            $customer = Customer::create([
                'owning_franchise_id' => $tenant['franchise']->id,
                'number' => 'C-'.bin2hex(random_bytes(3)),
                'name' => '14 Maple',
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
                'definition' => $definition->fresh(),
                'version' => $version,
            ];
        });
    }
}
