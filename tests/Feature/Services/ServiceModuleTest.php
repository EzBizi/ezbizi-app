<?php

namespace Tests\Feature\Services;

use App\Models\Service;
use App\Support\Modules\ModuleRegistry;
use Tests\TestCase;

/** Services — real module declaration (create, edit, delete). */
class ServiceModuleTest extends TestCase
{
    public function test_the_module_offers_view_create_edit_delete(): void
    {
        $module = app(ModuleRegistry::class)->get('services');

        $this->assertNotNull($module);
        $this->assertSame('Services', $module->label);
        $this->assertSame(
            [
                'services.view',
                'services.create',
                'services.edit',
                'services.delete',
            ],
            $module->permissionKeys(),
        );
        $this->assertNull($module->automationExemptBecause);
    }

    public function test_events_are_about_a_service(): void
    {
        $module = app(ModuleRegistry::class)->get('services');

        $this->assertNotNull($module->subject);
        $this->assertSame(Service::class, $module->subject->model);
        $this->assertSame('Service', $module->subject->label);
        $this->assertSame('service', $module->subject->key());
    }

    public function test_every_trigger_is_raised(): void
    {
        $module = app(ModuleRegistry::class)->get('services');
        $keys = [];
        foreach ($module->triggers as $trigger) {
            $this->assertTrue($trigger->isRaised, $trigger->key);
            $keys[] = $trigger->key;
        }

        $this->assertSame(
            ['service.created', 'service.updated', 'service.deleted'],
            $keys,
        );
    }
}
