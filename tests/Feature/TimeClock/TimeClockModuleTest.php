<?php

namespace Tests\Feature\TimeClock;

use App\Support\Modules\ModuleDefinition;
use App\Support\Modules\ModuleRegistry;
use App\Support\Settings\SettingDefinition;
use App\Support\Settings\SettingRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

class TimeClockModuleTest extends TestCase
{
    // Three of these tests provision a tenant. Without RefreshDatabase each one
    // COMMITS an "Alpha" organization that every later test in the process sees.
    use BuildsTenants;
    use RefreshDatabase;

    public function test_the_module_is_grantable_and_not_a_sidebar_tab(): void
    {
        $module = app(ModuleRegistry::class)->get('time_clock');

        $this->assertNotNull($module);
        $this->assertSame('Clock in', $module->label);
        $this->assertSame(['view', 'lock_screen', 'anywhere'], $module->actions);
        $this->assertSame(['time_clock.view', 'time_clock.lock_screen', 'time_clock.anywhere'], $module->permissionKeys());
        $this->assertFalse($module->sidebar);
        $this->assertSame(ModuleDefinition::ROLES_TAB, $module->rolesGroup);
    }

    public function test_lock_screen_does_not_imply_human_resources(): void
    {
        $alpha = $this->provisionTenant('Alpha');
        $locked = $this->userWithRole($alpha, 'Locked', ['time_clock.lock_screen']);

        $this->inTenant($alpha['org'], function () use ($locked) {
            $this->assertTrue($locked->hasPermission('time_clock.lock_screen'));
            $this->assertTrue($locked->hasPermission('time_clock.view'), 'any action implies view');
            $this->assertFalse($locked->hasPermission('hr.view'));
        });
    }

    public function test_the_schedule_settings_are_organization_only_with_these_defaults(): void
    {
        $registry = app(SettingRegistry::class);

        foreach ([
            'time_clock.lock_screen'       => [SettingDefinition::TYPE_BOOL, false],
            'time_clock.warning_minutes'   => [SettingDefinition::TYPE_ENUM, 5],
            'time_clock.extension_minutes' => [SettingDefinition::TYPE_ENUM, 30],
            'time_clock.self_schedule'     => [SettingDefinition::TYPE_BOOL, false],
        ] as $key => [$type, $default]) {
            $definition = $registry->getOrFail($key);
            $this->assertSame($type, $definition->type, $key);
            $this->assertSame($default, $definition->default, $key);
            $this->assertSame([SettingDefinition::SCOPE_ORGANIZATION], $definition->allowedScopes, $key);
            // Their own group since Work Hours left HR (2026-09-24).
            $this->assertSame('work_hours', $registry->groupOf($key)['key'], $key);
        }

        $this->assertContains(1, $registry->getOrFail('time_clock.warning_minutes')->options);
        $this->assertContains(120, $registry->getOrFail('time_clock.extension_minutes')->options);
        // Jose, 2026-09-30: "i should be able to input less like 5 or 10".
        $this->assertContains(5, $registry->getOrFail('time_clock.extension_minutes')->options);
        $this->assertContains(10, $registry->getOrFail('time_clock.extension_minutes')->options);
    }

    public function test_clock_in_does_not_imply_human_resources(): void
    {
        $alpha = $this->provisionTenant('Alpha');
        $puncher = $this->userWithRole($alpha, 'Tech', ['time_clock.view']);

        $this->inTenant($alpha['org'], function () use ($puncher) {
            $this->assertTrue($puncher->hasPermission('time_clock.view'));
            $this->assertFalse($puncher->hasPermission('hr.view'));
        });
    }

    public function test_the_setting_is_off_by_default_and_organization_only(): void
    {
        $definition = app(SettingRegistry::class)->getOrFail('time_clock.enabled');

        $this->assertSame(SettingDefinition::TYPE_BOOL, $definition->type);
        $this->assertFalse($definition->default);
        $this->assertSame([SettingDefinition::SCOPE_ORGANIZATION], $definition->allowedScopes);
        $this->assertSame('Clock in', $definition->label);
        $this->assertSame('work_hours', app(SettingRegistry::class)->groupOf('time_clock.enabled')['key']);
    }

    public function test_the_roles_catalogue_lists_clock_in_after_human_resources(): void
    {
        $alpha = $this->provisionTenant('Alpha');
        $keys = array_column(
            $this->signedInAs($alpha['admin'])->getJson('/api/v1/roles/catalogue')->assertOk()->json('modules'),
            'key',
        );

        $this->assertContains('time_clock', $keys);
        $this->assertLessThan(
            array_search('time_clock', $keys, true),
            array_search('hr', $keys, true),
        );
    }
}
