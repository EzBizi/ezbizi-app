<?php

namespace Tests\Feature\Api;

use App\Models\Vehicle;
use App\Models\VehicleInsurancePolicy;
use App\Models\VehiclePayment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/**
 * Fleet files hang off the subject; payment receipts stay behind financials (spec §7–§8).
 */
class FleetAttachmentsTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
    }

    public function test_a_vehicle_photo_is_readable_with_view(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $viewer = $this->userWithRole($tenant, 'Viewer', ['fleet_management.view']);

        $vehicle = $this->inTenant($tenant['org'], function () use ($tenant) {
            return Vehicle::factory()->create([
                'company_id' => $tenant['company']->id,
                'franchise_id' => $tenant['franchise']->id,
            ]);
        });

        $id = $this->signedInAs($tenant['admin'])->post('/api/v1/attachments', [
            'attachable_type' => 'vehicles',
            'attachable_id' => $vehicle->id,
            'file' => UploadedFile::fake()->image('van.png', 40, 40),
        ], ['Accept' => 'application/json'])
            ->assertCreated()
            ->json('data.id');

        $this->signedInAs($viewer)->get("/api/v1/attachments/{$id}")->assertOk();
    }

    public function test_an_insurance_pdf_is_readable_with_view(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $viewer = $this->userWithRole($tenant, 'Viewer', ['fleet_management.view']);

        $policy = $this->inTenant($tenant['org'], function () use ($tenant) {
            $vehicle = Vehicle::factory()->create([
                'company_id' => $tenant['company']->id,
                'franchise_id' => $tenant['franchise']->id,
            ]);

            return VehicleInsurancePolicy::factory()->create([
                'vehicle_id' => $vehicle->id,
                'ended_on' => null,
            ]);
        });

        $content = "%PDF-1.4\n%%EOF\n";

        $id = $this->signedInAs($tenant['admin'])->post('/api/v1/attachments', [
            'attachable_type' => 'vehicle_insurance_policies',
            'attachable_id' => $policy->id,
            'file' => UploadedFile::fake()->createWithContent('policy.pdf', $content),
        ], ['Accept' => 'application/json'])
            ->assertCreated()
            ->json('data.id');

        $body = $this->signedInAs($viewer)->get("/api/v1/attachments/{$id}")
            ->assertOk()
            ->streamedContent();

        $this->assertSame($content, $body);
    }

    public function test_payment_receipt_upload_needs_financials_with_the_fleet_sentence(): void
    {
        $tenant = $this->provisionTenant('Alpha');

        // Edit without financials: upload authorizes update, which must refuse
        // with the money sentence — not the generic edit refusal.
        $viewer = $this->userWithRole($tenant, 'Viewer', [
            'fleet_management.view',
            'fleet_management.edit',
        ]);
        $money = $this->userWithRole($tenant, 'Money', [
            'fleet_management.view',
            'fleet_management.edit',
            'fleet_management.financials',
        ]);

        $payment = $this->inTenant($tenant['org'], function () use ($tenant) {
            $vehicle = Vehicle::factory()->create([
                'company_id' => $tenant['company']->id,
                'franchise_id' => $tenant['franchise']->id,
            ]);

            return VehiclePayment::factory()->create([
                'vehicle_id' => $vehicle->id,
            ]);
        });

        $file = UploadedFile::fake()->createWithContent(
            'receipt.pdf',
            "%PDF-1.4\n%%EOF\n",
        );

        $this->signedInAs($viewer)->post('/api/v1/attachments', [
            'attachable_type' => 'vehicle_payments',
            'attachable_id' => $payment->id,
            'file' => $file,
        ], ['Accept' => 'application/json'])
            ->assertForbidden()
            ->assertJson([
                'message' => 'Amounts on the fleet are hidden for your role.',
            ]);

        $this->signedInAs($money)->post('/api/v1/attachments', [
            'attachable_type' => 'vehicle_payments',
            'attachable_id' => $payment->id,
            'file' => UploadedFile::fake()->createWithContent(
                'receipt.pdf',
                "%PDF-1.4\n%%EOF\n",
            ),
        ], ['Accept' => 'application/json'])
            ->assertCreated()
            ->assertJsonPath('data.attachable.type', 'vehicle_payments')
            ->assertJsonPath('data.attachable.id', $payment->id);
    }
}
