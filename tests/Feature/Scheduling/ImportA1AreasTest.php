<?php

namespace Tests\Feature\Scheduling;

use App\Models\Franchise;
use App\Models\ServiceArea;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/**
 * `scheduling:import-a1-areas` — the areas Workiz is booking into, that we
 * have no area for.
 *
 * The three cases that decide whether this is safe to point at production:
 * it must not delete, it must not revive a dead name, and it must not attach
 * anything to a duplicate franchise row nobody uses.
 */
class ImportA1AreasTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    /** @param  array<int, array<string, mixed>>  $rows */
    private function file(array $rows): string
    {
        $path = tempnam(sys_get_temp_dir(), 'areas').'.json';
        file_put_contents($path, json_encode($rows));

        return $path;
    }

    public function test_it_creates_the_missing_area_and_leaves_the_existing_one_alone(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $franchise = $tenant['franchise'];

        app(TenantContext::class)->set($tenant['org']);
        ServiceArea::create([
            'franchise_id' => $franchise->id,
            'name' => 'Cleveland',
        ]);

        $this->artisan('scheduling:import-a1-areas', [
            'file' => $this->file([
                ['franchise' => $franchise->name, 'name' => 'Cleveland', 'booked_30d' => 246],
                ['franchise' => $franchise->name, 'name' => 'Detroit', 'booked_30d' => 69],
            ]),
            '--organization' => 'Alpha',
        ])->assertSuccessful();

        app(TenantContext::class)->set($tenant['org']);
        $names = ServiceArea::query()->pluck('name')->sort()->values()->all();

        $this->assertSame(['Cleveland', 'Detroit'], $names);
    }

    /**
     * A NAME NOBODY IS BOOKING INTO IS NOT CREATED.
     *
     * This is the guard that stops the command re-adding what Workiz has
     * retired. "Atlanta North" took no booking in thirty days while plain
     * "Atlanta" took 80 — a run without this threshold would put both on the
     * screen and the consolidation would be undone by the tool meant to track
     * it.
     */
    public function test_a_name_with_no_recent_bookings_is_not_created(): void
    {
        $tenant = $this->provisionTenant('Alpha');

        $this->artisan('scheduling:import-a1-areas', [
            'file' => $this->file([
                ['franchise' => $tenant['franchise']->name, 'name' => 'Atlanta North', 'booked_30d' => 0],
                ['franchise' => $tenant['franchise']->name, 'name' => 'Atlanta', 'booked_30d' => 80],
            ]),
            '--organization' => 'Alpha',
        ])->assertSuccessful();

        app(TenantContext::class)->set($tenant['org']);
        $this->assertSame(['Atlanta'], ServiceArea::query()->pluck('name')->all());
    }

    /**
     * IT NEVER DELETES, and this is the case that proves it.
     *
     * An area of ours the measurement does not mention is a retire candidate,
     * not rubbish: "Atlanta North" may hold capacity somebody configured, and
     * dropping it would take that with it. It is reported and left standing.
     */
    public function test_an_area_the_file_does_not_mention_survives(): void
    {
        $tenant = $this->provisionTenant('Alpha');

        app(TenantContext::class)->set($tenant['org']);
        ServiceArea::create([
            'franchise_id' => $tenant['franchise']->id,
            'name' => 'Atlanta North',
        ]);

        $this->artisan('scheduling:import-a1-areas', [
            'file' => $this->file([
                ['franchise' => $tenant['franchise']->name, 'name' => 'Atlanta', 'booked_30d' => 80],
            ]),
            '--organization' => 'Alpha',
        ])
            ->expectsOutputToContain('Atlanta North')
            ->assertSuccessful();

        app(TenantContext::class)->set($tenant['org']);
        $this->assertTrue(
            ServiceArea::query()->where('name', 'Atlanta North')->exists(),
            'The command must never delete an area the measurement did not mention.',
        );
    }

    /**
     * DUPLICATE FRANCHISE NAMES RESOLVE TO THE ONE IN USE, NOT AT RANDOM.
     *
     * A1-Group holds Connecticut ×8, Texas ×3, Nationwide ×2, DMV ×2 — empty
     * rows created on 2026-09-10 and 2026-09-14. Attaching Nationwide's areas
     * to one of those would put them somewhere nobody looks, and nothing would
     * error.
     *
     * The tie-break here is the OLDEST, because it is the one that has to work:
     * Nationwide has zero areas on both rows, so "the one that already has
     * areas" cannot separate them.
     */
    public function test_a_duplicate_franchise_name_resolves_to_the_oldest_row(): void
    {
        $tenant = $this->provisionTenant('Alpha');

        app(TenantContext::class)->set($tenant['org']);

        $original = Franchise::create([
            'organization_id' => $tenant['org']->id,
            'company_id' => $tenant['company']->id,
            'name' => 'Nationwide',
        ]);
        $original->forceFill(['created_at' => now()->subMonth()])->save();

        $duplicate = Franchise::create([
            'organization_id' => $tenant['org']->id,
            'company_id' => $tenant['company']->id,
            'name' => 'Nationwide',
        ]);

        $this->artisan('scheduling:import-a1-areas', [
            'file' => $this->file([
                ['franchise' => 'Nationwide', 'name' => 'Detroit', 'booked_30d' => 69],
            ]),
            '--organization' => 'Alpha',
        ])->assertSuccessful();

        app(TenantContext::class)->set($tenant['org']);
        $area = ServiceArea::query()->where('name', 'Detroit')->first();

        $this->assertNotNull($area, 'Detroit was not created at all.');
        $this->assertSame(
            $original->id,
            $area->franchise_id,
            'The new area landed on the duplicate franchise row, where nobody would find it.',
        );
        $this->assertNotSame($duplicate->id, $area->franchise_id);
    }

    public function test_dry_run_writes_nothing(): void
    {
        $tenant = $this->provisionTenant('Alpha');

        $this->artisan('scheduling:import-a1-areas', [
            'file' => $this->file([
                ['franchise' => $tenant['franchise']->name, 'name' => 'Detroit', 'booked_30d' => 69],
            ]),
            '--organization' => 'Alpha',
            '--dry-run' => true,
        ])->assertSuccessful();

        app(TenantContext::class)->set($tenant['org']);
        $this->assertSame(0, ServiceArea::query()->count());
    }

    /**
     * A NAME WE ALREADY HAVE IS NEVER CREATED A SECOND TIME, even when the
     * measurement puts it under a different franchise.
     *
     * The file names a franchise by which Workiz account books into the area
     * most, and that can disagree with where the area already sits — "NYC" is
     * under New Jersey here, "Pittsburgh, PA" under Ohio. Per-franchise keying
     * would create a second area with the same name and nothing would error:
     * two capacity buckets for one territory, which is the split per-area
     * capacity exists to prevent.
     */
    public function test_a_name_under_another_franchise_is_not_created_twice(): void
    {
        $tenant = $this->provisionTenant('Alpha');

        app(TenantContext::class)->set($tenant['org']);

        $other = Franchise::create([
            'organization_id' => $tenant['org']->id,
            'company_id' => $tenant['company']->id,
            'name' => 'Nationwide',
        ]);

        ServiceArea::create([
            'franchise_id' => $tenant['franchise']->id,
            'name' => 'NYC',
        ]);

        $this->artisan('scheduling:import-a1-areas', [
            'file' => $this->file([
                ['franchise' => $other->name, 'name' => 'NYC', 'booked_30d' => 7],
            ]),
            '--organization' => 'Alpha',
        ])->assertSuccessful();

        app(TenantContext::class)->set($tenant['org']);
        $this->assertSame(
            1,
            ServiceArea::query()->where('name', 'NYC')->count(),
            'One territory ended up with two capacity buckets under two franchises.',
        );
    }
}
