<?php

namespace Tests\Feature\Inventory;

use App\Models\AutomationEvent;
use App\Models\Company;
use App\Models\InventoryItem;
use App\Models\InventoryLocation;
use App\Models\InventoryVendor;
use App\Models\Organization;
use App\Models\User;
use App\Support\Modules\ModuleRegistry;
use App\Support\Modules\TriggerDefinition;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/**
 * Inventory events in the outbox (spec §12).
 *
 * ModuleRegistryTest already greps app/ for quoted trigger keys. This watches
 * the outbox, because the same strings are easy to mention without emitting.
 * Subject is the item. item.low stays unraised — no sweep in this plan.
 */
class InventoryEventsTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    public function test_a_receive_emits_item_received_carrying_the_item(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $admin = $tenant['admin'];

        [$vendor, $item, $location] = $this->vendorItemLocation($tenant);
        $po = $this->openOrder($admin, $vendor, $item, $tenant, 10);

        $this->signedInAs($admin)
            ->postJson('/api/v1/inventory/purchase-orders/'.$po['id'].'/receives', [
                'location_id' => $location->id,
                'lines' => [
                    ['purchase_order_line_id' => $po['lines'][0]['id'], 'qty' => 4],
                ],
            ])
            ->assertCreated();

        $this->inTenant($tenant['org'], function () use ($item, $location, $po) {
            $received = AutomationEvent::query()->where('event_key', 'item.received')->first();
            $this->assertNotNull($received);
            $this->assertSame($item->id, $received->subject_id);
            $this->assertSame(InventoryItem::class, $received->subject_type);
            $this->assertSame('4.000', $received->payload['after']['qty'] ?? null);
            $this->assertSame($location->id, $received->payload['after']['location_id'] ?? null);
            $this->assertSame($po['id'], $received->payload['after']['purchase_order_id'] ?? null);

            $this->assertFalse(
                AutomationEvent::query()->where('event_key', 'item.updated')->exists(),
                'A movement does not raise item.updated.',
            );
            $this->assertFalse(
                AutomationEvent::query()->where('event_key', 'item.purchase_received')->exists(),
            );
            $this->assertFalse(
                AutomationEvent::query()->where('event_key', 'item.low')->exists(),
            );
        });
    }

    public function test_identity_save_writes_updated_and_a_full_receive_writes_purchase_received(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $admin = $tenant['admin'];

        $created = $this->signedInAs($admin)
            ->postJson('/api/v1/inventory/items', [
                'company_id' => $tenant['company']->id,
                'franchise_id' => $tenant['franchise']->id,
                'sku' => 'LINER-6',
                'name' => '6 mil liner',
                'unit' => 'each',
            ])
            ->assertCreated()
            ->json('data');

        $this->inTenant($tenant['org'], function () use ($created) {
            $event = AutomationEvent::query()->where('event_key', 'item.created')->first();
            $this->assertNotNull($event);
            $this->assertSame($created['id'], $event->subject_id);
            $this->assertSame(InventoryItem::class, $event->subject_type);
            $this->assertSame('LINER-6', $event->payload['after']['sku'] ?? null);
            $this->assertArrayNotHasKey('last_cost_minor', $event->payload['after'] ?? []);
        });

        $this->signedInAs($admin)
            ->patchJson('/api/v1/inventory/items/'.$created['id'], ['name' => '8 mil liner'])
            ->assertOk();

        $this->inTenant($tenant['org'], function () use ($created) {
            $updated = AutomationEvent::query()->where('event_key', 'item.updated')->first();
            $this->assertNotNull($updated);
            $this->assertSame($created['id'], $updated->subject_id);
            $this->assertSame('6 mil liner', $updated->payload['before']['name'] ?? null);
            $this->assertSame('8 mil liner', $updated->payload['after']['name'] ?? null);
        });

        [$vendor, $item, $location] = $this->vendorItemLocation($tenant, itemId: $created['id']);
        $po = $this->openOrder($admin, $vendor, $item, $tenant, 10);

        $this->inTenant($tenant['org'], function () use ($item, $po) {
            $ordered = AutomationEvent::query()->where('event_key', 'item.ordered')->first();
            $this->assertNotNull($ordered);
            $this->assertSame($item->id, $ordered->subject_id);
            $this->assertSame($po['id'], $ordered->payload['after']['purchase_order_id'] ?? null);
        });

        $this->signedInAs($admin)
            ->postJson('/api/v1/inventory/purchase-orders/'.$po['id'].'/receives', [
                'location_id' => $location->id,
                'lines' => [
                    ['purchase_order_line_id' => $po['lines'][0]['id'], 'qty' => 10],
                ],
            ])
            ->assertCreated();

        $this->inTenant($tenant['org'], function () use ($item, $po) {
            $this->assertSame(1, AutomationEvent::query()->where('event_key', 'item.updated')->count());
            $fully = AutomationEvent::query()->where('event_key', 'item.purchase_received')->first();
            $this->assertNotNull($fully);
            $this->assertSame($item->id, $fully->subject_id);
            $this->assertSame($po['id'], $fully->payload['after']['purchase_order_id'] ?? null);
        });
    }

    public function test_every_raised_item_trigger_is_emitted_and_item_low_is_not(): void
    {
        $module = app(ModuleRegistry::class)->get('inventory');
        $this->assertNotNull($module);

        $notRaised = array_values(array_filter(
            $module->triggers,
            fn (TriggerDefinition $t) => ! $t->isRaised,
        ));

        $this->assertSame(
            ['item.low'],
            array_map(fn (TriggerDefinition $t) => $t->key, $notRaised),
        );
        $this->assertStringContainsString(TriggerDefinition::NOT_RAISED_YET, $notRaised[0]->description);

        $tenant = $this->provisionTenant('Alpha');
        $this->walkTheWritePath($tenant);

        $emitted = $this->inTenant($tenant['org'], fn () => AutomationEvent::query()
            ->pluck('event_key')->unique()->values()->all());

        foreach ($module->triggers as $trigger) {
            if (! $trigger->isRaised) {
                $this->assertNotContains($trigger->key, $emitted, $trigger->key);
                continue;
            }

            $this->assertContains(
                $trigger->key,
                $emitted,
                "Inventory declares “{$trigger->label}” as raised, and a walk through the write "
                .'path never wrote it to the outbox.',
            );
        }
    }

    /**
     * @param  array{org: Organization, admin: User, company: Company, franchise: \App\Models\Franchise}  $tenant
     */
    private function walkTheWritePath(array $tenant): void
    {
        $admin = $tenant['admin'];

        $itemId = $this->signedInAs($admin)
            ->postJson('/api/v1/inventory/items', [
                'company_id' => $tenant['company']->id,
                'franchise_id' => $tenant['franchise']->id,
                'sku' => 'WALK-1',
                'name' => 'Walk liner',
                'unit' => 'each',
            ])
            ->assertCreated()
            ->json('data.id');

        $this->signedInAs($admin)
            ->patchJson('/api/v1/inventory/items/'.$itemId, ['name' => 'Walk liner 2'])
            ->assertOk();

        [$vendor, $item, $from] = $this->vendorItemLocation($tenant, itemId: $itemId);

        $to = $this->inTenant($tenant['org'], function () use ($tenant) {
            return InventoryLocation::factory()->create([
                'company_id' => $tenant['company']->id,
                'franchise_id' => $tenant['franchise']->id,
                'name' => 'Van bay',
            ]);
        });

        $po = $this->openOrder($admin, $vendor, $item, $tenant, 10);

        $this->signedInAs($admin)
            ->postJson('/api/v1/inventory/purchase-orders/'.$po['id'].'/receives', [
                'location_id' => $from->id,
                'lines' => [
                    ['purchase_order_line_id' => $po['lines'][0]['id'], 'qty' => 10],
                ],
            ])
            ->assertCreated();

        $this->signedInAs($admin)
            ->postJson('/api/v1/inventory/transfers', [
                'from_location_id' => $from->id,
                'to_location_id' => $to->id,
                'lines' => [
                    ['item_id' => $item->id, 'qty' => 3],
                ],
            ])
            ->assertCreated();

        $this->signedInAs($admin)
            ->postJson('/api/v1/inventory/issues', [
                'location_id' => $from->id,
                'note' => 'sold over the counter',
                'lines' => [
                    ['item_id' => $item->id, 'qty' => 2],
                ],
            ])
            ->assertCreated();

        $this->signedInAs($admin)
            ->postJson('/api/v1/inventory/adjustments', [
                'location_id' => $from->id,
                'item_id' => $item->id,
                'counted_as' => 4,
                'reason' => 'cycle count',
            ])
            ->assertCreated();
    }

    /**
     * @param  array{org: Organization, company: Company, franchise: \App\Models\Franchise}  $tenant
     * @return array{0: InventoryVendor, 1: InventoryItem, 2: InventoryLocation}
     */
    private function vendorItemLocation(array $tenant, ?string $itemId = null): array
    {
        return $this->inTenant($tenant['org'], function () use ($tenant, $itemId) {
            $vendor = InventoryVendor::factory()->create([
                'company_id' => $tenant['company']->id,
                'franchise_id' => $tenant['franchise']->id,
                'name' => 'Liner supply',
            ]);
            $item = $itemId === null
                ? InventoryItem::factory()->create([
                    'company_id' => $tenant['company']->id,
                    'franchise_id' => $tenant['franchise']->id,
                    'name' => '6 mil liner',
                ])
                : InventoryItem::query()->findOrFail($itemId);
            $location = InventoryLocation::factory()->create([
                'company_id' => $tenant['company']->id,
                'franchise_id' => $tenant['franchise']->id,
                'name' => 'Receiving',
            ]);

            return [$vendor, $item, $location];
        });
    }

    /**
     * @param  array{org: Organization, company: Company, franchise: \App\Models\Franchise}  $tenant
     * @return array<string, mixed>
     */
    private function openOrder(
        User $admin,
        InventoryVendor $vendor,
        InventoryItem $item,
        array $tenant,
        int $qty,
    ): array {
        $draft = $this->signedInAs($admin)
            ->postJson('/api/v1/inventory/purchase-orders', [
                'vendor_id' => $vendor->id,
                'company_id' => $tenant['company']->id,
                'franchise_id' => $tenant['franchise']->id,
                'lines' => [
                    ['item_id' => $item->id, 'qty' => $qty],
                ],
            ])
            ->assertCreated()
            ->json('data');

        $this->signedInAs($admin)
            ->postJson('/api/v1/inventory/purchase-orders/'.$draft['id'].'/open')
            ->assertOk();

        $draft['status'] = 'open';

        return $draft;
    }
}
