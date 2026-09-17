<?php

namespace Tests\Feature\Api;

use App\Models\InspectionDefinition;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/**
 * Setting the place a named inspection belongs to.
 *
 * Authoring is a REACH question, unlike running one — see InspectionScope for
 * why those are different. A person may only give an inspection to a place they
 * could act on, or a franchise manager could quietly take the group's
 * inspection away from everybody else with one PATCH.
 */
class InspectionBuilderScopeTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    public function test_a_definition_reports_its_scope(): void
    {
        $tenant = $this->provisionTenant('Alpha');

        $id = $this->signedInAs($tenant['admin'])
            ->postJson('/api/v1/inspections', [])
            ->assertCreated()
            ->json('data.id');

        // Absent scope reads as the organization, and says so with nulls rather
        // than by omitting the key. A client made to tell "no scope" from "the
        // server did not mention it" will guess, and half will guess wrong.
        $this->signedInAs($tenant['admin'])
            ->getJson("/api/v1/inspections/{$id}")
            ->assertOk()
            ->assertJsonPath('data.scope.type', null)
            ->assertJsonPath('data.scope.id', null);

        $this->signedInAs($tenant['admin'])
            ->patchJson("/api/v1/inspections/{$id}", [
                'scope_type' => 'franchise',
                'scope_id' => $tenant['franchise']->id,
            ])
            ->assertOk()
            ->assertJsonPath('data.scope.type', 'franchise')
            ->assertJsonPath('data.scope.id', $tenant['franchise']->id);
    }

    public function test_a_scope_from_another_organization_is_refused(): void
    {
        $alpha = $this->provisionTenant('Alpha');
        $beta = $this->provisionTenant('Beta');

        $id = $this->signedInAs($alpha['admin'])
            ->postJson('/api/v1/inspections', [])
            ->assertCreated()
            ->json('data.id');

        $this->signedInAs($alpha['admin'])
            ->patchJson("/api/v1/inspections/{$id}", [
                'scope_type' => 'franchise',
                'scope_id' => $beta['franchise']->id,
            ])
            ->assertStatus(422);
    }

    public function test_clearing_the_scope_returns_it_to_the_organization(): void
    {
        $tenant = $this->provisionTenant('Alpha');

        $id = $this->signedInAs($tenant['admin'])
            ->postJson('/api/v1/inspections', [])
            ->assertCreated()
            ->json('data.id');

        $this->signedInAs($tenant['admin'])->patchJson("/api/v1/inspections/{$id}", [
            'scope_type' => 'franchise',
            'scope_id' => $tenant['franchise']->id,
        ])->assertOk();

        // scope_id must be dropped with scope_type, or the row keeps a
        // franchise id under a null type and the next reader has to guess which
        // of the two won.
        $this->signedInAs($tenant['admin'])
            ->patchJson("/api/v1/inspections/{$id}", ['scope_type' => null])
            ->assertOk()
            ->assertJsonPath('data.scope.type', null)
            ->assertJsonPath('data.scope.id', null);
    }

    public function test_a_scope_id_without_a_type_is_refused(): void
    {
        $tenant = $this->provisionTenant('Alpha');

        $id = $this->signedInAs($tenant['admin'])
            ->postJson('/api/v1/inspections', [])
            ->assertCreated()
            ->json('data.id');

        $this->signedInAs($tenant['admin'])
            ->patchJson("/api/v1/inspections/{$id}", ['scope_id' => $tenant['franchise']->id])
            ->assertStatus(422);
    }

    /**
     * A place you cannot act on is not yours to give an inspection to.
     *
     * The tenant-boundary check would pass — the sibling franchise IS this
     * organization's — so without the reach check a franchise manager could
     * scope any inspection to a franchise they have nothing to do with.
     */
    public function test_a_scope_outside_the_authors_reach_is_refused(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $elsewhere = $this->extraFranchise($tenant, 'Second');

        $author = $this->userWithRole(
            $tenant, 'Mia', ['inspection.view', 'inspection.create', 'inspection.edit', 'inspection.new.view', 'inspection.reports.view', 'inspection.builder.view'],
            onlyAt: $tenant['franchise'],
        );

        $id = $this->signedInAs($tenant['admin'])
            ->postJson('/api/v1/inspections', [])
            ->assertCreated()
            ->json('data.id');

        $this->signedInAs($author)
            ->patchJson("/api/v1/inspections/{$id}", [
                'scope_type' => 'franchise',
                'scope_id' => $elsewhere->id,
            ])
            ->assertStatus(422);
    }

    /**
     * The Builder lists what exists here, the same as the picker does.
     *
     * Otherwise a franchise manager browses every company's inspections and can
     * open one they may not run — which is a disclosure, not an inconvenience.
     */
    public function test_the_builder_list_omits_an_inspection_scoped_elsewhere(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $elsewhere = $this->extraFranchise($tenant, 'Second');

        $theirs = $this->inTenant($tenant['org'], fn () => InspectionDefinition::create([
            'name' => 'Not yours',
            'created_by' => $tenant['admin']->id,
            'screens' => [],
            'scope_type' => InspectionDefinition::SCOPE_FRANCHISE,
            'scope_id' => $elsewhere->id,
        ]));

        $author = $this->userWithRole(
            $tenant, 'Mia', ['inspection.view', 'inspection.create', 'inspection.edit', 'inspection.new.view', 'inspection.reports.view', 'inspection.builder.view'],
            onlyAt: $tenant['franchise'],
        );

        $ids = collect($this->signedInAs($author)
            ->getJson('/api/v1/inspections')
            ->assertOk()
            ->json('data'))->pluck('id')->all();

        $this->assertNotContains($theirs->id, $ids);
    }
}
