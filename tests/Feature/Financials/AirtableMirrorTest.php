<?php

namespace Tests\Feature\Financials;

use App\Models\AirtableRecord;
use App\Models\FinancialRecord;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

class AirtableMirrorTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    private function row(array $tenant, string $id, array $fields): void
    {
        $this->inTenant($tenant['org'], fn () => AirtableRecord::query()->updateOrCreate(
            ['organization_id' => $tenant['org']->id, 'base_id' => 'appX', 'table_id' => 'tblR', 'record_id' => $id],
            ['table_name' => 'Repair NJ', 'fields' => $fields, 'fields_hash' => md5(json_encode($fields)), 'fetched_at' => now()],
        ));
    }

    public function test_a_linked_table_mirrors_the_copy_read_only_and_follows_its_changes(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $admin = $tenant['admin'];
        $teams = ['Team A', 'Team B', 'Team A', 'Team A', 'Team B'];
        foreach ($teams as $i => $team) {
            $this->row($tenant, "rec{$i}", ['Tech Name' => "Tech {$i}", 'Team' => $team, 'Date' => '2026-10-0'.($i + 1), 'Employee Cost' => 100.25 + $i, 'External Company' => $i === 0]);
        }

        $this->assertSame([['base_id' => 'appX', 'table_id' => 'tblR', 'table_name' => 'Repair NJ', 'rows' => 5]],
            $this->signedInAs($admin)->getJson('/api/v1/financials/airtable-tables')->assertOk()->json('data'));

        $base = $this->signedInAs($admin)->postJson('/api/v1/financials/bases', ['name' => 'A-1 books'])->json('data');
        $table = $this->signedInAs($admin)->postJson("/api/v1/financials/bases/{$base['id']}/airtable-links", ['base_id' => 'appX', 'table_id' => 'tblR'])
            ->assertCreated()->json('data');

        $types = collect($table['fields'])->pluck('type', 'name')->all();
        $this->assertSame(['date', 'number', 'checkbox', 'single_select', 'text'], [$types['Date'], $types['Employee Cost'], $types['External Company'], $types['Team'], $types['Tech Name']]);
        $this->assertCount(5, $table['records']);
        $this->assertSame('tblR', $table['mirror']['table_id']);

        // A renamed column keeps its link; a new Airtable column is added; a gone row leaves.
        $cost = collect($table['fields'])->firstWhere('name', 'Employee Cost');
        $this->signedInAs($admin)->patchJson("/api/v1/financials/fields/{$cost['id']}", ['name' => 'Paycheck', 'type' => 'currency'])->assertOk();
        $this->row($tenant, 'rec1', ['Tech Name' => 'Tech 1', 'Team' => 'Team C', 'Employee Cost' => 999, 'Gas Cost' => 12]);
        $this->inTenant($tenant['org'], fn () => AirtableRecord::query()->where('record_id', 'rec4')->update(['gone_at' => now()]));

        $this->artisan('ezbizi:financials-airtable-refresh')->assertSuccessful();

        $after = $this->signedInAs($admin)->getJson("/api/v1/financials/tables/{$table['id']}")->json('data');
        $fields = collect($after['fields'])->keyBy('name');
        $this->assertTrue($fields->has('Paycheck') && $fields->has('Gas Cost') && ! $fields->has('Employee Cost'));
        $this->assertCount(4, $after['records']);
        $rec1 = collect($after['records'])->first(fn ($r) => ($r['values'][$fields['Tech Name']['id']] ?? null) === 'Tech 1');
        $this->assertSame(999, $rec1['values'][$fields['Paycheck']['id']]);
        $this->assertSame('Team C', $rec1['values'][$fields['Team']['id']]);
        $this->assertContains('Team C', collect($fields['Team']['options']['choices'])->pluck('name'));
    }

    public function test_another_tenant_cannot_link_or_see_the_copy(): void
    {
        $alpha = $this->provisionTenant('Alpha');
        $beta = $this->provisionTenant('Beta');
        $this->row($alpha, 'rec1', ['Name' => 'x']);

        $this->assertSame([], $this->signedInAs($beta['admin'])->getJson('/api/v1/financials/airtable-tables')->json('data'));
        $base = $this->signedInAs($beta['admin'])->postJson('/api/v1/financials/bases', ['name' => 'B'])->json('data');
        $this->signedInAs($beta['admin'])->postJson("/api/v1/financials/bases/{$base['id']}/airtable-links", ['base_id' => 'appX', 'table_id' => 'tblR'])->assertStatus(422);
        $this->inTenant($beta['org'], fn () => $this->assertSame(0, FinancialRecord::query()->count()));
    }
}
