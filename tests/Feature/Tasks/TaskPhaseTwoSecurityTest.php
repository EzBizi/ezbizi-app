<?php

namespace Tests\Feature\Tasks;

use App\Models\Assignment;
use App\Models\AutomationEvent;
use App\Models\Company;
use App\Models\Franchise;
use App\Models\Role;
use App\Models\Task;
use App\Models\TaskActivity;
use App\Models\TaskBoard;
use App\Models\TaskBoardMember;
use App\Models\TaskDependency;
use App\Models\TaskRecurrenceOccurrence;
use App\Models\User;
use App\Support\Scoping\ViewScope;
use App\Support\Tasks\StatusCategory;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/**
 * Task 15: the security gate for Phase 2's one query surface (`GET
 * tasks/boards/{board}/rows`, all five presentations) and its riskiest
 * writes (dependencies, recurrence). Phase 1 proved four leaks closed —
 * sibling company, company-to-organization reach, private non-member,
 * membership revoked mid-read — for the single-board and cross-board task
 * lists. This file re-proves all four against EVERY presentation the rows
 * endpoint can render, because Phase 2 added a presentation-specific branch
 * (`TaskWindow::parse()`, `TaskListQuery::applyWindow()`,
 * `TaskWorkloadQuery`) inside a gate that already existed — a leak in any
 * one branch is still a leak, even if the other four are sound.
 */
final class TaskPhaseTwoSecurityTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    private const PRESENTATIONS = ['table', 'kanban', 'calendar', 'timeline', 'workload'];

    private array $alpha;

    protected function setUp(): void
    {
        parent::setUp();
        $this->alpha = $this->provisionTenant('Alpha');
        app(TenantContext::class)->set($this->alpha['org']);
        $this->artisan('ezbizi:sync-permissions')->assertSuccessful();
    }

    // ── The four leaks x five presentations, on the single-board rows endpoint ──

    /**
     * A board on a sibling company is REACHABLE (present in `/me`'s
     * `companies`, org-wide assignment) but outside the actor's current
     * view. `TaskPlace::coveredBy()` requires the current view to cover the
     * board's exact place, not merely that reach includes it somewhere —
     * every presentation must refuse identically.
     */
    public function test_sibling_company_board_refuses_every_presentation_despite_being_reachable(): void
    {
        // Built before any request runs as an actor other than provisioning
        // — ScopeReach memoizes per actor for the life of the binding, and a
        // company created after the first ask would be invisible for the
        // wrong reason (a stale memo, not the access rule this proves).
        [$sibling] = $this->secondCompany();

        $this->actingAs($this->alpha['admin']->fresh());
        $this->setView($this->alpha['admin'], ViewScope::COMPANY.':'.$sibling->id);
        $siblingBoard = $this->createBoard('scope');
        $this->dueTaskOn($siblingBoard, '2026-09-15');

        $worker = $this->userWithRole($this->alpha, 'Org-wide worker', ['tasks.view', 'tasks.edit']);
        $this->setView($worker, ViewScope::COMPANY.':'.$this->alpha['company']->id);
        $this->signedInAs($worker);
        $me = $this->getJson('/api/v1/me')->assertOk();
        $this->assertContains(
            $sibling->id,
            collect($me->json('data.companies'))->pluck('id')->all(),
            'the sibling company must be reachable for this to be the leak Phase 1 closed.',
        );

        foreach ($this->presentationConfigs($siblingBoard) as $presentation => $configuration) {
            $this->getJson($this->rowsUrl($siblingBoard, $configuration))
                ->assertNotFound("presentation [{$presentation}] must refuse a sibling-company board.");
        }
    }

    /**
     * An organization-level board (`company_id` and `franchise_id` both
     * null) requires reach that covers the ORGANIZATION itself —
     * company-only reach must never be enough, however the current view is
     * pinned, and however wide the presentation's own window is.
     */
    public function test_organization_level_board_refuses_every_presentation_when_reach_is_company_only(): void
    {
        $this->actingAs($this->alpha['admin']->fresh());
        $this->setView($this->alpha['admin'], null);
        $orgBoard = $this->createBoard('scope');
        $this->dueTaskOn($orgBoard, '2026-09-15');

        $companyOnly = $this->userAt('Company-only worker', $this->alpha['company']->id);
        $this->setView($companyOnly, null);
        $this->signedInAs($companyOnly);

        foreach ($this->presentationConfigs($orgBoard) as $presentation => $configuration) {
            $this->getJson($this->rowsUrl($orgBoard, $configuration))
                ->assertNotFound("presentation [{$presentation}] must refuse an organization board to company-only reach.");
        }
    }

    /**
     * A private board's non-member is refused however wide their
     * permissions or reach — `privateMembershipDecision()` is the last gate
     * `viewDecision()` runs, and it must close every presentation, not just
     * the ones Phase 1 originally shipped with.
     */
    public function test_private_non_member_board_refuses_every_presentation(): void
    {
        $this->actingAs($this->alpha['admin']->fresh());
        $this->setView($this->alpha['admin'], ViewScope::COMPANY.':'.$this->alpha['company']->id);
        $private = $this->createBoard('private');
        $this->dueTaskOn($private, '2026-09-15');

        $stranger = $this->userWithRole($this->alpha, 'Stranger', ['tasks.view', 'tasks.edit']);
        $this->setView($stranger, ViewScope::COMPANY.':'.$this->alpha['company']->id);
        $this->signedInAs($stranger);

        foreach ($this->presentationConfigs($private) as $presentation => $configuration) {
            $this->getJson($this->rowsUrl($private, $configuration))
                ->assertNotFound("presentation [{$presentation}] must refuse a private board's non-member.");
        }
    }

    /**
     * A membership revoked WHILE the actor is still signed in stops
     * returning that board's rows on the very next request — the exact
     * race Phase 1's private-membership check exists to close, proven here
     * for every presentation rather than only Calendar
     * (`TaskWindowedRowsTest` already covers Calendar alone).
     */
    public function test_membership_revoked_mid_read_stops_every_presentation(): void
    {
        $this->actingAs($this->alpha['admin']->fresh());
        $this->setView($this->alpha['admin'], ViewScope::COMPANY.':'.$this->alpha['company']->id);
        $private = $this->createBoard('private');
        $worker = $this->userWithRole($this->alpha, 'Worker', ['tasks.view', 'tasks.edit']);
        // Membership and the task are created while still signed in as the
        // admin (an active board owner) — the worker holds no `tasks.manage`
        // and is not yet a member, so it cannot add itself.
        $membershipId = $this->postJson("/api/v1/tasks/boards/{$private->id}/members", [
            'user_id' => $worker->id, 'role' => 'member',
        ])->assertCreated()->json('data.id');
        $this->enterTenant();
        $task = $this->dueTaskOn($private, '2026-09-15', $worker->id);
        $this->enterTenant();
        $statusId = (string) $task->status_option_id;
        $this->setView($worker, ViewScope::COMPANY.':'.$this->alpha['company']->id);

        $this->signedInAs($worker);
        foreach ($this->presentationConfigs($private, $statusId) as $presentation => $configuration) {
            $response = $this->getJson($this->rowsUrl($private, $configuration))->assertOk();
            $this->assertGreaterThan(
                0,
                $presentation === 'workload' ? count($response->json('data')) : (int) $response->json('meta.total'),
                "presentation [{$presentation}] must show the real row before revocation, or this proves nothing.",
            );
        }

        $this->actingAs($this->alpha['admin']->fresh());
        $this->enterTenant();
        $this->deleteJson("/api/v1/tasks/boards/{$private->id}/members/{$membershipId}")->assertOk();

        $this->signedInAs($worker);
        foreach ($this->presentationConfigs($private, $statusId) as $presentation => $configuration) {
            $this->getJson($this->rowsUrl($private, $configuration))
                ->assertNotFound("presentation [{$presentation}] must stop returning rows after revocation.");
        }
    }

    // ── Bucket identity: a foreign board's option id inside a legitimate kanban window ──

    /**
     * `buckets` carries record ids, validated by TaskViewCodec only for
     * FORMAT (task-13/14 codec), not for board ownership. The value that
     * actually keeps a foreign id from leaking a row is that Kanban's
     * `applyFilter()` runs against a query already scoped to `board_id =
     * $board->id` — a foreign bucket id simply matches nothing. Proven
     * directly rather than trusted: the same request, with the REAL bucket
     * id swapped in, must return the row the foreign id did not.
     */
    public function test_kanban_buckets_naming_a_foreign_boards_option_never_leak_a_row(): void
    {
        $this->actingAs($this->alpha['admin']->fresh());
        $this->setView($this->alpha['admin'], ViewScope::COMPANY.':'.$this->alpha['company']->id);
        $home = $this->createBoard('scope');
        $task = $this->dueTaskOn($home, '2026-09-15');
        $this->enterTenant();
        $realStatusId = (string) $task->status_option_id;

        $foreign = $this->createBoard('scope');
        $this->enterTenant();
        $foreignStatusId = (string) $foreign->statusOptions()->active()->first()->id;

        $configuration = $this->configFor('kanban', 'status', [$foreignStatusId]);
        $this->getJson($this->rowsUrl($home, $configuration))->assertOk()
            ->assertJsonPath('meta.total', 0)
            ->assertJsonCount(0, 'data');

        $realConfiguration = $this->configFor('kanban', 'status', [$realStatusId]);
        $this->getJson($this->rowsUrl($home, $realConfiguration))->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.id', $task->id);
    }

    // ── Over-wide windows and oversized bucket sets are refused, not truncated ──

    /**
     * An out-of-bounds window must come back as a hard refusal (422, no
     * `data` key at all) rather than a 200 carrying a silently truncated
     * slice — a caller who saw 200 would believe the returned rows were
     * everything. `TaskPresentationCodecTest` already proves this at the
     * codec unit level; this proves the same refusal survives the full HTTP
     * round trip through the endpoint every presentation actually uses.
     */
    public function test_over_wide_window_and_oversized_kanban_bucket_set_are_refused_not_truncated(): void
    {
        $this->actingAs($this->alpha['admin']->fresh());
        $this->setView($this->alpha['admin'], ViewScope::COMPANY.':'.$this->alpha['company']->id);
        $board = $this->createBoard('scope');

        $tooWide = $this->configFor('calendar');
        $tooWide['window'] = ['from' => '2026-01-01', 'to' => '2027-06-01']; // 516 days > 400.
        $response = $this->getJson($this->rowsUrl($board, $tooWide))
            ->assertUnprocessable()->assertJsonValidationErrors('configuration.window');
        $this->assertArrayNotHasKey('data', $response->json());

        $tooManyBuckets = $this->configFor('kanban', 'status', array_map(
            fn () => (string) Str::uuid7(), range(1, 51),
        ));
        $bucketResponse = $this->getJson($this->rowsUrl($board, $tooManyBuckets))
            ->assertUnprocessable()->assertJsonValidationErrors('configuration.window');
        $this->assertArrayNotHasKey('data', $bucketResponse->json());
    }

    // ── The cross-board Table gap: MyTasksApiTest proves Calendar/Timeline/Workload, not Table ──

    /**
     * `MyTasksApiTest::test_cross_board_presentations_exclude_sibling_com-
     * pany_organization_reach_and_private_non_member_boards` proves the
     * sibling-company and organization-reach leaks for Calendar. Table's
     * `myTasks()` shares the exact same `TaskBoardAccess::constrainVisible`
     * prefilter, but is a genuinely different method (owner-filtered rather
     * than windowed) — untested for these two leaks until now. Owning the
     * task on the leak board is the strongest version of this attack: even
     * an owned task must not appear if its board is outside the current
     * view.
     */
    public function test_cross_board_table_excludes_sibling_company_and_organization_reach_boards(): void
    {
        [$sibling] = $this->secondCompany();

        $this->actingAs($this->alpha['admin']->fresh());
        $this->setView($this->alpha['admin'], ViewScope::COMPANY.':'.$this->alpha['company']->id);
        $visible = $this->createBoard('scope');

        // Every task below is created while still signed in as the admin,
        // naming the worker as OWNER — the worker itself holds no
        // `tasks.create`, and the point of this test is board visibility,
        // not task-creation permission.
        $worker = $this->userWithRole($this->alpha, 'Org-wide worker', ['tasks.view', 'tasks.edit']);
        $visibleTask = $this->dueTaskOn($visible, '2026-09-15', $worker->id);

        $this->setView($this->alpha['admin'], ViewScope::COMPANY.':'.$sibling->id);
        $siblingBoard = $this->createBoard('scope');
        $this->dueTaskOn($siblingBoard, '2026-09-16', $worker->id);

        $this->setView($this->alpha['admin'], null);
        $orgBoard = $this->createBoard('scope');
        $this->dueTaskOn($orgBoard, '2026-09-17', $worker->id);

        $this->setView($worker, ViewScope::COMPANY.':'.$this->alpha['company']->id);
        $this->signedInAs($worker);
        $table = $this->getJson('/api/v1/tasks/my')->assertOk();
        $this->assertSame([$visibleTask->id], collect($table->json('data'))->pluck('id')->all());

        $this->enterTenant();
        $companyOnly = $this->userAt('Company-only worker', $this->alpha['company']->id);
        $this->setView($companyOnly, null);
        $this->signedInAs($companyOnly);
        $this->getJson('/api/v1/tasks/my')->assertForbidden();
    }

    // ── Failed writes leave no footprint ──

    /**
     * A dependency that fails cycle detection: the whole `add()` call runs
     * in one transaction (`TaskDependencyWriter::add()`), and the cycle
     * check throws before `TaskDependency::create()` — so a refusal must
     * leave the business row, the activity, the audit row, and the outbox
     * event all completely untouched, not merely the dependency count
     * (`TaskDependenciesTest` already covers that count alone).
     */
    public function test_failed_dependency_cycle_leaves_no_footprint(): void
    {
        $this->actingAs($this->alpha['admin']->fresh());
        $this->setView($this->alpha['admin'], ViewScope::COMPANY.':'.$this->alpha['company']->id);
        $board = $this->createBoard('scope');
        $a = $this->plainTask($board, 'A');
        $b = $this->plainTask($board, 'B');
        // A real edge first: A blocks B.
        $this->postJson("/api/v1/tasks/{$b->id}/dependencies", ['blocker_task_id' => $a->id])
            ->assertCreated();

        // The attack: B blocks A would close the loop A -> B -> A.
        $before = $this->footprint();
        $this->postJson("/api/v1/tasks/{$a->id}/dependencies", ['blocker_task_id' => $b->id])
            ->assertForbidden();
        $this->assertSame($before, $this->footprint());
    }

    /**
     * A recurrence declaration that conflicts on a stale `expected_version`:
     * `TaskWriter::assertVersion()` throws before any `forceFill()`/`save()`
     * runs, so a stale write must leave the task's own row, its activity,
     * its audit row, and (had the series ever run) its occurrence markers
     * completely untouched — not merely `recurrence` itself
     * (`TaskRecurrenceEndpointTest::test_a_stale_version_conflicts` already
     * covers that field alone).
     */
    public function test_failed_recurrence_declaration_on_stale_version_leaves_no_footprint(): void
    {
        $this->actingAs($this->alpha['admin']->fresh());
        $this->setView($this->alpha['admin'], ViewScope::COMPANY.':'.$this->alpha['company']->id);
        $board = $this->createBoard('scope');
        $task = $this->plainTask($board, 'Recurring');
        $staleVersion = (int) $task->version;

        $this->putJson("/api/v1/tasks/{$task->id}/recurrence", [
            'expected_version' => $staleVersion,
            'recurrence' => ['mode' => 'on_schedule', 'every' => 1, 'unit' => 'week'],
        ])->assertOk();

        $before = $this->footprint();
        $this->putJson("/api/v1/tasks/{$task->id}/recurrence", [
            'expected_version' => $staleVersion,
            'recurrence' => ['mode' => 'on_schedule', 'every' => 2, 'unit' => 'week'],
        ])->assertConflict();
        $this->assertSame($before, $this->footprint());
        $this->enterTenant();
        $this->assertSame(1, $task->fresh()->recurrence['every']);
    }

    // ── Helpers ──

    /** @return array<string, array<string, mixed>> presentation => configuration, keyed for readable assertion messages. */
    private function presentationConfigs(TaskBoard $board, ?string $statusId = null): array
    {
        return [
            'table' => $this->configFor('table'),
            'kanban' => $this->configFor('kanban', 'status', $statusId === null ? [] : [$statusId]),
            'calendar' => $this->configFor('calendar'),
            'timeline' => $this->configFor('timeline'),
            'workload' => $this->configFor('workload'),
        ];
    }

    private function configFor(string $presentation, ?string $bucketField = null, array $buckets = []): array
    {
        $window = match ($presentation) {
            'table' => ['page' => 1, 'per_page' => 50],
            'kanban' => ['bucket_field' => $bucketField ?? 'status', 'buckets' => $buckets],
            'calendar', 'timeline' => ['from' => '2026-09-01', 'to' => '2026-09-30'],
            'workload' => ['from' => '2026-09-01', 'to' => '2026-09-30', 'unit' => 'day'],
            default => throw new \InvalidArgumentException("Unknown presentation {$presentation}"),
        };

        return [
            'schema' => 2, 'presentation' => $presentation, 'filters' => [], 'sorts' => [],
            'hidden_columns' => [], 'column_order' => [], 'column_widths' => (object) [],
            'group_by' => null, 'search' => '', 'window' => $window,
        ];
    }

    private function rowsUrl(TaskBoard $board, array $configuration): string
    {
        return "/api/v1/tasks/boards/{$board->id}/rows?".http_build_query([
            'configuration' => json_encode($configuration, JSON_THROW_ON_ERROR),
        ]);
    }

    private function createBoard(string $visibility): TaskBoard
    {
        $id = $this->postJson('/api/v1/tasks/boards', ['name' => 'Board '.uniqid(), 'visibility' => $visibility])
            ->assertCreated()->json('data.id');
        $this->enterTenant();

        return TaskBoard::findOrFail($id);
    }

    private function plainTask(TaskBoard $board, string $title): Task
    {
        $group = $board->groups()->active()->firstOrFail();
        $status = $board->statusOptions()->active()->where('category', StatusCategory::NotStarted)->firstOrFail();
        $id = $this->postJson("/api/v1/tasks/boards/{$board->id}/tasks", [
            'title' => $title, 'group_id' => $group->id, 'status_option_id' => $status->id,
        ])->assertCreated()->json('data.id');
        $this->enterTenant();

        return Task::findOrFail($id);
    }

    private function dueTaskOn(TaskBoard $board, string $dueOn, ?string $ownerId = null): Task
    {
        $group = $board->groups()->active()->firstOrFail();
        $status = $board->statusOptions()->active()->where('category', StatusCategory::NotStarted)->firstOrFail();
        $id = $this->postJson("/api/v1/tasks/boards/{$board->id}/tasks", [
            'title' => 'Due task '.uniqid(), 'group_id' => $group->id, 'status_option_id' => $status->id,
            'due_on' => $dueOn, 'owner_user_id' => $ownerId,
        ])->assertCreated()->json('data.id');
        $this->enterTenant();

        return Task::findOrFail($id);
    }

    /** A second Company (plus a franchise under it) in the same organization — the sibling. */
    private function secondCompany(): array
    {
        return $this->inTenant($this->alpha['org'], function () {
            $company = Company::create(['name' => 'Sibling Co', 'is_active' => true]);
            $franchise = Franchise::create([
                'company_id' => $company->id, 'name' => 'Sibling F1',
                'timezone' => 'America/New_York', 'currency' => 'USD', 'is_active' => true,
            ]);

            return [$company, $franchise];
        });
    }

    /** A user whose reach is EXACTLY company-scoped — unlike `userWithRole()`'s default org-wide assignment. */
    private function userAt(string $name, string $companyId): User
    {
        return $this->inTenant($this->alpha['org'], function () use ($name, $companyId) {
            $role = Role::create(['organization_id' => $this->alpha['org']->id, 'name' => $name, 'is_system' => false]);
            $role->syncPermissionKeys(['tasks.view', 'tasks.edit']);
            $user = User::create([
                'organization_id' => $this->alpha['org']->id, 'role_id' => $role->id, 'name' => $name,
                'email' => strtolower(str_replace(' ', '.', $name)).'@'.$this->alpha['org']->slug.'.test',
                'password' => 'a-real-password', 'is_active' => true,
            ]);
            Assignment::create([
                'organization_id' => $this->alpha['org']->id, 'user_id' => $user->id,
                'assigned_company_id' => $companyId,
            ]);

            return $user->fresh(['role.permissions', 'assignments.assignedCompany']);
        });
    }

    /**
     * `actingAs()` binds one PHP object instance to the auth guard for the
     * rest of the calls that follow — it is not re-fetched per request — so
     * every navigation-scope change is followed by re-binding a fresh copy,
     * or every subsequent request silently reads the stale preferences
     * captured at the earlier `actingAs()`/`signedInAs()` call.
     */
    private function setView(User $user, ?string $scope): void
    {
        $this->enterTenant();
        $user->forceFill(['preferences' => ['navigation.scope' => $scope]])->save();
        $this->actingAs($user->fresh());
    }

    private function footprint(): array
    {
        $this->enterTenant();

        return [
            'tasks' => Task::count(),
            'dependencies' => TaskDependency::count(),
            'activities' => TaskActivity::count(),
            'audits' => DB::table('audit_events')->count(),
            'events' => AutomationEvent::count(),
            'occurrences' => TaskRecurrenceOccurrence::count(),
        ];
    }

    private function enterTenant(): void
    {
        app(TenantContext::class)->set($this->alpha['org']);
    }

    protected function tearDown(): void
    {
        app(TenantContext::class)->forget();
        parent::tearDown();
    }
}
