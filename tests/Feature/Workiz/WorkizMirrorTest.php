<?php

namespace Tests\Feature\Workiz;

use App\Jobs\MirrorToWorkiz;
use App\Models\AuditEvent;
use App\Models\AutomationEvent;
use App\Models\Customer;
use App\Models\Job;
use App\Models\SocialConnection;
use App\Models\WorkizJob;
use App\Support\Audit\AuditLogger;
use App\Support\Automations\ActionRunner;
use App\Support\Settings\SettingsResolver;
use App\Support\Social\SocialProvider;
use App\Support\Tenancy\TenantContext;
use App\Support\Workiz\WorkizJobWriter;
use App\Support\Workiz\WorkizMirror;
use App\Support\Workiz\WorkizV2;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/** EzBizi master, Workiz mirror (Jose, 2026-10-27): a change made in EzBizi is queued and written to Workiz. */
class WorkizMirrorTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    private array $t;

    private Job $job;

    protected function setUp(): void
    {
        parent::setUp();
        $this->t = $this->provisionTenant('Alpha');
        $this->job = $this->inTenant($this->t['org'], function () {
            $c = new SocialConnection;
            $c->forceFill(['organization_id' => $this->t['org']->id, 'scope_type' => SocialConnection::SCOPE_FRANCHISE, 'scope_id' => $this->t['franchise']->id,
                'provider' => SocialProvider::Workiz->value, 'remote_id' => $this->t['franchise']->id, 'display_name' => 'NY']);
            $c->connect(['api_token' => 'v2key_NY', 'refresh_token' => 'v2key_NY', 'api_version' => 'v2']);
            $c->save();
            WorkizJob::land($c, WorkizV2::job(array_replace(require base_path('tests/Fixtures/workiz/v2_job.php'), ['id' => 'JOB-1'])));
            $customer = Customer::factory()->create(['owning_franchise_id' => $this->t['franchise']->id]);
            $job = Job::factory()->create(['franchise_id' => $this->t['franchise']->id, 'customer_id' => $customer->id]);
            WorkizJob::query()->sole()->forceFill(['job_id' => $job->id])->save();

            return $job;
        });
    }

    public function test_a_status_change_reaches_the_workiz_job(): void
    {
        $answer = array_replace(require base_path('tests/Fixtures/workiz/v2_job.php'), ['id' => 'JOB-1', 'status' => 'Pending']);
        Http::fake(['api.workiz.com/crm/api/v2/jobs/JOB-1' => Http::response($answer)]);

        $this->inTenant($this->t['org'], fn () => WorkizMirror::queue($this->job, 'status', ['status' => 'Pending']));

        Http::assertSent(fn (Request $r) => $r->method() === 'PUT' && $r['status'] === 'Pending');
    }

    public function test_the_automation_job_set_step_queues_its_changes_for_workiz(): void
    {
        Queue::fake();
        $this->inTenant($this->t['org'], function () {
            $event = AutomationEvent::create(['organization_id' => $this->t['org']->id, 'event_key' => 'job.payment_received', 'payload' => [],
                'occurred_at' => now(), 'available_at' => now()]);
            $done = app(ActionRunner::class)->run(['type' => 'job.set', 'status' => Job::statusEntries($this->t['org'])[0]['value'],
                'tags' => 'Deposit Received', 'unschedule' => true], $event, $this->job);
            $this->assertTrue($done['ok'], $done['detail']);
        });

        $ops = [];
        Queue::assertPushed(MirrorToWorkiz::class, function (MirrorToWorkiz $j) use (&$ops) {
            $ops[] = $j->op;

            return true;
        });
        $this->assertSame(['status', 'tags', 'unschedule'], $ops);
    }

    public function test_nothing_is_queued_where_the_branch_does_not_mirror(): void
    {
        Queue::fake();
        $this->inTenant($this->t['org'], function () {
            app(SettingsResolver::class)->set('workiz.mirror', $this->t['franchise'], false);
            WorkizMirror::queue($this->job, 'status', ['status' => 'Pending']);
        });
        Queue::assertNothingPushed();

        $this->inTenant($this->t['org'], function () {
            app(SettingsResolver::class)->set('workiz.mirror', $this->t['franchise'], true);
            SocialConnection::query()->update(['status' => 'disconnected']);
            WorkizMirror::queue($this->job, 'status', ['status' => 'Pending']);
        });
        Queue::assertNothingPushed();
    }

    public function test_a_passing_refusal_is_retried_later_and_a_final_one_is_recorded(): void
    {
        // One fake: Http::fake stacks, it does not replace.
        Http::fake(['api.workiz.com/crm/api/v2/jobs/JOB-1' => Http::sequence()
            ->push(['message' => 'Rate limit exceeded'], 429)->push(['message' => 'Must assign technician to perform this action'], 400)]);
        Queue::fake();

        $this->inTenant($this->t['org'], fn () => (new MirrorToWorkiz($this->t['org']->id, $this->job->id, 'status', ['status' => 'Pending']))
            ->handle(app(TenantContext::class), app(WorkizJobWriter::class), app(AuditLogger::class)));
        Queue::assertPushed(MirrorToWorkiz::class, fn (MirrorToWorkiz $j) => $j->attempt === 2);

        $this->inTenant($this->t['org'], fn () => (new MirrorToWorkiz($this->t['org']->id, $this->job->id, 'status', ['status' => 'done pending approval']))
            ->handle(app(TenantContext::class), app(WorkizJobWriter::class), app(AuditLogger::class)));

        $failed = $this->inTenant($this->t['org'], fn () => AuditEvent::query()->where('action', 'workiz.mirror_failed')->get());
        $this->assertCount(1, $failed, 'the rate limit was retried, not recorded; the refusal was recorded');
    }
}
