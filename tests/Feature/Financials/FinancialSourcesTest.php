<?php

namespace Tests\Feature\Financials;

use App\Models\FinancialSource;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

class FinancialSourcesTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    public function test_an_organization_maps_its_own_airtable_tables_and_a_viewer_or_another_tenant_cannot(): void
    {
        $alpha = $this->provisionTenant('Alpha');
        $beta = $this->provisionTenant('Beta');
        $as = $this->signedInAs($alpha['admin']);

        $id = $as->postJson('/api/v1/financials/sources', [
            'base_id' => 'appR', 'table_id' => 'tblNJ', 'role' => 'repair', 'franchise_id' => $alpha['franchise']->id, 'company_id' => $alpha['company']->id,
            'field_map' => ['team' => 'Team', 'technician' => 'Tech Name', 'date' => 'Date'],
        ])->assertCreated()->json('data.id');

        $as->postJson('/api/v1/financials/sources', ['base_id' => 'appR', 'table_id' => 'tblNJ', 'role' => 'pnl', 'franchise_id' => $alpha['franchise']->id])->assertStatus(422);
        // One shared table, one row per place, told apart by a filter; markers are values.
        $shared = $as->postJson('/api/v1/financials/sources', [
            'base_id' => 'appF', 'table_id' => 'tblRefunds', 'role' => 'refunds', 'franchise_id' => $alpha['franchise']->id,
            'filter_field' => 'Company Name', 'filter_value' => 'A1 CHIMNEY NJ', 'markers' => ['skip' => 'Total'],
        ])->assertCreated()->json('data');
        $as->postJson('/api/v1/financials/sources', ['base_id' => 'appF', 'table_id' => 'tblRefunds', 'role' => 'refunds'])->assertCreated();
        $as->postJson('/api/v1/financials/sources', ['base_id' => 'appF', 'table_id' => 'tblX', 'role' => 'refunds', 'markers' => ['colour' => 'x']])->assertStatus(422);
        $this->assertSame('Total', $shared['markers']['skip']);

        // A place's books can move tables on a date.
        $old = $as->postJson('/api/v1/financials/sources', ['base_id' => 'appP', 'table_id' => 'tblWA', 'role' => 'pnl', 'franchise_id' => $alpha['franchise']->id, 'valid_until' => '2026-09-20'])
            ->assertCreated()->assertJsonPath('data.valid_until', '2026-09-20')->json('data');
        $as->postJson('/api/v1/financials/sources', ['base_id' => 'appP', 'table_id' => 'tblBad', 'role' => 'pnl', 'valid_from' => '2026-09-21', 'valid_until' => '2026-09-01'])->assertStatus(422);
        $this->inTenant($alpha['org'], function () use ($old) {
            $s = FinancialSource::query()->find($old['id']);
            $this->assertTrue($s->overlaps('2026-09-14', '2026-09-20'));
            $this->assertTrue($s->overlaps('2026-09-14', '2026-09-27'));
            $this->assertFalse($s->overlaps('2026-09-21', '2026-09-27'));
            $this->assertFalse($s->covers('2026-09-21'));
        });
        $this->inTenant($alpha['org'], function () use ($shared) {
            $s = FinancialSource::query()->find($shared['id']);
            $this->assertTrue($s->owns(['Company Name' => 'A1 CHIMNEY NJ']));
            $this->assertFalse($s->owns(['Company Name' => 'A1 CHIMNEY CT']));
        });
        $as->postJson('/api/v1/financials/sources', ['base_id' => 'appR', 'table_id' => 'tblX', 'role' => 'pnl', 'field_map' => ['colour' => 'x']])->assertStatus(422);
        $as->patchJson("/api/v1/financials/sources/{$id}", ['field_map' => ['team' => 'Crew']])->assertOk()->assertJsonPath('data.field_map.team', 'Crew');

        $listed = $as->getJson('/api/v1/financials/sources')->assertOk()->json();
        $this->assertSame('Alpha F1', collect($listed['data'])->firstWhere('role', 'repair')['place']);
        $this->assertArrayHasKey('hq_expenses', $listed['roles']);

        $this->inTenant($alpha['org'], fn () => $this->assertSame('Crew', FinancialSource::forRole('repair')->first()->field('team')));

        $viewer = $this->userWithRole($alpha, 'Viewer', ['financials.view', 'financials.bases.view', 'financials.settings.view']);
        $this->signedInAs($viewer)->getJson('/api/v1/financials/sources')->assertOk();
        $this->signedInAs($viewer)->deleteJson("/api/v1/financials/sources/{$id}")->assertForbidden();

        $this->assertSame([], $this->signedInAs($beta['admin'])->getJson('/api/v1/financials/sources')->json('data'));
        $this->signedInAs($beta['admin'])->deleteJson("/api/v1/financials/sources/{$id}")->assertNotFound();

        $this->signedInAs($alpha['admin'])->deleteJson("/api/v1/financials/sources/{$id}")->assertNoContent();
    }
}
