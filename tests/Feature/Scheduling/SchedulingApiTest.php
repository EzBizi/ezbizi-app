<?php

namespace Tests\Feature\Scheduling;

use App\Models\Franchise;
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

    /**
     * The exact payload a pre-0.0.0174 client sends after the read shape
     * changed, and it must be refused rather than stored.
     *
     * That client renders `zips` into a text field with `join(', ')`. Once the
     * server started returning `[{zip, city}]` it rendered
     *
     *     {zip: 75201, city: Dallas}
     *
     * and split it on commas and spaces on Save. Every fragment is under
     * sixteen characters, so `max:16` accepted all four and replaced the
     * area's coverage with pieces of its own serialisation — silently.
     *
     * The tolerance for bare strings exists so an older client can still
     * WRITE. This test is the other half: it must not be able to write
     * nonsense.
     */
    public function test_fragments_of_a_serialised_object_are_not_stored_as_zips(): void
    {
        [$tenant, $editor] = $this->tenantWithEditor();
        $area = $this->area($tenant, 'Dallas');

        $this->signedInAs($editor)
            ->putJson("/api/v1/scheduling/areas/{$area->id}/zips", [
                'zips' => ['75201'],
            ])
            ->assertOk();

        // What the old client actually sends.
        $this->signedInAs($editor)
            ->putJson("/api/v1/scheduling/areas/{$area->id}/zips", [
                'zips' => ['{zip:', '75201', 'city:', 'Dallas}'],
            ])
            ->assertStatus(422);

        app(TenantContext::class)->set($tenant['org']);

        // Refused as a whole, so the real coverage is untouched.
        $held = ServiceAreaZip::where('service_area_id', $area->id)
            ->pluck('zip')
            ->all();

        $this->assertSame(['75201'], $held);
    }

    /** Real postal codes from more than one country still go in. */
    public function test_hyphenated_and_spaced_postal_codes_are_accepted(): void
    {
        [$tenant, $editor] = $this->tenantWithEditor();
        $area = $this->area($tenant, 'Dallas');

        $this->signedInAs($editor)
            ->putJson("/api/v1/scheduling/areas/{$area->id}/zips", [
                'zips' => [
                    ['zip' => '75201-1234', 'city' => 'Dallas'],
                    ['zip' => 'SW1A 1AA', 'city' => 'London'],
                    ['zip' => 'K1A 0B1', 'city' => 'Ottawa'],
                ],
            ])
            ->assertOk();

        app(TenantContext::class)->set($tenant['org']);

        $this->assertSame(
            3,
            ServiceAreaZip::where('service_area_id', $area->id)->count(),
        );
    }

    /**
     * The list is scoped by the VIEW, asserted from both sides of the boundary.
     *
     * This is the claim the Settings screen's wording rests on. That screen
     * says "Every area in this view follows the pattern" and "No service areas
     * in this view" rather than speaking for the organization, and both
     * sentences are honest only if this endpoint actually hides what is out of
     * view. Nothing pinned that until now.
     *
     * BOTH SIDES, because one is the check that cannot fail: from inside the
     * franchise the correct answer and a broken count are identical — an empty
     * list looks the same whether the sibling's area was hidden or the query
     * returned nothing at all. The organization view is what proves the row
     * existed to be hidden.
     *
     * The view has to be SET. A user with no `navigation.scope` is in the
     * organization view however they are assigned, so without the preference
     * this would measure reach rather than view and pass either way.
     */
    public function test_the_area_list_is_scoped_to_the_view_from_both_sides(): void
    {
        [$tenant, $editor] = $this->tenantWithEditor();

        app(TenantContext::class)->set($tenant['org']);

        $sibling = Franchise::create([
            'organization_id' => $tenant['org']->id,
            'company_id' => $tenant['company']->id,
            'name' => 'Georgia',
        ]);

        ServiceArea::create(['franchise_id' => $sibling->id, 'name' => 'Atlanta']);
        ServiceArea::create(['franchise_id' => $tenant['franchise']->id, 'name' => 'Dallas']);

        // Side one: looking at their own franchise, Atlanta is not there.
        $editor->forceFill([
            'preferences' => ['navigation.scope' => 'franchise:'.$tenant['franchise']->id],
        ])->save();

        $narrow = collect(
            $this->signedInAs($editor)->getJson('/api/v1/scheduling/areas')->assertOk()->json('data')
        )->pluck('name');

        $this->assertSame(['Dallas'], $narrow->all());

        // Side two: the same person in the organization view sees the row that
        // was hidden. Without this, the assertion above passes for an endpoint
        // that has stopped returning anything.
        // The request above cleared the tenant, and a User is tenant-owned, so
        // the boundary refuses this write without it.
        app(TenantContext::class)->set($tenant['org']);
        $editor->forceFill(['preferences' => []])->save();

        $wide = collect(
            $this->signedInAs($editor)->getJson('/api/v1/scheduling/areas')->assertOk()->json('data')
        )->pluck('name')->sort()->values();

        $this->assertSame(['Atlanta', 'Dallas'], $wide->all());
    }

    /**
     * Every area's week arrives with the list, and a CLOSED day is null.
     *
     * The Overview draws one row per area without a request per area, so the
     * index carries the numbers. The part worth pinning is not that they are
     * there but that a closed day is `null` and never `0`: zero would be a day
     * that is open and takes nothing, which this module's storage deliberately
     * cannot express — absence of slot rows IS the closure. A client drawing
     * "0" where it should draw a dash would say the area works Sundays and is
     * full.
     *
     * All seven days are present, because a missing key cannot be told from a
     * closed one by anybody reading the payload.
     */
    public function test_the_list_carries_each_week_with_closed_days_as_null(): void
    {
        [$tenant, $editor] = $this->tenantWithEditor();
        $area = $this->area($tenant, 'Dallas');

        $this->signedInAs($editor)
            ->putJson("/api/v1/scheduling/areas/{$area->id}/slots", [
                'slots' => [
                    ['weekday' => 1, 'start_time' => '07:00', 'end_time' => '11:00', 'max_jobs' => 2],
                    ['weekday' => 1, 'start_time' => '13:00', 'end_time' => '17:00', 'max_jobs' => 3],
                    ['weekday' => 4, 'start_time' => '07:00', 'end_time' => '11:00', 'max_jobs' => 1],
                ],
            ])
            ->assertOk();

        $week = $this->signedInAs($editor)
            ->getJson('/api/v1/scheduling/areas')
            ->assertOk()
            ->json('data.0.week');

        $this->assertCount(7, $week, 'Every weekday travels, open or not.');

        // THE DISCRIMINATOR, AND IT HAS TO READ THE RAW BODY. Keys 0..6 in
        // order make a PHP list, which json_encode writes as a JSON ARRAY —
        // and the client reads a map keyed by weekday, so an array threw a
        // cast error and greyed the whole Settings pane.
        //
        // Every decoded assertion is blind to this. `->json()` decodes with
        // assoc, and PHP turns the object's "0","1",... back into INT keys, so
        // `array_is_list()` calls the decoded object a list too — my first
        // attempt at this check asserted exactly that and failed against the
        // fixed code. The two shapes differ in the bytes and nowhere else.
        $raw = $this->signedInAs($editor)
            ->getJson('/api/v1/scheduling/areas')
            ->getContent();

        $this->assertStringContainsString(
            '"week":{',
            $raw,
            'week must encode as a JSON object.',
        );
        $this->assertStringNotContainsString('"week":[', $raw);

        // Monday sums its two slots rather than reporting either.
        $this->assertSame(5, $week['1']);
        $this->assertSame(1, $week['4']);

        // Sunday has no rows: null, and emphatically not 0.
        $this->assertNull($week['0']);
        $this->assertNotSame(0, $week['0']);
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
