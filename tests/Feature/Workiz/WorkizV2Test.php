<?php

namespace Tests\Feature\Workiz;

use App\Console\Commands\ProjectWorkizRecords;
use App\Models\Customer;
use App\Models\Job;
use App\Models\SocialConnection;
use App\Models\User;
use App\Models\WorkizClient;
use App\Models\WorkizJob;
use App\Models\WorkizTeamMember;
use App\Support\Reports\Experimental\WorkizJobs;
use App\Support\Social\SocialProvider;
use App\Support\Workiz\WorkizJobWriter;
use App\Support\Workiz\WorkizLeads;
use App\Support\Workiz\WorkizV2;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/**
 * Workiz v2 (Jose, 2026-10-05: every branch moves to its v2 key). Every v2
 * answer faked here has the shape measured live that day on Massachusetts'
 * key; tests/Fixtures/workiz/v2_job.php is one job of it.
 */
class WorkizV2Test extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    private function v2Job(array $overrides = []): array
    {
        return array_replace_recursive(require base_path('tests/Fixtures/workiz/v2_job.php'), $overrides);
    }

    /** @return array{0: array, 1: SocialConnection} */
    private function branch(string $version = 'v2'): array
    {
        $tenant = $this->provisionTenant('Alpha');

        $connection = $this->inTenant($tenant['org'], function () use ($tenant, $version) {
            $c = new SocialConnection;
            $c->forceFill([
                'organization_id' => $tenant['org']->id,
                'scope_type' => SocialConnection::SCOPE_FRANCHISE,
                'scope_id' => $tenant['franchise']->id,
                'provider' => SocialProvider::Workiz->value,
                'remote_id' => $tenant['franchise']->id,
                'display_name' => 'Massachusetts',
            ]);
            $c->connect($version === 'v2'
                ? ['api_token' => 'v2key_MA', 'refresh_token' => 'v2key_MA', 'api_version' => 'v2']
                : ['api_token' => 'api_MA', 'refresh_token' => 'api_MA', 'auth_secret' => 'sec_MA']);
            $c->save();

            return $c;
        });

        return [$tenant, $connection];
    }

    private function page(array $jobs, bool $more = false): array
    {
        return ['pageSize' => 100, 'page' => 1, 'totalResults' => count($jobs), 'hasMore' => $more, 'data' => $jobs];
    }

    /**
     * Custom fields come with every read; a job's files only with a single
     * read, never the list (measured 2026-10-06). So the list, which lands
     * every minute, must not empty the files a single read found.
     */
    public function test_custom_fields_land_and_a_list_read_keeps_the_files_a_single_read_found(): void
    {
        [$tenant, $connection] = $this->branch();
        $file = ['id' => 'FLE-ADybNZQonxAXWKn8', 'name' => 'IMG_2201', 'fileUrl' => 'https://st.sendajob.com/upload/IMG_2201'];

        $this->inTenant($tenant['org'], function () use ($connection, $file) {
            WorkizJob::land($connection, WorkizV2::job($this->v2Job(['attachments' => [$file]])));
            WorkizJob::land($connection, WorkizV2::job($this->v2Job()));

            $payload = WorkizJob::query()->sole()->payload;
            $this->assertSame([['Name' => 'System Type', 'Value' => 'Wood Burning']], $payload['CustomFields']);
            // assertEquals: MySQL's JSON keeps keys in its own order.
            $this->assertEquals([['id' => $file['id'], 'Name' => 'IMG_2201', 'Url' => $file['fileUrl']]], $payload['Attachments']);

            // A single read that finds none: the file was removed in Workiz.
            WorkizJob::land($connection, WorkizV2::job($this->v2Job(['attachments' => []])));
            $this->assertSame([], WorkizJob::query()->sole()->payload['Attachments']);
        });
    }

    public function test_a_v2_job_reads_as_the_v1_payload_every_reader_knows(): void
    {
        $p = WorkizV2::job($this->v2Job());

        $this->assertSame('NYCZ7W', $p['UUID']);
        $this->assertSame(11008, $p['SerialId']);
        $this->assertSame(1008, $p['ClientId'], "the client's serial is v1's ClientId");
        $this->assertSame('2026-10-07 08:00:00', $p['JobDateTime']);
        $this->assertSame('Chimney Sweep', $p['JobType']);
        $this->assertSame('LS Ads', $p['JobSource'], 'v2 calls the source an ad group');
        $this->assertSame('Boston South', $p['ServiceArea']);
        $this->assertSame(['02760', 'North Attleborough', 'Massachusetts'], [$p['PostalCode'], $p['City'], $p['State']]);
        $this->assertSame(['5085550142', 'Pat', 'Rivera'], [$p['Phone'], $p['FirstName'], $p['LastName']]);
        $this->assertSame([0, 0, 0], [$p['JobTotalPrice'], $p['JobAmountDue'], $p['SubTotal']], 'v2 null is v1 0');
        $this->assertSame([['id' => 'USR-ap8XB7Vm8PqogDWq', 'Name' => 'Chaim Perez']], $p['Team']);
        $this->assertSame(['Membership'], $p['Tags']);
        $this->assertSame('Chimney Sweep', $p['LineItems'][0]['Name']);
        $this->assertSame(189, $p['LineItems'][0]['Price']);
        $this->assertSame('JOB-8ApvxXebm23oQV4E', $p['v2']['id']);
        $this->assertSame('CL-xzA4NAKqzXW76XQp', $p['v2']['client_id']);
    }

    public function test_the_sweep_reads_a_v2_branch_through_v2(): void
    {
        [$tenant] = $this->branch();
        Http::fake(['api.workiz.com/crm/api/v2/jobs*' => Http::response($this->page([$this->v2Job()]))]);

        $this->artisan('ezbizi:sync-workiz', ['--reconcile' => 0, '--verify' => 0])->assertSuccessful();

        Http::assertSent(fn (Request $r) => str_starts_with($r->url(), WorkizV2::BASE.'/jobs')
            && $r->hasHeader('Authorization', 'Bearer v2key_MA')
            && $r['page'] == 1 && $r['pageSize'] == 100 && $r['dateProperty'] === 'date' && $r['dateOperator'] === 'next');

        $row = $this->inTenant($tenant['org'], fn () => WorkizJob::query()->sole());
        $this->assertSame('NYCZ7W', $row->remote_id);
        $this->assertSame(1008, (int) $row->client_id);
        $this->assertSame('JOB-8ApvxXebm23oQV4E', $row->payload['v2']['id']);
    }

    /** 11-10: a job booked today and dated weeks back (DMV 800020991) is found by its created date. */
    public function test_a_job_booked_today_with_a_past_date_is_read_by_when_it_was_made(): void
    {
        [$tenant] = $this->branch();
        Http::fake(['api.workiz.com/crm/api/v2/jobs*' => fn (Request $r) => Http::response($this->page($r['dateProperty'] === 'created'
            ? [$this->v2Job(['uuid' => 'T6TDTG', 'id' => 'JOB-BACKDATED', 'date' => '2026-10-01 11:00:00'])] : [$this->v2Job()]))]);

        $this->artisan('ezbizi:sync-workiz', ['--reconcile' => 0, '--verify' => 0])->assertSuccessful();

        $this->assertEqualsCanonicalizing(['NYCZ7W', 'T6TDTG'], $this->inTenant($tenant['org'], fn () => WorkizJob::query()->pluck('remote_id')->all()));
    }

    /**
     * Measured live on Connecticut: v2's address is the client's, not the
     * job's (0T933A: v1 Bolton CT, v2 Avon IN); a finished job Workiz marks
     * unscheduled has no date in v2 (009OCC); a removed job type reads null.
     */
    public function test_v2_never_blanks_or_moves_what_v1_knew(): void
    {
        [$tenant, $connection] = $this->branch();

        $this->inTenant($tenant['org'], function () use ($connection) {
            foreach ([['DONE01', 'Done'], ['DPA001', 'done pending approval'], ['OPEN01', 'Submitted']] as [$uuid, $status]) {
                WorkizJob::create([
                    'franchise_id' => $connection->scope_id, 'connection_id' => $connection->id, 'remote_id' => $uuid,
                    'status' => $status, 'job_type' => 'Chimney Inspection', 'address' => '7 Bolton Road', 'city' => 'Bolton',
                    'postal_code' => '06043', 'scheduled_for' => '2026-07-16 08:00:00', 'payload' => [], 'synced_at' => now(),
                ]);

                WorkizJob::land($connection, WorkizV2::job($this->v2Job([
                    'uuid' => $uuid, 'status' => $status, 'date' => null, 'endDate' => null, 'isScheduled' => false, 'jobType' => null,
                    'clientInfo' => ['addressDetails' => ['address' => '1 Avon Way', 'city' => 'Avon', 'zipCode' => '46123', 'state' => 'Indiana']],
                ])));
            }

            $done = WorkizJob::query()->where('remote_id', 'DONE01')->sole();
            $this->assertSame(['7 Bolton Road', 'Bolton', '06043'], [$done->address, $done->city, $done->postal_code], "the job's own place");
            $this->assertSame('Chimney Inspection', $done->job_type);
            $this->assertSame('2026-07-16 08:00:00', $done->scheduled_for?->format('Y-m-d H:i:s'), 'a finished visit keeps its date');

            $this->assertNotNull(WorkizJob::query()->where('remote_id', 'DPA001')->value('scheduled_for'), 'pending approval is finished work too');

            $open = WorkizJob::query()->where('remote_id', 'OPEN01')->sole();
            $this->assertNull($open->scheduled_for, 'an open job Workiz unscheduled is waiting for a date');

            // A job EzBizi has never held takes what v2 says.
            WorkizJob::land($connection, WorkizV2::job($this->v2Job(['uuid' => 'NEW001'])));
            $this->assertSame('North Attleborough', WorkizJob::query()->where('remote_id', 'NEW001')->value('city'));
        });
    }

    public function test_one_job_is_read_by_its_job_id_and_a_404_is_gone(): void
    {
        [$tenant, $connection] = $this->branch();
        $this->inTenant($tenant['org'], fn () => WorkizJob::land($connection, WorkizV2::job($this->v2Job())));

        Http::fake([
            'api.workiz.com/crm/api/v2/jobs/JOB-8ApvxXebm23oQV4E' => Http::sequence()
                ->push($this->v2Job(['status' => 'Done']))
                ->push(['message' => 'Invalid Job Id', 'error' => 'Not Found', 'statusCode' => 404], 404),
        ]);

        $workiz = app(WorkizJobs::class);
        $this->assertSame('Done', $workiz->find($connection, 'NYCZ7W')['job']['Status'], 'by the uuid EzBizi keys on');
        $this->assertSame(['job' => null, 'failed' => null], $workiz->find($connection, 'NYCZ7W'), 'Workiz holds no such job');
    }

    public function test_a_key_says_which_version_it_speaks(): void
    {
        Http::fake([
            'api.workiz.com/api/v1/v2key/*' => Http::response(['error' => 'Forbidden', 'message' => 'Invalid API path or malformed API key.'], 403),
            'api.workiz.com/api/v1/api_ok/*' => Http::response(['flag' => true, 'data' => []]),
            'api.workiz.com/crm/api/v2/*' => Http::response($this->page([])),
        ]);

        $this->assertSame('v2', app(WorkizJobs::class)->verify('v2key'));
        $this->assertSame('v1', app(WorkizJobs::class)->verify('api_ok'));
    }

    /** New York, measured: 11 people, all matched by email and name, 10 of 10 agreeing through shared jobs. */
    public function test_a_v2_team_member_takes_over_the_v1_rows_link(): void
    {
        [$tenant, $connection] = $this->branch();
        $user = $this->inTenant($tenant['org'], fn () => User::factory()->create(['organization_id' => $tenant['org']->id]));
        $this->inTenant($tenant['org'], fn () => WorkizTeamMember::create([
            'franchise_id' => $connection->scope_id, 'remote_id' => '436274', 'name' => 'Chaim Perez', 'email' => 'chaim@example.test',
            'is_active' => true, 'is_field_tech' => true, 'user_id' => $user->id, 'payload' => [], 'synced_at' => now(),
        ]));

        Http::fake(['api.workiz.com/crm/api/v2/users*' => Http::response($this->page([[
            'id' => 'USR-ap8XB7Vm8PqogDWq', 'name' => 'Chaim Perez', 'emailAddress' => 'Chaim@Example.test', 'role' => 'admin',
            'fieldTech' => true, 'active' => true, 'serviceAreas' => [['id' => 'SA-1', 'name' => 'Boston South']], 'jobTypes' => [],
        ]]))]);

        $this->artisan('ezbizi:sync-workiz-team')->assertSuccessful();

        $this->inTenant($tenant['org'], function () use ($user) {
            $new = WorkizTeamMember::query()->where('remote_id', 'USR-ap8XB7Vm8PqogDWq')->sole();
            $this->assertSame($user->id, $new->user_id);
            $this->assertSame(['Boston South'], $new->service_areas);
            $old = WorkizTeamMember::query()->where('remote_id', '436274')->sole();
            $this->assertFalse($old->is_active, 'one person, one seat');
            $this->assertSame('USR-ap8XB7Vm8PqogDWq', $old->payload['succeeded_by'], "the board's way from an old payload to the person");
        });
    }

    public function test_connecting_a_v2_key_keeps_the_v1_pair_beside_it(): void
    {
        [$tenant, $connection] = $this->branch('v1');
        Http::fake([
            'api.workiz.com/api/v1/*' => Http::response(['error' => 'Forbidden'], 403),
            'api.workiz.com/crm/api/v2/*' => Http::response($this->page([])),
        ]);

        $admin = $this->inTenant($tenant['org'], function () use ($tenant, $connection) {
            $tenant['admin']->forceFill(['preferences' => ['navigation.scope' => 'franchise:'.$connection->scope_id]])->save();

            return $tenant['admin']->fresh();
        });

        $this->signedInAs($admin)->postJson('/api/v1/social/workiz', ['api_token' => 'v2key_MA'])->assertCreated();

        $credentials = $this->inTenant($tenant['org'], fn () => $connection->fresh()->credentials);
        $this->assertSame('v2', $credentials['api_version']);
        $this->assertSame('v2key_MA', $credentials['api_token']);
        $this->assertArrayNotHasKey('auth_secret', $credentials);
        $this->assertSame(['api_token' => 'api_MA', 'auth_secret' => 'sec_MA'], $credentials['v1']);
    }

    /** Jose, 11-12: Connect does the whole setup and answers what passed and what still needs a person. */
    public function test_connecting_reads_the_lists_sets_a_missing_standard_and_answers_its_health(): void
    {
        [$tenant, $connection] = $this->branch('v1');
        Http::fake([
            'api.workiz.com/api/v1/*' => Http::response(['error' => 'Forbidden'], 403),
            'api.workiz.com/crm/api/v2/tags*' => Http::response($this->page([['id' => 'T1', 'name' => 'DEPOSIT RECEIVED', 'color' => '#0059a0']])),
            'api.workiz.com/crm/api/v2/*' => Http::response($this->page([])),
        ]);
        $admin = $this->inTenant($tenant['org'], function () use ($tenant, $connection) {
            $tenant['admin']->forceFill(['preferences' => ['navigation.scope' => 'franchise:'.$connection->scope_id]])->save();

            return $tenant['admin']->fresh();
        });

        $health = collect($this->signedInAs($admin)->postJson('/api/v1/social/workiz', ['api_token' => 'v2key_MA'])->assertCreated()->json('data.health'))->keyBy('key');

        $this->assertTrue($health['key']['ok']);
        $this->assertTrue($health['tags']['ok'], 'the tag list is read at Connect, not on the next schedule');
        $this->assertTrue($health['standard']['ok'], 'with no standard yet, this account sets it');
        $this->assertFalse($health['web_login']['ok']);
        $this->assertSame('No Workiz web login is connected.', $health['web_login']['detail']);
        $this->assertFalse($health['webhook']['ok']);
        $this->assertStringContainsString('/api/v1/social/workiz/webhook/'.$connection->getKey(), $health['webhook']['detail']);
        $this->assertSame(['DEPOSIT RECEIVED'], collect($this->inTenant($tenant['org'], fn () => app(\App\Support\Settings\SettingsResolver::class)
            ->get('workiz.tag_baseline', $tenant['org'])))->pluck('name')->all());
        // 11-12: with a standard saved, filter('is_array') got (value, key) and 500'd this list.
        $cards = collect($this->signedInAs($admin)->getJson('/api/v1/social/connections?include=data_sources')->assertOk()->json('data'));
        $this->assertNotNull($cards->firstWhere('id', $connection->getKey())['workiz']['health'] ?? null, 'the list draws this account health');
    }

    /** Reports 11-20: 333 of 891 done jobs never had a single read, so their photos were unknown. */
    public function test_a_done_jobs_files_are_read_once_per_version_of_the_job(): void
    {
        [$tenant, $connection] = $this->branch();
        $base = array_replace(require base_path('tests/Fixtures/workiz/v2_job.php'), ['id' => 'JOB-D1', 'status' => 'Done', 'date' => now()->subDays(2)->format('Y-m-d H:i:s'), 'utcUpdatedAt' => '2026-11-19 15:00:00']);
        $land = fn (array $job) => $this->inTenant($tenant['org'], fn () => WorkizJob::land($connection, WorkizV2::job($job)));
        $queued = fn () => \Illuminate\Support\Facades\Queue::pushed(\App\Jobs\RereadWorkizRecord::class)->count();
        \Illuminate\Support\Facades\Queue::fake();

        $land($base);
        $this->artisan('ezbizi:read-workiz-job-files')->assertSuccessful();
        $this->assertSame(1, $queued(), 'a done job no single read has touched is read');

        $land($base + ['attachments' => [['id' => 'F1', 'name' => 'chimney.jpg', 'fileUrl' => 'https://st.sendajob.com/f1.jpg']]]);
        $land($base);
        $this->artisan('ezbizi:read-workiz-job-files')->assertSuccessful();
        $this->assertSame(1, $queued(), 'read at this version, so not again; a list read keeps the files');

        $land(array_replace($base, ['utcUpdatedAt' => '2026-11-19 18:00:00']));
        $this->artisan('ezbizi:read-workiz-job-files')->assertSuccessful();
        $this->assertSame(2, $queued(), 'the job changed after its files were read');
        $this->assertSame('chimney.jpg', WorkizJob::query()->withoutGlobalScopes()->sole()->payload['Attachments'][0]['Name']);
    }

    /** 0621: synced_at moves on every list read, so a synced_at window matched 35,543 old jobs, not ~333. */
    public function test_an_old_done_job_that_was_just_synced_is_not_read_for_files(): void
    {
        [$tenant, $connection] = $this->branch();
        \Illuminate\Support\Facades\Queue::fake();
        $this->inTenant($tenant['org'], fn () => WorkizJob::land($connection, WorkizV2::job(array_replace(require base_path('tests/Fixtures/workiz/v2_job.php'), ['id' => 'JOB-OLD', 'status' => 'Done', 'utcUpdatedAt' => '2026-01-01 10:00:00']))));
        WorkizJob::query()->withoutGlobalScopes()->update(['scheduled_for' => now()->subDays(90), 'synced_at' => now()]);

        $this->artisan('ezbizi:read-workiz-job-files')->assertSuccessful();
        \Illuminate\Support\Facades\Queue::assertNotPushed(\App\Jobs\RereadWorkizRecord::class);
    }

    /** 11-12: a slot killed by its 300s timeout left the projection lock held an hour; no Workiz job reached EzBizi. */
    public function test_a_killed_runs_lock_frees_within_five_minutes_and_health_flags_a_waiting_job(): void
    {
        [$tenant, $connection] = $this->branch();
        $this->inTenant($tenant['org'], fn () => WorkizJob::land($connection, WorkizV2::job($this->v2Job())));
        \Illuminate\Support\Facades\Cache::lock(\App\Console\Commands\ProjectWorkizRecords::LOCK, \App\Console\Commands\ProjectWorkizRecords::LOCK_SECONDS)->get();

        $this->travel(11)->minutes();
        $health = collect($this->inTenant($tenant['org'], fn () => \App\Support\Workiz\WorkizHealth::check($connection)))->keyBy('key');
        $this->assertFalse($health['projection']['ok']);
        $this->assertStringContainsString('waiting since', $health['projection']['detail']);
        $this->assertTrue(\Illuminate\Support\Facades\Cache::lock(\App\Console\Commands\ProjectWorkizRecords::LOCK, 1)->get(), 'the dead run no longer holds it');
    }

    /** Jose, 11-12: "a process that constantly checks the workiz - ezbizi ezbizi - workiz sync all the time". */
    public function test_the_watchdog_heals_a_stuck_lock_resends_an_unsent_job_and_alerts_once_per_episode(): void
    {
        [$tenant, $connection] = $this->branch();
        \Illuminate\Support\Facades\Queue::fake();
        $job = $this->inTenant($tenant['org'], function () use ($tenant) {
            app(\App\Support\Settings\SettingsResolver::class)->set('workiz.mirror', $tenant['franchise'], true);
            WorkizJob::land(SocialConnection::query()->sole(), WorkizV2::job($this->v2Job()));
            $customer = \App\Models\Customer::factory()->create(['owning_franchise_id' => $tenant['franchise']->id]);

            return \App\Models\Job::factory()->create(['franchise_id' => $tenant['franchise']->id, 'customer_id' => $customer->id]);
        });
        \Illuminate\Support\Facades\Cache::lock(\App\Console\Commands\ProjectWorkizRecords::LOCK, 300)->get();
        $this->travel(16)->minutes();
        $this->inTenant($tenant['org'], fn () => WorkizJob::query()->update(['synced_at' => now()]));

        $this->artisan('ezbizi:workiz-watchdog')->assertSuccessful();
        $this->artisan('ezbizi:workiz-watchdog')->assertSuccessful();

        $this->assertTrue(\Illuminate\Support\Facades\Cache::lock(\App\Console\Commands\ProjectWorkizRecords::LOCK, 1)->get(), 'the stuck lock is freed');
        \Illuminate\Support\Facades\Queue::assertPushed(\App\Jobs\MirrorToWorkiz::class, 1);
        $raised = fn (string $key) => $this->inTenant($tenant['org'], fn () => \App\Models\AutomationEvent::query()->where('event_key', $key)->count());
        $this->assertSame(1, $raised('franchise.workiz_sync_drift'), 'one alert, not one per run');
        $health = collect($this->inTenant($tenant['org'], fn () => \App\Support\Workiz\WorkizHealth::check($connection)))->keyBy('key');
        $this->assertFalse($health['sync']['ok']);
        $this->assertStringContainsString($job->number, $health['sync']['detail']);

        // Both sides in step again: one "recovered", and the card says OK.
        $this->inTenant($tenant['org'], function () use ($job) {
            $row = WorkizJob::query()->sole();
            $row->forceFill(['job_id' => $job->getKey(), 'synced_at' => now()])->save();
            \Illuminate\Support\Facades\DB::table('jobs')->where('id', $job->getKey())->update(['status' => $row->status,
                'scheduled_for' => $row->getRawOriginal('scheduled_for'), 'amount_minor' => (int) round(((float) ($row->payload['JobTotalPrice'] ?? 0)) * 100)]);
        });
        $this->artisan('ezbizi:workiz-watchdog')->assertSuccessful();
        $this->artisan('ezbizi:workiz-watchdog')->assertSuccessful();
        $this->assertSame(1, $raised('franchise.workiz_sync_recovered'));
        $health = collect($this->inTenant($tenant['org'], fn () => \App\Support\Workiz\WorkizHealth::check($connection)))->keyBy('key');
        $this->assertStringStartsWith('OK · last checked', $health['sync']['detail']);
    }

    /** Reports, 11-12: Texas's Workiz writes Central on a New York branch; its jobs were stamped an hour early. */
    public function test_an_accounts_clock_is_measured_from_its_copy_lag_not_taken_from_the_branch(): void
    {
        [$tenant] = $this->branch();
        $this->travelTo(\Illuminate\Support\Carbon::parse('2026-11-12 18:00:00', 'UTC'));
        $this->inTenant($tenant['org'], function () use ($tenant) {
            foreach (range(1, 10) as $i) {
                WorkizJob::query()->create(['franchise_id' => $tenant['franchise']->id, 'remote_id' => 'C'.$i, 'status' => 'Submitted', 'payload' => [],
                    'booked_at' => '2026-11-12 11:58:00', 'synced_at' => now()]); // copied 2 minutes after noon Central
            }
            $this->assertSame('America/Chicago', \App\Support\Workiz\WorkizClock::zone($tenant['franchise']));
        });
    }

    public function test_dispatch_writes_go_through_v2(): void
    {
        [$tenant, $connection] = $this->branch();
        [$job, $member] = $this->inTenant($tenant['org'], function () use ($connection) {
            WorkizJob::land($connection, WorkizV2::job($this->v2Job()));
            $member = WorkizTeamMember::create([
                'franchise_id' => $connection->scope_id, 'remote_id' => 'USR-ap8XB7Vm8PqogDWq', 'name' => 'Chaim Perez',
                'is_active' => true, 'is_field_tech' => true, 'payload' => [], 'synced_at' => now(),
            ]);

            return [WorkizJob::query()->sole(), $member];
        });

        Http::fake([
            'api.workiz.com/crm/api/v2/jobs/JOB-8ApvxXebm23oQV4E/team*' => Http::response(['id' => 'USR-ap8XB7Vm8PqogDWq', 'name' => 'Chaim Perez', 'isPrimary' => true], 201),
            'api.workiz.com/crm/api/v2/jobs/JOB-8ApvxXebm23oQV4E' => Http::response($this->v2Job(['date' => '2026-10-08 09:00:00', 'endDate' => '2026-10-08 11:00:00'])),
        ]);

        $writer = app(WorkizJobWriter::class);
        $moved = $this->inTenant($tenant['org'], fn () => $writer->reschedule($job, '2026-10-08 09:00:00', '2026-10-08 11:00:00'));
        $this->inTenant($tenant['org'], fn () => $writer->assign($job, $member));

        Http::assertSent(fn (Request $r) => $r->method() === 'PUT' && str_ends_with($r->url(), '/jobs/JOB-8ApvxXebm23oQV4E')
            && $r['date'] === '2026-10-08 09:00:00' && $r['endDate'] === '2026-10-08 11:00:00');
        Http::assertSent(fn (Request $r) => $r->method() === 'POST' && str_ends_with($r->url(), '/team') && $r['technitionId'] === 'USR-ap8XB7Vm8PqogDWq');
        $this->assertNull($moved['refused']);
        $this->assertSame('2026-10-08 09:00:00', $moved['job']->scheduled_for->format('Y-m-d H:i:s'), "the update's own answer, landed");
    }

    public function test_a_visit_is_created_through_v2_by_type_id_and_client_id(): void
    {
        [$tenant, $connection] = $this->branch();
        $visit = $this->inTenant($tenant['org'], function () use ($connection) {
            $customer = Customer::create(['owning_franchise_id' => $connection->scope_id, 'number' => 'C-1', 'name' => 'Pat Rivera', 'is_active' => true]);
            WorkizClient::create(['franchise_id' => $connection->scope_id, 'client_id' => 1008, 'customer_id' => $customer->id, 'synced_at' => now()]);
            // An earlier job of theirs, through v2, is where their CL- id is known.
            WorkizJob::land($connection, WorkizV2::job($this->v2Job(['uuid' => 'OLD001'])));

            return Job::create([
                'franchise_id' => $connection->scope_id, 'customer_id' => $customer->id, 'number' => 'J-1', 'title' => 'Membership visit',
                'status' => 'Submitted', 'scheduled_for' => '2026-11-10 09:00:00', 'duration_minutes' => 90, 'currency' => 'USD',
            ]);
        });

        Http::fake([
            'api.workiz.com/crm/api/v2/jobtypes*' => Http::response($this->page([['id' => 'JT-x1', 'name' => 'Chimney Inspection']])),
            'api.workiz.com/crm/api/v2/jobs' => Http::response($this->v2Job(['id' => 'JOB-new', 'uuid' => 'NEW001', 'serialId' => 11009, 'date' => '2026-11-10 09:00:00']), 201),
        ]);

        $made = $this->inTenant($tenant['org'], fn () => app(WorkizJobWriter::class)->create($visit, 'chimney inspection'));

        Http::assertSent(fn (Request $r) => $r->method() === 'POST' && str_ends_with($r->url(), '/v2/jobs')
            && $r['jobTypeId'] === 'JT-x1' && $r['clientInfo'] === ['clientId' => 'CL-xzA4NAKqzXW76XQp']
            && $r['date'] === '2026-11-10 09:00:00' && $r['endDate'] === '2026-11-10 10:30:00');
        $this->assertNull($made['refused']);
        $this->assertSame('NEW001', $made['job']->remote_id);
        $this->assertSame($visit->id, $made['job']->job_id);

        $this->artisan('ezbizi:project-workiz')->assertSuccessful();
        $this->assertSame(1, $this->inTenant($tenant['org'], fn () => Job::query()->where('title', '!=', 'Chimney sweep #1')->count()), 'not projected a second time');
    }

    public function test_a_type_the_branch_does_not_have_is_refused_by_name(): void
    {
        [$tenant, $connection] = $this->branch();
        $visit = $this->inTenant($tenant['org'], fn () => Job::create([
            'franchise_id' => $connection->scope_id, 'number' => 'J-1', 'title' => 'Visit', 'status' => 'Submitted', 'currency' => 'USD',
            'customer_id' => Customer::create(['owning_franchise_id' => $connection->scope_id, 'number' => 'C-1', 'name' => 'Pat Rivera', 'is_active' => true])->id,
        ]));
        Http::fake(['api.workiz.com/crm/api/v2/jobtypes*' => Http::response($this->page([['id' => 'JT-x1', 'name' => 'Chimney Sweep']]))]);

        $made = $this->inTenant($tenant['org'], fn () => app(WorkizJobWriter::class)->create($visit, 'Dryer Vent'));

        $this->assertStringContainsString('no job type "Dryer Vent"', (string) $made['refused']);
        Http::assertNotSent(fn (Request $r) => $r->method() === 'POST');
    }

    public function test_leads_are_read_through_v2_by_when_they_were_made(): void
    {
        [, $connection] = $this->branch();
        Http::fake(['api.workiz.com/crm/api/v2/leads*' => Http::response($this->page([$this->v2Job(['uuid' => 'LEAD01', 'description' => 'Wants a quote'])]))]);

        $read = app(WorkizLeads::class)->raw($connection, since: '2026-10-01');

        Http::assertSent(fn (Request $r) => $r['dateProperty'] === 'created' && $r['date'] === '2026-10-01');
        $this->assertSame('LEAD01', $read['leads'][0]['UUID']);
        $this->assertSame('Wants a quote', $read['leads'][0]['LeadNotes']);
    }

    /** Massachusetts, 2026-10-06: connected on v2 alone, it keeps a v1 pair for what only v1 says (who booked a job). */
    public function test_a_v2_branch_keeps_a_v1_pair_that_reads_its_own_jobs(): void
    {
        [$tenant, $connection] = $this->branch();
        $this->inTenant($tenant['org'], fn () => WorkizJob::create([
            'franchise_id' => $connection->scope_id, 'connection_id' => $connection->id, 'remote_id' => 'NYCZ7W',
            'status' => 'Submitted', 'payload' => [], 'synced_at' => now(),
        ]));
        $file = tempnam(sys_get_temp_dir(), 'wz');
        file_put_contents($file, "api_MA\nsec_MA\n");
        Http::fake(['api.workiz.com/api/v1/api_MA/job/all/*' => Http::response(['flag' => true, 'data' => [['UUID' => 'NYCZ7W']]])]);

        $this->artisan('ezbizi:workiz-keep-v1', ['franchise' => $connection->scope_id, '--key-file' => $file, '--dry-run' => true])->assertSuccessful();
        $this->assertArrayNotHasKey('v1', $this->inTenant($tenant['org'], fn () => $connection->fresh()->credentials), 'a dry run changes nothing');

        $this->artisan('ezbizi:workiz-keep-v1', ['franchise' => $connection->scope_id, '--key-file' => $file])->assertSuccessful();

        $credentials = $this->inTenant($tenant['org'], fn () => $connection->fresh()->credentials);
        $this->assertSame(['v2key_MA', 'v2', 'api_MA', 'sec_MA'], [$credentials['api_token'], $credentials['api_version'], $credentials['v1']['api_token'], $credentials['v1']['auth_secret']]);
        $this->assertSame(1, DB::table('audit_events')->where('action', 'workiz.v1_kept')->count());
        @unlink($file);
    }

    /** A pair that reads another book, or none of this one, is not kept; nor is a v2 key passed off as v1. */
    public function test_a_v1_pair_that_is_not_this_branchs_is_refused(): void
    {
        [$tenant, $connection] = $this->branch();
        $other = $this->extraFranchise($tenant, 'Nationwide');
        $this->inTenant($tenant['org'], fn () => WorkizJob::create([
            'franchise_id' => $other->id, 'connection_id' => (string) Str::uuid(), 'remote_id' => 'NYCZ7W',
            'status' => 'Submitted', 'payload' => [], 'synced_at' => now(),
        ]));
        $this->inTenant($tenant['org'], fn () => WorkizJob::create([
            'franchise_id' => $connection->scope_id, 'connection_id' => $connection->id, 'remote_id' => 'MINE01',
            'status' => 'Submitted', 'payload' => [], 'synced_at' => now(),
        ]));
        $file = tempnam(sys_get_temp_dir(), 'wz');
        Http::fake([
            'api.workiz.com/api/v1/api_NW/job/all/*' => Http::response(['flag' => true, 'data' => [['UUID' => 'NYCZ7W']]]),
            'api.workiz.com/api/v1/api_BOTH/job/all/*' => Http::response(['flag' => true, 'data' => [['UUID' => 'MINE01'], ['UUID' => 'NYCZ7W']]]),
            'api.workiz.com/api/v1/api_NONE/job/all/*' => Http::response(['flag' => true, 'data' => [['UUID' => 'NOBODY']]]),
            'api.workiz.com/api/v1/*' => Http::response(['error' => 'Forbidden'], 403),
            'api.workiz.com/crm/api/v2/jobs*' => Http::response($this->page([$this->v2Job()])),
        ]);

        // Another branch's book; a book holding this branch's jobs AND another's; a book with none of this branch's.
        foreach (['api_NW', 'api_BOTH', 'api_NONE'] as $token) {
            file_put_contents($file, $token."\nsecret\n");
            $this->artisan('ezbizi:workiz-keep-v1', ['franchise' => $connection->scope_id, '--key-file' => $file])
                ->expectsOutputToContain("does not read this branch's jobs")->assertFailed();
        }

        file_put_contents($file, "v2key_MA\nnothing\n");
        $this->artisan('ezbizi:workiz-keep-v1', ['franchise' => $connection->scope_id, '--key-file' => $file])
            ->expectsOutputToContain('not a v1 token')->assertFailed();

        $this->assertArrayNotHasKey('v1', $this->inTenant($tenant['org'], fn () => $connection->fresh()->credentials));
        @unlink($file);
    }

    /** A key saved under the wrong branch's name would put one book on another. */
    public function test_switching_refuses_a_key_that_reads_another_branchs_jobs(): void
    {
        [$tenant, $connection] = $this->branch('v1');
        $other = $this->extraFranchise($tenant, 'Nationwide');
        $this->inTenant($tenant['org'], fn () => WorkizJob::create([
            'franchise_id' => $other->id, 'connection_id' => (string) Str::uuid(), 'remote_id' => 'NYCZ7W',
            'status' => 'Submitted', 'payload' => [], 'synced_at' => now(),
        ]));
        $file = tempnam(sys_get_temp_dir(), 'wz');
        file_put_contents($file, "v2key_MA\n");
        Http::fake([
            'api.workiz.com/api/v1/*' => Http::response(['error' => 'Forbidden'], 403),
            'api.workiz.com/crm/api/v2/jobs*' => Http::response($this->page([$this->v2Job()])),
        ]);

        $this->artisan('ezbizi:workiz-use-v2', ['franchise' => $connection->scope_id, '--key-file' => $file])
            ->expectsOutputToContain("reads another account's jobs")->assertFailed();

        $this->assertSame('api_MA', $this->inTenant($tenant['org'], fn () => $connection->fresh()->credentials['api_token']), 'nothing changed');
        @unlink($file);
    }

    public function test_switching_moves_the_branch_and_brings_its_team_along(): void
    {
        [$tenant, $connection] = $this->branch('v1');
        $this->inTenant($tenant['org'], fn () => WorkizJob::create([
            'franchise_id' => $connection->scope_id, 'connection_id' => $connection->id, 'remote_id' => 'NYCZ7W',
            'status' => 'Submitted', 'payload' => [], 'synced_at' => now(),
        ]));
        $file = tempnam(sys_get_temp_dir(), 'wz');
        file_put_contents($file, "v2key_MA\n");
        Http::fake([
            'api.workiz.com/api/v1/*' => Http::response(['error' => 'Forbidden'], 403),
            'api.workiz.com/crm/api/v2/jobs*' => Http::response($this->page([$this->v2Job()])),
            'api.workiz.com/crm/api/v2/users*' => Http::response($this->page([['id' => 'USR-1', 'name' => 'Chaim Perez', 'emailAddress' => 'c@example.test', 'active' => true, 'fieldTech' => true]])),
        ]);

        $this->artisan('ezbizi:workiz-use-v2', ['franchise' => $connection->scope_id, '--key-file' => $file, '--dry-run' => true])->assertSuccessful();
        $this->assertSame('api_MA', $this->inTenant($tenant['org'], fn () => $connection->fresh()->credentials['api_token']), 'a dry run changes nothing');

        $this->artisan('ezbizi:workiz-use-v2', ['franchise' => $connection->scope_id, '--key-file' => $file])->assertSuccessful();

        $this->inTenant($tenant['org'], function () use ($connection) {
            $credentials = $connection->fresh()->credentials;
            $this->assertSame(['v2key_MA', 'v2'], [$credentials['api_token'], $credentials['api_version']]);
            $this->assertSame('api_MA', $credentials['v1']['api_token']);
            $this->assertTrue(WorkizTeamMember::query()->where('remote_id', 'USR-1')->exists(), 'the team came with it');
            $this->assertSame('JOB-8ApvxXebm23oQV4E', WorkizJob::query()->where('remote_id', 'NYCZ7W')->sole()->payload['v2']['id'], 'and the upcoming jobs, re-read through v2');
        });
        $this->assertSame(1, DB::table('audit_events')->where('action', 'workiz.moved_to_v2')->count());
        $this->assertFalse(Cache::lock(ProjectWorkizRecords::LOCK, 1)->get() === false, 'the lock is let go');
        @unlink($file);
    }

    /**
     * Connecticut, 2026-10-05: two "Adir Shemesh" in Workiz with one email, one
     * active, and two v1 rows the same. The first choice was a tie and left his
     * active v1 row beside the active v2 one: twice on the board, two seats.
     */
    public function test_two_people_by_one_email_still_end_as_one_active_row(): void
    {
        [$tenant, $connection] = $this->branch();
        $user = $this->inTenant($tenant['org'], fn () => User::factory()->create(['organization_id' => $tenant['org']->id]));
        $this->inTenant($tenant['org'], function () use ($connection, $user) {
            foreach ([['437573', true, $user->id], ['400001', false, null]] as [$id, $active, $linked]) {
                WorkizTeamMember::create([
                    'franchise_id' => $connection->scope_id, 'remote_id' => $id, 'name' => 'Adir Shemesh', 'email' => 'adir@example.test',
                    'is_active' => $active, 'is_field_tech' => true, 'user_id' => $linked, 'payload' => [], 'synced_at' => now(),
                ]);
            }
        });
        $person = fn (string $id, bool $active) => ['id' => $id, 'name' => 'Adir Shemesh', 'emailAddress' => 'adir@example.test', 'fieldTech' => true, 'active' => $active];
        Http::fake(['api.workiz.com/crm/api/v2/users*' => Http::response($this->page([$person('USR-old', false), $person('USR-live', true)]))]);

        $this->artisan('ezbizi:sync-workiz-team')->assertSuccessful();

        $this->inTenant($tenant['org'], function () use ($user) {
            $this->assertSame(0, WorkizTeamMember::query()->where('remote_id', 'not like', 'USR-%')->where('is_active', true)->count(), 'no v1 row left active');
            $this->assertSame('USR-live', WorkizTeamMember::query()->where('remote_id', '437573')->sole()->payload['succeeded_by']);
            $this->assertSame($user->id, WorkizTeamMember::query()->where('remote_id', 'USR-live')->value('user_id'), 'the live row has his link');
        });
    }

    /**
     * Connecticut as the first switch left it: both v2 rows already exist,
     * and his active v1 row was never succeeded. The next team sync settles it.
     */
    public function test_a_v1_row_left_active_by_an_earlier_tie_is_settled(): void
    {
        [$tenant, $connection] = $this->branch();
        $this->inTenant($tenant['org'], function () use ($connection) {
            foreach ([['437573', true, []], ['400001', false, ['succeeded_by' => 'USR-old']], ['USR-old', false, []], ['USR-live', true, []]] as [$id, $active, $payload]) {
                WorkizTeamMember::create([
                    'franchise_id' => $connection->scope_id, 'remote_id' => $id, 'name' => 'Adir Shemesh', 'email' => 'adir@example.test',
                    'is_active' => $active, 'is_field_tech' => true, 'payload' => $payload, 'synced_at' => now(),
                ]);
            }
        });
        $person = fn (string $id, bool $active) => ['id' => $id, 'name' => 'Adir Shemesh', 'emailAddress' => 'adir@example.test', 'fieldTech' => true, 'active' => $active];
        Http::fake(['api.workiz.com/crm/api/v2/users*' => Http::response($this->page([$person('USR-old', false), $person('USR-live', true)]))]);

        $this->artisan('ezbizi:sync-workiz-team')->assertSuccessful();

        $this->inTenant($tenant['org'], function () {
            $old = WorkizTeamMember::query()->where('remote_id', '437573')->sole();
            $this->assertFalse($old->is_active);
            $this->assertSame('USR-live', $old->payload['succeeded_by']);
        });
    }

    /** Rows v1 landed carry no JOB- id, and v2 reads a job by nothing else: a refresh lands each once through v2. */
    public function test_a_refresh_backfill_gives_rows_v1_landed_their_v2_id(): void
    {
        [$tenant, $connection] = $this->branch();
        $this->inTenant($tenant['org'], fn () => WorkizJob::create([
            'franchise_id' => $connection->scope_id, 'connection_id' => $connection->id, 'remote_id' => 'NYCZ7W',
            'status' => 'Submitted', 'payload' => ['UUID' => 'NYCZ7W'], 'synced_at' => now()->subDay(),
        ]));
        Http::fake(['api.workiz.com/crm/api/v2/jobs*' => Http::response($this->page([$this->v2Job(['status' => 'Done'])]))]);

        $this->artisan('ezbizi:backfill-workiz', ['--refresh' => true, '--restart' => true, '--pause' => 0])->assertSuccessful();

        $row = $this->inTenant($tenant['org'], fn () => WorkizJob::query()->sole());
        $this->assertSame('JOB-8ApvxXebm23oQV4E', $row->payload['v2']['id']);
        $this->assertSame('Done', $row->status, "and Workiz's status today");
    }

    /** v2 answers 500 for any page holding one New Jersey job: the page is read around it, and paging goes on. */
    public function test_a_page_workiz_cannot_list_is_read_around_its_bad_job(): void
    {
        [, $connection] = $this->branch();
        $job = fn (int $n) => $this->v2Job(['uuid' => sprintf('J%05d', $n), 'id' => 'JOB-'.$n]);
        Http::fake(function (Request $r) use ($job) {
            [$page, $size] = [(int) $r['page'], (int) $r['pageSize']];
            $first = ($page - 1) * $size + 1;
            $items = range($first, min($first + $size - 1, 150));
            if (in_array(57, $items, true) && $size > 1 || ($size === 1 && $page === 57)) {
                return Http::response(['code' => 'INTERNAL_SERVER_ERROR', 'status' => 500], 500);
            }

            return Http::response($this->page(array_map($job, $first > 150 ? [] : $items), $first + $size - 1 < 150));
        });

        $read = app(WorkizJobs::class)->raw($connection, 1000, '2023-01-01');

        $this->assertNull($read['failed']);
        $this->assertCount(149, $read['jobs'], 'all but the one Workiz cannot list, across both pages');
        $this->assertNotContains('J00057', array_column($read['jobs'], 'UUID'));
    }

    /** A row v1 landed has no JOB- id; the kept v1 pair asks for it, and its 204 is "deleted" (Nationwide's 45 moved jobs). */
    public function test_a_row_v1_landed_is_asked_through_the_kept_v1_pair(): void
    {
        [$tenant, $connection] = $this->branch();
        $this->inTenant($tenant['org'], function () use ($connection) {
            $connection->connect(['api_token' => 'v2key_NW', 'api_version' => 'v2', 'v1' => ['api_token' => 'api_NW', 'auth_secret' => 'sec_NW']]);
            $connection->save();
            WorkizJob::create([
                'franchise_id' => $connection->scope_id, 'connection_id' => $connection->id, 'remote_id' => '09RR7R',
                'status' => 'Submitted', 'payload' => ['UUID' => '09RR7R'], 'synced_at' => now(),
            ]);
        });
        Http::fake(['api.workiz.com/api/v1/api_NW/job/get/09RR7R/' => Http::response(null, 204)]);

        $this->assertSame(['job' => null, 'failed' => null], app(WorkizJobs::class)->find($connection->fresh(), '09RR7R'));
        Http::assertSentCount(1);
    }

    /** Jobs' Book panel, 2026-10-27: description, custom fields by the branch's ids, and all-day (end on the next day, as Workiz takes it). */
    public function test_a_booked_job_sends_its_description_custom_fields_and_all_day(): void
    {
        [$tenant, $connection] = $this->branch();
        $visit = $this->inTenant($tenant['org'], function () use ($connection) {
            $customer = Customer::create(['owning_franchise_id' => $connection->scope_id, 'number' => 'C-1', 'name' => 'Pat Rivera', 'is_active' => true]);
            \App\Models\WorkizRecord::query()->create(['franchise_id' => $connection->scope_id, 'connection_id' => $connection->id, 'type' => 'custom_field',
                'remote_id' => 'CF-1', 'payload' => ['name' => 'Pricing '], 'synced_at' => now()]);

            return Job::create([
                'franchise_id' => $connection->scope_id, 'customer_id' => $customer->id, 'number' => 'J-1', 'title' => 'Sweep', 'description' => 'Back door',
                'status' => 'Submitted', 'scheduled_for' => '2026-11-10 09:00:00', 'duration_minutes' => 90, 'currency' => 'USD',
                'all_day' => true, 'custom_fields' => ['pricing' => '$99 - Level 1 Inspection', 'Not in Workiz' => 'x'],
            ]);
        });
        Http::fake([
            'api.workiz.com/crm/api/v2/jobtypes*' => Http::response($this->page([['id' => 'JT-x1', 'name' => 'Chimney Inspection']])),
            'api.workiz.com/crm/api/v2/jobs' => Http::response($this->v2Job(['id' => 'JOB-new', 'uuid' => 'NEW001', 'serialId' => 11009]), 201),
        ]);

        $this->inTenant($tenant['org'], fn () => app(WorkizJobWriter::class)->create($visit, 'Chimney Inspection'));

        Http::assertSent(fn (Request $r) => $r->method() === 'POST' && str_ends_with($r->url(), '/v2/jobs')
            && $r['description'] === 'Back door' && $r['customFields'] === [['id' => 'CF-1', 'value' => '$99 - Level 1 Inspection']]
            && $r['isAllDay'] === true && $r['date'] === '2026-11-10' && $r['endDate'] === '2026-11-11');
    }

    /** 11-12: a job booked with no date reaches Workiz unscheduled; a field Workiz requires comes back as its refusal. */
    public function test_a_job_with_no_date_is_created_unscheduled_and_a_missing_required_field_is_named(): void
    {
        [$tenant, $connection] = $this->branch();
        $visit = $this->inTenant($tenant['org'], function () use ($connection) {
            $customer = Customer::create(['owning_franchise_id' => $connection->scope_id, 'number' => 'C-1', 'name' => 'Pat Rivera', 'is_active' => true]);

            return Job::create(['franchise_id' => $connection->scope_id, 'customer_id' => $customer->id, 'number' => 'J-1', 'title' => 'Sweep',
                'status' => 'Submitted', 'currency' => 'USD']);
        });
        Http::fake([
            'api.workiz.com/crm/api/v2/jobtypes*' => Http::response($this->page([['id' => 'JT-x1', 'name' => 'Chimney Inspection']])),
            'api.workiz.com/crm/api/v2/jobs' => Http::sequence()
                ->push(['message' => 'The following custom fields are required: Pricing'], 400)
                ->push($this->v2Job(['id' => 'JOB-new', 'uuid' => 'NEW001', 'serialId' => 11009, 'date' => null]), 201),
        ]);

        $first = $this->inTenant($tenant['org'], fn () => app(WorkizJobWriter::class)->create($visit, 'Chimney Inspection'));
        $this->assertStringContainsString('Pricing', (string) $first['refused']);

        $made = $this->inTenant($tenant['org'], fn () => app(WorkizJobWriter::class)->create($visit, 'Chimney Inspection'));
        $this->assertNull($made['refused']);
        Http::assertSent(fn (Request $r) => $r->method() === 'POST' && str_ends_with($r->url(), '/v2/jobs')
            && $r['isScheduled'] === false && ! isset($r['date']));
    }
}
