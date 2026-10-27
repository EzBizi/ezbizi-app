<?php

namespace Tests\Feature\Scheduling;

use App\Http\Controllers\Api\SchedulingAreaMapController;
use App\Models\ServiceArea;
use App\Models\ServiceAreaZip;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/**
 * Drawn service areas (Jose, 2026-10-21): the ZIPs whose centre is inside a
 * shape are assigned on save, another area's ZIP moves only when taken, and
 * deleting an area says where its ZIPs went.
 */
class ServiceAreaMapTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    private const SQUARE = [[38.0, -77.0], [38.0, -76.0], [39.0, -76.0], [39.0, -77.0]];

    protected function setUp(): void
    {
        parent::setUp();
        DB::table('zip_centroids')->insert([
            ['zip' => '20601', 'latitude' => 38.5, 'longitude' => -76.5],   // inside
            ['zip' => '20602', 'latitude' => 38.6, 'longitude' => -76.4],   // inside, owned by B
            ['zip' => '20603', 'latitude' => 38.9, 'longitude' => -76.9],   // inside
            ['zip' => '30301', 'latitude' => 33.7, 'longitude' => -84.4],   // outside
            ['zip' => '20699', 'latitude' => 38.95, 'longitude' => -75.5],  // in the box's bbox row, outside the triangle
        ]);
    }

    private function area(array $t, string $name): ServiceArea
    {
        app(TenantContext::class)->set($t['org']);

        return ServiceArea::create(['franchise_id' => $t['franchise']->id, 'name' => $name]);
    }

    public function test_drawing_assigns_the_zips_inside_and_moves_a_conflict_only_when_taken(): void
    {
        $t = $this->provisionTenant('Alpha');
        $a = $this->area($t, 'Charlotte Hall');
        $b = $this->area($t, 'Waldorf');
        ServiceAreaZip::create(['franchise_id' => $b->franchise_id, 'service_area_id' => $b->id, 'zip' => '20602']);
        ServiceAreaZip::create(['franchise_id' => $a->franchise_id, 'service_area_id' => $a->id, 'zip' => '30301']); // typed, far away
        $as = $this->signedInAs($t['admin']);

        $plan = $as->postJson("/api/v1/scheduling/areas/{$a->id}/polygons/preview", ['polygons' => [self::SQUARE]])->assertOk()->json('data');
        $this->assertSame(['20601', '20603'], $plan['add']);
        $this->assertSame([['zip' => '20602', 'area_id' => $b->id, 'area_name' => 'Waldorf', 'may_take' => true]], $plan['conflicts']);
        $this->assertSame(['30301'], $plan['outside']);
        $this->assertSame([], $plan['remove']);

        // Not taken: the conflict stays with its owner.
        $as->putJson("/api/v1/scheduling/areas/{$a->id}/polygons", ['polygons' => [self::SQUARE]])
            ->assertOk()->assertJsonPath('data.left_with_owner', ['20602']);
        app(TenantContext::class)->set($t['org']);
        $this->assertSame($b->id, ServiceAreaZip::where('zip', '20602')->value('service_area_id'));

        // Taken: it moves.
        $as->putJson("/api/v1/scheduling/areas/{$a->id}/polygons", ['polygons' => [self::SQUARE], 'take' => ['20602']])
            ->assertOk()->assertJsonPath('data.moved', ['20602']);
        app(TenantContext::class)->set($t['org']);
        $this->assertSame($a->id, ServiceAreaZip::where('zip', '20602')->value('service_area_id'));

        // Redrawn smaller: drawn ZIPs outside go, the typed one stays.
        $small = [[38.4, -76.6], [38.4, -76.3], [38.7, -76.3], [38.7, -76.6]];
        $as->putJson("/api/v1/scheduling/areas/{$a->id}/polygons", ['polygons' => [$small]])
            ->assertOk()->assertJsonPath('data.removed', ['20603']);
        app(TenantContext::class)->set($t['org']);
        $this->assertEqualsCanonicalizing(['20601', '20602', '30301'],
            ServiceAreaZip::where('service_area_id', $a->id)->pluck('zip')->all());

        // Views: one area, the branch, the company.
        $map = $as->getJson("/api/v1/scheduling/areas/{$a->id}/map")->assertOk()->json('data');
        $this->assertCount(1, $map['polygons']);
        $this->assertSame(38.5, collect($map['zips'])->firstWhere('zip', '20601')['latitude']);
        $branch = $as->getJson("/api/v1/scheduling/areas-map?franchise_id={$t['franchise']->id}")->assertOk()->json('data');
        $this->assertSame(['Charlotte Hall', 'Waldorf'], array_column($branch, 'name'));
        $this->assertCount(2, $as->getJson('/api/v1/scheduling/areas-map')->assertOk()->json('data'));
    }

    public function test_a_triangle_excludes_a_centre_inside_its_bounding_box(): void
    {
        $t = $this->provisionTenant('Alpha');
        $a = $this->area($t, 'Charlotte Hall');
        $triangle = [[38.0, -77.0], [38.0, -75.0], [39.0, -77.0]];

        $add = $this->signedInAs($t['admin'])
            ->postJson("/api/v1/scheduling/areas/{$a->id}/polygons/preview", ['polygons' => [$triangle]])->json('data.add');
        $this->assertNotContains('20699', $add);
        $this->assertContains('20601', $add);
    }

    /** Auto draw (Jose, 2026-10-27): the ZIP centres' hull, pushed out so edge ZIPs sit inside. */
    public function test_an_outline_is_drawn_from_the_zips_and_holds_them_all(): void
    {
        $t = $this->provisionTenant('Alpha');
        $a = $this->area($t, 'San Antonio');
        $as = $this->signedInAs($t['admin']);
        ServiceAreaZip::create(['franchise_id' => $a->franchise_id, 'service_area_id' => $a->id, 'zip' => '20601']);
        $as->getJson("/api/v1/scheduling/areas/{$a->id}/outline")->assertStatus(422);

        app(TenantContext::class)->set($t['org']);
        foreach (['20602', '20603', '30301'] as $z) {
            ServiceAreaZip::create(['franchise_id' => $a->franchise_id, 'service_area_id' => $a->id, 'zip' => $z]);
        }
        $poly = $as->getJson("/api/v1/scheduling/areas/{$a->id}/outline")->assertOk()->json('data.polygon');
        $this->assertGreaterThanOrEqual(3, count($poly));
        foreach ([[38.5, -76.5], [38.6, -76.4], [38.9, -76.9], [33.7, -84.4]] as [$lat, $lng]) {
            $this->assertTrue(SchedulingAreaMapController::contains($poly, $lat, $lng), "{$lat},{$lng} is inside");
        }
    }

    /** Recommended (Jose, 2026-10-27): a circle round a stored centre ZIP and radius. */
    public function test_a_recommended_circle_comes_from_the_stored_centre_and_radius(): void
    {
        $t = $this->provisionTenant('Alpha');
        $a = $this->area($t, 'Charlotte Hall');
        $as = $this->signedInAs($t['admin']);
        $as->getJson("/api/v1/scheduling/areas/{$a->id}/outline?mode=recommended")->assertStatus(422);

        $as->patchJson("/api/v1/scheduling/areas/{$a->id}", ['center_zip' => '20601', 'radius_miles' => 10])->assertOk()->assertJsonPath('data.radius_miles', 10);
        $circle = $as->getJson("/api/v1/scheduling/areas/{$a->id}/outline?mode=recommended")->assertOk()->json('data.polygon');
        $this->assertCount(32, $circle);
        $this->assertTrue(SchedulingAreaMapController::contains($circle, 38.5, -76.5), 'the centre is inside');
        $this->assertTrue(SchedulingAreaMapController::contains($circle, 38.6, -76.4), '20602, ~9 miles out, is inside');
        $this->assertFalse(SchedulingAreaMapController::contains($circle, 38.9, -76.9), '20603, ~35 miles out, is not');
    }

    public function test_an_area_keeps_its_own_colour(): void
    {
        $t = $this->provisionTenant('Alpha');
        $a = $this->area($t, 'Dallas');
        $as = $this->signedInAs($t['admin']);
        $as->patchJson("/api/v1/scheduling/areas/{$a->id}", ['color' => '#1A73E8'])->assertOk()->assertJsonPath('data.color', '#1A73E8')->assertJsonPath('data.name', 'Dallas');
        $as->patchJson("/api/v1/scheduling/areas/{$a->id}", ['color' => 'blue'])->assertStatus(422);
        $this->assertSame('#1A73E8', $as->getJson('/api/v1/scheduling/areas-map')->json('data.0.color'));
    }

    public function test_deleting_an_area_moves_its_zips_or_says_they_are_unassigned(): void
    {
        $t = $this->provisionTenant('Alpha');
        $a = $this->area($t, 'Old');
        $b = $this->area($t, 'New');
        $c = $this->area($t, 'Gone');
        ServiceAreaZip::create(['franchise_id' => $a->franchise_id, 'service_area_id' => $a->id, 'zip' => '20601']);
        ServiceAreaZip::create(['franchise_id' => $c->franchise_id, 'service_area_id' => $c->id, 'zip' => '20603']);
        $as = $this->signedInAs($t['admin']);

        $as->deleteJson("/api/v1/scheduling/areas/{$a->id}?move_zips_to={$b->id}")
            ->assertOk()->assertJsonPath('data.moved', 1)->assertJsonPath('data.moved_to.name', 'New');
        app(TenantContext::class)->set($t['org']);
        $this->assertSame($b->id, ServiceAreaZip::where('zip', '20601')->value('service_area_id'));

        $as->deleteJson("/api/v1/scheduling/areas/{$c->id}")->assertOk()->assertJsonPath('data.unassigned', 1);

        // Another tenant's area cannot receive them.
        $other = $this->provisionTenant('Bravo', 'b@example.test');
        $theirs = $this->area($other, 'Theirs');
        $this->signedInAs($t['admin'])->deleteJson("/api/v1/scheduling/areas/{$b->id}?move_zips_to={$theirs->id}")->assertNotFound();
    }

    public function test_the_centroid_loader_reads_the_gazetteer_shape(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'gaz');
        file_put_contents($file, "GEOID\tALAND\tAWATER\tALAND_SQMI\tAWATER_SQMI\tINTPTLAT\tINTPTLONG\n"
            ."00601\t1\t1\t1\t1\t18.180555\t-66.749961\n20601\t1\t1\t1\t1\t38.6\t-76.9   \n");

        $this->artisan('scheduling:load-zip-centroids', ['--file' => $file])->assertSuccessful();
        unlink($file);

        $this->assertSame(2, DB::table('zip_centroids')->count());
        $this->assertEqualsWithDelta(-76.9, (float) DB::table('zip_centroids')->where('zip', '20601')->value('longitude'), 0.0001);
    }
}
