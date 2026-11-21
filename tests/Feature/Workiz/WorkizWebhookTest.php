<?php

namespace Tests\Feature\Workiz;

use App\Models\SocialConnection;
use App\Models\WorkizJob;
use App\Models\WorkizRecord;
use App\Support\Social\SocialProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/**
 * Workiz's Automation Center posting "this changed" (v2 webhooks, 2026-10-06).
 * The body is shaped as the v2 spec documents it: {trigger, data, metadata},
 * `data` being the record as GET /:id would answer.
 */
class WorkizWebhookTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    /** @return array{0: array, 1: SocialConnection} */
    private function branch(string $version = 'v2'): array
    {
        $tenant = $this->provisionTenant('Alpha');

        $connection = $this->inTenant($tenant['org'], function () use ($tenant, $version) {
            $c = new SocialConnection;
            $c->forceFill([
                'organization_id' => $tenant['org']->id,
                'scope_type' => SocialConnection::SCOPE_FRANCHISE,
                'scope_id' => $tenant['franchise']->id,
                'provider' => SocialProvider::Workiz->value,
                'remote_id' => $tenant['franchise']->id,
                'display_name' => 'Massachusetts',
            ]);
            $c->connect($version === 'v2'
                ? ['api_token' => 'v2key_MA', 'refresh_token' => 'v2key_MA', 'api_version' => 'v2']
                : ['api_token' => 'api_MA', 'refresh_token' => 'api_MA', 'auth_secret' => 'sec_MA']);
            $c->save();

            return $c;
        });

        return [$tenant, $connection];
    }

    private function job(array $overrides = []): array
    {
        return array_replace(require base_path('tests/Fixtures/workiz/v2_job.php'), $overrides);
    }

    private function notify(SocialConnection $connection, string $trigger, array $data)
    {
        return $this->postJson('/api/v1/social/workiz/webhook/'.$connection->getKey(), [
            'trigger' => ['type' => $trigger], 'data' => $data, 'metadata' => ['ruleId' => 1],
        ]);
    }

    /** The body only names the job; what lands is what Workiz answers for it, read with the branch's key. */
    public function test_a_job_webhook_lands_what_workiz_answers_not_what_it_was_sent(): void
    {
        [$tenant, $connection] = $this->branch();
        Http::fake(['api.workiz.com/crm/api/v2/jobs/JOB-8ApvxXebm23oQV4E' => Http::response(['data' => [$this->job()]])]);

        $this->notify($connection, 'job_status_changed', $this->job(['status' => 'Canceled']))->assertStatus(202);

        $this->inTenant($tenant['org'], fn () => $this->assertSame('Submitted', WorkizJob::query()->where('remote_id', 'NYCZ7W')->sole()->status));
        Http::assertSent(fn (Request $r) => $r->hasHeader('Authorization', 'Bearer v2key_MA'));
    }

    public function test_an_invoice_webhook_lands_the_invoice(): void
    {
        [$tenant, $connection] = $this->branch();
        $invoice = ['id' => 'IV-9v8mL2pl05EMlBzD', 'jobId' => 'JOB-1', 'totalPrice' => 329.12, 'amountDue' => 0, 'updated' => '2026-10-05 22:41:13', 'created' => '2026-10-05 18:38:44'];
        Http::fake(['api.workiz.com/crm/api/v2/invoices/IV-9v8mL2pl05EMlBzD' => Http::response(['data' => $invoice])]);

        $this->notify($connection, 'invoice_sent', ['id' => 'IV-9v8mL2pl05EMlBzD'])->assertStatus(202);

        $this->inTenant($tenant['org'], fn () => $this->assertSame('329.12', WorkizRecord::query()->where('type', 'invoice')->sole()->amount));
    }

    /** A save that fires three rules is one change: one read. */
    public function test_three_rules_on_one_save_read_the_job_once(): void
    {
        [, $connection] = $this->branch();
        Http::fake(['api.workiz.com/*' => Http::response(['data' => [$this->job()]])]);

        foreach (['job_updated', 'job_status_changed', 'job_scheduled'] as $trigger) {
            $this->notify($connection, $trigger, ['id' => 'JOB-8ApvxXebm23oQV4E'])->assertStatus(202);
        }

        Http::assertSentCount(1);
    }

    /** 11-21: a 02:11 rate-limit burst spent tries=3 on releases; 8 reads died as MaxAttemptsExceeded. */
    public function test_a_read_that_keeps_meeting_the_rate_limit_is_retried_not_failed(): void
    {
        [$tenant, $connection] = $this->branch();
        config(['queue.default' => 'database']);
        Http::fake(['api.workiz.com/crm/api/v2/jobs/*' => Http::sequence()
            ->push(['message' => 'Rate limit exceeded'], 429)->push(['message' => 'Rate limit exceeded'], 429)
            ->push(['message' => 'Rate limit exceeded'], 429)->push(['data' => [$this->job()]])]);
        \App\Jobs\RereadWorkizRecord::dispatch($connection->getKey(), 'job', 'JOB-8ApvxXebm23oQV4E');

        foreach (range(1, 4) as $pickup) {
            $this->artisan('queue:work', ['--once' => true, '--tries' => 1])->assertSuccessful();
            $this->travel(6)->minutes();
        }

        $this->assertSame(0, \DB::table('failed_jobs')->count());
        $this->inTenant($tenant['org'], fn () => $this->assertSame(1, WorkizJob::query()->count()));
    }

    /** Desk 11-21: the 2h budget must not spend the key on a read that throws for real. */
    public function test_a_read_that_throws_fails_after_three_exceptions(): void
    {
        [, $connection] = $this->branch();
        config(['queue.default' => 'database']);
        $calls = 0;
        Http::fake(function () use (&$calls) {
            $calls++;
            throw new \RuntimeException('boom');
        });
        \App\Jobs\RereadWorkizRecord::dispatch($connection->getKey(), 'job', 'JOB-8ApvxXebm23oQV4E');

        foreach (range(1, 5) as $pickup) {
            $this->artisan('queue:work', ['--once' => true, '--tries' => 1]);
            $this->travel(6)->minutes();
        }

        $this->assertSame(3, $calls);
        $this->assertSame(1, \DB::table('failed_jobs')->count());
    }

    /** A job Workiz no longer has is the sweeps' to retire, on two signals; a webhook does not delete. */
    public function test_a_record_workiz_no_longer_has_is_left_to_the_sweeps(): void
    {
        [$tenant, $connection] = $this->branch();
        Http::fake(['api.workiz.com/crm/api/v2/jobs/*' => Http::sequence()
            ->push(['data' => [$this->job()]])
            ->push(['message' => 'Job not found', 'statusCode' => 404], 404)]);
        $this->notify($connection, 'job_created', ['id' => 'JOB-8ApvxXebm23oQV4E']);
        $this->travel(1)->minutes();

        $this->notify($connection, 'job_deleted', ['id' => 'JOB-8ApvxXebm23oQV4E'])->assertStatus(202);

        $this->inTenant($tenant['org'], fn () => $this->assertSame(1, WorkizJob::query()->count()));
    }

    /** What another account answers to the same id is not this branch's record. */
    public function test_an_answer_about_a_different_record_is_not_landed(): void
    {
        [$tenant, $connection] = $this->branch();
        Http::fake(['api.workiz.com/*' => Http::response(['data' => [$this->job(['id' => 'JOB-someoneElse0'])]])]);

        $this->notify($connection, 'job_created', ['id' => 'JOB-8ApvxXebm23oQV4E'])->assertStatus(202);

        $this->inTenant($tenant['org'], fn () => $this->assertSame(0, WorkizJob::query()->count()));
    }

    public function test_a_lead_webhook_runs_the_branch_lead_import(): void
    {
        [, $connection] = $this->branch();
        Http::fake(['api.workiz.com/*' => Http::response(['pageSize' => 100, 'page' => 1, 'totalResults' => 0, 'hasMore' => false, 'data' => []])]);

        $this->notify($connection, 'lead_created', ['id' => 'LD-anything'])->assertStatus(202);

        Http::assertSent(fn (Request $r) => str_contains($r->url(), '/crm/api/v2/leads'));
    }

    public function test_an_unknown_or_v1_branch_is_not_found(): void
    {
        [, $v1] = $this->branch('v1');
        Http::fake();

        $this->postJson('/api/v1/social/workiz/webhook/01a10e0b-4807-70d3-8dd1-9cccb3ec69f5', ['data' => ['id' => 'JOB-8ApvxXebm23oQV4E']])->assertStatus(404);
        $this->notify($v1, 'job_created', ['id' => 'JOB-8ApvxXebm23oQV4E'])->assertStatus(404);
        Http::assertNothingSent();
    }

    public function test_a_body_naming_nothing_readable_is_refused(): void
    {
        [, $connection] = $this->branch();
        Http::fake();

        $this->notify($connection, 'job_created', ['id' => "JOB-1'; DROP TABLE jobs"])->assertStatus(422);
        $this->notify($connection, 'client_created', ['id' => 'CL-1234abcd'])->assertStatus(422);
        Http::assertNothingSent();
    }
}
