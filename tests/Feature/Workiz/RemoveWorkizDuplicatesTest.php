<?php

namespace Tests\Feature\Workiz;

use App\Models\Customer;
use App\Models\Job;
use App\Models\WorkizClient;
use App\Models\WorkizJob;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/**
 * Removing what the 2026-09-23 race left behind, and nothing else.
 *
 * Two overlapping projection runs created 432 duplicate jobs and 500 duplicate
 * customers. Every one had a surviving twin — same customer and appointment for
 * a job, same branch, name and phone for a customer — and nothing attached. Most
 * of these tests are about what the command must LEAVE ALONE, because a
 * deletion is the one mistake here that cannot be seen afterwards.
 */
class RemoveWorkizDuplicatesTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    /**
     * A properly projected customer and job, linked from the mirror.
     *
     * @return array{0: Customer, 1: Job}
     */
    private function projected(array $tenant, string $remote, array $job = []): array
    {
        return $this->inTenant($tenant['org'], function () use ($tenant, $remote, $job) {
            $branch = $tenant['franchise'];

            $customer = Customer::create([
                'owning_franchise_id' => $branch->id,
                'number' => 'C-'.Str::random(6),
                'name' => 'Pat Rivera',
                'phone' => '+15555550100',
            ]);

            WorkizClient::create([
                'franchise_id' => $branch->id,
                'client_id' => random_int(1000, 999999),
                'customer_id' => $customer->id,
                'synced_at' => now(),
            ]);

            $made = Job::create(array_merge([
                'franchise_id' => $branch->id,
                'customer_id' => $customer->id,
                'number' => 'J-'.Str::random(6),
                'title' => 'Chimney Inspection',
                'status' => 'Done',
                'scheduled_for' => '2026-05-20 11:00:00',
                'amount_minor' => 208200,
                'currency' => 'USD',
            ], $job));

            WorkizJob::create([
                'franchise_id' => $branch->id,
                'remote_id' => $remote,
                'status' => $made->status,
                'job_id' => $made->id,
                'payload' => [],
                'synced_at' => now(),
            ]);

            return [$customer, $made];
        });
    }

    /** A job exactly as the race left it: same work, no mirror row pointing at it. */
    private function duplicateOf(array $tenant, Job $twin, array $overrides = []): Job
    {
        return $this->inTenant($tenant['org'], fn () => Job::create(array_merge([
            'franchise_id' => $twin->franchise_id,
            'customer_id' => $twin->customer_id,
            'number' => 'J-'.Str::random(6),
            'title' => $twin->title,
            'status' => $twin->status,
            'scheduled_for' => $twin->getRawOriginal('scheduled_for'),
            'amount_minor' => $twin->amount_minor,
            'currency' => 'USD',
        ], $overrides)));
    }

    private function duplicateCustomerOf(array $tenant, Customer $twin): Customer
    {
        return $this->inTenant($tenant['org'], fn () => Customer::create([
            'owning_franchise_id' => $twin->owning_franchise_id,
            'number' => 'C-'.Str::random(6),
            'name' => $twin->name,
            'phone' => $twin->phone,
        ]));
    }

    private function alive(array $tenant, string $class, string $id): bool
    {
        return $this->inTenant($tenant['org'], fn () => $class::query()->whereKey($id)->exists());
    }

    public function test_a_duplicate_job_is_removed_and_its_linked_twin_kept(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        [, $twin] = $this->projected($tenant, 'CT0001');
        $duplicate = $this->duplicateOf($tenant, $twin);

        $this->artisan('ezbizi:workiz-remove-duplicates')->assertSuccessful();

        $this->assertFalse($this->alive($tenant, Job::class, $duplicate->id));
        $this->assertTrue($this->alive($tenant, Job::class, $twin->id));

        // Soft-deleted, so it is reversible.
        $this->assertTrue($this->inTenant($tenant['org'], fn () => Job::withTrashed()->whereKey($duplicate->id)->exists()));
    }

    /**
     * Ten of the 432 no longer matched their twin exactly: 0.0.0264's follow
     * pass had moved the LINKED copy's price or status, and left the unlinked
     * duplicate frozen. Same customer, appointment and title is the twin.
     */
    public function test_a_duplicate_whose_twin_has_since_followed_workiz_is_still_removed(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        [, $twin] = $this->projected($tenant, 'CT0002', ['status' => 'Done', 'amount_minor' => 350000]);
        $duplicate = $this->duplicateOf($tenant, $twin, ['status' => 'done pending approval', 'amount_minor' => 0]);

        $this->artisan('ezbizi:workiz-remove-duplicates')->assertSuccessful();

        $this->assertFalse($this->alive($tenant, Job::class, $duplicate->id));
    }

    /** An unlinked job with no twin might be the only copy. Never guessed at. */
    public function test_an_unlinked_job_with_no_twin_is_kept(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        [, $twin] = $this->projected($tenant, 'CT0003');
        $lonely = $this->duplicateOf($tenant, $twin, ['scheduled_for' => '2026-06-01 09:00:00']);

        $this->artisan('ezbizi:workiz-remove-duplicates')->assertSuccessful();

        $this->assertTrue($this->alive($tenant, Job::class, $lonely->id));
    }

    /**
     * A job a PERSON made is never removed, even if it looks exactly like a
     * Workiz job — somebody may have typed the same work in by hand, and that
     * is theirs. The API audits every job it creates as `job.created`.
     */
    public function test_a_job_a_person_made_is_never_removed(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        [, $twin] = $this->projected($tenant, 'CT0004');
        $byHand = $this->duplicateOf($tenant, $twin);

        $this->inTenant($tenant['org'], fn () => DB::table('audit_events')->insert([
            'id' => (string) Str::uuid(),
            'organization_id' => $tenant['org']->id,
            'actor_type' => 'user',
            'action' => 'job.created',
            'target_type' => Job::class,
            'target_id' => $byHand->id,
            'occurred_at' => now(),
        ]));

        $this->artisan('ezbizi:workiz-remove-duplicates')->assertSuccessful();

        $this->assertTrue($this->alive($tenant, Job::class, $byHand->id));
    }

    /** Something hanging off it — a photo, a line — means a person used it. */
    public function test_a_duplicate_with_an_attachment_is_kept(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        [, $twin] = $this->projected($tenant, 'CT0005');
        $duplicate = $this->duplicateOf($tenant, $twin);

        $this->inTenant($tenant['org'], fn () => DB::table('attachments')->insert([
            'id' => (string) Str::uuid(),
            'organization_id' => $tenant['org']->id,
            'attachable_type' => Job::class,
            'attachable_id' => $duplicate->id,
            'disk' => 'local',
            'path' => 'x/y.jpg',
            'original_name' => 'y.jpg',
            'mime' => 'image/jpeg',
            'size_bytes' => 1,
            'checksum' => str_repeat('0', 64),
            'created_at' => now(),
            'updated_at' => now(),
        ]));

        $this->artisan('ezbizi:workiz-remove-duplicates')->assertSuccessful();

        $this->assertTrue($this->alive($tenant, Job::class, $duplicate->id));
    }

    /**
     * Not only attachments: any table naming it, found in the schema — here a
     * morph pair the command has no list entry for. The next table to point at
     * a job (a Photos pane, say) protects it the same way.
     */
    public function test_a_duplicate_any_other_table_names_is_kept(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        [, $twin] = $this->projected($tenant, 'CT0010');
        $duplicate = $this->duplicateOf($tenant, $twin);

        $this->inTenant($tenant['org'], fn () => DB::table('automation_events')->insert([
            'id' => (string) Str::uuid(),
            'organization_id' => $tenant['org']->id,
            'event_key' => 'job.status_changed',
            'subject_type' => 'jobs',
            'subject_id' => $duplicate->id,
            'occurred_at' => now(),
            'available_at' => now(),
        ]));

        $this->artisan('ezbizi:workiz-remove-duplicates')->assertSuccessful();

        $this->assertTrue($this->alive($tenant, Job::class, $duplicate->id));
    }

    public function test_a_duplicate_customer_with_no_jobs_is_removed(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        [$twin] = $this->projected($tenant, 'CT0006');
        $duplicate = $this->duplicateCustomerOf($tenant, $twin);

        $this->artisan('ezbizi:workiz-remove-duplicates')->assertSuccessful();

        $this->assertFalse($this->alive($tenant, Customer::class, $duplicate->id));
        $this->assertTrue($this->alive($tenant, Customer::class, $twin->id));
    }

    /** A customer somebody's work hangs off is a customer, whatever it looks like. */
    public function test_a_duplicate_customer_that_has_jobs_is_kept(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        [$twin, $job] = $this->projected($tenant, 'CT0007');
        $duplicate = $this->duplicateCustomerOf($tenant, $twin);
        $this->duplicateOf($tenant, $job, ['customer_id' => $duplicate->id, 'scheduled_for' => '2026-07-01 09:00:00']);

        $this->artisan('ezbizi:workiz-remove-duplicates')->assertSuccessful();

        $this->assertTrue($this->alive($tenant, Customer::class, $duplicate->id));
    }

    /** A deleted job still names its customer; restoring the job must find it. */
    public function test_a_duplicate_customer_whose_only_job_was_deleted_is_kept(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        [$twin, $job] = $this->projected($tenant, 'CT0011');
        $duplicate = $this->duplicateCustomerOf($tenant, $twin);
        $deleted = $this->duplicateOf($tenant, $job, ['customer_id' => $duplicate->id, 'scheduled_for' => '2026-07-01 09:00:00']);
        $this->inTenant($tenant['org'], fn () => $deleted->delete());

        $this->artisan('ezbizi:workiz-remove-duplicates')->assertSuccessful();

        $this->assertTrue($this->alive($tenant, Customer::class, $duplicate->id));
    }

    public function test_a_dry_run_removes_nothing_and_says_what_it_would(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        [$twinCustomer, $twin] = $this->projected($tenant, 'CT0008');
        $duplicate = $this->duplicateOf($tenant, $twin);
        $duplicateCustomer = $this->duplicateCustomerOf($tenant, $twinCustomer);

        $this->artisan('ezbizi:workiz-remove-duplicates', ['--dry-run' => true])
            ->expectsOutputToContain('1 duplicate jobs')
            ->expectsOutputToContain('1 duplicate customers')
            ->assertSuccessful();

        $this->assertTrue($this->alive($tenant, Job::class, $duplicate->id));
        $this->assertTrue($this->alive($tenant, Customer::class, $duplicateCustomer->id));
    }

    public function test_one_audit_row_records_what_was_removed(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        [, $twin] = $this->projected($tenant, 'CT0009');
        $this->duplicateOf($tenant, $twin);

        $this->artisan('ezbizi:workiz-remove-duplicates')->assertSuccessful();

        $rows = $this->inTenant($tenant['org'], fn () => DB::table('audit_events')->where('action', 'workiz.duplicates_removed')->get());

        $this->assertCount(1, $rows);
    }
}
