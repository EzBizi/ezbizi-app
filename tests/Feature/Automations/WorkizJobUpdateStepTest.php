<?php

namespace Tests\Feature\Automations;

use App\Models\AutomationEvent;
use App\Models\Customer;
use App\Models\Job;
use App\Models\SocialConnection;
use App\Models\WorkizJob;
use App\Support\Automations\ActionRunner;
use App\Support\Social\SocialProvider;
use App\Support\Workiz\WorkizV2;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/** Jose, 2026-10-21: a rule sets the Workiz job's status and sub-status, and shows Workiz's refusal. */
class WorkizJobUpdateStepTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    public function test_a_rule_cancels_the_workiz_job_with_its_sub_status_and_reports_a_refusal_in_workizs_words(): void
    {
        $t = $this->provisionTenant('Alpha');
        $workizJob = array_replace(require base_path('tests/Fixtures/workiz/v2_job.php'), ['id' => 'JOB-1', 'status' => 'Canceled']);
        Http::fake([
            'api.workiz.com/crm/api/v2/jobSubStatus*' => Http::response(['data' => [['id' => 'JSS-9', 'name' => 'Estimate Declined']]]),
            'api.workiz.com/crm/api/v2/jobs/JOB-1' => Http::sequence()->push($workizJob)->push(['message' => 'Must assign technician to perform this action'], 400)->push($workizJob),
        ]);

        $this->inTenant($t['org'], function () use ($t) {
            $c = new SocialConnection;
            $c->forceFill(['organization_id' => $t['org']->id, 'scope_type' => SocialConnection::SCOPE_FRANCHISE, 'scope_id' => $t['franchise']->id,
                'provider' => SocialProvider::Workiz->value, 'remote_id' => $t['franchise']->id, 'display_name' => 'NY']);
            $c->connect(['api_token' => 'v2key_NY', 'refresh_token' => 'v2key_NY', 'api_version' => 'v2']);
            $c->save();
            WorkizJob::land($c, WorkizV2::job(array_replace(require base_path('tests/Fixtures/workiz/v2_job.php'), ['id' => 'JOB-1'])));
            $customer = Customer::factory()->create(['owning_franchise_id' => $t['franchise']->id]);
            $job = Job::factory()->create(['franchise_id' => $t['franchise']->id, 'customer_id' => $customer->id]);
            WorkizJob::query()->sole()->forceFill(['job_id' => $job->id])->save();

            $step = ['type' => 'workiz.job_update', 'status' => 'Canceled', 'sub_status' => 'Estimate Declined'];
            $event = new AutomationEvent(['event_key' => 'job.estimate_aging', 'payload' => []]);

            $done = app(ActionRunner::class)->run($step, $event, $job);
            $this->assertTrue($done['ok'], $done['detail']);
            Http::assertSent(fn (Request $r) => $r->method() === 'PUT' && $r['status'] === 'Canceled' && $r['subStatusId'] === 'JSS-9');

            $refused = app(ActionRunner::class)->run($step, $event, $job);
            $this->assertFalse($refused['ok']);
            $this->assertStringContainsString('Must assign technician', $refused['detail']);

            $off = app(ActionRunner::class)->run(['type' => 'workiz.job_update', 'unschedule' => true], $event, $job);
            $this->assertTrue($off['ok'], $off['detail']);
            Http::assertSent(fn (Request $r) => $r->method() === 'PUT' && ($r->data()['isScheduled'] ?? null) === false);
        });
    }
}
