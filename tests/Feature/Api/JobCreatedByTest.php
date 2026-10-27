<?php

namespace Tests\Feature\Api;

use App\Models\Customer;
use App\Models\Job;
use App\Models\WorkizJob;
use App\Models\WorkizTeamMember;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/** Who opened a job and when, on the job (Jose, 2026-10-27: EzBizi the master). */
class JobCreatedByTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    public function test_a_person_opening_a_job_is_kept(): void
    {
        $alpha = $this->provisionTenant('Alpha');
        $customer = $this->inTenant($alpha['org'], fn () => Customer::create(['owning_franchise_id' => $alpha['franchise']->id, 'number' => 'C-1', 'name' => 'A'])->id);

        $this->signedInAs($alpha['admin'])->postJson('/api/v1/jobs', ['franchise_id' => $alpha['franchise']->id, 'customer_id' => $customer,
            'title' => 'Sweep', 'status' => 'scheduled'])->assertCreated()
            ->assertJsonPath('data.created_by.user_id', $alpha['admin']->id)
            ->assertJsonPath('data.created_by.name', $alpha['admin']->name);
    }

    public function test_a_mirrored_job_takes_workizs_birth_and_creator(): void
    {
        $alpha = $this->provisionTenant('Alpha');
        $tech = $this->userWithRole($alpha, 'Tech', ['jobs.view']);
        [$linked, $unlinked] = $this->inTenant($alpha['org'], function () use ($alpha, $tech) {
            $f = $alpha['franchise'];
            $f->forceFill(['timezone' => 'America/New_York'])->save();
            $c = Customer::create(['owning_franchise_id' => $f->id, 'number' => 'C-1', 'name' => 'A']);
            WorkizTeamMember::create(['franchise_id' => $f->id, 'remote_id' => 'U1', 'name' => 'Ron Levi', 'user_id' => $tech->id, 'payload' => [], 'synced_at' => now()]);
            $mk = function (string $n, array $p) use ($f, $c) {
                $job = Job::create(['franchise_id' => $f->id, 'customer_id' => $c->id, 'number' => $n, 'title' => $n, 'status' => 'x', 'currency' => 'USD']);
                $w = WorkizJob::create(['franchise_id' => $f->id, 'remote_id' => 'W'.$n, 'status' => 'x', 'payload' => $p, 'synced_at' => now()]);
                $w->job_id = $job->id;
                $w->save();

                return $job->id;
            };

            return [$mk('A', ['CreatedDate' => '2026-03-05 09:30:00', 'CreatedBy' => 'Ron Levi']),
                $mk('B', ['CreatedDate' => '2026-07-01 16:00:00', 'CreatedBy' => 'Jovanni Martin'])];
        });

        $this->artisan('jobs:backfill-workiz-created')->expectsOutputToContain('Set 2 jobs')->assertSuccessful();
        $this->artisan('jobs:backfill-workiz-created')->expectsOutputToContain('Set 0 jobs')->assertSuccessful();

        $read = fn (string $id) => $this->inTenant($alpha['org'], fn () => Job::query()->findOrFail($id));
        // 9:30 in New York in March is 14:30 UTC (EST).
        $this->assertSame(['2026-03-05 14:30:00', $tech->id, 'Ron Levi'],
            [$read($linked)->created_at->utc()->format('Y-m-d H:i:s'), $read($linked)->created_by_user_id, $read($linked)->created_by_label]);
        $this->assertSame([null, 'Jovanni Martin'], [$read($unlinked)->created_by_user_id, $read($unlinked)->created_by_label],
            'a Workiz dispatcher with no EzBizi user is still named');
    }
}
