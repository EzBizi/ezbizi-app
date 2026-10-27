<?php

namespace Tests\Feature\Scheduling;

use App\Models\Customer;
use App\Models\Job;
use App\Models\JobTechnician;
use App\Models\SchedulingTechnician;
use App\Models\ServiceArea;
use App\Models\WorkizJob;
use App\Models\WorkizTeamMember;
use App\Support\Scheduling\JobTechnicians;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/**
 * The native roster is seeded once from Workiz and EzBizi owns it after; an
 * import never overwrites a job a person assigned (Jose, 2026-10-27).
 */
class SeedTechniciansTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    public function test_the_roster_and_job_teams_are_seeded_and_a_person_s_assignment_survives_imports(): void
    {
        $t = $this->provisionTenant('Alpha');
        app(TenantContext::class)->set($t['org']);
        $fr = $t['franchise']->id;
        $area = ServiceArea::create(['franchise_id' => $fr, 'name' => 'Seattle']);
        $chaim = WorkizTeamMember::create(['franchise_id' => $fr, 'remote_id' => 'USR-1', 'name' => 'Chaim Perez', 'is_active' => true, 'is_field_tech' => true, 'service_areas' => ['seattle '], 'payload' => [], 'synced_at' => now()]);
        WorkizTeamMember::create(['franchise_id' => $fr, 'remote_id' => '436274', 'name' => 'Chaim Perez', 'is_active' => false, 'is_field_tech' => true, 'payload' => ['succeeded_by' => 'USR-1'], 'synced_at' => now()]);
        $job = Job::factory()->create(['franchise_id' => $fr, 'customer_id' => Customer::factory()->create(['owning_franchise_id' => $fr])->id]);
        WorkizJob::create(['franchise_id' => $fr, 'remote_id' => 'J1', 'job_id' => $job->id, 'status' => 'Submitted', 'payload' => ['Team' => [['id' => 436274, 'Name' => 'Chaim Perez']]], 'synced_at' => now()]);

        $this->artisan('scheduling:seed-technicians')->assertSuccessful();
        $this->artisan('scheduling:seed-technicians')->assertSuccessful();
        app(TenantContext::class)->set($t['org']);

        // One person, not two: the retired v1 row is its v2 successor.
        $tech = SchedulingTechnician::query()->sole();
        $this->assertSame([$chaim->id, [$area->id]], [$tech->workiz_member_id, $tech->areas->pluck('id')->all()]);
        $this->assertSame([[$tech->id, JobTechnician::WORKIZ]], JobTechnician::query()->get()->map(fn ($r) => [$r->technician_id, $r->source])->all());

        // A person's change is never replaced by the next import.
        $other = SchedulingTechnician::create(['franchise_id' => $fr, 'name' => 'Native Only']);
        JobTechnicians::assign($job, $other);
        $harry = WorkizTeamMember::create(['franchise_id' => $fr, 'remote_id' => 'USR-2', 'name' => 'Harry Jenkins', 'is_active' => true, 'is_field_tech' => true, 'payload' => [], 'synced_at' => now()]);
        SchedulingTechnician::create(['franchise_id' => $fr, 'name' => 'Harry Jenkins', 'workiz_member_id' => $harry->id]);
        JobTechnicians::fromWorkiz($job, [['id' => 'USR-2', 'Name' => 'Harry Jenkins']]);
        $this->assertEqualsCanonicalizing([$tech->id, $other->id], JobTechnician::query()->pluck('technician_id')->all());
    }
}
