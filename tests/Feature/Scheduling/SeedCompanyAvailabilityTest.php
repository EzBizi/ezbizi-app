<?php

namespace Tests\Feature\Scheduling;

use App\Models\Company;
use App\Models\Franchise;
use App\Models\ServiceArea;
use App\Models\ServiceAreaSlot;
use App\Models\ServiceAreaZip;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/**
 * Companies with no service areas still book online (Jose via Bar,
 * 2026-10-22): a catch-all "All areas" per branch, seeded with a copy of the
 * source company's typical week, takes any ZIP no listed area takes.
 */
class SeedCompanyAvailabilityTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    public function test_a_company_without_areas_gets_a_catch_all_copy_of_the_source_week(): void
    {
        $t = $this->provisionTenant('Alpha');
        app(TenantContext::class)->set($t['org']);
        $source = $t['company'];
        foreach (['North', 'South'] as $name) {
            $a = ServiceArea::create(['franchise_id' => $t['franchise']->id, 'name' => $name]);
            ServiceAreaZip::create(['franchise_id' => $a->franchise_id, 'service_area_id' => $a->id, 'zip' => $name === 'North' ? '20601' : '20602']);
            foreach ([1, 2] as $day) {
                ServiceAreaSlot::create(['service_area_id' => $a->id, 'weekday' => $day, 'start_time' => '08:00', 'end_time' => '11:00', 'max_jobs' => $name === 'North' ? 2 : 4]);
            }
        }
        // Areas with no week (most of A-1's) do not dilute the pattern.
        foreach (['East', 'West', 'Central'] as $empty) {
            ServiceArea::create(['franchise_id' => $t['franchise']->id, 'name' => $empty]);
        }
        $everdoors = Company::create(['name' => 'Everdoors']);
        $branch = Franchise::create(['company_id' => $everdoors->id, 'name' => 'Everdoors MD']);
        $sub = Company::create(['name' => 'AngiSweep', 'parent_company_id' => $source->id]);
        Franchise::create(['company_id' => $sub->id, 'name' => 'AngiSweep']);

        $this->artisan('scheduling:seed-company-availability', ['--from' => $source->name])->assertSuccessful();
        app(TenantContext::class)->set($t['org']);

        $catchAll = ServiceArea::query()->where('franchise_id', $branch->id)->sole();
        $this->assertTrue($catchAll->covers_all_zips);
        $this->assertSame([[1, 3], [2, 3]], ServiceAreaSlot::where('service_area_id', $catchAll->id)->orderBy('weekday')->get()
            ->map(fn ($s) => [(int) $s->weekday, (int) $s->max_jobs])->all(), 'median of 2 and 4, each weekday copied');
        // The sub-company books on its parent's areas, so it gets none.
        $this->assertSame(1, ServiceArea::query()->where('covers_all_zips', true)->count());

        // Any ZIP in Everdoors' branch lands in the catch-all; a listed ZIP still wins in its own company.
        $this->assertSame($catchAll->id, ServiceArea::forZip('99999', [$branch->id])?->id);
        $this->assertSame('North', ServiceArea::forZip('20601', [$t['franchise']->id, $branch->id])?->name);
        $this->assertNull(ServiceArea::forZip('99999', [$t['franchise']->id]));

        // Re-running does not add a second one.
        $this->artisan('scheduling:seed-company-availability', ['--from' => $source->name])->assertSuccessful();
        app(TenantContext::class)->set($t['org']);
        $this->assertSame(1, ServiceArea::query()->where('covers_all_zips', true)->count());
    }
}
