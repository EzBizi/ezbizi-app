<?php

namespace Tests\Feature\Inspection;

use App\Models\InspectionDefinition;
use App\Models\InspectionReport;
use App\Models\InspectionRun;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/**
 * The Test tab's dry run: every show/skip explained, a report preview, and
 * nothing written anywhere a real inspection would leave a trace.
 */
class RehearsalTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    private const SCREENS = [
        ['name' => 'The system', 'fields' => [
            ['key' => 'system_type', 'label' => 'System', 'type' => 'choice', 'print' => 'ignore',
                'required' => true, 'options' => ['masonry', 'wood']],
            ['key' => 'firebox', 'label' => 'Firebox condition', 'type' => 'text', 'required' => true,
                'visible_if' => ['field' => 'system_type', 'op' => 'eq', 'value' => 'masonry']],
        ]],
        ['name' => 'Wood stove', 'visible_if' => ['field' => 'system_type', 'op' => 'eq', 'value' => 'wood'],
            'fields' => [['key' => 'stove', 'label' => 'Stove condition', 'type' => 'text']]],
    ];

    public function test_the_unpublished_draft_is_walked_explained_and_previewed_without_a_trace(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $definition = $this->inTenant($tenant['org'], fn () => InspectionDefinition::create([
            'name' => 'Chimney', 'created_by' => $tenant['admin']->id, 'screens' => self::SCREENS,
        ]));

        $tables = ['inspection_runs', 'inspection_reports', 'audit_events', 'notifications'];
        $before = array_map(fn ($t) => DB::table($t)->count(), $tables);

        $data = $this->signedInAs($tenant['admin'])
            ->postJson("/api/v1/inspections/{$definition->id}/rehearse", [
                'version' => 'draft',
                'answers' => ['system_type' => 'masonry'],
            ])
            ->assertOk()
            ->json('data');

        [$system, $wood] = $data['screens'];
        $this->assertTrue($system['shown']);
        $this->assertFalse($wood['shown']);
        $this->assertSame('When system_type is wood; it is "masonry" → skipped.', $wood['why']);
        $this->assertTrue($system['fields'][1]['shown']);
        $this->assertSame('Firebox condition', $data['first_missing'], 'the required firebox is the first gap');

        $this->assertStringContainsString('Firebox condition', $data['preview_html']);
        $this->assertStringNotContainsString('Stove condition', $data['preview_html']);

        $this->assertSame($before, array_map(fn ($t) => DB::table($t)->count(), $tables),
            'a test run leaves no run, report, audit row or notification');
    }

    public function test_someone_who_cannot_edit_forms_cannot_test_them(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $definition = $this->inTenant($tenant['org'], fn () => InspectionDefinition::create([
            'name' => 'Chimney', 'created_by' => $tenant['admin']->id, 'screens' => self::SCREENS,
        ]));
        $tech = $this->userWithRole($tenant, 'Tech', ['inspection.view', 'inspection.new.view']);

        $this->signedInAs($tech)
            ->postJson("/api/v1/inspections/{$definition->id}/rehearse", ['answers' => []])
            ->assertForbidden();
    }

    public function test_the_form_lists_its_published_versions_by_the_id_rehearse_takes(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $definition = $this->inTenant($tenant['org'], fn () => InspectionDefinition::create([
            'name' => 'Chimney', 'created_by' => $tenant['admin']->id, 'screens' => self::SCREENS,
        ]));
        $as = $this->signedInAs($tenant['admin']);

        $this->assertSame([], $as->getJson("/api/v1/inspections/{$definition->id}")->assertOk()->json('data.versions'));

        $as->postJson("/api/v1/inspections/{$definition->id}/publish")->assertSuccessful();
        $versions = $as->getJson("/api/v1/inspections/{$definition->id}")->json('data.versions');

        $this->assertCount(1, $versions);
        $this->assertSame(1, $versions[0]['version']);
        $as->postJson("/api/v1/inspections/{$definition->id}/rehearse", [
            'version' => $versions[0]['id'], 'answers' => ['system_type' => 'wood'],
        ])->assertOk()->assertJsonPath('data.screens.1.shown', true);
    }
}
