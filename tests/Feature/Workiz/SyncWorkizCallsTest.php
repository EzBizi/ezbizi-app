<?php

namespace Tests\Feature\Workiz;

use App\Models\Customer;
use App\Models\Job;
use App\Models\SocialConnection;
use App\Models\WorkizCall;
use App\Models\WorkizJob;
use App\Support\Privacy\Anonymiser;
use App\Support\Social\SocialProvider;
use App\Support\Workiz\WorkizV2;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/**
 * Workiz phone calls and Workiz's scores for them (Jose via Grok AI,
 * 2026-10-12). Keys as GET /v2/calls answered for DMV on 2026-10-12; the
 * numbers and people are invented.
 */
class SyncWorkizCallsTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    private array $tenant;

    private Job $job;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-10-12 12:00:00', 'UTC'));

        $this->tenant = $this->provisionTenant('Alpha');
        $this->job = $this->inTenant($this->tenant['org'], function () {
            $c = new SocialConnection;
            $c->forceFill([
                'organization_id' => $this->tenant['org']->id, 'scope_type' => SocialConnection::SCOPE_FRANCHISE,
                'scope_id' => $this->tenant['franchise']->id, 'provider' => SocialProvider::Workiz->value,
                'remote_id' => $this->tenant['franchise']->id, 'display_name' => 'DMV',
            ]);
            $c->connect(['api_token' => 'v2key_DMV', 'refresh_token' => 'v2key_DMV', 'api_version' => 'v2']);
            $c->save();

            $customer = Customer::create(['owning_franchise_id' => $this->tenant['franchise']->id, 'number' => 'C-1', 'name' => 'Pat Rivera', 'is_active' => true]);
            $job = Job::create(['franchise_id' => $this->tenant['franchise']->id, 'customer_id' => $customer->id, 'number' => 'J-1', 'title' => 'Sweep', 'status' => 'scheduled']);
            WorkizJob::land($c, WorkizV2::job(array_replace(require base_path('tests/Fixtures/workiz/v2_job.php'), ['id' => 'JOB-1'])));
            WorkizJob::query()->update(['job_id' => $job->id]);

            return $job;
        });
    }

    private function workizCall(array $overrides = []): array
    {
        return array_replace([
            'id' => 'CALL-1', 'direction' => 'inbound', 'from' => '+12405550100', 'to' => '+13015550111',
            'callMaskingNumber' => null, 'duration' => 184, 'status' => 'answered', 'hasRecording' => true,
            'flowName' => 'Main line', 'adGroupId' => 'AG-1', 'clientId' => 'CL-1', 'clientName' => 'Pat Rivera',
            'jobId' => 'JOB-1', 'leadId' => null, 'answeredByUserId' => 'USR-ana', 'answeredByUserName' => 'Ana Ruiz',
            'calledByUserId' => null, 'calledByUserName' => null, 'tags' => [],
            'insights' => ['sentiment' => 'positive', 'intent' => 'existingBooking', 'dispatcherScore' => 78, 'objection' => 'other', 'pricingDiscussed' => false, 'booked' => false],
            'updatedAt' => '2026-10-11 09:16:00', 'createdAt' => '2026-10-11 09:12:30',
        ], $overrides);
    }

    public function test_calls_land_with_workizs_scores_and_their_job(): void
    {
        Http::fake(['api.workiz.com/crm/api/v2/calls*' => Http::response(['hasMore' => false, 'data' => [
            $this->workizCall(),
            $this->workizCall(['id' => 'CALL-2', 'direction' => 'outgoing', 'jobId' => null, 'answeredByUserId' => null, 'answeredByUserName' => null,
                'calledByUserId' => 'USR-bo', 'calledByUserName' => 'Bo Lin', 'insights' => null, 'createdAt' => '2026-10-11 10:00:00']),
        ]])]);

        $this->artisan('ezbizi:sync-workiz-calls')->assertSuccessful();

        $calls = $this->inTenant($this->tenant['org'], fn () => WorkizCall::query()->orderBy('remote_id')->get()->keyBy('remote_id'));
        $one = $calls['CALL-1'];
        $this->assertSame(['inbound', '+12405550100', 184, 'answered', 'USR-ana', 'Ana Ruiz', $this->job->id, '2026-10-11 09:12:30'],
            [$one->direction, $one->from_number, $one->duration, $one->status, $one->user_remote_id, $one->user_name, $one->job_id, $one->called_at->format('Y-m-d H:i:s')]);
        $this->assertSame(['positive', 'existingBooking', 78, 'other', false, false],
            [$one->sentiment, $one->intent, $one->dispatcher_score, $one->objection, $one->pricing_discussed, $one->booked]);
        // Placed, not answered: the caller is the staff member; unscored.
        $this->assertSame(['USR-bo', 'Bo Lin', null, null], [$calls['CALL-2']->user_remote_id, $calls['CALL-2']->user_name, $calls['CALL-2']->dispatcher_score, $calls['CALL-2']->job_id]);

        Http::assertSent(fn (Request $r) => $r->hasHeader('Authorization', 'Bearer v2key_DMV') && $r['date'] === '2025-10-12'
            && $r['dateProperty'] === 'created' && $r['field'] === 'createdAt' && $r['order'] === 'ASC');
    }

    /** A later run reads from the newest call held less two days, and lands a call once. */
    public function test_a_later_run_reads_on_from_the_newest_call(): void
    {
        Http::fake(['api.workiz.com/crm/api/v2/calls*' => Http::response(['hasMore' => false, 'data' => [$this->workizCall()]])]);

        $this->artisan('ezbizi:sync-workiz-calls')->assertSuccessful();
        $this->artisan('ezbizi:sync-workiz-calls')->assertSuccessful();

        $this->assertSame(1, $this->inTenant($this->tenant['org'], fn () => WorkizCall::query()->count()));
        Http::assertSent(fn (Request $r) => $r['date'] === '2026-10-09');
    }

    public function test_erasing_the_customer_blanks_the_numbers_on_their_calls(): void
    {
        Http::fake(['api.workiz.com/crm/api/v2/calls*' => Http::response(['hasMore' => false, 'data' => [$this->workizCall()]])]);
        $this->artisan('ezbizi:sync-workiz-calls')->assertSuccessful();

        app(Anonymiser::class)->purge($this->inTenant($this->tenant['org'], fn () => Customer::query()->sole()));

        $call = $this->inTenant($this->tenant['org'], fn () => WorkizCall::query()->sole());
        $this->assertSame([null, null, 184], [$call->from_number, $call->to_number, $call->duration]);
    }
}
