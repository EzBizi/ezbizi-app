<?php

namespace Tests\Feature\Inspection;

use App\Models\InspectionDefinition;
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
}
