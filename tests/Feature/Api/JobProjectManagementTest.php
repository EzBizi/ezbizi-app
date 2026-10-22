<?php

namespace Tests\Feature\Api;

use App\Models\AutomationEvent;
use App\Models\Customer;
use App\Models\Job;
use App\Models\WorkizRecord;
use App\Support\Settings\SettingsResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/**
 * The Project Management tab (Jose, 2026-10-21: "build it, but don't activate
 * it yet"). Every job in a watched status, and those that left one lately,
 * each as a timeline from entering it to the end, with what comes next.
 */
class JobProjectManagementTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    public function test_a_waiting_job_reads_as_a_timeline_with_its_deadline(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-21 12:00:00', 'UTC'));
        [$alpha, $job] = $this->world();

        $row = collect($this->signedInAs($alpha['admin'])->getJson('/api/v1/jobs/project-management')->assertOk()->json('data'))
            ->firstWhere('id', $job);

        $this->assertSame('partial', $row['stage']);
        $this->assertSame(['entered', 'payment', 'tags', 'status'], array_column($row['timeline'], 'kind'));
        $this->assertSame('2026-10-05', substr($row['timeline'][0]['at'], 0, 10));
        $this->assertSame(['added' => ['DEPOSIT RECEIVED'], 'removed' => []], $row['timeline'][2]['tags']);
        $this->assertSame('Automation', $row['timeline'][3]['by']);
        // Partly paid: the clock has stopped (Jose: the timer stops once a payment arrives).
        $this->assertSame(['estimate_date' => '2026-10-01', 'days_waiting' => 20, 'deadline' => null, 'timer' => 'stopped'],
            array_intersect_key($row['waiting'], array_flip(['estimate_date', 'days_waiting', 'deadline', 'timer'])));
        $this->assertSame(5000, $row['timeline'][1]['amount_minor']);
    }

    /** Money follows the role: without Financials no amount, though the stage still says partial. */
    public function test_without_financials_no_amounts(): void
    {
        [$alpha, $job] = $this->world();
        $pm = $this->userWithRole($alpha, 'PM', ['jobs.view', 'project_management.view']);

        $body = $this->signedInAs($pm)->getJson('/api/v1/jobs/project-management')->assertOk();
        $this->assertSame('partial', $body->json('data.0.stage'));
        $this->assertStringNotContainsString('amount_minor', $body->getContent());
        $this->assertStringNotContainsString('5000', $body->getContent());

        $this->signedInAs($this->userWithRole($alpha, 'Viewer', ['jobs.view']))
            ->getJson('/api/v1/jobs/project-management')->assertForbidden();
        // Jose, 2026-10-21: Project Management also needs Jobs view.
        $this->signedInAs($this->userWithRole($alpha, 'PM only', ['project_management.view']))
            ->getJson('/api/v1/jobs/project-management/stats?from=2026-10-01&to=2026-10-02')->assertForbidden();
    }

    public function test_with_nothing_paid_the_clock_runs_to_its_deadline(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-21 12:00:00', 'UTC'));
        [$alpha, $job] = $this->world();
        $this->inTenant($alpha['org'], fn () => \App\Models\WorkizRecord::query()->where('type', 'payment')->delete());

        $waiting = $this->signedInAs($alpha['admin'])->getJson('/api/v1/jobs/project-management')->assertOk()->json('data.0.waiting');

        $this->assertSame(['running', '2026-10-31'], [$waiting['timer'], $waiting['deadline']]);
        $this->assertStringStartsWith('2026-10-31T00:00:00', $waiting['deadline_at']);
    }

    /** Jose, 2026-10-22: what happened in a window, each stat opening its jobs. */
    public function test_the_dashboard_counts_what_happened_in_the_window(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-21 12:00:00', 'UTC'));
        [$alpha, $job] = $this->world();

        $stats = $this->signedInAs($alpha['admin'])->getJson('/api/v1/jobs/project-management/stats?from=2026-10-05&to=2026-10-12')
            ->assertOk()->json('data');

        $this->assertSame(['count' => 1, 'job_ids' => [$job], 'amount_minor' => 5000], $stats['payments']);
        // The rule's dpa → dpa is not a change; the entry on the 5th is.
        $this->assertSame(['count' => 1, 'job_ids' => [$job]], $stats['status_changes']);
        $this->assertSame(['count' => 1, 'job_ids' => [$job]], $stats['tag_changes']);
        $this->assertSame(0, $stats['cancellations']['count'], 'no cancelled statuses named');

        $this->assertSame(0, $this->signedInAs($alpha['admin'])
            ->getJson('/api/v1/jobs/project-management/stats?from=2026-10-13&to=2026-10-14')->json('data.payments.count'));
    }

    public function test_recently_paid_and_drill_down_and_each_step_reads_as_a_sentence(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-21 12:00:00', 'UTC'));
        [$alpha, $job] = $this->world();
        $get = fn (string $q) => $this->signedInAs($alpha['admin'])->getJson("/api/v1/jobs/project-management?{$q}")->assertOk()->json('data');

        $this->assertSame([$job], array_column($get('filter=recently_paid&from=2026-10-10&to=2026-10-10'), 'id'));
        $this->assertSame([], $get('filter=recently_paid&from=2026-10-11&to=2026-10-20'));
        $row = $get('ids[]='.$job)[0];
        $this->assertSame('Payment received, partly paid', $row['timeline'][1]['what']);
        $this->assertSame('+DEPOSIT RECEIVED', $row['timeline'][2]['what']);
        $this->assertSame('Entered Done, pending approval', $row['timeline'][0]['what'], 'the label, not the key');
    }

    /**
     * Jose, 2026-10-22: "Today" showed jobs with nothing today (the window was
     * UTC days, last evening's Eastern changes), and Yesterday failed (514 ids).
     */
    public function test_the_window_is_the_viewers_days_and_a_stat_opens_by_name(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-21 12:00:00', 'UTC'));
        [$alpha, $job] = $this->world();
        $get = fn (string $q) => $this->signedInAs($alpha['admin'])->getJson("/api/v1/jobs/project-management{$q}")->assertOk()->json('data');

        // The tag change is 09:00 UTC on the 12th: the 12th in New York, the 11th in Pago Pago.
        $this->assertSame(1, $get('/stats?from=2026-10-12&to=2026-10-12&timezone=America/New_York')['tag_changes']['count']);
        $this->assertSame(0, $get('/stats?from=2026-10-12&to=2026-10-12&timezone=Pacific/Pago_Pago')['tag_changes']['count']);

        $this->assertSame([$job], array_column($get('?event=tag_changes&from=2026-10-12&to=2026-10-12&timezone=America/New_York'), 'id'));
        $this->assertSame([], $get('?event=tag_changes&from=2026-10-12&to=2026-10-12&timezone=Pacific/Pago_Pago'));
        $this->assertArrayHasKey('technician', $get('?event=tag_changes&from=2026-10-12&to=2026-10-12')[0]);
    }

    /** 2026-10-22: with no watched statuses the stage lists are empty, but a stat's jobs and Recently Paid are not. */
    public function test_drill_downs_stand_without_watched_statuses(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-21 12:00:00', 'UTC'));
        [$alpha, $job] = $this->world();
        $this->inTenant($alpha['org'], fn () => app(SettingsResolver::class)->set('jobs.estimate_aging_statuses', $alpha['org'], []));
        $get = fn (string $q) => array_column($this->signedInAs($alpha['admin'])->getJson("/api/v1/jobs/project-management?{$q}")->assertOk()->json('data'), 'id');

        $this->assertSame([], $get(''));
        $this->assertSame([$job], $get('event=payments&from=2026-10-10&to=2026-10-10'));
        $this->assertSame([$job], $get('filter=recently_paid&from=2026-10-10&to=2026-10-10'));
    }

    /** Jose, 2026-10-22: what EzBizi itself did, and a Workiz step EzBizi caused named as EzBizi's. */
    public function test_logs_list_ezbizis_own_changes_and_the_timeline_credits_the_rule(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-21 12:00:00', 'UTC'));
        [$alpha, $job] = $this->world();

        $this->inTenant($alpha['org'], function () use ($alpha, $job) {
            $model = Job::query()->findOrFail($job);
            $emit = function (string $key, array $payload, string $at) use ($model) {
                $e = app(\App\Support\Automations\EventEmitter::class)->emit($key, $model, $payload);
                $e->forceFill(['occurred_at' => $at])->save();

                return $e;
            };
            $rule = \App\Models\Automation::create(['organization_id' => $alpha['org']->id, 'name' => 'Payment on DPA',
                'trigger_key' => 'job.payment_received', 'is_active' => true]);
            $actions = [['type' => 'workiz.job_update', 'status' => 'Submitted', 'tags' => 'Deposit Received']];
            $version = \App\Models\AutomationVersion::create(['organization_id' => $alpha['org']->id, 'automation_id' => $rule->id,
                'version' => 1, 'conditions' => null, 'actions' => $actions, 'created_at' => now()]);
            $run = fn (string $at, string $status, array $extra) => \App\Models\AutomationRun::create($extra + [
                'automation_id' => $rule->id, 'automation_version_id' => $version->id, 'status' => $status,
                'event_id' => $emit('job.payment_received', [], $at)->id, 'started_at' => $at, 'finished_at' => Carbon::parse($at)->addSeconds(5),
                'actions_snapshot' => $actions, 'action_count' => 1]);

            $run('2026-10-14 10:00:00', 'succeeded', ['action_results' => [['type' => 'workiz.job_update', 'ok' => true, 'state' => 'succeeded',
                'detail' => 'Set status Submitted; tags Deposit Received in Workiz.']]]);
            // Workiz's sync reports the change ten minutes later, as its own.
            $emit('job.status_changed', ['before' => ['status' => 'dpa'], 'after' => ['status' => 'submitted']], '2026-10-14 10:10:00');
            $run('2026-10-15 09:00:00', 'skipped', ['action_results' => [], 'condition_trace' => ['type' => 'all', 'matched' => false,
                'children' => [['type' => 'condition', 'field' => 'status', 'op' => 'eq', 'actual' => 'scheduled', 'expected' => 'dpa', 'matched' => false]]]]);
            $emit('job.updated', ['before' => ['title' => 'A job'], 'after' => ['title' => 'Renamed']], '2026-10-15 11:00:00')
                ->forceFill(['actor_id' => $alpha['admin']->id, 'actor_label' => 'Ada Admin <ada@example.test>'])->save();
        });

        $row = collect($this->signedInAs($alpha['admin'])->getJson('/api/v1/jobs/project-management?ids[]='.$job)->assertOk()->json('data.0.timeline'))
            ->firstWhere('to', 'submitted');
        $this->assertSame('EzBizi · Payment on DPA', $row['by']);

        $logs = $this->signedInAs($alpha['admin'])->getJson('/api/v1/jobs/project-management/logs?from=2026-10-14&to=2026-10-15&timezone=UTC')
            ->assertOk()->json('data');
        $this->assertSame(['manual', 'skipped', 'succeeded'], array_column($logs, 'outcome'));
        $this->assertSame('Ada Admin', $logs[0]['by']);
        $this->assertSame('Title: "Renamed"', $logs[0]['changes'][0]['detail']);
        $this->assertSame('Status was Scheduled; the rule wants Done, pending approval.', $logs[1]['reason']);
        $this->assertSame(['Payment on DPA', 'J-1', 'A Customer'], [$logs[2]['rule']['name'], $logs[2]['job']['number'], $logs[2]['job']['customer']]);
        $this->assertSame('Set status Submitted; tags Deposit Received in Workiz.', $logs[2]['changes'][0]['detail']);

        $skipped = $this->signedInAs($alpha['admin'])
            ->getJson('/api/v1/jobs/project-management/logs?from=2026-10-14&to=2026-10-15&timezone=UTC&outcome=skipped')->json();
        $this->assertSame(['skipped'], array_column($skipped['data'], 'outcome'));
        // Counts for every outcome whatever is selected, and the skips folded per rule.
        $this->assertSame(['succeeded' => 1, 'failed' => 0, 'skipped' => 1, 'manual' => 1], $skipped['meta']['counts']);
        $this->assertSame([['reason' => 'Status was Scheduled; the rule wants Done, pending approval.', 'count' => 1]],
            $skipped['meta']['skipped_by_rule'][0]['reasons']);
    }

    public function test_filters_by_stage_branch_and_days(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-21 12:00:00', 'UTC'));
        [$alpha, $job] = $this->world();
        $get = fn (string $q) => array_column($this->signedInAs($alpha['admin'])->getJson("/api/v1/jobs/project-management?{$q}")->assertOk()->json('data'), 'id');

        $this->assertSame([$job], $get('stage=partial'));
        // Every chip's count on every call (UI/UX, 2026-10-22).
        $this->assertSame(['recently_paid' => 1, 'waiting' => 0, 'partial' => 1, 'full' => 0, 'closed' => 0],
            $this->signedInAs($alpha['admin'])->getJson('/api/v1/jobs/project-management?stage=full&from=2026-10-10&to=2026-10-10')->json('meta.counts'));
        $this->assertSame([], $get('stage=full'));
        $this->assertSame([$job], $get('franchise_id='.$alpha['franchise']->id));
        $this->assertSame([], $get('min_days=25'));
        // Search: number, customer, phone (digits only), Workiz number.
        $this->assertSame([$job], $get('q=J-1'));
        $this->assertSame([$job], $get('q=A+Customer'));
        $this->assertSame([$job], $get('q=(555)%20010'));
        $this->assertSame([$job], $get('q=7000242'));
        $this->assertSame([], $get('q=nobody'));
    }

    /** @return array{0: array, 1: string} */
    private function world(): array
    {
        $alpha = $this->provisionTenant('Alpha');

        $job = $this->inTenant($alpha['org'], function () use ($alpha) {
            $settings = app(SettingsResolver::class);
            $settings->set('jobs.statuses', $alpha['org'], [
                ['value' => 'scheduled', 'label' => 'Scheduled'],
                ['value' => 'dpa', 'label' => 'Done, pending approval'],
                ['value' => 'submitted', 'label' => 'Submitted'],
            ]);
            $settings->set('jobs.estimate_aging_statuses', $alpha['org'], ['dpa']);

            $customer = Customer::create(['owning_franchise_id' => $alpha['franchise']->id, 'number' => 'C-1', 'name' => 'A Customer', 'phone' => '+15550101234']);
            $job = Job::create(['franchise_id' => $alpha['franchise']->id, 'customer_id' => $customer->id,
                'number' => 'J-1', 'title' => 'A job', 'status' => 'dpa', 'currency' => 'USD']);

            $event = function (string $key, array $payload, string $at, ?AutomationEvent $cause = null) use ($job) {
                $e = app(\App\Support\Automations\EventEmitter::class)->emit($key, $job, $payload, causedBy: $cause);
                $e->forceFill(['occurred_at' => $at])->save();

                return $e;
            };
            $event('job.status_changed', ['before' => ['status' => 'scheduled'], 'after' => ['status' => 'dpa']], '2026-10-05 15:00:00');
            $cause = $event('job.updated', ['before' => ['tags' => []], 'after' => ['tags' => ['DEPOSIT RECEIVED']]], '2026-10-12 09:00:00');
            $event('job.status_changed', ['before' => ['status' => 'dpa'], 'after' => ['status' => 'dpa']], '2026-10-12 09:01:00', $cause);

            WorkizRecord::create(['franchise_id' => $alpha['franchise']->id, 'type' => 'estimate', 'remote_id' => 'E1', 'job_id' => $job->id,
                'amount' => '200.00', 'starts_at' => '2026-10-01 10:00:00', 'payload' => [], 'synced_at' => now()]);
            WorkizRecord::create(['franchise_id' => $alpha['franchise']->id, 'type' => 'payment', 'remote_id' => 'P1', 'job_id' => $job->id,
                'amount' => '50.00', 'status' => 'succeeded', 'starts_at' => '2026-10-10 10:00:00', 'payload' => ['type' => 'charge'], 'synced_at' => now()]);
            WorkizJobFixture::mirror($job, ['JobTotalPrice' => 200]);

            return $job->id;
        });

        return [$alpha, $job];
    }
}

/** A Workiz mirror row for a job, with the money the total comes from. */
final class WorkizJobFixture
{
    public static function mirror(Job $job, array $payload): void
    {
        $row = \App\Models\WorkizJob::create(['franchise_id' => $job->franchise_id, 'remote_id' => 'JOB-'.$job->number, 'serial_id' => '700024283',
            'status' => 'x', 'payload' => $payload, 'synced_at' => now()]);
        $row->job_id = $job->id;
        $row->save();
    }
}
