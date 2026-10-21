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

    public function test_filters_by_stage_branch_and_days(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-21 12:00:00', 'UTC'));
        [$alpha, $job] = $this->world();
        $get = fn (string $q) => array_column($this->signedInAs($alpha['admin'])->getJson("/api/v1/jobs/project-management?{$q}")->assertOk()->json('data'), 'id');

        $this->assertSame([$job], $get('stage=partial'));
        $this->assertSame([], $get('stage=full'));
        $this->assertSame([$job], $get('franchise_id='.$alpha['franchise']->id));
        $this->assertSame([], $get('min_days=25'));
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

            $customer = Customer::create(['owning_franchise_id' => $alpha['franchise']->id, 'number' => 'C-1', 'name' => 'A Customer']);
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
        $row = \App\Models\WorkizJob::create(['franchise_id' => $job->franchise_id, 'remote_id' => 'JOB-'.$job->number,
            'status' => 'x', 'payload' => $payload, 'synced_at' => now()]);
        $row->job_id = $job->id;
        $row->save();
    }
}
