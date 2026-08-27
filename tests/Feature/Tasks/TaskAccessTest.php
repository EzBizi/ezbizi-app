<?php

namespace Tests\Feature\Tasks;

use App\Models\Assignment;
use App\Models\Company;
use App\Models\Franchise;
use App\Models\Role;
use App\Models\Task;
use App\Models\TaskBoard;
use App\Models\TaskBoardMember;
use App\Models\TaskUpdate;
use App\Models\User;
use App\Policies\TaskBoardPolicy;
use App\Policies\TaskPolicy;
use App\Policies\TaskUpdatePolicy;
use App\Support\Access\ScopeReach;
use App\Support\Scoping\ViewScope;
use App\Support\Tenancy\TenantContext;
use App\Support\Tasks\TaskBoardAccess;
use App\Support\Tasks\TaskParticipantEligibility;
use App\Support\Tasks\TaskPlace;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

class TaskAccessTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    private array $alpha;

    private int $position = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $this->alpha = $this->provisionTenant('Alpha');
        app(TenantContext::class)->set($this->alpha['org']);
        $this->artisan('ezbizi:sync-permissions')->assertSuccessful();
    }

    protected function tearDown(): void
    {
        app(TenantContext::class)->forget();
        parent::tearDown();
    }

    public function test_task_place_uses_the_exact_active_view_and_independent_assignment_reach(): void
    {
        $organizationUser = $this->userAt('Org manager', Assignment::SCOPE_ORGANIZATION, $this->alpha['org']->id);
        $companyUser = $this->userAt('Company manager', Assignment::SCOPE_COMPANY, $this->alpha['company']->id);
        $franchiseUser = $this->userAt('Branch manager', Assignment::SCOPE_FRANCHISE, $this->alpha['franchise']->id);

        $organizationView = $this->lookingAt($organizationUser, ViewScope::ORGANIZATION);
        $companyView = $this->lookingAt($companyUser, ViewScope::COMPANY, $this->alpha['company']->id);
        $franchiseView = $this->lookingAt($franchiseUser, ViewScope::FRANCHISE, $this->alpha['franchise']->id);

        $this->assertSame([
            'organization_id' => $this->alpha['org']->id,
            'company_id' => null,
            'franchise_id' => null,
        ], TaskPlace::fromView($organizationView));
        $this->assertSame($this->alpha['company']->id, TaskPlace::fromView($companyView)['company_id']);
        $this->assertSame([
            'organization_id' => $this->alpha['org']->id,
            'company_id' => $this->alpha['company']->id,
            'franchise_id' => $this->alpha['franchise']->id,
        ], TaskPlace::fromView($franchiseView));

        $organizationBoard = $this->board(null, null);
        $companyBoard = $this->board($this->alpha['company'], null);
        $franchiseBoard = $this->board($this->alpha['company'], $this->alpha['franchise']);
        $reach = app(ScopeReach::class);

        $this->assertTrue(TaskPlace::coveredBy($organizationUser, $organizationBoard, $organizationView, $reach));
        $this->assertFalse(TaskPlace::coveredBy($companyUser, $organizationBoard, $companyView, $reach));
        $this->assertTrue(TaskPlace::coveredBy($companyUser, $companyBoard, $companyView, $reach));
        $this->assertFalse(TaskPlace::coveredBy($franchiseUser, $companyBoard, $franchiseView, $reach));
        $this->assertTrue(TaskPlace::coveredBy($franchiseUser, $franchiseBoard, $franchiseView, $reach));
    }

    public function test_visible_query_intersects_current_view_reach_and_private_membership_before_loading(): void
    {
        [$siblingCompany, $siblingFranchise] = $this->secondCompany();
        [$descendantCompany, $descendantFranchise] = $this->subCompany();
        $user = $this->userAt('Wide manager', Assignment::SCOPE_ORGANIZATION, $this->alpha['org']->id);
        $view = $this->lookingAt($user, ViewScope::COMPANY, $this->alpha['company']->id);

        $visible = $this->board($this->alpha['company'], $this->alpha['franchise'], 'scope', 'Visible');
        $descendant = $this->board($descendantCompany, $descendantFranchise, 'scope', 'Descendant');
        $privateMember = $this->board($this->alpha['company'], $this->alpha['franchise'], 'private', 'Private member');
        $this->member($privateMember, $user, 'member');
        $this->board($this->alpha['company'], $this->alpha['franchise'], 'private', 'Private stranger');
        $this->board($siblingCompany, $siblingFranchise, 'scope', 'Sibling');

        $query = TaskBoard::query();
        app(TaskBoardAccess::class)->constrainVisible($query, $user, $view);

        $this->assertSame(
            collect([$descendant->id, $privateMember->id, $visible->id])->sort()->values()->all(),
            $query->orderBy('name')->pluck('id')->sort()->values()->all(),
        );
    }

    public function test_private_access_and_management_require_membership_owner_and_permissions(): void
    {
        $owner = $this->userAt('Owner', Assignment::SCOPE_COMPANY, $this->alpha['company']->id);
        $member = $this->userAt('Member', Assignment::SCOPE_COMPANY, $this->alpha['company']->id);
        $stranger = $this->userAt('Stranger', Assignment::SCOPE_COMPANY, $this->alpha['company']->id);
        $board = $this->board($this->alpha['company'], null, 'private');
        $this->member($board, $owner, 'owner');
        $this->member($board, $member, 'member');
        $access = app(TaskBoardAccess::class);

        foreach ([$owner, $member, $stranger] as $user) {
            $this->lookingAt($user, ViewScope::COMPANY, $this->alpha['company']->id);
        }

        $this->assertTrue($access->viewDecision($owner, $board, ViewScope::forUser($owner))->allowed());
        $this->assertTrue($access->viewDecision($member, $board, ViewScope::forUser($member))->allowed());
        $this->assertFalse($access->viewDecision($stranger, $board, ViewScope::forUser($stranger))->allowed());
        $this->assertTrue($access->manageDecision($owner, $board, ViewScope::forUser($owner))->allowed());
        $this->assertFalse($access->manageDecision($member, $board, ViewScope::forUser($member))->allowed());
    }

    public function test_background_access_ignores_ui_scope_but_never_reach_or_private_membership(): void
    {
        [$siblingCompany, $siblingFranchise] = $this->secondCompany();
        $user = $this->userAt('Wide worker', Assignment::SCOPE_ORGANIZATION, $this->alpha['org']->id);
        $this->lookingAt($user, ViewScope::COMPANY, $this->alpha['company']->id);
        $sibling = $this->board($siblingCompany, $siblingFranchise, 'scope');
        $private = $this->board($siblingCompany, $siblingFranchise, 'private');
        $access = app(TaskBoardAccess::class);

        $this->assertFalse($access->viewDecision($user, $sibling, ViewScope::forUser($user))->allowed());
        $this->assertTrue($access->backgroundViewDecision($user, $sibling)->allowed());
        $this->assertFalse($access->backgroundViewDecision($user, $private)->allowed());

        $this->member($private, $user, 'member');
        $this->assertTrue($access->backgroundViewDecision($user, $private)->allowed());
    }

    public function test_creation_does_not_trust_organization_fallback_or_raw_reach(): void
    {
        $companyUser = $this->userAt('Company creator', Assignment::SCOPE_COMPANY, $this->alpha['company']->id);
        $access = app(TaskBoardAccess::class);

        $companyView = $this->lookingAt($companyUser, ViewScope::COMPANY, $this->alpha['company']->id);
        $this->assertTrue($access->createDecision($companyUser, $companyView)->allowed());

        // An invalid saved scope falls back to organization view. Company reach
        // must not turn that fallback into authority to file at the organization.
        $organizationFallback = $this->lookingAt($companyUser, ViewScope::COMPANY, '01999999-9999-7999-8999-999999999999');
        $this->assertTrue($organizationFallback->isOrganization());
        $this->assertFalse($access->createDecision($companyUser, $organizationFallback)->allowed());

        $withoutManage = $this->userAt(
            'Viewer', Assignment::SCOPE_COMPANY, $this->alpha['company']->id, ['tasks.view'],
        );
        $view = $this->lookingAt($withoutManage, ViewScope::COMPANY, $this->alpha['company']->id);
        $this->assertFalse($access->createDecision($withoutManage, $view)->allowed());
    }

    public function test_participant_eligibility_does_not_create_private_membership(): void
    {
        $candidate = $this->userAt('Candidate', Assignment::SCOPE_COMPANY, $this->alpha['company']->id);
        $scopeBoard = $this->board($this->alpha['company'], null, 'scope');
        $privateBoard = $this->board($this->alpha['company'], null, 'private');
        $eligibility = app(TaskParticipantEligibility::class);

        $this->assertTrue($eligibility->forMembership($candidate, $privateBoard)->allowed());
        $this->assertFalse($eligibility->forTaskParticipation($candidate, $privateBoard)->allowed());
        $this->assertTrue($eligibility->forTaskParticipation($candidate, $scopeBoard)->allowed());
        $this->assertFalse(TaskBoardMember::where('board_id', $privateBoard->id)->exists());

        $this->member($privateBoard, $candidate, 'member');
        $this->assertTrue($eligibility->forTaskParticipation($candidate, $privateBoard)->allowed());

        $candidate->forceFill(['is_active' => false])->save();
        $this->assertFalse($eligibility->forMembership($candidate->fresh(), $privateBoard)->allowed());
    }

    public function test_an_administrator_can_recover_an_orphan_without_gaining_board_access(): void
    {
        $oldOwner = $this->userAt('Former owner', Assignment::SCOPE_COMPANY, $this->alpha['company']->id);
        $newOwner = $this->userAt('Replacement owner', Assignment::SCOPE_COMPANY, $this->alpha['company']->id);
        $board = $this->board($this->alpha['company'], null, 'private', 'Secret recovery board');
        $this->member($board, $oldOwner, 'owner');
        $oldOwner->forceFill(['is_active' => false])->save();

        $result = app(TaskBoardAccess::class)->recoverOrphanedOwner(
            $this->alpha['admin']->fresh(),
            $board,
            $newOwner,
        );

        $this->assertSame(['board_id' => $board->id, 'owner_user_id' => $newOwner->id], $result);
        $this->assertTrue(TaskBoardMember::active()->where('board_id', $board->id)
            ->where('user_id', $newOwner->id)->where('role', 'owner')->exists());
        $this->assertFalse(TaskBoardMember::where('board_id', $board->id)
            ->where('user_id', $this->alpha['admin']->id)->exists());
        $this->assertFalse(app(TaskBoardAccess::class)->backgroundViewDecision(
            $this->alpha['admin']->fresh(), $board,
        )->allowed());

        $audit = DB::table('audit_events')->where('action', 'task_board.owner_recovered')->first();
        $this->assertNotNull($audit);
        $this->assertStringNotContainsString('Secret recovery board', (string) $audit->metadata);
    }

    public function test_an_administrator_cannot_nominate_themselves_during_recovery(): void
    {
        $oldOwner = $this->userAt('Unavailable owner', Assignment::SCOPE_COMPANY, $this->alpha['company']->id);
        $board = $this->board($this->alpha['company'], null, 'private');
        $this->member($board, $oldOwner, 'owner');
        $oldOwner->forceFill(['is_active' => false])->save();

        try {
            app(TaskBoardAccess::class)->recoverOrphanedOwner(
                $this->alpha['admin']->fresh(),
                $board,
                $this->alpha['admin']->fresh(),
            );
            $this->fail('Recovery accepted the acting Administrator as the new owner.');
        } catch (AuthorizationException $exception) {
            $this->assertStringContainsString('another eligible owner', $exception->getMessage());
        }

        $this->assertFalse(TaskBoardMember::where('board_id', $board->id)
            ->where('user_id', $this->alpha['admin']->id)->exists());
    }

    public function test_manage_only_is_sufficient_for_an_owner_to_administer_a_board(): void
    {
        $manager = $this->userAt(
            'Board manager',
            Assignment::SCOPE_COMPANY,
            $this->alpha['company']->id,
            ['tasks.manage'],
        );
        $this->lookingAt($manager, ViewScope::COMPANY, $this->alpha['company']->id);
        $board = $this->board($this->alpha['company'], null, 'private');
        $this->member($board, $manager, 'owner');

        $this->assertTrue(app(TaskBoardPolicy::class)->update($manager, $board)->allowed());
        $this->assertTrue(app(TaskBoardPolicy::class)->delete($manager, $board)->allowed());
    }

    public function test_task_policies_preserve_access_decisions_and_each_permission_boundary(): void
    {
        $user = $this->userAt('Policy owner', Assignment::SCOPE_COMPANY, $this->alpha['company']->id);
        $this->lookingAt($user, ViewScope::COMPANY, $this->alpha['company']->id);
        $board = $this->board($this->alpha['company'], null, 'private');
        $this->member($board, $user, 'owner');

        $task = new Task;
        $task->forceFill(['organization_id' => $this->alpha['org']->id]);
        $task->setRelation('board', $board);
        $update = new TaskUpdate;
        $update->setRelation('task', $task);

        $this->assertTrue(app(TaskBoardPolicy::class)->update($user, $board)->allowed());
        $this->assertTrue(app(TaskPolicy::class)->comment($user, $task)->allowed());
        $this->assertTrue(app(TaskPolicy::class)->export($user, $task)->allowed());
        $this->assertTrue(app(TaskUpdatePolicy::class)->view($user, $update)->allowed());
    }

    public function test_each_tasks_permission_remains_an_independent_policy_boundary(): void
    {
        $board = $this->board($this->alpha['company'], null, 'private');
        $all = ['tasks.view', 'tasks.create', 'tasks.edit', 'tasks.comment', 'tasks.delete', 'tasks.export', 'tasks.manage'];

        $withoutView = $this->userAt(
            'Without Tasks',
            Assignment::SCOPE_COMPANY,
            $this->alpha['company']->id,
            [],
        );
        $this->lookingAt($withoutView, ViewScope::COMPANY, $this->alpha['company']->id);
        $this->member($board, $withoutView, 'owner');
        $this->assertFalse(app(TaskBoardPolicy::class)->viewAny($withoutView)->allowed());
        $this->assertFalse(app(TaskBoardPolicy::class)->view($withoutView, $board)->allowed());

        foreach (array_values(array_diff($all, ['tasks.view'])) as $missing) {
            $user = $this->userAt(
                'Missing '.substr($missing, strlen('tasks.')),
                Assignment::SCOPE_COMPANY,
                $this->alpha['company']->id,
                array_values(array_diff($all, [$missing])),
            );
            $this->lookingAt($user, ViewScope::COMPANY, $this->alpha['company']->id);
            $this->member($board, $user, 'owner');

            $task = new Task;
            $task->forceFill(['organization_id' => $this->alpha['org']->id]);
            $task->setRelation('board', $board);

            $decision = match ($missing) {
                'tasks.view' => app(TaskBoardPolicy::class)->view($user, $board),
                'tasks.create' => app(TaskPolicy::class)->create($user, $board),
                'tasks.edit' => app(TaskPolicy::class)->update($user, $task),
                'tasks.comment' => app(TaskPolicy::class)->comment($user, $task),
                'tasks.delete' => app(TaskPolicy::class)->delete($user, $task),
                'tasks.export' => app(TaskPolicy::class)->export($user, $task),
                'tasks.manage' => app(TaskBoardPolicy::class)->update($user, $board),
            };

            $this->assertFalse($decision->allowed(), "{$missing} was bypassed.");
        }
    }

    private function userAt(
        string $name,
        string $scope,
        string $scopeId,
        array $permissions = ['tasks.view', 'tasks.create', 'tasks.edit', 'tasks.comment', 'tasks.delete', 'tasks.export', 'tasks.manage'],
    ): User {
        return $this->inTenant($this->alpha['org'], function () use ($name, $scope, $scopeId, $permissions) {
            $role = Role::create(['name' => $name, 'is_system' => false]);
            $role->syncPermissionKeys($permissions);
            $user = User::create([
                'role_id' => $role->id,
                'name' => $name,
                'email' => strtolower(str_replace(' ', '.', $name)).'@alpha.test',
                'password' => 'a-real-password',
                'is_active' => true,
            ]);
            Assignment::create([
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

    private function lookingAt(User $user, string $type, ?string $id = null): ViewScope
    {
        $value = $type === ViewScope::ORGANIZATION ? null : "{$type}:{$id}";
        $user->forceFill(['preferences' => array_filter(['navigation.scope' => $value])])->save();

        return ViewScope::forUser($user->fresh(['role.permissions', 'assignments.assignedCompany']));
    }

    private function board(?Company $company, ?Franchise $franchise, string $visibility = 'scope', string $name = 'Board'): TaskBoard
    {
        return $this->inTenant($this->alpha['org'], function () use ($company, $franchise, $visibility, $name) {
            $board = new TaskBoard;
            $board->forceFill([
                'organization_id' => $this->alpha['org']->id,
                'company_id' => $company?->id,
                'franchise_id' => $franchise?->id,
                'name' => $name.' '.$this->position,
                'visibility' => $visibility,
                'position' => $this->position++,
                'created_by' => $this->alpha['admin']->id,
                'updated_by' => $this->alpha['admin']->id,
            ])->save();

            return $board;
        });
    }

    private function member(TaskBoard $board, User $user, string $role): TaskBoardMember
    {
        return $this->inTenant($this->alpha['org'], fn () => TaskBoardMember::create([
            'board_id' => $board->id,
            'user_id' => $user->id,
            'role' => $role,
            'is_active' => true,
        ]));
    }

    /** @return array{Company, Franchise} */
    private function secondCompany(): array
    {
        return $this->inTenant($this->alpha['org'], function () {
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

    /** @return array{Company, Franchise} */
    private function subCompany(): array
    {
        return $this->inTenant($this->alpha['org'], function () {
            $company = Company::create([
                'parent_company_id' => $this->alpha['company']->id,
                'name' => 'Descendant Co',
                'is_active' => true,
            ]);
            $franchise = Franchise::create([
                'company_id' => $company->id,
                'name' => 'Descendant F1',
                'timezone' => 'America/New_York',
                'currency' => 'USD',
                'is_active' => true,
            ]);

            return [$company, $franchise];
        });
    }
}
