<?php

namespace Tests\Feature\Tasks;

use App\Models\Assignment;
use App\Models\Company;
use App\Models\Franchise;
use App\Models\Role;
use App\Models\Task;
use App\Models\TaskBoard;
use App\Models\User;
use App\Support\Scoping\ViewScope;
use App\Support\Tasks\StatusCategory;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

class MyTasksApiTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    public function test_my_tasks_and_quick_search_use_current_view_visible_boards_without_private_leaks(): void
    {
        $alpha = $this->provisionTenant('Alpha');
        app(TenantContext::class)->set($alpha['org']);
        $this->artisan('ezbizi:sync-permissions')->assertSuccessful();
        $alpha['admin']->forceFill(['preferences' => [
            'navigation.scope' => ViewScope::COMPANY.':'.$alpha['company']->id,
        ]])->save();
        $worker = $this->userWithRole($alpha, 'Worker', ['tasks.view', 'tasks.edit']);
        $worker->forceFill(['preferences' => $alpha['admin']->preferences])->save();
        $this->actingAs($alpha['admin']->fresh());
        $visible = $this->createBoard('Visible board', 'scope');
        $private = $this->createBoard('Secret board', 'private');
        $privateMembershipId = $this->postJson("/api/v1/tasks/boards/{$private->id}/members", [
            'user_id' => $worker->id, 'role' => 'member',
        ])->assertCreated()->json('data.id');
        $owned = $this->createTask($visible, 'Owned visible task', $worker->id);
        $unowned = $this->createTask($visible, 'Searchable shared work', $alpha['admin']->id);
        $this->createTask($private, 'Secret task phrase', $worker->id);
        $this->deleteJson("/api/v1/tasks/boards/{$private->id}/members/{$privateMembershipId}")
            ->assertOk();

        $this->signedInAs($worker);
        $response = $this->getJson('/api/v1/tasks/my')->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.id', $owned->id);
        $etag = $response->headers->get('ETag');
        $this->assertNotNull($etag);
        $cached = $this->getJson('/api/v1/tasks/my', ['If-None-Match' => $etag]);
        $this->assertSame($etag, $cached->headers->get('ETag'));
        $cached->assertNoContent(304);

        app(TenantContext::class)->set($alpha['org']);
        $visible->forceFill(['name' => 'Renamed visible board'])->save();
        $this->getJson('/api/v1/tasks/my', ['If-None-Match' => $etag])->assertOk()
            ->assertJsonPath('data.0.board.name', 'Renamed visible board');

        $this->getJson('/api/v1/tasks/search?q=Searchable')->assertOk()
            ->assertJsonPath('data.0.task_id', $unowned->id)
            ->assertJsonPath('data.0.deep_link', "/tasks/boards/{$visible->id}/tasks/{$unowned->id}");
        $this->getJson('/api/v1/tasks/search?q=Secret')->assertOk()->assertJsonCount(0, 'data');
    }

    /**
     * Task 14: Calendar, Timeline and Workload open across every board the
     * current view lets the viewer already open — not owner-filtered the
     * way Table's `myTasks()` is. Two scope-visible boards prove "across
     * the view" rather than one board reused twice.
     */
    public function test_cross_board_calendar_timeline_and_workload_open_across_the_current_view(): void
    {
        $alpha = $this->provisionTenant('Alpha');
        app(TenantContext::class)->set($alpha['org']);
        $this->artisan('ezbizi:sync-permissions')->assertSuccessful();
        $alpha['admin']->forceFill(['preferences' => [
            'navigation.scope' => ViewScope::COMPANY.':'.$alpha['company']->id,
        ]])->save();
        $this->actingAs($alpha['admin']->fresh());
        $boardA = $this->createBoard('Board A', 'scope');
        $boardB = $this->createBoard('Board B', 'scope');
        $worker = $this->userWithRole($alpha, 'Worker', ['tasks.view', 'tasks.edit']);
        $worker->forceFill(['preferences' => [
            'navigation.scope' => ViewScope::COMPANY.':'.$alpha['company']->id,
        ]])->save();
        $today = now()->toDateString();
        $taskA = $this->createTaskDue($boardA, 'Task from board A', $worker->id, $today);
        $taskB = $this->createTaskDue($boardB, 'Task from board B', $alpha['admin']->id, $today);
        $month = now()->format('Y-m');
        $from = "{$month}-01";
        $to = now()->endOfMonth()->toDateString();

        $this->signedInAs($worker);

        $calendar = $this->getJson('/api/v1/tasks/my?'.http_build_query([
            'configuration' => json_encode(['presentation' => 'calendar', 'window' => ['from' => $from, 'to' => $to]]),
        ]))->assertOk();
        $calendarIds = collect($calendar->json('data'))->pluck('id')->sort()->values()->all();
        $this->assertSame(collect([$taskA->id, $taskB->id])->sort()->values()->all(), $calendarIds);

        $timeline = $this->getJson('/api/v1/tasks/my?'.http_build_query([
            'configuration' => json_encode(['presentation' => 'timeline', 'window' => ['from' => $from, 'to' => $to]]),
        ]))->assertOk();
        $timelineIds = collect($timeline->json('data'))->pluck('id')->sort()->values()->all();
        $this->assertSame(collect([$taskA->id, $taskB->id])->sort()->values()->all(), $timelineIds);

        $workload = $this->getJson('/api/v1/tasks/my?'.http_build_query([
            'configuration' => json_encode(['presentation' => 'workload', 'window' => ['from' => $from, 'to' => $to, 'unit' => 'day']]),
        ]))->assertOk();
        $ownerIds = collect($workload->json('data'))->pluck('owner_id')->sort()->values()->all();
        $this->assertSame(collect([$alpha['admin']->id, $worker->id])->sort()->values()->all(), $ownerIds);
    }

    /**
     * ONLY boards the viewer may already open come back — the exact leaks
     * `TaskBoardAccess::constrainVisible` (reused verbatim, not
     * reimplemented) exists to close: a board on a sibling company that is
     * reachable (present in `/me`'s `companies`) but outside the current
     * view; an organization-level board that a company-scoped reach does
     * not cover even while the current view claims Organization; and a
     * private board the viewer is not an active member of.
     */
    public function test_cross_board_presentations_exclude_sibling_company_organization_reach_and_private_non_member_boards(): void
    {
        $alpha = $this->provisionTenant('Alpha');
        app(TenantContext::class)->set($alpha['org']);
        $this->artisan('ezbizi:sync-permissions')->assertSuccessful();
        $today = now()->toDateString();
        $month = now()->format('Y-m');
        $from = "{$month}-01";
        $to = now()->endOfMonth()->toDateString();
        $window = ['from' => $from, 'to' => $to];

        // Created BEFORE any request runs as admin: `ScopeReach` is a
        // `scoped()` (request-lifetime) binding that this test's repeated
        // `postJson()` calls do not reboot, so it memoizes admin's reach the
        // first time anything asks. Creating the sibling company after that
        // first ask would leave it permanently absent from the memoized set
        // — indistinguishable from the real leak this test exists to prove
        // is closed, but for the wrong reason (a stale cache, not
        // `constrainVisible`). Building it first keeps the fixture honest.
        [$siblingCompany] = $this->secondCompany($alpha);

        // Visible board, in the current view, that the leak boards below get
        // compared against — the endpoint must still return this one.
        $alpha['admin']->forceFill(['preferences' => [
            'navigation.scope' => ViewScope::COMPANY.':'.$alpha['company']->id,
        ]])->save();
        $this->actingAs($alpha['admin']->fresh());
        $visible = $this->createBoard('Visible board', 'scope');
        $visibleTask = $this->createTaskDue($visible, 'Visible task', $alpha['admin']->id, $today);

        // `actingAs()` binds one PHP object instance to the auth guard for
        // the rest of the test — it is not re-fetched per request — so each
        // `forceFill(...)->save()` below is followed by a fresh `actingAs()`
        // call. Skipping that would leave every subsequent request reading
        // the STALE preferences captured at the first `actingAs()` call,
        // silently filing every "sibling"/"org" board at the alpha company
        // after all and making this test assert nothing about a leak.
        $adminPreferences = $alpha['admin']->preferences;
        $alpha['admin']->forceFill(['preferences' => [
            'navigation.scope' => ViewScope::COMPANY.':'.$siblingCompany->id,
        ]])->save();
        $this->actingAs($alpha['admin']->fresh());
        $siblingBoard = $this->createBoard('Sibling board', 'scope');
        $this->createTaskDue($siblingBoard, 'Sibling company task', $alpha['admin']->id, $today);

        // Organization-level board — only an Organization-scoped view with
        // reach that actually covers the organization sees it.
        $alpha['admin']->forceFill(['preferences' => ['navigation.scope' => null]])->save();
        $this->actingAs($alpha['admin']->fresh());
        $orgBoard = $this->createBoard('Org board', 'scope');
        $this->createTaskDue($orgBoard, 'Org level task', $alpha['admin']->id, $today);
        $alpha['admin']->forceFill(['preferences' => $adminPreferences])->save();
        $this->actingAs($alpha['admin']->fresh());

        // Private board the worker never joins.
        $private = $this->createBoard('Private board', 'private');
        $this->createTaskDue($private, 'Private task', $alpha['admin']->id, $today);

        // Org-wide reach (so the sibling company shows up in `/me`), current
        // view pinned at the alpha company — proves reach alone is not
        // enough, the CURRENT VIEW must also cover the board's place.
        $orgWideWorker = $this->userWithRole($alpha, 'Org-wide worker', ['tasks.view', 'tasks.edit']);
        $orgWideWorker->forceFill(['preferences' => [
            'navigation.scope' => ViewScope::COMPANY.':'.$alpha['company']->id,
        ]])->save();

        $this->signedInAs($orgWideWorker);
        $me = $this->getJson('/api/v1/me')->assertOk();
        $this->assertContains(
            $siblingCompany->id,
            collect($me->json('data.companies'))->pluck('id')->all(),
            'the sibling company must be reachable (present in /me) for this to be a real reach-vs-view leak.',
        );
        $calendar = $this->getJson('/api/v1/tasks/my?'.http_build_query([
            'configuration' => json_encode(['presentation' => 'calendar', 'window' => $window]),
        ]))->assertOk();
        $this->assertSame([$visibleTask->id], collect($calendar->json('data'))->pluck('id')->all());

        // Company-only reach, current view claims Organization — the same
        // access path (`directoryDecision`, reused verbatim from `myTasks()`)
        // refuses this OUTRIGHT before `constrainVisible` ever runs: reach
        // does not cover the view the request claims to be standing at, so
        // the whole endpoint is refused rather than silently omitting the
        // organization-level board from an otherwise-200 response. Either
        // shape would close the leak; this is the one this access path
        // actually takes, and a 200 with the org task present would be the
        // real bug this asserts is absent.
        //
        // The request above cleared tenant context on its way out (a
        // finishing request always does — see `createBoard()`'s own reset),
        // so it is restored explicitly before touching tenant data again.
        app(TenantContext::class)->set($alpha['org']);
        $companyOnlyWorker = $this->userAt($alpha, 'Company-only worker', Assignment::SCOPE_COMPANY, $alpha['company']->id);
        $companyOnlyWorker->forceFill(['preferences' => ['navigation.scope' => null]])->save();
        $this->signedInAs($companyOnlyWorker);
        $this->getJson('/api/v1/tasks/my?'.http_build_query([
            'configuration' => json_encode(['presentation' => 'calendar', 'window' => $window]),
        ]))->assertForbidden();
    }

    /**
     * A membership revoked mid-session stops returning that board's rows —
     * the exact race Phase 1's private-membership check was built to close,
     * now exercised through the cross-board path instead of a single board.
     */
    public function test_cross_board_presentation_membership_revocation_mid_session_stops_returning_that_boards_rows(): void
    {
        $alpha = $this->provisionTenant('Alpha');
        app(TenantContext::class)->set($alpha['org']);
        $this->artisan('ezbizi:sync-permissions')->assertSuccessful();
        $alpha['admin']->forceFill(['preferences' => [
            'navigation.scope' => ViewScope::COMPANY.':'.$alpha['company']->id,
        ]])->save();
        $worker = $this->userWithRole($alpha, 'Worker', ['tasks.view', 'tasks.edit']);
        $worker->forceFill(['preferences' => $alpha['admin']->preferences])->save();
        $this->actingAs($alpha['admin']->fresh());
        $private = $this->createBoard('Private board', 'private');
        $membershipId = $this->postJson("/api/v1/tasks/boards/{$private->id}/members", [
            'user_id' => $worker->id, 'role' => 'member',
        ])->assertCreated()->json('data.id');
        $today = now()->toDateString();
        $task = $this->createTaskDue($private, 'Private task', $worker->id, $today);
        $month = now()->format('Y-m');
        $window = ['from' => "{$month}-01", 'to' => now()->endOfMonth()->toDateString()];

        $this->signedInAs($worker);
        $before = $this->getJson('/api/v1/tasks/my?'.http_build_query([
            'configuration' => json_encode(['presentation' => 'calendar', 'window' => $window]),
        ]))->assertOk();
        $this->assertSame([$task->id], collect($before->json('data'))->pluck('id')->all());

        // Revoked by the board owner mid-session — the worker itself only
        // holds `tasks.edit`, not membership management, so this must run
        // as admin, not as the still-signed-in worker.
        $this->actingAs($alpha['admin']->fresh());
        app(TenantContext::class)->set($alpha['org']);
        $this->deleteJson("/api/v1/tasks/boards/{$private->id}/members/{$membershipId}")->assertOk();

        $this->signedInAs($worker);
        $after = $this->getJson('/api/v1/tasks/my?'.http_build_query([
            'configuration' => json_encode(['presentation' => 'calendar', 'window' => $window]),
        ]))->assertOk();
        $this->assertSame([], collect($after->json('data'))->pluck('id')->all());
    }

    private function createBoard(string $name, string $visibility): TaskBoard
    {
        $id = $this->postJson('/api/v1/tasks/boards', compact('name', 'visibility'))->assertCreated()->json('data.id');
        app(TenantContext::class)->set(auth()->user()->organization);

        return TaskBoard::findOrFail($id);
    }

    private function createTask(TaskBoard $board, string $title, string $ownerId): Task
    {
        app(TenantContext::class)->set(auth()->user()->organization);
        $status = $board->statusOptions()->active()->where('category', StatusCategory::NotStarted)->firstOrFail();
        $group = $board->groups()->active()->firstOrFail();
        $id = $this->postJson("/api/v1/tasks/boards/{$board->id}/tasks", [
            'title' => $title, 'owner_user_id' => $ownerId,
            'group_id' => $group->id, 'status_option_id' => $status->id,
        ])->assertCreated()->json('data.id');
        app(TenantContext::class)->set(auth()->user()->organization);

        return Task::findOrFail($id);
    }

    private function createTaskDue(TaskBoard $board, string $title, string $ownerId, string $dueOn): Task
    {
        app(TenantContext::class)->set(auth()->user()->organization);
        $status = $board->statusOptions()->active()->where('category', StatusCategory::NotStarted)->firstOrFail();
        $group = $board->groups()->active()->firstOrFail();
        $id = $this->postJson("/api/v1/tasks/boards/{$board->id}/tasks", [
            'title' => $title, 'owner_user_id' => $ownerId,
            'group_id' => $group->id, 'status_option_id' => $status->id,
            'due_on' => $dueOn,
        ])->assertCreated()->json('data.id');
        app(TenantContext::class)->set(auth()->user()->organization);

        return Task::findOrFail($id);
    }

    /** A second Company (plus a franchise under it) in the same organization. */
    private function secondCompany(array $tenant): array
    {
        return $this->inTenant($tenant['org'], function () {
            $company = Company::create(['name' => 'Sibling Co', 'is_active' => true]);
            $franchise = Franchise::create([
                'company_id' => $company->id,
                'name' => 'Sibling F1',
                'timezone' => 'America/New_York',
                'currency' => 'USD',
                'is_active' => true,
            ]);

            return [$company, $franchise];
        });
    }

    /**
     * A user whose assignment reach is EXACTLY [$scope, $scopeId] — unlike
     * `BuildsTenants::userWithRole()`, which is always organization-wide
     * unless given a single franchise. Needed here to build a user whose
     * reach is company-only, so the organization-view leak test is actually
     * about reach and not about a franchise-narrower shortcut.
     */
    private function userAt(array $tenant, string $name, string $scope, string $scopeId): User
    {
        return $this->inTenant($tenant['org'], function () use ($tenant, $name, $scope, $scopeId) {
            $role = Role::create(['organization_id' => $tenant['org']->id, 'name' => $name, 'is_system' => false]);
            $role->syncPermissionKeys(['tasks.view', 'tasks.edit']);
            $user = User::create([
                'organization_id' => $tenant['org']->id,
                'role_id' => $role->id,
                'name' => $name,
                'email' => strtolower(str_replace(' ', '.', $name)).'@'.$tenant['org']->slug.'.test',
                'password' => 'a-real-password',
                'is_active' => true,
            ]);
            Assignment::create([
                'organization_id' => $tenant['org']->id,
                'user_id' => $user->id,
                match ($scope) {
                    Assignment::SCOPE_ORGANIZATION => 'assigned_organization_id',
                    Assignment::SCOPE_COMPANY => 'assigned_company_id',
                    default => 'assigned_franchise_id',
                } => $scopeId,
            ]);

            return $user->fresh(['role.permissions', 'assignments.assignedCompany']);
        });
    }

    protected function tearDown(): void
    {
        app(TenantContext::class)->forget();
        parent::tearDown();
    }
}
