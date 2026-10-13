<?php

namespace Tests\Feature\Reports;

use App\Http\Controllers\Api\AutomationsController;
use App\Support\Dashboard\GlanceRegistry;
use App\Support\Modules\ModuleRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

class ReportsModuleTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    private function module(): ?object
    {
        return collect(app(ModuleRegistry::class)->all())->firstWhere('key', 'reports');
    }

    /**
     * Three capability actions, and one per tab since 2026-09-17.
     *
     * The tab actions are not a second list: `TabDefinition::action()` folds
     * each one into `actions`, which is what lets moduleOf(), materialise() and
     * ezbizi:sync-permissions carry on knowing nothing about tabs. Asserting
     * the whole array rather than just the first three is deliberate — a tab
     * silently dropping out of the registry would leave a route gated on a
     * permission that no longer exists for anybody to hold.
     */
    public function test_the_module_declares_its_permissions_and_its_tabs(): void
    {
        $module = $this->module();

        $this->assertNotNull($module, 'the reports module is not registered');
        $this->assertSame([
            'view',
            'build',
            'export',
            'library.view',
            'builder.view',
            'experimental.view',
            'scoring.view',
            'marketing.view',
            'meta.view',
            'thumbtack.view',
        ], $module->actions);
    }

    /**
     * THE LABELS ARE THE WORDS ON THE CHIPS, not near-misses.
     *
     * An administrator ticks these in the roles editor and then goes looking
     * for the tab. If this said "Library" and the chip said "Reports", they
     * have been handed a checkbox for something whose name they were told
     * wrongly — and nothing errors, because both halves work.
     */
    public function test_each_tab_is_labelled_the_way_the_chip_is(): void
    {
        $labels = array_map(
            static fn ($tab): string => $tab->label,
            $this->module()->tabs,
        );

        // Renamed by Jose, 2026-10-06 ("Builder to report builder", "new name for experimental");
        // Scoring added 2026-10-13 (Administrators and Lead Managers).
        $this->assertSame(['Reports', 'Report Builder', 'Performance', 'Scoring', 'Marketing', 'Meta', 'Thumbtack'], $labels);
    }

    /**
     * Building and exporting without the tab would be grants with nothing to
     * click, and the roles editor draws one word per action so it cannot say
     * so on its own.
     */
    public function test_build_and_export_require_view(): void
    {
        $module = $this->module();

        $this->assertSame(['reports.view'], $module->actionRequires['build']);
        $this->assertSame(['reports.view'], $module->actionRequires['export']);
    }

    public function test_report_send_is_declared_with_a_spelled_out_execution_type(): void
    {
        $action = collect($this->module()->automationActions)->firstWhere('key', 'report.send');

        $this->assertNotNull($action, 'report.send is not declared');

        // Left to derive, `report.send` would reduce to the bare word `send`
        // in a namespace every module shares.
        $this->assertSame('report.send', $action->type());
        $this->assertFalse($action->subjectBound);
    }

    /**
     * Declared, and deliberately not implemented. The const publishes
     * `implemented: true`, which would let a tenant save a rule that throws on
     * every run and then auto-disables itself.
     */
    /*
     * Was assertNotContains until 2026-09-16, and the flip is the guard doing
     * its job rather than breaking. Automations built the arm - ActionRunner
     * routes 'report.send' to sendReport() through SendSavedReport - so a
     * catalogue still calling it unbuilt would tell a rule author the step
     * does nothing, which is the exact lie this test was written to prevent
     * in the other direction.
     */
    public function test_report_send_is_in_the_implemented_list(): void
    {
        $reflection = new \ReflectionClass(AutomationsController::class);
        $implemented = $reflection->getConstant('IMPLEMENTED_ACTIONS');

        $this->assertIsArray($implemented, 'IMPLEMENTED_ACTIONS was not readable');
        $this->assertContains('report.send', $implemented);
    }

    public function test_the_glance_is_registered_and_keyed_to_the_module(): void
    {
        $keys = array_map(fn ($glance) => $glance->key(), app(GlanceRegistry::class)->all());

        $this->assertContains('reports', $keys);
    }

    public function test_the_tab_is_refused_without_any_reports_permission(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $nobody = $this->userWithRole($tenant, 'Nobody', []);

        $this->signedInAs($nobody)
            ->getJson('/api/v1/reports')
            ->assertForbidden();
    }

    /**
     * The positive control. `User::hasPermission` gates every key through
     * ModuleAvailability, so before the module was registered even an
     * administrator got 403 here — with a perfectly correct policy. That cost
     * eleven confusing test failures, and this is the assertion that names it.
     */
    public function test_an_administrator_reaches_the_tab_once_the_module_is_registered(): void
    {
        $tenant = $this->provisionTenant('Alpha');

        // Roles are tenant data, so this read needs a context of its own — the
        // request below establishes one for itself.
        $this->assertTrue(
            $this->inTenant($tenant['org'], fn () => $tenant['admin']->hasPermission('reports.view')),
            'reports.view is not an available permission, so the module is not registered',
        );

        $this->signedInAs($tenant['admin'])
            ->getJson('/api/v1/reports')
            ->assertOk();
    }
}
