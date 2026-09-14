<?php

namespace Tests\Feature\Api;

use App\Models\Company;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/**
 * Making a company a sub company, and making a sub company a company again.
 *
 * ── Why this exists, and what it overturns ───────────────────────
 *
 * §3 of the build plan answered "Can entities be moved between parents?" with a
 * flat **No**, and `StructureTest` pinned it. Jose asked for it on 2026-09-14,
 * from the case that actually happens: he created Crestline Home as a company
 * when it should have been a sub company, and there was no way back short of
 * deleting a branch of a live business.
 *
 * The rule's REASON was never wrong — "every setting, assignment and roll-up
 * below a company is expressed in terms of where it sits, so a move silently
 * rewrites history" — and nothing here makes that untrue. It is why the act is
 * organization-level, audited, and raised as an event rather than quietly
 * saved. What changed is the judgement that the cost of an unfixable mistake is
 * higher than the cost of a move that has to be understood.
 *
 * ── The one rule that did NOT move ───────────────────────────────
 *
 * §3's fixed depth. A sub company still cannot hold a sub company, so a company
 * with children cannot become one — and the structural refusals below are the
 * same sentence `store()` gives, from the same code path, because two wordings
 * for one rule is two things to keep true.
 *
 * `update()` still refuses `parent_company_id` outright. This is its own route
 * for that reason: a field that reshapes the organization must not ride along
 * with a phone number.
 */
class CompanyReparentTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    private function url(Company $company): string
    {
        return "/api/v1/companies/{$company->id}/parent";
    }

    private function secondCompany(array $tenant, string $name = 'Crestline Home'): Company
    {
        return $this->inTenant($tenant['org'], fn () => Company::create([
            'organization_id' => $tenant['org']->id,
            'name'            => $name,
            'is_active'       => true,
        ]));
    }

    // ── the two directions Jose asked for ────────────────────────

    public function test_a_company_becomes_a_sub_company(): void
    {
        $tenant    = $this->provisionTenant('A1-Group');
        $crestline = $this->secondCompany($tenant);

        $this->signedInAs($tenant['admin'])
            ->patchJson($this->url($crestline), ['parent_company_id' => $tenant['company']->id])
            ->assertOk()
            ->assertJsonPath('parent_company_id', $tenant['company']->id)
            ->assertJsonPath('is_sub_company', true);

        $this->assertSame(
            $tenant['company']->id,
            Company::withoutGlobalScopes()->find($crestline->id)->parent_company_id,
        );
    }

    public function test_a_sub_company_becomes_a_company_again(): void
    {
        $tenant    = $this->provisionTenant('A1-Group');
        $crestline = $this->secondCompany($tenant);

        $this->signedInAs($tenant['admin'])
            ->patchJson($this->url($crestline), ['parent_company_id' => $tenant['company']->id])
            ->assertOk();

        $this->signedInAs($tenant['admin'])
            ->patchJson($this->url($crestline), ['parent_company_id' => null])
            ->assertOk()
            ->assertJsonPath('parent_company_id', null)
            ->assertJsonPath('is_sub_company', false);

        $this->assertNull(Company::withoutGlobalScopes()->find($crestline->id)->parent_company_id);
    }

    /**
     * The third direction, and the one Jose named second: a sub company moving
     * from one parent to another.
     *
     * "like for example i wanted chimney star which is a sub company of a1
     * chimney, to be reassigned as a sub company of everdoors" (2026-09-14). It
     * needs no separate endpoint — a parent id is a parent id — but it is the
     * case somebody would assume had been overlooked, and an untested direction
     * is one refactor away from being overlooked for real.
     */
    public function test_a_sub_company_moves_to_a_different_parent(): void
    {
        $tenant    = $this->provisionTenant('A1-Group');
        $everdoors = $this->secondCompany($tenant, 'Everdoors');
        $star      = $this->secondCompany($tenant, 'Chimney Star');

        // Under A-1 Chimney to begin with, which is the tenant's first company.
        $this->signedInAs($tenant['admin'])
            ->patchJson($this->url($star), ['parent_company_id' => $tenant['company']->id])
            ->assertOk();

        // Straight across to Everdoors, without passing through the top level.
        $this->signedInAs($tenant['admin'])
            ->patchJson($this->url($star), ['parent_company_id' => $everdoors->id])
            ->assertOk()
            ->assertJsonPath('parent_company_id', $everdoors->id)
            ->assertJsonPath('is_sub_company', true);

        $this->assertSame(
            $everdoors->id,
            Company::withoutGlobalScopes()->find($star->id)->parent_company_id,
        );
    }

    /**
     * The move is recorded as an event, not only as a saved column.
     *
     * A parent id changing is the quietest possible representation of an act
     * that hands a whole branch's authority to a different set of people. An
     * automation on `company.updated` is how a business can watch for it.
     */
    public function test_the_move_is_raised_as_a_company_update(): void
    {
        $tenant    = $this->provisionTenant('A1-Group');
        $crestline = $this->secondCompany($tenant);

        $this->signedInAs($tenant['admin'])
            ->patchJson($this->url($crestline), ['parent_company_id' => $tenant['company']->id])
            ->assertOk();

        $event = $this->inTenant($tenant['org'], fn () => \App\Models\AutomationEvent::query()
            ->where('event_key', 'company.updated')
            ->where('subject_id', $crestline->id)
            ->first());

        $this->assertNotNull($event, 'A move that nothing can react to is a silent restructuring.');
        $this->assertSame($tenant['company']->id, $event->payload['after']['parent_company_id']);
    }

    public function test_putting_it_where_it_already_is_raises_nothing(): void
    {
        $tenant    = $this->provisionTenant('A1-Group');
        $crestline = $this->secondCompany($tenant);

        $this->signedInAs($tenant['admin'])
            ->patchJson($this->url($crestline), ['parent_company_id' => null])
            ->assertOk();

        $this->assertSame(0, $this->inTenant($tenant['org'], fn () => \App\Models\AutomationEvent::query()
            ->where('event_key', 'company.updated')
            ->where('subject_id', $crestline->id)
            ->count()));
    }

    // ── §3's depth, which did not move ───────────────────────────

    public function test_a_company_holding_sub_companies_cannot_become_one(): void
    {
        $tenant    = $this->provisionTenant('A1-Group');
        $everdoors = $this->secondCompany($tenant, 'Everdoors');
        $speedy    = $this->secondCompany($tenant, 'Speedy Garage Doors');

        $this->signedInAs($tenant['admin'])
            ->patchJson($this->url($speedy), ['parent_company_id' => $everdoors->id])
            ->assertOk();

        // Everdoors now holds one, so it cannot itself go under A1-Group's
        // first company — that would put Speedy three levels down.
        $this->signedInAs($tenant['admin'])
            ->patchJson($this->url($everdoors), ['parent_company_id' => $tenant['company']->id])
            ->assertStatus(422)
            // Names the one in the way, so a wide tree does not have to be
            // opened branch by branch to find it.
            ->assertJsonPath('errors.parent_company_id.0', fn (string $m) => str_contains($m, 'Speedy Garage Doors'));

        $this->assertNull(Company::withoutGlobalScopes()->find($everdoors->id)->parent_company_id);
    }

    public function test_a_company_cannot_sit_under_a_sub_company(): void
    {
        $tenant    = $this->provisionTenant('A1-Group');
        $sub       = $this->secondCompany($tenant, 'AngiSweep');
        $crestline = $this->secondCompany($tenant, 'Crestline Home');

        $this->signedInAs($tenant['admin'])
            ->patchJson($this->url($sub), ['parent_company_id' => $tenant['company']->id])
            ->assertOk();

        $this->signedInAs($tenant['admin'])
            ->patchJson($this->url($crestline), ['parent_company_id' => $sub->id])
            ->assertStatus(422);
    }

    public function test_a_company_cannot_sit_under_itself(): void
    {
        $tenant    = $this->provisionTenant('A1-Group');
        $crestline = $this->secondCompany($tenant);

        $this->signedInAs($tenant['admin'])
            ->patchJson($this->url($crestline), ['parent_company_id' => $crestline->id])
            ->assertStatus(422);
    }

    public function test_another_organizations_company_is_not_a_parent_anybody_can_reach(): void
    {
        $tenant    = $this->provisionTenant('A1-Group');
        $stranger  = $this->provisionTenant('Everdoors Inc', 'other@everdoors.test');
        $crestline = $this->secondCompany($tenant);

        $this->signedInAs($tenant['admin'])
            ->patchJson($this->url($crestline), ['parent_company_id' => $stranger['company']->id])
            ->assertStatus(422);

        $this->assertNull(Company::withoutGlobalScopes()->find($crestline->id)->parent_company_id);
    }

    // ── who may do it ────────────────────────────────────────────

    /**
     * Authority never flows upward (TODO #12), and a move is the sharpest case.
     *
     * Putting Crestline under A-1 Chimney hands everybody assigned to A-1
     * Chimney authority over Crestline's franchises, customers, jobs and
     * settings. Nobody is granted anything and no assignment changes — the
     * whole trail is one column. So it is asked of somebody standing over the
     * organization, the same test `CompanyPolicy::create` makes for adding a
     * top-level company.
     */
    public function test_somebody_standing_at_one_company_cannot_move_another(): void
    {
        $tenant    = $this->provisionTenant('A1-Group');
        $crestline = $this->secondCompany($tenant);

        $manager = $this->userWithRole(
            $tenant,
            'Branch manager',
            ['companies.view', 'companies.edit'],
            onlyAt: $tenant['franchise'],
        );

        $this->signedInAs($manager)
            ->patchJson($this->url($crestline), ['parent_company_id' => $tenant['company']->id])
            ->assertForbidden();

        $this->assertNull(Company::withoutGlobalScopes()->find($crestline->id)->parent_company_id);
    }

    /**
     * CONTROL for the refusal above: the same request from somebody who DOES
     * stand at the organization succeeds. Without it, that test passes equally
     * well against a route that refuses everybody.
     */
    public function test_somebody_standing_at_the_organization_may_move_it(): void
    {
        $tenant    = $this->provisionTenant('A1-Group');
        $crestline = $this->secondCompany($tenant);

        $this->signedInAs($tenant['admin'])
            ->patchJson($this->url($crestline), ['parent_company_id' => $tenant['company']->id])
            ->assertOk();
    }

    public function test_the_tree_says_whether_a_company_may_be_moved_and_why_not(): void
    {
        $tenant    = $this->provisionTenant('A1-Group');
        $everdoors = $this->secondCompany($tenant, 'Everdoors');
        $speedy    = $this->secondCompany($tenant, 'Speedy Garage Doors');

        $this->signedInAs($tenant['admin'])
            ->patchJson($this->url($speedy), ['parent_company_id' => $everdoors->id])
            ->assertOk();

        // Nested, not flat: sub companies hang inside their parent's row so a
        // client cannot draw one as a sibling of its own parent.
        $tree = $this->signedInAs($tenant['admin'])
            ->getJson('/api/v1/companies')->assertOk()->json('companies');

        $rowFor = function (string $id) use ($tree) {
            foreach ($tree as $row) {
                if ($row['id'] === $id) {
                    return $row;
                }

                foreach ($row['sub_companies'] as $sub) {
                    if ($sub['id'] === $id) {
                        return $sub;
                    }
                }
            }

            return null;
        };

        // Holds one, so it may not become one — and the menu is told the same
        // sentence the write would have refused with, from the same method.
        $this->assertFalse($rowFor($everdoors->id)['may_reparent']);
        $this->assertStringContainsString(
            'Speedy Garage Doors',
            $rowFor($everdoors->id)['may_reparent_reason'],
        );

        // A sub company may always be promoted: it can never be holding one.
        $this->assertTrue($rowFor($speedy->id)['may_reparent']);
        $this->assertNull($rowFor($speedy->id)['may_reparent_reason']);
    }
}
