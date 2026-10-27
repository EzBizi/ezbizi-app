<?php

namespace Tests\Feature\Memberships;

use App\Support\Social\SocialProvider;
use App\Jobs\SendMembershipVisitToWorkiz;
use App\Models\AutomationEvent;
use App\Models\Customer;
use App\Models\Membership;
use App\Models\MembershipPlan;
use App\Models\SocialConnection;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/** A confirmed visit goes to the branch's Workiz too; a refusal tells staff (2026-10-05). */
class MembershipsWorkizTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    private array $t;
    private Membership $membership;

    protected function setUp(): void
    {
        parent::setUp();
        $this->t = $this->provisionTenant('Alpha');
        $this->membership = $this->inTenant($this->t['org'], function () {
            $customer = Customer::create(['owning_franchise_id' => $this->t['franchise']->id, 'number' => 'C-1', 'name' => 'Marta Quill']);
            $plan = MembershipPlan::create(['name' => 'Yearly sweep', 'price_minor' => 19900, 'billing' => 'yearly', 'job_type' => 'Chimney Inspection']);
            $starts = CarbonImmutable::today()->subYear()->addWeeks(3);

            return Membership::create([
                'franchise_id' => $this->t['franchise']->id, 'customer_id' => $customer->id,
                'membership_plan_id' => $plan->id, 'enrollment' => 'new',
                'starts_on' => $starts, 'ends_on' => $starts->addYear()->subDay(),
            ]);
        });
    }

    private function book(): void
    {
        $url = $this->inTenant($this->t['org'], fn () => $this->membership->confirm_visit_url);
        $this->post($url, ['date' => CarbonImmutable::today()->addWeeks(4)->toDateString()])->assertOk();
    }

    private function connectWorkiz(): void
    {
        $this->inTenant($this->t['org'], function () {
            $c = new SocialConnection;
            $c->forceFill([
                'organization_id' => $this->t['org']->id,
                'scope_type' => SocialConnection::SCOPE_FRANCHISE,
                'scope_id' => $this->t['franchise']->id,
                'provider' => SocialProvider::Workiz->value,
                'remote_id' => $this->t['franchise']->id,
                'display_name' => 'New York',
            ]);
            $c->connect(['api_token' => 'api_NewYork', 'refresh_token' => 'api_NewYork', 'auth_secret' => 'sec_NewYork']);
            $c->save();
        });
    }

    private function notInWorkiz(): int
    {
        return AutomationEvent::withoutGlobalScopes()->where('event_key', 'membership.visit_not_in_workiz')->count();
    }

    public function test_a_booked_visit_is_queued_for_workiz_once(): void
    {
        Queue::fake();
        $this->book();
        $this->book();

        Queue::assertPushed(SendMembershipVisitToWorkiz::class, 1);
    }

    public function test_the_plans_job_type_is_sent_and_a_refusal_tells_staff(): void
    {
        $this->connectWorkiz();
        Http::fake(['api.workiz.com/*' => Http::response(['error' => true, 'code' => 400, 'msg' => 'Error Validating Fields',
            'details' => ['JobType' => 'Could not find matching value for JobType - Chimney Inspection']], 400)]);

        $this->book();   // the sync queue runs the job in-line

        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/job/create/')
            && ($r->data()['JobType'] ?? null) === 'Chimney Inspection'
            && ! array_key_exists('JobSource', $r->data()));
        $this->assertSame(1, $this->notInWorkiz());
        $event = AutomationEvent::withoutGlobalScopes()->where('event_key', 'membership.visit_not_in_workiz')->first();
        $this->assertStringContainsString('JobType', $event->payload['after']['refused']);
    }

    public function test_a_branch_with_no_workiz_sends_nothing_and_says_nothing(): void
    {
        Http::fake();
        $this->book();

        Http::assertNothingSent();
        $this->assertSame(0, $this->notInWorkiz());
    }

    public function test_a_busy_sync_is_retried_and_only_the_last_try_tells_staff(): void
    {
        $this->connectWorkiz();
        $this->inTenant($this->t['org'], function () {
            $job = app(\App\Support\Jobs\BookJob::class)(Customer::query()->find($this->membership->customer_id),
                $this->t['franchise'], 'Visit', CarbonImmutable::today()->addWeeks(4)->toDateString());
            $this->membership->forceFill(['visit_job_id' => $job->id])->save();
        });

        $lock = \Illuminate\Support\Facades\Cache::lock(\App\Console\Commands\ProjectWorkizRecords::LOCK, 600);
        $lock->get();
        Queue::fake();

        try {
            app()->call([new SendMembershipVisitToWorkiz($this->membership->id), 'handle']);
            Queue::assertPushed(SendMembershipVisitToWorkiz::class, fn ($j) => $j->attempt === 2);
            $this->assertSame(0, $this->notInWorkiz());

            app()->call([new SendMembershipVisitToWorkiz($this->membership->id, SendMembershipVisitToWorkiz::BUSY_TRIES), 'handle']);
            $this->assertSame(1, $this->notInWorkiz());
        } finally {
            $lock->release();
        }
    }

    public function test_the_ezbizi_job_is_booked_first_with_the_plans_type(): void
    {
        Queue::fake();
        $this->book();

        $job = \App\Models\Job::withoutGlobalScopes()->sole();
        $this->assertSame('Chimney Inspection', $job->job_type, 'EzBizi is the master; Workiz mirrors this job.');
        $this->assertSame($job->id, Membership::withoutGlobalScopes()->sole()->visit_job_id);
    }
}
