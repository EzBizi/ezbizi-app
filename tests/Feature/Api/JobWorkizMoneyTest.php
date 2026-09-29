<?php

namespace Tests\Feature\Api;

use App\Models\Customer;
use App\Models\Job;
use App\Models\WorkizJob;
use App\Support\Jobs\WorkizMoney;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/**
 * A Workiz job's money, read live from the mirror (spec 2026-09-29 §2). Workiz
 * sends dollars as int or float; EzBizi speaks integer minor units.
 */
class JobWorkizMoneyTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    public function test_a_partly_paid_job(): void
    {
        [$alpha, $job] = $this->workizJob(['JobTotalPrice' => 2413, 'JobAmountDue' => 1206.5,
            'SubTotal' => 2413, 'PaymentDueDate' => '2026-10-02 00:00:00',
            'LineItems' => [['Name' => 'Sweep', 'Description' => '', 'Quantity' => 1, 'Price' => 2413, 'Cost' => 900, 'Taxable' => 0]]]);

        $money = $this->inTenant($alpha['org'], fn () => WorkizMoney::for(Job::findOrFail($job)));

        $this->assertSame(241300, $money['total']);
        $this->assertSame(120650, $money['due']);
        $this->assertSame(120650, $money['paid']);
        $this->assertSame('partial', $money['state']);
        $this->assertSame('2026-10-02', $money['due_on']);
        $this->assertSame([['name' => 'Sweep', 'description' => null, 'quantity' => 1.0, 'unit_price' => 241300, 'taxable' => false]], $money['lines']);
    }

    /** round, never cast: (int) (1.15 * 100) is 114 in binary floating point. */
    public function test_dollars_are_rounded_to_cents(): void
    {
        [$alpha, $job] = $this->workizJob(['JobTotalPrice' => 1.15, 'JobAmountDue' => 1.15]);
        $money = $this->inTenant($alpha['org'], fn () => WorkizMoney::for(Job::findOrFail($job)));

        $this->assertSame(115, $money['total']);
        $this->assertSame('unpaid', $money['state']);
    }

    public function test_the_states(): void
    {
        $this->assertSame('none', WorkizMoney::state(0, 0));
        $this->assertSame('paid', WorkizMoney::state(5000, 0));
        $this->assertSame('credit', WorkizMoney::state(5000, -500));
        $this->assertSame('partial', WorkizMoney::state(5000, 2000));
        $this->assertSame('unpaid', WorkizMoney::state(5000, 5000));
    }

    public function test_a_credit_is_shown_as_a_credit_not_a_negative_due(): void
    {
        [$alpha, $job] = $this->workizJob(['JobTotalPrice' => 100, 'JobAmountDue' => -25]);
        $money = $this->inTenant($alpha['org'], fn () => WorkizMoney::for(Job::findOrFail($job)));

        $this->assertSame([0, 2500, 10000], [$money['due'], $money['credit'], $money['paid']]);
    }

    public function test_a_job_with_no_workiz_row_has_no_workiz_money(): void
    {
        $alpha = $this->provisionTenant('Alpha');
        $job = $this->aJob($alpha);

        $this->assertNull($this->inTenant($alpha['org'], fn () => WorkizMoney::for(Job::findOrFail($job))));
    }

    public function test_the_job_carries_it_only_with_the_toggle(): void
    {
        [$alpha, $job] = $this->workizJob(['JobTotalPrice' => 100, 'JobAmountDue' => 100]);

        $this->signedInAs($alpha['admin'])->getJson("/api/v1/jobs/{$job}")->assertOk()
            ->assertJsonPath('data.money.state', 'unpaid')
            ->assertJsonPath('data.money.total', 10000);

        $dispatcher = $this->userWithRole($alpha, 'Dispatcher', ['jobs.view']);
        $this->assertArrayNotHasKey('money',
            $this->signedInAs($dispatcher)->getJson("/api/v1/jobs/{$job}")->assertOk()->json('data'));
    }

    /** @return array{0: array, 1: string} */
    private function workizJob(array $payload): array
    {
        $alpha = $this->provisionTenant('Alpha');
        $job = $this->aJob($alpha);

        $this->inTenant($alpha['org'], function () use ($alpha, $job, $payload) {
            $row = WorkizJob::create(['franchise_id' => $alpha['franchise']->id,
                'remote_id' => strtoupper(bin2hex(random_bytes(4))), 'status' => 'Submitted',
                'payload' => $payload, 'synced_at' => now()]);
            $row->job_id = $job;
            $row->save();
        });

        return [$alpha, $job];
    }

    private function aJob(array $tenant): string
    {
        return $this->inTenant($tenant['org'], function () use ($tenant) {
            $customer = Customer::create(['owning_franchise_id' => $tenant['franchise']->id,
                'number' => 'C-'.bin2hex(random_bytes(3)), 'name' => 'A Customer']);

            return Job::create(['franchise_id' => $tenant['franchise']->id, 'customer_id' => $customer->id,
                'number' => 'J-'.bin2hex(random_bytes(3)), 'title' => 'A job', 'status' => 'scheduled',
                'currency' => 'USD'])->id;
        });
    }
}
