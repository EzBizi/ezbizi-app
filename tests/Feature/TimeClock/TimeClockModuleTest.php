<?php

namespace Tests\Feature\TimeClock;

use App\Support\Modules\ModuleDefinition;
use App\Support\Modules\ModuleRegistry;
use App\Support\Settings\SettingDefinition;
use App\Support\Settings\SettingRegistry;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

class TimeClockModuleTest extends TestCase
{
    use BuildsTenants;

    public function test_the_module_is_grantable_and_not_a_sidebar_tab(): void
    {
        $module = app(ModuleRegistry::class)->get('time_clock');

        $this->assertNotNull($module);
        $this->assertSame('Clock in', $module->label);
        $this->assertSame(['view'], $module->actions);
        $this->assertSame(['time_clock.view'], $module->permissionKeys());
        $this->assertFalse($module->sidebar);
        $this->assertSame(ModuleDefinition::ROLES_TAB, $module->rolesGroup);
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
        $this->assertSame('hr', app(SettingRegistry::class)->groupOf('time_clock.enabled')['key']);
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
