<?php

namespace Tests\Feature\Reports;

use App\Models\AirtableRecord;
use App\Models\AirtableRecordVersion;
use App\Models\FinancialSource;
use App\Models\SocialConnection;
use App\Support\Social\SocialProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/** The A1 Leads takeover (2026-10-16): Airtable copied into EzBizi, every change kept. */
class AirtableSnapshotTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    public function test_tables_are_copied_and_a_changed_row_keeps_its_earlier_state(): void
    {
        $t = $this->provisionTenant('Alpha');
        $this->inTenant($t['org'], function () use ($t) {
            $c = new SocialConnection;
            $c->forceFill(['organization_id' => $t['org']->id, 'scope_type' => SocialConnection::SCOPE_COMPANY, 'scope_id' => $t['company']->id,
                'provider' => SocialProvider::Airtable->value, 'remote_id' => 'appWhbwht0xgB5Yut', 'display_name' => 'P&L']);
            $c->connect(['api_token' => 'pat']);
            $c->save();
            // Only what the tenant mapped (Financials' sources) is copied.
            FinancialSource::query()->create(['base_id' => 'appe1vSSRRvKToShq', 'table_id' => 'tblxCKagahNfmjaSQ', 'role' => 'refunds']);
        });

        $refunds = [['id' => 'rec1', 'createdTime' => '2026-10-01T10:00:00.000Z', 'fields' => ['Refund Amount' => 120, 'Job Number' => '901']],
            ['id' => 'rec2', 'fields' => ['Refund Amount' => 50]]];
        $fake = function () use (&$refunds) {
            Http::fake([
                '*/meta/bases/*' => Http::response(['tables' => [['id' => 'tblxCKagahNfmjaSQ', 'name' => 'Refunds']]]),
                '*/tblxCKagahNfmjaSQ*' => Http::response(['records' => $refunds]),
                '*' => Http::response(['records' => []]),
            ]);
        };

        $fake();
        $this->artisan('ezbizi:airtable-snapshot')->assertSuccessful();

        $rows = AirtableRecord::withoutGlobalScopes()->where('table_id', 'tblxCKagahNfmjaSQ')->get()->keyBy('record_id');
        $this->assertCount(2, $rows);
        $this->assertSame('Refunds', $rows['rec1']->table_name);
        $this->assertSame(120, $rows['rec1']->fields['Refund Amount']);

        // rec1 changes, rec2 leaves Airtable.
        $refunds = [['id' => 'rec1', 'fields' => ['Refund Amount' => 150, 'Job Number' => '901']]];
        Http::swap(new Factory);
        $fake();
        $this->artisan('ezbizi:airtable-snapshot')->assertSuccessful();

        $rows = AirtableRecord::withoutGlobalScopes()->where('table_id', 'tblxCKagahNfmjaSQ')->get()->keyBy('record_id');
        $this->assertSame(150, $rows['rec1']->fields['Refund Amount']);
        $this->assertNotNull($rows['rec2']->gone_at, 'kept, marked gone');
        $this->assertSame(120, AirtableRecordVersion::withoutGlobalScopes()->sole()->fields['Refund Amount'], 'the earlier state is kept');
    }

    public function test_nothing_is_copied_for_a_tenant_with_nothing_mapped(): void
    {
        $this->provisionTenant('Alpha');
        Http::fake();

        $this->artisan('ezbizi:airtable-snapshot')->assertSuccessful();

        Http::assertNothingSent();
        $this->assertSame(0, AirtableRecord::withoutGlobalScopes()->count());
    }
}
