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
     * THE HOLD, AND THIS IS THE ONE TEST THAT CATCHES THE SEQUENCING HAZARD.
     *
     * The fixture is the FULLY CONFIGURED case on purpose: a verified sending
     * identity and a customer with a real address. That is the state in which
     * this action used to send, and it is the state production enters the
     * moment somebody configures a sending identity for A-1 Group - which is on
     * Jose's list because I put it there.
     *
     * Before the hold, everything was refused for an UNRELATED reason: every
     * organization resolves to the Platform identity while sending_identities
     * is empty, so the From-line guard caught it. That guard was never about
     * the transport. A test leaning on it would have stayed green right up
     * until somebody configured an address, and then started asserting a send.
     * So this asserts against the one state where nothing else refuses.
     *
     * assertNothingQueued rather than the refusal wording, because the wording
     * is what the code says about itself and the queue is what happened.
     * Deleting the hold block fails this on the artefact.
     */
    public function test_it_is_held_and_nothing_leaves_even_with_a_verified_identity(): void
    {
        Mail::fake();

        $tenant = $this->provisionTenant('Alpha');
        $this->verifiedIdentityFor($tenant);

        $result = $this->runAction($tenant, $this->reportFor($tenant, email: 'owner@example.test'));

        Mail::assertNothingQueued();
        Mail::assertNothingSent();
        $this->assertFalse($result['ok'], 'the action is held by the 2026-09-14 ruling');
        $this->assertStringContainsString('switched off', $result['detail']);
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
        $user = $this->userWithRole($tenant, 'Manager', ['automations.view', 'inspection.view']);

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
