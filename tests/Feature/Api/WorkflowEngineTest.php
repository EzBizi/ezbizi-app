<?php

namespace Tests\Feature\Api;

use App\Models\AuditEvent;
use App\Models\Customer;
use App\Models\Job;
use App\Models\Workflow;
use App\Models\WorkflowEvent;
use App\Models\WorkflowRun;
use App\Models\WorkflowVersion;
use App\Services\Organizations\ProvisionOrganization;
use App\Support\Tenancy\TenantContext;
use App\Support\Workflows\OutboundWebhook;
use App\Support\Workflows\WorkflowEngine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

/**
 * The workflow engine (plan §5.5).
 *
 * The plan is blunt about why this matters: "Automation that fires twice, or not
 * at all, or at the wrong moment, destroys trust faster than a missing feature."
 * So these test the guarantees rather than the happy path.
 */
class WorkflowEngineTest extends TestCase
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

    private function workflow(string $trigger, ?array $conditions, array $actions): Workflow
    {
        return $this->inTenant(function () use ($trigger, $conditions, $actions) {
            $workflow = Workflow::create([
                'organization_id' => $this->org['organization']->id,
                'name'            => "When {$trigger}",
                'trigger_key'     => $trigger,
                'is_active'       => true,
            ]);

            $version = WorkflowVersion::create([
                'organization_id' => $this->org['organization']->id,
                'workflow_id'     => $workflow->id,
                'version'         => 1,
                'conditions'      => $conditions,
                'actions'         => $actions,
                'created_at'      => now(),
            ]);

            $workflow->forceFill(['active_version_id' => $version->id])->save();

            return $workflow->fresh();
        });
    }

    /** Deliver everything sitting in the outbox, the way the scheduled sweep does. */
    private function drain(): int
    {
        $engine = app(WorkflowEngine::class);

        return $this->inTenant(fn () => WorkflowEvent::whereNull('delivered_at')
            ->orderBy('occurred_at')
            ->get()
            ->sum(fn (WorkflowEvent $e) => $engine->deliver($e)));
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

    public function test_a_business_change_writes_its_event_in_the_same_transaction(): void
    {
        [, $job] = $this->makeJob();

        $event = $this->inTenant(fn () => WorkflowEvent::where('event_key', 'job.created')->first());

        $this->assertNotNull($event);
        $this->assertSame($job, $event->subject_id);
        $this->assertNull($event->delivered_at, 'Nothing should deliver inline.');
        $this->assertSame('scheduled', $event->payload['after']['status']);
    }

    public function test_an_event_for_a_trigger_no_module_declares_is_refused(): void
    {
        // A workflow listening for a typo would look configured and never fire —
        // the most confusing failure there is, because nothing reports an error.
        $this->expectException(\InvalidArgumentException::class);

        $this->inTenant(fn () => app(\App\Support\Workflows\EventEmitter::class)
            ->emit('job.levitated', null, []));
    }

    // ── conditions (§5.5) ────────────────────────────────────────

    public function test_a_matching_rule_acts_and_a_non_matching_one_records_why_not(): void
    {
        $this->workflow('job.status_changed',
            ['all' => [['field' => 'status', 'op' => 'eq', 'value' => 'completed']]],
            [['type' => 'update_field', 'field' => 'title', 'value' => 'Done']],
        );

        [, $job] = $this->makeJob();
        $this->drain();

        $this->as()->patchJson("/api/v1/jobs/{$job}", ['status' => 'in_progress'])->assertOk();
        $this->drain();

        $skipped = $this->inTenant(fn () => WorkflowRun::where('status', WorkflowRun::SKIPPED)->first());

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
        $this->workflow('job.created',
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
        $this->workflow('job.status_changed',
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
        $this->workflow('job.created', null,
            [['type' => 'update_field', 'field' => 'title', 'value' => 'Touched']]);

        $this->makeJob();

        $engine = app(WorkflowEngine::class);

        $event = $this->inTenant(fn () => WorkflowEvent::where('event_key', 'job.created')->first());

        $first  = $this->inTenant(fn () => $engine->deliver($event));
        $event->forceFill(['delivered_at' => null])->save();
        $second = $this->inTenant(fn () => $engine->deliver($event->fresh()));

        // At-least-once delivery is the contract; the unique index on
        // (event, version) is what stops at-least-once becoming at-least-twice.
        $this->assertSame(1, $first);
        $this->assertSame(0, $second);
        $this->assertSame(1, $this->inTenant(fn () => WorkflowRun::count()));
    }

    // ── loop protection (§5.5) ───────────────────────────────────

    public function test_a_rule_that_feeds_itself_is_stopped_and_recorded(): void
    {
        // Sets a field on every update, which emits another update. Left alone
        // this runs until something falls over.
        $this->workflow('job.updated', null,
            [['type' => 'update_field', 'field' => 'description', 'value' => 'set by the rule']]);

        [, $job] = $this->makeJob();
        $this->as()->patchJson("/api/v1/jobs/{$job}", ['title' => 'Kick it off'])->assertOk();

        // Drain repeatedly: each pass delivers the events the previous one made.
        for ($i = 0; $i < 12; $i++) {
            $this->drain();
        }

        $depth = $this->inTenant(fn () => WorkflowEvent::max('depth'));

        $this->assertLessThanOrEqual(WorkflowEvent::MAX_DEPTH, $depth);
        $this->assertDatabaseHas('audit_events', ['action' => 'workflow.cascade_stopped']);
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
        $this->workflow('job.created', null,
            [['type' => 'webhook', 'url' => 'https://169.254.169.254/steal']]);

        $this->makeJob();
        $this->drain();

        $run = $this->inTenant(fn () => WorkflowRun::first());

        // Recorded as a failed action, not an exception that loses the run —
        // whoever configured it has to be able to see why it never worked.
        $this->assertSame(WorkflowRun::FAILED, $run->status);
        $this->assertFalse($run->action_results[0]['ok']);
        $this->assertStringContainsString('internal address', $run->action_results[0]['detail']);
    }

    // ── operability (§5.5) ───────────────────────────────────────

    public function test_a_workflow_that_keeps_failing_disables_itself(): void
    {
        $workflow = $this->workflow('job.status_changed', null,
            [['type' => 'webhook', 'url' => 'https://127.0.0.1/nope']]);

        [, $job] = $this->makeJob();

        foreach (['in_progress', 'completed', 'cancelled', 'scheduled', 'in_progress'] as $status) {
            $this->as()->patchJson("/api/v1/jobs/{$job}", ['status' => $status])->assertOk();
            $this->drain();
        }

        $workflow = $workflow->fresh();

        // Otherwise it retries on every event forever and the noise buries
        // whatever actually broke.
        $this->assertNotNull($workflow->disabled_at);
        $this->assertStringContainsString('consecutive failures', $workflow->disabled_reason);
    }

    public function test_a_version_cannot_be_edited(): void
    {
        $workflow = $this->workflow('job.created', null, [['type' => 'notify', 'message' => 'hi']]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('immutable');

        // Every run records the version it executed. A version that can change
        // turns the run log into a description of a rule that no longer exists.
        $this->inTenant(fn () => $workflow->activeVersion->update(['actions' => []]));
    }

    public function test_a_test_run_changes_nothing(): void
    {
        $workflow = $this->workflow('job.created', null,
            [['type' => 'update_field', 'field' => 'title', 'value' => 'Changed by test']]);

        [, $job] = $this->makeJob();

        $event = $this->inTenant(fn () => WorkflowEvent::where('event_key', 'job.created')->first());

        $result = app(WorkflowEngine::class)->rehearse($workflow, $event);

        $this->assertTrue($result['matched']);
        $this->assertTrue($result['would_do'][0]['skipped']);
        // The point of a test mode is trying a rule against real data without
        // emailing a customer about a job from last March.
        $this->assertSame('A job', $this->inTenant(fn () => Job::find($job)->title));
    }

    public function test_an_action_cannot_write_a_field_the_model_does_not_expose(): void
    {
        $this->workflow('job.created', null,
            [['type' => 'update_field', 'field' => 'organization_id', 'value' => 'anything']]);

        $this->makeJob();
        $this->drain();

        $run = $this->inTenant(fn () => WorkflowRun::first());

        // "The model decides what is writable" is the rule that covers the next
        // field nobody thought of, not just this one.
        $this->assertFalse($run->action_results[0]['ok']);
        $this->assertStringContainsString('not a field a workflow may set', $run->action_results[0]['detail']);
    }

    public function test_a_franchise_scoped_workflow_ignores_another_franchises_work(): void
    {
        $other = $this->inTenant(fn () => \App\Models\Franchise::create([
            'organization_id' => $this->org['organization']->id,
            'company_id'      => $this->org['franchise']->company_id,
            'name' => 'Second Franchise', 'is_active' => true,
        ]));

        $workflow = $this->workflow('job.created', null,
            [['type' => 'update_field', 'field' => 'title', 'value' => 'Touched']]);

        $this->inTenant(fn () => $workflow->forceFill([
            'scope_type' => 'franchise', 'scope_id' => $other->id,
        ])->save());

        [, $job] = $this->makeJob();
        $this->drain();

        // Scope is not the settings cascade in reverse: a franchise's own rule
        // must not fire on a sibling's work just because they share a parent.
        $this->assertSame('A job', $this->inTenant(fn () => Job::find($job)->title));
        $this->assertSame(0, $this->inTenant(fn () => WorkflowRun::count()));
    }

    public function test_customer_owner_reassignment_reaches_the_engine(): void
    {
        $this->workflow('customer.owner_reassigned', null,
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

        $run = $this->inTenant(fn () => WorkflowRun::where('status', WorkflowRun::SUCCEEDED)
            ->latest('started_at')->first());

        $this->assertNotNull($run);
        // Attribute substitution only — a tenant-authored string evaluated as
        // code would be remote execution wearing a friendly name.
        $this->assertStringContainsString('Person moved franchise', $run->action_results[0]['detail']);
    }
}
