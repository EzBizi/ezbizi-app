<?php

namespace Tests\Feature\Api;

use App\Models\Customer;
use App\Models\Job;
use App\Models\WorkizJob;
use App\Models\WorkizRecord;
use App\Models\WorkizTeamMember;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/**
 * What Workiz v2 knows about a job, on the job (Jose, 2026-10-06: "take
 * advantage of everything v2 has to offer"). Read-only: Workiz stays the
 * truth for A-1's invoices (decision 2). Its custom fields and files for
 * anybody who opens the job; its invoices, payments, estimates, timesheets
 * and the "Pricing" field only with Jobs Financials.
 */
class JobWorkizExtrasTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    public function test_a_financials_reader_gets_everything_workiz_has_on_the_job(): void
    {
        [$alpha, $job] = $this->workizJob();

        $workiz = $this->signedInAs($alpha['admin'])->getJson("/api/v1/jobs/{$job}")->assertOk()->json('data.workiz');

        $this->assertSame([
            ['name' => 'System Type', 'value' => 'Wood Burning'],
            ['name' => 'Time Frames', 'value' => 'Appointment Time: 8-11'],
            ['name' => 'Pricing', 'value' => '$299 Chimney Sweep'],
        ], $workiz['fields']);
        $this->assertSame([
            ['name' => 'chimney.jpg', 'url' => 'https://st.sendajob.com/uploads/a/chimney.jpg'],
            ['name' => 'inspection-report.pdf', 'url' => 'https://st.sendajob.com/uploads/a/report.pdf'],
        ], $workiz['files']);
        $this->assertSame([[
            'number' => '200023384', 'total_minor' => 32470, 'due_minor' => 0, 'tax_minor' => 2470, 'tip_minor' => 29,
            'issued_on' => '2026-10-01', 'due_on' => '2026-10-15',
        ]], $workiz['invoices']);
        $this->assertSame([[
            'paid_on' => '2026-10-02', 'amount_minor' => 32470, 'kind' => 'charge', 'status' => 'succeeded', 'collected_by' => 'Chaim Perez',
        ]], $workiz['payments']);
        $this->assertSame([[
            'number' => '200023384-2', 'total_minor' => 120000, 'status' => 'Won', 'dated_on' => '2026-09-20',
            'portal_url' => 'https://wrkz.me/hs8I92', 'estimate_link' => 'https://app.workiz.com/clientPortal/estimate/EST-1:abc',
        ]], $workiz['estimates']);
        $this->assertSame([[
            'who' => 'Chaim Perez', 'starts_at' => '2026-10-01T13:00:00+00:00', 'ends_at' => '2026-10-01T15:30:00+00:00', 'labor_cost_minor' => 7500,
        ]], $workiz['timesheets']);
        $this->assertSame('USD', $workiz['currency']);
    }

    /** The pair: without Financials no amount and no price, under any key; the job's own facts stay. */
    public function test_without_financials_only_the_fields_and_files_arrive(): void
    {
        [$alpha, $job] = $this->workizJob();
        $dispatcher = $this->userWithRole($alpha, 'Dispatcher', ['jobs.view']);

        $data = $this->signedInAs($dispatcher)->getJson("/api/v1/jobs/{$job}")->assertOk()->json('data');

        $this->assertSame(['fields', 'files'], array_keys($data['workiz']));
        $this->assertSame(['System Type', 'Time Frames'], array_column($data['workiz']['fields'], 'name'));
        $json = json_encode($data);
        foreach (['32470', '324.70', '120000', '7500', '$299', 'Pricing', 'invoices', 'payments', 'labor_cost'] as $needle) {
            $this->assertStringNotContainsString($needle, $json, $needle);
        }
    }

    /** Only https links: a payload is Workiz's, and a javascript: url would be ours to have shown. */
    public function test_a_file_that_is_not_an_https_link_is_left_out(): void
    {
        [$alpha, $job] = $this->workizJob(['Attachments' => [['id' => 1, 'Name' => 'x', 'Url' => 'javascript:alert(1)']]]);

        $this->assertSame(
            ['inspection-report.pdf'],
            array_column($this->signedInAs($alpha['admin'])->getJson("/api/v1/jobs/{$job}")->json('data.workiz.files'), 'name'),
        );
    }

    public function test_a_job_workiz_does_not_have_carries_no_workiz_block(): void
    {
        $alpha = $this->provisionTenant('Alpha');
        $job = $this->aJob($alpha);

        $this->signedInAs($alpha['admin'])->getJson("/api/v1/jobs/{$job}")->assertOk()->assertJsonPath('data.workiz', null);
    }

    /** @return array{0: array, 1: string} */
    private function workizJob(array $payloadOverrides = []): array
    {
        $alpha = $this->provisionTenant('Alpha');
        $job = $this->aJob($alpha);
        $f = $alpha['franchise']->id;

        $this->inTenant($alpha['org'], function () use ($f, $job, $payloadOverrides) {
            $row = WorkizJob::create(['franchise_id' => $f, 'remote_id' => 'JOB-ABC', 'status' => 'Done', 'synced_at' => now(),
                'payload' => $payloadOverrides + [
                    'CustomFields' => [
                        ['Name' => 'System Type', 'Value' => 'Wood Burning'],
                        ['Name' => 'Real Wood Y/N', 'Value' => ''],
                        ['Name' => 'Time Frames', 'Value' => 'Appointment Time: 8-11'],
                        ['Name' => 'Pricing', 'Value' => '$299 Chimney Sweep'],
                        ['Name' => 'Inspection Report', 'Value' => [['url' => 'https://st.sendajob.com/uploads/a/report.pdf', 'name' => 'inspection-report.pdf']]],
                    ],
                    'Attachments' => [['id' => 1, 'Name' => 'chimney.jpg', 'Url' => 'https://st.sendajob.com/uploads/a/chimney.jpg']],
                ]]);
            $row->job_id = $job;
            $row->save();

            WorkizTeamMember::create(['franchise_id' => $f, 'remote_id' => 'USR-7', 'name' => 'Chaim Perez', 'payload' => [], 'synced_at' => now()]);

            $record = fn (array $a) => WorkizRecord::create($a + ['franchise_id' => $f, 'job_id' => $job, 'job_remote_id' => 'JOB-ABC', 'synced_at' => now()]);
            $record(['type' => 'invoice', 'remote_id' => 'INV-1', 'amount' => '324.70', 'amount_due' => '0.00', 'starts_at' => '2026-10-01 10:00:00',
                'payload' => ['serialId' => '200023384', 'taxAmount' => 24.7, 'tipAmount' => 0.29, 'amountDueDate' => '2026-10-15 00:00:00']]);
            $record(['type' => 'payment', 'remote_id' => 'PAY-1', 'amount' => '324.70', 'status' => 'succeeded', 'user_remote_id' => 'USR-7',
                'starts_at' => '2026-10-02 11:00:00', 'payload' => ['type' => 'charge']]);
            $record(['type' => 'estimate', 'remote_id' => 'EST-1', 'amount' => '1200.00', 'status' => 'Won', 'starts_at' => '2026-09-20 09:00:00',
                'payload' => ['serialId' => '200023384-2', 'declineReason' => 'free text, never copied'],
                'links' => ['portal' => 'https://wrkz.me/hs8I92', 'estimate' => 'https://app.workiz.com/clientPortal/estimate/EST-1:abc', 'proposal' => null]]);
            $record(['type' => 'timesheet', 'remote_id' => 'TS-1', 'amount' => '75.00', 'user_remote_id' => 'USR-7',
                'starts_at' => '2026-10-01 13:00:00', 'ends_at' => '2026-10-01 15:30:00', 'payload' => []]);
        });

        return [$alpha, $job];
    }

    private function aJob(array $alpha): string
    {
        return $this->inTenant($alpha['org'], function () use ($alpha) {
            $customer = Customer::create(['owning_franchise_id' => $alpha['franchise']->id, 'number' => 'C-1', 'name' => 'A Customer']);

            return Job::create(['franchise_id' => $alpha['franchise']->id, 'customer_id' => $customer->id,
                'number' => 'J-1', 'title' => 'A job', 'status' => 'scheduled', 'currency' => 'USD'])->id;
        });
    }
}
