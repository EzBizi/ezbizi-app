<?php

namespace Tests\Feature\Workiz;

use App\Console\Commands\ProjectWorkizRecords;
use App\Models\AutomationEvent;
use App\Models\Customer;
use App\Models\Job;
use App\Models\ServiceArea;
use App\Models\ServiceAreaZip;
use App\Models\User;
use App\Models\WorkizClient;
use App\Models\WorkizJob;
use App\Models\WorkizTeamMember;
use App\Support\Settings\SettingsResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

class ProjectWorkizRecordsTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    private function mirror(array $tenant, array $overrides = [], array $payloadOverrides = []): WorkizJob
    {
        $payload = array_merge(
            require base_path('tests/Fixtures/workiz/job_payload.php'),
            $payloadOverrides,
        );

        return $this->inTenant($tenant['org'], fn () => WorkizJob::create(array_merge([
            'franchise_id' => $tenant['franchise']->id,
            'connection_id' => (string) Str::uuid(),
            'remote_id' => $payload['UUID'],
            'client_id' => $payload['ClientId'],
            'status' => $payload['Status'],
            'job_type' => $payload['JobType'],
            'address' => $payload['Address'],
            'city' => $payload['City'],
            'state' => $payload['State'],
            'postal_code' => $payload['PostalCode'],
            'country' => $payload['Country'],
            'unit' => null,
            'booked_at' => $payload['CreatedDate'],
            'scheduled_for' => $payload['JobDateTime'],
            'payload' => $payload,
            'synced_at' => now(),
        ], $overrides)));
    }

    public function test_it_creates_a_customer_and_a_job_from_one_mirrored_record(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $this->mirror($tenant);

        $this->artisan('ezbizi:project-workiz')->assertSuccessful();

        [$customer, $job] = $this->inTenant($tenant['org'], fn () => [
            Customer::query()->first(),
            Job::query()->first(),
        ]);

        $this->assertSame('Pat Rivera', $customer->name);
        $this->assertSame('1 Example Street', $customer->address_line1);
        $this->assertSame('07030', $customer->postal_code);
        $this->assertSame($tenant['franchise']->id, $customer->owning_franchise_id);
        $this->assertNotEmpty($customer->number);

        $this->assertSame($customer->getKey(), $job->customer_id);
        $this->assertSame($tenant['franchise']->id, $job->franchise_id);
        $this->assertSame('Submitted', $job->status, 'Workiz\'s own vocabulary, unmapped');
        $this->assertNotEmpty($job->number);
        $this->assertNotEmpty($job->title);
    }

    /** Jobs, 2026-10-27: a projected job is born when and by whom Workiz says. */
    public function test_a_projected_job_carries_workizs_created_date_and_creator(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $this->inTenant($tenant['org'], fn () => \App\Models\WorkizTeamMember::create(['franchise_id' => $tenant['franchise']->id, 'remote_id' => 'U9',
            'name' => 'Ana Ruiz', 'user_id' => $tenant['admin']->id, 'is_active' => true, 'is_field_tech' => false, 'service_areas' => [], 'payload' => [], 'synced_at' => now()]));
        $this->mirror($tenant, [], ['CreatedDate' => '2026-01-15 09:30:00', 'CreatedBy' => 'ana ruiz ']);

        $this->artisan('ezbizi:project-workiz')->assertSuccessful();

        $job = $this->inTenant($tenant['org'], fn () => Job::query()->sole());
        $zone = $tenant['franchise']->fresh()->timezone ?: 'America/New_York';
        $this->assertSame(\Illuminate\Support\Carbon::createFromFormat('Y-m-d H:i:s', '2026-01-15 09:30:00', $zone)->utc()->toDateTimeString(), $job->created_at->utc()->toDateTimeString());
        $this->assertSame(['ana ruiz', $tenant['admin']->id], [$job->created_by_label, $job->created_by_user_id]);
    }

    /** Jobs, 2026-11-02: type, sub-status and tags land on create and follow Workiz until a person changes them. */
    public function test_workiz_labels_land_and_follow_until_a_person_changes_them(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $row = $this->mirror($tenant, [], ['JobType' => ' Chimney Sweep ', 'SubStatus' => 'Waiting', 'Tags' => ['VIP ', 'VIP', 'Roof']]);

        $this->artisan('ezbizi:project-workiz')->assertSuccessful();
        $job = fn () => $this->inTenant($tenant['org'], fn () => Job::query()->sole());
        $this->assertSame(['Chimney Sweep', 'Waiting', ['VIP', 'Roof']], [$job()->job_type, $job()->sub_status, $job()->tags]);

        // Workiz moves all three; nobody touched them here, so they follow.
        $move = fn (array $p) => $this->inTenant($tenant['org'], fn () => $row->fresh()->forceFill(['payload' => $p + $row->fresh()->payload])->save());
        $move(['JobType' => 'Inspection', 'SubStatus' => 'Parts ordered', 'Tags' => ['Roof']]);
        $this->artisan('ezbizi:project-workiz')->assertSuccessful();
        $this->assertSame(['Inspection', 'Parts ordered', ['Roof']], [$job()->job_type, $job()->sub_status, $job()->tags]);

        // A person sets the type and empties the tags; Workiz moving again leaves those alone.
        $this->inTenant($tenant['org'], fn () => $job()->forceFill(['job_type' => 'Repair', 'tags' => []])->save());
        $move(['JobType' => 'Sweep', 'SubStatus' => 'Ready', 'Tags' => ['New']]);
        $this->artisan('ezbizi:project-workiz')->assertSuccessful();
        $this->assertSame(['Repair', 'Ready', []], [$job()->job_type, $job()->sub_status, $job()->tags]);
    }

    /** Jobs, 11-10: Workiz's notes grow after the job is copied; the description follows until a person edits it. */
    public function test_workiz_notes_follow_into_the_description_until_a_person_edits_it(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $row = $this->mirror($tenant, [], ['JobNotes' => 'Line 1']);
        $this->artisan('ezbizi:project-workiz')->assertSuccessful();
        $job = fn () => $this->inTenant($tenant['org'], fn () => Job::query()->sole());
        $notes = fn (string $n) => $this->inTenant($tenant['org'], fn () => $row->fresh()->forceFill(['payload' => ['JobNotes' => $n] + $row->fresh()->payload])->save());

        // A row copied before the description was followed: the start of Workiz's notes is still ours.
        $this->inTenant($tenant['org'], fn () => $row->fresh()->forceFill(['projected' => array_diff_key($row->fresh()->projected, ['description' => 1])])->save());
        $notes("Line 1
Line 2");
        $this->artisan('ezbizi:project-workiz')->assertSuccessful();
        $this->assertSame("Line 1
Line 2", $job()->description);

        $this->inTenant($tenant['org'], fn () => $job()->forceFill(['description' => 'Typed in EzBizi'])->save());
        $notes("Line 1
Line 2
Line 3");
        $this->artisan('ezbizi:project-workiz')->assertSuccessful();
        $this->assertSame('Typed in EzBizi', $job()->description);
    }

    /** Scheduling, 2026-10-27: Workiz's team is handed over on create and again only when it moves. */
    public function test_the_workiz_team_is_followed_only_when_it_moves(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $row = $this->mirror($tenant, [], ['Team' => [['id' => 'U1', 'Name' => 'Ron']]]);

        $this->artisan('ezbizi:project-workiz')->assertSuccessful();
        $this->assertSame(['U1'], $this->inTenant($tenant['org'], fn () => $row->fresh()->projected['team']));

        $this->artisan('ezbizi:project-workiz')->doesntExpectOutputToContain('followed a change')->assertSuccessful();

        $this->inTenant($tenant['org'], fn () => $row->fresh()->forceFill(['payload' => ['Team' => [['id' => 'U2', 'Name' => 'Sam']]] + $row->fresh()->payload])->save());
        $this->artisan('ezbizi:project-workiz')->assertSuccessful();
        $this->assertSame(['U2'], $this->inTenant($tenant['org'], fn () => $row->fresh()->projected['team']));
    }

    /**
     * THE HOUSE RULE, and this command is the one most able to break it.
     * `docs/DEVELOPMENT-RULES.md`: a recurring sweep never overwrites a field a
     * person has touched.
     */
    public function test_a_second_run_creates_nothing_and_reverts_nothing(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $this->mirror($tenant);

        $this->artisan('ezbizi:project-workiz')->assertSuccessful();

        // A person fixes the name and the customer's own record of them.
        $this->inTenant($tenant['org'], function () {
            $customer = Customer::query()->first();
            $customer->name = 'Patricia Rivera-Okonkwo';
            $customer->phone = '+1 555 000 9999';
            $customer->save();
        });

        $this->artisan('ezbizi:project-workiz')->assertSuccessful();

        [$customers, $jobs, $customer] = $this->inTenant($tenant['org'], fn () => [
            Customer::query()->count(),
            Job::query()->count(),
            Customer::query()->first(),
        ]);

        $this->assertSame(1, $customers, 'a second run must not mint a second customer');
        $this->assertSame(1, $jobs);
        $this->assertSame('Patricia Rivera-Okonkwo', $customer->name, 'the correction must survive');
        $this->assertSame('+1 555 000 9999', $customer->phone);
    }

    /**
     * One person, several jobs, one customer. The client is derived by grouping
     * on (franchise, ClientId) — if that grouping broke, every job would mint
     * its own customer and the book would be unusable.
     */
    public function test_several_jobs_for_one_client_produce_one_customer(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $this->mirror($tenant, [], ['UUID' => 'JOB001', 'CreatedDate' => '2026-01-01 09:00:00']);
        $this->mirror($tenant, [], ['UUID' => 'JOB002', 'CreatedDate' => '2026-02-01 09:00:00']);
        $this->mirror($tenant, [], ['UUID' => 'JOB003', 'CreatedDate' => '2026-03-01 09:00:00']);

        $this->artisan('ezbizi:project-workiz')->assertSuccessful();

        $this->inTenant($tenant['org'], function () {
            $this->assertSame(1, Customer::query()->count());
            $this->assertSame(3, Job::query()->count());
            $this->assertSame(1, Job::query()->distinct()->count('customer_id'));
        });
    }

    /**
     * THE SAME ClientId IN TWO BRANCHES IS TWO PEOPLE.
     *
     * Measured on A-1's real data: 5,337 distinct ClientId values against 7,225
     * distinct (account, ClientId) pairs. Keyed on the integer alone this would
     * merge about 1,888 clients into strangers.
     */
    public function test_the_same_client_id_in_two_branches_is_two_customers(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $other = $this->extraFranchise($tenant, 'Connecticut');

        $this->mirror($tenant, [], ['UUID' => 'NJ0001']);
        $this->mirror($tenant, ['franchise_id' => $other->id], [
            'UUID' => 'CT0001', 'FirstName' => 'Different', 'LastName' => 'Person',
        ]);

        $this->artisan('ezbizi:project-workiz')->assertSuccessful();

        $this->inTenant($tenant['org'], function () {
            $this->assertSame(2, Customer::query()->count());
            $this->assertSame(2, WorkizClient::query()->count());
        });
    }

    /**
     * `jobs.customer_id` is NOT NULL, so a job whose client has not projected
     * is skipped rather than forced onto somebody.
     */
    public function test_a_job_is_not_forced_onto_a_customer_that_does_not_exist(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $this->mirror($tenant, ['client_id' => null]);

        $this->artisan('ezbizi:project-workiz')->assertSuccessful();

        $this->inTenant($tenant['org'], function () {
            $this->assertSame(0, Job::query()->count());
            $this->assertSame(0, Customer::query()->count());
        });
    }

    /**
     * The most recent job's contact block wins, and which one it was is
     * recorded — "latest wins" is otherwise an assertion nobody can check.
     */
    public function test_the_latest_jobs_contact_details_win(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $this->mirror($tenant, [], ['UUID' => 'OLD001', 'CreatedDate' => '2025-01-01 09:00:00', 'Email' => 'old@example.test']);
        $this->mirror($tenant, [], ['UUID' => 'NEW001', 'CreatedDate' => '2026-06-01 09:00:00', 'Email' => 'new@example.test']);

        $this->artisan('ezbizi:project-workiz')->assertSuccessful();

        $client = $this->inTenant($tenant['org'], fn () => WorkizClient::query()->first());

        $this->assertSame('new@example.test', $client->email);
        $this->assertSame('NEW001', $client->from_remote_job_id);
    }

    /** Give a mirror row a chosen id, so a test can say which one landed first. */
    private function rekey(WorkizJob $row, string $id): void
    {
        DB::table('workiz_jobs')->where('id', $row->id)->update(['id' => $id]);
    }

    /**
     * An older job that LANDS later — the history import's shape — must not
     * overwrite the newer job's contact block. Latest wins by date, not by the
     * order the rows happen to be read in.
     */
    public function test_an_older_job_landing_later_does_not_overwrite_the_newer_contact(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $new = $this->mirror($tenant, [], ['UUID' => 'NEW002', 'CreatedDate' => '2026-06-01 09:00:00', 'Email' => 'new@example.test']);
        $old = $this->mirror($tenant, [], ['UUID' => 'OLD002', 'CreatedDate' => '2025-01-01 09:00:00', 'Email' => 'old@example.test']);
        $this->rekey($new, '00000000-0000-7000-8000-000000000001');
        $this->rekey($old, '00000000-0000-7000-8000-000000000002');

        $this->artisan('ezbizi:project-workiz')->assertSuccessful();

        $client = $this->inTenant($tenant['org'], fn () => WorkizClient::query()->first());

        $this->assertSame('new@example.test', $client->email);
        $this->assertSame('NEW002', $client->from_remote_job_id);
    }

    public function test_an_undated_job_never_overwrites_a_dated_contact(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $dated = $this->mirror($tenant, [], ['UUID' => 'DATED1', 'CreatedDate' => '2026-06-01 09:00:00', 'Email' => 'dated@example.test']);
        $undated = $this->mirror($tenant, ['booked_at' => null], ['UUID' => 'UNDAT1', 'Email' => 'undated@example.test']);
        $this->rekey($dated, '00000000-0000-7000-8000-000000000001');
        $this->rekey($undated, '00000000-0000-7000-8000-000000000002');

        $this->artisan('ezbizi:project-workiz')->assertSuccessful();

        $this->assertSame('dated@example.test', $this->inTenant($tenant['org'], fn () => WorkizClient::query()->first())->email);
    }

    /**
     * EVERY mirrored job's client is derived, however the rows fall into pages.
     *
     * Production, 2026-09-24: ordering by booked_at under chunkById, which pages
     * by id, never visited 828 of 8,054 rows, so 148 jobs had no client and never
     * became jobs. Here: the row with the smallest id is the most recently
     * booked, behind a full page of older ones.
     */
    public function test_a_client_is_derived_from_every_mirrored_job_past_the_first_page(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $late = $this->mirror($tenant, [], ['UUID' => 'LATE01', 'ClientId' => 777, 'CreatedDate' => '2026-09-01 09:00:00']);
        $this->rekey($late, '00000000-0000-7000-8000-000000000000');

        for ($i = 1; $i <= 500; $i++) {
            $this->mirror($tenant, [], [
                'UUID' => sprintf('F%05d', $i),
                'ClientId' => 1,
                'CreatedDate' => Carbon::parse('2020-01-01 00:00:00')->addMinutes($i)->format('Y-m-d H:i:s'),
            ]);
        }

        $this->artisan('ezbizi:project-workiz', ['--limit' => 1])->assertSuccessful();

        $this->assertTrue(
            $this->inTenant($tenant['org'], fn () => WorkizClient::query()->where('client_id', 777)->exists()),
            'the client of the row behind the first page was never derived',
        );
    }

    /** A Scheduling service area, with its zips, in the tenant's franchise. */
    private function area(array $tenant, string $name, array $zips = []): ServiceArea
    {
        return $this->inTenant($tenant['org'], function () use ($tenant, $name, $zips) {
            $area = ServiceArea::create([
                'organization_id' => $tenant['org']->id,
                'franchise_id' => $tenant['franchise']->id,
                'name' => $name,
            ]);

            foreach ($zips as $zip) {
                ServiceAreaZip::create([
                    'organization_id' => $tenant['org']->id,
                    'franchise_id' => $tenant['franchise']->id,
                    'service_area_id' => $area->id,
                    'zip' => $zip,
                ]);
            }

            return $area;
        });
    }

    /** Workiz's own area name places the job (JobServiceArea's rule 1). */
    public function test_a_job_takes_its_service_area_from_workiz(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $area = $this->area($tenant, 'Connecticut');
        $this->mirror($tenant, ['service_area' => 'Connecticut']);

        $this->artisan('ezbizi:project-workiz')->assertSuccessful();

        $this->assertSame($area->id, $this->inTenant($tenant['org'], fn () => Job::query()->value('service_area_id')));
    }

    /**
     * With no area named, the JOB's own zip places it -- not the customer's,
     * which comes from the client's most recent job and can be another address.
     */
    public function test_a_job_is_placed_by_its_own_zip_not_the_customers(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $hartford = $this->area($tenant, 'Hartford', ['06457']);
        $this->area($tenant, 'Stamford', ['06901']);

        $this->mirror($tenant, ['service_area' => null, 'postal_code' => '6457'], ['UUID' => 'OLDJOB', 'CreatedDate' => '2025-01-01 09:00:00']);
        $this->mirror($tenant, ['service_area' => null, 'postal_code' => '06901'], ['UUID' => 'NEWJOB', 'CreatedDate' => '2026-06-01 09:00:00']);

        $this->artisan('ezbizi:project-workiz')->assertSuccessful();

        $placed = $this->inTenant($tenant['org'], fn () => Job::query()
            ->join('workiz_jobs', 'workiz_jobs.job_id', '=', 'jobs.id')
            ->where('workiz_jobs.remote_id', 'OLDJOB')
            ->value('jobs.service_area_id'));

        $this->assertSame($hartford->id, $placed);
    }

    /** Change what Workiz says about the mirrored job, as the next sync would. */
    private function workizSays(array $tenant, array $columns): void
    {
        $this->inTenant($tenant['org'], fn () => WorkizJob::query()->first()->forceFill($columns)->save());
    }

    private function areaOfTheJob(array $tenant): ?string
    {
        return $this->inTenant($tenant['org'], fn () => Job::query()->value('service_area_id'));
    }

    /** Workiz moves the job to another of the franchise's areas; EzBizi follows, silently. */
    public function test_a_new_workiz_area_moves_the_job(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $hartford = $this->area($tenant, 'Hartford');
        $stamford = $this->area($tenant, 'Stamford');
        $this->mirror($tenant, ['service_area' => 'Hartford']);
        $this->artisan('ezbizi:project-workiz')->assertSuccessful();
        $this->assertSame($hartford->id, $this->areaOfTheJob($tenant));

        $this->workizSays($tenant, ['service_area' => 'Stamford']);
        $this->artisan('ezbizi:project-workiz')->assertSuccessful();

        $this->assertSame($stamford->id, $this->areaOfTheJob($tenant));
        $this->assertSame(0, $this->inTenant($tenant['org'], fn () => AutomationEvent::query()->count()), 'an area move raises no event');
    }

    /** The zip is an input too: with no area named, a new zip re-places the job. */
    public function test_a_new_workiz_zip_moves_the_job(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $this->area($tenant, 'Hartford', ['06457']);
        $stamford = $this->area($tenant, 'Stamford', ['06901']);
        $this->mirror($tenant, ['service_area' => null, 'postal_code' => '06457']);
        $this->artisan('ezbizi:project-workiz')->assertSuccessful();

        $this->workizSays($tenant, ['postal_code' => '06901']);
        $this->artisan('ezbizi:project-workiz')->assertSuccessful();

        $this->assertSame($stamford->id, $this->areaOfTheJob($tenant));
    }

    /**
     * A PERSON'S CHOICE WINS. `service_area_chosen_at` is set whenever somebody
     * picks the area through the API, and nothing Workiz says moves it again.
     */
    public function test_an_area_a_person_chose_is_never_moved(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $hartford = $this->area($tenant, 'Hartford');
        $this->area($tenant, 'Stamford');
        $this->mirror($tenant, ['service_area' => 'Stamford']);
        $this->artisan('ezbizi:project-workiz')->assertSuccessful();

        $this->inTenant($tenant['org'], fn () => Job::query()->first()
            ->forceFill(['service_area_id' => $hartford->id, 'service_area_chosen_at' => now()])->save());

        $this->workizSays($tenant, ['service_area' => 'Stamford', 'postal_code' => '06901']);
        $this->artisan('ezbizi:project-workiz')->assertSuccessful();

        $this->assertSame($hartford->id, $this->areaOfTheJob($tenant));
    }

    /**
     * THE FIRST PASS ONLY RECORDS. A job projected before this change carries no
     * record of Workiz's area inputs, and its area may be the Jobs session's
     * backfill, which folded RETIRED names (East Connecticut) that resolve()
     * never will. Re-resolving it would put it back to none. So the first pass
     * records what Workiz says and moves nothing; the next change moves it.
     */
    public function test_the_first_pass_records_workizs_area_and_moves_nothing(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $connecticut = $this->area($tenant, 'Connecticut');
        $stamford = $this->area($tenant, 'Stamford');
        $this->mirror($tenant, ['service_area' => 'East Connecticut']);
        $this->artisan('ezbizi:project-workiz')->assertSuccessful();

        // As it stood before this change: projected without the inputs, and
        // the backfill's folded area on the job.
        $this->inTenant($tenant['org'], function () use ($connecticut) {
            $row = WorkizJob::query()->first();
            $row->projected = array_diff_key($row->projected, ['area_inputs' => true]);
            $row->save();
            Job::query()->first()->forceFill(['service_area_id' => $connecticut->id])->save();
        });

        $this->artisan('ezbizi:project-workiz')->assertSuccessful();
        $this->assertSame($connecticut->id, $this->areaOfTheJob($tenant), 'the backfill must survive the first pass');

        $this->workizSays($tenant, ['service_area' => 'Stamford']);
        $this->artisan('ezbizi:project-workiz')->assertSuccessful();
        $this->assertSame($stamford->id, $this->areaOfTheJob($tenant), 'and a real change after it still moves the job');
    }

    /** A Workiz technician in the tenant's branch, linked to $user when given. */
    private function technician(array $tenant, string $remoteId, string $name, ?User $user = null): WorkizTeamMember
    {
        return $this->inTenant($tenant['org'], fn () => WorkizTeamMember::create([
            'franchise_id' => $tenant['franchise']->id,
            'remote_id' => $remoteId,
            'name' => $name,
            'is_active' => true,
            'user_id' => $user?->id,
            'payload' => [],
            'synced_at' => now(),
        ]));
    }

    private function performerOfTheJob(array $tenant): ?string
    {
        return $this->inTenant($tenant['org'], fn () => Job::query()->value('performed_by_user_id'));
    }

    /**
     * The Field session's ask: arrival, routes and geocoding read
     * `performed_by_user_id`, and it was empty on all 8,685 jobs. Workiz's
     * first team member, through the technician link, fills it.
     */
    public function test_a_job_is_performed_by_its_linked_workiz_technician(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $moses = $this->userWithRole($tenant, 'Tech Moses');
        $this->technician($tenant, '367907', 'Moses Hai Zion', $moses);
        $this->mirror($tenant, [], ['Team' => [['id' => 367907, 'Name' => 'Moses Hai Zion']]]);

        $this->artisan('ezbizi:project-workiz')->assertSuccessful();

        $this->assertSame($moses->id, $this->performerOfTheJob($tenant));
    }

    /** A technician nobody has linked to a user places the job with nobody. */
    public function test_an_unlinked_technician_leaves_the_job_with_no_performer(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $this->technician($tenant, '367907', 'Moses Hai Zion');
        $this->mirror($tenant, [], ['Team' => [['id' => 367907, 'Name' => 'Moses Hai Zion']]]);

        $this->artisan('ezbizi:project-workiz')->assertSuccessful();

        $this->assertNull($this->performerOfTheJob($tenant));
    }

    public function test_a_reassignment_in_workiz_reaches_the_job(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $moses = $this->userWithRole($tenant, 'Tech Moses');
        $raz = $this->userWithRole($tenant, 'Tech Raz');
        $this->technician($tenant, '367907', 'Moses Hai Zion', $moses);
        $this->technician($tenant, '461281', 'Raz Eliezer', $raz);
        $row = $this->mirror($tenant, [], ['Team' => [['id' => 367907, 'Name' => 'Moses Hai Zion']]]);
        $this->artisan('ezbizi:project-workiz')->assertSuccessful();

        $this->inTenant($tenant['org'], fn () => $row->forceFill(['payload' => ['Team' => [['id' => 461281, 'Name' => 'Raz Eliezer']]] + $row->payload])->save());
        $this->artisan('ezbizi:project-workiz')->assertSuccessful();

        $this->assertSame($raz->id, $this->performerOfTheJob($tenant));
    }

    /** THE HOUSE RULE: a performer a person chose in EzBizi is never reverted. */
    public function test_a_performer_a_person_chose_is_never_reverted(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $moses = $this->userWithRole($tenant, 'Tech Moses');
        $raz = $this->userWithRole($tenant, 'Tech Raz');
        $kim = $this->userWithRole($tenant, 'Tech Kim');
        $this->technician($tenant, '367907', 'Moses Hai Zion', $moses);
        $this->technician($tenant, '461281', 'Raz Eliezer', $raz);
        $row = $this->mirror($tenant, [], ['Team' => [['id' => 367907, 'Name' => 'Moses Hai Zion']]]);
        $this->artisan('ezbizi:project-workiz')->assertSuccessful();

        $this->inTenant($tenant['org'], fn () => Job::query()->first()->forceFill(['performed_by_user_id' => $kim->id])->save());
        $this->inTenant($tenant['org'], fn () => $row->forceFill(['payload' => ['Team' => [['id' => 461281, 'Name' => 'Raz Eliezer']]] + $row->payload])->save());
        $this->artisan('ezbizi:project-workiz')->assertSuccessful();

        $this->assertSame($kim->id, $this->performerOfTheJob($tenant));
    }

    /**
     * Only 4 of 430 technicians were linked when this shipped. Linking one
     * later, in Scheduling > Settings > Schedule, reaches the jobs they
     * already hold.
     */
    public function test_linking_a_technician_later_reaches_the_jobs_they_hold(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $moses = $this->userWithRole($tenant, 'Tech Moses');
        $member = $this->technician($tenant, '367907', 'Moses Hai Zion');
        $this->mirror($tenant, [], ['Team' => [['id' => 367907, 'Name' => 'Moses Hai Zion']]]);
        $this->artisan('ezbizi:project-workiz')->assertSuccessful();
        $this->assertNull($this->performerOfTheJob($tenant));

        $this->inTenant($tenant['org'], fn () => $member->forceFill(['user_id' => $moses->id])->save());
        $this->artisan('ezbizi:project-workiz')->assertSuccessful();

        $this->assertSame($moses->id, $this->performerOfTheJob($tenant));
    }

    /**
     * A job projected before this change has no performer recorded as ours.
     * A value it already holds was put there by a person, and stays.
     */
    public function test_a_performer_set_before_this_change_is_kept(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $moses = $this->userWithRole($tenant, 'Tech Moses');
        $kim = $this->userWithRole($tenant, 'Tech Kim');
        $this->technician($tenant, '367907', 'Moses Hai Zion', $moses);
        $row = $this->mirror($tenant);
        $this->artisan('ezbizi:project-workiz')->assertSuccessful();

        $this->inTenant($tenant['org'], function () use ($row, $kim) {
            $row->refresh();
            $row->projected = array_diff_key($row->projected, ['performed_by_user_id' => true]);
            $row->payload = ['Team' => [['id' => 367907, 'Name' => 'Moses Hai Zion']]] + $row->payload;
            $row->save();
            Job::query()->first()->forceFill(['performed_by_user_id' => $kim->id])->save();
        });
        $this->artisan('ezbizi:project-workiz')->assertSuccessful();

        $this->assertSame($kim->id, $this->performerOfTheJob($tenant));
    }

    /**
     * Each branch is its own Workiz account with its own ids: a technician is
     * found in the JOB's branch only, never another one's with the same number.
     */
    public function test_a_technician_is_found_only_in_the_jobs_own_branch(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $other = $this->extraFranchise($tenant, 'Connecticut');
        $stranger = $this->userWithRole($tenant, 'Tech Elsewhere');
        $this->inTenant($tenant['org'], fn () => WorkizTeamMember::create([
            'franchise_id' => $other->id,
            'remote_id' => '367907',
            'name' => 'Someone Else',
            'is_active' => true,
            'user_id' => $stranger->id,
            'payload' => [],
            'synced_at' => now(),
        ]));
        $this->mirror($tenant, [], ['Team' => [['id' => 367907, 'Name' => 'Moses Hai Zion']]]);

        $this->artisan('ezbizi:project-workiz')->assertSuccessful();

        $this->assertNull($this->performerOfTheJob($tenant));
    }

    /**
     * EVERY MINUTE (Jose, 2026-09-28) needs a pass well under a minute, and
     * rebuilding all ~8,000 clients took most of the 80 seconds one took. Only a
     * mirror row landed since the last pass can change a client, so only
     * those are read. The clock is pinned: the rule is about seconds.
     */
    public function test_a_pass_reads_only_mirror_rows_written_since_the_last_one(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $this->travelTo(Carbon::parse('2026-09-28 10:00:00', 'UTC'));
        $row = $this->mirror($tenant, [], ['Email' => 'first@example.test']);

        $this->travelTo(Carbon::parse('2026-09-28 10:01:00', 'UTC'));
        $this->artisan('ezbizi:project-workiz')->assertSuccessful();

        // Changed behind the pass's back: synced_at still says 10:00.
        DB::table('workiz_jobs')->where('id', $row->id)->update([
            'payload' => json_encode(['Email' => 'second@example.test'] + $row->payload),
        ]);

        $this->travelTo(Carbon::parse('2026-09-28 10:02:00', 'UTC'));
        $this->artisan('ezbizi:project-workiz')->assertSuccessful();
        $email = fn () => $this->inTenant($tenant['org'], fn () => WorkizClient::query()->value('email'));
        $this->assertSame('first@example.test', $email(), 'a row untouched since the last pass is not read again');

        // Landed after that pass began: read.
        DB::table('workiz_jobs')->where('id', $row->id)->update(['synced_at' => '2026-09-28 10:02:30']);
        $this->travelTo(Carbon::parse('2026-09-28 10:03:00', 'UTC'));
        $this->artisan('ezbizi:project-workiz')->assertSuccessful();
        $this->assertSame('second@example.test', $email());

        $this->travelBack();
    }

    /** A dry run writes nothing -- including the watermark, or the next real pass would skip rows. */
    public function test_a_dry_run_does_not_move_the_watermark(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $this->travelTo(Carbon::parse('2026-09-28 10:00:00', 'UTC'));
        $row = $this->mirror($tenant, [], ['Email' => 'first@example.test']);
        $this->travelTo(Carbon::parse('2026-09-28 10:01:00', 'UTC'));
        $this->artisan('ezbizi:project-workiz')->assertSuccessful();

        DB::table('workiz_jobs')->where('id', $row->id)->update([
            'payload' => json_encode(['Email' => 'second@example.test'] + $row->payload),
            'synced_at' => '2026-09-28 10:01:30',
        ]);
        $this->travelTo(Carbon::parse('2026-09-28 10:02:00', 'UTC'));
        $this->artisan('ezbizi:project-workiz', ['--dry-run' => true])->assertSuccessful();
        $this->travelTo(Carbon::parse('2026-09-28 10:03:00', 'UTC'));
        $this->artisan('ezbizi:project-workiz')->assertSuccessful();

        $this->assertSame('second@example.test', $this->inTenant($tenant['org'], fn () => WorkizClient::query()->value('email')));
        $this->travelBack();
    }

    public function test_a_dry_run_writes_nothing(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $this->mirror($tenant);

        $this->artisan('ezbizi:project-workiz', ['--dry-run' => true])->assertSuccessful();

        $this->inTenant($tenant['org'], function () {
            $this->assertSame(0, Customer::query()->count());
            $this->assertSame(0, Job::query()->count());
            $this->assertSame(0, WorkizClient::query()->count());
        });
    }

    /**
     * Money arrives as dollars, integer or double. `(int)(1.15 * 100)` is 114.
     */
    public function test_a_price_becomes_minor_units_without_a_rounding_hole(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $this->mirror($tenant, [], ['JobTotalPrice' => 1.15]);

        $this->artisan('ezbizi:project-workiz')->assertSuccessful();

        $this->assertSame(115, $this->inTenant($tenant['org'], fn () => Job::query()->first())->amount_minor);
    }

    /**
     * THE BUG JOSE REPORTED: "all the jobs show as submitted, so the status
     * aren't updating".
     *
     * The projection used to be insert-only, so a job was frozen at the status
     * it had the moment it was first projected. Measured on production
     * 2026-09-24: 147 projected jobs already disagreed with the mirror, 63 of
     * them mirror "In progress" and shown "Submitted".
     */
    public function test_a_status_change_in_workiz_reaches_the_job(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $mirrored = $this->mirror($tenant);

        $this->artisan('ezbizi:project-workiz')->assertSuccessful();

        // The sweep refreshes the mirror with Workiz's new word.
        $this->inTenant($tenant['org'], function () use ($mirrored) {
            $mirrored->refresh();
            $mirrored->status = 'Done';
            $mirrored->save();
        });

        $this->artisan('ezbizi:project-workiz')->assertSuccessful();

        $this->assertSame('Done', $this->inTenant($tenant['org'], fn () => Job::query()->first())->status);
    }

    /**
     * AND THE HOUSE RULE STILL HOLDS: a status a PERSON set in EzBizi is never
     * reverted by the sweep. The job follows Workiz only while it still holds
     * what the projection last wrote into it.
     */
    public function test_a_status_a_person_changed_in_ezbizi_is_not_reverted(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $mirrored = $this->mirror($tenant);

        $this->artisan('ezbizi:project-workiz')->assertSuccessful();

        $this->inTenant($tenant['org'], function () {
            $job = Job::query()->first();
            $job->status = 'In progress';
            $job->save();
        });

        $this->inTenant($tenant['org'], function () use ($mirrored) {
            $mirrored->refresh();
            $mirrored->status = 'Canceled';
            $mirrored->save();
        });

        $this->artisan('ezbizi:project-workiz')->assertSuccessful();

        $this->assertSame(
            'In progress',
            $this->inTenant($tenant['org'], fn () => Job::query()->first())->status,
            'a choice made by a person must survive the sweep',
        );
    }

    /**
     * Same freeze, same fix, two more fields that change over a job's life: a
     * reschedule and the price, which Workiz fills in when the job is
     * invoiced — most imported jobs showed USD 0.00 because A1 Tools last saw
     * them before they were priced.
     */
    public function test_a_reschedule_and_a_price_change_reach_the_job(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $mirrored = $this->mirror($tenant);

        $this->artisan('ezbizi:project-workiz')->assertSuccessful();

        $this->inTenant($tenant['org'], function () use ($mirrored) {
            $mirrored->refresh();
            $mirrored->scheduled_for = '2026-10-02 13:30:00';
            $mirrored->payload = array_merge($mirrored->payload, ['JobTotalPrice' => 349.5]);
            $mirrored->save();
        });

        $this->artisan('ezbizi:project-workiz')->assertSuccessful();

        $job = $this->inTenant($tenant['org'], fn () => Job::query()->first());

        $this->assertSame('2026-10-02 13:30:00', $job->getRawOriginal('scheduled_for'));
        $this->assertSame(34950, $job->amount_minor);
    }

    /**
     * Which fields follow is decided PER FIELD. A person fixing the price must
     * not stop the status from following Workiz, and the other way round.
     */
    public function test_a_hand_edit_to_one_field_does_not_freeze_the_others(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $mirrored = $this->mirror($tenant);

        $this->artisan('ezbizi:project-workiz')->assertSuccessful();

        $this->inTenant($tenant['org'], function () {
            $job = Job::query()->first();
            $job->amount_minor = 99900;
            $job->save();
        });

        $this->inTenant($tenant['org'], function () use ($mirrored) {
            $mirrored->refresh();
            $mirrored->status = 'Done';
            $mirrored->payload = array_merge($mirrored->payload, ['JobTotalPrice' => 10]);
            $mirrored->save();
        });

        $this->artisan('ezbizi:project-workiz')->assertSuccessful();

        $job = $this->inTenant($tenant['org'], fn () => Job::query()->first());

        $this->assertSame('Done', $job->status, 'status still follows Workiz');
        $this->assertSame(99900, $job->amount_minor, 'the hand-set price is kept');
    }

    /**
     * A-1's real vocabulary, as the Jobs session wrote it to production on
     * 2026-09-24, plus an earned list. `EarnedStatuses::isEarned` only counts a
     * status the organization ALLOWS, so both settings are needed.
     *
     * @param  list<string>  $earned
     */
    private function workizVocabulary(array $tenant, array $earned): void
    {
        $this->inTenant($tenant['org'], function () use ($tenant, $earned) {
            $resolver = app(SettingsResolver::class);
            $resolver->set('jobs.statuses', $tenant['org'], array_map(
                fn (string $s) => ['value' => $s, 'label' => $s],
                ['Submitted', 'Pending', 'In progress', 'done pending approval', 'Done', 'Canceled'],
            ));
            $resolver->set('jobs.earned_statuses', $tenant['org'], $earned);
        });
    }

    private function inNewYork(array $tenant): void
    {
        $this->inTenant($tenant['org'], fn () => $tenant['franchise']->forceFill(['timezone' => 'America/New_York'])->save());
    }

    /**
     * WORKIZ-COMPLETED WORK MUST REACH REVENUE. The Jobs session's finding:
     * `earned_at` has one writer, JobsController, which only fires through the
     * API. A job the projection moved to Done got no stamp, and revenue reads
     * `whereNotNull('earned_at')`, so that money was invisible for ever.
     *
     * Stamped at the moment WORKIZ recorded, read in the branch's own zone —
     * `LastStatusUpdate` is wall time with no zone, and reading it as UTC moves
     * the stamp four or five hours and, at a month end, into the wrong month.
     */
    public function test_a_job_workiz_moves_into_an_earned_status_is_stamped_at_the_workiz_moment(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $this->inNewYork($tenant);
        $this->workizVocabulary($tenant, ['Done']);
        $mirrored = $this->mirror($tenant);

        $this->artisan('ezbizi:project-workiz')->assertSuccessful();

        $this->assertNull($this->inTenant($tenant['org'], fn () => Job::query()->first())->earned_at);

        $this->inTenant($tenant['org'], function () use ($mirrored) {
            $mirrored->refresh();
            $mirrored->status = 'Done';
            $mirrored->last_status_update = '2026-08-28 11:00:13';
            $mirrored->save();
        });

        $this->artisan('ezbizi:project-workiz')->assertSuccessful();

        $job = $this->inTenant($tenant['org'], fn () => Job::query()->first());

        $this->assertSame('Done', $job->status);
        $this->assertNotNull($job->earned_at);
        $this->assertSame(
            Carbon::parse('2026-08-28 11:00:13', 'America/New_York')->getTimestamp(),
            $job->earned_at->getTimestamp(),
            'the Workiz moment in the branch\'s zone, not now and not UTC',
        );
    }

    /**
     * THE 2,256 ALREADY SITTING AT DONE ARE NOT STAMPED BY A SYNC.
     *
     * Their status does not move, so nothing is stamped — otherwise every one
     * would be earned NOW and a year of revenue would land in this month. That
     * backfill is a one-time act the Jobs session owns, using this same method
     * with each job's own moment.
     */
    public function test_a_job_already_sitting_in_an_earned_status_is_not_stamped_now(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $this->inNewYork($tenant);
        $this->workizVocabulary($tenant, []);
        $this->mirror($tenant, [], ['Status' => 'Done']);
        $this->inTenant($tenant['org'], fn () => WorkizJob::query()->update(['status' => 'Done']));

        $this->artisan('ezbizi:project-workiz')->assertSuccessful();

        // Jose names Done as earned AFTER the job was imported.
        $this->workizVocabulary($tenant, ['Done']);

        $this->artisan('ezbizi:project-workiz')->assertSuccessful();

        $this->assertNull($this->inTenant($tenant['org'], fn () => Job::query()->first())->earned_at);
    }

    /**
     * A job that arrives already Done, once an earned status exists, is
     * stamped at its own Workiz moment — the same moment the Jobs session's
     * backfill will use, so both paths agree to the minute.
     */
    /**
     * Arriving already Done with NO Workiz moment stays unstamped: "now" would
     * file historical work under the current month. The Jobs session left the
     * 210 race duplicates unstamped for the same reason.
     */
    public function test_a_job_created_already_earned_with_no_workiz_moment_is_left_unstamped(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $this->workizVocabulary($tenant, ['Done']);
        $this->mirror($tenant, ['status' => 'Done', 'last_status_update' => null], ['Status' => 'Done']);

        $this->artisan('ezbizi:project-workiz')->assertSuccessful();

        $job = $this->inTenant($tenant['org'], fn () => Job::query()->first());

        $this->assertSame('Done', $job?->status);
        $this->assertNull($job->earned_at);
    }

    public function test_a_job_created_already_earned_is_stamped_at_its_workiz_moment(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $this->inNewYork($tenant);
        $this->workizVocabulary($tenant, ['Done']);
        $this->mirror($tenant, ['status' => 'Done', 'last_status_update' => '2026-07-04 16:30:00'], ['Status' => 'Done']);

        $this->artisan('ezbizi:project-workiz')->assertSuccessful();

        $job = $this->inTenant($tenant['org'], fn () => Job::query()->first());

        $this->assertSame(
            Carbon::parse('2026-07-04 16:30:00', 'America/New_York')->getTimestamp(),
            $job->earned_at?->getTimestamp(),
        );
    }

    /**
     * THE CASE THAT CAN ACTUALLY STAMP THE BACKLOG BY MISTAKE.
     *
     * A Done job that is not stamped is never a candidate on status alone —
     * mirror and job agree. But Workiz fills in the PRICE when a job is
     * invoiced, which makes an already-Done job a candidate on a different
     * field. Stamping on "this row changed" rather than "the status moved"
     * would earn it NOW, and a year of revenue would drift into this month
     * one invoice at a time.
     */
    public function test_a_price_change_on_an_already_earned_job_does_not_stamp_it(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $this->inNewYork($tenant);
        $this->workizVocabulary($tenant, []);
        $mirrored = $this->mirror($tenant, ['status' => 'Done'], ['Status' => 'Done']);

        $this->artisan('ezbizi:project-workiz')->assertSuccessful();

        $this->workizVocabulary($tenant, ['Done']);

        $this->inTenant($tenant['org'], function () use ($mirrored) {
            $mirrored->refresh();
            $mirrored->payload = array_merge($mirrored->payload, ['JobTotalPrice' => 425]);
            $mirrored->save();
        });

        $this->artisan('ezbizi:project-workiz')->assertSuccessful();

        $job = $this->inTenant($tenant['org'], fn () => Job::query()->first());

        $this->assertSame(42500, $job->amount_minor, 'the price still follows Workiz');
        $this->assertNull($job->earned_at, 'but a price is not a move into Done');
    }

    /**
     * @return Collection<int, AutomationEvent>
     */
    private function statusEvents(array $tenant)
    {
        return $this->inTenant($tenant['org'], fn () => AutomationEvent::query()
            ->where('event_key', 'job.status_changed')
            ->get());
    }

    /**
     * JOSE'S RULE NEEDS THIS: "when a job changes status to Done, then it would
     * send a message via txt". A-1 works in Workiz, so almost every real move to
     * Done arrives through this pass — and a write through the model emits
     * nothing, so without this the rule would never fire for A-1's actual work.
     *
     * And the event carries the customer's contact, because the send actions
     * take a recipient ONLY from the event (ActionRunner::valueFromEvent, on
     * purpose) — without it a rule has nobody to text.
     */
    public function test_a_recent_move_in_workiz_announces_the_status_change_with_the_customer(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $this->inNewYork($tenant);
        $mirrored = $this->mirror($tenant);

        $this->artisan('ezbizi:project-workiz')->assertSuccessful();

        $this->inTenant($tenant['org'], function () use ($mirrored) {
            $mirrored->refresh();
            $mirrored->status = 'Done';
            $mirrored->last_status_update = now('America/New_York')->subMinutes(10)->format('Y-m-d H:i:s');
            $mirrored->save();
        });

        $this->artisan('ezbizi:project-workiz')->assertSuccessful();

        $events = $this->statusEvents($tenant);
        $job = $this->inTenant($tenant['org'], fn () => Job::query()->first());

        $this->assertCount(1, $events);

        // JobsController's exact shape — nothing more. assertEquals, not
        // assertSame: MySQL JSON does not keep key order, so the semantic
        // comparison is the true one (docs/DEVELOPMENT-RULES.md).
        $this->assertEquals(
            ['before' => ['status' => 'Submitted'], 'after' => ['status' => 'Done']],
            $events->first()->payload,
        );

        // The JOB is the subject: that is how the Automations session's "The
        // customer it is about" recipient reaches the customer at run time.
        $this->assertSame(Job::class, $events->first()->subject_type);
        $this->assertSame($job->getKey(), $events->first()->subject_id);
    }

    /**
     * NO PERSONAL DATA IN THE EVENT. The Automations session's ruling:
     * `automation_events` is declared as holding none and erasure never visits
     * it, so a customer's number carried there would outlive the customer.
     * Pinned because an earlier draft of this very pass carried it.
     */
    public function test_the_announcement_carries_no_customer_contact(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $this->inNewYork($tenant);
        $mirrored = $this->mirror($tenant);

        $this->artisan('ezbizi:project-workiz')->assertSuccessful();

        $this->inTenant($tenant['org'], function () use ($mirrored) {
            $mirrored->refresh();
            $mirrored->status = 'Done';
            $mirrored->last_status_update = now('America/New_York')->subMinutes(10)->format('Y-m-d H:i:s');
            $mirrored->save();
        });

        $this->artisan('ezbizi:project-workiz')->assertSuccessful();

        $payload = json_encode($this->statusEvents($tenant)->first()->payload);

        $this->assertStringNotContainsString('5555550100', $payload);
        $this->assertStringNotContainsString('someone@example.test', $payload);
        $this->assertStringNotContainsString('Pat Rivera', $payload);
    }

    /**
     * JobsController's companion: `job.completed` on the way INTO an earned
     * status. Templates such as "Book next year's visit" listen on it, so a
     * Workiz job that skipped it would silently skip them too.
     */
    public function test_entering_an_earned_status_also_announces_the_completion(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $this->inNewYork($tenant);
        $this->workizVocabulary($tenant, ['Done']);
        $mirrored = $this->mirror($tenant);

        $this->artisan('ezbizi:project-workiz')->assertSuccessful();

        $this->inTenant($tenant['org'], function () use ($mirrored) {
            $mirrored->refresh();
            $mirrored->status = 'Done';
            $mirrored->last_status_update = now('America/New_York')->subMinutes(10)->format('Y-m-d H:i:s');
            $mirrored->save();
        });

        $this->artisan('ezbizi:project-workiz')->assertSuccessful();

        $completed = $this->inTenant($tenant['org'], fn () => AutomationEvent::query()
            ->where('event_key', 'job.completed')->get());

        $this->assertCount(1, $completed);
        $this->assertSame('Done', $completed->first()->payload['after']['status']);
    }

    /**
     * And not while nothing is earned — which is A1-Group today. Jose's rule
     * therefore hangs off `job.status_changed` with `after.status = Done`.
     */
    public function test_no_completion_is_announced_while_no_status_is_earned(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $this->inNewYork($tenant);
        $this->workizVocabulary($tenant, []);
        $mirrored = $this->mirror($tenant);

        $this->artisan('ezbizi:project-workiz')->assertSuccessful();

        $this->inTenant($tenant['org'], function () use ($mirrored) {
            $mirrored->refresh();
            $mirrored->status = 'Done';
            $mirrored->last_status_update = now('America/New_York')->subMinutes(10)->format('Y-m-d H:i:s');
            $mirrored->save();
        });

        $this->artisan('ezbizi:project-workiz')->assertSuccessful();

        $this->assertCount(1, $this->statusEvents($tenant), 'the status change is still announced');
        $this->assertSame(0, $this->inTenant($tenant['org'], fn () => AutomationEvent::query()
            ->where('event_key', 'job.completed')->count()));
    }

    /**
     * THE FLOOD THIS MUST NEVER CAUSE. 0.0.0264's catch-up re-reads about 1,300
     * past jobs whose status changed weeks or months ago. Announcing those would
     * text customers about work finished in August. Workiz's own clock says when
     * the change happened, and that — not when we noticed — decides.
     */
    public function test_a_move_workiz_made_long_ago_is_followed_but_not_announced(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $this->inNewYork($tenant);
        $mirrored = $this->mirror($tenant);

        $this->artisan('ezbizi:project-workiz')->assertSuccessful();

        $this->inTenant($tenant['org'], function () use ($mirrored) {
            $mirrored->refresh();
            $mirrored->status = 'Done';
            $mirrored->last_status_update = '2026-08-28 11:00:13';
            $mirrored->save();
        });

        $this->artisan('ezbizi:project-workiz')->assertSuccessful();

        $this->assertSame('Done', $this->inTenant($tenant['org'], fn () => Job::query()->first())->status, 'still followed');
        $this->assertCount(0, $this->statusEvents($tenant), 'but nobody is told');
    }

    /**
     * No Workiz moment, no announcement. When it cannot say WHEN the change
     * happened, the safe answer is silence: a text that should have gone and
     * did not is recoverable, one about a months-old job is not.
     */
    public function test_a_move_with_no_workiz_moment_is_not_announced(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $mirrored = $this->mirror($tenant);

        $this->artisan('ezbizi:project-workiz')->assertSuccessful();

        $this->inTenant($tenant['org'], function () use ($mirrored) {
            $mirrored->refresh();
            $mirrored->status = 'Done';
            $mirrored->last_status_update = null;
            $mirrored->save();
        });

        $this->artisan('ezbizi:project-workiz')->assertSuccessful();

        $this->assertCount(0, $this->statusEvents($tenant));
    }

    /**
     * A price or a date moving is not a status change, and says nothing.
     */
    public function test_a_price_change_is_not_announced_as_a_status_change(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $mirrored = $this->mirror($tenant);

        $this->artisan('ezbizi:project-workiz')->assertSuccessful();

        $this->inTenant($tenant['org'], function () use ($mirrored) {
            $mirrored->refresh();
            $mirrored->last_status_update = now()->format('Y-m-d H:i:s');
            $mirrored->payload = array_merge($mirrored->payload, ['JobTotalPrice' => 99]);
            $mirrored->save();
        });

        $this->artisan('ezbizi:project-workiz')->assertSuccessful();

        $this->assertCount(0, $this->statusEvents($tenant));
    }

    /**
     * THE SECOND NET, the Automations session's ruling: a pass that finds more
     * recent moves than any real hour produces is a backfill, and announces
     * NONE rather than a subset — a partial flood is worse than none, because
     * nobody can tell which customers were texted.
     */
    /** Automations, 2026-11-03: a recent Workiz tag move announces job.tags_changed; an old one or a burst over the limit (a backfill) does not. */
    public function test_a_workiz_tag_move_is_announced_only_when_recent_and_not_a_backfill(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $this->inNewYork($tenant);
        $rows = array_map(fn ($u) => $this->mirror($tenant, [], ['UUID' => $u, 'Tags' => ['Old']]), ['JOB001', 'JOB002', 'JOB003']);
        $this->artisan('ezbizi:project-workiz')->assertSuccessful();
        $tagEvents = fn () => $this->inTenant($tenant['org'], fn () => AutomationEvent::query()->where('event_key', 'job.tags_changed')->get());
        $move = function (WorkizJob $row, array $tags, string $when) use ($tenant) {
            $this->inTenant($tenant['org'], fn () => $row->fresh()->forceFill(['payload' => ['Tags' => $tags] + $row->fresh()->payload, 'last_status_update' => $when])->save());
        };
        $recent = now('America/New_York')->subMinutes(5)->format('Y-m-d H:i:s');

        $move($rows[0], ['Old', 'VIP'], '2026-08-28 11:00:13');
        $this->artisan('ezbizi:project-workiz')->assertSuccessful();
        $this->assertCount(0, $tagEvents(), 'an old move is followed silently');

        $move($rows[1], ['Old', 'VIP'], $recent);
        $this->artisan('ezbizi:project-workiz')->assertSuccessful();
        $this->assertEquals(['before' => ['tags' => ['Old']], 'after' => ['tags' => ['Old', 'VIP']]], $tagEvents()->sole()->payload);

        $move($rows[0], ['New'], $recent);
        $move($rows[1], ['New'], $recent);
        $move($rows[2], ['New'], $recent);
        $this->artisan('ezbizi:project-workiz', ['--announce-limit' => 2])->assertSuccessful();
        $this->assertCount(1, $tagEvents(), 'three at once over a limit of two is a backfill');
    }

    public function test_a_burst_of_recent_moves_beyond_the_limit_announces_nothing(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $this->inNewYork($tenant);

        $rows = [];
        foreach (['JOB001', 'JOB002', 'JOB003'] as $uuid) {
            $rows[] = $this->mirror($tenant, [], ['UUID' => $uuid]);
        }

        $this->artisan('ezbizi:project-workiz')->assertSuccessful();

        $this->inTenant($tenant['org'], function () use ($rows) {
            foreach ($rows as $mirrored) {
                $mirrored->refresh();
                $mirrored->status = 'Done';
                $mirrored->last_status_update = now('America/New_York')->subMinutes(5)->format('Y-m-d H:i:s');
                $mirrored->save();
            }
        });

        $this->artisan('ezbizi:project-workiz', ['--announce-limit' => 2])
            ->expectsOutputToContain('backfill')
            ->assertSuccessful();

        $this->assertCount(0, $this->statusEvents($tenant));
        $this->assertSame(3, $this->inTenant($tenant['org'], fn () => Job::query()->where('status', 'Done')->count()), 'the jobs still follow');
    }

    /**
     * THE RACE THAT MADE 432 DUPLICATE JOBS AND 500 DUPLICATE CUSTOMERS.
     *
     * 2026-09-23 20:37 on production: the SCHEDULED run (limit 500) and a
     * MANUAL run (--limit=10000, projecting the history import) overlapped.
     * The schedule's withoutOverlapping() only guards scheduled invocations,
     * and this command claimed rows with no lock of its own — both read the
     * same unprojected rows and both created a job for each. Measured from the
     * two `customer.imported` audit rows two seconds apart.
     *
     * So the command takes its own lock, whoever invokes it, and a second run
     * while one is in progress does nothing at all.
     */
    public function test_a_run_while_another_is_in_progress_creates_nothing(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $this->mirror($tenant);

        $held = Cache::lock(ProjectWorkizRecords::LOCK, 60);
        $this->assertTrue($held->get(), 'the test must be able to hold the lock or it proves nothing');

        try {
            $this->artisan('ezbizi:project-workiz')
                ->expectsOutputToContain('already running')
                ->assertSuccessful();
        } finally {
            $held->release();
        }

        $this->inTenant($tenant['org'], function () {
            $this->assertSame(0, Customer::query()->count());
            $this->assertSame(0, Job::query()->count());
        });

        // And the lock is free again afterwards: the next run proceeds.
        $this->artisan('ezbizi:project-workiz')->assertSuccessful();
        $this->assertSame(1, $this->inTenant($tenant['org'], fn () => Job::query()->count()));
    }

    /**
     * Workiz required an email and a phone, so dispatch typed placeholders. As
     * a customer's contact they reach a stranger: Customers' lists, applied at
     * the one place a Workiz client becomes a customer.
     */
    public function test_a_placeholder_email_or_phone_never_reaches_the_customer(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $this->mirror($tenant, [], ['Email' => ' NM@gmail.com', 'Phone' => '8187482055']);

        $this->artisan('ezbizi:project-workiz')->assertSuccessful();

        $customer = $this->inTenant($tenant['org'], fn () => Customer::query()->sole());
        $this->assertNull($customer->email);
        $this->assertNull($customer->phone);
    }

    /**
     * When the client first booked: their earliest Workiz job's created date.
     * Without it the dashboard counts every customer the projection makes on
     * catch-up as new that day (Customers, b4606536).
     */
    public function test_a_projected_customer_is_a_customer_since_their_first_workiz_booking(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $this->mirror($tenant, ['remote_id' => 'LATER', 'booked_at' => '2026-01-10 08:00:00'], ['UUID' => 'LATER']);
        $this->mirror($tenant, ['remote_id' => 'FIRST', 'booked_at' => '2025-03-02 22:30:00'], ['UUID' => 'FIRST']);

        $this->artisan('ezbizi:project-workiz')->assertSuccessful();

        $customer = $this->inTenant($tenant['org'], fn () => Customer::query()->sole());
        $this->assertSame('2025-03-02', $customer->customer_since?->toDateString());
    }

    // ── adoption: a Workiz client EzBizi already holds as a customer ──

    /** Pat Rivera, as the fixture's job carries them: 5555550100, 1 Example Street, 07030. */
    private function pat(array $tenant, string $branchId, array $overrides = []): Customer
    {
        return $this->inTenant($tenant['org'], fn () => Customer::create(array_merge([
            'owning_franchise_id' => $branchId, 'number' => 'C-'.Str::random(6), 'name' => 'Pat  rivera',
            'phone' => '+15555550100', 'address_line1' => '1 Example Street', 'postal_code' => '07030', 'is_active' => true,
        ], $overrides)));
    }

    /**
     * Massachusetts got its own Workiz account on 2026-10-05: new client
     * numbers for people EzBizi holds through Nationwide's. Each is attached to
     * the customer it is, in whatever branch, and nothing about that customer
     * moves -- re-homing is Customers' step.
     */
    public function test_a_client_ezbizi_already_holds_is_adopted_not_created_again(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $nationwide = $this->extraFranchise($tenant, 'Nationwide');
        $pat = $this->pat($tenant, $nationwide->id);
        $this->mirror($tenant);

        $this->artisan('ezbizi:project-workiz')->assertSuccessful();

        $this->inTenant($tenant['org'], function () use ($pat, $nationwide) {
            $this->assertSame(1, Customer::query()->count(), 'no twin');
            $this->assertSame($pat->id, WorkizClient::query()->sole()->customer_id);
            $this->assertSame($pat->id, Job::query()->sole()->customer_id, 'the job is theirs');
            $this->assertSame($nationwide->id, $pat->fresh()->owning_franchise_id, 'ownership untouched');

            $audit = DB::table('audit_events')->where('action', 'customer.workiz_clients_adopted')->sole();
            $this->assertSame($pat->id, json_decode($audit->metadata, true)['adopted'][0]['customer_id']);
        });
    }

    /** The booker at another house, and the placeholder address staff share, are not the same person. */
    /** Customers, 11-04: the placeholder streets are the org's own list, not a constant. */
    public function test_a_street_the_org_lists_as_a_placeholder_is_never_matched(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $this->pat($tenant, $tenant['franchise']->id);
        $this->inTenant($tenant['org'], fn () => \App\Models\BusinessListItem::create(['organization_id' => $tenant['org']->id,
            'list_key' => 'customers.placeholder_streets', 'item_key' => '1 Example Street', 'value' => [], 'sort' => 0, 'active' => true]));
        $this->mirror($tenant);

        $this->artisan('ezbizi:project-workiz')->assertSuccessful();

        $this->assertSame(2, $this->inTenant($tenant['org'], fn () => Customer::query()->count()), 'created, not adopted at the listed street');
    }

    public function test_another_postal_code_or_a_placeholder_street_is_a_new_customer(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $nationwide = $this->extraFranchise($tenant, 'Nationwide');
        $this->pat($tenant, $nationwide->id, ['postal_code' => '10001']);
        $this->pat($tenant, $nationwide->id, ['address_line1' => '800 SE 4th Ave']);
        $home = $this->pat($tenant, $nationwide->id);
        // At home: the one real match. At the placeholder: nobody, though phone, name and zip agree.
        $this->mirror($tenant);
        $this->mirror($tenant, ['remote_id' => 'PH0001', 'client_id' => 2002, 'address' => '800 Southeast 4th Avenue'],
            ['UUID' => 'PH0001', 'ClientId' => 2002, 'Address' => '800 Southeast 4th Avenue']);

        $this->artisan('ezbizi:project-workiz')->assertSuccessful();

        $this->inTenant($tenant['org'], function () use ($home) {
            $this->assertSame($home->id, WorkizClient::query()->where('client_id', 1002)->value('customer_id'));
            $this->assertSame(4, Customer::query()->count(), 'one created, for the placeholder');
        });
    }

    /** Several matches: the one in the client's own branch, when exactly one is; otherwise created, and listed. */
    public function test_several_matches_adopt_the_one_in_the_clients_own_branch_or_none(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $own = $this->pat($tenant, $tenant['franchise']->id);
        $this->pat($tenant, $this->extraFranchise($tenant, 'Nationwide')->id);
        $this->mirror($tenant);

        $this->artisan('ezbizi:project-workiz')->assertSuccessful();
        $this->inTenant($tenant['org'], fn () => $this->assertSame($own->id, WorkizClient::query()->sole()->customer_id));

        $elsewhere = $this->provisionTenant('Beta');
        $this->pat($elsewhere, $this->extraFranchise($elsewhere, 'Ohio')->id);
        $this->pat($elsewhere, $this->extraFranchise($elsewhere, 'Texas')->id);
        $this->mirror($elsewhere, ['connection_id' => (string) Str::uuid()]);

        $this->artisan('ezbizi:project-workiz')->assertSuccessful();

        $this->inTenant($elsewhere['org'], function () use ($elsewhere) {
            $this->assertSame(3, Customer::query()->count(), 'neither is chosen');
            $listed = json_decode(DB::table('audit_events')->where('organization_id', $elsewhere['org']->id)->where('action', 'customer.workiz_clients_adopted')->value('metadata'), true);
            $this->assertCount(2, $listed['matched_several'][0]['customers']);
        });
    }

    /**
     * Customers' points, 2026-10-05: a zip is its first five digits, so ZIP+4 is
     * the same place; a phone is ten national digits, whichever of the two
     * shapes `customers.phone` holds (12 legacy rows are bare ten digits).
     */
    public function test_zip_plus_four_and_a_bare_ten_digit_phone_are_the_same_person(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $pat = $this->pat($tenant, $this->extraFranchise($tenant, 'Nationwide')->id, ['phone' => '5555550100']);
        $this->mirror($tenant, ['postal_code' => '07030-1234'], ['PostalCode' => '07030-1234']);

        $this->artisan('ezbizi:project-workiz')->assertSuccessful();

        $this->inTenant($tenant['org'], fn () => $this->assertSame($pat->id, WorkizClient::query()->sole()->customer_id));
    }

    /** Every Massachusetts zip starts with a 0 a spreadsheet can drop: four digits are listed, never padded into a guess. */
    public function test_a_zip_too_short_to_read_is_listed_not_guessed(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $pat = $this->pat($tenant, $this->extraFranchise($tenant, 'Nationwide')->id);
        $this->mirror($tenant, ['postal_code' => '7030'], ['PostalCode' => '7030']);

        $this->artisan('ezbizi:project-workiz')->assertSuccessful();

        $this->inTenant($tenant['org'], function () use ($tenant, $pat) {
            $this->assertSame(2, Customer::query()->count(), 'created, as before');
            $listed = json_decode(DB::table('audit_events')->where('organization_id', $tenant['org']->id)->where('action', 'customer.workiz_clients_adopted')->value('metadata'), true);
            $this->assertSame([$pat->id], $listed['zip_unreadable'][0]['customers']);
        });
    }
}
