<?php

namespace Tests\Feature\Training;

use App\Models\Role;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/**
 * `GET /training/assignees` (`AssignmentsController::assignees`) — the read
 * endpoint the missing-assign-control finding's fix (Part B,
 * audit-fixes-report.md) needs to exist before the client's picker can name
 * anybody at all. Gated on `training.assign` alone, never `hr.view` or
 * `roles.view` — see the controller method's own docblock.
 */
class TrainingAssigneesTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    public function test_requires_training_assign(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $noAccess = $this->userWithRole($tenant, 'NoAccess', ['training.view'], onlyAt: $tenant['franchise']);

        $this->signedInAs($noAccess)
            ->getJson('/api/v1/training/assignees?type=user')
            ->assertForbidden();
    }

    public function test_type_user_returns_only_people_within_the_assigners_reach(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $riverside = $tenant['franchise'];
        $northgate = $this->extraFranchise($tenant, 'Northgate');

        $supervisor = $this->userWithRole($tenant, 'Supervisor', ['training.assign'], onlyAt: $riverside);
        $crewMember = $this->userWithRole($tenant, 'RiversideCrew', [], onlyAt: $riverside);
        $sibling = $this->userWithRole($tenant, 'NorthgateCrew', [], onlyAt: $northgate);

        $names = collect(
            $this->signedInAs($supervisor)
                ->getJson('/api/v1/training/assignees?type=user')
                ->assertOk()
                ->json('data'),
        )->pluck('name');

        $this->assertTrue($names->contains('RiversideCrew'));
        $this->assertFalse($names->contains('NorthgateCrew'), 'A sibling franchise\'s crew must never be offered.');

        // Never offering an option the write endpoint would then refuse —
        // see AssignmentWriter's own "ONE write path" docblock.
        $this->signedInAs($supervisor)
            ->postJson('/api/v1/training/assignments', [
                'guide_id' => $this->publishedGuide($tenant, $riverside)->id,
                'assignee_type' => 'user',
                'assignee_id' => $sibling->id,
            ])
            ->assertForbidden();
    }

    public function test_type_user_search_filters_by_name(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $supervisor = $this->userWithRole($tenant, 'Supervisor', ['training.assign']);
        $this->userWithRole($tenant, 'Alice', [], email: 'alice@'.$tenant['org']->slug.'.test');
        $this->userWithRole($tenant, 'Bob', [], email: 'bob@'.$tenant['org']->slug.'.test');

        $names = collect(
            $this->signedInAs($supervisor)
                ->getJson('/api/v1/training/assignees?type=user&search=ali')
                ->assertOk()
                ->json('data'),
        )->pluck('name');

        $this->assertTrue($names->contains('Alice'));
        $this->assertFalse($names->contains('Bob'));
    }

    public function test_type_role_is_marked_assignable_only_with_organization_reach(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $riverside = $tenant['franchise'];

        $this->inTenant($tenant['org'], fn () => Role::create([
            'organization_id' => $tenant['org']->id, 'name' => 'Crew', 'is_system' => false,
        ]));

        $orgSupervisor = $this->userWithRole($tenant, 'OrgSupervisor', ['training.assign']);
        $franchiseSupervisor = $this->userWithRole($tenant, 'LocalSupervisor', ['training.assign'], onlyAt: $riverside);

        $orgRows = collect(
            $this->signedInAs($orgSupervisor)
                ->getJson('/api/v1/training/assignees?type=role')
                ->assertOk()
                ->json('data'),
        );
        $this->assertTrue($orgRows->every(fn ($row) => $row['assignable'] === true));
        $this->assertTrue($orgRows->pluck('name')->contains('Crew'));

        $franchiseRows = collect(
            $this->signedInAs($franchiseSupervisor)
                ->getJson('/api/v1/training/assignees?type=role')
                ->assertOk()
                ->json('data'),
        );
        // Still LISTED — marked, not hidden, same as `HrPeopleController`'s
        // own role list (this method's own docblock).
        $this->assertTrue($franchiseRows->pluck('name')->contains('Crew'));
        $this->assertTrue($franchiseRows->every(fn ($row) => $row['assignable'] === false));
    }

    private function publishedGuide(array $tenant, $franchise): \App\Models\TrainingGuide
    {
        return $this->inTenant($tenant['org'], fn () => \App\Models\TrainingGuide::create([
            'company_id' => $tenant['company']->id, 'franchise_id' => $franchise->id,
            'title' => 'Ladder safety', 'status' => \App\Models\TrainingGuide::PUBLISHED, 'screens' => [],
        ]));
    }
}
