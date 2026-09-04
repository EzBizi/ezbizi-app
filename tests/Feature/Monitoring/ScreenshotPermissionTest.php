<?php

namespace Tests\Feature\Monitoring;

use App\Support\Modules\ModuleRegistry;
use App\Support\Modules\PermissionCatalogue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Task 2 of 2026-08-31-monitoring-screenshots: `screenshots.view` is a grant
 * on the `monitoring` module, narrower than `monitoring.view` the same way
 * `specs.view` is — seeing a device online is not the same permission as
 * seeing what its screen looked like.
 */
class ScreenshotPermissionTest extends TestCase
{
    use RefreshDatabase;

    public function test_monitoring_declares_screenshots_view(): void
    {
        $module = app(ModuleRegistry::class)->get('monitoring');

        $this->assertNotNull($module);
        $this->assertContains('screenshots.view', $module->actions);
    }

    public function test_permission_catalogue_knows_monitoring_screenshots_view(): void
    {
        $keys = app(PermissionCatalogue::class)->keys();

        $this->assertContains('monitoring.screenshots.view', $keys);
    }
}
