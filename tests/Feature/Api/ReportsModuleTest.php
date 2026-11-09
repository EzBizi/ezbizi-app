<?php

namespace Tests\Feature\Api;

use App\Support\Modules\ModuleRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Google Ads is a tab inside Reports.
 *
 * Jose's call, 2026-09-15, having been shown it under Marketing: "It is clearly
 * not supposed to be in Marketing. Move it to Reports in a new tab called
 * Google Ads." Marketing is what the business SAYS about itself - its listing,
 * its reviews, its posts. What that costs is a measurement, and measurements
 * live in Reports.
 *
 * Pinned because Marketing had a test for exactly this and Reports did not, so
 * a module could be moved into or out of Reports with nothing going red. That
 * asymmetry is how the first placement went unquestioned.
 */
class ReportsModuleTest extends TestCase
{
    use RefreshDatabase;

    private const MODULE = 'reports';

    public function test_google_ads_is_drawn_inside_reports(): void
    {
        $registry = app(ModuleRegistry::class);

        $this->assertSame(
            ['ads_performance', 'profit_loss'],
            array_map(fn ($m) => $m->key, $registry->childrenOf(self::MODULE)),
            'Registry order is the tab order, so this is the order of the strip.',
        );

        $this->assertSame(
            'Google Ads',
            $registry->get('ads_performance')?->label,
            'The tab is named for the thing it reports on, not for the metric.',
        );

        $this->assertNull(
            $registry->get(self::MODULE)?->parent,
            'Reports is top level - nesting is one deep, so it can hold a child.',
        );
    }

    /**
     * Nested is not granted.
     *
     * The failure worth guarding, and the same one Marketing guards: somebody
     * reads "Google Ads is inside Reports" as a permission relationship and
     * lets `reports.view` carry advertising spend with it. What a business
     * spends on advertising is a separate grant from what it measures
     * internally, which is the whole reason this module has a permission of its
     * own rather than borrowing one.
     */
    public function test_reports_view_does_not_carry_advertising_spend(): void
    {
        $registry = app(ModuleRegistry::class);

        // `view` and `manage` since 2026-09-24, when Jose asked for campaigns
        // to be paused and enabled from EzBizi. Still no create, edit or
        // delete: the figures are Google's and are not stored, and `manage` is
        // campaign STATUS only - its own grant, so that reading spend never
        // carries the power to switch it off.
        $this->assertSame(
            ['view', 'manage'],
            $registry->get('ads_performance')?->actions,
            'Google Ads reads spend and pauses or enables campaigns, and nothing else.',
        );

        $this->assertNotContains(
            'reports.view',
            $registry->get('ads_performance')?->permissionKeys() ?? [],
            'it declares its own grants and borrows none from Reports',
        );
    }
}
