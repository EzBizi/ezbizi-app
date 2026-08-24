<?php

namespace Tests\Feature\Inventory;

use App\Models\InventoryItem;
use App\Support\Modules\ModuleRegistry;
use App\Support\Modules\TriggerDefinition;
use Tests\TestCase;

/**
 * Inventory — real module declaration (create, edit, delete, financials).
 *
 * Every trigger stays unraised until EventEmitter::emit('item.…') lands
 * (Task 12). item.low stays unraised for good — no sweep in this plan.
 * Nothing a rule can do moves stock.
 */
class InventoryModuleTest extends TestCase
{
    private const MODULE = 'inventory';

    public function test_the_module_offers_view_create_edit_delete_and_financials(): void
    {
        $module = app(ModuleRegistry::class)->get(self::MODULE);

        $this->assertNotNull($module);
        $this->assertSame('Inventory', $module->label);
        $this->assertSame(
            [
                'inventory.view',
                'inventory.create',
                'inventory.edit',
                'inventory.delete',
                'inventory.financials',
            ],
            $module->permissionKeys(),
        );
        $this->assertNull($module->automationExemptBecause);
    }

    public function test_events_are_about_an_item(): void
    {
        $module = app(ModuleRegistry::class)->get(self::MODULE);

        $this->assertNotNull($module->subject);
        $this->assertSame(InventoryItem::class, $module->subject->model);
        $this->assertSame('Item', $module->subject->label);
        $this->assertSame('item', $module->subject->key());
    }

    public function test_item_low_is_declared_and_not_raised(): void
    {
        $module = app(ModuleRegistry::class)->get(self::MODULE);
        $trigger = $module->trigger('item.low');

        $this->assertNotNull($trigger);
        $this->assertSame('Stock is low', $trigger->label);
        $this->assertFalse($trigger->isRaised);
        $this->assertStringContainsString(
            TriggerDefinition::NOT_RAISED_YET,
            $trigger->description,
        );
    }

    public function test_every_declared_trigger_is_item_prefixed_and_unraised(): void
    {
        $module = app(ModuleRegistry::class)->get(self::MODULE);

        $this->assertNotEmpty($module->triggers);

        $keys = [];
        foreach ($module->triggers as $trigger) {
            $this->assertStringStartsWith('item.', $trigger->key);
            $this->assertFalse($trigger->isRaised, $trigger->key);
            $keys[] = $trigger->key;
        }

        $this->assertSame(
            [
                'item.created',
                'item.updated',
                'item.received',
                'item.issued',
                'item.transferred',
                'item.adjusted',
                'item.ordered',
                'item.purchase_received',
                'item.low',
            ],
            $keys,
        );
    }

    public function test_no_automation_action_moves_stock(): void
    {
        $module = app(ModuleRegistry::class)->get(self::MODULE);

        $this->assertSame([], $module->automationActions);
    }
}
