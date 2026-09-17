<?php

namespace Tests\Feature\Inventory;

use App\Models\InventoryIssue;
use App\Models\InventoryItem;
use App\Models\InventoryLocation;
use App\Models\InventoryPurchaseOrder;
use App\Models\InventoryVendor;
use App\Support\Inventory\InventoryPurchaseOrderStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/**
 * Inventory files hang off the subject; PO invoices stay behind financials (spec §9–§10).
 */
class InventoryAttachmentsTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
    }

    public function test_an_item_photo_uploads_with_edit(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $editor = $this->userWithRole($tenant, 'Editor', [
            'inventory.view', 'inventory.stock.view', 'inventory.items.view', 'inventory.orders.view', 'inventory.locations.view',
            'inventory.edit',
        ]);
        $viewer = $this->userWithRole($tenant, 'Viewer', [
            'inventory.view', 'inventory.stock.view', 'inventory.items.view', 'inventory.orders.view', 'inventory.locations.view',
        ]);

        $item = $this->inTenant($tenant['org'], function () use ($tenant) {
            return InventoryItem::factory()->create([
                'company_id' => $tenant['company']->id,
                'franchise_id' => $tenant['franchise']->id,
            ]);
        });

        $id = $this->signedInAs($editor)->post('/api/v1/attachments', [
            'attachable_type' => 'inventory_items',
            'attachable_id' => $item->id,
            'file' => UploadedFile::fake()->image('liner.png', 40, 40),
        ], ['Accept' => 'application/json'])
            ->assertCreated()
            ->assertJsonPath('data.attachable.type', 'inventory_items')
            ->assertJsonPath('data.attachable.id', $item->id)
            ->json('data.id');

        $this->signedInAs($viewer)->get("/api/v1/attachments/{$id}")->assertOk();
    }

    public function test_po_attachment_without_financials_is_forbidden_with_the_inventory_sentence(): void
    {
        $tenant = $this->provisionTenant('Alpha');

        // Edit without financials: upload authorizes update, which must refuse
        // with the money sentence — not the generic edit refusal.
        $editor = $this->userWithRole($tenant, 'Editor', [
            'inventory.view', 'inventory.stock.view', 'inventory.items.view', 'inventory.orders.view', 'inventory.locations.view',
            'inventory.edit',
        ]);
        $money = $this->userWithRole($tenant, 'Money', [
            'inventory.view', 'inventory.stock.view', 'inventory.items.view', 'inventory.orders.view', 'inventory.locations.view',
            'inventory.edit',
            'inventory.financials',
        ]);

        $order = $this->purchaseOrder($tenant);

        $this->signedInAs($editor)->post('/api/v1/attachments', [
            'attachable_type' => 'inventory_purchase_orders',
            'attachable_id' => $order->id,
            'file' => UploadedFile::fake()->createWithContent(
                'invoice.pdf',
                "%PDF-1.4\n%%EOF\n",
            ),
        ], ['Accept' => 'application/json'])
            ->assertForbidden()
            ->assertJson([
                'message' => 'Costs on inventory are hidden for your role.',
            ]);

        $this->signedInAs($money)->post('/api/v1/attachments', [
            'attachable_type' => 'inventory_purchase_orders',
            'attachable_id' => $order->id,
            'file' => UploadedFile::fake()->createWithContent(
                'invoice.pdf',
                "%PDF-1.4\n%%EOF\n",
            ),
        ], ['Accept' => 'application/json'])
            ->assertCreated()
            ->assertJsonPath('data.attachable.type', 'inventory_purchase_orders')
            ->assertJsonPath('data.attachable.id', $order->id);
    }

    /**
     * A receipt URL is a back door to the ledger (spec §9).
     *
     * GET /attachments/{id} authorizes `view` on the PO, and the header is
     * visible without financials. The file still must not be.
     */
    public function test_a_po_file_is_not_readable_without_financials(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $editor = $this->userWithRole($tenant, 'Editor', [
            'inventory.view', 'inventory.stock.view', 'inventory.items.view', 'inventory.orders.view', 'inventory.locations.view',
            'inventory.edit',
        ]);
        $money = $this->userWithRole($tenant, 'Money', [
            'inventory.view', 'inventory.stock.view', 'inventory.items.view', 'inventory.orders.view', 'inventory.locations.view',
            'inventory.edit',
            'inventory.financials',
        ]);

        $order = $this->purchaseOrder($tenant);

        $id = $this->signedInAs($money)->post('/api/v1/attachments', [
            'attachable_type' => 'inventory_purchase_orders',
            'attachable_id' => $order->id,
            'file' => UploadedFile::fake()->createWithContent(
                'invoice.pdf',
                "%PDF-1.4\n%%EOF\n",
            ),
        ], ['Accept' => 'application/json'])
            ->assertCreated()
            ->json('data.id');

        $this->signedInAs($editor)
            ->getJson('/api/v1/attachments?attachable_type=inventory_purchase_orders&attachable_id='.$order->id)
            ->assertForbidden()
            ->assertJson([
                'message' => 'Costs on inventory are hidden for your role.',
            ]);

        $this->signedInAs($editor)->get("/api/v1/attachments/{$id}")
            ->assertForbidden()
            ->assertJson([
                'message' => 'Costs on inventory are hidden for your role.',
            ]);

        $this->signedInAs($money)->get("/api/v1/attachments/{$id}")->assertOk();
    }

    public function test_a_vendor_file_uploads_with_edit(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $editor = $this->userWithRole($tenant, 'Editor', [
            'inventory.view', 'inventory.stock.view', 'inventory.items.view', 'inventory.orders.view', 'inventory.locations.view',
            'inventory.edit',
        ]);

        $vendor = $this->inTenant($tenant['org'], function () use ($tenant) {
            return InventoryVendor::factory()->create([
                'company_id' => $tenant['company']->id,
                'franchise_id' => $tenant['franchise']->id,
            ]);
        });

        $this->signedInAs($editor)->post('/api/v1/attachments', [
            'attachable_type' => 'inventory_vendors',
            'attachable_id' => $vendor->id,
            'file' => UploadedFile::fake()->createWithContent(
                'w9.pdf',
                "%PDF-1.4\n%%EOF\n",
            ),
        ], ['Accept' => 'application/json'])
            ->assertCreated()
            ->assertJsonPath('data.attachable.type', 'inventory_vendors')
            ->assertJsonPath('data.attachable.id', $vendor->id);
    }

    public function test_an_issue_ticket_uploads_with_edit(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $editor = $this->userWithRole($tenant, 'Editor', [
            'inventory.view', 'inventory.stock.view', 'inventory.items.view', 'inventory.orders.view', 'inventory.locations.view',
            'inventory.edit',
        ]);

        $issue = $this->inTenant($tenant['org'], function () use ($tenant) {
            $location = InventoryLocation::factory()->create([
                'company_id' => $tenant['company']->id,
                'franchise_id' => $tenant['franchise']->id,
            ]);

            return InventoryIssue::create([
                'location_id' => $location->id,
                'note' => 'sold over the counter',
                'number' => 'IS-'.bin2hex(random_bytes(4)),
                'issued_on' => '2026-08-24',
            ]);
        });

        $this->signedInAs($editor)->post('/api/v1/attachments', [
            'attachable_type' => 'inventory_issues',
            'attachable_id' => $issue->id,
            'file' => UploadedFile::fake()->image('ticket.png', 40, 40),
        ], ['Accept' => 'application/json'])
            ->assertCreated()
            ->assertJsonPath('data.attachable.type', 'inventory_issues')
            ->assertJsonPath('data.attachable.id', $issue->id);
    }

    public function test_po_json_omits_attachments_without_financials(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $viewer = $this->userWithRole($tenant, 'Viewer', ['inventory.view', 'inventory.stock.view', 'inventory.items.view', 'inventory.orders.view', 'inventory.locations.view']);
        $money = $this->userWithRole($tenant, 'Money', [
            'inventory.view', 'inventory.stock.view', 'inventory.items.view', 'inventory.orders.view', 'inventory.locations.view',
            'inventory.financials',
        ]);

        $order = $this->purchaseOrder($tenant);

        $stripped = $this->signedInAs($viewer)
            ->getJson('/api/v1/inventory/purchase-orders/'.$order->id)
            ->assertOk()
            ->json('data');

        $this->assertSame($order->id, $stripped['id']);
        $this->assertArrayNotHasKey('attachments', $stripped);

        $kept = $this->signedInAs($money)
            ->getJson('/api/v1/inventory/purchase-orders/'.$order->id)
            ->assertOk()
            ->json('data');

        $this->assertSame($order->id, $kept['id']);
        $this->assertArrayNotHasKey('attachments', $kept);
    }

    /** @param  array{org: \App\Models\Organization, company: \App\Models\Company, franchise: \App\Models\Franchise}  $tenant */
    private function purchaseOrder(array $tenant): InventoryPurchaseOrder
    {
        return $this->inTenant($tenant['org'], function () use ($tenant) {
            $vendor = InventoryVendor::factory()->create([
                'company_id' => $tenant['company']->id,
                'franchise_id' => $tenant['franchise']->id,
            ]);

            return InventoryPurchaseOrder::create([
                'company_id' => $tenant['company']->id,
                'franchise_id' => $tenant['franchise']->id,
                'vendor_id' => $vendor->id,
                'number' => 'PO-'.bin2hex(random_bytes(4)),
                'status' => InventoryPurchaseOrderStatus::Draft,
            ]);
        });
    }
}
