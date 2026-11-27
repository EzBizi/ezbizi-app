<?php

namespace Tests\Feature\Scheduling;

use App\Models\Customer;
use App\Models\Franchise;
use App\Models\Job;
use App\Models\SchedulingTechnician;
use App\Models\SchedulingTimeSlot;
use App\Models\ServiceArea;
use App\Models\User;
use App\Support\Scheduling\JobTechnicians;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/**
 * The Schedule board, one day of technicians × time slots, read from EzBizi's
 * own jobs and roster (Jose, 2026-10-27: EzBizi is the master). Nothing here
 * has a Workiz row: a tenant without Workiz gets the same board.
 */
class SchedulingBoardTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    private ?User $viewer = null;

    /** @return array{0: SchedulingTechnician, 1: SchedulingTechnician} */
    private function day(array $tenant): array
    {
        app(TenantContext::class)->set($tenant['org']);
        SchedulingTimeSlot::create(['start_time' => '08:00', 'end_time' => '11:00']);
        SchedulingTimeSlot::create(['start_time' => '11:00', 'end_time' => '14:00']);
        $area = ServiceArea::create(['franchise_id' => $tenant['franchise']->id, 'name' => 'Hartford']);
        $ivan = SchedulingTechnician::create(['franchise_id' => $tenant['franchise']->id, 'name' => 'Ivan Sharkov']);
        $ivan->areas()->sync([$area->id]);
        $harry = SchedulingTechnician::create(['franchise_id' => $tenant['franchise']->id, 'name' => 'Harry Jenkins']);

        return [$ivan, $harry];
    }

    private function job(array $tenant, string $at, array $techs = [], string $status = 'Submitted', ?Franchise $franchise = null): Job
    {
        app(TenantContext::class)->set($tenant['org']);
        $job = Job::factory()->create([
            'franchise_id' => ($franchise ?? $tenant['franchise'])->id, 'customer_id' => Customer::factory()->create(['owning_franchise_id' => ($franchise ?? $tenant['franchise'])->id, 'name' => 'John Lynch'])->id,
            'status' => $status, 'scheduled_for' => $at,
            'job_type' => 'Chimney Inspection', 'service_area_id' => ServiceArea::query()->where('name', 'Hartford')->value('id'),
        ]);
        foreach ($techs as $t) {
            JobTechnicians::assign($job, $t);
        }

        return $job;
    }

    private function board(array $tenant, array $query = []): array
    {
        $this->viewer ??= $this->userWithRole($tenant, 'Dispatcher', ['scheduling.view', 'scheduling.schedule.view']);

        return $this->signedInAs($this->viewer)
            ->getJson('/api/v1/scheduling/board?'.http_build_query(['date' => '2026-09-25'] + $query))
            ->assertOk()
            ->json('data');
    }

    public function test_an_assigned_job_lands_on_its_technician_and_window(): void
    {
        $t = $this->provisionTenant('Alpha');
        [$ivan] = $this->day($t);
        $job = $this->job($t, '2026-09-25 09:30:00', [$ivan]);

        $b = $this->board($t);
        $this->assertSame([[$job->id, $ivan->id, 0, 'Hartford']], array_map(fn ($c) => [$c['job_id'], $c['technician_id'], $c['window'], $c['service_area']], $b['scheduled']));
        $this->assertSame([['member_id' => $ivan->id, 'name' => 'Ivan Sharkov']], $b['scheduled'][0]['team']);
        $tech = collect($b['technicians'])->firstWhere('id', $ivan->id);
        $this->assertSame([1, ['Hartford']], [$tech['jobs'], $tech['service_areas']]);
        $this->assertSame(['scheduled' => 1, 'unassigned' => 0], array_intersect_key($b['counts'], ['scheduled' => 0, 'unassigned' => 0]));
    }

    public function test_a_job_with_nobody_on_it_is_unassigned_and_cancelled_is_left_out(): void
    {
        $t = $this->provisionTenant('Alpha');
        $this->day($t);
        $open = $this->job($t, '2026-09-25 12:00:00');
        $this->job($t, '2026-09-25 12:00:00', status: 'Canceled');

        $b = $this->board($t);
        $this->assertSame([$open->id], array_column($b['unassigned'], 'job_id'));
        $this->assertSame([], $b['scheduled']);
    }

    public function test_an_inactive_technician_still_on_a_job_keeps_a_row(): void
    {
        $t = $this->provisionTenant('Alpha');
        [, $harry] = $this->day($t);
        $this->job($t, '2026-09-25 09:00:00', [$harry]);
        $harry->update(['is_active' => false]);

        $this->assertContains($harry->id, array_column($this->board($t)['technicians'], 'id'));
    }

    public function test_a_franchise_filter_narrows_the_board(): void
    {
        $t = $this->provisionTenant('Alpha');
        [$ivan] = $this->day($t);
        $other = $this->extraFranchise($t, 'Boston');
        $this->job($t, '2026-09-25 09:00:00', [$ivan]);
        $this->job($t, '2026-09-25 09:00:00', franchise: $other);

        $this->assertCount(1, $this->board($t, ['franchise_id' => $other->id])['unassigned']);
        $this->assertSame([], $this->board($t, ['franchise_id' => $other->id])['scheduled']);
    }

    public function test_every_branch_is_counted_once_per_job(): void
    {
        $t = $this->provisionTenant('Alpha');
        [$ivan, $harry] = $this->day($t);
        $other = $this->extraFranchise($t, 'Boston');
        $this->job($t, '2026-09-25 09:00:00', [$ivan, $harry]);
        $this->job($t, '2026-09-25 12:00:00');
        $this->job($t, '2026-09-25 09:00:00', franchise: $other);

        $company = $t['franchise']->company->name;
        $by = $this->board($t)['counts']['by_branch'];
        $this->assertSame([2, 1], [$by[$company.' / '.$t['franchise']->name], $by[$company.' / Boston']]);
    }

    public function test_the_board_needs_the_schedule_tab(): void
    {
        $t = $this->provisionTenant('Alpha');
        $caller = $this->userWithRole($t, 'Caller', ['scheduling.view', 'scheduling.availability.view']);
        $this->signedInAs($caller)->getJson('/api/v1/scheduling/board?date=2026-09-25')->assertForbidden();
    }
}
