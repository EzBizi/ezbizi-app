<?php

namespace Tests\Feature\Inspection;

use App\Mail\InspectionReportMail;
use App\Models\Customer;
use App\Models\InspectionDefinition;
use App\Models\InspectionDefinitionVersion;
use App\Models\InspectionReport;
use App\Models\Job;
use App\Models\SendingIdentity;
use App\Support\Automations\ActionRunner;
use App\Support\Mail\SendingState;
use App\Support\Mail\MailTransport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/**
 * Emailing a finished inspection to the customer, as an automation action.
 *
 * Jose's call, 2026-09-14: "something to be decided in automations, not
 * hardcoded". So there is no send button and no controller here — the whole
 * feature is one `ActionRunner` case a tenant attaches to `inspection.submitted`.
 *
 * ── What is worth pinning ────────────────────────────────────────
 *
 * The happy path least of all. Every test here that matters is a REFUSAL,
 * because this is the module's first outbound message to somebody who is not a
 * user of this software: the recipient is a member of the public who gave their
 * address to a chimney company, and the attachment holds their address, their
 * answers and photographs of their property.
 *
 * So: it must not send from the EzBizi address, it must not raise when the
 * tenant has not set an address up, and it must not invent a recipient. Each of
 * those returns a run-log line the tenant can act on, and writes nothing.
 */
class InspectionReportEmailActionTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    public function test_it_emails_the_report_to_the_customer(): void
    {
        Mail::fake();

        $tenant = $this->provisionTenant('Alpha');
        $this->verifiedIdentityFor($tenant);

        $result = $this->runAction($tenant, $this->reportFor($tenant, email: 'owner@example.test'));

        $this->assertTrue($result['ok'], $result['detail']);
        Mail::assertQueued(
            InspectionReportMail::class,
            fn (InspectionReportMail $mail) => $mail->hasTo('owner@example.test'),
        );
    }

    /**
     * The refusal that matters most: never from the EzBizi address.
     *
     * `MailIdentityResolver` falls back to the platform identity when no row
     * exists, and that identity CAN send — `MailTransport::Platform` requires no
     * domain proof. So `asTenant()` would have delivered this happily, from an
     * address the recipient has never heard of, disclosing which software their
     * chimney company runs. Nothing else in the codebase had called `asTenant`
     * yet, so nothing had met that fallback.
     *
     * The tenant here has NO sending identity, which is the default state of
     * every organization on the platform today.
     */
    public function test_it_refuses_rather_than_sending_from_the_platform_address(): void
    {
        Mail::fake();

        $tenant = $this->provisionTenant('Alpha');

        $result = $this->runAction($tenant, $this->reportFor($tenant, email: 'owner@example.test'));

        Mail::assertNothingQueued();
        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('has not set up its own sending address', $result['detail']);
    }

    /**
     * An unverified identity is reported, not raised.
     *
     * `asTenant` throws `CannotSendMail` by design — right for a controller,
     * wrong for a queued action, where an exception kills the rule and tells the
     * tenant nothing. The dispatcher's own sentence is carried into the run log
     * instead, because it already says what to do about it.
     */
    public function test_an_unverified_sending_address_is_reported_not_raised(): void
    {
        Mail::fake();

        $tenant = $this->provisionTenant('Alpha');
        $this->verifiedIdentityFor($tenant, state: SendingState::Pending);

        $result = $this->runAction($tenant, $this->reportFor($tenant, email: 'owner@example.test'));

        Mail::assertNothingQueued();
        $this->assertFalse($result['ok']);
        $this->assertNotSame('', trim($result['detail']), 'the refusal must say something actionable');
    }

    /** A customer with no address is an ordinary case, not a fault. */
    public function test_a_customer_with_no_email_is_reported_and_nothing_is_sent(): void
    {
        Mail::fake();

        $tenant = $this->provisionTenant('Alpha');
        $this->verifiedIdentityFor($tenant);

        $result = $this->runAction($tenant, $this->reportFor($tenant, email: null));

        Mail::assertNothingQueued();
        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('no email address', $result['detail']);
    }

    /** Attached to the wrong trigger, it declines instead of guessing. */
    public function test_it_declines_a_subject_that_is_not_a_report(): void
    {
        Mail::fake();

        $tenant = $this->provisionTenant('Alpha');

        $result = $this->inTenant($tenant['org'], fn () => app(ActionRunner::class)->run(
            ['type' => 'report.email'],
            $this->event($tenant),
            subject: $tenant['admin'],
        ));

        Mail::assertNothingQueued();
        $this->assertFalse($result['ok']);
    }

    // ── fixtures ─────────────────────────────────────────────────

    /**
     * @return array{type: string, ok: bool, detail: string}
     */
    private function runAction(array $tenant, InspectionReport $report): array
    {
        return $this->inTenant($tenant['org'], fn () => app(ActionRunner::class)->run(
            ['type' => 'report.email'],
            $this->event($tenant),
            subject: $report->fresh(),
        ));
    }

    private function event(array $tenant): \App\Models\AutomationEvent
    {
        return $this->inTenant($tenant['org'], fn () => \App\Models\AutomationEvent::create([
            'event_key' => 'inspection.submitted',
            'subject_type' => 'inspection_report',
            'subject_id' => null,
            'payload' => [],
            'occurred_at' => now(),
            'available_at' => now(),
        ]));
    }

    private function verifiedIdentityFor(array $tenant, SendingState $state = SendingState::Verified): void
    {
        // `state` is deliberately NOT fillable — the model's docblock says so
        // and explains why: nothing with `organizations.edit` and a PATCH may
        // declare its own domain verified. A fixture has to force it, which is
        // the correct amount of friction.
        $this->inTenant($tenant['org'], function () use ($tenant, $state) {
            $identity = new SendingIdentity([
                'organization_id' => $tenant['org']->id,
                'scope_type' => SendingIdentity::SCOPE_ORGANIZATION,
                'scope_id' => $tenant['org']->id,
                'from_address' => 'reports@alpha.test',
                'from_name' => 'Alpha Chimney',
                'transport' => MailTransport::VerifiedDomain->value,
            ]);

            $identity->forceFill([
                'state' => $state->value,
                'verified_at' => $state === SendingState::Verified ? now() : null,
            ])->save();
        });
    }

    private function reportFor(array $tenant, ?string $email): InspectionReport
    {
        return $this->inTenant($tenant['org'], function () use ($tenant, $email) {
            $customer = Customer::create([
                'owning_franchise_id' => $tenant['franchise']->id,
                'number' => 'C-'.bin2hex(random_bytes(3)),
                'name' => 'Dana Okafor',
                'email' => $email,
            ]);

            $job = Job::create([
                'franchise_id' => $tenant['franchise']->id,
                'customer_id' => $customer->id,
                'number' => 'J-'.bin2hex(random_bytes(3)),
                'title' => 'Annual inspection',
                'status' => 'scheduled',
            ]);

            $screens = [[
                'name' => 'Findings',
                'fields' => [['key' => 'notes', 'label' => 'Notes', 'type' => 'text']],
            ]];

            $definition = InspectionDefinition::create([
                'name' => 'Level 1 Chimney Inspection',
                'created_by' => $tenant['admin']->id,
                'screens' => $screens,
            ]);

            $version = InspectionDefinitionVersion::create([
                'inspection_definition_id' => $definition->id,
                'version' => 1,
                'layout_preset' => 'step_strip',
                'screens' => $screens,
                'published_by' => $tenant['admin']->id,
            ]);

            return InspectionReport::create([
                'customer_id' => $customer->id,
                'job_id' => $job->id,
                'inspection_definition_id' => $definition->id,
                'inspection_definition_version_id' => $version->id,
                'submitted_by' => $tenant['admin']->id,
                'submitted_at' => now(),
                'answers' => ['notes' => 'All clear.'],
                'layout_preset' => 'step_strip',
                'definition_name' => 'Level 1 Chimney Inspection',
            ]);
        });
    }
}
