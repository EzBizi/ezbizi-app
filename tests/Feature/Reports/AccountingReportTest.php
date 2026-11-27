<?php

namespace Tests\Feature\Reports;

use App\Http\Controllers\Api\AccountingReportController;
use App\Models\WorkizJob;
use App\Models\WorkizRecord;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/** Reports › Accounting (Shoval, 2026-11-18): the week's money that does not add up, one row per problem. */
class AccountingReportTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    public function test_each_kind_of_money_problem_in_the_week(): void
    {
        $t = $this->provisionTenant('Alpha');
        $f = $t['franchise'];
        $this->inTenant($t['org'], function () use ($t, $f) {
            $job = fn (string $id, string $status, ?string $closed, array $payload = []) => WorkizJob::query()->create(['franchise_id' => $f->id,
                'remote_id' => $id, 'serial_id' => '80'.substr($id, 1), 'status' => $status, 'booked_at' => '2026-11-01 09:00:00', 'closed_at' => $closed,
                'payload' => $payload + ['FirstName' => 'Ann', 'LastName' => 'Lee', 'v2' => ['id' => 'JOB-'.$id]], 'synced_at' => now()]);
            $job('J1', 'Done', '2026-11-11 15:00:00', ['JobTotalPrice' => 500, 'JobAmountDue' => 120.50]);
            $job('J2', 'Done', '2026-11-12 15:00:00', ['JobTotalPrice' => 0, 'JobAmountDue' => 0]);
            $job('J3', 'Done', '2026-11-12 16:00:00', ['JobTotalPrice' => 300, 'JobAmountDue' => 0]);
            // Workiz's $0 copy of a finished job, and one closed before the week.
            $job('J4', 'done pending approval', '2026-11-12 15:00:00', ['JobTotalPrice' => 0]);
            $job('J5', 'Done', '2026-11-01 15:00:00', ['JobTotalPrice' => 0]);
            $job('J6', 'Canceled', null);

            $record = fn (string $type, string $id, string $at, array $more) => WorkizRecord::query()->create($more + ['franchise_id' => $f->id,
                'type' => $type, 'remote_id' => $id, 'starts_at' => $at, 'payload' => ['serialId' => $id], 'synced_at' => now()]);
            // Owing 40 days after it was made, and one only 5 days old.
            $record('invoice', 'IN-1', '2026-10-04 10:00:00', ['amount' => 200, 'amount_due' => 200, 'job_remote_id' => 'JOB-J3']);
            $record('invoice', 'IN-2', '2026-11-08 10:00:00', ['amount' => 90, 'amount_due' => 90]);
            $record('payment', 'PAY-1', '2026-11-13 10:00:00', ['amount' => 75, 'status' => 'succeeded', 'job_remote_id' => 'JOB-J6']);
            // Paid and refunded in full: nothing kept, not an issue.
            $job('J7', 'Canceled', null);
            $record('payment', 'PAY-4', '2026-11-13 10:00:00', ['amount' => 80, 'status' => 'succeeded', 'job_remote_id' => 'JOB-J7']);
            $record('payment', 'PAY-5', '2026-11-14 10:00:00', ['amount' => -80, 'status' => 'succeeded', 'job_remote_id' => 'JOB-J7']);
            $record('payment', 'PAY-2', '2026-11-13 11:00:00', ['amount' => 60, 'status' => 'deleted', 'job_remote_id' => 'JOB-J3']);
            $record('payment', 'PAY-3', '2026-11-13 12:00:00', ['amount' => 300, 'status' => 'succeeded', 'job_remote_id' => 'JOB-J3']);

            // EzBizi's own invoice, unpaid since October.
            DB::table('invoices')->insert(['id' => (string) Str::uuid(), 'organization_id' => $t['org']->id, 'company_id' => $f->company_id,
                'franchise_id' => $f->id, 'number' => 'INV-9', 'issued_on' => '2026-10-01', 'status' => 'sent', 'currency' => 'USD',
                'bill_to_type' => 'customer', 'bill_to_name' => 'Bob Ray', 'subtotal_cents' => 10000, 'tax_cents' => 0, 'total_cents' => 10000, 'created_at' => now(), 'updated_at' => now()]);
        });
        $get = fn (string $query = '') => $this->signedInAs($t['admin'])->getJson('/api/v1/reports/accounting?from=2026-11-10&to=2026-11-16'.$query)->assertOk();

        $data = $get()->json('data');
        $this->assertSame([
            'done_unpaid' => ['count' => 1, 'amount_minor' => 12050],
            'done_zero' => ['count' => 1, 'amount_minor' => 0],
            'invoice_overdue' => ['count' => 2, 'amount_minor' => 30000],
            'paid_cancelled' => ['count' => 1, 'amount_minor' => 7500],
            'payment_deleted' => ['count' => 1, 'amount_minor' => 6000],
        ], $data['figures']);
        $this->assertSame(30, $data['invoice_days']);
        $rows = collect($data['rows']);
        $this->assertSame(['801', 'Ann Lee'], [$rows->firstWhere('issue', 'done_unpaid')['job_number'], $rows->firstWhere('issue', 'done_unpaid')['client']]);
        $this->assertSame(['Invoice IN-1 · job Done', 'Invoice INV-9'], $rows->where('issue', 'invoice_overdue')->pluck('detail')->sort()->values()->all());

        // The business allows 45 days: October's Workiz invoice is not late yet; EzBizi's is.
        $this->signedInAs($t['admin'])->postJson('/api/v1/settings/lists/'.AccountingReportController::LIST.'/items',
            ['value' => ['rule' => 'invoice_days', 'value' => 45]])->assertSuccessful();
        $get('&issue[]=invoice_overdue')->assertJsonPath('data.count', 1)->assertJsonPath('data.rows.0.detail', 'Invoice INV-9');
    }

    public function test_it_needs_its_tab(): void
    {
        $t = $this->provisionTenant('Alpha');

        $this->signedInAs($this->userWithRole($t, 'Office', ['reports.view']))
            ->getJson('/api/v1/reports/accounting?from=2026-11-10&to=2026-11-16')->assertForbidden();
    }
}
