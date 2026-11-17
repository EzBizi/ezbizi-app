<?php

namespace Tests\Feature\Scheduling;

use App\Models\ServiceArea;
use App\Models\ServiceAreaSlot;
use App\Support\Scheduling\Openings;
use App\Support\Settings\SettingsResolver;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/** Dispatchers, via Jose 2026-11-17: "Friday appointments must be scheduled no later than the 12-3 PM time window." */
class LatestWindowTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    public function test_windows_after_the_latest_offer_nothing_and_an_area_can_set_its_own(): void
    {
        $t = $this->provisionTenant('Alpha');
        app(TenantContext::class)->set($t['org']);
        $area = ServiceArea::create(['franchise_id' => $t['franchise']->id, 'name' => 'Dallas']);
        foreach ([4, 5] as $d) {
            foreach ([['08:00', '11:00'], ['12:00', '15:00'], ['15:00', '18:00']] as [$s, $e]) {
                ServiceAreaSlot::create(['service_area_id' => $area->id, 'weekday' => $d, 'start_time' => $s, 'end_time' => $e, 'max_jobs' => 2]);
            }
        }
        app(SettingsResolver::class)->set('scheduling.latest_window', $t['org'], ['5' => '12:00']);
        $left = fn () => app(TenantContext::class)->run($t['org'], fn () => collect(app(Openings::class)->days($area->fresh(), CarbonImmutable::parse('2026-11-19'), 2))
            ->mapWithKeys(fn ($d) => [$d['date'] => array_column($d['windows'], 'remaining')])->all());

        // Thursday untouched; Friday's 3-6 PM offers nothing.
        $this->assertSame(['2026-11-19' => [2, 2, 2], '2026-11-20' => [2, 2, 0]], $left());

        $as = $this->signedInAs($t['admin']);
        $as->putJson("/api/v1/scheduling/areas/{$area->id}/latest-windows", ['latest_windows' => ['5' => '08:00']])
            ->assertOk()->assertJsonPath('data.latest_windows.5', '08:00');
        $this->assertSame([2, 0, 0], $left()['2026-11-20']);

        $as->putJson("/api/v1/scheduling/areas/{$area->id}/latest-windows", ['latest_windows' => ['5' => '9pm']])->assertStatus(422);
        $as->putJson("/api/v1/scheduling/areas/{$area->id}/latest-windows", ['latest_windows' => null])->assertOk();
        $this->assertSame([2, 2, 0], $left()['2026-11-20']);
    }
}
