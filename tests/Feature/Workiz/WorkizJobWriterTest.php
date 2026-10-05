<?php

namespace Tests\Feature\Workiz;

use App\Console\Commands\ProjectWorkizRecords;
use App\Models\Customer;
use App\Models\Job;
use App\Models\SocialConnection;
use App\Models\WorkizClient;
use App\Models\WorkizJob;
use App\Models\WorkizTeamMember;
use App\Support\Social\SocialProvider;
use App\Support\Workiz\WorkizJobWriter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
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

    // ── create(): a job EzBizi booked, made in Workiz too ─────────────

    /** Workiz's answer to a create, captured live on 2026-10-05 (NY test client 1196, job Y7TCXI). */
    private function created(string $clientId): array
    {
        return ['flag' => true, 'msg' => 'Created Job', 'data' => [[
            'UUID' => 'Y7TCXI', 'SerialId' => '900018617', 'JobName' => '', 'ClientId' => $clientId, 'link' => 'https://api.workiz.com/root/job/Y7TCXI/',
        ]], 'code' => 201];
    }

    /** A member's visit as BookJob books it, with the Workiz client numbers the member holds in the branch. */
    private function booked(array $tenant, array $keys): Job
    {
        return $this->inTenant($tenant['org'], function () use ($tenant, $keys) {
            $branch = $tenant['franchise']->id;
            $customer = Customer::create([
                'owning_franchise_id' => $branch, 'number' => 'C-1', 'name' => 'Mary Ann Rivera', 'is_active' => true,
                'phone' => '+13055550142', 'email' => 'mary@example.com', 'address_line1' => '12 Main St', 'address_line2' => 'Apt 4',
                'city' => 'Brooklyn', 'state' => 'NY', 'postal_code' => '11201',
            ]);

            foreach ($keys as $key) {
                WorkizClient::create(['franchise_id' => $branch, 'client_id' => $key, 'customer_id' => $customer->id, 'synced_at' => now()]);
            }

            return Job::create([
                'franchise_id' => $branch, 'customer_id' => $customer->id, 'number' => 'J-1', 'title' => 'Membership visit',
                'status' => 'Submitted', 'scheduled_for' => '2026-11-10 09:00:00', 'duration_minutes' => 90, 'currency' => 'USD',
            ]);
        });
    }

    private function counts(array $tenant): array
    {
        return $this->inTenant($tenant['org'], fn () => ['jobs' => Job::query()->count(), 'customers' => Customer::query()->count()]);
    }

    public function test_a_booked_visit_is_made_in_workiz_and_linked_before_the_projection_can_see_it(): void
    {
        [$tenant] = $this->world();
        $visit = $this->booked($tenant, keys: [1100, 1196]);
        $lockFree = null;

        Http::fake(['api.workiz.com/api/v1/api_NewYork/job/create/' => function () use (&$lockFree) {
            $lockFree = Cache::lock(ProjectWorkizRecords::LOCK, 1)->get();

            return Http::response($this->created('1196'));
        }]);

        $result = $this->inTenant($tenant['org'], fn () => $this->writer()->create($visit, 'Chimney Inspection', 'Recurring Client'));

        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/job/create/')
            && $r['auth_secret'] === 'sec_NewYork'
            && $r['ClientId'] === 1196
            && $r['FirstName'] === 'Mary Ann' && $r['LastName'] === 'Rivera'
            && $r['Phone'] === '3055550142'
            && $r['Email'] === 'mary@example.com'
            && $r['Address'] === '12 Main St' && $r['Unit'] === 'Apt 4'
            && $r['City'] === 'Brooklyn' && $r['State'] === 'NY' && $r['PostalCode'] === '11201'
            && $r['JobDateTime'] === '2026-11-10 09:00:00' && $r['JobEndDateTime'] === '2026-11-10 10:30:00'
            && $r['JobType'] === 'Chimney Inspection' && $r['JobSource'] === 'Recurring Client'
            && ! isset($r['Comments']));

        $this->assertFalse($lockFree, 'the projection is held off while the job and its link are made');
        $this->assertNull($result['refused']);
        $this->assertSame('Y7TCXI', $result['job']->remote_id);
        $this->assertSame($visit->id, $result['job']->job_id);
        $this->assertSame(900018617, (int) $result['job']->serial_id);
        $this->assertSame('Submitted', $result['job']->projected['status'], 'it follows Workiz from here, like any Workiz job');
        $this->assertSame(1, DB::table('audit_events')->where('action', 'workiz.job_created')->count());

        $before = $this->counts($tenant);
        $this->artisan('ezbizi:project-workiz')->assertSuccessful();
        $this->assertSame($before, $this->counts($tenant), 'the visit is not projected a second time');

        // Twice is once: a retried call finds the job it already made.
        $again = $this->inTenant($tenant['org'], fn () => $this->writer()->create($visit, 'Chimney Inspection', 'Recurring Client'));
        $this->assertSame($result['job']->id, $again['job']->id);
        Http::assertSentCount(1);
    }

    /** A member Workiz does not know yet: the client Workiz makes is theirs, so the projection makes no second customer. */
    public function test_the_client_workiz_makes_is_claimed_for_the_member(): void
    {
        [$tenant] = $this->world();
        $visit = $this->booked($tenant, keys: []);

        Http::fake(['api.workiz.com/api/v1/api_NewYork/job/create/' => Http::response($this->created('2719'))]);

        $this->inTenant($tenant['org'], fn () => $this->writer()->create($visit, null));

        Http::assertSent(fn (Request $r) => ! array_key_exists('ClientId', $r->data()) && ! array_key_exists('JobType', $r->data()) && ! array_key_exists('JobSource', $r->data()));
        $this->assertSame($visit->customer_id, $this->inTenant($tenant['org'], fn () => WorkizClient::query()->where('client_id', 2719)->value('customer_id')));

        $before = $this->counts($tenant);
        $this->artisan('ezbizi:project-workiz')->assertSuccessful();
        $this->assertSame($before, $this->counts($tenant));
    }

    /** Workiz may file the job under a client EzBizi knows as someone else: that key stays theirs, and the visit stays the member's. */
    public function test_a_client_number_held_by_another_customer_is_not_taken(): void
    {
        [$tenant] = $this->world();
        $visit = $this->booked($tenant, keys: []);
        $other = $this->inTenant($tenant['org'], function () use ($tenant) {
            $other = Customer::create(['owning_franchise_id' => $tenant['franchise']->id, 'number' => 'C-2', 'name' => 'Dana Booker', 'is_active' => true]);
            WorkizClient::create(['franchise_id' => $tenant['franchise']->id, 'client_id' => 2719, 'customer_id' => $other->id, 'synced_at' => now()]);

            return $other;
        });

        Http::fake(['api.workiz.com/*' => Http::response($this->created('2719'))]);

        $result = $this->inTenant($tenant['org'], fn () => $this->writer()->create($visit, null));

        $this->assertSame($visit->id, $result['job']->job_id);
        $this->assertSame($other->id, $this->inTenant($tenant['org'], fn () => WorkizClient::query()->where('client_id', 2719)->value('customer_id')));
        $this->assertSame($visit->customer_id, $this->inTenant($tenant['org'], fn () => $visit->fresh()->customer_id));
    }

    /**
     * A create Workiz accepted without naming the job cannot be linked; dispatch
     * is told not to type it in again. Not a shape Workiz has sent -- the one
     * answer here that was not captured -- but A1 Tools' guard for the same case.
     */
    public function test_an_answer_naming_no_job_is_not_called_a_success(): void
    {
        [$tenant] = $this->world();
        $visit = $this->booked($tenant, keys: [1196]);

        Http::fake(['api.workiz.com/*' => Http::response(['flag' => true, 'msg' => 'Created Job', 'code' => 201, 'data' => [['SerialId' => '800019848', 'ClientId' => '1196']]])]);

        $result = $this->inTenant($tenant['org'], fn () => $this->writer()->create($visit, null));

        $this->assertNull($result['job']);
        $this->assertStringContainsString('did not say which one', (string) $result['refused']);
    }

    /** Workiz's own sentence, field names included, and nothing linked. Captured live 2026-10-05: "Membership" is in no branch's source list. */
    public function test_a_refused_create_comes_back_with_the_fields_workiz_named(): void
    {
        [$tenant] = $this->world();
        $visit = $this->booked($tenant, keys: [1196]);

        Http::fake(['api.workiz.com/*' => Http::response(['error' => true, 'code' => 400, 'msg' => 'Error Validating Fields', 'details' => ['JobSource' => 'Could not find matching value for  JobSource - Membership']], 400)]);

        $result = $this->inTenant($tenant['org'], fn () => $this->writer()->create($visit, 'Chimney Inspection', 'Membership'));

        $this->assertSame(['job' => null, 'refused' => 'Error Validating Fields: JobSource: Could not find matching value for  JobSource - Membership'], $result);
        $this->assertNull($this->inTenant($tenant['org'], fn () => WorkizJob::query()->where('job_id', $visit->id)->first()));
        $this->assertSame(0, DB::table('audit_events')->where('action', 'workiz.job_created')->count());
    }

    /** A lost answer is not "nothing happened": Workiz may have made the job, so dispatch must look before typing it in. */
    public function test_no_answer_says_the_job_may_exist(): void
    {
        [$tenant] = $this->world();
        $visit = $this->booked($tenant, keys: [1196]);

        Http::fake(fn () => throw new ConnectionException('cURL error 28: Operation timed out'));

        $result = $this->inTenant($tenant['org'], fn () => $this->writer()->create($visit, 'Chimney Inspection'));

        $this->assertStringContainsString('look for it in Workiz before entering it', (string) $result['refused']);
    }

    /**
     * The projection holds the lock: nothing is sent, and the refusal is the one
     * sentence a caller may retry on. Answered well inside the queue worker's
     * default 60 seconds, so a queued caller is never killed waiting
     * (production runs queue:work with no --timeout and --tries=1).
     */
    public function test_a_busy_sync_refuses_in_time_and_sends_nothing(): void
    {
        [$tenant] = $this->world();
        $visit = $this->booked($tenant, keys: [1196]);
        Http::fake();
        $held = Cache::lock(ProjectWorkizRecords::LOCK, 120);
        $held->get();

        $started = microtime(true);
        $result = $this->inTenant($tenant['org'], fn () => $this->writer()->create($visit, 'Chimney Inspection'));

        $this->assertSame(['job' => null, 'refused' => WorkizJobWriter::BUSY], $result);
        $this->assertLessThan(30, microtime(true) - $started, "inside the worker's 60 seconds, with Workiz's own 30 to spare");
        Http::assertNothingSent();
        $held->release();
    }

    public function test_a_branch_without_workiz_has_nothing_to_enter(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $visit = $this->booked($tenant, keys: []);

        Http::fake();

        $result = $this->inTenant($tenant['org'], fn () => $this->writer()->create($visit, 'Chimney Inspection'));

        $this->assertSame(['job' => null, 'refused' => null], $result);
        Http::assertNothingSent();
    }
}
