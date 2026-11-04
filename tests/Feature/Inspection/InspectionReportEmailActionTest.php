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
*
 * ── FOUR TESTS WERE REMOVED WITH THE HOLD, DELIBERATELY ─────────
 *
 * `emailInspectionReport` now returns Jose's ruling before it looks at
 * anything, so every branch below it is unreachable and every test aimed at one
 * passes no matter what that branch does. Four did:
 *
 *   - it refuses rather than sending from the platform address
 *   - an unverified sending address is reported, not raised
 *   - a customer with no email is reported and nothing is sent
 *   - it declines a subject that is not a report
 *
 * Each was real and each proved something while the code could reach it. Kept
 * as-is they would have been four green tests that cannot fail — the exact
 * shape this module spent a week finding elsewhere — and worse than absent,
 * because a suite listing them reads as though those refusals are still
 * verified. They are not; they are merely unreachable.
 *
 * RESTORE THEM WHEN THE HOLD LIFTS. The fixtures they used are all still here
 * — `verifiedIdentityFor`, `reportFor`, `runAction` — so each is a few lines,
 * and `git log` on this file has the originals.
 */
class InspectionReportEmailActionTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;






    /**
     * NO SENDING IDENTITY, DELIBERATELY — that is the production state.
     *
     * This test used to call `verifiedIdentityFor($tenant)` first, because the
     * action sent through `asTenant` and needed one. It does not any more:
     * Jose reversed the ruling on 2026-09-16 and the send goes out on EZBIZI's
     * identity via `platformForTenant`, with the branch named in the body.
     *
     * Leaving the fixture in would have been the quiet failure. Production has
     * ZERO rows in `sending_identities`, so a test that configures one proves
     * the action works in a state no organization is in, and would have stayed
     * green if the action had silently gone back to requiring one.
     */
    public function test_it_emails_the_report_to_the_customer(): void
    {
        Mail::fake();

        $tenant = $this->provisionTenant('Alpha');

        $result = $this->runAction($tenant, $this->reportFor($tenant, email: 'owner@example.test'));

        $this->assertTrue($result['ok'], $result['detail']);
        Mail::assertQueued(
            InspectionReportMail::class,
            fn (InspectionReportMail $mail) => $mail->hasTo('owner@example.test'),
        );
    }

    /**
     * Mail::fake() above never builds a queue payload, so it passed while the
     * first real send died with "Malformed UTF-8": the queue JSON-encodes the
     * mailable and a PDF is binary.
     */
    public function test_the_queued_mail_survives_json_encoding_with_binary_pdf_bytes(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $bytes = "%PDF-1.4\xff\xfe\x00\x80binary";
        $mail = new InspectionReportMail($this->reportFor($tenant, email: 'owner@example.test'), 'Alpha', $bytes);

        $this->assertNotFalse(json_encode(serialize($mail)));
        $this->assertSame($bytes, unserialize(serialize($mail))->attachments()[0]->attachWith(fn ($path) => null, fn ($data) => $data()));
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
            ['type' => 'inspection_report.email'],
            $this->event($tenant),
            subject: $tenant['admin'],
        ));

        Mail::assertNothingQueued();
        $this->assertFalse($result['ok']);
    }

    /**
     * THE BUILDER MUST LET SOMEBODY SELECT IT.
     *
     * Everything else in this file proved the action WORKS. None of it could
     * see that no customer could reach it. `AutomationsController` publishes
     * each action as `implemented => in_array($a->type(), IMPLEMENTED_ACTIONS)`,
     * and that const is a hand-maintained list in another module's file — so an
     * action can be declared, executed, and covered by five green tests while
     * the picker draws it "Not built yet" and refuses the click.
     *
     * That is the QUIET direction of the same const going wrong. The loud one
     * is a const entry with no arm: rules get built and every run records
     * FAILED, which somebody notices within a day. `training.assign` had that
     * shape once. Mine had the silent one, and it SHIPPED — found by the
     * Training session reading main, not by anything here.
     *
     * So this asserts the API response a builder actually receives, rather than
     * the declaration it is derived from. A test on `ModuleRegistry` would have
     * passed throughout, because the module's half was never the broken half.
     */
    public function test_the_action_is_offered_to_the_builder_as_implemented(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        // `automations.build.view` since 891f8378 put /automations/catalogue behind
        // the Build tab's own grant. This test is about the ACTION being listed.
        // The inspection tab keys are here for the same reason one layer down:
        // the catalogue omits a module this person cannot reach.
        $user = $this->userWithRole($tenant, 'Manager', [
            'automations.view', 'automations.build.view',
            'inspection.view', 'inspection.new.view',
            'inspection.reports.view', 'inspection.builder.view',
        ]);

        $data = $this->signedInAs($user)
            ->getJson('/api/v1/automations/catalogue')
            ->assertOk()
            ->json('data');

        $actions = [];
        foreach ($data['modules'] as $module) {
            foreach ($module['declared_actions'] ?? [] as $action) {
                $actions[$action['key']] = $action;
            }
        }

        $this->assertArrayHasKey(
            'inspection.report.email',
            $actions,
            'the action must appear in the catalogue at all',
        );
        $this->assertTrue(
            $actions['inspection.report.email']['implemented'],
            'declared and executable is not enough — the builder refuses to select '
            .'an action published as implemented:false',
        );
    }

    // ── fixtures ─────────────────────────────────────────────────

    /**
     * @return array{type: string, ok: bool, detail: string}
     */
    private function runAction(array $tenant, InspectionReport $report): array
    {
        return $this->inTenant($tenant['org'], fn () => app(ActionRunner::class)->run(
            ['type' => 'inspection_report.email'],
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
