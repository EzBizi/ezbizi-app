<?php

namespace Tests\Feature\Monitoring;

use App\Support\Modules\ModuleRegistry;
use App\Support\Modules\PermissionCatalogue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MonitoringModuleRegistrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_monitoring_is_a_module_with_view_and_specs_view_permissions(): void
    {
        $module = app(ModuleRegistry::class)->get('monitoring');

        $this->assertNotNull($module);
        $this->assertContains('view', $module->actions);
        $this->assertContains('specs.view', $module->actions);
    }

    public function test_monitoring_declares_its_two_triggers(): void
    {
        $module = app(ModuleRegistry::class)->get('monitoring');
        $keys = array_map(fn ($t) => $t->key, $module->triggers);

        $this->assertContains('monitoring.new_device_seen', $keys);
        $this->assertContains('monitoring.device_offline', $keys);
    }

    public function test_permission_catalogue_knows_monitoring_view_and_specs_view(): void
    {
        $keys = app(PermissionCatalogue::class)->keys();

        $this->assertContains('monitoring.view', $keys);
        $this->assertContains('monitoring.specs.view', $keys);
    }
}
