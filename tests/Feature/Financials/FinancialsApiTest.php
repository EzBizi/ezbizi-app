<?php

namespace Tests\Feature\Financials;

use App\Models\FinancialRecord;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

class FinancialsApiTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    public function test_a_bookkeeping_base_round_trips_records_fields_views_and_csv(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $admin = $tenant['admin'];

        $base = $this->signedInAs($admin)
            ->postJson('/api/v1/financials/bases', ['name' => 'Books', 'template' => 'bookkeeping'])
            ->assertCreated()->json('data');
        $tableId = $base['tables'][0]['id'];

        $table = $this->signedInAs($admin)->getJson("/api/v1/financials/tables/{$tableId}")->assertOk()->json('data');
        $fields = collect($table['fields'])->keyBy('name');
        $this->assertSame(['grid', 'kanban', 'calendar'], collect($table['views'])->pluck('type')->all());

        $record = $this->signedInAs($admin)->postJson("/api/v1/financials/tables/{$tableId}/records", ['values' => [
            $fields['Name']['id'] => 'Diesel',
            $fields['Amount']['id'] => '42.50',
            $fields['Category']['id'] => 'Fuel',
            $fields['Paid']['id'] => 'true',
        ]])->assertCreated()->json('data');
        $this->assertSame(42.5, $record['values'][$fields['Amount']['id']]);
        $this->assertTrue($record['values'][$fields['Paid']['id']]);

        // A choice that is not on the list is refused.
        $this->signedInAs($admin)->patchJson("/api/v1/financials/records/{$record['id']}", ['values' => [
            $fields['Category']['id'] => 'Yachts',
        ]])->assertStatus(422);

        // Partial update keeps the other cells; null clears one.
        $updated = $this->signedInAs($admin)->patchJson("/api/v1/financials/records/{$record['id']}", ['values' => [
            $fields['Paid']['id'] => null,
        ]])->assertOk()->json('data');
        $this->assertSame('Diesel', $updated['values'][$fields['Name']['id']]);
        $this->assertArrayNotHasKey($fields['Paid']['id'], $updated['values']);

        // Turning a text column into a number converts what converts.
        $this->signedInAs($admin)->patchJson("/api/v1/financials/fields/{$fields['Notes']['id']}", ['type' => 'number'])->assertOk();

        $imported = $this->signedInAs($admin)->postJson("/api/v1/financials/tables/{$tableId}/import", [
            'csv' => "Name,Amount,Vendor\nRent,1200,Landlord\nTape,3.99,Depot",
        ])->assertOk()->json('data');
        $this->assertSame(2, $imported['imported']);
        $this->assertContains('Vendor', collect($imported['table']['fields'])->pluck('name')->all());

        $csv = $this->signedInAs($admin)->get("/api/v1/financials/tables/{$tableId}/export")->assertOk()->getContent();
        $this->assertStringContainsString('Rent,', $csv);
        $this->assertStringContainsString('Diesel', $csv);

        $this->signedInAs($admin)->deleteJson("/api/v1/financials/fields/{$fields['Name']['id']}")->assertStatus(422);
        $this->signedInAs($admin)->deleteJson("/api/v1/financials/records/{$record['id']}")->assertNoContent();
        $this->inTenant($tenant['org'], fn () => $this->assertSame(2, FinancialRecord::query()->count()));
    }

    public function test_view_only_cannot_write_and_another_tenant_cannot_see(): void
    {
        $alpha = $this->provisionTenant('Alpha');
        $beta = $this->provisionTenant('Beta');
        $base = $this->signedInAs($alpha['admin'])
            ->postJson('/api/v1/financials/bases', ['name' => 'Books'])->assertCreated()->json('data');
        $tableId = $base['tables'][0]['id'];

        $viewer = $this->userWithRole($alpha, 'Viewer', ['financials.view', 'financials.bases.view', 'financials.settings.view']);
        $this->signedInAs($viewer)->getJson("/api/v1/financials/tables/{$tableId}")->assertOk();
        $this->signedInAs($viewer)->postJson("/api/v1/financials/tables/{$tableId}/records", ['values' => []])->assertForbidden();
        $this->signedInAs($viewer)->postJson('/api/v1/financials/bases', ['name' => 'Mine'])->assertForbidden();

        $this->signedInAs($beta['admin'])->getJson("/api/v1/financials/tables/{$tableId}")->assertNotFound();
        $this->assertSame([], $this->signedInAs($beta['admin'])->getJson('/api/v1/financials/bases')->json('data'));
    }
}
