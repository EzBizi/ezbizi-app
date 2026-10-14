<?php

namespace Tests\Feature\Api;

use App\Models\Customer;
use App\Models\Job;
use App\Models\SalesDeal;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

class SalesPipelineTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    public function test_pending_approval_jobs_are_deals_and_others_are_not(): void
    {
        $t = $this->provisionTenant();
        $pending = $this->job($t, 'done pending approval', 1350000);
        $this->job($t, 'scheduled', 5000);

        $rows = $this->signedInAs($this->userWithRole($t, 'Sales', ['sales.view']))
            ->getJson('/api/v1/sales/deals')->assertOk()->json('data');

        $this->assertSame([$pending->id], array_column($rows, 'job_id'));
        $this->assertSame(1350000, $rows[0]['quote_minor']);
        $this->assertSame('follow_up', $rows[0]['stage']);
        $this->assertSame('Pat Doe', $rows[0]['name']);
    }

    public function test_marking_sold_stamps_it_and_keeps_it_after_the_job_moves_on(): void
    {
        $t = $this->provisionTenant();
        $job = $this->job($t, 'done pending approval', 415000);
        $user = $this->userWithRole($t, 'Sales', ['sales.view', 'sales.manage']);

        $this->signedInAs($user)->putJson("/api/v1/sales/deals/{$job->id}", [
            'stage' => 'sold', 'urgency' => 'asap', 'next_follow_up' => '2026-10-20', 'notes' => 'Zelled deposit',
        ])->assertOk()->assertJsonPath('data.stage', 'sold');

        $this->inTenant($t['org'], fn () => $job->update(['status' => 'Done']));

        $row = $this->signedInAs($user)->getJson('/api/v1/sales/deals')->json('data.0');
        $this->assertSame($job->id, $row['job_id'], 'a worked deal stays after the job leaves pending');
        $this->assertNotNull($row['sold_at']);
        $this->assertSame('2026-10-20', $row['next_follow_up']);
    }

    public function test_view_alone_cannot_edit_and_bad_stage_is_refused(): void
    {
        $t = $this->provisionTenant();
        $job = $this->job($t, 'done pending approval', 100);

        $this->signedInAs($this->userWithRole($t, 'Viewer', ['sales.view']))
            ->putJson("/api/v1/sales/deals/{$job->id}", ['stage' => 'sold'])->assertForbidden();

        $this->signedInAs($this->userWithRole($t, 'Sales', ['sales.view', 'sales.manage']))
            ->putJson("/api/v1/sales/deals/{$job->id}", ['stage' => 'won'])->assertUnprocessable();

        $this->assertSame(0, $this->inTenant($t['org'], fn () => SalesDeal::count()));
    }

    public function test_lead_date_is_workizs_created_date_not_the_import(): void
    {
        $t = $this->provisionTenant();
        $job = $this->job($t, 'done pending approval', 100);
        $this->inTenant($t['org'], fn () => \App\Models\WorkizJob::create([
            'franchise_id' => $t['franchise']->id, 'remote_id' => 'w1', 'synced_at' => now(),
            'job_id' => $job->id, 'serial_id' => '500032883', 'status' => 'done pending approval',
            'payload' => ['CreatedDate' => '2026-07-22 09:15:00'],
        ]));

        $row = $this->signedInAs($this->userWithRole($t, 'Sales', ['sales.view']))
            ->getJson('/api/v1/sales/deals')->json('data.0');

        $this->assertSame('2026-07-22', $row['lead_date']);
        $this->assertEquals('500032883', $row['job_number']);
    }

    public function test_another_organizations_job_is_not_reachable(): void
    {
        $other = $this->job($this->provisionTenant('Beta'), 'done pending approval', 100);
        $t = $this->provisionTenant();
        $user = $this->userWithRole($t, 'Sales', ['sales.view', 'sales.manage']);

        $this->signedInAs($user)->getJson('/api/v1/sales/deals')->assertJsonCount(0, 'data');
        $this->signedInAs($user)->putJson("/api/v1/sales/deals/{$other->id}", ['stage' => 'sold'])->assertNotFound();
    }

    private function job(array $t, string $status, int $amount): Job
    {
        return $this->inTenant($t['org'], function () use ($t, $status, $amount) {
            $customer = Customer::create([
                'owning_franchise_id' => $t['franchise']->id,
                'number' => 'C-'.bin2hex(random_bytes(3)),
                'name' => 'Pat Doe',
                'phone' => '6092403123',
                'state' => 'NJ',
            ]);

            $job = Job::create([
                'franchise_id' => $t['franchise']->id,
                'customer_id' => $customer->id,
                'number' => 'J-'.bin2hex(random_bytes(3)),
                'title' => 'Work',
                'status' => $status,
                'currency' => 'USD',
            ]);
            $job->forceFill(['amount_minor' => $amount])->saveQuietly();

            return $job;
        });
    }
}
