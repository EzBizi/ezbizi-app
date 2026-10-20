<?php

namespace Tests\Feature\Financials;

use App\Models\AirtableRecord;
use App\Models\FinancialAdjustment;
use App\Models\FinancialSource;
use App\Models\Franchise;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

class FinancialsHomeTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    public function test_cards_come_from_mapped_sources_and_native_records_inside_the_view(): void
    {
        $this->travelTo(now()->parse('2026-10-08 12:00'));
        $t = $this->provisionTenant('Alpha');
        $other = $this->inTenant($t['org'], fn () => Franchise::create(['organization_id' => $t['org']->id, 'company_id' => $t['company']->id, 'name' => 'Alpha F2']));

        $this->inTenant($t['org'], function () use ($t, $other) {
            $fields = ['Date' => '2026-10-06', 'Company Name' => 'F1', 'Technician Name' => 'Ann', 'Refund/dispute Amount' => 100];
            AirtableRecord::query()->create(['organization_id' => $t['org']->id, 'base_id' => 'appR', 'table_id' => 'tblRef', 'table_name' => 'Refunds',
                'record_id' => 'rec1', 'fields' => $fields, 'fields_hash' => md5('x'), 'fetched_at' => now()]);
            FinancialSource::create(['base_id' => 'appR', 'table_id' => 'tblRef', 'role' => 'refunds', 'franchise_id' => $t['franchise']->id,
                'company_id' => $t['company']->id, 'filter_field' => 'Company Name', 'filter_value' => 'F1']);
            FinancialSource::create(['base_id' => 'appP', 'table_id' => 'tblPnl2', 'role' => 'pnl', 'franchise_id' => $other->id, 'company_id' => $t['company']->id]);
            FinancialAdjustment::create(['franchise_id' => $t['franchise']->id, 'company_id' => $t['company']->id, 'kind' => 'refund',
                'date' => '2026-10-07', 'technician' => 'Bob', 'cents' => 2550]);
        });
        $this->signedInAs($t['admin'])->postJson('/api/v1/financials/bases', ['name' => 'Budget', 'template' => 'budget'])->assertCreated();

        $home = $this->signedInAs($t['admin'])->getJson('/api/v1/financials/home')->assertOk()->json('data');
        $areas = collect($home['areas'])->keyBy('key');
        $this->assertSame(['refunds', 'pnl'], $areas->keys()->all());
        $this->assertSame('both', $areas['refunds']['source']);
        $this->assertSame('This week: $125.50 refunded · 0 disputes', $areas['refunds']['figure']);
        $this->assertSame(['Refunds · F1', 'Recorded in EzBizi'], array_column($areas['refunds']['tables'], 'name'));
        $this->assertSame('airtable', $areas['pnl']['source']);
        $this->assertSame([null, '/financials/adjustments'], array_column($areas['refunds']['tables'], 'route'));
        $this->assertSame([false, true], array_column($areas['refunds']['tables'], 'may_open'));
        $this->assertSame('Budget', $home['bases'][0]['name']);
        $this->assertSame('native', $home['bases'][0]['source']);
        $this->assertTrue($home['may_create_base']);

        // Somebody at F1 only never gets F2's P&L card.
        $f1 = $this->userWithRole($t, 'F1 office', ['financials.view', 'financials.bases.view'], onlyAt: $t['franchise']);
        $mine = $this->signedInAs($f1)->getJson('/api/v1/financials/home')->assertOk()->json('data');
        $this->assertSame(['refunds'], array_column($mine['areas'], 'key'));
        $this->assertFalse($mine['may_create_base']);

        // A business with no Airtable and nothing recorded: no area cards at all.
        $beta = $this->provisionTenant('Beta');
        $this->assertSame([], $this->signedInAs($beta['admin'])->getJson('/api/v1/financials/home')->json('data.areas'));
    }
}
