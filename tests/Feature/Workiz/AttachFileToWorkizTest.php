<?php

namespace Tests\Feature\Workiz;

use App\Jobs\AttachFileToWorkiz;
use App\Models\AuditEvent;
use App\Models\Customer;
use App\Models\Job;
use App\Models\SocialConnection;
use App\Models\WorkizJob;
use App\Support\Social\SocialProvider;
use App\Support\Workiz\WorkizV2;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/** Monday list 2026-11-03: an inspection PDF or job photo saved in EzBizi lands on the Workiz job; a refusal never reaches the caller. */
class AttachFileToWorkizTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    private array $t;

    private Job $job;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        Storage::disk('local')->put('inspections/report.pdf', '%PDF-1.1 test');
        $this->t = $this->provisionTenant('Alpha');
        $this->job = $this->inTenant($this->t['org'], function () {
            $c = new SocialConnection;
            $c->forceFill(['organization_id' => $this->t['org']->id, 'scope_type' => SocialConnection::SCOPE_FRANCHISE, 'scope_id' => $this->t['franchise']->id,
                'provider' => SocialProvider::Workiz->value, 'remote_id' => $this->t['franchise']->id, 'display_name' => 'NY']);
            $c->connect(['api_token' => 'k', 'refresh_token' => 'k', 'api_version' => 'v2']);
            $c->save();
            $web = new SocialConnection;
            $web->forceFill(['organization_id' => $this->t['org']->id, 'scope_type' => SocialConnection::SCOPE_COMPANY, 'scope_id' => $this->t['company']->id,
                'provider' => SocialProvider::WorkizWeb->value, 'remote_id' => $this->t['company']->id, 'display_name' => 'Workiz web login']);
            $web->connect(['cookies' => ['sendajob_sessid' => 's'], 'user_id' => '1', 'account_id' => '2', 'accounts' => ['main' => $this->t['franchise']->id]]);
            $web->forceFill(['status' => 'connected'])->save();
            WorkizJob::land($c, WorkizV2::job(array_replace(require base_path('tests/Fixtures/workiz/v2_job.php'), ['id' => 'JOB-1'])));
            $customer = Customer::factory()->create(['owning_franchise_id' => $this->t['franchise']->id]);
            $job = Job::factory()->create(['franchise_id' => $this->t['franchise']->id, 'customer_id' => $customer->id]);
            WorkizJob::query()->sole()->forceFill(['job_id' => $job->id])->save();

            return $job;
        });
    }

    private function attach(?array $upload): void
    {
        Http::fake([
            'app.workiz.com/ajaxc/job/get/*' => Http::response(['flag' => true, 'data' => ['id' => '555']]),
            'app.workiz.com/ajaxc/files/uploadDocFiles/*' => Http::response($upload),
            'app.workiz.com/*' => Http::response(['flag' => true, 'data' => []]),
        ]);
        (new AttachFileToWorkiz($this->t['org']->id, $this->job->id, 'local', 'inspections/report.pdf', 'Inspection 123.pdf'))->handle(app(\App\Support\Tenancy\TenantContext::class), app(\App\Support\Audit\AuditLogger::class));
    }

    private function failures()
    {
        return $this->inTenant($this->t['org'], fn () => AuditEvent::query()->where('action', 'workiz.attach_failed')->get());
    }

    public function test_the_file_lands_on_the_workiz_job_by_its_numeric_id(): void
    {
        $this->attach(['flag' => true, 'files' => [['id' => '9']]]);

        Http::assertSent(fn (Request $r) => $r->url() === 'https://app.workiz.com/ajaxc/files/uploadDocFiles/555/'
            && str_contains($r->body(), 'name="the_file"; filename="Inspection 123.pdf"') && str_contains($r->body(), '%PDF-1.1 test')
            && str_contains($r->body(), 'name="docType"'));
        $this->assertCount(0, $this->failures());
    }

    public function test_a_refusal_is_audited_not_thrown(): void
    {
        $this->attach(['flag' => false, 'msg' => 'please upgrade your plan']);

        $this->assertSame('please upgrade your plan', $this->failures()->sole()->changes['refused']);
    }

    public function test_a_job_not_in_workiz_yet_is_tried_again_later(): void
    {
        $this->inTenant($this->t['org'], fn () => WorkizJob::query()->update(['job_id' => null]));
        Queue::fake();

        $this->attach(['flag' => true]);

        Queue::assertPushed(AttachFileToWorkiz::class, fn (AttachFileToWorkiz $j) => $j->attempt === 2);
        Http::assertNotSent(fn (Request $r) => str_contains($r->url(), 'uploadDocFiles'));
        $this->assertCount(0, $this->failures());
    }
}
