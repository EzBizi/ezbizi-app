<?php

namespace Tests\Feature\Api;

use App\Models\Customer;
use App\Models\Job;
use App\Models\WorkizJob;
use App\Models\WorkizRecord;
use App\Support\Settings\SettingsResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/** Project Management's overhaul (Jose, 2026-10-22): stages from status, schedule and money due; the dashboard; the warning. */
class JobProjectStagesTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    public function test_stages_dashboard_filters_and_the_paid_not_started_warning(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-22 15:00:00', 'UTC'));
        $alpha = $this->provisionTenant('Alpha');
        $ids = $this->inTenant($alpha['org'], function () use ($alpha) {
            $s = app(SettingsResolver::class);
            $s->set('jobs.statuses', $alpha['org'], array_map(fn ($v) => ['value' => $v, 'label' => ucfirst($v)],
                ['in progress', 'pending', 'submitted', 'dpa', 'done', 'canceled']));
            $s->set('jobs.pm_stages', $alpha['org'], [
                ['key' => 'in_progress', 'statuses' => ['in progress']],
                ['key' => 'waiting_review', 'statuses' => ['pending']],
                ['key' => 'ready_to_schedule', 'statuses' => ['submitted'], 'scheduled' => false],
                ['key' => 'scheduled', 'statuses' => ['submitted'], 'scheduled' => true],
                ['key' => 'done_waiting_payment', 'statuses' => ['dpa'], 'due' => true],
                ['key' => 'completed', 'statuses' => ['done']],
            ]);
            $s->set('jobs.pm_redo_words', $alpha['org'], ['Redo']);
            $s->set('jobs.pm_warning_email', $alpha['org'], 'info@alpha.test');

            $f = $alpha['franchise']->id;
            $customer = Customer::create(['owning_franchise_id' => $f, 'number' => 'C-1', 'name' => 'A Customer']);
            $job = fn (string $n, array $a) => Job::create($a + ['franchise_id' => $f, 'customer_id' => $customer->id,
                'number' => $n, 'title' => $n, 'currency' => 'USD'])->id;
            $pay = fn (string $id, string $at, string $amount = '50.00') => WorkizRecord::create(['franchise_id' => $f, 'type' => 'payment',
                'remote_id' => 'P'.$id, 'job_id' => $id, 'amount' => $amount, 'status' => 'succeeded', 'starts_at' => $at, 'payload' => [], 'synced_at' => now()]);

            $ids = [
                'A' => $job('A', ['status' => 'in progress', 'external_company' => 'Acme', 'tags' => ['REDO']]),
                'B' => $job('B', ['status' => 'pending', 'review_reason' => 'order_parts']),
                'C' => $job('C', ['status' => 'submitted']),
                'D' => $job('D', ['status' => 'submitted', 'scheduled_for' => '2026-10-24 09:00:00']),
                'E' => $job('E', ['status' => 'dpa']),
                'F' => $job('F', ['status' => 'done']),
            ];
            $pay($ids['C'], '2026-10-07 10:00:00');
            $pay($ids['E'], '2026-09-10 10:00:00');
            $w = WorkizJob::create(['franchise_id' => $f, 'remote_id' => 'JOB-E', 'status' => 'x', 'synced_at' => now(),
                'payload' => ['JobTotalPrice' => 300, 'JobAmountDue' => 100]]);
            $w->job_id = $ids['E'];
            $w->save();

            return $ids;
        });
        $get = fn (string $url) => $this->signedInAs($alpha['admin'])->getJson("/api/v1/jobs/project-management/{$url}")->assertOk()->json();

        $list = $get('jobs?stage=ready_to_schedule');
        $this->assertSame(['in_progress' => 1, 'waiting_review' => 1, 'ready_to_schedule' => 1, 'scheduled' => 1,
            'done_waiting_payment' => 1, 'completed' => 1, 'cancelled' => 0], $list['meta']['counts']);
        $this->assertSame([['C', '2026-10-07', 15]], array_map(fn ($r) => [$r['number'], $r['paid_at'], $r['days_since_payment']], $list['data']));
        $this->assertSame(['A'], array_column($get('jobs?stage=in_progress&redo=1&external=1')['data'], 'number'));
        $this->assertSame([], $get('jobs?stage=in_progress&redo=0')['data']);
        $this->assertSame(['E'], array_column($get('jobs?stage=done_waiting_payment&stopped=1')['data'], 'number'));

        $o = $get('overview')['data'];
        $this->assertSame(['count' => 1, 'ours' => 0, 'external' => 1, 'second_visit' => 0, 'redo' => 1, 'new' => 0], $o['in_progress']);
        $this->assertSame(1, collect($o['waiting_review']['by_reason'])->firstWhere('value', 'order_parts')['count']);
        $this->assertSame(['count' => 1, 'next_7_days' => 1], $o['scheduled']);
        $this->assertSame(['count' => 1, 'total_minor' => 30000, 'due_minor' => 10000], $o['done_waiting_payment']);
        $this->assertSame(1, $o['stopped_payments']);
        $this->assertSame([['C', 15, 10]], array_map(fn ($r) => [$r['job']['number'], $r['days'], $r['limit']], $o['waiting_to_start']));

        Mail::fake();
        $this->artisan('jobs:warn-paid-not-started')->expectsOutputToContain('Sent 1 warning')->assertSuccessful();
    }
}
