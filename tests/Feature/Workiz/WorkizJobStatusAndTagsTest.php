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
 * The writes Jose's rules make (Automations, 2026-10-21),
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
            $first = WorkizJob::query()->sole();
            // The account's own statuses, as the mirror knows them: a sub-status's parent, and a status a job carries.
            \App\Models\WorkizRecord::query()->create(['franchise_id' => $this->t['franchise']->id, 'connection_id' => $c->id, 'type' => 'sub_status',
                'remote_id' => 'JSS-9', 'payload' => ['name' => 'Estimate Declined', 'parentName' => 'Canceled'], 'synced_at' => now()]);
            WorkizJob::query()->create(['franchise_id' => $this->t['franchise']->id, 'connection_id' => $c->id, 'remote_id' => 'OTHER1',
                'status' => 'done pending approval', 'payload' => [], 'synced_at' => now()]);

            return $first;
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

    /** Jose 11-04: the statuses are the account's own, not a list in code: EzBizi's word goes up as the account spells it. */
    public function test_a_status_goes_up_in_the_accounts_own_spelling(): void
    {
        Http::fake(['api.workiz.com/crm/api/v2/jobs/JOB-1' => Http::response(array_replace(require base_path('tests/Fixtures/workiz/v2_job.php'), ['id' => 'JOB-1']))]);

        $this->inTenant($this->t['org'], fn () => $this->writer()->setStatus($this->job, 'Done Pending Approval'));

        Http::assertSent(fn (Request $r) => $r->method() === 'PUT' && $r['status'] === 'done pending approval');
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
        // v2 only adds; with no web login nothing could come off, and the audit says so (desk 11-10).
        $audit = $this->inTenant($this->t['org'], fn () => \App\Models\AuditEvent::query()->where('action', 'workiz.job_tags_set')->sole()->metadata);
        $this->assertSame([[], ['Membership']], [$audit['removed'], $audit['not_removed']]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Workiz has no tag "VIP" in this account.');
        $this->inTenant($this->t['org'], fn () => $this->writer()->setTags($this->job, ['VIP']));
    }

    /** Desk 11-10: v2's PUT only adds a tag; the one to drop comes off through the web login's job page call. */
    public function test_a_dropped_tag_is_taken_off_through_the_web_login(): void
    {
        $this->inTenant($this->t['org'], function () {
            $web = new SocialConnection;
            $web->forceFill(['organization_id' => $this->t['org']->id, 'scope_type' => SocialConnection::SCOPE_COMPANY, 'scope_id' => $this->t['company']->id,
                'provider' => SocialProvider::WorkizWeb->value, 'remote_id' => $this->t['company']->id, 'display_name' => 'Workiz web login']);
            $web->connect(['cookies' => ['sendajob_sessid' => 's'], 'user_id' => '1', 'account_id' => '2', 'accounts' => ['main' => $this->t['franchise']->id]]);
            $web->forceFill(['status' => 'connected'])->save();
        });
        $job = array_replace(require base_path('tests/Fixtures/workiz/v2_job.php'), ['id' => 'JOB-1', 'tags' => [['id' => 'TAG-1', 'name' => 'Membership'], ['id' => 'TAG-D', 'name' => 'DEPOSIT RECEIVED']]]);
        Http::fake([
            'api.workiz.com/crm/api/v2/tags*' => Http::response(['data' => [['id' => 'TAG-1', 'name' => 'Membership'], ['id' => 'TAG-D', 'name' => 'DEPOSIT RECEIVED']]]),
            'api.workiz.com/crm/api/v2/jobs/JOB-1' => fn (Request $r) => Http::response($r->method() === 'GET' ? ['data' => [$job]] : $job),
            'app.workiz.com/ajaxc/jobTags/getAll/' => Http::response(['flag' => true, 'data' => [['id' => '901', 'name' => 'MEMBERSHIP'], ['id' => '902', 'name' => 'DEPOSIT RECEIVED']]]),
            'app.workiz.com/ajaxc/job/get/*' => Http::response(['flag' => true, 'data' => ['id' => '555']]),
            'app.workiz.com/ajaxc/jobTags/remove/' => Http::response(['flag' => true, 'msg' => 'Tag removed']),
            'app.workiz.com/*' => Http::response(['flag' => true, 'data' => []]),
        ]);

        $this->inTenant($this->t['org'], fn () => $this->writer()->setTags($this->job, ['DEPOSIT RECEIVED']));

        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/ajaxc/jobTags/remove/') && $r['id'] === '901' && $r['objectId'] === '555' && $r['context'] === 'job');
        $audit = $this->inTenant($this->t['org'], fn () => \App\Models\AuditEvent::query()->where('action', 'workiz.job_tags_set')->sole()->metadata);
        $this->assertSame([['Membership'], []], [$audit['removed'], $audit['not_removed']]);
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
