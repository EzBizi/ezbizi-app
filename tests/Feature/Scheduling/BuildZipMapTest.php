<?php

namespace Tests\Feature\Scheduling;

use App\Models\ServiceArea;
use App\Models\ServiceAreaZip;
use App\Models\WorkizJob;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/**
 * `scheduling:build-zip-map` — the map nobody types.
 *
 * The cases that matter are the ones where two areas both claim a zip, where
 * a person has already decided, and where Workiz names something we do not
 * have. Everything else is a GROUP BY.
 */
class BuildZipMapTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    /** @return array{0: array<string, mixed>, 1: ServiceArea} */
    private function tenantWithArea(string $areaName = 'Chicago'): array
    {
        $tenant = $this->provisionTenant('Alpha');

        app(TenantContext::class)->set($tenant['org']);
        $area = ServiceArea::create([
            'franchise_id' => $tenant['franchise']->id,
            'name' => $areaName,
        ]);

        return [$tenant, $area];
    }

    private function job(array $tenant, string $serviceArea, string $zip, ?string $city = null): void
    {
        app(TenantContext::class)->set($tenant['org']);

        WorkizJob::create([
            'franchise_id' => $tenant['franchise']->id,
            'remote_id' => strtoupper(bin2hex(random_bytes(4))),
            'service_area' => $serviceArea,
            'postal_code' => $zip,
            'city' => $city,
            'payload' => [],
            'synced_at' => now(),
        ]);
    }

    public function test_it_maps_a_zip_to_the_area_its_jobs_name(): void
    {
        [$tenant, $area] = $this->tenantWithArea();

        $this->job($tenant, 'Chicago', '60601', 'Chicago');
        $this->job($tenant, 'Chicago', '60601-1234', 'Chicago');

        $this->artisan('scheduling:build-zip-map', ['--organization' => 'Alpha'])
            ->assertSuccessful();

        app(TenantContext::class)->set($tenant['org']);
        $zip = ServiceAreaZip::query()->first();

        $this->assertNotNull($zip, 'Nothing was mapped at all.');
        $this->assertSame('60601', $zip->zip, 'The +4 form should collapse onto the 5-digit zip.');
        $this->assertSame($area->id, $zip->service_area_id);
        $this->assertSame('Chicago', $zip->city);
        $this->assertSame('workiz_jobs', $zip->source);
    }

    /**
     * THE TIE-BREAK, which is the whole reason job_count is kept.
     *
     * 420 zips in A-1's history are claimed by two or more areas and 339 of
     * those sit inside ONE Workiz account, so the franchise settles nothing.
     * Most jobs wins.
     */
    public function test_two_areas_claiming_one_zip_are_decided_by_job_count(): void
    {
        [$tenant, $chicago] = $this->tenantWithArea('Chicago');

        app(TenantContext::class)->set($tenant['org']);
        ServiceArea::create([
            'franchise_id' => $tenant['franchise']->id,
            'name' => 'Milwaukee',
        ]);

        // Milwaukee names it once; Chicago names it three times.
        $this->job($tenant, 'Milwaukee', '60601');
        $this->job($tenant, 'Chicago', '60601');
        $this->job($tenant, 'Chicago', '60601');
        $this->job($tenant, 'Chicago', '60601');

        $this->artisan('scheduling:build-zip-map', ['--organization' => 'Alpha'])
            ->assertSuccessful();

        app(TenantContext::class)->set($tenant['org']);
        $zips = ServiceAreaZip::query()->where('zip', '60601')->get();

        $this->assertCount(1, $zips, 'UNIQUE(franchise_id, zip) means one winner, not two rows.');
        $this->assertSame(
            $chicago->id,
            $zips->first()->service_area_id,
            'The area with more jobs in that zip must win.',
        );
    }

    /**
     * A ROW A PERSON TYPED IS NEVER OVERWRITTEN.
     *
     * A derived map is rebuilt whenever the history moves. Without the source
     * split a rebuild either wipes what somebody entered or leaves a zip
     * pointing at the area it used to belong to — both silent.
     */
    public function test_a_hand_entered_mapping_beats_the_evidence(): void
    {
        [$tenant, $chicago] = $this->tenantWithArea('Chicago');

        app(TenantContext::class)->set($tenant['org']);
        $milwaukee = ServiceArea::create([
            'franchise_id' => $tenant['franchise']->id,
            'name' => 'Milwaukee',
        ]);

        // Somebody decided this zip is Milwaukee's. No source: a person.
        ServiceAreaZip::create([
            'franchise_id' => $tenant['franchise']->id,
            'service_area_id' => $milwaukee->id,
            'zip' => '60601',
        ]);

        // The evidence says otherwise, loudly.
        $this->job($tenant, 'Chicago', '60601');
        $this->job($tenant, 'Chicago', '60601');

        $this->artisan('scheduling:build-zip-map', ['--organization' => 'Alpha'])
            ->assertSuccessful();

        app(TenantContext::class)->set($tenant['org']);
        $this->assertSame(
            $milwaukee->id,
            ServiceAreaZip::query()->where('zip', '60601')->first()->service_area_id,
            'The rebuild overwrote a mapping a person entered.',
        );
        $this->assertNotSame($chicago->id, ServiceAreaZip::query()->where('zip', '60601')->first()->service_area_id);
    }

    /**
     * A NAME WE DO NOT HAVE IS NOT INVENTED, and its jobs are reported.
     *
     * Twelve Workiz names still have no area here — 212 jobs, 2.8% of the
     * history. Guessing one would file those zips against another area's
     * allowance, which is the over-scheduling per-area capacity exists to stop.
     */
    public function test_an_unknown_service_area_maps_nothing_and_is_reported(): void
    {
        [$tenant] = $this->tenantWithArea('Chicago');

        $this->job($tenant, 'Kansas City', '64101');
        $this->job($tenant, 'Chicago', '60601');

        $this->artisan('scheduling:build-zip-map', [
            '--organization' => 'Alpha',
            '--report-unmatched' => true,
        ])
            ->expectsOutputToContain('Kansas City')
            ->assertSuccessful();

        app(TenantContext::class)->set($tenant['org']);
        $this->assertSame(
            ['60601'],
            ServiceAreaZip::query()->pluck('zip')->all(),
            'A zip whose service area does not exist here must not be mapped to anything.',
        );
    }

    /** Re-running moves a zip that moved, and does not duplicate one that did not. */
    public function test_it_is_idempotent_and_follows_a_zip_that_moves(): void
    {
        [$tenant, $chicago] = $this->tenantWithArea('Chicago');

        app(TenantContext::class)->set($tenant['org']);
        $milwaukee = ServiceArea::create([
            'franchise_id' => $tenant['franchise']->id,
            'name' => 'Milwaukee',
        ]);

        $this->job($tenant, 'Chicago', '60601');

        $this->artisan('scheduling:build-zip-map', ['--organization' => 'Alpha'])->assertSuccessful();
        $this->artisan('scheduling:build-zip-map', ['--organization' => 'Alpha'])->assertSuccessful();

        app(TenantContext::class)->set($tenant['org']);
        $this->assertSame(1, ServiceAreaZip::query()->where('zip', '60601')->count());
        $this->assertSame($chicago->id, ServiceAreaZip::query()->where('zip', '60601')->first()->service_area_id);

        // Milwaukee takes over the zip.
        foreach (range(1, 3) as $ignored) {
            $this->job($tenant, 'Milwaukee', '60601');
        }

        $this->artisan('scheduling:build-zip-map', ['--organization' => 'Alpha'])->assertSuccessful();

        app(TenantContext::class)->set($tenant['org']);
        $this->assertSame(1, ServiceAreaZip::query()->where('zip', '60601')->count());
        $this->assertSame(
            $milwaukee->id,
            ServiceAreaZip::query()->where('zip', '60601')->first()->service_area_id,
            'A derived row must follow the evidence when the evidence changes.',
        );
    }

    public function test_dry_run_writes_nothing(): void
    {
        [$tenant] = $this->tenantWithArea('Chicago');
        $this->job($tenant, 'Chicago', '60601');

        $this->artisan('scheduling:build-zip-map', [
            '--organization' => 'Alpha',
            '--dry-run' => true,
        ])->assertSuccessful();

        app(TenantContext::class)->set($tenant['org']);
        $this->assertSame(0, ServiceAreaZip::query()->count());
    }

    /**
     * THE A1 TOOLS SEED. Workiz's API cannot return history — `/job/all/`
     * ignores `start_date` and hands back roughly the next hundred upcoming
     * appointments — so A-1's eighteen months of evidence exists only in A1
     * Tools' own store. It comes in as a file, the same way
     * `fleet:import-a1-tools` and `scheduling:import-a1-areas` do, and lands
     * under its own source word so the two derivations stay distinguishable.
     */
    public function test_it_builds_the_map_from_an_a1_tools_export(): void
    {
        [$tenant, $area] = $this->tenantWithArea('Chicago');

        $path = tempnam(sys_get_temp_dir(), 'zips').'.json';
        file_put_contents($path, json_encode([
            ['service_area' => 'Chicago', 'zip' => '60601', 'city' => 'Chicago', 'jobs' => 412],
            ['service_area' => 'Nowhere', 'zip' => '99999', 'city' => null, 'jobs' => 5],
        ]));

        $this->artisan('scheduling:build-zip-map', [
            '--organization' => 'Alpha',
            '--file' => $path,
        ])->assertSuccessful();

        app(TenantContext::class)->set($tenant['org']);
        $zips = ServiceAreaZip::query()->get();

        $this->assertCount(1, $zips, 'The unknown area must contribute nothing.');
        $this->assertSame('60601', $zips->first()->zip);
        $this->assertSame($area->id, $zips->first()->service_area_id);
        $this->assertSame('a1tools_jobs', $zips->first()->source);
    }

    /**
     * A hand-typed row survives the A1 seed too — the protection is on NULL,
     * not on one particular derivation.
     */
    public function test_the_a1_seed_also_leaves_a_hand_entered_row_alone(): void
    {
        [$tenant] = $this->tenantWithArea('Chicago');

        app(TenantContext::class)->set($tenant['org']);
        $milwaukee = ServiceArea::create([
            'franchise_id' => $tenant['franchise']->id,
            'name' => 'Milwaukee',
        ]);
        ServiceAreaZip::create([
            'franchise_id' => $tenant['franchise']->id,
            'service_area_id' => $milwaukee->id,
            'zip' => '60601',
        ]);

        $path = tempnam(sys_get_temp_dir(), 'zips').'.json';
        file_put_contents($path, json_encode([
            ['service_area' => 'Chicago', 'zip' => '60601', 'city' => 'Chicago', 'jobs' => 900],
        ]));

        $this->artisan('scheduling:build-zip-map', [
            '--organization' => 'Alpha',
            '--file' => $path,
        ])->assertSuccessful();

        app(TenantContext::class)->set($tenant['org']);
        $this->assertSame(
            $milwaukee->id,
            ServiceAreaZip::query()->where('zip', '60601')->first()->service_area_id,
        );
    }
}
