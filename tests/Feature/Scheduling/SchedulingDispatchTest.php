<?php

namespace Tests\Feature\Scheduling;

use App\Models\Customer;
use App\Models\Job;
use App\Models\JobTechnician;
use App\Models\SchedulingTechnician;
use App\Models\SocialConnection;
use App\Models\WorkizJob;
use App\Models\WorkizTeamMember;
use App\Support\Social\SocialProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/**
 * Dispatching from the Schedule board. EzBizi is the master (Jose,
 * 2026-10-27): every change lands in EzBizi first; a job with a Workiz twin is
 * then mirrored there, and Workiz refusing never undoes EzBizi. The Workiz
 * answers faked here are the ones captured live on test job 99QWJ0.
 */
class SchedulingDispatchTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    private const ASSIGNED = ['flag' => true, 'msg' => 'User assigned', 'data' => ['uuid' => '99QWJ0', 'job_id' => '900018563', 'link' => 'https://api.workiz.com/root/job/99QWJ0/'], 'code' => 200];

    private const UPDATED = ['flag' => true, 'msg' => 'Job updated', 'data' => [['UUID' => '99QWJ0', 'JobName' => 'EzBizi API TEST', 'ClientId' => '1196', 'link' => 'https://api.workiz.com/root/job/99QWJ0/']], 'code' => 200];

    private const NOT_IN_AREA = ['error' => true, 'code' => 400, 'msg' => 'Validation rule exception', 'details' => ['error' => 'Cannot assign Chaim Perez to job 99QWJ0, User is not assigned to select service area.']];

    /**
     * A job and a technician in EzBizi; with $mirrored, each has a Workiz twin
     * on a connected account.
     *
     * @return array{0: array, 1: Job, 2: SchedulingTechnician}
     */
    private function world(bool $mirrored = false, ?int $minutes = 120): array
    {
        $tenant = $this->provisionTenant('Alpha');
        $branch = $tenant['franchise'];

        [$job, $tech] = $this->inTenant($tenant['org'], function () use ($tenant, $branch, $mirrored, $minutes) {
            $job = Job::factory()->create(['franchise_id' => $branch->id, 'customer_id' => Customer::factory()->create(['owning_franchise_id' => $branch->id])->id, 'status' => 'Submitted', 'scheduled_for' => '2026-10-30 10:00:00', 'duration_minutes' => $minutes]);
            $tech = SchedulingTechnician::create(['franchise_id' => $branch->id, 'name' => 'Chaim Perez']);
            if (! $mirrored) {
                return [$job, $tech];
            }
            $c = new SocialConnection;
            $c->forceFill([
                'organization_id' => $tenant['org']->id, 'scope_type' => SocialConnection::SCOPE_FRANCHISE, 'scope_id' => $branch->id,
                'provider' => SocialProvider::Workiz->value, 'remote_id' => $branch->id, 'display_name' => 'New York',
            ]);
            $c->connect(['api_token' => 'api_NewYork', 'refresh_token' => 'api_NewYork', 'auth_secret' => 'sec_NewYork']);
            $c->save();
            WorkizJob::create([
                'franchise_id' => $branch->id, 'connection_id' => $c->id, 'remote_id' => '99QWJ0', 'job_id' => $job->id,
                'status' => 'Submitted', 'service_area' => 'Seattle', 'scheduled_for' => '2026-10-30 10:00:00',
                'scheduled_end' => '2026-10-30 12:00:00', 'payload' => $this->payload(), 'synced_at' => now()->subHour(),
            ]);
            $member = WorkizTeamMember::create([
                'franchise_id' => $branch->id, 'remote_id' => '436274', 'name' => 'Chaim Perez', 'is_active' => true,
                'is_field_tech' => true, 'service_areas' => ['Seattle'], 'payload' => [], 'synced_at' => now(),
            ]);
            $tech->update(['workiz_member_id' => $member->id]);

            return [$job, $tech];
        });

        return [$tenant, $job, $tech];
    }

    private function payload(array $overrides = []): array
    {
        return array_merge(require base_path('tests/Fixtures/workiz/job_payload.php'), [
            'UUID' => '99QWJ0', 'Status' => 'Submitted', 'ServiceArea' => 'Seattle',
            'JobDateTime' => '2026-10-30 10:00:00', 'JobEndDateTime' => '2026-10-30 12:00:00', 'Team' => [],
        ], $overrides);
    }

    private function reread(array $overrides = []): array
    {
        return ['api.workiz.com/api/v1/api_NewYork/job/get/99QWJ0/' => Http::response(['flag' => true, 'data' => [$this->payload($overrides)]])];
    }

    /** No Workiz at all: EzBizi assigns, moves and unassigns on its own. */
    public function test_without_workiz_dispatch_writes_ezbizi_and_sends_nothing(): void
    {
        [$tenant, $job, $tech] = $this->world();
        Http::fake();
        $as = $this->signedInAs($tenant['admin']);

        $as->postJson("/api/v1/scheduling/board/jobs/{$job->id}/assign", ['member_id' => $tech->id])->assertOk()->assertJsonPath('data.id', $job->id);
        $this->assertSame([$tech->id], $this->inTenant($tenant['org'], fn () => JobTechnician::query()->where('job_id', $job->id)->pluck('technician_id')->all()));

        $as->postJson("/api/v1/scheduling/board/jobs/{$job->id}/reschedule", ['date' => '2026-10-31', 'start_time' => '08:00', 'end_time' => '11:00'])->assertOk();
        $this->assertSame('2026-10-31 08:00:00', $this->inTenant($tenant['org'], fn () => $job->fresh()->getRawOriginal('scheduled_for')));

        $as->postJson("/api/v1/scheduling/board/jobs/{$job->id}/unassign", ['member_id' => $tech->id])->assertOk();
        $this->assertSame(0, $this->inTenant($tenant['org'], fn () => JobTechnician::query()->where('job_id', $job->id)->count()));
        Http::assertNothingSent();
    }

    /** With a Workiz twin, EzBizi changes first and Workiz is told after. */
    public function test_a_mirrored_job_is_assigned_in_ezbizi_then_sent_to_workiz(): void
    {
        [$tenant, $job, $tech] = $this->world(mirrored: true);
        Http::fake(['api.workiz.com/api/v1/api_NewYork/job/assign/' => Http::response(self::ASSIGNED)]
            + $this->reread(['Team' => [['id' => 436274, 'Name' => 'Chaim Perez']]]));

        $this->signedInAs($tenant['admin'])->postJson("/api/v1/scheduling/board/jobs/{$job->id}/assign", ['member_id' => $tech->id])->assertOk();

        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/job/assign/') && $r['User'] === 'Chaim Perez');
    }

    /** Workiz refusing does not undo EzBizi's assignment. */
    public function test_a_workiz_refusal_leaves_the_ezbizi_assignment(): void
    {
        [$tenant, $job, $tech] = $this->world(mirrored: true);
        Http::fake(['api.workiz.com/api/v1/api_NewYork/job/assign/' => Http::response(self::NOT_IN_AREA, 400)]);

        $this->signedInAs($tenant['admin'])->postJson("/api/v1/scheduling/board/jobs/{$job->id}/assign", ['member_id' => $tech->id])->assertOk();
        $this->assertSame(1, $this->inTenant($tenant['org'], fn () => JobTechnician::query()->where('job_id', $job->id)->count()));
    }

    /** A move keeps the job's own length, in EzBizi and in what Workiz is sent. */
    public function test_a_move_keeps_the_jobs_length(): void
    {
        [$tenant, $job] = $this->world(mirrored: true);
        Http::fake(['api.workiz.com/api/v1/api_NewYork/job/update/' => Http::response(self::UPDATED)] + $this->reread());

        $this->signedInAs($tenant['admin'])->postJson("/api/v1/scheduling/board/jobs/{$job->id}/reschedule", [
            'date' => '2026-10-31', 'start_time' => '08:00', 'end_time' => '11:00',
        ])->assertOk();

        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/job/update/')
            && $r['JobDateTime'] === '2026-10-31 08:00:00' && $r['JobEndDateTime'] === '2026-10-31 10:00:00');
    }

    /** With no length on record the job takes the window's. */
    public function test_a_job_with_no_length_takes_the_window(): void
    {
        [$tenant, $job] = $this->world(minutes: null);
        $this->signedInAs($tenant['admin'])->postJson("/api/v1/scheduling/board/jobs/{$job->id}/reschedule", [
            'date' => '2026-10-31', 'start_time' => '11:00', 'end_time' => '14:00',
        ])->assertOk();
        $this->assertSame(180, $this->inTenant($tenant['org'], fn () => $job->fresh()->duration_minutes));
    }

    /** Seeing the board is not changing it: dispatch needs Scheduling's edit. */
    public function test_the_schedule_tab_alone_cannot_dispatch_and_the_board_says_so(): void
    {
        [$tenant, $job, $tech] = $this->world();
        $viewer = $this->userWithRole($tenant, 'Viewer', ['scheduling.view', 'scheduling.schedule.view']);

        $this->signedInAs($viewer)->postJson("/api/v1/scheduling/board/jobs/{$job->id}/assign", ['member_id' => $tech->id])->assertForbidden();
        $this->signedInAs($viewer)->getJson('/api/v1/scheduling/board?date=2026-10-30')->assertOk()->assertJsonPath('data.can_dispatch', false);
    }

    /** A technician from another branch is not found. */
    public function test_a_technician_from_another_branch_is_not_found(): void
    {
        [$tenant, $job] = $this->world();
        $texas = $this->extraFranchise($tenant, 'Texas');
        $other = $this->inTenant($tenant['org'], fn () => SchedulingTechnician::create(['franchise_id' => $texas->id, 'name' => 'Elsewhere']));

        $this->signedInAs($tenant['admin'])->postJson("/api/v1/scheduling/board/jobs/{$job->id}/assign", ['member_id' => $other->id])->assertNotFound();
    }
}
