<?php

namespace Tests\Feature\Reports;

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

    public function test_the_module_declares_its_three_permissions(): void
    {
        $module = $this->module();

        $this->assertNotNull($module, 'the reports module is not registered');
        $this->assertSame(['view', 'build', 'export'], $module->actions);
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
    public function test_report_send_is_not_yet_in_the_implemented_list(): void
    {
        $reflection = new \ReflectionClass(\App\Http\Controllers\Api\AutomationsController::class);
        $implemented = $reflection->getConstant('IMPLEMENTED_ACTIONS');

        $this->assertIsArray($implemented, 'IMPLEMENTED_ACTIONS was not readable');
        $this->assertNotContains('report.send', $implemented);
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
