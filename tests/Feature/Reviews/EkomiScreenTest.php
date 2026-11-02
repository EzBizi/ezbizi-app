<?php

namespace Tests\Feature\Reviews;

use App\Models\AutomationEvent;
use App\Models\Customer;
use App\Models\Job;
use App\Models\SocialConnection;
use App\Support\Social\SocialProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/** The eKomi screen (Jose, 2026-11-02): what A1 Tools did with eKomi, from EzBizi. */
class EkomiScreenTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    private array $t;

    private SocialConnection $c;

    /** eKomi's answer key for the next add-recipient call. */
    private int $askKey = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->t = $this->provisionTenant();
        $this->c = $this->inTenant($this->t['org'], function () {
            $c = new SocialConnection;
            $c->forceFill(['organization_id' => $this->t['org']->id, 'scope_type' => SocialConnection::SCOPE_COMPANY, 'scope_id' => $this->t['company']->id,
                'provider' => SocialProvider::Ekomi->value, 'remote_id' => '154959', 'display_name' => 'eKomi · Co']);
            $c->connect(['refresh_token' => 'pw', 'shop_id' => '154959', 'password' => 'pw']);
            $c->save();

            return $c;
        });
        Http::fake(fn (Request $r) => match (true) {
            str_contains($r->url(), 'getSnapshot') => Http::response(['done' => 1, 'info' => ['account_name' => 'A1', 'fb_count' => 397, 'fb_avg' => 4.96, 'active' => 1,
                'ekomi_certificate' => 'https://www.ekomi-us.com/review-a1.html']]),
            str_contains($r->url(), 'activity_stream') => Http::response(['data' => [
                ['delivered_at' => 'x', 'opened_at' => 'x', 'clicked_at' => 'x', 'reviewed_at' => 'x'],
                ['delivered_at' => 'x'], ['workflow_status' => 'interrupted'], ['delivered_at' => 'x', 'opened_at' => 'x'],
            ], 'meta' => []]),
            str_contains($r->url(), 'api/v1/recipients') => Http::response(['data' => [
                ['order_id' => 'J1', 'recipient_type' => 'email', 'created_at' => '2026-10-30', 'detail' => ['first_name' => 'Ada', 'last_name' => 'Lee',
                    'email' => 'ada@x.com', 'workflow_status' => 'completed', 'review_given_at' => '2026-10-31']],
                ['order_id' => 'J2', 'recipient_type' => 'email', 'detail' => ['first_name' => 'Bo', 'email' => 'bo@x.com',
                    'workflow_status' => 'interrupted', 'workflow_interrupted_reason' => 'Bounced']],
            ]]),
            str_contains($r->url(), 'add-recipient') => Http::response($this->askKey === 0
                ? ['status' => 'success', 'key' => 0, 'message' => 'ok'] : ['status' => 'error', 'key' => $this->askKey, 'message' => 'no']),
            default => Http::response([], 404),
        });
    }

    private function job(?string $email): Job
    {
        return $this->inTenant($this->t['org'], function () use ($email) {
            $customer = Customer::create(['owning_franchise_id' => $this->t['franchise']->id, 'number' => 'C-'.random_int(1, 1e6),
                'name' => 'Ada Lee', 'email' => $email]);

            return Job::factory()->create(['franchise_id' => $this->t['franchise']->id, 'customer_id' => $customer->id]);
        });
    }

    private function completed(Job $job, int $hoursAgo): void
    {
        $this->inTenant($this->t['org'], fn () => AutomationEvent::create(['organization_id' => $this->t['org']->id, 'event_key' => 'job.completed',
            'subject_type' => Job::class, 'subject_id' => $job->id, 'payload' => [], 'occurred_at' => now()->subHours($hoursAgo), 'available_at' => now()]));
    }

    private function asks(): int
    {
        return count(Http::recorded(fn (Request $r) => str_contains($r->url(), 'add-recipient')));
    }

    public function test_overview_requests_and_the_account_list(): void
    {
        $this->signedInAs($this->t['admin']);
        $this->getJson('/api/v1/ekomi')->assertOk()->assertJsonPath('data.0.shop_id', '154959')->assertJsonPath('data.0.settings.auto_request', false);

        $o = $this->getJson("/api/v1/ekomi/{$this->c->id}/overview")->assertOk();
        $o->assertJsonPath('data.snapshot.review_count', 397)->assertJsonPath('data.snapshot.certificate_url', 'https://www.ekomi-us.com/review-a1.html');
        $this->assertSame([4, 3, 2, 1, 1, 1], array_values(array_intersect_key($o->json('data.funnel'),
            array_flip(['requested', 'delivered', 'opened', 'clicked', 'reviewed', 'bounced']))));

        $rows = $this->getJson("/api/v1/ekomi/{$this->c->id}/requests?q=bo")->assertOk()->json('data');
        $this->assertSame([['Bo', 'interrupted', 'Bounced']], array_map(fn ($r) => [$r['name'], $r['status'], $r['bounce_reason']], $rows));
    }

    public function test_asking_a_jobs_customer_takes_the_publish_grant_and_an_email(): void
    {
        $job = $this->job('ada@x.com');
        $viewer = $this->userWithRole($this->t, 'Viewer', ['reviews.view']);
        $this->signedInAs($viewer)->postJson("/api/v1/ekomi/{$this->c->id}/requests", ['job_id' => $job->id])->assertForbidden();

        $this->signedInAs($this->t['admin'])->postJson("/api/v1/ekomi/{$this->c->id}/requests", ['job_id' => $job->id])
            ->assertOk()->assertJsonPath('data.ok', true);
        Http::assertSent(fn (Request $r) => str_contains($r->url(), 'add-recipient') && $r['transaction_id'] === $job->id && $r['email'] === 'ada@x.com');

        $this->postJson("/api/v1/ekomi/{$this->c->id}/requests", ['job_id' => $this->job(null)->id])->assertUnprocessable();
    }

    public function test_settings_keep_a_blank_password_and_switching_on_never_asks_the_backlog(): void
    {
        $this->signedInAs($this->t['admin'])->putJson("/api/v1/ekomi/{$this->c->id}/settings", ['password' => '', 'auto_request' => true, 'delay_hours' => 2])
            ->assertOk()->assertJsonPath('data.start_date', now()->toDateString());
        $this->assertSame('pw', $this->c->fresh()->credentials['password']);

        $old = $this->job('old@x.com');
        $this->completed($old, 72); // before switching on
        $fresh = $this->job('ada@x.com');
        $this->completed($fresh, 1); // inside the 2-hour wait
        $this->artisan('ezbizi:ekomi-requests')->assertSuccessful();
        $this->assertSame(0, $this->asks());
    }

    public function test_each_completed_job_is_asked_once_and_a_passing_refusal_is_retried(): void
    {
        $this->inTenant($this->t['org'], fn () => $this->c->forceFill(['credentials' => $this->c->credentials
            + ['auto_request' => true, 'delay_hours' => 2, 'start_date' => now()->subDays(5)->toDateString()]])->save());
        $job = $this->job('ada@x.com');
        $this->completed($job, 3);

        $this->askKey = 500;
        $this->artisan('ezbizi:ekomi-requests')->assertSuccessful();
        $this->assertNull($job->fresh()->ekomi_requested_at, 'a passing refusal is tried again');

        $this->askKey = 0;
        $this->artisan('ezbizi:ekomi-requests')->assertSuccessful();
        $this->artisan('ezbizi:ekomi-requests')->assertSuccessful();
        $this->assertSame(2, $this->asks(), 'the refused try, then once');
        $this->assertNotNull($job->fresh()->ekomi_requested_at);
    }
}
