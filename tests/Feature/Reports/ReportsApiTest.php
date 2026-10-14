<?php

namespace Tests\Feature\Reports;

use App\Models\Automation;
use App\Models\AutomationVersion;
use App\Models\Customer;
use App\Models\Job;
use App\Models\SavedReport;
use App\Models\Task;
use App\Models\TaskGroup;
use App\Models\TaskStatus;
use App\Models\WorkizJob;
use App\Support\Reports\ReportMap;
use App\Support\Tasks\TaskBoards;
use Carbon\CarbonImmutable;
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

    /** Jose, 2026-10-09: "i also want a preview view, as you select the items". */
    public function test_an_unsaved_definition_previews_its_first_rows_and_its_count(): void
    {
        $tenant = $this->provisionTenant('Alpha');

        foreach (range(1, 30) as $i) {
            $this->makeJob($tenant, ['title' => "Sweep {$i}"]);
        }

        $preview = $this->signedInAs($tenant['admin'])
            ->postJson('/api/v1/reports/preview', ['definition' => $this->titlesDefinition()])
            ->assertOk()
            ->json('data');

        $this->assertCount(25, $preview['rows']);
        $this->assertSame(30, $preview['count']);
        $this->assertSame('Title', $preview['columns'][0]['label']);
        $this->assertSame(0, SavedReport::withoutGlobalScopes()->count());
    }

    /** Task Board tasks: a personal board reports only to its owner, as on screen. */
    public function test_tasks_report_the_boards_the_person_could_open(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $other = $this->userWithRole($tenant, 'Tech', []);

        $this->inTenant($tenant['org'], function () use ($tenant, $other) {
            $boards = app(TaskBoards::class);
            foreach ([
                'Branch task' => $boards->ensureFor($tenant['franchise']),
                'My task' => $boards->ensureFor($tenant['admin']),
                'Their task' => $boards->ensureFor($other),
            ] as $title => $board) {
                Task::query()->create([
                    'board_id' => $board->id,
                    'group_id' => TaskGroup::query()->where('board_id', $board->id)->value('id'),
                    'franchise_id' => $board->franchise_id,
                    'title' => $title,
                ]);
            }
        });

        $titles = $this->signedInAs($tenant['admin'])
            ->postJson('/api/v1/reports/preview', ['definition' => [
                'root' => 'tasks',
                'columns' => [['resource' => 'tasks', 'column' => 'title']],
                'filters' => [],
                'sort' => [['resource' => 'tasks', 'column' => 'title', 'direction' => 'asc']],
            ]])
            ->assertOk()
            ->json('data.rows');

        // Administrator holds tasks.view_all (Jose, 2026-10-14), so a colleague's
        // personal board is one it could open too.
        $this->assertSame(['Branch task', 'My task', 'Their task'], array_map(fn ($r) => array_values($r)[0], $titles));
    }

    /** Task Board v2: a custom status reports its own name and done flag; subtasks say so. */
    public function test_tasks_report_custom_statuses_and_subtasks(): void
    {
        $tenant = $this->provisionTenant('Alpha');

        $this->inTenant($tenant['org'], function () use ($tenant) {
            $board = app(TaskBoards::class)->ensureFor($tenant['franchise']);
            $invoiced = TaskStatus::query()->create(['board_id' => $board->id, 'name' => 'Invoiced', 'colour' => '#00aa00', 'position' => 9, 'is_done' => true]);
            $group = TaskGroup::query()->where('board_id', $board->id)->value('id');
            $parent = Task::query()->create(['board_id' => $board->id, 'group_id' => $group, 'franchise_id' => $board->franchise_id,
                'title' => 'A parent', 'status' => 'done', 'status_id' => $invoiced->id]);
            Task::query()->create(['board_id' => $board->id, 'group_id' => $group, 'franchise_id' => $board->franchise_id,
                'title' => 'B child', 'parent_task_id' => $parent->id,
                'status_id' => TaskStatus::query()->where('board_id', $board->id)->where('key', 'not_started')->value('id')]);
        });

        $rows = $this->signedInAs($tenant['admin'])
            ->postJson('/api/v1/reports/preview', ['definition' => [
                'root' => 'tasks',
                'columns' => [
                    ['resource' => 'tasks', 'column' => 'title'],
                    ['resource' => 'task_statuses', 'column' => 'name'],
                    ['resource' => 'task_statuses', 'column' => 'is_done'],
                    ['resource' => 'tasks', 'column' => 'is_subtask'],
                ],
                'filters' => [],
                'sort' => [['resource' => 'tasks', 'column' => 'title', 'direction' => 'asc']],
            ]])
            ->assertOk()
            ->json('data.rows');

        $this->assertSame([['A parent', 'Invoiced', true, false], ['B child', 'Not started', false, true]],
            array_map(fn ($r) => array_map(fn ($v) => is_numeric($v) && ! is_string($v) ? (bool) $v : $v, array_values($r)), $rows));
    }

    /** The same gates as a run: building, and a column's own permission. */
    public function test_a_preview_keeps_the_build_and_column_permissions(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $this->makeJob($tenant, ['title' => 'Sweep']);

        $reader = $this->userWithRole($tenant, 'Reader', ['reports.view', 'reports.builder.view', 'jobs.view']);

        $this->signedInAs($reader)
            ->postJson('/api/v1/reports/preview', ['definition' => $this->titlesDefinition()])
            ->assertForbidden();

        $builder = $this->userWithRole($tenant, 'Builder', ['reports.view', 'reports.build', 'reports.builder.view', 'jobs.view']);

        $this->signedInAs($builder)
            ->postJson('/api/v1/reports/preview', ['definition' => [
                'root' => 'jobs',
                'columns' => [['resource' => 'jobs', 'column' => 'amount_minor']],
                'filters' => [],
            ]])
            ->assertUnprocessable()
            ->assertJsonPath('errors.definition.0', "'Amount' needs the jobs.financials permission, which you do not have.");

        $this->signedInAs($builder)
            ->postJson('/api/v1/reports/preview', ['definition' => $this->titlesDefinition()])
            ->assertOk()
            ->assertJsonPath('data.count', 1);
    }

    public function test_a_report_sorts_and_renames_its_columns(): void
    {
        $tenant = $this->provisionTenant('Alpha');

        foreach (['Bravo', 'Alpha', 'Charlie'] as $title) {
            $this->makeJob($tenant, ['title' => $title]);
        }

        $definition = [
            'root' => 'jobs',
            'columns' => [['resource' => 'jobs', 'column' => 'title', 'label' => 'Job']],
            'filters' => [],
            'sort' => [['resource' => 'jobs', 'column' => 'title', 'dir' => 'desc']],
        ];

        $preview = $this->signedInAs($tenant['admin'])
            ->postJson('/api/v1/reports/preview', ['definition' => $definition])
            ->assertOk()
            ->json('data');

        $this->assertSame(['Charlie', 'Bravo', 'Alpha'], array_column($preview['rows'], 'jobs.title'));
        $this->assertSame('Job', $preview['columns'][0]['label']);
    }

    public function test_a_saved_report_downloads_as_csv(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $this->makeJob($tenant, ['title' => 'Sweep']);

        $report = $this->signedInAs($tenant['admin'])
            ->postJson('/api/v1/reports', [
                'company_id' => $tenant['company']->id,
                'name' => 'Open jobs',
                'definition' => $this->titlesDefinition(),
            ])
            ->json('data');

        $response = $this->signedInAs($tenant['admin'])
            ->get('/api/v1/reports/'.$report['id'].'/csv')
            ->assertOk()
            ->assertHeader('Content-Type', 'text/csv; charset=UTF-8');

        $this->assertStringContainsString('Open-jobs-', (string) $response->headers->get('Content-Disposition'));
        $this->assertSame("Title\nSweep\n", $response->getContent());
    }

    /** The builder brief's filters: one of, between, empty, a named period, and any/all. */
    public function test_the_new_filters_keep_the_rows_they_name(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-09 12:00', 'America/New_York'));
        $tenant = $this->provisionTenant('Alpha');

        $this->makeJob($tenant, ['title' => 'A', 'status' => 'done', 'duration_minutes' => 30, 'created_at' => '2026-10-09 14:00:00']);
        $this->makeJob($tenant, ['title' => 'B', 'status' => 'scheduled', 'duration_minutes' => 90, 'created_at' => '2026-09-15 14:00:00']);
        $this->makeJob($tenant, ['title' => 'C', 'status' => 'cancelled', 'duration_minutes' => 120, 'created_at' => '2026-08-01 14:00:00']);

        $titles = function (array $filters, string $match = 'all') use ($tenant) {
            $rows = $this->signedInAs($tenant['admin'])
                ->postJson('/api/v1/reports/preview', ['definition' => [
                    'root' => 'jobs',
                    'columns' => [['resource' => 'jobs', 'column' => 'title']],
                    'filters' => $filters,
                    'match' => $match,
                    'sort' => [['resource' => 'jobs', 'column' => 'title']],
                ]])
                ->assertOk()
                ->json('data.rows');

            return array_column($rows, 'jobs.title');
        };

        $f = fn (string $column, string $op, mixed $value = null) => ['resource' => 'jobs', 'column' => $column, 'op' => $op, 'value' => $value];

        $this->assertSame(['A', 'C'], $titles([$f('status', 'in', ['done', 'cancelled'])]));
        $this->assertSame(['B'], $titles([$f('status', 'not_in', ['done', 'cancelled'])]));
        $this->assertSame(['A', 'B'], $titles([$f('duration_minutes', 'between', [30, 90])]));
        $this->assertSame(['A'], $titles([$f('created_at', 'period', 'today')]));
        $this->assertSame(['B'], $titles([$f('created_at', 'period', 'last_month')]));
        $this->assertSame(['A', 'C'], $titles([$f('status', 'eq', 'done'), $f('status', 'eq', 'cancelled')], 'any'));
        $this->assertSame([], $titles([$f('status', 'eq', 'done'), $f('status', 'eq', 'cancelled')]));
        $this->assertSame([], $titles([$f('title', 'empty')]));
        $this->assertSame(['A', 'B', 'C'], $titles([$f('title', 'not_empty')]));

        CarbonImmutable::setTestNow();
    }

    /** Group by and totals (the builder brief, items 10-11), with a grand total over every row. */
    public function test_a_report_groups_and_totals_its_rows(): void
    {
        $tenant = $this->provisionTenant('Alpha');

        $this->makeJob($tenant, ['status' => 'done', 'duration_minutes' => 30, 'created_at' => '2026-09-10 15:00:00']);
        $this->makeJob($tenant, ['status' => 'done', 'duration_minutes' => 60, 'created_at' => '2026-10-02 15:00:00']);
        $this->makeJob($tenant, ['status' => 'cancelled', 'duration_minutes' => 90, 'created_at' => '2026-10-03 15:00:00']);

        $summary = fn (array $group) => $this->signedInAs($tenant['admin'])
            ->postJson('/api/v1/reports/preview', ['definition' => [
                'root' => 'jobs',
                'columns' => [],
                'filters' => [],
                'group' => $group,
                'totals' => [
                    ['fn' => 'count'],
                    ['resource' => 'jobs', 'column' => 'duration_minutes', 'fn' => 'sum'],
                    ['resource' => 'jobs', 'column' => 'duration_minutes', 'fn' => 'avg'],
                ],
            ]])
            ->assertOk()
            ->json('data');

        $byStatus = $summary([['resource' => 'jobs', 'column' => 'status']]);

        $this->assertSame(['jobs.status', 'count', 'jobs.duration_minutes:sum', 'jobs.duration_minutes:avg'], array_column($byStatus['columns'], 'key'));
        $this->assertSame('Minutes (sum)', $byStatus['columns'][2]['label']);
        $this->assertSame([['done', 2, 90], ['cancelled', 1, 90]], array_map(
            fn ($row) => [$row['jobs.status'], (int) $row['count'], (int) $row['jobs.duration_minutes:sum']],
            $byStatus['rows'],
        ));
        // The grand total is its own query, so the average is of all three jobs.
        $this->assertSame(3, (int) $byStatus['total']['count']);
        $this->assertSame(60.0, (float) $byStatus['total']['jobs.duration_minutes:avg']);

        $byMonth = $summary([['resource' => 'jobs', 'column' => 'created_at', 'bucket' => 'month']]);
        $this->assertSame(['2026-10-01' => 2, '2026-09-01' => 1], array_combine(
            array_column($byMonth['rows'], 'jobs.created_at:month'),
            array_map('intval', array_column($byMonth['rows'], 'count')),
        ));

        $this->signedInAs($tenant['admin'])
            ->postJson('/api/v1/reports/preview', ['definition' => [
                'root' => 'jobs', 'columns' => [], 'filters' => [],
                'group' => [['resource' => 'jobs', 'column' => 'status', 'bucket' => 'month']],
            ]])
            ->assertUnprocessable();
    }

    /**
     * Workiz as reportable data (the builder brief, item 17). Workiz stamps
     * are the place's own clock, so "today" is read as it is written: a job
     * booked at 1 am local is today's, which a UTC reading would drop.
     */
    public function test_workiz_jobs_report_their_source_and_total_in_local_time(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-09 12:00', 'America/New_York'));
        $tenant = $this->provisionTenant('Alpha');

        $this->inTenant($tenant['org'], function () use ($tenant) {
            foreach ([['LS Ads', 250.5, '2026-10-09 01:00:00'], ['LS Ads', 100, '2026-10-09 09:00:00'], ['GMB', 75, '2026-10-08 23:00:00']] as $i => [$source, $total, $at]) {
                WorkizJob::query()->create([
                    'franchise_id' => $tenant['franchise']->id,
                    'remote_id' => "JOB-{$i}",
                    'job_source' => $source,
                    'booked_at' => $at,
                    'payload' => ['JobTotalPrice' => $total, 'Team' => [['id' => 'USR-1', 'Name' => 'Ann']]],
                    'synced_at' => now(),
                ]);
            }
        });

        $data = $this->signedInAs($tenant['admin'])
            ->postJson('/api/v1/reports/preview', ['definition' => [
                'root' => 'workiz_jobs',
                'columns' => [],
                'filters' => [['resource' => 'workiz_jobs', 'column' => 'booked_at', 'op' => 'period', 'value' => 'today']],
                'group' => [['resource' => 'workiz_jobs', 'column' => 'job_source']],
                'totals' => [['fn' => 'count'], ['resource' => 'workiz_jobs', 'column' => 'total_minor', 'fn' => 'sum']],
            ]])
            ->assertOk()
            ->json('data');

        $this->assertSame([['LS Ads', 2, 35050]], array_map(
            fn ($row) => [$row['workiz_jobs.job_source'], (int) $row['count'], (int) $row['workiz_jobs.total_minor:sum']],
            $data['rows'],
        ));

        $tech = $this->signedInAs($tenant['admin'])
            ->postJson('/api/v1/reports/preview', ['definition' => [
                'root' => 'workiz_jobs',
                'columns' => [['resource' => 'workiz_jobs', 'column' => 'tech']],
                'filters' => [],
            ]])
            ->json('data.rows.0');
        $this->assertSame('Ann', $tech['workiz_jobs.tech']);

        CarbonImmutable::setTestNow();
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
