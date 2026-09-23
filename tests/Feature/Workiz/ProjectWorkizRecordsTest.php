<?php

namespace Tests\Feature\Workiz;

use App\Models\Customer;
use App\Models\Job;
use App\Models\WorkizClient;
use App\Models\WorkizJob;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
}
