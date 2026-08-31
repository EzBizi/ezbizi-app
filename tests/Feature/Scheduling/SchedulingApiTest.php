<?php

namespace Tests\Feature\Scheduling;

use App\Models\ServiceArea;
use App\Models\ServiceAreaSlot;
use App\Models\ServiceAreaZip;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/**
 * Capacity settings over the wire
 * (design docs/previews/scheduling-layouts.html #7).
 *
 * The three checks worth having are the three promises the screen makes:
 * a closed day is an ABSENCE, an apply is ALL OR NONE, and a zip clash names
 * the area holding it. Everything else here is CRUD that would fail loudly.
 */
class SchedulingApiTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    /** @return array{0: array, 1: \App\Models\User} */
    private function tenantWithEditor(): array
    {
        $tenant = $this->provisionTenant('Alpha');

        return [$tenant, $tenant['admin']];
    }

    private function area(array $tenant, string $name): ServiceArea
    {
        app(TenantContext::class)->set($tenant['org']);

        return ServiceArea::create([
            'franchise_id' => $tenant['franchise']->id,
            'name' => $name,
        ]);
    }

    /**
     * A closed day is a weekday with no rows. The API must not offer zero as a
     * way to say it — if it did, "we do not work Sundays" and "we work Sundays
     * and are full" would become the same stored state and nothing would err.
     */
    public function test_a_day_is_closed_by_omitting_it_and_zero_capacity_is_refused(): void
    {
        [$tenant, $editor] = $this->tenantWithEditor();
        $area = $this->area($tenant, 'Dallas');

        $this->signedInAs($editor)
            ->putJson("/api/v1/scheduling/areas/{$area->id}/slots", [
                'slots' => [
                    ['weekday' => 1, 'start_time' => '07:00', 'end_time' => '11:00', 'max_jobs' => 2],
                ],
            ])
            ->assertOk();

        // Monday has a row; Sunday simply has none. That absence IS the closure.
        // The tenant is re-set because the request that just ran cleared it,
        // and the boundary refuses an unscoped read rather than quietly
        // returning every organization's rows.
        app(TenantContext::class)->set($tenant['org']);

        $this->assertSame(1, ServiceAreaSlot::where('service_area_id', $area->id)->count());
        $this->assertSame(
            0,
            ServiceAreaSlot::where('service_area_id', $area->id)->where('weekday', 0)->count(),
        );

        $this->signedInAs($editor)
            ->putJson("/api/v1/scheduling/areas/{$area->id}/slots", [
                'slots' => [
                    ['weekday' => 0, 'start_time' => '07:00', 'end_time' => '11:00', 'max_jobs' => 0],
                ],
            ])
            ->assertStatus(422);
    }

    public function test_two_slots_that_overlap_on_one_day_are_refused(): void
    {
        [$tenant, $editor] = $this->tenantWithEditor();
        $area = $this->area($tenant, 'Dallas');

        $this->signedInAs($editor)
            ->putJson("/api/v1/scheduling/areas/{$area->id}/slots", [
                'slots' => [
                    ['weekday' => 1, 'start_time' => '07:00', 'end_time' => '11:00', 'max_jobs' => 2],
                    ['weekday' => 1, 'start_time' => '10:00', 'end_time' => '15:00', 'max_jobs' => 2],
                ],
            ])
            ->assertStatus(422);
    }

    /**
     * Guard 1. A named area that is out of view fails the WHOLE apply, so the
     * partial state that manufactures unintended drift is never reached.
     */
    public function test_an_apply_naming_an_unreachable_area_changes_nothing(): void
    {
        [$tenant, $editor] = $this->tenantWithEditor();
        $dallas = $this->area($tenant, 'Dallas');

        $other = $this->provisionTenant('Beta', 'beta@example.test');
        $houston = $this->area($other, 'Houston');

        $this->signedInAs($editor)
            ->postJson('/api/v1/scheduling/pattern/apply', [
                'area_ids' => [$dallas->id, $houston->id],
                'slots' => [
                    ['weekday' => 1, 'start_time' => '07:00', 'end_time' => '11:00', 'max_jobs' => 2],
                ],
            ])
            ->assertStatus(422);

        // Dallas was reachable and named first. It must still be untouched.
        app(TenantContext::class)->set($tenant['org']);

        $this->assertSame(0, ServiceAreaSlot::where('service_area_id', $dallas->id)->count());
    }

    /** Guard 2. The preview names each area and what it would become. */
    public function test_preview_names_every_area_it_would_change_and_what_changes(): void
    {
        [$tenant, $editor] = $this->tenantWithEditor();
        $dallas = $this->area($tenant, 'Dallas');
        $houston = $this->area($tenant, 'Houston');

        $pattern = [
            ['weekday' => 4, 'start_time' => '15:00', 'end_time' => '17:00', 'max_jobs' => 2],
        ];

        // Dallas already matches; Houston takes 1 where the pattern says 2.
        $this->signedInAs($editor)
            ->putJson("/api/v1/scheduling/areas/{$dallas->id}/slots", ['slots' => $pattern])
            ->assertOk();

        $this->signedInAs($editor)
            ->putJson("/api/v1/scheduling/areas/{$houston->id}/slots", [
                'slots' => [
                    ['weekday' => 4, 'start_time' => '15:00', 'end_time' => '17:00', 'max_jobs' => 1],
                ],
            ])
            ->assertOk();

        $body = $this->signedInAs($editor)
            ->postJson('/api/v1/scheduling/pattern/preview', [
                'area_ids' => [$dallas->id, $houston->id],
                'slots' => $pattern,
            ])
            ->assertOk()
            ->json('data');

        $this->assertSame(1, $body['unchanged'], 'Dallas already matches and should not be listed.');
        $this->assertCount(1, $body['changes']);
        $this->assertSame('Houston', $body['changes'][0]['name']);
        $this->assertSame(1, $body['changes'][0]['from'][0]['max_jobs']);
        $this->assertSame(2, $body['changes'][0]['to'][0]['max_jobs']);
    }

    /** The pattern is derived from the rows, so it cannot disagree with them. */
    public function test_the_overview_derives_the_pattern_and_names_who_differs(): void
    {
        [$tenant, $editor] = $this->tenantWithEditor();
        $pattern = [
            ['weekday' => 1, 'start_time' => '07:00', 'end_time' => '11:00', 'max_jobs' => 2],
        ];

        foreach (['Dallas', 'Fort Worth', 'Houston'] as $name) {
            $area = $this->area($tenant, $name);

            $this->signedInAs($editor)->putJson(
                "/api/v1/scheduling/areas/{$area->id}/slots",
                ['slots' => $name === 'Houston'
                    ? [['weekday' => 1, 'start_time' => '07:00', 'end_time' => '11:00', 'max_jobs' => 1]]
                    : $pattern],
            )->assertOk();
        }

        $body = $this->signedInAs($editor)->getJson('/api/v1/scheduling/areas')->assertOk()->json();

        $this->assertSame(2, $body['pattern']['followed_by']);
        $this->assertSame(3, $body['pattern']['of']);

        $differs = collect($body['data'])->firstWhere('name', 'Houston');
        $this->assertFalse($differs['follows_pattern']);
    }

    /** The clash names the area holding the zip, not just "already taken". */
    public function test_a_zip_already_in_another_area_is_refused_by_name(): void
    {
        [$tenant, $editor] = $this->tenantWithEditor();
        $dallas = $this->area($tenant, 'Dallas');
        $houston = $this->area($tenant, 'Houston');

        $this->signedInAs($editor)
            ->putJson("/api/v1/scheduling/areas/{$dallas->id}/zips", ['zips' => ['75201']])
            ->assertOk();

        $this->signedInAs($editor)
            ->putJson("/api/v1/scheduling/areas/{$houston->id}/zips", ['zips' => ['75201']])
            ->assertStatus(422)
            ->assertJsonPath('errors.zips.0', fn (string $m) => str_contains($m, 'Dallas'));

        // And Houston's own zips are untouched by the refusal.
        app(TenantContext::class)->set($tenant['org']);

        $this->assertSame(0, ServiceAreaZip::where('service_area_id', $houston->id)->count());
    }

    public function test_a_role_without_scheduling_cannot_read_or_write_capacity(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $area = $this->area($tenant, 'Dallas');

        $viewer = $this->userWithRole($tenant, 'Viewer', ['customers.view']);

        $this->signedInAs($viewer)->getJson('/api/v1/scheduling/areas')->assertForbidden();

        $this->signedInAs($viewer)
            ->putJson("/api/v1/scheduling/areas/{$area->id}/slots", ['slots' => []])
            ->assertForbidden();
    }
}
