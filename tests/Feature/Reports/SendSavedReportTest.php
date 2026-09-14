<?php

namespace Tests\Feature\Reports;

use App\Models\Automation;
use App\Models\Customer;
use App\Models\Job;
use App\Models\SavedReport;
use App\Models\SendingIdentity;
use App\Support\Mail\CannotSendMail;
use App\Support\Mail\MailTransport;
use App\Support\Mail\SendingState;
use App\Support\Reports\ReportCsv;
use App\Support\Reports\ReportResult;
use App\Support\Reports\SendSavedReport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use RuntimeException;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/**
 * `report.send` — running a saved report and mailing it.
 *
 * Unblocked 2026-09-14: Automations built the scheduled trigger, and SES left
 * the sandbox. Under the sandbox this was the worst possible shape — a send
 * that fails on a timer, reports success, and whose only symptom is somebody
 * quietly not receiving something they had stopped noticing.
 */
class SendSavedReportTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    private function report(array $tenant, array $place = []): SavedReport
    {
        return $this->inTenant($tenant['org'], fn () => SavedReport::create(array_merge([
            'name' => 'Open jobs',
            'definition' => [
                'root' => 'jobs',
                'columns' => [['resource' => 'jobs', 'column' => 'title']],
                'filters' => [],
            ],
        ], $place)));
    }

    private function rule(array $tenant, ?string $scopeType = null, ?string $scopeId = null): Automation
    {
        return $this->inTenant($tenant['org'], fn () => Automation::create([
            'name' => 'Weekly report',
            'trigger_key' => 'automation.schedule_reached',
            'scope_type' => $scopeType,
            'scope_id' => $scopeId,
            'is_active' => true,
        ]));
    }

    private function makeJob(array $tenant, string $title): Job
    {
        return $this->inTenant($tenant['org'], function () use ($tenant, $title) {
            $customer = Customer::factory()->create([
                'owning_franchise_id' => $tenant['franchise']->id,
            ]);

            return Job::factory()->create([
                'franchise_id' => $tenant['franchise']->id,
                'customer_id' => $customer->id,
                'title' => $title,
            ]);
        });
    }

    /**
     * THE GUARD THE AUTOMATIONS SESSION ASKED FOR, and the reason it exists:
     * a franchise-scoped rule naming an organization-wide report would mail the
     * whole business's numbers to an address of its author's choosing, and
     * nothing about that looks wrong in the builder.
     */
    public function test_a_branch_rule_cannot_mail_an_organization_wide_report(): void
    {
        Mail::fake();

        $tenant = $this->provisionTenant('Alpha');
        $this->sendingIdentity($tenant, SendingState::Verified);

        $this->expectException(RuntimeException::class);

        try {
            $this->inTenant($tenant['org'], fn () => app(SendSavedReport::class)->send(
                $this->report($tenant),
                ['owner@example.test'],
                $this->rule($tenant, 'franchise', $tenant['franchise']->id),
                $tenant['admin'],
            ));
        } finally {
            // Refused BEFORE anything left the building, not after.
            Mail::assertNothingSent();
        }
    }

    public function test_a_rule_naming_nobody_refuses_rather_than_sending_to_nobody(): void
    {
        Mail::fake();

        $tenant = $this->provisionTenant('Alpha');
        $this->sendingIdentity($tenant, SendingState::Verified);

        $this->expectException(RuntimeException::class);

        $this->inTenant($tenant['org'], fn () => app(SendSavedReport::class)->send(
            $this->report($tenant),
            [],
            $this->rule($tenant),
            $tenant['admin'],
        ));
    }

    // ── the tenant's own address, or nothing ─────────────────────

    // ── partial failure, which is the ruling that matters ────────

    // ── nothing can send, and that is a ruling ─────────────

    /**
     * THE ROW THAT DISTINGUISHES THE RULE FROM THE GUARD IT REPLACED.
     *
     * A tenant with its own VERIFIED sending address - the one case that used
     * to work, and the only state whose refusal is new. The other three were
     * already refused before Jose's ruling, so a test covering only those would
     * pass against the old code unchanged and prove nothing about the change.
     *
     * That question - "would this have been green before my change?" - is the
     * SES session's, asked of their own passing tests. It is sharper than the
     * one that found my last defect, which was an absence a grep could reach.
     */
    public function test_a_verified_tenant_is_still_refused(): void
    {
        Mail::fake();

        $tenant = $this->provisionTenant('Alpha');
        $this->makeJob($tenant, 'Sweep');
        $this->sendingIdentity($tenant, SendingState::Verified);

        try {
            $this->inTenant($tenant['org'], fn () => app(SendSavedReport::class)->send(
                $this->report($tenant),
                ['owner@example.test'],
                $this->rule($tenant),
                $tenant['admin'],
            ));

            $this->fail('a verified tenant mailed a report through EzBizi');
        } catch (CannotSendMail $e) {
            $this->assertStringContainsString('own email provider', $e->getMessage());
        }

        Mail::assertNothingSent();
    }

    /**
     * Every identity state, refused.
     *
     * Jose, 2026-09-14: "Email should not be sent as EzBizi, EZBIZI EMAIL IS
     * ONLY FOR EZBIZI ACTIONS LIKE ACCOUNT RECOVERY, PASSWORD, RESET,
     * VERIFICATIONS, ETC." The SERVICE rather than the from-address, so there
     * is no configuration of anything that lets a report out.
     */
    public function test_no_identity_state_lets_a_report_out(): void
    {
        $states = [null, SendingState::Pending, SendingState::Failed, SendingState::Verified];

        foreach ($states as $index => $state) {
            Mail::fake();

            $tenant = $this->provisionTenant('Tenant'.$index);
            $this->makeJob($tenant, 'Sweep');

            if ($state !== null) {
                $this->sendingIdentity($tenant, $state);
            }

            try {
                $this->inTenant($tenant['org'], fn () => app(SendSavedReport::class)->send(
                    $this->report($tenant),
                    ['owner@example.test'],
                    $this->rule($tenant),
                    $tenant['admin'],
                ));

                $this->fail('a report left with identity state '.($state?->value ?? 'none'));
            } catch (CannotSendMail $e) {
                // ignored: the refusal is the pass
            }

            Mail::assertNothingSent();
        }
    }

    /**
     * THE SCOPE GUARD STILL FIRES FIRST, and the order is the point.
     *
     * A rule mailing a report its own scope cannot read must hear about the
     * SCOPE, not about email. If the mail refusal came first, the disclosure
     * guard would be invisible until a transport is connected - and would look
     * like it had been holding all along.
     */
    public function test_the_scope_guard_is_reached_before_the_mail_refusal(): void
    {
        Mail::fake();

        $tenant = $this->provisionTenant('Alpha');

        try {
            $this->inTenant($tenant['org'], fn () => app(SendSavedReport::class)->send(
                $this->report($tenant),
                ['owner@example.test'],
                $this->rule($tenant, 'franchise', $tenant['franchise']->id),
                $tenant['admin'],
            ));

            $this->fail('a branch rule mailed an organization-wide report');
        } catch (RuntimeException $e) {
            $this->assertNotInstanceOf(CannotSendMail::class, $e);
        }
    }

    // ── the CSV ──────────────────────────────────────────────────

    public function test_the_csv_carries_the_labels_and_the_rows(): void
    {
        $csv = ReportCsv::render(new ReportResult(
            columns: [['key' => 'jobs.title', 'label' => 'Title', 'type' => 'string']],
            rows: [['jobs.title' => 'Sweep']],
            capped: false,
            count: 1,
        ));

        $this->assertStringContainsString('Title', $csv);
        $this->assertStringContainsString('Sweep', $csv);
        $this->assertStringNotContainsString('matched more', $csv);
    }

    /**
     * A CSV outlives the message it arrived in - it is saved, forwarded and
     * opened next month - so a truncated one that does not SAY it is truncated
     * is indistinguishable from a complete one. Somebody totals the column and
     * is quietly wrong.
     */
    public function test_a_capped_csv_says_so_inside_the_file(): void
    {
        $csv = ReportCsv::render(new ReportResult(
            columns: [['key' => 'jobs.title', 'label' => 'Title', 'type' => 'string']],
            rows: [['jobs.title' => 'Sweep']],
            capped: true,
            count: 5000,
        ));

        $this->assertStringContainsString('This report matched more', $csv);
    }

    public function test_a_value_with_a_comma_does_not_break_the_columns(): void
    {
        $csv = ReportCsv::render(new ReportResult(
            columns: [['key' => 'c.name', 'label' => 'Name', 'type' => 'string']],
            rows: [['c.name' => 'Smith, John']],
            capped: false,
            count: 1,
        ));

        $this->assertStringContainsString('"Smith, John"', $csv);
    }

    /**
     * A sending identity in a given state.
     *
     * MEASURED, because I nearly wrote the opposite: removing every call to
     * this leaves all eight tests GREEN. Since `asTenant` refuses
     * unconditionally, no identity state changes any outcome, so this fixture
     * is not load-bearing for a single assertion.
     *
     * It is kept in exactly one place — the verified-tenant test — because it
     * is what makes that test MEAN the distinguishing case. Without it the test
     * reads "a tenant with no identity is refused", which was already true
     * before the ruling. The fixture carries the intent, not the pass, and
     * saying so is the difference between a control and decoration.
     *
     * There used to be a second helper beside this one, from the days when a
     * verified identity made a send SUCCEED. Two fixtures for one job, one of
     * them implying an outcome that no longer exists.
     */
    private function sendingIdentity(array $tenant, SendingState $state): void
    {
        $this->inTenant($tenant['org'], function () use ($tenant, $state) {
            (new SendingIdentity([
                'organization_id' => $tenant['org']->id,
                'scope_type' => SendingIdentity::SCOPE_ORGANIZATION,
                'scope_id' => $tenant['org']->id,
                'from_address' => 'reports@alpha.test',
                'from_name' => 'Alpha Chimney',
                'transport' => MailTransport::VerifiedDomain->value,
            ]))->forceFill([
                'state' => $state->value,
                'verified_at' => $state === SendingState::Verified ? now() : null,
            ])->save();
        });
    }
}
