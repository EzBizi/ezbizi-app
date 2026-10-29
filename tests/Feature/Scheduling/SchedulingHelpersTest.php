<?php

namespace Tests\Feature\Scheduling;

use App\Models\Customer;
use App\Models\Job;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/**
 * Scheduling › Helpers, A1 Tools' helper calendar in EzBizi (Jose,
 * 2026-10-20). Its three rules: no double-booking (a full day takes both
 * halves), your own bookings only unless you can edit Scheduling, and
 * franchise scope.
 */
class SchedulingHelpersTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    public function test_helpers_are_booked_once_per_half_and_only_by_their_owner(): void
    {
        $t = $this->provisionTenant('Alpha');
        $fr = $t['franchise']->id;
        $tech = $this->userWithRole($t, 'Technician', ['scheduling.view', 'scheduling.helpers.view']);
        $other = $this->userWithRole($t, 'Technician 2', ['scheduling.view', 'scheduling.helpers.view'], 'two@example.test');

        // The roster takes scheduling.edit.
        $this->signedInAs($tech)->postJson('/api/v1/scheduling/helpers', ['franchise_id' => $fr, 'name' => 'Sam'])->assertForbidden();
        $helper = $this->signedInAs($t['admin'])->postJson('/api/v1/scheduling/helpers', ['franchise_id' => $fr, 'name' => 'Sam'])
            ->assertCreated()->json('data.id');

        // "For" lists people only to those who may book for others.
        $this->assertSame([], $this->signedInAs($tech)->getJson("/api/v1/scheduling/helpers?franchise_id={$fr}")->json('people'));
        $this->assertContains($other->id, array_column($this->signedInAs($t['admin'])->getJson("/api/v1/scheduling/helpers?franchise_id={$fr}")->json('people'), 'id'));

        $book = fn ($who, $slot, $date = '2026-11-02') => $this->signedInAs($who)
            ->postJson('/api/v1/scheduling/helpers/bookings', ['helper_id' => $helper, 'date' => $date, 'slot' => $slot]);

        $am = $book($tech, 'am')->assertCreated()->json('data');
        $this->assertSame([$tech->id, true], [$am['technician']['id'], $am['can_change']]);
        $book($other, 'am')->assertUnprocessable();      // same half
        $book($other, 'full')->assertUnprocessable();    // a full day takes the morning too
        $book($other, 'pm')->assertCreated();            // the afternoon is free
        $book($other, 'full', '2026-11-03')->assertCreated();
        $book($tech, 'pm', '2026-11-03')->assertUnprocessable(); // a half on a full day
        $book($tech, 'am', '2026-11-03')->assertUnprocessable(); // either half

        // Your own only: the other technician cannot move or remove it.
        $this->signedInAs($other)->deleteJson("/api/v1/scheduling/helpers/bookings/{$am['id']}")->assertForbidden();
        $this->signedInAs($other)->patchJson("/api/v1/scheduling/helpers/bookings/{$am['id']}", ['slot' => 'full'])->assertForbidden();
        // Booking FOR someone else takes scheduling.edit.
        $this->signedInAs($tech)->postJson('/api/v1/scheduling/helpers/bookings',
            ['helper_id' => $helper, 'date' => '2026-11-04', 'slot' => 'am', 'technician_id' => $other->id])->assertForbidden();
        $this->signedInAs($t['admin'])->postJson('/api/v1/scheduling/helpers/bookings',
            ['helper_id' => $helper, 'date' => '2026-11-04', 'slot' => 'am', 'technician_id' => $other->id])
            ->assertCreated()->assertJsonPath('data.technician.id', $other->id);

        $month = $this->signedInAs($other)->getJson("/api/v1/scheduling/helpers/bookings?franchise_id={$fr}&month=2026-11")
            ->assertOk()->json('data');
        $this->assertCount(4, $month);
        $this->assertFalse(collect($month)->firstWhere('id', $am['id'])['can_change']);

        // Moving your own onto a taken half is refused; deleting it frees it.
        $this->signedInAs($tech)->patchJson("/api/v1/scheduling/helpers/bookings/{$am['id']}", ['slot' => 'pm'])->assertUnprocessable();
        $this->signedInAs($tech)->deleteJson("/api/v1/scheduling/helpers/bookings/{$am['id']}")->assertOk();
        $book($other, 'am')->assertCreated();

        // A retired helper takes no new bookings.
        $this->signedInAs($t['admin'])->patchJson("/api/v1/scheduling/helpers/{$helper}", ['is_active' => false])->assertOk();
        $book($tech, 'am', '2026-11-10')->assertUnprocessable();
    }

    public function test_a_booking_shows_the_job_its_number_names(): void
    {
        $t = $this->provisionTenant('Alpha');
        $fr = $t['franchise']->id;
        app(TenantContext::class)->set($t['org']);
        $customer = Customer::create(['owning_franchise_id' => $fr, 'number' => 'C-1', 'name' => 'Charles O Neil', 'city' => 'Dallas']);
        Job::create(['franchise_id' => $fr, 'customer_id' => $customer->id, 'number' => '54950', 'title' => 'Sweep', 'status' => 'Submitted', 'scheduled_for' => '2026-11-02 08:00:00']);
        $as = $this->signedInAs($t['admin']);
        $helper = $as->postJson('/api/v1/scheduling/helpers', ['franchise_id' => $fr, 'name' => 'Sam'])->json('data.id');

        $found = $as->postJson('/api/v1/scheduling/helpers/bookings', ['helper_id' => $helper, 'date' => '2026-11-02', 'slot' => 'am', 'job_number' => ' 54950 '])
            ->assertCreated()->json('data.job');
        $this->assertSame(['Charles O Neil', 'Dallas'], [$found['customer'], $found['address']]);
        // A number not in EzBizi yet is normal on a fresh job: kept, shown without a job.
        $as->postJson('/api/v1/scheduling/helpers/bookings', ['helper_id' => $helper, 'date' => '2026-11-02', 'slot' => 'pm', 'job_number' => '99999'])
            ->assertCreated()->assertJsonPath('data.job', null)->assertJsonPath('data.job_number', '99999');
    }

    public function test_another_tenants_helpers_are_out_of_reach(): void
    {
        $a = $this->provisionTenant('Alpha');
        $b = $this->provisionTenant('Bravo');
        $helper = $this->signedInAs($a['admin'])->postJson('/api/v1/scheduling/helpers',
            ['franchise_id' => $a['franchise']->id, 'name' => 'Sam'])->json('data.id');

        $this->signedInAs($b['admin'])->getJson("/api/v1/scheduling/helpers?franchise_id={$a['franchise']->id}")->assertNotFound();
        $this->signedInAs($b['admin'])->postJson('/api/v1/scheduling/helpers/bookings',
            ['helper_id' => $helper, 'date' => '2026-11-02', 'slot' => 'am'])->assertNotFound();
        $this->signedInAs($b['admin'])->postJson('/api/v1/scheduling/helpers',
            ['franchise_id' => $a['franchise']->id, 'name' => 'Intruder'])->assertNotFound();
    }
}
