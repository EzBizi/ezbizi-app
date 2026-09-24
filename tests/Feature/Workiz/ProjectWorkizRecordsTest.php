<?php

namespace Tests\Feature\Workiz;

use App\Models\Customer;
use App\Models\Job;
use App\Models\WorkizClient;
use App\Models\WorkizJob;
use App\Support\Settings\SettingsResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
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
}
