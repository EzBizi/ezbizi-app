<?php

namespace Tests\Feature\Api;

use App\Support\Modules\ModuleRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Ads (Google and Meta campaigns) is a tab inside Marketing, not Reports.
 *
 * Jose put it in Reports on 2026-09-15 and moved it back on 2026-11-05: "it's
 * not a report, but ads management which should go on marketing".
 *
 * Pinned because Marketing had a test for exactly this and Reports did not, so
 * a module could be moved into or out of Reports with nothing going red. That
 * asymmetry is how the first placement went unquestioned.
 */
class ReportsModuleTest extends TestCase
{
    use RefreshDatabase;

    private const MODULE = 'reports';

    public function test_ads_is_drawn_inside_marketing_not_reports(): void
    {
        $registry = app(ModuleRegistry::class);

        $this->assertSame(
            ['profit_loss'],
            array_map(fn ($m) => $m->key, $registry->childrenOf(self::MODULE)),
            'Registry order is the tab order, so this is the order of the strip.',
        );

        $this->assertSame(
            ['marketing', 'Ads'],
            [$registry->get('ads_performance')?->parent, $registry->get('ads_performance')?->label],
            'Ads management is Marketing; the tab holds Google and Meta, so neither name hides the other.',
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
