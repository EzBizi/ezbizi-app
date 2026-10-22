<?php

namespace Tests\Feature\Workiz;

use App\Models\SocialConnection;
use App\Models\WorkizJob;
use App\Support\Social\SocialProvider;
use App\Support\Workiz\WorkizJobWriter;
use App\Support\Workiz\WorkizV2;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/**
 * The writes Jose's Project Management rules make (Automations, 2026-10-21),
 * shaped as measured on NY's test job 900018655 on 2026-10-20.
 */
class WorkizJobStatusAndTagsTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    private array $t;

    private WorkizJob $job;

    protected function setUp(): void
    {
        parent::setUp();
        $this->t = $this->provisionTenant('Alpha');
        $this->job = $this->inTenant($this->t['org'], function () {
            $c = new SocialConnection;
            $c->forceFill([
                'organization_id' => $this->t['org']->id, 'scope_type' => SocialConnection::SCOPE_FRANCHISE,
                'scope_id' => $this->t['franchise']->id, 'provider' => SocialProvider::Workiz->value,
                'remote_id' => $this->t['franchise']->id, 'display_name' => 'New York',
            ]);
            $c->connect(['api_token' => 'v2key_NY', 'refresh_token' => 'v2key_NY', 'api_version' => 'v2']);
            $c->save();
            WorkizJob::land($c, WorkizV2::job(array_replace(require base_path('tests/Fixtures/workiz/v2_job.php'), ['id' => 'JOB-1'])));

            return WorkizJob::query()->sole();
        });
    }

    private function writer(): WorkizJobWriter
    {
        return app(WorkizJobWriter::class);
    }

    public function test_a_status_and_sub_status_go_up_by_the_accounts_own_ids(): void
    {
        $job = array_replace(require base_path('tests/Fixtures/workiz/v2_job.php'), ['id' => 'JOB-1', 'status' => 'Canceled']);
        Http::fake([
            'api.workiz.com/crm/api/v2/jobSubStatus*' => Http::response(['data' => [['id' => 'JSS-1', 'name' => 'Duplicate Job'], ['id' => 'JSS-9', 'name' => 'Estimate Declined']]]),
            'api.workiz.com/crm/api/v2/jobs/JOB-1' => Http::response($job),
        ]);

        $this->inTenant($this->t['org'], fn () => $this->writer()->setStatus($this->job, 'canceled', 'estimate declined'));

        Http::assertSent(fn (Request $r) => $r->method() === 'PUT' && $r['status'] === 'Canceled' && $r['subStatusId'] === 'JSS-9');
        $this->assertSame('Canceled', $this->inTenant($this->t['org'], fn () => $this->job->fresh()->status));
    }

    public function test_workizs_own_refusal_is_thrown_as_it_reads(): void
    {
        Http::fake(['api.workiz.com/crm/api/v2/jobs/JOB-1' => Http::response(['message' => 'Must assign technician to perform this action'], 400)]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Must assign technician to perform this action');

        $this->inTenant($this->t['org'], fn () => $this->writer()->setStatus($this->job, 'done pending approval'));
    }

    /** Jose 10-21: the list is replaced and the dropped tags audited; NY spells it "DEPOSIT RECIEVED". */
    public function test_tags_replace_the_jobs_own_by_the_accounts_spelling(): void
    {
        $job = array_replace(require base_path('tests/Fixtures/workiz/v2_job.php'), ['id' => 'JOB-1', 'tags' => [['id' => 'TAG-1', 'name' => 'Membership'], ['id' => 'TAG-D', 'name' => 'DEPOSIT RECIEVED']]]);
        Http::fake([
            'api.workiz.com/crm/api/v2/tags*' => Http::response(['data' => [['id' => 'TAG-1', 'name' => 'Membership'], ['id' => 'TAG-D', 'name' => 'DEPOSIT RECIEVED'], ['id' => 'TAG-T', 'name' => 'DONT CHANGE TECH']]]),
            'api.workiz.com/crm/api/v2/jobs/JOB-1' => function (Request $r) use ($job) {
                return Http::response($r->method() === 'GET' ? ['data' => [$job]] : $job);
            },
        ]);

        $this->inTenant($this->t['org'], fn () => $this->writer()->setTags($this->job, ['DEPOSIT RECEIVED', 'dont  change tech']));

        Http::assertSent(fn (Request $r) => $r->method() === 'PUT' && $r['tags'] === ['TAG-D', 'TAG-T']);
        $this->assertSame(['Membership'], $this->inTenant($this->t['org'], fn () => \App\Models\AuditEvent::query()->where('action', 'workiz.job_tags_set')->sole()->metadata['removed']));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Workiz has no tag "VIP" in this account.');
        $this->inTenant($this->t['org'], fn () => $this->writer()->setTags($this->job, ['VIP']));
    }

    /** Jose's Action 1, 2026-10-22: payment in, time off the schedule. isScheduled:false is what Workiz takes (measured on 900018655). */
    public function test_unschedule_clears_the_jobs_time_in_workiz(): void
    {
        $job = array_replace(require base_path('tests/Fixtures/workiz/v2_job.php'), ['id' => 'JOB-1', 'isScheduled' => false, 'date' => null, 'endDate' => null]);
        Http::fake(['api.workiz.com/crm/api/v2/jobs/JOB-1' => Http::response($job)]);

        $this->inTenant($this->t['org'], fn () => $this->writer()->unschedule($this->job));

        Http::assertSent(fn (Request $r) => $r->method() === 'PUT' && $r->data() === ['isScheduled' => false]);
    }
}
