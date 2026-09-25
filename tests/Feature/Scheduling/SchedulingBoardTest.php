<?php

namespace Tests\Feature\Scheduling;

use App\Models\Franchise;
use App\Models\SchedulingTimeSlot;
use App\Models\User;
use App\Models\WorkizJob;
use App\Models\WorkizTeamMember;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/**
 * The Schedule board — one day, technicians × time slots (Jose, 2026-09-25,
 * with A1 Tools' board as the model). Read-only: it shows who is doing what,
 * and changes nothing in Workiz.
 */
class SchedulingBoardTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    private ?User $viewer = null;

    private function day(array $tenant): void
    {
        app(TenantContext::class)->set($tenant['org']);

        SchedulingTimeSlot::create(['start_time' => '08:00', 'end_time' => '11:00']);
        SchedulingTimeSlot::create(['start_time' => '11:00', 'end_time' => '14:00']);

        WorkizTeamMember::create([
            'franchise_id' => $tenant['franchise']->id,
            'remote_id' => '101',
            'name' => 'Ivan Sharkov',
            'is_field_tech' => true,
            'is_active' => true,
            'payload' => [],
            'synced_at' => now(),
        ]);
        WorkizTeamMember::create([
            'franchise_id' => $tenant['franchise']->id,
            'remote_id' => '102',
            'name' => 'Harry Jenkins',
            'is_field_tech' => true,
            'is_active' => true,
            'payload' => [],
            'synced_at' => now(),
        ]);
    }

    private function job(array $tenant, string $at, array $team = [], string $status = 'Submitted', ?Franchise $franchise = null): void
    {
        app(TenantContext::class)->set($tenant['org']);

        WorkizJob::create([
            'franchise_id' => ($franchise ?? $tenant['franchise'])->id,
            'remote_id' => strtoupper(bin2hex(random_bytes(4))),
            'service_area' => 'Hartford',
            'postal_code' => '06103',
            'city' => 'Hartford',
            'address' => '1430 Red Oaks Dr',
            'status' => $status,
            'scheduled_for' => $at,
            'payload' => ['FirstName' => 'John', 'LastName' => 'Lynch', 'JobType' => 'Chimney Inspection', 'SerialId' => 400594, 'Team' => $team],
            'synced_at' => now(),
        ]);
    }

    private function board(array $tenant, array $query = []): array
    {
        $this->viewer ??= $this->userWithRole($tenant, 'Dispatcher', ['scheduling.view', 'scheduling.schedule.view']);

        return $this->signedInAs($this->viewer)
            ->getJson('/api/v1/scheduling/board?'.http_build_query(['date' => '2026-09-25'] + $query))
            ->assertOk()
            ->json('data');
    }

    /** An assigned job lands on its technician, in the window its time falls in. */
    public function test_an_assigned_job_lands_on_its_technician_and_window(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $this->day($tenant);

        // 11:00 exactly is the second window, not the first.
        $this->job($tenant, '2026-09-25 11:00:00', [['id' => 101, 'Name' => 'Ivan Sharkov']]);

        $board = $this->board($tenant);

        $this->assertSame(1, $board['counts']['scheduled']);
        $this->assertSame('Ivan Sharkov', $board['technicians'][0]['name'], 'The busy technician reads first.');
        $this->assertSame(1, $board['technicians'][0]['jobs']);

        $card = $board['scheduled'][0];
        $this->assertSame($board['technicians'][0]['id'], $card['technician_id']);
        $this->assertSame(1, $card['window']);
        $this->assertSame('John Lynch', $card['customer']);
        $this->assertSame('400594', $card['number']);
    }

    /** Nobody on it → unassigned. Cancelled and done are not on the board at all. */
    public function test_a_job_with_nobody_on_it_is_unassigned_and_cancelled_is_left_out(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $this->day($tenant);

        $this->job($tenant, '2026-09-25 08:00:00');
        $this->job($tenant, '2026-09-25 09:00:00', [], 'Canceled');
        $this->job($tenant, '2026-09-25 09:30:00', [['id' => 102, 'Name' => 'Harry Jenkins']], 'Done');

        $board = $this->board($tenant);

        $this->assertSame(1, $board['counts']['unassigned']);
        $this->assertSame(0, $board['counts']['scheduled']);
        $this->assertSame(0, $board['unassigned'][0]['window']);
    }

    /**
     * A technician on a job but missing from the mirror still gets a row.
     * Hiding their work because a sync lagged would show a quiet day that is
     * not one.
     */
    public function test_a_technician_missing_from_the_mirror_still_gets_a_row(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $this->day($tenant);

        $this->job($tenant, '2026-09-25 08:00:00', [['id' => 999, 'Name' => 'New Hire']]);

        $names = array_column($this->board($tenant)['technicians'], 'name');

        $this->assertContains('New Hire', $names);
    }

    /** Picking a franchise shows only that franchise's technicians and jobs. */
    public function test_a_franchise_filter_narrows_the_board(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $this->day($tenant);

        app(TenantContext::class)->set($tenant['org']);
        $other = Franchise::create([
            'organization_id' => $tenant['org']->id,
            'company_id' => $tenant['company']->id,
            'name' => 'Nationwide',
        ]);

        $this->job($tenant, '2026-09-25 08:00:00');
        $this->job($tenant, '2026-09-25 08:00:00', [], 'Submitted', $other);

        $this->assertSame(2, $this->board($tenant)['counts']['unassigned']);
        $this->assertSame(1, $this->board($tenant, ['franchise_id' => $other->id])['counts']['unassigned']);
    }

    /** The Schedule tab is its own grant. */
    public function test_the_board_needs_the_schedule_tab(): void
    {
        $tenant = $this->provisionTenant('Alpha');

        $availabilityOnly = $this->userWithRole($tenant, 'Caller', ['scheduling.view', 'scheduling.availability.view']);

        $this->signedInAs($availabilityOnly)
            ->getJson('/api/v1/scheduling/board?date=2026-09-25')
            ->assertForbidden();
    }
}
