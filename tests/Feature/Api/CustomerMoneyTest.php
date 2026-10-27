<?php

namespace Tests\Feature\Api;

use App\Models\Customer;
use App\Models\Franchise;
use App\Models\Job;
use App\Models\WorkizRecord;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/**
 * GET /customers/{id}`.money` -- Workiz's client header (invoiced, due, past
 * due, paid), summed from the v2 mirror through the customer's jobs.
 *
 * Every figure is a different number, so a column summed into the wrong field
 * cannot pass; and each case carries a record that must NOT count beside ones
 * that must.
 */
class CustomerMoneyTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    private array $alpha;

    private Franchise $other;

    private Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->alpha = $this->provisionTenant('Alpha', 'a@alpha.test');
        $this->other = $this->extraFranchise($this->alpha, 'Other branch');
        $this->customer = $this->inTenant($this->alpha['org'], fn () => Customer::create([
            'owning_franchise_id' => $this->alpha['franchise']->id, 'number' => 'C-1', 'name' => 'Ann',
        ]));

        $here = $this->job($this->alpha['franchise'], 'J-1');
        // Workiz's amountDue is a snapshot: paid AFTER the invoice was last
        // edited, it still says 1000. The payments say 450 is left.
        $this->record($here, 'invoice', 1000.00, due: 1000.00, dueOn: '2026-10-01', id: 'IV-A');
        $this->record($here, 'payment', 600.00, status: 'succeeded', invoice: 'IV-A');
        $this->record($here, 'payment', -50.00, status: 'succeeded', invoice: 'IV-A');  // a refund
        $this->record($here, 'payment', 999.00, status: 'pending', invoice: 'IV-A');    // not paid yet
        // The other way round: Workiz knows of a payment the mirror has no
        // invoice link for, so its 75 beats the 250 the payments imply.
        $this->record($here, 'invoice', 250.00, due: 75.00, dueOn: '2026-10-20', id: 'IV-B');
        $this->record($here, 'estimate', 300.00, status: 'Pending');
        $this->record($here, 'estimate', 7000.00, status: 'Won');                       // not open

        // Another branch's job for the same customer: theirs to see, not ours.
        $this->record($this->job($this->other, 'J-2'), 'invoice', 5000.00, due: 5000.00, dueOn: '2026-09-01');
    }

    private function job(Franchise $franchise, string $number): Job
    {
        return $this->inTenant($this->alpha['org'], fn () => Job::create([
            'franchise_id' => $franchise->id, 'customer_id' => $this->customer->id,
            'number' => $number, 'title' => $number, 'status' => 'scheduled',
        ]));
    }

    private function record(Job $job, string $type, float $amount, ?float $due = null, ?string $dueOn = null,
        ?string $status = null, ?string $id = null, ?string $invoice = null): void
    {
        $this->inTenant($this->alpha['org'], fn () => WorkizRecord::create([
            'franchise_id' => $job->franchise_id, 'type' => $type, 'remote_id' => $id ?? uniqid($type),
            'job_id' => $job->id, 'amount' => $amount, 'amount_due' => $due, 'status' => $status,
            'payload' => array_filter(['amountDueDate' => $dueOn === null ? null : "{$dueOn} 00:00:00", 'invoiceId' => $invoice]),
            'synced_at' => now(),
        ]));
    }

    private function money($user, string $query = '?today=2026-10-06'): ?array
    {
        return $this->signedInAs($user)
            ->getJson("/api/v1/customers/{$this->customer->id}{$query}")
            ->assertOk()->json('data.money');
    }

    public function test_it_reads_like_the_workiz_header_for_the_branch_you_see(): void
    {
        $here = $this->userWithRole($this->alpha, 'Branch money', ['customers.view', 'jobs.financials'],
            onlyAt: $this->alpha['franchise']);

        $this->assertSame([[
            'currency' => 'USD',
            'invoiced_minor' => 125000,
            'due_minor' => 52500,
            'past_due_minor' => 45000,
            'paid_minor' => 55000,
            'invoices' => 2,
            'open_estimates' => 1,
            'open_estimates_minor' => 30000,
        ]], $this->money($here));
    }

    public function test_without_a_date_past_due_is_unknown_not_zero(): void
    {
        $money = $this->money($this->alpha['admin'], '');

        $this->assertNull($money[0]['past_due_minor']);
        // The other branch's invoice counts for somebody who sees both.
        $this->assertSame(625000, $money[0]['invoiced_minor']);
    }

    public function test_a_job_with_its_own_invoice_is_counted_from_that_and_not_from_workiz(): void
    {
        // EzBizi is the master (Jose, 2026-10-27). J-3 has an EzBizi invoice
        // AND Workiz's mirror of it; only EzBizi's may count, or every figure
        // doubles once invoices are mirrored. J-1's Workiz history still counts.
        $job = $this->job($this->alpha['franchise'], 'J-3');
        $this->record($job, 'invoice', 9999.00, due: 9999.00, dueOn: '2026-09-01', id: 'IV-MIRROR');
        $this->inTenant($this->alpha['org'], function () use ($job) {
            $invoice = \App\Models\Invoice::create([
                'franchise_id' => $job->franchise_id, 'number' => 'INV-1', 'issued_on' => '2026-09-01',
                'due_on' => '2026-09-15', 'status' => 'sent', 'currency' => 'USD',
                'bill_to_type' => 'customer', 'bill_to_id' => $this->customer->id, 'bill_to_name' => 'Ann',
                'source_type' => 'job', 'source_id' => $job->id, 'total_cents' => 80000,
            ]);
            \App\Models\InvoicePayment::create(['invoice_id' => $invoice->id, 'paid_on' => '2026-09-10',
                'amount_cents' => 30000, 'method' => 'card']);
        });
        $here = $this->userWithRole($this->alpha, 'Branch money', ['customers.view', 'jobs.financials'],
            onlyAt: $this->alpha['franchise']);

        $money = $this->money($here)[0];

        // J-1 (Workiz): invoiced 1250, due 525, past due 450, paid 550.
        // J-3 (EzBizi): invoiced 800, due 500 (all past due), paid 300.
        $this->assertSame(205000, $money['invoiced_minor']);
        $this->assertSame(102500, $money['due_minor']);
        $this->assertSame(95000, $money['past_due_minor']);
        $this->assertSame(85000, $money['paid_minor']);
        $this->assertSame(3, $money['invoices']);
    }

    public function test_without_financials_there_is_no_money(): void
    {
        $viewer = $this->userWithRole($this->alpha, 'Viewer', ['customers.view']);

        $this->assertNull($this->money($viewer));
    }
}
