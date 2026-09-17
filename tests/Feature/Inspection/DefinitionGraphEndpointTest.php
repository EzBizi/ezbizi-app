<?php

namespace Tests\Feature\Inspection;

use App\Models\InspectionDefinition;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/**
 * The builder can actually fetch the chart, and only if it may see the form.
 *
 * DefinitionGraphTest proves the graph is right. This proves somebody can reach
 * it — the half that a unit test of a static method cannot see, and the half
 * this module has been caught by before: an action that was declared, executed,
 * covered by five green tests, and impossible for any customer to select.
 */
class DefinitionGraphEndpointTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    public function test_the_builder_can_fetch_the_graph_for_a_draft(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $author = $this->userWithRole($tenant, 'Manager', ['inspection.view', 'inspection.edit', 'inspection.new.view', 'inspection.reports.view', 'inspection.builder.view']);

        $definition = $this->inTenant($tenant['org'], fn () => InspectionDefinition::create([
            'name' => 'Level 1',
            'created_by' => $tenant['admin']->id,
            'screens' => [
                ['name' => 'Exterior', 'fields' => [
                    ['key' => 'roof_condition', 'label' => 'Roof condition', 'type' => 'text'],
                ]],
                ['name' => 'Roof detail', 'visible_if' => ['field' => 'gone', 'op' => 'eq', 'value' => 'Poor'], 'fields' => [
                    ['key' => 'roof_photo', 'label' => 'Photograph', 'type' => 'text'],
                ]],
            ],
        ]));

        $data = $this->signedInAs($author)
            ->getJson("/api/v1/inspections/{$definition->id}/graph")
            ->assertOk()
            ->json('data');

        $states = [];
        foreach ($data['nodes'] as $node) {
            $states[$node['title']] = $node['state'];
        }

        // The dead branch reaches the client, not just the unit test.
        $this->assertSame('dead', $states['Roof detail']);
        $this->assertNotSame([], $data['edges']);
    }

    /** Somebody who may not see the inspection may not see its shape either. */
    public function test_it_is_refused_without_permission(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $outsider = $this->userWithRole($tenant, 'Driver', ['fleet.view']);

        $definition = $this->inTenant($tenant['org'], fn () => InspectionDefinition::create([
            'name' => 'Level 1',
            'created_by' => $tenant['admin']->id,
            'screens' => [],
        ]));

        $this->signedInAs($outsider)
            ->getJson("/api/v1/inspections/{$definition->id}/graph")
            ->assertForbidden();
    }
}
