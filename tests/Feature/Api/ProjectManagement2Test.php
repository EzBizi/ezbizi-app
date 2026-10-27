<?php

namespace Tests\Feature\Api;

use App\Models\Customer;
use App\Models\Job;
use App\Models\WorkizJob;
use App\Models\WorkizRecord;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/** Project Management - 2 (Jose, 2026-10-26): the jobs done pending approval, in his columns. */
class ProjectManagement2Test extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    public function test_lists_only_done_pending_approval_jobs_with_money_for_financials(): void
    {
        $alpha = $this->provisionTenant('Alpha');
        $beta = $this->provisionTenant('Beta');

        $this->inTenant($beta['org'], fn () => $this->job($beta, 'B-1', 'done pending approval'));
        $id = $this->inTenant($alpha['org'], function () use ($alpha) {
            $this->job($alpha, 'J-2', 'Done');

            $id = $this->job($alpha, 'J-1', 'Done Pending Approval', ['JobTotalPrice' => 0, 'JobAmountDue' => 900.5, 'CreatedDate' => '2026-09-01 10:00:00']);
            // The latest estimate still in play is what waits on approval.
            foreach ([['E1', '300.00', 'Pending', '2026-09-02'], ['E2', '450.00', null, '2026-09-03'], ['E3', '999.00', 'Declined', '2026-09-04']] as [$remote, $amount, $status, $at]) {
                $this->record($alpha, $id, 'estimate', $remote, $amount, $status, $at);
            }
            $this->record($alpha, $id, 'payment', 'P1', '100.00', 'succeeded', '2026-09-05');
            $this->record($alpha, $id, 'payment', 'P2', '50.00', 'failed', '2026-09-05');

            return $id;
        });

        $rows = $this->signedInAs($alpha['admin'])->getJson('/api/v1/project-management-2/jobs')->assertOk()->json('data');

        $this->assertCount(1, $rows, 'Done and another tenant are left out');
        $this->assertSame([
            'id' => $id, 'customer' => 'Ann Smith', 'branch' => 'Alpha F1', 'company' => 'Alpha Co', 'tags' => ['DEPOSIT'],
            'address' => '1 Main St, Columbus, OH 43004', 'created' => '2026-09-01', 'modified' => '2026-10-20',
            'pending' => 35000, 'paid' => 10000, 'total' => 45000, 'currency' => 'USD',
        ], array_diff_key($rows[0], ['status' => 1, 'job_number' => 1]));
        $this->assertEquals('7001', $rows[0]['job_number'], "Workiz's job number");
        $this->assertNotEmpty($rows[0]['status']);
    }

    public function test_money_is_absent_without_financials_and_the_module_is_required(): void
    {
        $alpha = $this->provisionTenant('Alpha');
        $this->inTenant($alpha['org'], fn () => $this->job($alpha, 'J-1', 'done pending approval', ['JobTotalPrice' => 1200, 'JobAmountDue' => 900]));

        $row = $this->signedInAs($this->userWithRole($alpha, 'PM2', ['jobs.view', 'project_management_2.view']))
            ->getJson('/api/v1/project-management-2/jobs')->assertOk()->json('data.0');
        foreach (['pending', 'paid', 'total'] as $key) {
            $this->assertArrayNotHasKey($key, $row);
        }

        $this->signedInAs($this->userWithRole($alpha, 'Jobs only', ['jobs.view']))
            ->getJson('/api/v1/project-management-2/jobs')->assertForbidden();
    }

    public function test_q_searches_customer_job_id_and_address_and_the_list_is_paged(): void
    {
        $alpha = $this->provisionTenant('Alpha');
        [$ann, $bob] = $this->inTenant($alpha['org'], function () use ($alpha) {
            $ann = $this->job($alpha, 'J-3', 'done pending approval');
            $bob = $this->job($alpha, 'J-4', 'done pending approval');
            Job::find($bob)->customer->update(['name' => 'Bob Jones', 'address_line1' => '9 Elm Rd']);

            return [$ann, $bob];
        });
        $ids = fn (string $q) => collect($this->signedInAs($alpha['admin'])
            ->getJson('/api/v1/project-management-2/jobs?q='.urlencode($q))->assertOk()->json('data'))->pluck('id')->sort()->values()->all();

        $this->assertSame([$ann], $ids('7003'), "Workiz's job number");
        $this->assertSame([$bob], $ids('J-4'), 'our job number');
        $this->assertSame([$bob], $ids('bob'), 'customer name');
        $this->assertSame([$bob], $ids('Elm'), 'address');
        $this->assertSame(collect([$ann, $bob])->sort()->values()->all(), $ids('Columbus'), 'city');

        $page = $this->signedInAs($alpha['admin'])->getJson('/api/v1/project-management-2/jobs?per_page=1')->assertOk();
        $this->assertCount(1, $page->json('data'));
        $this->assertSame(['total' => 2, 'per_page' => 1, 'current_page' => 1, 'last_page' => 2], $page->json('meta'));
    }

    public function test_a_job_opens_with_its_money_and_timeline(): void
    {
        $alpha = $this->provisionTenant('Alpha');
        $beta = $this->provisionTenant('Beta');
        $other = $this->inTenant($beta['org'], fn () => $this->job($beta, 'B-1', 'done pending approval'));
        [$id, $bare, $native] = $this->inTenant($alpha['org'], function () use ($alpha) {
            $id = $this->job($alpha, 'J-5', 'done pending approval', ['JobTotalPrice' => 1200, 'CreatedDate' => '2026-09-01 10:00:00']);
            // Workiz's invoice total wins over the estimate.
            $this->record($alpha, $id, 'estimate', 'E9', '999.00', 'Pending', '2026-09-02');
            $this->record($alpha, $id, 'payment', 'P1', '500.00', 'succeeded', '2026-09-05');
            $this->record($alpha, $id, 'payment', 'P2', '100.00', 'succeeded', '2026-09-06', ['type' => 'refund']);
            $job = Job::find($id);
            $emit = function (string $key, array $payload, string $at) use ($job) {
                app(\App\Support\Automations\EventEmitter::class)->emit($key, $job, $payload)
                    ->forceFill(['occurred_at' => $at, 'actor_label' => 'Ada'])->save();
            };
            $emit('job.status_changed', ['before' => ['status' => 'scheduled'], 'after' => ['status' => 'done pending approval']], '2026-09-03 09:00:00');
            $emit('job.updated', ['before' => ['tags' => []], 'after' => ['tags' => ['DEPOSIT']]], '2026-09-07 09:00:00');
            $emit('job.updated', ['before' => ['title' => 'A'], 'after' => ['title' => 'B']], '2026-09-08 09:00:00');

            // EzBizi's own job, no Workiz mirror: its own amount is its total.
            $native = Job::create(['franchise_id' => $alpha['franchise']->id, 'customer_id' => Job::find($id)->customer_id, 'number' => 'N-1',
                'title' => 'Ours', 'status' => 'done pending approval', 'currency' => 'USD', 'amount_minor' => 5000])->id;

            return [$id, $this->job($alpha, 'J-6', 'done pending approval'), $native];
        });

        $job = $this->signedInAs($alpha['admin'])->getJson("/api/v1/project-management-2/jobs/{$id}")->assertOk()->json('data');
        $this->assertSame([120000, 40000, 80000], [$job['total'], $job['paid'], $job['pending']]);
        $this->assertSame(['created', 'estimate', 'status', 'payment', 'payment', 'tags'], array_column($job['timeline'], 'kind'), 'oldest first; a title edit is left out');
        $this->assertSame(['Job created', 'Estimate Pending'], array_slice(array_column($job['timeline'], 'what'), 0, 2));
        $this->assertStringStartsWith('Status Scheduled → ', $job['timeline'][2]['what']);
        $this->assertSame('Ada', $job['timeline'][2]['by']);
        $this->assertSame([99900, 50000, -10000], array_values(array_filter(array_map(fn ($s) => $s['amount'] ?? null, $job['timeline']))));
        $this->assertSame(['Payment', 'Refund', 'Tags +DEPOSIT'], array_slice(array_column($job['timeline'], 'what'), 3));

        $rows = collect($this->signedInAs($alpha['admin'])->getJson('/api/v1/project-management-2/jobs')->json('data'))->keyBy('id');
        $this->assertSame([null, null, null], [$rows[$bare]['total'], $rows[$bare]['paid'], $rows[$bare]['pending']], 'no price draws no zero');
        $this->assertSame(['N-1', 5000, 0, 5000], [$rows[$native]['job_number'], $rows[$native]['total'], $rows[$native]['paid'], $rows[$native]['pending']]);
        $this->signedInAs($alpha['admin'])->getJson("/api/v1/project-management-2/jobs/{$native}")->assertOk()
            ->assertJsonPath('data.timeline.0.what', 'Job created');

        $this->signedInAs($alpha['admin'])->getJson("/api/v1/project-management-2/jobs/{$other}")->assertNotFound();
    }

    private function record(array $tenant, string $job, string $type, string $remote, string $amount, ?string $status, string $day, array $payload = []): void
    {
        WorkizRecord::create(['franchise_id' => $tenant['franchise']->id, 'type' => $type, 'remote_id' => $remote, 'job_id' => $job,
            'amount' => $amount, 'status' => $status, 'starts_at' => "{$day} 10:00:00", 'payload' => $payload, 'synced_at' => now()]);
    }

    private function job(array $tenant, string $number, string $status, array $money = []): string
    {
        $customer = Customer::create(['owning_franchise_id' => $tenant['franchise']->id, 'number' => "C-{$number}", 'name' => 'Ann Smith',
            'address_line1' => '1 Main St', 'city' => 'Columbus', 'state' => 'OH', 'postal_code' => '43004']);
        $job = Job::create(['franchise_id' => $tenant['franchise']->id, 'customer_id' => $customer->id, 'number' => $number,
            'title' => 'A job', 'status' => $status, 'currency' => 'USD', 'tags' => ['DEPOSIT']]);

        $mirror = WorkizJob::create(['franchise_id' => $job->franchise_id, 'remote_id' => "JOB-{$number}", 'serial_id' => '700'.preg_replace('/\D/', '', $number),
            'status' => 'x', 'payload' => $money, 'last_status_update' => '2026-10-20 08:00:00', 'synced_at' => now()]);
        $mirror->job_id = $job->id;
        $mirror->save();

        return $job->id;
    }
}
