<?php

namespace Tests\Feature\Scheduling;

use App\Models\Franchise;
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
 * Dispatching from the Schedule board, through the Workiz module's writer. The
 * Workiz answers faked here are the ones the Workiz session captured live on
 * the test job 99QWJ0 (2026-09-25); the writer's own rules are its tests, and
 * these are the board's: who may, which job, which technician, what length.
 */
class SchedulingDispatchTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    private const ASSIGNED = ['flag' => true, 'msg' => 'User assigned', 'data' => ['uuid' => '99QWJ0', 'job_id' => '900018563', 'link' => 'https://api.workiz.com/root/job/99QWJ0/'], 'code' => 200];

    private const UPDATED = ['flag' => true, 'msg' => 'Job updated', 'data' => [['UUID' => '99QWJ0', 'JobName' => 'EzBizi API TEST', 'ClientId' => '1196', 'link' => 'https://api.workiz.com/root/job/99QWJ0/']], 'code' => 200];

    private const NOT_IN_AREA = ['error' => true, 'code' => 400, 'msg' => 'Validation rule exception', 'details' => ['error' => 'Cannot assign Chaim Perez to job 99QWJ0, User is not assigned to select service area.']];

    /** @return array{0: array, 1: WorkizJob, 2: WorkizTeamMember} */
    private function world(?string $end = '2026-10-30 12:00:00'): array
    {
        $tenant = $this->provisionTenant('Alpha');
        $branch = $tenant['franchise'];

        [$job, $member] = $this->inTenant($tenant['org'], function () use ($tenant, $branch, $end) {
            $c = new SocialConnection;
            $c->forceFill([
                'organization_id' => $tenant['org']->id,
                'scope_type' => SocialConnection::SCOPE_FRANCHISE,
                'scope_id' => $branch->id,
                'provider' => SocialProvider::Workiz->value,
                'remote_id' => $branch->id,
                'display_name' => 'New York',
            ]);
            $c->connect(['api_token' => 'api_NewYork', 'refresh_token' => 'api_NewYork', 'auth_secret' => 'sec_NewYork']);
            $c->save();

            $job = WorkizJob::create([
                'franchise_id' => $branch->id,
                'connection_id' => $c->id,
                'remote_id' => '99QWJ0',
                'status' => 'Submitted',
                'service_area' => 'Seattle',
                'scheduled_for' => '2026-10-30 10:00:00',
                'scheduled_end' => $end,
                'payload' => $this->payload(),
                'synced_at' => now()->subHour(),
            ]);

            $member = WorkizTeamMember::create([
                'franchise_id' => $branch->id,
                'remote_id' => '436274',
                'name' => 'Chaim Perez',
                'is_active' => true,
                'is_field_tech' => true,
                'service_areas' => ['Portland', 'Seattle'],
                'payload' => [],
                'synced_at' => now(),
            ]);

            return [$job, $member];
        });

        return [$tenant, $job, $member];
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

    public function test_assigning_from_the_board_sends_the_technician_to_workiz(): void
    {
        [$tenant, $job, $member] = $this->world();

        Http::fake(['api.workiz.com/api/v1/api_NewYork/job/assign/' => Http::response(self::ASSIGNED)]
            + $this->reread(['Team' => [['id' => 436274, 'Name' => 'Chaim Perez']]]));

        $this->signedInAs($tenant['admin'])
            ->postJson("/api/v1/scheduling/board/jobs/{$job->id}/assign", ['member_id' => $member->id])
            ->assertOk()
            ->assertJsonPath('data.id', $job->id);

        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/job/assign/') && $r['User'] === 'Chaim Perez');
    }

    /** Workiz's refusal reaches the dispatcher in Workiz's words. */
    public function test_a_workiz_refusal_comes_back_as_its_own_sentence(): void
    {
        [$tenant, $job, $member] = $this->world();

        Http::fake(['api.workiz.com/api/v1/api_NewYork/job/assign/' => Http::response(self::NOT_IN_AREA, 400)]);

        $this->signedInAs($tenant['admin'])
            ->postJson("/api/v1/scheduling/board/jobs/{$job->id}/assign", ['member_id' => $member->id])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Cannot assign Chaim Perez to job 99QWJ0, User is not assigned to select service area.');
    }

    /**
     * A move keeps the job's own length: a two-hour job dropped into 8–11
     * runs 8–10, not 8–11. Workiz moves only the start unless given the end.
     */
    public function test_a_move_keeps_the_jobs_length(): void
    {
        [$tenant, $job] = $this->world();

        Http::fake(['api.workiz.com/api/v1/api_NewYork/job/update/' => Http::response(self::UPDATED)] + $this->reread());

        $this->signedInAs($tenant['admin'])
            ->postJson("/api/v1/scheduling/board/jobs/{$job->id}/reschedule", [
                'date' => '2026-10-31', 'start_time' => '08:00', 'end_time' => '11:00',
            ])->assertOk();

        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/job/update/')
            && $r['JobDateTime'] === '2026-10-31 08:00:00'
            && $r['JobEndDateTime'] === '2026-10-31 10:00:00');
    }

    /** With no length on record the job ends with the window. */
    public function test_a_job_with_no_end_ends_with_the_window(): void
    {
        [$tenant, $job] = $this->world(end: null);

        Http::fake(['api.workiz.com/api/v1/api_NewYork/job/update/' => Http::response(self::UPDATED)] + $this->reread());

        $this->signedInAs($tenant['admin'])
            ->postJson("/api/v1/scheduling/board/jobs/{$job->id}/reschedule", [
                'date' => '2026-10-31', 'start_time' => '11:00', 'end_time' => '14:00',
            ])->assertOk();

        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/job/update/')
            && $r['JobDateTime'] === '2026-10-31 11:00:00'
            && $r['JobEndDateTime'] === '2026-10-31 14:00:00');
    }

    /** Seeing the board is not changing it: dispatch needs Scheduling's edit. */
    public function test_the_schedule_tab_alone_cannot_dispatch_and_the_board_says_so(): void
    {
        [$tenant, $job, $member] = $this->world();
        Http::fake();

        $viewer = $this->userWithRole($tenant, 'Viewer', ['scheduling.view', 'scheduling.schedule.view']);

        $this->signedInAs($viewer)
            ->postJson("/api/v1/scheduling/board/jobs/{$job->id}/assign", ['member_id' => $member->id])
            ->assertForbidden();
        Http::assertNothingSent();

        $this->signedInAs($viewer)
            ->getJson('/api/v1/scheduling/board?date=2026-10-30')
            ->assertOk()
            ->assertJsonPath('data.can_dispatch', false);

        $dispatcher = $this->userWithRole($tenant, 'Dispatcher', ['scheduling.view', 'scheduling.schedule.view', 'scheduling.edit']);

        $this->signedInAs($dispatcher)
            ->getJson('/api/v1/scheduling/board?date=2026-10-30')
            ->assertJsonPath('data.can_dispatch', true)
            ->assertJsonPath('data.unassigned.0.job_id', $job->id)
            ->assertJsonPath('data.technicians.0.member_id', $member->id)
            ->assertJsonPath('data.technicians.0.service_areas', ['Portland', 'Seattle']);
    }

    /** A technician from another branch's account is not found, and Workiz is never asked. */
    public function test_a_technician_from_another_branch_is_not_found(): void
    {
        [$tenant, $job] = $this->world();
        Http::fake();

        $other = $this->inTenant($tenant['org'], function () use ($tenant) {
            $branch = Franchise::create([
                'organization_id' => $tenant['org']->id,
                'company_id' => $tenant['company']->id,
                'name' => 'Texas',
            ]);

            return WorkizTeamMember::create([
                'franchise_id' => $branch->id,
                'remote_id' => '1',
                'name' => 'Somebody Else',
                'is_active' => true,
                'payload' => [],
                'synced_at' => now(),
            ]);
        });

        $this->signedInAs($tenant['admin'])
            ->postJson("/api/v1/scheduling/board/jobs/{$job->id}/assign", ['member_id' => $other->id])
            ->assertNotFound();
        Http::assertNothingSent();
    }
}
