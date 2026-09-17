<?php

namespace Tests\Feature\Inventory;

use App\Models\InventoryItem;
use App\Support\Modules\ModuleRegistry;
use App\Support\Modules\TriggerDefinition;
use Tests\TestCase;

/**
 * Inventory — real module declaration (create, edit, delete, financials).
 *
 * Clock trigger item.low stays unraised (no sweep in this plan). The rest
 * are raised from the write path; InventoryEventsTest watches the outbox.
 * Nothing a rule can do moves stock.
 */
class InventoryModuleTest extends TestCase
{
    private const MODULE = 'inventory';

    public function test_the_module_offers_view_create_edit_delete_financials_and_a_grant_per_tab(): void
    {
        $module = app(ModuleRegistry::class)->get(self::MODULE);

        $this->assertNotNull($module);
        $this->assertSame('Inventory', $module->label);

        /*
         * Order matters here and is the registry's, not this list's: the module
         * actions first, then one `view` per tab in the order the chip row
         * draws them. Asserting the whole array rather than four `contains`
         * calls is deliberate — a tab silently dropped from the declaration
         * would still pass every containment check for the three that remain.
         */
        $this->assertSame(
            [
                'inventory.view',
                'inventory.create',
                'inventory.edit',
                'inventory.delete',
                'inventory.financials',
                'inventory.stock.view',
                'inventory.items.view',
                'inventory.orders.view',
                'inventory.locations.view',
            ],
            $module->permissionKeys(),
        );
        $this->assertNull($module->automationExemptBecause);
    }

    public function test_every_declared_tab_is_labelled_with_the_word_the_chip_row_shows(): void
    {
        $tabs = app(ModuleRegistry::class)->get(self::MODULE)->tabs;

        /*
         * The label is what an administrator ticks in the roles editor, so it
         * has to be the word they will then look for on screen. These four are
         * copied from `inventory_screen.dart`'s chip row; a tab renamed there
         * and not here hands somebody a checkbox for a tab whose name they were
         * told wrongly, and nothing errors.
         */
        $this->assertSame(
            ['stock' => 'Stock', 'items' => 'Items', 'orders' => 'Orders', 'locations' => 'Locations'],
            collect($tabs)->mapWithKeys(fn ($tab) => [$tab->key => $tab->label])->all(),
        );
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

    public function test_only_item_low_stays_unraised(): void
    {
        $module = app(ModuleRegistry::class)->get(self::MODULE);

        $this->assertNotEmpty($module->triggers);

        $keys = [];
        $notRaised = [];
        foreach ($module->triggers as $trigger) {
            $this->assertStringStartsWith('item.', $trigger->key);
            if (! $trigger->isRaised) {
                $notRaised[] = $trigger->key;
            }
            $keys[] = $trigger->key;
        }

        $this->assertSame(['item.low'], $notRaised);
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
                'item.sale_opened',
                'item.sold',
                'item.sale_fulfilled',
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
