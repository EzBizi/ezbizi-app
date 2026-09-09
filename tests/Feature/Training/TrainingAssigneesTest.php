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

    /**
     * `AssignmentsController::userCandidates` used to `limit(50)` BEFORE
     * filtering by §4 reach, so if a supervisor's own reachable crew
     * happened to sort alphabetically LATE, and 50-or-more unreachable
     * people in the organization sorted before them, the query's own 50
     * rows could be entirely unreachable people — filtering left nothing,
     * and the picker came back empty with no signal anything was ever
     * cut. Riverside's crew is named to sort after 55 Northgate names, so
     * the old `limit(50)` — applied before the filter — would have
     * fetched only Northgate rows and returned zero Riverside names here.
     */
    public function test_a_reachable_crew_sorting_late_alphabetically_is_not_cut_by_the_display_limit(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $riverside = $tenant['franchise'];
        $northgate = $this->extraFranchise($tenant, 'Northgate');

        $supervisor = $this->userWithRole($tenant, 'Supervisor', ['training.assign'], onlyAt: $riverside);

        // 55 unreachable people, sorting alphabetically BEFORE the
        // reachable crew below — enough on their own to fill the old
        // pre-filter limit(50).
        foreach (range(1, 55) as $n) {
            $this->userWithRole(
                $tenant,
                sprintf('AAA Northgate %02d', $n),
                [],
                email: sprintf('northgate%02d@%s.test', $n, $tenant['org']->slug),
                onlyAt: $northgate,
            );
        }

        $this->userWithRole($tenant, 'ZZZ Riverside Crew', [], onlyAt: $riverside);

        $response = $this->signedInAs($supervisor)
            ->getJson('/api/v1/training/assignees?type=user')
            ->assertOk();

        $names = collect($response->json('data'))->pluck('name');

        $this->assertTrue(
            $names->contains('ZZZ Riverside Crew'),
            'A reachable person must not be excluded just because they sort late.',
        );
        $this->assertFalse($response->json('truncated'), '57 candidates, none of it should need truncating.');
    }

    private function publishedGuide(array $tenant, $franchise): \App\Models\TrainingGuide
    {
        return $this->inTenant($tenant['org'], fn () => \App\Models\TrainingGuide::create([
            'company_id' => $tenant['company']->id, 'franchise_id' => $franchise->id,
            'title' => 'Ladder safety', 'status' => \App\Models\TrainingGuide::PUBLISHED, 'screens' => [],
        ]));
    }
}
