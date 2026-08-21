<?php

namespace Tests\Feature\Fleet;

use App\Models\Vehicle;
use App\Support\Modules\ModuleRegistry;
use App\Support\Modules\TriggerDefinition;
use Tests\TestCase;

/**
 * Fleet Management — real module declaration (assign, financials, vehicle automations).
 *
 * Triggers stay isRaised: false until Task 13 emits them. insurance_expiring and
 * service_due stay unraised (no sweep in this plan).
 */
class FleetModuleTest extends TestCase
{
    private const MODULE = 'fleet_management';

    public function test_the_module_offers_view_create_edit_delete_assign_and_financials(): void
    {
        $module = app(ModuleRegistry::class)->get(self::MODULE);

        $this->assertNotNull($module);
        $this->assertSame('Fleet Management', $module->label);
        $this->assertSame(
            [
                'fleet_management.view',
                'fleet_management.create',
                'fleet_management.edit',
                'fleet_management.delete',
                'fleet_management.assign',
                'fleet_management.financials',
            ],
            $module->permissionKeys(),
        );
        $this->assertNull($module->automationExemptBecause);
    }

    public function test_events_are_about_a_vehicle(): void
    {
        $module = app(ModuleRegistry::class)->get(self::MODULE);

        $this->assertNotNull($module->subject);
        $this->assertSame(Vehicle::class, $module->subject->model);
        $this->assertSame('Vehicle', $module->subject->label);
        $this->assertSame('vehicle', $module->subject->key());
    }

    public function test_vehicle_insurance_expiring_is_declared_and_not_raised(): void
    {
        $module = app(ModuleRegistry::class)->get(self::MODULE);
        $trigger = $module->trigger('vehicle.insurance_expiring');

        $this->assertNotNull($trigger);
        $this->assertSame('Insurance expiring', $trigger->label);
        $this->assertFalse($trigger->isRaised);
        $this->assertStringContainsString(
            TriggerDefinition::NOT_RAISED_YET,
            $trigger->description,
        );
    }

    public function test_every_declared_vehicle_event_is_marked_as_not_raised_yet(): void
    {
        $module = app(ModuleRegistry::class)->get(self::MODULE);

        $this->assertNotEmpty($module->triggers);

        foreach ($module->triggers as $trigger) {
            $this->assertFalse(
                $trigger->isRaised,
                "'{$trigger->key}' claims something raises it.",
            );
            $this->assertStringStartsWith('vehicle.', $trigger->key);
        }
    }

    public function test_automation_actions_are_declared_for_assign_status_and_fields(): void
    {
        $module = app(ModuleRegistry::class)->get(self::MODULE);
        $keys = array_map(fn ($a) => $a->key, $module->automationActions);

        $this->assertSame(
            [
                'vehicle.assign_to_user',
                'vehicle.unassign',
                'vehicle.change_status',
                'vehicle.update_field',
            ],
            $keys,
        );
    }
}
