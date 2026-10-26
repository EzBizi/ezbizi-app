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
                WorkizRecord::create(['franchise_id' => $alpha['franchise']->id, 'type' => 'estimate', 'remote_id' => $remote, 'job_id' => $id,
                    'amount' => $amount, 'status' => $status, 'starts_at' => "{$at} 10:00:00", 'payload' => [], 'synced_at' => now()]);
            }

            return $id;
        });

        $rows = $this->signedInAs($alpha['admin'])->getJson('/api/v1/project-management-2/jobs')->assertOk()->json('data');

        $this->assertCount(1, $rows, 'Done and another tenant are left out');
        $this->assertSame([
            'id' => $id, 'customer' => 'Ann Smith', 'branch' => 'Alpha F1', 'company' => 'Alpha Co', 'tags' => ['DEPOSIT'],
            'address' => '1 Main St, Columbus, OH 43004', 'created' => '2026-09-01', 'modified' => '2026-10-20',
            'money_pending' => 45000, 'money_owed' => 90050, 'currency' => 'USD',
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
        $this->assertArrayNotHasKey('money_pending', $row);
        $this->assertArrayNotHasKey('money_owed', $row);

        $this->signedInAs($this->userWithRole($alpha, 'Jobs only', ['jobs.view']))
            ->getJson('/api/v1/project-management-2/jobs')->assertForbidden();
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
