<?php

namespace Tests\Feature\Monitoring;

use App\Models\Company;
use App\Models\Franchise;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/**
 * GET /api/v1/monitoring/assignable-people — the people a floor-plan EDITOR may
 * put on a tile (2026-09-09-monitoring-general-map, Task 7a).
 *
 * Its whole reason to exist is the gate: the editor picker cannot read
 * `hr/people`, which is gated on HR grants a Monitoring editor has no reason to
 * hold, so asking for the roster there would either force an unrelated
 * permission onto every map editor or hand back a 403 with nothing to show. The
 * endpoint answers exactly the question the picker asks — "who may I assign" —
 * behind exactly the grant the assignment itself needs (`monitoring.map.manage`),
 * over exactly the predicate the write endpoint validates against
 * (`ScopeReach::constrainToVisiblePeople`). The list and the refusal are the
 * same rule, so a name in this list can always be saved and one that is missing
 * would always have been refused.
 *
 * The load-bearing test is the reach one, and it is a paired assertion: a person
 * inside the actor's reach is PRESENT (the positive control — without it a
 * broken query that returned nobody would read exactly like a working filter)
 * and a sibling company's person is ABSENT.
 */
class FloorPlanPeopleTest extends TestCase
{
    use BuildsTenants, RefreshDatabase;

    /** A user assigned at COMPANY level with the given grants. */
    private function companyManager(array $tenant, Company $company, string $name, array $perms = ['monitoring.map.manage']): User
    {
        return $this->inTenant($tenant['org'], function () use ($tenant, $company, $name, $perms) {
            $role = Role::create(['organization_id' => $tenant['org']->id, 'name' => $name, 'is_system' => false]);
            $role->syncPermissionKeys($perms);

            $user = User::create([
                'organization_id' => $tenant['org']->id,
                'role_id' => $role->id,
                'name' => $name,
                'email' => strtolower(preg_replace('/[^a-z0-9]/i', '', $name)).'@'.$tenant['org']->slug.'.test',
                'password' => 'a-real-password',
                'is_active' => true,
            ]);

            \App\Models\Assignment::create([
                'organization_id' => $tenant['org']->id,
                'user_id' => $user->id,
                'assigned_company_id' => $company->getKey(),
            ]);

            return $user;
        });
    }

    public function test_it_lists_people_within_reach_and_not_a_sibling_companys_person(): void
    {
        $alpha = $this->provisionTenant('Alpha');
        $companyA = $alpha['company'];
        $franchiseA = $alpha['franchise'];

        // A second company in the same org, with its own franchise and person.
        $franchiseB = $this->inTenant($alpha['org'], function () use ($alpha) {
            $companyB = Company::create([
                'organization_id' => $alpha['org']->id, 'name' => 'Company B', 'is_active' => true,
            ]);

            return Franchise::create([
                'organization_id' => $alpha['org']->id, 'company_id' => $companyB->id,
                'name' => 'B F1', 'is_active' => true,
            ]);
        });

        $inReach = $this->userWithRole($alpha, 'Reachable Person', [], onlyAt: $franchiseA);
        $sibling = $this->userWithRole($alpha, 'Sibling Person', [], onlyAt: $franchiseB);

        $manager = $this->companyManager($alpha, $companyA, 'Company A Mgr');

        $rows = $this->signedInAs($manager)
            ->getJson('/api/v1/monitoring/assignable-people')
            ->assertOk()
            ->json('data');

        $ids = array_column($rows, 'id');

        // The positive control: without it, a query that returned nobody at all
        // would satisfy the absence assertion below and look like a filter.
        $this->assertContains($inReach->getKey(), $ids, 'a person inside the actor reach must be offered');
        $this->assertContains($manager->getKey(), $ids, 'the actor is always someone they can see');
        $this->assertNotContains($sibling->getKey(), $ids, 'a sibling company person is outside reach');

        // The row shape the picker reads: id + name, nothing else.
        $row = collect($rows)->firstWhere('id', $inReach->getKey());
        $this->assertSame(['id', 'name'], array_keys($row));
        $this->assertSame('Reachable Person', $row['name']);
    }

    public function test_without_map_manage_it_is_refused_with_a_sentence(): void
    {
        $alpha = $this->provisionTenant('Alpha');

        // A view-only monitoring user: the map is readable, the picker is not —
        // they cannot assign anybody, so the roster is not theirs to read.
        $viewer = $this->userWithRole($alpha, 'Viewer', ['monitoring.view']);

        $this->signedInAs($viewer)
            ->getJson('/api/v1/monitoring/assignable-people')
            ->assertForbidden()
            ->assertJsonStructure(['message']);

        // The control: the same request WITH the grant is allowed, so the 403
        // above is the grant talking and not a broken route.
        $editor = $this->userWithRole($alpha, 'Editor', ['monitoring.view', 'monitoring.map.manage']);

        $this->signedInAs($editor)
            ->getJson('/api/v1/monitoring/assignable-people')
            ->assertOk()
            ->assertJsonStructure(['data' => [['id', 'name']]]);
    }
}
