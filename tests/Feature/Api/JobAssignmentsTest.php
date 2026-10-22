<?php

namespace Tests\Feature\Api;

use App\Models\Customer;
use App\Models\Job;
use App\Models\WorkizJob;
use App\Models\WorkizRecord;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/** Shoval via Jose, 2026-10-22: paid jobs assigned to a technician, like a task board. */
class JobAssignmentsTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    public function test_paid_jobs_are_grouped_and_assigned(): void
    {
        $alpha = $this->provisionTenant('Alpha');
        $tech = $this->userWithRole($alpha, 'Tech', ['jobs.view']);
        $this->inTenant($alpha['org'], fn () => $tech->forceFill(['name' => 'Ron Levi'])->save());
        [$paid, $unpaid, $workiz] = $this->inTenant($alpha['org'], function () use ($alpha) {
            $f = $alpha['franchise']->id;
            $c = Customer::create(['owning_franchise_id' => $f, 'number' => 'C-1', 'name' => 'A Customer']);
            $job = fn (string $n) => Job::create(['franchise_id' => $f, 'customer_id' => $c->id, 'number' => $n, 'title' => $n,
                'status' => 'submitted', 'currency' => 'USD'])->id;
            $ids = [$job('PAID'), $job('UNPAID'), $job('WZ')];
            foreach ([$ids[0], $ids[2]] as $i => $id) {
                WorkizRecord::create(['franchise_id' => $f, 'type' => 'payment', 'remote_id' => "P{$i}", 'job_id' => $id, 'amount' => '50.00',
                    'status' => 'succeeded', 'starts_at' => '2026-10-2'.$i.' 10:00:00', 'payload' => [], 'synced_at' => now()]);
            }
            $w = WorkizJob::create(['franchise_id' => $f, 'remote_id' => 'JOB-WZ', 'status' => 'x', 'payload' => [], 'synced_at' => now()]);
            $w->job_id = $ids[2];
            $w->save();

            return $ids;
        });
        $board = fn () => $this->signedInAs($alpha['admin'])->getJson('/api/v1/jobs/project-management/assignments')->assertOk()->json('data');

        $this->assertSame(['WZ', 'PAID'], array_column($board()['unassigned'], 'number'), 'paid only, newest payment first');

        $this->signedInAs($alpha['admin'])->postJson("/api/v1/jobs/project-management/jobs/{$paid}/assign", ['technician_id' => $tech->id])
            ->assertOk()->assertJsonPath('data.technician.name', 'Ron Levi');
        // A Workiz job whose technician has no Workiz link: Workiz cannot be told, so nothing changes.
        $this->signedInAs($alpha['admin'])->postJson("/api/v1/jobs/project-management/jobs/{$workiz}/assign", ['technician_id' => $tech->id])
            ->assertUnprocessable()->assertJsonPath('errors.technician_id.0', "Ron Levi is not linked to anyone on this branch's Workiz, so Workiz cannot be told.");
        $this->signedInAs($alpha['admin'])->postJson("/api/v1/jobs/project-management/jobs/{$workiz}/assign", ['external_company' => 'Acme Masonry'])
            ->assertOk()->assertJsonPath('data.external_company', 'Acme Masonry');

        $b = $board();
        $this->assertSame([], $b['unassigned']);
        $this->assertSame([['Ron Levi', 1]], array_map(fn ($t) => [$t['name'], $t['count']], $b['technicians']));
        $this->assertSame(['WZ'], array_column($b['external']['jobs'], 'number'));
        $this->assertSame(1, $this->inTenant($alpha['org'], fn () => \App\Models\AuditEvent::query()->where('action', 'job.assigned')->where('target_id', $paid)->count()));

        // Reading the board is not changing a job.
        $this->signedInAs($this->userWithRole($alpha, 'PM', ['jobs.view', 'project_management.view']))
            ->postJson("/api/v1/jobs/project-management/jobs/{$unpaid}/assign", ['technician_id' => $tech->id])->assertForbidden();
    }
}
