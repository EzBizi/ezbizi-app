<?php

namespace Tests\Feature\Reports;

use App\Models\Automation;
use App\Models\AutomationVersion;
use App\Models\Customer;
use App\Models\Job;
use App\Support\Reports\ReportMap;
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
                'customer_id' => $customer->id,
            ], $attributes));
        });
    }

    private function titlesDefinition(): array
    {
        return [
            'root' => 'jobs',
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
                'name' => 'Open jobs',
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
                'name' => 'First name',
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
                'name' => 'Second name',
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
     * A resource whose permission does not exist is invisible to EVERYBODY,
     * administrators included: User::hasPermission runs ModuleAvailability
     * before the role is consulted, and the administrator short-circuit lives
     * downstream of that. So a map entry naming a permission no module
     * declares is a resource nobody can ever report on, and nothing else would
     * say so.
     */
    public function test_every_mapped_resource_is_offered_to_an_administrator(): void
    {
        $tenant = $this->provisionTenant('Alpha');

        $body = $this->signedInAs($tenant['admin'])
            ->getJson('/api/v1/reports/fields')
            ->assertOk()
            ->json('data');

        foreach (array_keys(ReportMap::all()) as $key) {
            $this->assertArrayHasKey(
                $key,
                $body,
                "{$key} is in ReportMap but an administrator is not offered it, which "
                .'means its permission is not one any registered module declares',
            );
        }
    }

    /**
     * THE SHAPE ON THE WIRE, not the shape in PHP.
     *
     * An empty PHP array encodes as `[]` — a JSON ARRAY — and a non-empty one
     * as an object. Three of the four resources have no relations, so the
     * field list shipped both shapes under the same key, and the client threw
     * casting the array to a map. In a release build that is a blank grey
     * panel with nothing in any log.
     *
     * The PHP value is identical either way, so only the ENCODED body can say
     * which this is. That is why this reads the raw content rather than
     * json().
     */
    public function test_relations_and_columns_are_objects_even_when_empty(): void
    {
        $tenant = $this->provisionTenant('Alpha');

        $body = $this->signedInAs($tenant['admin'])
            ->getJson('/api/v1/reports/fields')
            ->assertOk()
            ->getContent();

        $this->assertStringNotContainsString(
            '"relations":[]',
            $body,
            'a resource with no relations encoded as a JSON array; the client casts '
            .'this to a map and throws, which a release build draws as a blank panel',
        );

        $this->assertStringNotContainsString('"columns":[]', $body);

        // The control: a resource with no relations really is in this payload,
        // so the assertions above are looking at the case that can fail.
        $decoded = json_decode($body, true)['data'];
        $empty = array_filter($decoded, fn ($r) => $r['relations'] === []);
        $this->assertNotEmpty(
            $empty,
            'no mapped resource has an empty relations map, so this test proves nothing',
        );
    }

    /**
     * The picker must not advertise a module the person cannot open at all.
     */
    public function test_the_field_list_hides_a_module_the_person_has_no_permission_for(): void
    {
        $tenant = $this->provisionTenant('Alpha');

        // The BUILDER tab, because the field picker is the builder's pane. A
        // role without it never reaches this endpoint at all, which is a
        // different refusal from the one under test.
        $runner = $this->userWithRole($tenant, 'Runner', [
            'reports.view',
            'reports.builder.view',
            'jobs.view',
        ]);

        $body = $this->signedInAs($runner)
            ->getJson('/api/v1/reports/fields')
            ->assertOk()
            ->json('data');

        $this->assertArrayHasKey('jobs', $body, 'jobs.view was granted and should be offered');
        $this->assertArrayNotHasKey('users', $body, 'hr.view was not granted');
    }

    // ── money needs jobs.financials (Jose, 2026-09-29) ─────────────

    /**
     * A SAVED REPORT WITH A JOB AMOUNT IS REFUSED TO WHOEVER LACKS FINANCIALS.
     *
     * The author (an administrator) holds every permission; the person
     * running it holds jobs.view and not jobs.financials. Before this the run
     * printed every amount - the one way out Jobs' toggle had left, since the
     * export already drops the same columns.
     *
     * Both halves in one test: the SAME report, the SAME runner, granted
     * financials, runs - so the refusal is about the money and nothing else.
     */
    public function test_a_job_amount_needs_the_financials_permission_to_run(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $this->makeJob($tenant, ['title' => 'Sweep']);

        $report = $this->signedInAs($tenant['admin'])
            ->postJson('/api/v1/reports', [
                'company_id' => $tenant['company']->id,
                'name' => 'Takings',
                'definition' => [
                    'root' => 'jobs',
                    'columns' => [
                        ['resource' => 'jobs', 'column' => 'title'],
                        ['resource' => 'jobs', 'column' => 'amount_minor'],
                    ],
                    'filters' => [],
                ],
            ])
            ->assertCreated()
            ->json('data');

        $without = $this->userWithRole($tenant, 'Runner', ['reports.view', 'reports.library.view', 'jobs.view']);

        $this->signedInAs($without)
            ->postJson('/api/v1/reports/'.$report['id'].'/run')
            ->assertUnprocessable()
            ->assertJsonPath('errors.definition.0', "'Amount' needs the jobs.financials permission, which you do not have.");

        $with = $this->userWithRole($tenant, 'Bookkeeper', [
            'reports.view', 'reports.library.view', 'jobs.view', 'jobs.financials',
        ]);

        $this->signedInAs($with)
            ->postJson('/api/v1/reports/'.$report['id'].'/run')
            ->assertOk()
            ->assertJsonPath('data.count', 1);
    }

    /** Filtering on the amount reads it too, so it is refused the same way. */
    public function test_filtering_on_a_job_amount_needs_financials_too(): void
    {
        $tenant = $this->provisionTenant('Alpha');

        $report = $this->signedInAs($tenant['admin'])
            ->postJson('/api/v1/reports', [
                'company_id' => $tenant['company']->id,
                'name' => 'Big jobs',
                'definition' => [
                    'root' => 'jobs',
                    'columns' => [['resource' => 'jobs', 'column' => 'title']],
                    'filters' => [['resource' => 'jobs', 'column' => 'amount_minor', 'op' => 'gt', 'value' => 500000]],
                ],
            ])
            ->assertCreated()
            ->json('data');

        $without = $this->userWithRole($tenant, 'Runner', ['reports.view', 'reports.library.view', 'jobs.view']);

        $this->signedInAs($without)
            ->postJson('/api/v1/reports/'.$report['id'].'/run')
            ->assertUnprocessable();
    }

    /** The picker does not offer what the run would refuse. */
    public function test_the_field_list_offers_job_money_only_with_financials(): void
    {
        $tenant = $this->provisionTenant('Alpha');

        $plain = $this->userWithRole($tenant, 'Builder', ['reports.view', 'reports.builder.view', 'jobs.view']);
        $money = $this->userWithRole($tenant, 'Money', ['reports.view', 'reports.builder.view', 'jobs.view', 'jobs.financials']);

        $columns = fn ($user) => array_keys($this->signedInAs($user)
            ->getJson('/api/v1/reports/fields')
            ->assertOk()
            ->json('data.jobs.columns'));

        $this->assertNotContains('amount_minor', $columns($plain));
        $this->assertNotContains('currency', $columns($plain));
        $this->assertContains('title', $columns($plain));

        $this->assertContains('amount_minor', $columns($money));
        $this->assertContains('currency', $columns($money));
    }

    // ── which automations send a report (2026-09-30) ───────────────

    /**
     * THE LIBRARY SAYS WHICH RULES SEND EACH REPORT - to a reader who may see
     * rules. An off rule is listed, and does not count as sending.
     */
    public function test_the_library_names_the_rules_that_send_a_report(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        [$sent, $off, $unsent] = $this->threeReports($tenant);

        $reader = $this->userWithRole($tenant, 'Reader', [
            'reports.view', 'reports.library.view', 'jobs.view', 'automations.view',
        ]);

        $rows = collect($this->signedInAs($reader)->getJson('/api/v1/reports')->assertOk()->json('data'))
            ->keyBy('name');

        $this->assertTrue($rows['Sent']['sent_automatically']);
        $this->assertSame(['Sunday send'], array_column($rows['Sent']['sent_by'], 'name'));

        // Listed so the row can say "off", but it sends nothing.
        $this->assertFalse($rows['Off']['sent_automatically']);
        $this->assertSame(['Paused send'], array_column($rows['Off']['sent_by'], 'name'));
        $this->assertFalse($rows['Off']['sent_by'][0]['is_active']);

        $this->assertFalse($rows['Unsent']['sent_automatically']);
        $this->assertSame([], $rows['Unsent']['sent_by']);

        // What each counts, in the map's own word.
        $this->assertSame('Jobs', $rows['Sent']['root_label']);
    }

    /** Without automations.view: yes or no, never the rule's name or schedule. */
    public function test_a_reader_without_automations_gets_only_yes_or_no(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $this->threeReports($tenant);

        $reader = $this->userWithRole($tenant, 'Reader', ['reports.view', 'reports.library.view', 'jobs.view']);

        $response = $this->signedInAs($reader)->getJson('/api/v1/reports')->assertOk();
        $rows = collect($response->json('data'))->keyBy('name');

        $this->assertTrue($rows['Sent']['sent_automatically']);
        $this->assertNull($rows['Sent']['sent_by']);
        $this->assertFalse($rows['Unsent']['sent_automatically']);

        // The rule names appear nowhere in the payload.
        $this->assertStringNotContainsString('Sunday send', $response->getContent());
    }

    /** @return array{0: string, 1: string, 2: string} report ids */
    private function threeReports(array $tenant): array
    {
        $ids = [];

        foreach (['Sent', 'Off', 'Unsent'] as $name) {
            $ids[] = $this->signedInAs($tenant['admin'])
                ->postJson('/api/v1/reports', [
                    'company_id' => $tenant['company']->id,
                    'name' => $name,
                    'definition' => $this->titlesDefinition(),
                ])
                ->assertCreated()
                ->json('data.id');
        }

        $this->sendingRule($tenant, 'Sunday send', $ids[0], active: true);
        $this->sendingRule($tenant, 'Paused send', $ids[1], active: false);

        return $ids;
    }

    /** Shaped like Automations' own ReportSendersTest fixture. */
    private function sendingRule(array $tenant, string $name, string $reportId, bool $active): void
    {
        $this->inTenant($tenant['org'], function () use ($tenant, $name, $reportId, $active) {
            $org = $tenant['org']->id;
            $rule = Automation::create(['organization_id' => $org, 'name' => $name,
                'trigger_key' => 'automation.schedule_reached', 'is_active' => $active,
                'schedule' => ['kind' => 'weekly', 'days' => ['sunday'], 'time' => '20:00', 'timezone' => 'America/New_York']]);
            $version = AutomationVersion::create(['organization_id' => $org, 'automation_id' => $rule->id, 'version' => 1,
                'conditions' => null, 'actions' => [['type' => 'report.send', 'report_id' => $reportId]], 'created_at' => now()]);
            $rule->forceFill(['active_version_id' => $version->id])->save();
        });
    }

    public function test_building_is_refused_without_the_build_permission(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $runner = $this->userWithRole($tenant, 'Runner', ['reports.view', 'jobs.view']);

        $this->signedInAs($runner)
            ->postJson('/api/v1/reports', [
                'company_id' => $tenant['company']->id,
                'name' => 'Nope',
                'definition' => $this->titlesDefinition(),
            ])
            ->assertForbidden();
    }

    /**
     * The positive half: the same person may RUN a report somebody else built.
     * Without this, a policy that refused everything would pass the test above.
     *
     * `reports.library.view` joined the role on 2026-09-17 and the rule under
     * test did not change: running still needs no `reports.build`, which is the
     * distinction this has always been about. What the tab adds is a second,
     * separate question — may this person see the library pane at all — and
     * every existing role was granted it by migration, so nobody who could run
     * a report yesterday needs a new grant today.
     */
    public function test_running_is_allowed_with_only_the_view_permission(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $runner = $this->userWithRole($tenant, 'Runner', [
            'reports.view',
            'reports.library.view',
            'jobs.view',
        ]);

        $this->makeJob($tenant, ['title' => 'Sweep']);

        $created = $this->signedInAs($tenant['admin'])
            ->postJson('/api/v1/reports', [
                'company_id' => $tenant['company']->id,
                'name' => 'Open jobs',
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

    /**
     * THE BUG JOSE HIT: standing in a company, every save came back "File this
     * on the company you are looking at, not on the group."
     *
     * The builder sends no place at all, which used to mean the organization,
     * which PlaceInView refuses from anywhere but the group view. So a report
     * could only be created from the organization view — the one place most
     * people are not standing.
     */
    public function test_a_report_saved_from_a_company_view_files_on_that_company(): void
    {
        $tenant = $this->provisionTenant('Alpha');

        $created = $this->signedInAs($tenant['admin'])
            ->withHeader('X-EzBizi-View', 'company:'.$tenant['company']->id)
            ->postJson('/api/v1/reports', [
                'name' => 'From the company',
                'definition' => $this->titlesDefinition(),
            ])
            ->assertCreated()
            ->json('data');

        $this->assertSame($tenant['company']->id, $created['company_id']);
        $this->assertNull($created['franchise_id']);
    }

    public function test_a_report_saved_from_a_franchise_view_files_on_that_franchise(): void
    {
        $tenant = $this->provisionTenant('Alpha');

        $created = $this->signedInAs($tenant['admin'])
            ->withHeader('X-EzBizi-View', 'franchise:'.$tenant['franchise']->id)
            ->postJson('/api/v1/reports', [
                'name' => 'From the branch',
                'definition' => $this->titlesDefinition(),
            ])
            ->assertCreated()
            ->json('data');

        $this->assertSame($tenant['franchise']->id, $created['franchise_id']);
        // A place belongs to a company, and PlaceInView refuses a franchise
        // without one, so the company is carried too.
        $this->assertSame($tenant['company']->id, $created['company_id']);
    }

    /** The organization view still files on the group, as it always did. */
    public function test_a_report_saved_from_the_organization_view_files_on_the_group(): void
    {
        $tenant = $this->provisionTenant('Alpha');

        $created = $this->signedInAs($tenant['admin'])
            ->withHeader('X-EzBizi-View', 'organization')
            ->postJson('/api/v1/reports', [
                'name' => 'From the group',
                'definition' => $this->titlesDefinition(),
            ])
            ->assertCreated()
            ->json('data');

        $this->assertNull($created['company_id']);
        $this->assertNull($created['franchise_id']);
    }

    /**
     * The default must not become an override: an explicit place is still
     * honoured, and still checked against the view.
     */
    public function test_an_explicit_place_outside_the_view_is_still_refused(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $other = $this->extraFranchise($tenant, 'Alpha F2');

        $this->signedInAs($tenant['admin'])
            ->withHeader('X-EzBizi-View', 'franchise:'.$tenant['franchise']->id)
            ->postJson('/api/v1/reports', [
                'company_id' => $tenant['company']->id,
                'franchise_id' => $other->id,
                'name' => 'Somewhere else',
                'definition' => $this->titlesDefinition(),
            ])
            ->assertStatus(422);
    }

    public function test_a_definition_naming_an_unmapped_table_is_refused_at_save(): void
    {
        $tenant = $this->provisionTenant('Alpha');

        $this->signedInAs($tenant['admin'])
            ->postJson('/api/v1/reports', [
                'company_id' => $tenant['company']->id,
                'name' => 'Sneaky',
                'definition' => [
                    'root' => 'password_reset_tokens',
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
                'name' => 'Sneaky column',
                'definition' => [
                    'root' => 'jobs',
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
                'name' => 'Company report',
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
                'name' => 'Audited',
                'definition' => $this->titlesDefinition(),
            ])
            ->assertCreated()
            ->json('data');

        $this->signedInAs($tenant['admin'])
            ->postJson('/api/v1/reports/'.$created['id'].'/run')
            ->assertOk();

        $this->assertDatabaseHas('audit_events', [
            'organization_id' => $tenant['org']->id,
            'action' => 'report.run',
        ]);
    }
}
