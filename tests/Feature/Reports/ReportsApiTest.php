<?php

namespace Tests\Feature\Reports;

use App\Models\Customer;
use App\Models\Job;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

class ReportsApiTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    private function makeJob(array $tenant, array $attributes = []): Job
    {
        return $this->inTenant($tenant['org'], function () use ($tenant, $attributes) {
            $customer = Customer::factory()->create([
                'owning_franchise_id' => $tenant['franchise']->id,
            ]);

            return Job::factory()->create(array_merge([
                'franchise_id' => $tenant['franchise']->id,
                'customer_id'  => $customer->id,
            ], $attributes));
        });
    }

    private function titlesDefinition(): array
    {
        return [
            'root'    => 'jobs',
            'columns' => [['resource' => 'jobs', 'column' => 'title']],
            'filters' => [],
        ];
    }

    public function test_an_administrator_builds_saves_and_runs_a_report(): void
    {
        $tenant = $this->provisionTenant('Alpha');

        $this->makeJob($tenant, ['title' => 'Sweep']);

        $created = $this->signedInAs($tenant['admin'])
            ->postJson('/api/v1/reports', [
                'company_id' => $tenant['company']->id,
                'name'       => 'Open jobs',
                'definition' => $this->titlesDefinition(),
            ])
            ->assertCreated()
            ->json('data');

        $this->assertSame('Open jobs', $created['name']);

        $run = $this->signedInAs($tenant['admin'])
            ->postJson('/api/v1/reports/'.$created['id'].'/run')
            ->assertOk()
            ->json('data');

        // Read the decoded body rather than assertJsonPath: column keys are
        // `jobs.title`, and assertJsonPath reads a dot as a level separator,
        // so the path form would look for a `jobs` object that is not there.
        $this->assertSame('Sweep', $run['rows'][0]['jobs.title']);
        $this->assertFalse($run['capped']);
        $this->assertSame(1, $run['count']);
        $this->assertSame('Title', $run['columns'][0]['label']);
    }

    public function test_a_report_can_be_listed_shown_renamed_and_deleted(): void
    {
        $tenant = $this->provisionTenant('Alpha');

        $created = $this->signedInAs($tenant['admin'])
            ->postJson('/api/v1/reports', [
                'company_id' => $tenant['company']->id,
                'name'       => 'First name',
                'definition' => $this->titlesDefinition(),
            ])
            ->assertCreated()
            ->json('data');

        $this->signedInAs($tenant['admin'])
            ->getJson('/api/v1/reports')
            ->assertOk()
            ->assertJsonPath('data.0.name', 'First name');

        $this->signedInAs($tenant['admin'])
            ->getJson('/api/v1/reports/'.$created['id'])
            ->assertOk()
            ->assertJsonPath('data.name', 'First name');

        $this->signedInAs($tenant['admin'])
            ->patchJson('/api/v1/reports/'.$created['id'], [
                'company_id' => $tenant['company']->id,
                'name'       => 'Second name',
                'definition' => $this->titlesDefinition(),
            ])
            ->assertOk()
            ->assertJsonPath('data.name', 'Second name');

        $this->signedInAs($tenant['admin'])
            ->deleteJson('/api/v1/reports/'.$created['id'])
            ->assertNoContent();

        $this->signedInAs($tenant['admin'])
            ->getJson('/api/v1/reports')
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    public function test_the_field_list_offers_only_mapped_resources(): void
    {
        $tenant = $this->provisionTenant('Alpha');

        $body = $this->signedInAs($tenant['admin'])
            ->getJson('/api/v1/reports/fields')
            ->assertOk()
            ->json('data');

        $this->assertArrayHasKey('jobs', $body);
        $this->assertArrayNotHasKey('password_reset_tokens', $body);
        $this->assertSame('Title', $body['jobs']['columns']['title']['label']);
        $this->assertSame('customers', $body['jobs']['relations']['customer']['to']);
    }

    /**
     * The picker must not advertise a module the person cannot open at all.
     */
    public function test_the_field_list_hides_a_module_the_person_has_no_permission_for(): void
    {
        $tenant = $this->provisionTenant('Alpha');

        $runner = $this->userWithRole($tenant, 'Runner', ['reports.view', 'jobs.view']);

        $body = $this->signedInAs($runner)
            ->getJson('/api/v1/reports/fields')
            ->assertOk()
            ->json('data');

        $this->assertArrayHasKey('jobs', $body, 'jobs.view was granted and should be offered');
        $this->assertArrayNotHasKey('users', $body, 'hr.view was not granted');
    }

    public function test_building_is_refused_without_the_build_permission(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $runner = $this->userWithRole($tenant, 'Runner', ['reports.view', 'jobs.view']);

        $this->signedInAs($runner)
            ->postJson('/api/v1/reports', [
                'company_id' => $tenant['company']->id,
                'name'       => 'Nope',
                'definition' => $this->titlesDefinition(),
            ])
            ->assertForbidden();
    }

    /**
     * The positive half: the same person may RUN a report somebody else built.
     * Without this, a policy that refused everything would pass the test above.
     */
    public function test_running_is_allowed_with_only_the_view_permission(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $runner = $this->userWithRole($tenant, 'Runner', ['reports.view', 'jobs.view']);

        $this->makeJob($tenant, ['title' => 'Sweep']);

        $created = $this->signedInAs($tenant['admin'])
            ->postJson('/api/v1/reports', [
                'company_id' => $tenant['company']->id,
                'name'       => 'Open jobs',
                'definition' => $this->titlesDefinition(),
            ])
            ->assertCreated()
            ->json('data');

        $this->signedInAs($runner)
            ->postJson('/api/v1/reports/'.$created['id'].'/run')
            ->assertOk()
            ->assertJsonPath('data.count', 1);
    }

    public function test_the_tab_is_refused_without_any_reports_permission(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $nobody = $this->userWithRole($tenant, 'Nobody', []);

        $this->signedInAs($nobody)
            ->getJson('/api/v1/reports')
            ->assertForbidden();
    }

    public function test_a_definition_naming_an_unmapped_table_is_refused_at_save(): void
    {
        $tenant = $this->provisionTenant('Alpha');

        $this->signedInAs($tenant['admin'])
            ->postJson('/api/v1/reports', [
                'company_id' => $tenant['company']->id,
                'name'       => 'Sneaky',
                'definition' => [
                    'root'    => 'password_reset_tokens',
                    'columns' => [['resource' => 'password_reset_tokens', 'column' => 'email']],
                    'filters' => [],
                ],
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('definition');
    }

    public function test_a_definition_with_an_undeclared_column_is_refused_at_save(): void
    {
        $tenant = $this->provisionTenant('Alpha');

        $this->signedInAs($tenant['admin'])
            ->postJson('/api/v1/reports', [
                'company_id' => $tenant['company']->id,
                'name'       => 'Sneaky column',
                'definition' => [
                    'root'    => 'jobs',
                    'columns' => [['resource' => 'jobs', 'column' => 'performed_by_user_id']],
                    'filters' => [],
                ],
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('definition');
    }

    public function test_a_sibling_franchises_report_is_not_listed_from_this_view(): void
    {
        $tenant = $this->provisionTenant('Alpha');

        $this->signedInAs($tenant['admin'])
            ->postJson('/api/v1/reports', [
                'company_id' => $tenant['company']->id,
                'name'       => 'Company report',
                'definition' => $this->titlesDefinition(),
            ])
            ->assertCreated();

        // A different company entirely, reachable by this administrator.
        $other = $this->provisionTenant('Alpha');

        $names = $this->signedInAs($tenant['admin'])
            ->getJson('/api/v1/reports')
            ->assertOk()
            ->json('data.*.name');

        $this->assertSame(['Company report'], $names);

        $seenByOther = $this->signedInAs($other['admin'])
            ->getJson('/api/v1/reports')
            ->assertOk()
            ->json('data');

        $this->assertSame([], $seenByOther, "another organization's reports were listed");
    }

    public function test_a_run_is_written_to_the_audit_log(): void
    {
        $tenant = $this->provisionTenant('Alpha');

        $this->makeJob($tenant, ['title' => 'Sweep']);

        $created = $this->signedInAs($tenant['admin'])
            ->postJson('/api/v1/reports', [
                'company_id' => $tenant['company']->id,
                'name'       => 'Audited',
                'definition' => $this->titlesDefinition(),
            ])
            ->assertCreated()
            ->json('data');

        $this->signedInAs($tenant['admin'])
            ->postJson('/api/v1/reports/'.$created['id'].'/run')
            ->assertOk();

        $this->assertDatabaseHas('audit_events', [
            'organization_id' => $tenant['org']->id,
            'action'          => 'report.run',
        ]);
    }
}
