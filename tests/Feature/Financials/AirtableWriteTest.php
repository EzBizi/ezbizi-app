<?php

namespace Tests\Feature\Financials;

use App\Models\AirtableRecord;
use App\Models\FinancialRecord;
use App\Models\SocialConnection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/** Jose, 2026-10-21: edits in EzBizi land in Airtable; Airtable's own changes are never overwritten. */
class AirtableWriteTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    /** Airtable as it stands now: rec2's Amount was changed there after our copy. */
    private array $airtable = [
        'rec1' => ['Name' => 'Diesel', 'Amount' => 10, 'Total' => 10],
        'rec2' => ['Name' => 'Rent', 'Amount' => 999, 'Total' => 999],
    ];

    /** @var list<array{method: string, body: array}> */
    private array $writes = [];

    private function fakeAirtable(): void
    {
        Http::fake(function (Request $request) {
            $url = $request->url();
            if (str_contains($url, '/meta/bases/appX/tables')) {
                return Http::response(['tables' => [['id' => 'tblR', 'fields' => [
                    ['name' => 'Name', 'type' => 'singleLineText'], ['name' => 'Amount', 'type' => 'currency'], ['name' => 'Total', 'type' => 'formula'],
                ]]]]);
            }
            if ($request->method() === 'GET') {
                return Http::response(['records' => collect($this->airtable)->map(fn ($f, $id) => ['id' => $id, 'fields' => $f])->values()->all()]);
            }
            $this->writes[] = ['method' => $request->method(), 'body' => $request->data()];
            if ($request->method() === 'PATCH') {
                $out = [];
                foreach ($request->data()['records'] as $r) {
                    $this->airtable[$r['id']] = array_merge($this->airtable[$r['id']], $r['fields']);
                    $out[] = ['id' => $r['id'], 'fields' => $this->airtable[$r['id']]];
                }

                return Http::response(['records' => $out]);
            }
            if ($request->method() === 'POST') {
                $this->airtable['rec3'] = (array) $request->data()['records'][0]['fields'];

                return Http::response(['records' => [['id' => 'rec3', 'fields' => $this->airtable['rec3'], 'createdTime' => now()->toIso8601String()]]]);
            }

            return Http::response(['records' => [['id' => 'rec1', 'deleted' => true]]]);
        });
    }

    public function test_edits_write_to_airtable_and_a_cell_changed_there_first_is_not_overwritten(): void
    {
        $t = $this->provisionTenant('Alpha');
        $this->inTenant($t['org'], function () use ($t) {
            $c = new SocialConnection;
            $c->forceFill(['organization_id' => $t['org']->id, 'scope_type' => 'organization', 'scope_id' => $t['org']->id,
                'provider' => 'airtable', 'remote_id' => 'appX', 'display_name' => 'Books']);
            $c->connect(['api_token' => 'pat_x', 'refresh_token' => 'pat_x', 'base_id' => 'appX']);
            $c->save();
            foreach (['rec1' => ['Name' => 'Diesel', 'Amount' => 10, 'Total' => 10], 'rec2' => ['Name' => 'Rent', 'Amount' => 20, 'Total' => 20]] as $id => $f) {
                AirtableRecord::query()->create(['organization_id' => $t['org']->id, 'base_id' => 'appX', 'table_id' => 'tblR', 'table_name' => 'Costs',
                    'record_id' => $id, 'fields' => $f, 'fields_hash' => md5(json_encode($f)), 'fetched_at' => now()]);
            }
        });
        $this->fakeAirtable();
        $as = $this->signedInAs($t['admin']);
        $base = $as->postJson('/api/v1/financials/bases', ['name' => 'B'])->json('data.id');
        $table = $as->postJson("/api/v1/financials/bases/{$base}/airtable-links", ['base_id' => 'appX', 'table_id' => 'tblR'])->assertCreated()->json('data');
        $fields = collect($table['fields'])->keyBy('name');
        $this->assertTrue($fields['Total']['locked']);
        $this->assertFalse($fields['Amount']['locked']);
        $rows = collect($table['records'])->keyBy(fn ($r) => $r['values'][$fields['Name']['id']]);

        // A paste over both rows' Amount: rec1 writes; rec2 changed in Airtable first, so it is reported and takes theirs.
        $answer = $as->patchJson("/api/v1/financials/tables/{$table['id']}/records", ['changes' => [
            ['id' => $rows['Diesel']['id'], 'values' => [$fields['Amount']['id'] => '15']],
            ['id' => $rows['Rent']['id'], 'values' => [$fields['Amount']['id'] => '25']],
        ]])->assertOk()->json();
        $this->assertSame([['id' => 'rec1', 'fields' => ['Amount' => 15]]], $this->writes[0]['body']['records']);
        $this->assertSame([['record' => $rows['Rent']['id'], 'field' => 'Amount', 'theirs' => 999, 'yours' => 25]], $answer['conflicts']);
        $now = collect($answer['data'])->keyBy('id');
        $this->assertSame(15, $now[$rows['Diesel']['id']]['values'][$fields['Amount']['id']]);
        $this->assertSame(999, $now[$rows['Rent']['id']]['values'][$fields['Amount']['id']]);

        // Computed columns are never written.
        $as->patchJson("/api/v1/financials/tables/{$table['id']}/records", ['changes' => [
            ['id' => $rows['Diesel']['id'], 'values' => [$fields['Total']['id'] => '1']],
        ]])->assertStatus(422);

        // A new row is made in Airtable first; deleting removes it there.
        $made = $as->postJson("/api/v1/financials/tables/{$table['id']}/records", ['values' => [$fields['Name']['id'] => 'Tape']])->assertCreated()->json('data');
        $this->assertSame('POST', $this->writes[1]['method']);
        $as->deleteJson("/api/v1/financials/records/{$made['id']}")->assertNoContent();
        $this->assertSame('DELETE', $this->writes[2]['method']);
        $this->inTenant($t['org'], fn () => $this->assertFalse(FinancialRecord::query()->whereKey($made['id'])->exists()));

        // Columns still change in Airtable only.
        $as->postJson("/api/v1/financials/tables/{$table['id']}/fields", ['name' => 'X', 'type' => 'text'])->assertStatus(422);

        // Cut over: EzBizi keeps it; nothing more goes to Airtable, and the hourly refresh leaves it alone.
        $as->postJson("/api/v1/financials/tables/{$table['id']}/cut-over")->assertOk()->assertJsonPath('data.mirror', null);
        $before = count($this->writes);
        $as->patchJson("/api/v1/financials/tables/{$table['id']}/records", ['changes' => [
            ['id' => $rows['Diesel']['id'], 'values' => [$fields['Amount']['id'] => '77']],
        ]])->assertOk();
        $as->postJson("/api/v1/financials/tables/{$table['id']}/fields", ['name' => 'Notes here', 'type' => 'text'])->assertCreated();
        $this->assertCount($before, $this->writes);
        $this->artisan('ezbizi:financials-airtable-refresh')->assertSuccessful();
        $diesel = collect($as->getJson("/api/v1/financials/tables/{$table['id']}")->json('data.records'))->firstWhere('id', $rows['Diesel']['id']);
        $this->assertSame(77, $diesel['values'][$fields['Amount']['id']]);
    }
}
