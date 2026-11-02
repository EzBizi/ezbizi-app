<?php

namespace Tests\Feature\Scheduling;

use App\Models\Franchise;
use App\Models\ServiceArea;
use App\Models\ServiceAreaZip;
use App\Models\ServiceAreaZipRelease;
use App\Models\WorkizJob;
use App\Support\Tenancy\TenantContext;
use Illuminate\Console\Scheduling\Schedule;
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

    private function job(array $tenant, string $serviceArea, string $zip, ?string $city = null, ?Franchise $franchise = null): void
    {
        app(TenantContext::class)->set($tenant['org']);

        WorkizJob::create([
            'franchise_id' => ($franchise ?? $tenant['franchise'])->id,
            'remote_id' => strtoupper(bin2hex(random_bytes(4))),
            'service_area' => $serviceArea,
            'postal_code' => $zip,
            'city' => $city,
            'payload' => [],
            'synced_at' => now(),
        ]);
    }

    /**
     * Jose, 2026-10-21: he removed 20662 from Maryland to give it to Charlotte
     * Hall, and the sweep put it back in Maryland twelve seconds later, so
     * every add to Charlotte Hall was refused as a clash.
     */
    public function test_a_zip_a_person_removed_is_not_re_added_by_the_sweep(): void
    {
        [$tenant, $maryland] = $this->tenantWithArea('Maryland');
        $charlotte = ServiceArea::create(['franchise_id' => $tenant['franchise']->id, 'name' => 'Charlotte Hall']);
        $this->job($tenant, 'Maryland', '20662', 'Nanjemoy');
        $this->artisan('scheduling:build-zip-map', ['--organization' => 'Alpha'])->assertSuccessful();
        $as = $this->signedInAs($tenant['admin']);

        $as->putJson("/api/v1/scheduling/areas/{$maryland->id}/zips", ['zips' => []])->assertOk();
        $this->artisan('scheduling:build-zip-map', ['--organization' => 'Alpha'])->assertSuccessful();
        app(TenantContext::class)->set($tenant['org']);
        $this->assertSame(0, ServiceAreaZip::query()->where('zip', '20662')->count(), 'The sweep re-added a zip a person removed.');

        $as->putJson("/api/v1/scheduling/areas/{$charlotte->id}/zips", ['zips' => ['20662']])->assertOk();
        // Assigned again by hand: no longer released, and the sweep leaves it where the person put it.
        $this->artisan('scheduling:build-zip-map', ['--organization' => 'Alpha'])->assertSuccessful();
        app(TenantContext::class)->set($tenant['org']);
        $this->assertSame($charlotte->id, ServiceAreaZip::query()->where('zip', '20662')->value('service_area_id'));
        $this->assertSame(0, ServiceAreaZipRelease::query()->count());
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

    /**
     * A ZIP THAT ALREADY HAS AN AREA KEEPS IT — re-running adds, never moves.
     *
     * This test used to assert the opposite ("a derived row must follow the
     * evidence") and was rewritten on 2026-09-25, deliberately. A1 Tools
     * measured most-jobs-wins on A-1's history: 136 of 169 contested zips were
     * ties, and 7 of the 33 it decided were wrong, all metro borders. A map
     * cleaned by hand that day must not be re-decided by the next run. The
     * disagreement is printed instead, for a person.
     */
    public function test_a_zip_that_already_has_an_area_is_reported_and_not_moved(): void
    {
        [$tenant, $chicago] = $this->tenantWithArea('Chicago');

        app(TenantContext::class)->set($tenant['org']);
        ServiceArea::create([
            'franchise_id' => $tenant['franchise']->id,
            'name' => 'Indiana',
        ]);

        $this->job($tenant, 'Chicago', '46410');
        $this->artisan('scheduling:build-zip-map', ['--organization' => 'Alpha'])->assertSuccessful();

        // Now the evidence swings to Indiana, three to one.
        foreach (range(1, 3) as $ignored) {
            $this->job($tenant, 'Indiana', '46410');
        }

        $this->artisan('scheduling:build-zip-map', ['--organization' => 'Alpha'])
            ->expectsOutputToContain("46410  on {$tenant['franchise']->name} › Chicago; the evidence says {$tenant['franchise']->name} › Indiana")
            ->assertSuccessful();

        app(TenantContext::class)->set($tenant['org']);
        $this->assertSame(1, ServiceAreaZip::query()->where('zip', '46410')->count());
        $this->assertSame(
            $chicago->id,
            ServiceAreaZip::query()->where('zip', '46410')->first()->service_area_id,
            'A rebuild moved a zip that already had an area.',
        );
    }

    /**
     * The contest is ORGANIZATION-wide: two areas in two different branches
     * that both name a new zip produce one row, not one per branch.
     */
    public function test_areas_in_two_franchises_naming_one_new_zip_produce_one_row(): void
    {
        [$tenant, $newJersey] = $this->tenantWithArea('New Jersey');

        app(TenantContext::class)->set($tenant['org']);
        $nationwide = Franchise::create([
            'organization_id' => $tenant['org']->id,
            'company_id' => $tenant['company']->id,
            'name' => 'Nationwide',
        ]);
        ServiceArea::create(['franchise_id' => $nationwide->id, 'name' => 'New York']);

        $this->job($tenant, 'New York', '08753', franchise: $nationwide);
        foreach (range(1, 3) as $ignored) {
            $this->job($tenant, 'New Jersey', '08753');
        }

        $this->artisan('scheduling:build-zip-map', ['--organization' => 'Alpha'])->assertSuccessful();

        app(TenantContext::class)->set($tenant['org']);
        $rows = ServiceAreaZip::query()->where('zip', '08753')->get();

        $this->assertCount(1, $rows, 'One zip, one area, across the whole organization.');
        $this->assertSame($newJersey->id, $rows->first()->service_area_id);
    }

    /**
     * A NAME IS ONLY UNIQUE WITHIN ITS BRANCH'S WORKIZ ACCOUNT. On 2026-09-28
     * Nationwide gained its own Austin beside Texas's, and a dry run keyed on
     * the name alone credited Texas's Austin zips to Nationwide's. A job
     * matches only an area of its own franchise.
     */
    public function test_a_name_shared_by_two_branches_maps_each_jobs_zip_to_its_own_branch(): void
    {
        [$tenant, $texasAustin] = $this->tenantWithArea('Austin');

        app(TenantContext::class)->set($tenant['org']);
        $nationwide = Franchise::create([
            'organization_id' => $tenant['org']->id,
            'company_id' => $tenant['company']->id,
            'name' => 'Nationwide',
        ]);
        $nationwideAustin = ServiceArea::create(['franchise_id' => $nationwide->id, 'name' => 'Austin']);

        $this->job($tenant, 'Austin', '78701');
        $this->job($tenant, 'Austin', '78613', franchise: $nationwide);

        $this->artisan('scheduling:build-zip-map', ['--organization' => 'Alpha'])->assertSuccessful();

        app(TenantContext::class)->set($tenant['org']);
        $this->assertSame($texasAustin->id, ServiceAreaZip::query()->where('zip', '78701')->value('service_area_id'));
        $this->assertSame($nationwideAustin->id, ServiceAreaZip::query()->where('zip', '78613')->value('service_area_id'));
    }

    /**
     * Jose, 2026-09-28: a booking in a zip Tampa has never had puts the zip on
     * Tampa, by itself. The scheduled run names no organization, so it must
     * cover each one — and still never create an area for a name it lacks.
     */
    public function test_the_scheduled_run_maps_every_organization_and_creates_no_area(): void
    {
        [$alpha] = $this->tenantWithArea('Tampa');
        $this->job($alpha, 'Tampa', '33602');
        $this->job($alpha, 'Orlando', '32801');

        $beta = $this->provisionTenant('Beta', 'beta@example.test');
        app(TenantContext::class)->set($beta['org']);
        $chicago = ServiceArea::create(['franchise_id' => $beta['franchise']->id, 'name' => 'Chicago']);
        $this->job($beta, 'Chicago', '60601');

        $this->artisan('scheduling:build-zip-map')->assertSuccessful();

        app(TenantContext::class)->set($alpha['org']);
        $this->assertSame(['33602'], ServiceAreaZip::query()->pluck('zip')->all());
        $this->assertFalse(ServiceArea::query()->where('name', 'Orlando')->exists(), 'A name with no area must not become one.');

        app(TenantContext::class)->set($beta['org']);
        $this->assertSame($chicago->id, ServiceAreaZip::query()->where('zip', '60601')->value('service_area_id'));
    }

    public function test_it_is_scheduled(): void
    {
        $events = collect(app(Schedule::class)->events())
            ->filter(fn ($e) => str_contains((string) $e->command, 'scheduling:build-zip-map'));

        $this->assertCount(1, $events, 'New bookings would never add their zips.');
        $this->assertSame('*/5 * * * *', $events->first()->expression);
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
     * `scheduling:import-a1-areas` does, and lands
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
