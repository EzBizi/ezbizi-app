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

    public function test_many_cells_change_at_once_or_none_do(): void
    {
        $t = $this->provisionTenant('Alpha');
        $as = $this->signedInAs($t['admin']);
        $base = $as->postJson('/api/v1/financials/bases', ['name' => 'Books', 'template' => 'bookkeeping'])->json('data');
        $tableId = $base['tables'][0]['id'];
        $fields = collect($as->getJson("/api/v1/financials/tables/{$tableId}")->json('data.fields'))->keyBy('name');
        $a = $as->postJson("/api/v1/financials/tables/{$tableId}/records", ['values' => [$fields['Name']['id'] => 'A']])->json('data.id');
        $b = $as->postJson("/api/v1/financials/tables/{$tableId}/records", ['values' => [$fields['Name']['id'] => 'B']])->json('data.id');
        $amount = $fields['Amount']['id'];

        // A paste of two amounts.
        $as->patchJson("/api/v1/financials/tables/{$tableId}/records", ['changes' => [
            ['id' => $a, 'values' => [$amount => '10.50']], ['id' => $b, 'values' => [$amount => '3']],
        ]])->assertOk()->assertJsonPath('data.0.values.'.$amount, 10.5);

        // One bad cell refuses the whole paste; nothing changed.
        $as->patchJson("/api/v1/financials/tables/{$tableId}/records", ['changes' => [
            ['id' => $a, 'values' => [$amount => '99']], ['id' => $b, 'values' => [$amount => 'lots']],
        ]])->assertStatus(422);
        $rows = collect($as->getJson("/api/v1/financials/tables/{$tableId}")->json('data.records'))->keyBy('id');
        $this->assertSame(10.5, $rows[$a]['values'][$amount]);

        // Delete over a selection is a change to null.
        $as->patchJson("/api/v1/financials/tables/{$tableId}/records", ['changes' => [['id' => $b, 'values' => [$amount => null]]]])->assertOk();
        $rows = collect($as->getJson("/api/v1/financials/tables/{$tableId}")->json('data.records'))->keyBy('id');
        $this->assertArrayNotHasKey($amount, $rows[$b]['values']);
    }

    public function test_column_widths_are_this_persons_and_kept_in_bounds(): void
    {
        $t = $this->provisionTenant('Alpha');
        $key = '01a0a0be-6b35-72d6-95c1-cef7e622fd3c:01a0a0be-6b35-72d6-95c1-cef7e622fd3d';
        $this->signedInAs($t['admin'])->patchJson('/api/v1/me/preferences', ['preferences' => ['financials.column_widths' => [
            $key => 2000, 'not a key' => 100,
        ]]])->assertOk();
        $this->assertSame([$key => 800], $this->signedInAs($t['admin'])->getJson('/api/v1/me')->json('data.preferences')['financials.column_widths']);
    }
}
