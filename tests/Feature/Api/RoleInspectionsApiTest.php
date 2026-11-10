<?php

namespace Tests\Feature\Api;

use App\Models\InspectionDefinition;
use App\Models\InspectionDefinitionGrant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

class RoleInspectionsApiTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    public function test_the_catalogue_nests_this_organizations_named_inspections(): void
    {
        $alpha = $this->provisionTenant('Alpha');
        $bravo = $this->provisionTenant('Bravo');
        $this->named($alpha, 'Level 1');
        $this->named($bravo, 'Level 9');

        $modules = $this->signedInAs($alpha['admin'])
            ->getJson('/api/v1/roles/catalogue')->assertOk()->json('modules');

        $inspection = collect($modules)->firstWhere('key', 'inspection');
        $this->assertSame(['Level 1'], array_column($inspection['inspections'], 'name'));

        foreach ($modules as $module) {
            if ($module['key'] === 'inspection') {
                continue;
            }
            $this->assertArrayNotHasKey('inspections', $module);
        }

        $theirs = $this->signedInAs($bravo['admin'])
            ->getJson('/api/v1/roles/catalogue')->assertOk()->json('modules');

        $this->assertSame(
            ['Level 9'],
            array_column(collect($theirs)->firstWhere('key', 'inspection')['inspections'], 'name'),
        );
    }

    public function test_granting_the_tab_grants_no_named_inspection(): void
    {
        $alpha = $this->provisionTenant('Alpha');
        $this->named($alpha, 'Level 1');

        $role = $this->signedInAs($alpha['admin'])->postJson('/api/v1/roles', [
            'name' => 'Tech',
            'permissions' => ['inspection.view', 'inspection.create', 'inspection.edit', 'inspection.new.view', 'inspection.reports.view', 'inspection.builder.view'],
        ])->assertCreated()->json('data');

        $this->assertSame([], $role['inspections']);
    }

    public function test_a_named_inspection_is_granted_and_revoked_on_role_save(): void
    {
        $alpha = $this->provisionTenant('Alpha');
        $level1 = $this->named($alpha, 'Level 1');
        $this->named($alpha, 'Level 2');

        $role = $this->signedInAs($alpha['admin'])->postJson('/api/v1/roles', [
            'name' => 'Tech',
            'permissions' => ['inspection.view', 'inspection.create', 'inspection.edit', 'inspection.new.view', 'inspection.reports.view', 'inspection.builder.view'],
            'inspections' => [$level1->id],
        ])->assertCreated()->json('data');

        $this->assertSame([$level1->id], $role['inspections']);

        $this->signedInAs($alpha['admin'])->patchJson("/api/v1/roles/{$role['id']}", [
            'permissions' => ['inspection.view', 'inspection.create', 'inspection.edit', 'inspection.new.view', 'inspection.reports.view', 'inspection.builder.view'],
            'inspections' => [],
        ])->assertOk()->assertJsonPath('data.inspections', []);

        $this->inTenant($alpha['org'], fn () => $this->assertSame(0, InspectionDefinitionGrant::count()));
    }

    public function test_a_named_inspection_on_a_role_without_the_tab_is_refused(): void
    {
        $alpha = $this->provisionTenant('Alpha');
        $level1 = $this->named($alpha, 'Level 1');

        $this->signedInAs($alpha['admin'])->postJson('/api/v1/roles', [
            'name' => 'Blind',
            'permissions' => ['customers.view'],
            'inspections' => [$level1->id],
        ])->assertStatus(422)->assertJsonPath(
            'errors.inspections.0',
            fn (string $m) => str_contains($m, 'would open nothing')
                && str_contains($m, 'Inspection'),
        );
    }

    /** @param  array{org: \App\Models\Organization, admin: \App\Models\User}  $tenant */
    private function named(array $tenant, string $name): InspectionDefinition
    {
        return $this->inTenant($tenant['org'], fn () => InspectionDefinition::create([
            'name' => $name,
            'created_by' => $tenant['admin']->id,
        ]));
    }
}
