<?php

namespace Tests\Feature\Api;

use App\Models\Customer;
use App\Models\InspectionDefinition;
use App\Models\InspectionDefinitionGrant;
use App\Models\InspectionDefinitionVersion;
use App\Models\InspectionRun;
use App\Support\Inspection\InspectionAccess;
use App\Support\Inspection\ScopeKey;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

class InspectionAccessTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    public function test_carlos_cannot_see_rodrigos_run(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $seeded = $this->seedPublished($tenant);
        $rodrigo = $this->tech($tenant, 'Rodrigo');
        $carlos = $this->tech($tenant, 'Carlos');
        $this->grant($tenant, $seeded['definition'], $rodrigo->role_id);
        $this->grant($tenant, $seeded['definition'], $carlos->role_id);

        $run = $this->inTenant($tenant['org'], fn () => InspectionRun::create([
            'user_id' => $rodrigo->id,
            'customer_id' => $seeded['customer']->id,
            'inspection_definition_id' => $seeded['definition']->id,
            'inspection_definition_version_id' => $seeded['version']->id,
            'scope_key' => ScopeKey::for($seeded['customer']->id, null),
            'answers' => [],
        ]));

        $access = app(InspectionAccess::class);

        $this->assertTrue($access->maySeeRun($rodrigo, $run));
        $this->assertFalse($access->maySeeRun($carlos, $run));
    }

    public function test_the_picker_omits_ungranted_published_definitions(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $offered = $this->seedPublished($tenant, 'Level 1');
        $this->seedPublished($tenant, 'Level 2');
        $tech = $this->tech($tenant, 'Rodrigo');
        $this->grant($tenant, $offered['definition'], $tech->role_id);

        $ids = $this->inTenant(
            $tenant['org'],
            fn () => app(InspectionAccess::class)->startable($tech)->pluck('id')->all(),
        );

        $this->assertSame([$offered['definition']->id], $ids);
    }

    public function test_submit_of_an_open_run_still_works_after_grant_revoke(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $seeded = $this->seedPublished($tenant);
        $tech = $this->tech($tenant, 'Rodrigo');
        $this->grant($tenant, $seeded['definition'], $tech->role_id);

        $run = $this->inTenant($tenant['org'], fn () => InspectionRun::create([
            'user_id' => $tech->id,
            'customer_id' => $seeded['customer']->id,
            'inspection_definition_id' => $seeded['definition']->id,
            'inspection_definition_version_id' => $seeded['version']->id,
            'scope_key' => ScopeKey::for($seeded['customer']->id, null),
            'answers' => [],
        ]));

        $this->inTenant($tenant['org'], fn () => InspectionDefinitionGrant::query()
            ->where('role_id', $tech->role_id)
            ->delete());

        $access = app(InspectionAccess::class);

        $this->inTenant($tenant['org'], function () use ($access, $tech, $run, $seeded) {
            $this->assertTrue($access->maySubmit($tech, $run));
            $this->assertFalse($access->mayStart($tech, $seeded['definition']));
            $this->assertSame([], $access->startable($tech)->pluck('id')->all());
        });
    }

    private function tech(array $tenant, string $name): \App\Models\User
    {
        return $this->userWithRole($tenant, $name, [
            'inspection.view', 'inspection.create',
        ]);
    }

    private function grant(array $tenant, InspectionDefinition $definition, string $roleId): void
    {
        $this->inTenant($tenant['org'], fn () => InspectionDefinitionGrant::create([
            'inspection_definition_id' => $definition->id,
            'role_id' => $roleId,
        ]));
    }

    /**
     * @param  array{org: \App\Models\Organization, admin: \App\Models\User, franchise: \App\Models\Franchise}  $tenant
     * @return array{customer: Customer, definition: InspectionDefinition, version: InspectionDefinitionVersion}
     */
    private function seedPublished(array $tenant, string $name = 'Level 1'): array
    {
        return $this->inTenant($tenant['org'], function () use ($tenant, $name) {
            $customer = Customer::query()->first() ?? Customer::create([
                'owning_franchise_id' => $tenant['franchise']->id,
                'number' => 'C-'.bin2hex(random_bytes(3)),
                'name' => '14 Maple',
            ]);

            $definition = InspectionDefinition::create([
                'name' => $name,
                'created_by' => $tenant['admin']->id,
                'published_at' => now(),
            ]);

            $version = InspectionDefinitionVersion::create([
                'inspection_definition_id' => $definition->id,
                'version' => 1,
                'layout_preset' => 'step_strip',
                'screens' => [],
                'published_by' => $tenant['admin']->id,
            ]);

            $definition->update(['current_version_id' => $version->id]);

            return [
                'customer' => $customer,
                'definition' => $definition->fresh(),
                'version' => $version,
            ];
        });
    }
}
