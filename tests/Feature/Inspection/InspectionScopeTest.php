<?php

namespace Tests\Feature\Inspection;

use App\Models\InspectionDefinition;
use App\Support\Inspection\InspectionScope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/**
 * Where a named inspection exists.
 *
 * Scope is not a permission. `InspectionDefinitionGrant` still decides who may
 * RUN a definition; scope decides which places it exists in at all. Both have
 * to pass, and the tests below are careful to fail for one reason at a time.
 */
class InspectionScopeTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    public function test_a_definition_is_organization_wide_until_it_is_told_otherwise(): void
    {
        $this->assertTrue(Schema::hasColumn('inspection_definitions', 'scope_type'));
        $this->assertTrue(Schema::hasColumn('inspection_definitions', 'scope_id'));

        $tenant = $this->provisionTenant('Alpha');

        $definition = $this->inTenant($tenant['org'], fn () => InspectionDefinition::create([
            'name' => 'Level 1',
            'created_by' => $tenant['admin']->id,
            'screens' => [],
        ]));

        // Null is the organization. A default of anything else would make every
        // definition written before this column belong to a place nobody chose.
        $this->assertNull($definition->scope_type);
        $this->assertNull($definition->scope_id);
    }

    public function test_a_scope_can_be_stored_on_a_definition(): void
    {
        $tenant = $this->provisionTenant('Alpha');

        $definition = $this->inTenant($tenant['org'], fn () => InspectionDefinition::create([
            'name' => 'Level 1',
            'created_by' => $tenant['admin']->id,
            'screens' => [],
            'scope_type' => InspectionDefinition::SCOPE_FRANCHISE,
            'scope_id' => $tenant['franchise']->id,
        ]));

        $fresh = $this->inTenant(
            $tenant['org'],
            fn () => InspectionDefinition::findOrFail($definition->id),
        );

        $this->assertSame('franchise', $fresh->scope_type);
        $this->assertSame($tenant['franchise']->id, $fresh->scope_id);
    }

    // ── visibility, which is not reach ───────────────────────────

    /**
     * The case the whole class exists for.
     *
     * A technician assigned to one franchise does NOT reach the company above
     * them — `ScopeReach::covers` says false, correctly, because they may not
     * administer it. They must still see the inspection that company owns, or a
     * company-scoped definition is invisible to every person who would run it
     * and the feature is worse than useless.
     */
    public function test_a_franchise_technician_sees_the_inspection_their_company_owns(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $tech = $this->userWithRole(
            $tenant, 'Rodrigo', ['inspection.view', 'inspection.create'],
            // Pinned to ONE franchise. userWithRole assigns at organization
            // level by default, which reaches every franchise — and would make
            // the sideways test pass for the wrong reason, then keep passing
            // if scope were deleted entirely.
            onlyAt: $tenant['franchise'],
        );

        $owned = $this->inTenant($tenant['org'], fn () => InspectionDefinition::create([
            'name' => 'Company wide',
            'created_by' => $tenant['admin']->id,
            'screens' => [],
            'scope_type' => InspectionDefinition::SCOPE_COMPANY,
            'scope_id' => $tenant['company']->id,
        ]));

        $this->assertContains($owned->id, $this->visibleTo($tenant, $tech));
    }

    public function test_a_sibling_franchises_inspection_is_not_visible(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $tech = $this->userWithRole(
            $tenant, 'Rodrigo', ['inspection.view', 'inspection.create'],
            // Pinned to ONE franchise. userWithRole assigns at organization
            // level by default, which reaches every franchise — and would make
            // the sideways test pass for the wrong reason, then keep passing
            // if scope were deleted entirely.
            onlyAt: $tenant['franchise'],
        );
        $other = $this->extraFranchise($tenant, 'Second');

        $theirs = $this->inTenant($tenant['org'], fn () => InspectionDefinition::create([
            'name' => 'Not yours',
            'created_by' => $tenant['admin']->id,
            'screens' => [],
            'scope_type' => InspectionDefinition::SCOPE_FRANCHISE,
            'scope_id' => $other->id,
        ]));

        $this->assertNotContains($theirs->id, $this->visibleTo($tenant, $tech),
            'a franchise-scoped inspection must not leak sideways to a sibling');
    }

    public function test_an_organization_wide_inspection_is_visible_to_everybody(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $tech = $this->userWithRole(
            $tenant, 'Rodrigo', ['inspection.view', 'inspection.create'],
            // Pinned to ONE franchise. userWithRole assigns at organization
            // level by default, which reaches every franchise — and would make
            // the sideways test pass for the wrong reason, then keep passing
            // if scope were deleted entirely.
            onlyAt: $tenant['franchise'],
        );

        $all = $this->inTenant($tenant['org'], fn () => InspectionDefinition::create([
            'name' => 'Everyone',
            'created_by' => $tenant['admin']->id,
            'screens' => [],
        ]));

        $this->assertContains($all->id, $this->visibleTo($tenant, $tech));
    }

    /** @return string[] */
    private function visibleTo(array $tenant, $user): array
    {
        return $this->inTenant($tenant['org'], function () use ($user) {
            $query = InspectionDefinition::query();
            app(InspectionScope::class)->constrain($query, $user->fresh());

            return $query->pluck('id')->all();
        });
    }
}
