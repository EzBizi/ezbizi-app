<?php

namespace Tests\Feature\Api;

use App\Models\AuditEvent;
use App\Models\Role;
use App\Support\Modules\ModuleDefinition;
use App\Support\Modules\ModuleRegistry;
use App\Support\Modules\TabDefinition;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/** Per-role phone bottom bar (Jose, 2026-11-23). */
class RoleMobileBarTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Fixture modules, registered before the first request: a route keeps
        // its injected catalogue for the whole test.
        $modules = ['bara' => 'Bar Alpha', 'barb' => 'Bar Beta', 'barc' => 'Bar Gamma', 'bard' => 'Bar Delta', 'bare' => 'Bar Epsilon'];

        foreach ($modules as $key => $label) {
            app(ModuleRegistry::class)->register(new ModuleDefinition(
                key: $key,
                label: $label,
                actions: ['view'],
                tabs: [new TabDefinition('route', 'Route'), new TabDefinition('board', 'Board')],
                automationExemptBecause: 'A fixture, not a feature.',
            ));
        }
    }

    private function tenantWithTech(): array
    {
        $alpha = $this->provisionTenant('Alpha');
        $tech = $this->userWithRole($alpha, 'Tech', [
            'bara.view', 'bara.route.view', 'barb.view', 'barc.view', 'bard.view', 'bare.view',
        ]);

        return [$alpha, $tech, $this->inTenant($alpha['org'], fn () => $tech->role)];
    }

    private function patchBar(array $alpha, Role $role, ?array $bar)
    {
        return $this->signedInAs($alpha['admin'])->patchJson("/api/v1/roles/{$role->id}", ['mobile_bar' => $bar]);
    }

    public function test_unreachable_slots_are_refused_and_a_valid_bar_is_saved(): void
    {
        [$alpha, , $role] = $this->tenantWithTech();

        $cases = [
            'module without view' => [['module' => 'customers', 'tab' => null]],
            'tab without its tick' => [['module' => 'bara', 'tab' => 'board']],
            'unknown tab' => [['module' => 'bara', 'tab' => 'nope']],
            'five slots' => [['module' => 'bara'], ['module' => 'barb'], ['module' => 'barc'], ['module' => 'bard'], ['module' => 'bare']],
            'duplicate' => [['module' => 'bara', 'tab' => 'route'], ['module' => 'bara', 'tab' => 'route']],
        ];

        foreach ($cases as $name => $bar) {
            $this->patchBar($alpha, $role, $bar)->assertStatus(422)->assertJsonValidationErrors('mobile_bar');
            $this->assertNull($this->inTenant($alpha['org'], fn () => $role->fresh()->mobile_bar), $name);
        }

        $this->patchBar($alpha, $role, [['module' => 'bara', 'tab' => 'board']])
            ->assertJsonPath('errors.mobile_bar.0', 'This role cannot open Bar Alpha › Board, so it cannot be on its bar.');

        // Control: reachable slots are accepted and echoed on the role.
        $saved = $this->patchBar($alpha, $role, [['module' => 'bara', 'tab' => 'route'], ['module' => 'barb']])
            ->assertOk()->json('data.mobile_bar');
        $this->assertEquals([['module' => 'bara', 'tab' => 'route'], ['module' => 'barb', 'tab' => null]], $saved);

        $this->patchBar($alpha, $role, null)->assertOk()->assertJsonPath('data.mobile_bar', null);
    }

    public function test_create_checks_the_requested_permissions(): void
    {
        $alpha = $this->provisionTenant('Alpha');

        $this->signedInAs($alpha['admin'])->postJson('/api/v1/roles', [
            'name' => 'Driver', 'permissions' => ['bara.view'],
            'mobile_bar' => [['module' => 'bara', 'tab' => 'route']],
        ])->assertStatus(422)->assertJsonValidationErrors('mobile_bar');

        $this->signedInAs($alpha['admin'])->postJson('/api/v1/roles', [
            'name' => 'Driver', 'permissions' => ['bara.view', 'bara.route.view'],
            'mobile_bar' => [['module' => 'bara', 'tab' => 'route']],
        ])->assertCreated()->assertJsonPath('data.mobile_bar.0.tab', 'route');
    }

    public function test_me_returns_the_bar_in_order_with_labels(): void
    {
        [$alpha, $tech, $role] = $this->tenantWithTech();
        $this->patchBar($alpha, $role, [['module' => 'barb'], ['module' => 'bara', 'tab' => 'route']])->assertOk();

        $this->signedInAs($tech)->getJson('/api/v1/me')->assertOk()->assertJsonPath('data.mobile_bar', [
            ['module' => 'barb', 'tab' => null, 'label' => 'Bar Beta'],
            ['module' => 'bara', 'tab' => 'route', 'label' => 'Route'],
        ]);
    }

    public function test_me_is_null_when_unset(): void
    {
        [, $tech] = $this->tenantWithTech();

        $this->signedInAs($tech)->getJson('/api/v1/me')->assertOk()->assertJsonPath('data.mobile_bar', null);
    }

    public function test_me_drops_a_slot_the_role_lost(): void
    {
        [$alpha, $tech, $role] = $this->tenantWithTech();
        $this->patchBar($alpha, $role, [['module' => 'bara', 'tab' => 'route'], ['module' => 'barb']])->assertOk();

        $this->inTenant($alpha['org'], fn () => $role->fresh()->syncPermissionKeys(['bara.view', 'barb.view']));

        $this->signedInAs($tech->fresh())->getJson('/api/v1/me')->assertOk()->assertJsonPath('data.mobile_bar', [
            ['module' => 'barb', 'tab' => null, 'label' => 'Bar Beta'],
        ]);
        $this->assertCount(2, $this->inTenant($alpha['org'], fn () => $role->fresh()->mobile_bar));
    }

    public function test_options_list_only_reachable_modules_and_tabs(): void
    {
        [$alpha, , $role] = $this->tenantWithTech();

        $modules = collect($this->signedInAs($alpha['admin'])
            ->getJson("/api/v1/roles/{$role->id}/mobile-bar/options")
            ->assertOk()->json('data.modules'))->keyBy('module');

        $this->assertSame([['tab' => 'route', 'label' => 'Route']], $modules['bara']['tabs']);
        $this->assertSame([], $modules['barb']['tabs']);
        $this->assertSame('Bar Alpha', $modules['bara']['label']);
        $this->assertArrayNotHasKey('customers', $modules->all());
    }

    public function test_the_change_is_audited(): void
    {
        [$alpha, , $role] = $this->tenantWithTech();
        $this->patchBar($alpha, $role, [['module' => 'barb']])->assertOk();

        $event = $this->inTenant($alpha['org'], fn () => AuditEvent::query()
            ->where('action', 'role.mobile_bar_changed')->first());

        $this->assertNotNull($event);
        $this->assertEquals([['module' => 'barb', 'tab' => null]], $event->changes['after']);
        $this->assertNull($event->changes['before']);
    }
}
