<?php

namespace Tests\Feature\Api;

use App\Models\AuditEvent;
use App\Models\Automation;
use App\Models\AutomationEvent;
use App\Models\AutomationRun;
use App\Models\AutomationVersion;
use App\Models\Customer;
use App\Models\Job;
use App\Models\User;
use App\Models\Notification;
use App\Services\Organizations\ProvisionOrganization;
use App\Support\Automations\AutomationEngine;
use App\Support\Automations\ConditionEvaluator;
use App\Support\Automations\OutboundWebhook;
use App\Support\Settings\SettingsResolver;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

/**
 * The automation engine (plan §5.5).
 *
 * The plan is blunt about why this matters: "Automation that fires twice, or not
 * at all, or at the wrong moment, destroys trust faster than a missing feature."
 * So these test the guarantees rather than the happy path.
 */
class AutomationEngineTest extends TestCase
{
    use RefreshDatabase;

    private array $org;

    protected function setUp(): void
    {
        parent::setUp();

        $result = app(ProvisionOrganization::class)(
            organizationName: 'Alpha',
            administrator: ['name' => 'Admin', 'email' => 'a@alpha.test', 'password' => 'a-real-password'],
            companyName: 'Co', franchiseName: 'F1',
        );

        $this->org = [
            'organization' => $result['organization'],
            'admin'        => $result['administrator'],
            'franchise'    => $result['franchise'],
        ];
    }

    private function inTenant(callable $callback): mixed
    {
        return app(TenantContext::class)->run($this->org['organization'], $callback);
    }

    private function as(): static
    {
        $token = $this->postJson('/api/v1/auth/login', [
            'email' => $this->org['admin']->email, 'password' => 'a-real-password',
        ])->assertOk()->json('token');

        $this->app['auth']->forgetGuards();

        return $this->withHeader('Authorization', "Bearer {$token}");
    }

    private function automation(string $trigger, ?array $conditions, array $actions): Automation
    {
        return $this->inTenant(function () use ($trigger, $conditions, $actions) {
            $automation = Automation::create([
                'organization_id' => $this->org['organization']->id,
                'name'            => "When {$trigger}",
                'trigger_key'     => $trigger,
                'is_active'       => true,
            ]);

            $version = AutomationVersion::create([
                'organization_id' => $this->org['organization']->id,
                'automation_id'     => $automation->id,
                'version'         => 1,
                'conditions'      => $conditions,
                'actions'         => $actions,
                'created_at'      => now(),
            ]);

            $automation->forceFill(['active_version_id' => $version->id])->save();

            return $automation->fresh();
        });
    }

    /** Deliver everything sitting in the outbox, the way the scheduled sweep does. */
    private function drain(): int
    {
        $engine = app(AutomationEngine::class);

        return $this->inTenant(fn () => AutomationEvent::whereNull('delivered_at')
            ->orderBy('occurred_at')
            ->get()
            ->sum(fn (AutomationEvent $e) => $engine->deliver($e)));
    }

    private function makeJob(array $overrides = []): array
    {
        $customer = $this->as()->postJson('/api/v1/customers', [
            'owning_franchise_id' => $this->org['franchise']->id, 'name' => 'Person',
        ])->assertCreated()->json('data.id');

        $job = $this->as()->postJson('/api/v1/jobs', array_merge([
            'franchise_id' => $this->org['franchise']->id,
            'customer_id'  => $customer,
            'title'        => 'A job', 'status' => 'scheduled',
        ], $overrides))->assertCreated()->json('data.id');

        return [$customer, $job];
    }

    // ── the outbox (§5.5) ────────────────────────────────────────

    public function test_deleted_subject_keeps_its_emitted_franchise_scope(): void
    {
        $rule = $this->automation('customer.deleted', null, []);
        $this->inTenant(function () use ($rule) {
            $rule->update(['scope_type' => 'franchise', 'scope_id' => $this->org['franchise']->id]);
            $customer = Customer::factory()->create(['owning_franchise_id' => $this->org['franchise']->id]);
            $event = app(\App\Support\Automations\EventEmitter::class)->emit('customer.deleted', $customer);
            $customer->delete();
            app(AutomationEngine::class)->deliver($event);
            $this->assertSame(1, AutomationRun::where('event_id', $event->id)->count());
        });
    }

    public function test_transient_webhook_retries_only_itself_with_original_version_and_key(): void
    {
        $rule = $this->automation('job.created', null, [
            ['type' => 'update_field', 'field' => 'title', 'value' => 'First success'],
            ['type' => 'webhook', 'url' => 'https://example.com/hook'],
        ]);
        $keys = [];
        $mock = \Mockery::mock(OutboundWebhook::class);
        $mock->shouldReceive('send')->twice()->andReturnUsing(function ($url, $payload) use (&$keys) {
            $keys[] = $payload['idempotency_key'];
            return ['ok' => count($keys) > 1, 'status' => count($keys) > 1 ? 200 : 503, 'error' => 'HTTP 503'];
        });
        $this->app->instance(OutboundWebhook::class, $mock);
        [, $jobId] = $this->makeJob();
        $this->inTenant(function () use ($rule, $jobId, &$keys) {
            $event = AutomationEvent::where('event_key', 'job.created')->firstOrFail();
            app(AutomationEngine::class)->deliver($event);
            $this->assertNull($event->fresh()->delivered_at);
            Job::whereKey($jobId)->update(['title' => 'Changed after success']);
            $replacement = AutomationVersion::create([
                'automation_id' => $rule->id, 'version' => 2, 'created_at' => now(),
                'actions' => [['type' => 'update_field', 'field' => 'title', 'value' => 'Wrong version']],
            ]);
            $rule->update(['active_version_id' => $replacement->id]);
            $this->travel(61)->seconds();
            app(AutomationEngine::class)->deliver($event->fresh());
            $this->assertNotNull($event->fresh()->delivered_at);
            $this->assertSame('Changed after success', Job::findOrFail($jobId)->title);
            $this->assertCount(2, $keys);
            $this->assertSame($keys[0], $keys[1]);
            $this->assertNotSame($event->id, $keys[0]);
            $this->assertSame(1, AutomationRun::where('event_id', $event->id)->count());
        });
    }

    public function test_retryable_webhook_is_bounded_and_dead_letters_after_five_attempts(): void
    {
        $this->automation('job.created', null, [['type' => 'webhook', 'url' => 'https://example.com/hook']]);
        $mock = \Mockery::mock(OutboundWebhook::class);
        $mock->shouldReceive('send')->times(5)->andReturn(['ok' => false, 'status' => 503, 'error' => 'HTTP 503']);
        $this->app->instance(OutboundWebhook::class, $mock);
        $this->makeJob();
        $this->inTenant(function () {
            $event = AutomationEvent::where('event_key', 'job.created')->firstOrFail();
            for ($i = 0; $i < 5; $i++) {
                app(AutomationEngine::class)->deliver($event->fresh());
                $this->travel(901)->seconds();
            }
            $this->assertNotNull($event->fresh()->failed_at);
            $this->assertNotNull($event->fresh()->delivered_at);
            $run = AutomationRun::where('event_id', $event->id)->firstOrFail();
            $this->assertSame('failed', $run->status);
            $this->assertSame(5, $run->action_results[0]['attempts']);
            app(AutomationEngine::class)->deliver($event->fresh());
        });
    }

    public function test_recovered_worker_never_repeats_an_ambiguous_sms_but_resumes_its_webhook(): void
    {
        $actions = [['type' => 'sms.send'], ['type' => 'webhook', 'url' => 'https://example.com/hook']];
        $rule = $this->automation('job.created', null, $actions);
        $mock = \Mockery::mock(OutboundWebhook::class);
        $mock->shouldReceive('send')->once()->andReturn(['ok' => true, 'status' => 200, 'error' => null]);
        $this->app->instance(OutboundWebhook::class, $mock);
        $this->makeJob();
        $this->inTenant(function () use ($rule, $actions) {
            $event = AutomationEvent::where('event_key', 'job.created')->firstOrFail();
            $event->forceFill(['version_ids' => [$rule->active_version_id],
                'processing_until' => now()->subMinute(), 'processing_token' => (string) \Illuminate\Support\Str::uuid(),
            ])->save();
            $run = AutomationRun::create([
                'automation_id' => $rule->id, 'automation_version_id' => $rule->active_version_id,
                'event_id' => $event->id, 'status' => 'matched', 'started_at' => now()->subMinutes(11),
                'actions_snapshot' => $actions, 'action_count' => 2,
                'action_results' => [
                    ['type' => 'sms.send', 'ok' => false, 'state' => 'started', 'attempts' => 1],
                    ['type' => 'webhook', 'ok' => false, 'state' => 'started', 'attempts' => 1],
                ],
            ]);
            app(AutomationEngine::class)->deliver($event);
            $run->refresh();
            $this->assertSame('unknown', $run->action_results[0]['state']);
            $this->assertSame(1, $run->action_results[0]['attempts']);
            $this->assertSame('succeeded', $run->action_results[1]['state']);
            $this->assertSame('failed', $run->status);
        });
    }

    public function test_an_existing_delivery_lease_prevents_overlapping_side_effects(): void
    {
        $this->automation('job.created', null, [['type' => 'webhook', 'url' => 'https://example.com/hook']]);
        $mock = \Mockery::mock(OutboundWebhook::class);
        $mock->shouldNotReceive('send');
        $this->app->instance(OutboundWebhook::class, $mock);
        $this->makeJob();
        $this->inTenant(function () {
            $event = AutomationEvent::where('event_key', 'job.created')->firstOrFail();
            $event->forceFill(['processing_until' => now()->addMinutes(10)])->save();
            $this->assertSame(0, app(AutomationEngine::class)->deliver($event));
            $this->assertSame(0, AutomationRun::where('event_id', $event->id)->count());
        });
    }

    public function test_disabling_a_rule_between_retries_finishes_its_pending_run(): void
    {
        $rule = $this->automation('job.created', null, [['type' => 'webhook', 'url' => 'https://example.com/hook']]);
        $mock = \Mockery::mock(OutboundWebhook::class);
        $mock->shouldReceive('send')->once()->andReturn(['ok' => false, 'status' => 503, 'error' => 'HTTP 503']);
        $this->app->instance(OutboundWebhook::class, $mock);
        $this->makeJob();
        $this->inTenant(function () use ($rule) {
            $event = AutomationEvent::where('event_key', 'job.created')->firstOrFail();
            app(AutomationEngine::class)->deliver($event);
            $rule->update(['is_active' => false]);
            $this->travel(61)->seconds();
            app(AutomationEngine::class)->deliver($event->fresh());
            $this->assertNotNull($event->fresh()->delivered_at);
            $this->assertNotNull($event->fresh()->failed_at);
            $this->assertSame('failed', AutomationRun::where('event_id', $event->id)->firstOrFail()->status);
        });
    }

    public function test_pending_run_keeps_its_original_rule_scope_after_rule_edit(): void
    {
        $rule = $this->automation('job.created', null, [['type' => 'webhook', 'url' => 'https://example.com/hook']]);
        $seen = [];
        $runner = \Mockery::mock(\App\Support\Automations\ActionRunner::class);
        $runner->shouldReceive('run')->twice()->andReturnUsing(function ($action, $event, $subject, $isTest = false, $automation = null) use (&$seen) {
            $seen[] = $automation->scope_type;
            return ['type' => 'webhook', 'ok' => count($seen) > 1, 'retryable' => true, 'detail' => 'Temporary outage'];
        });
        $this->app->instance(\App\Support\Automations\ActionRunner::class, $runner);
        $this->makeJob();
        $this->inTenant(function () use ($rule, &$seen) {
            $event = AutomationEvent::where('event_key', 'job.created')->firstOrFail();
            app(AutomationEngine::class)->deliver($event);
            $rule->update(['scope_type' => 'franchise', 'scope_id' => $this->org['franchise']->id]);
            $this->travel(61)->seconds();
            app(AutomationEngine::class)->deliver($event->fresh());
            $this->assertSame([null, null], $seen);
        });
    }

    public function test_queue_exhaustion_records_unknown_actions_and_does_not_overwrite_completed_delivery(): void
    {
        $rule = $this->automation('job.created', null, []);
        $this->makeJob();
        $this->inTenant(function () use ($rule) {
            $event = AutomationEvent::where('event_key', 'job.created')->firstOrFail();
            $run = AutomationRun::create([
                'automation_id' => $rule->id, 'automation_version_id' => $rule->active_version_id,
                'event_id' => $event->id, 'status' => 'matched', 'started_at' => now(),
                'actions_snapshot' => [['type' => 'sms.send']],
                'action_results' => [['type' => 'sms.send', 'state' => 'started', 'ok' => false, 'attempts' => 1]],
            ]);
            (new \App\Jobs\DeliverAutomationEvent($event->id))->failed(new \RuntimeException('Worker exhausted'));
            $this->assertSame('failed', $run->fresh()->status);
            $this->assertSame('unknown', $run->fresh()->action_results[0]['state']);
            $this->assertNotNull($event->fresh()->failed_at);
            $event->refresh()->forceFill(['failed_at' => null, 'last_error' => null, 'delivered_at' => now()])->save();
            (new \App\Jobs\DeliverAutomationEvent($event->id))->failed(new \RuntimeException('Late failure'));
            $this->assertNull($event->fresh()->failed_at);
        });
    }

    public function test_a_business_change_writes_its_event_in_the_same_transaction(): void
    {
        [, $job] = $this->makeJob();

        $event = $this->inTenant(fn () => AutomationEvent::where('event_key', 'job.created')->first());

        $this->assertNotNull($event);
        $this->assertSame($job, $event->subject_id);
        $this->assertNull($event->delivered_at, 'Nothing should deliver inline.');
        $this->assertSame('scheduled', $event->payload['after']['status']);
    }

    public function test_an_event_for_a_trigger_no_module_declares_is_refused(): void
    {
        // An automation listening for a typo would look configured and never
        // fire — the most confusing failure there is, because nothing at all
        // reports an error.
        $this->expectException(\InvalidArgumentException::class);

        $this->inTenant(fn () => app(\App\Support\Automations\EventEmitter::class)
            ->emit('job.levitated', null, []));
    }

    // ── conditions (§5.5) ────────────────────────────────────────

    public function test_a_matching_rule_acts_and_a_non_matching_one_records_why_not(): void
    {
        $this->automation('job.status_changed',
            ['all' => [['field' => 'status', 'op' => 'eq', 'value' => 'completed']]],
            [['type' => 'update_field', 'field' => 'title', 'value' => 'Done']],
        );

        [, $job] = $this->makeJob();
        $this->drain();

        $this->as()->patchJson("/api/v1/jobs/{$job}", ['status' => 'in_progress'])->assertOk();
        $this->drain();

        $skipped = $this->inTenant(fn () => AutomationRun::where('status', AutomationRun::SKIPPED)->first());

        // A recorded skip is the difference between "the rule did not match" and
        // "the rule never saw it" — completely different answers for whoever is
        // asking why their automation did not fire.
        $this->assertNotNull($skipped);
        $this->assertFalse($skipped->condition_trace['matched']);

        $this->as()->patchJson("/api/v1/jobs/{$job}", ['status' => 'completed'])->assertOk();
        $this->drain();

        $this->assertSame('Done', $this->inTenant(fn () => Job::find($job)->title));
    }

    public function test_changed_to_does_not_fire_on_creation(): void
    {
        $this->automation('job.created',
            ['all' => [['field' => 'status', 'op' => 'changed_to', 'value' => 'scheduled']]],
            [['type' => 'update_field', 'field' => 'title', 'value' => 'Should not happen']],
        );

        [, $job] = $this->makeJob();
        $this->drain();

        // On a create there is nothing to have changed FROM. Answering true would
        // fire "when status changes to completed" on every job created completed.
        $this->assertSame('A job', $this->inTenant(fn () => Job::find($job)->title));
    }

    public function test_nested_any_and_all_evaluate_as_written(): void
    {
        $this->automation('job.status_changed',
            ['any' => [
                ['field' => 'status', 'op' => 'eq', 'value' => 'cancelled'],
                ['all' => [
                    ['field' => 'status', 'op' => 'eq', 'value' => 'completed'],
                    ['field' => 'amount_minor', 'op' => 'gte', 'value' => 10000],
                ]],
            ]],
            [['type' => 'update_field', 'field' => 'title', 'value' => 'Matched']],
        );

        [, $job] = $this->makeJob(['amount_minor' => 5000]);
        $this->drain();

        // Completed but under the threshold — the inner `all` fails, and the
        // outer `any` has nothing else true.
        $this->as()->patchJson("/api/v1/jobs/{$job}", ['status' => 'completed'])->assertOk();
        $this->drain();
        $this->assertSame('A job', $this->inTenant(fn () => Job::find($job)->title));

        $this->as()->patchJson("/api/v1/jobs/{$job}", ['status' => 'cancelled'])->assertOk();
        $this->drain();
        $this->assertSame('Matched', $this->inTenant(fn () => Job::find($job)->title));
    }

    // ── idempotency (§5.5) ───────────────────────────────────────

    public function test_redelivering_an_event_cannot_run_the_same_version_twice(): void
    {
        $this->automation('job.created', null,
            [['type' => 'update_field', 'field' => 'title', 'value' => 'Touched']]);

        $this->makeJob();

        $engine = app(AutomationEngine::class);

        $event = $this->inTenant(fn () => AutomationEvent::where('event_key', 'job.created')->first());

        $first  = $this->inTenant(fn () => $engine->deliver($event));

        // Inside the tenant: BelongsToTenant refuses a write with no tenant in
        // context, which is exactly what it is for.
        $second = $this->inTenant(function () use ($engine, $event) {
            $event->forceFill(['delivered_at' => null])->save();

            return $engine->deliver($event->fresh());
        });

        // At-least-once delivery is the contract; the unique index on
        // (event, version) is what stops at-least-once becoming at-least-twice.
        $this->assertSame(1, $first);
        $this->assertSame(0, $second);
        $this->assertSame(1, $this->inTenant(fn () => AutomationRun::count()));
    }

    // ── loop protection (§5.5) ───────────────────────────────────

    public function test_a_rule_that_feeds_itself_is_stopped_and_recorded(): void
    {
        // TWO rules that answer each other. One rule setting a field to a
        // constant does not actually loop — the runner notices the value is
        // already what it is being set to and emits nothing, which is a guard
        // worth having but no test of the depth cap. A pair that disagree is a
        // real cascade.
        $this->automation('job.updated', null,
            [['type' => 'update_field', 'field' => 'description', 'value' => 'ping']]);
        $this->automation('job.updated', null,
            [['type' => 'update_field', 'field' => 'description', 'value' => 'pong']]);

        [, $job] = $this->makeJob();
        $this->as()->patchJson("/api/v1/jobs/{$job}", ['title' => 'Kick it off'])->assertOk();

        // Drain repeatedly: each pass delivers the events the previous one made.
        for ($i = 0; $i < 12; $i++) {
            $this->drain();
        }

        $depth = $this->inTenant(fn () => AutomationEvent::max('depth'));

        $this->assertLessThanOrEqual(AutomationEvent::MAX_DEPTH, $depth);
        $this->assertDatabaseHas('audit_events', ['action' => 'automation.cascade_stopped']);
    }

    // ── the SSRF guard (§5.5 — "the sharp edge") ─────────────────

    public function test_a_webhook_cannot_be_pointed_at_anything_internal(): void
    {
        $guard = app(OutboundWebhook::class);

        // The cloud metadata endpoint. On a box using instance roles this is
        // credentials, reachable by anyone who can type a URL into a settings
        // form — which is the whole reason this class exists.
        foreach ([
            'https://169.254.169.254/latest/meta-data/',
            'https://127.0.0.1/admin',
            'https://10.0.0.5/internal',
            'https://192.168.1.1/',
            'https://[::1]/',
        ] as $url) {
            try {
                $guard->assertAllowed($url);
                $this->fail("{$url} should have been refused.");
            } catch (RuntimeException $e) {
                $this->assertStringContainsString('internal address', $e->getMessage());
            }
        }
    }

    public function test_plain_http_is_refused(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('https');

        // The payload is customer data and it is signed; putting it on the wire
        // in clear defeats both.
        app(OutboundWebhook::class)->assertAllowed('http://example.com/hook');
    }

    public function test_a_webhook_action_reports_the_refusal_rather_than_throwing(): void
    {
        $this->automation('job.created', null,
            [['type' => 'webhook', 'url' => 'https://169.254.169.254/steal']]);

        $this->makeJob();
        $this->drain();

        $run = $this->inTenant(fn () => AutomationRun::first());

        // Recorded as a failed action, not an exception that loses the run —
        // whoever configured it has to be able to see why it never worked.
        $this->assertSame(AutomationRun::FAILED, $run->status);
        $this->assertFalse($run->action_results[0]['ok']);
        $this->assertStringContainsString('internal address', $run->action_results[0]['detail']);
    }

    public function test_a_redelivery_does_not_perform_the_actions_a_second_time(): void
    {
        // The earlier idempotency test passed for the wrong reason: its action
        // set a field to a constant, so the second run no-opped INSIDE the runner
        // rather than being stopped before it. A webhook cannot no-op — the POST
        // either happened twice or it did not.
        $this->automation('job.created', null,
            [['type' => 'webhook', 'url' => 'https://169.254.169.254/never']]);

        $this->makeJob();

        $engine = app(AutomationEngine::class);
        $event  = $this->inTenant(fn () => AutomationEvent::where('event_key', 'job.created')->first());

        $this->inTenant(fn () => $engine->deliver($event));

        $this->inTenant(function () use ($engine, $event) {
            $event->forceFill(['delivered_at' => null])->save();
            $engine->deliver($event->fresh());
        });

        // One run, one attempt. The run row is claimed BEFORE the action loop, so
        // the unique index refuses the redelivery while it can still prevent the
        // call rather than merely decline to record it.
        $runs = $this->inTenant(fn () => AutomationRun::all());

        $this->assertCount(1, $runs);
        $this->assertCount(1, $runs->first()->action_results);
    }

    public function test_the_budget_counts_actions_rather_than_rules(): void
    {
        [, $job] = $this->makeJob();

        $event = $this->inTenant(fn () => AutomationEvent::where('event_key', 'job.created')->first());

        $this->inTenant(fn () => AutomationRun::create([
            'organization_id'     => $this->org['organization']->id,
            'automation_id'         => $this->automation('job.created', null,
                [['type' => 'notify', 'message' => 'x']])->id,
            'automation_version_id' => AutomationVersion::first()->id,
            'event_id'            => $event->id,
            'status'              => AutomationRun::SUCCEEDED,
            'action_results'      => array_fill(0, 40, ['type' => 'notify', 'ok' => true, 'detail' => 'x']),
            'action_count'        => 40,
            'started_at'          => now(),
        ]));

        // Counting runs made a budget of 50 mean "50 rules firing", each of which
        // can carry any number of actions — so the ceiling on outbound calls was
        // not a ceiling at all.
        $this->assertSame(40, $this->inTenant(fn () => $event->fresh()->actionsSpent()));
    }

    public function test_an_automation_cannot_move_a_customer_between_franchises(): void
    {
        $other = $this->inTenant(fn () => \App\Models\Franchise::create([
            'organization_id' => $this->org['organization']->id,
            'company_id'      => $this->org['franchise']->company_id,
            'name' => 'Second Franchise', 'is_active' => true,
        ]));

        $this->automation('customer.created', null,
            [['type' => 'update_field', 'field' => 'owning_franchise_id', 'value' => $other->id]]);

        [$customer] = $this->makeJob();
        $this->drain();

        // owning_franchise_id is fillable, and the HTTP API deliberately refuses
        // it: reassigning accountability is a separate audited act (§5.2). A rule
        // must not be able to do silently what an endpoint refuses loudly.
        $this->assertSame(
            $this->org['franchise']->id,
            $this->inTenant(fn () => Customer::find($customer)->owning_franchise_id),
        );

        $run = $this->inTenant(fn () => AutomationRun::where('status', AutomationRun::FAILED)->first());
        $this->assertStringContainsString('not a field an automation may set', $run->action_results[0]['detail']);
    }

    public function test_an_automated_status_change_still_fires_the_status_trigger(): void
    {
        $this->automation('job.created', null,
            [['type' => 'update_field', 'field' => 'status', 'value' => 'completed']]);

        $this->makeJob();
        $this->drain();

        // "When a job is completed" is the canonical rule the plan names. It must
        // not silently fail to fire because another rule is what completed it.
        $this->assertTrue($this->inTenant(fn () => AutomationEvent::where('event_key', 'job.status_changed')->exists()));
    }

    public function test_a_mapped_ipv6_address_cannot_smuggle_an_internal_target(): void
    {
        // ::ffff:169.254.169.254 IS 169.254.169.254. It was not the same string
        // and — worse — not the same LENGTH, so the family check refused to
        // compare it against any IPv4 range and it sailed through to the metadata
        // endpoint. One character of typing, complete bypass.
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('internal address');

        app(OutboundWebhook::class)->assertAllowed('https://[::ffff:169.254.169.254]/latest/meta-data/');
    }

    // ── operability (§5.5) ───────────────────────────────────────

    /**
     * The people who could fix it are told, and only them.
     *
     * The reason this sat unbuilt is the assertion at the end: a null subject
     * makes audienceFor() fall through to EVERY ACTIVE USER, so one branch's
     * broken rule would notify a whole business. Passing the automation is what
     * prevents that, and nothing else in this file would catch it going away.
     */
    public function test_switching_itself_off_tells_the_people_who_could_fix_it(): void
    {
        $automation = $this->automation('job.status_changed', null,
            [['type' => 'webhook', 'url' => 'https://127.0.0.1/nope']]);

        [, $job] = $this->makeJob();

        foreach (['in_progress', 'completed', 'cancelled', 'scheduled', 'in_progress'] as $status) {
            $this->as()->patchJson("/api/v1/jobs/{$job}", ['status' => $status])->assertOk();
            $this->drain();
        }

        $rows = $this->inTenant(fn () => Notification::where('automation_id', $automation->id)->get());

        $this->assertCount(1, $rows, 'once on the transition - not once per failing event while it sits off');
        $this->assertStringContainsString($automation->name, (string) $rows->first()->title);

        $this->assertNotNull($rows->first()->user_id, 'a notification with no recipient is not a notification');

        // WHAT THIS TEST DOES NOT PROVE, said out loud so nobody reads it as
        // cover it does not give: the rule here is ORGANISATION-scoped, so the
        // people with reach over it and "every active user" are the same set.
        // The scoping that matters - a FRANCHISE rule not reaching the whole
        // business - needs a franchise-scoped automation and a user posted
        // somewhere else, and this fixture has neither. An assertion comparing
        // these two counts would pass either way and would only look like proof.
        // The guard that does hold is announceDisabled() passing the automation
        // as subject; delete that argument and audienceFor() broadcasts.
    }

    public function test_an_automation_that_keeps_failing_disables_itself(): void
    {
        $automation = $this->automation('job.status_changed', null,
            [['type' => 'webhook', 'url' => 'https://127.0.0.1/nope']]);

        [, $job] = $this->makeJob();

        foreach (['in_progress', 'completed', 'cancelled', 'scheduled', 'in_progress'] as $status) {
            $this->as()->patchJson("/api/v1/jobs/{$job}", ['status' => $status])->assertOk();
            $this->drain();
        }

        $automation = $automation->fresh();

        // Otherwise it retries on every event forever and the noise buries
        // whatever actually broke.
        $this->assertNotNull($automation->disabled_at);
        $this->assertStringContainsString('consecutive failures', $automation->disabled_reason);

        // THE REASON IS READ BY A CUSTOMER, so it must not carry the thrown text.
        // automations_screen.dart:370 renders this string on the automation card;
        // both callers used to pass $e->getMessage() straight into it. The
        // assertion above cannot see that - it was green while the leak existed.
        //
        // MEASURED, not derived. fc4f200f's message called this red derived; it is
        // not any more. Running the old formula - "Last error: {$reason}" - against
        // the same text this test finds in automation_runs.error:
        //     OLD contains 'internal address': true  -> assertStringNotContains FAILS
        //     NEW contains 'internal address': false -> passes
        // Done on a copy in a scratchpad rather than by reverting the model, because
        // a sabotage on this tree is published to every session reading it.
        $error = $this->inTenant(fn () => AutomationRun::where('automation_id', $automation->id)
            ->where('status', AutomationRun::FAILED)
            ->first()?->error);

        $this->assertNotNull($error, 'no failed run was recorded, so this proves nothing');
        $this->assertStringContainsString('internal address', $error,
            'the thrown text must still be kept SOMEWHERE - if this fails, dropping it '
            .'from disabled_reason lost it rather than moved it');
        $this->assertStringNotContainsString('internal address', $automation->disabled_reason);
        $this->assertStringContainsString('Run history', $automation->disabled_reason,
            'the reason must name the control the user can actually see - the menu item '
            .'at automations_screen.dart:301 is labelled "Run history"');
    }

    /**
     * A flat payload is readable by a condition.
     *
     * The customer's rule, verbatim: mail from notify@ringcentral.com whose
     * subject says Missed. `email.received` carries its keys at the top level,
     * not under `after`, and the subject of the event is the MAILBOX — which has
     * no `subject` and no `from_address`. Before the middle arm in valueOf()
     * both clauses read null, so the rule skipped every run since it was
     * written and the trace said "the value at the time was nothing".
     *
     * Break it by deleting that arm and both assertions go red.
     */
    public function test_a_condition_reads_a_flat_payload_and_not_only_an_update(): void
    {
        $event = $this->inTenant(fn () => AutomationEvent::create([
            'organization_id' => $this->org['organization']->id,
            'event_key'       => 'email.received',
            'payload'         => [
                // Exactly what MailboxWatcher::payload() writes.
                'address'      => 'office@example.test',
                'subject'      => 'Missed call from (954) 555-0111',
                'from_name'    => 'RingCentral',
                'from_address' => 'notify@ringcentral.com',
                'has_attachments' => false,
            ],
            'occurred_at'     => now(),
            'available_at'    => now(),
        ]));

        $evaluator = app(ConditionEvaluator::class);

        $matched = $evaluator->evaluate(['all' => [
            ['field' => 'from_address', 'op' => 'eq', 'value' => 'notify@ringcentral.com'],
            ['field' => 'subject', 'op' => 'contains', 'value' => 'Missed'],
        ]], $event, null);

        $this->assertTrue($matched['matched'], 'both clauses describe the payload and must match');

        // The trace is what the customer reads when a rule does not fire, so it
        // has to carry the real value rather than the null that was there.
        $this->assertSame(
            'notify@ringcentral.com',
            $matched['trace']['children'][0]['actual'],
            'the trace must show the value the condition actually saw',
        );

        // And the negative still works — a flat payload readable does not mean
        // everything now matches.
        $this->assertFalse(
            $evaluator->evaluate(
                ['all' => [['field' => 'subject', 'op' => 'contains', 'value' => 'Voicemail']]],
                $event,
                null,
            )['matched'],
        );
    }

    /**
     * Zero recipients has two causes and they send you to different places.
     *
     * Break it by returning the old single sentence from ActionRunner::notify()
     * and this goes red on the "switched off" assertion.
     */
    public function test_a_silenced_company_is_told_so_rather_than_that_nobody_had_reach(): void
    {
        $automation = $this->automation('job.created', null,
            [['type' => 'notify', 'message' => 'New job']]);

        // Nothing about the rule changes — it still matches, still runs, still
        // reaches the same people. Only the company's setting moves.
        $this->inTenant(fn () => app(SettingsResolver::class)
            ->set('notifications.automation_enabled', $this->org['organization'], false));

        $this->makeJob();
        $this->drain();

        $detail = $this->inTenant(fn () => AutomationRun::query()
            ->where('automation_id', $automation->id)
            ->latest('started_at')
            ->firstOrFail()
            ->action_results[0]['detail'] ?? '');

        $this->assertStringContainsString('switched off', $detail);

        // The failure this exists for: a settings toggle reported as a scoping
        // problem, sending whoever debugs the rule to look at franchise
        // assignments for a reason that is not there.
        $this->assertStringNotContainsString('Nobody has reach', $detail);
    }

    public function test_a_version_cannot_be_edited(): void
    {
        $automation = $this->automation('job.created', null, [['type' => 'notify', 'message' => 'hi']]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('immutable');

        // Every run records the version it executed. A version that can change
        // turns the run log into a description of a rule that no longer exists.
        $this->inTenant(fn () => $automation->activeVersion->update(['actions' => []]));
    }

    public function test_a_test_run_changes_nothing(): void
    {
        $automation = $this->automation('job.created', null,
            [['type' => 'update_field', 'field' => 'title', 'value' => 'Changed by test']]);

        [, $job] = $this->makeJob();

        $event = $this->inTenant(fn () => AutomationEvent::where('event_key', 'job.created')->first());

        $result = app(AutomationEngine::class)->rehearse($automation, $event);

        $this->assertTrue($result['matched']);
        $this->assertTrue($result['would_do'][0]['skipped']);
        // The point of a test mode is trying a rule against real data without
        // emailing a customer about a job from last March.
        $this->assertSame('A job', $this->inTenant(fn () => Job::find($job)->title));
    }

    public function test_an_action_cannot_write_a_field_the_model_does_not_expose(): void
    {
        $this->automation('job.created', null,
            [['type' => 'update_field', 'field' => 'organization_id', 'value' => 'anything']]);

        $this->makeJob();
        $this->drain();

        $run = $this->inTenant(fn () => AutomationRun::first());

        // "The model decides what is writable" is the rule that covers the next
        // field nobody thought of, not just this one.
        $this->assertFalse($run->action_results[0]['ok']);
        $this->assertStringContainsString('not a field an automation may set', $run->action_results[0]['detail']);
    }

    public function test_a_franchise_scoped_automation_ignores_another_franchises_work(): void
    {
        $other = $this->inTenant(fn () => \App\Models\Franchise::create([
            'organization_id' => $this->org['organization']->id,
            'company_id'      => $this->org['franchise']->company_id,
            'name' => 'Second Franchise', 'is_active' => true,
        ]));

        $automation = $this->automation('job.created', null,
            [['type' => 'update_field', 'field' => 'title', 'value' => 'Touched']]);

        $this->inTenant(fn () => $automation->forceFill([
            'scope_type' => 'franchise', 'scope_id' => $other->id,
        ])->save());

        [, $job] = $this->makeJob();
        $this->drain();

        // Scope is not the settings cascade in reverse: a franchise's own rule
        // must not fire on a sibling's work just because they share a parent.
        $this->assertSame('A job', $this->inTenant(fn () => Job::find($job)->title));
        $this->assertSame(0, $this->inTenant(fn () => AutomationRun::count()));
    }

    public function test_customer_owner_reassignment_reaches_the_engine(): void
    {
        $this->automation('customer.owner_reassigned', null,
            [['type' => 'notify', 'message' => '{{ name }} moved franchise']]);

        $other = $this->inTenant(fn () => \App\Models\Franchise::create([
            'organization_id' => $this->org['organization']->id,
            'company_id'      => $this->org['franchise']->company_id,
            'name' => 'Second Franchise', 'is_active' => true,
        ]));

        [$customer] = $this->makeJob();

        $this->as()->postJson("/api/v1/customers/{$customer}/owner", [
            'owning_franchise_id' => $other->id, 'reason' => 'Relocated.',
        ])->assertOk();

        $this->drain();

        $run = $this->inTenant(fn () => AutomationRun::where('status', AutomationRun::SUCCEEDED)
            ->latest('started_at')->first());

        $this->assertNotNull($run);
        // Attribute substitution only — a tenant-authored string evaluated as
        // code would be remote execution wearing a friendly name.
        $this->assertStringContainsString('Person moved franchise', $run->action_results[0]['detail']);
    }
}
