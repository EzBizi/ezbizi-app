<?php

namespace Tests\Feature\Workiz;

use App\Models\SocialConnection;
use App\Models\WorkizJob;
use App\Models\WorkizTeamMember;
use App\Support\Social\SocialProvider;
use App\Support\Workiz\WorkizJobWriter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/**
 * Changing a Workiz job from EzBizi. Every Workiz answer faked here is one
 * captured live on 2026-09-25 from the test job 99QWJ0 ("EzBizi API TEST",
 * NY account), made for this with Jose's approval.
 */
class WorkizJobWriterTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    private const UPDATED = ['flag' => true, 'msg' => 'Job updated', 'data' => [['UUID' => '99QWJ0', 'JobName' => 'EzBizi API TEST', 'ClientId' => '1196', 'link' => 'https://api.workiz.com/root/job/99QWJ0/']], 'code' => 200];

    private const ASSIGNED = ['flag' => true, 'msg' => 'User assigned', 'data' => ['uuid' => '99QWJ0', 'job_id' => '900018563', 'link' => 'https://api.workiz.com/root/job/99QWJ0/'], 'code' => 200];

    private const NOT_IN_AREA = ['error' => true, 'code' => 400, 'msg' => 'Validation rule exception', 'details' => ['error' => 'Cannot assign Chaim Perez to job 99QWJ0, User is not assigned to select service area.']];

    /** @return array{0: array, 1: WorkizJob, 2: WorkizTeamMember} */
    private function world(bool $writeKey = true): array
    {
        $tenant = $this->provisionTenant('Alpha');
        $branch = $tenant['franchise'];

        [$job, $member] = $this->inTenant($tenant['org'], function () use ($tenant, $branch, $writeKey) {
            $c = new SocialConnection;
            $c->forceFill([
                'organization_id' => $tenant['org']->id,
                'scope_type' => SocialConnection::SCOPE_FRANCHISE,
                'scope_id' => $branch->id,
                'provider' => SocialProvider::Workiz->value,
                'remote_id' => $branch->id,
                'display_name' => 'New York',
            ]);
            $c->connect(['api_token' => 'api_NewYork', 'refresh_token' => 'api_NewYork'] + ($writeKey ? ['auth_secret' => 'sec_NewYork'] : []));
            $c->save();

            $job = WorkizJob::create([
                'franchise_id' => $branch->id,
                'connection_id' => $c->id,
                'remote_id' => '99QWJ0',
                'status' => 'Submitted',
                'service_area' => 'Seattle',
                'scheduled_for' => '2026-10-30 11:45:00',
                'payload' => $this->payload(),
                'synced_at' => now()->subHour(),
            ]);

            $member = WorkizTeamMember::create([
                'franchise_id' => $branch->id,
                'remote_id' => '436274',
                'name' => 'Chaim Perez',
                'is_active' => true,
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
            'JobDateTime' => '2026-10-30 11:45:00', 'JobEndDateTime' => '2026-10-30 11:45:00', 'Team' => [],
        ], $overrides);
    }

    private function writer(): WorkizJobWriter
    {
        return app(WorkizJobWriter::class);
    }

    public function test_a_reschedule_sends_start_and_end_and_returns_workizs_own_answer(): void
    {
        [$tenant, $job] = $this->world();

        Http::fake([
            'api.workiz.com/api/v1/api_NewYork/job/update/' => Http::response(self::UPDATED),
            'api.workiz.com/api/v1/api_NewYork/job/get/99QWJ0/' => Http::response(['flag' => true, 'data' => [
                $this->payload(['JobDateTime' => '2026-10-30 07:00:00', 'JobEndDateTime' => '2026-10-30 08:00:00']),
            ]]),
        ]);

        $result = $this->inTenant($tenant['org'], fn () => $this->writer()->reschedule($job, '2026-10-30 07:00:00', '2026-10-30 08:00:00'));

        // Start AND end: measured, Workiz moves only the start when sent alone.
        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/job/update/')
            && $r->isJson()
            && $r['auth_secret'] === 'sec_NewYork'
            && $r['UUID'] === '99QWJ0'
            && $r['JobDateTime'] === '2026-10-30 07:00:00'
            && $r['JobEndDateTime'] === '2026-10-30 08:00:00');

        $this->assertNull($result['refused']);
        $this->assertSame('2026-10-30 07:00:00', $result['job']->scheduled_for->format('Y-m-d H:i:s'), 'the mirror holds Workiz\'s answer at once');
        $this->assertSame(1, DB::table('audit_events')->where('action', 'workiz.job_rescheduled')->count());
    }

    /** Workiz takes the technician's NAME, never the id (A1 Tools probed both). */
    public function test_assigning_sends_the_technicians_workiz_name(): void
    {
        [$tenant, $job, $member] = $this->world();

        Http::fake([
            'api.workiz.com/api/v1/api_NewYork/job/assign/' => Http::response(self::ASSIGNED),
            'api.workiz.com/api/v1/api_NewYork/job/get/99QWJ0/' => Http::response(['flag' => true, 'data' => [
                $this->payload(['Team' => [['id' => 436274, 'Name' => 'Chaim Perez']]]),
            ]]),
        ]);

        $result = $this->inTenant($tenant['org'], fn () => $this->writer()->assign($job, $member));

        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/job/assign/') && $r['User'] === 'Chaim Perez' && $r['UUID'] === '99QWJ0');
        $this->assertSame('Chaim Perez', $result['job']->payload['Team'][0]['Name']);
        $this->assertSame(1, DB::table('audit_events')->where('action', 'workiz.job_assigned')->count());
    }

    /**
     * Workiz's refusal is handed back as its own sentence -- it is what a
     * dispatcher can act on -- and nothing is recorded or re-read.
     */
    public function test_a_workiz_refusal_comes_back_verbatim_and_nothing_is_recorded(): void
    {
        [$tenant, $job, $member] = $this->world();

        Http::fake(['api.workiz.com/*' => Http::response(self::NOT_IN_AREA, 400)]);

        $result = $this->inTenant($tenant['org'], fn () => $this->writer()->assign($job, $member));

        $this->assertNull($result['job']);
        $this->assertSame('Cannot assign Chaim Perez to job 99QWJ0, User is not assigned to select service area.', $result['refused']);
        Http::assertSentCount(1);
        $this->assertSame(0, DB::table('audit_events')->where('action', 'like', 'workiz.job_%')->count());
    }

    public function test_a_branch_without_a_write_key_is_refused_without_asking_workiz(): void
    {
        [$tenant, $job, $member] = $this->world(writeKey: false);

        Http::fake();

        $result = $this->inTenant($tenant['org'], fn () => $this->writer()->assign($job, $member));

        $this->assertNotNull($result['refused']);
        Http::assertNothingSent();
    }

    /** One branch's technician cannot be sent to another branch's Workiz account. */
    public function test_a_technician_from_another_branch_is_refused_without_asking_workiz(): void
    {
        [$tenant, $job, $member] = $this->world();
        $other = $this->extraFranchise($tenant, 'Connecticut');
        $this->inTenant($tenant['org'], fn () => $member->forceFill(['franchise_id' => $other->id])->save());

        Http::fake();

        $result = $this->inTenant($tenant['org'], fn () => $this->writer()->assign($job, $member));

        $this->assertNotNull($result['refused']);
        Http::assertNothingSent();
    }

    /**
     * Workiz holds naive wall-clock times in the account's own zone. A Carbon
     * or an ISO stamp could arrive shifted by hours, so only that shape passes.
     */
    public function test_times_must_be_workizs_own_wall_clock(): void
    {
        [$tenant, $job] = $this->world();
        Http::fake();

        $this->expectException(InvalidArgumentException::class);

        $this->inTenant($tenant['org'], fn () => $this->writer()->reschedule($job, '2026-10-30T07:00:00Z', '2026-10-30 08:00:00'));
    }
}
