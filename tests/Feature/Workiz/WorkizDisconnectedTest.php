<?php

namespace Tests\Feature\Workiz;

use App\Models\SocialConnection;
use App\Support\Social\SocialProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/**
 * "Workiz disconnected" (Jose, 2026-10-27: EzBizi master until Workiz is
 * disconnected). With no key, or a key that was revoked, every sweep,
 * webhook and writer no-ops: no call to Workiz, no error, nothing in
 * failed_jobs.
 */
class WorkizDisconnectedTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    /** Every Workiz command the scheduler runs (routes/console.php). */
    private const COMMANDS = [
        'ezbizi:sync-workiz', 'ezbizi:sync-workiz-team', 'ezbizi:sync-workiz-records', 'ezbizi:sync-workiz-calls',
        'ezbizi:sync-workiz-client-ids', 'ezbizi:import-workiz-leads', 'ezbizi:project-workiz', 'ezbizi:workiz-fill-creators',
        'ezbizi:workiz-web-payments', 'ezbizi:workiz-web-estimate-links', 'ezbizi:workiz-web-lists', 'ezbizi:workiz-web-job-companies',
    ];

    private function runEverything(): void
    {
        Http::fake();

        foreach (self::COMMANDS as $command) {
            $before = count(Http::recorded());
            $this->artisan($command)->assertSuccessful();
            $this->assertSame($before, count(Http::recorded()), $command.' called Workiz');
        }
        $this->artisan('ezbizi:backfill-workiz', ['--pending' => true, '--since' => '2000-01-01', '--pages' => 1, '--pause' => 0])->assertSuccessful();

        Http::assertNothingSent();
        $this->assertSame(0, DB::table('failed_jobs')->count());
    }

    public function test_an_organization_without_workiz_runs_every_sweep_quietly(): void
    {
        $this->provisionTenant('Alpha');

        $this->runEverything();
    }

    /**
     * Disconnecting deletes the connection (SocialConnectionsController), and
     * the mirror stays: jobs, records, team, a web login's reads. Nothing that
     * reads them may reach for the key that is gone.
     */
    public function test_a_disconnected_branch_with_its_mirror_left_behind_is_never_called(): void
    {
        $t = $this->provisionTenant('Alpha');
        $gone = $this->inTenant($t['org'], function () use ($t) {
            $c = new SocialConnection;
            $c->forceFill(['organization_id' => $t['org']->id, 'scope_type' => SocialConnection::SCOPE_FRANCHISE, 'scope_id' => $t['franchise']->id,
                'provider' => SocialProvider::Workiz->value, 'remote_id' => $t['franchise']->id, 'display_name' => 'Workiz']);
            $c->connect(['api_token' => 'k', 'refresh_token' => 'k', 'api_version' => 'v2']);
            $c->save();
            \App\Models\WorkizJob::land($c, \App\Support\Workiz\WorkizV2::job(array_replace(require base_path('tests/Fixtures/workiz/v2_job.php'), ['id' => 'JOB-1'])));
            $id = $c->id;
            $c->delete();

            return $id;
        });

        $this->runEverything();

        // A rule's Workiz step and the outbound mirror skip, rather than fail.
        $this->inTenant($t['org'], function () use ($t) {
            $customer = \App\Models\Customer::factory()->create(['owning_franchise_id' => $t['franchise']->id]);
            $job = \App\Models\Job::factory()->create(['franchise_id' => $t['franchise']->id, 'customer_id' => $customer->id]);
            \App\Models\WorkizJob::query()->update(['job_id' => $job->id]);
            $step = app(\App\Support\Automations\ActionRunner::class)->run(['type' => 'workiz.job_update', 'status' => 'Pending'],
                new \App\Models\AutomationEvent(['event_key' => 'job.updated', 'payload' => []]), $job);
            $this->assertSame([true, true], [$step['ok'], $step['skipped'] ?? false], $step['detail']);
            \Illuminate\Support\Facades\Queue::fake();
            \App\Support\Workiz\WorkizMirror::queue($job, 'status', ['status' => 'Pending']);
            \Illuminate\Support\Facades\Queue::assertNothingPushed();
        });
        Http::assertNothingSent();
        $this->postJson('/api/v1/social/workiz/webhook/'.$gone, ['data' => ['id' => 'JOB-abcd1234']])->assertNotFound();
    }
}
