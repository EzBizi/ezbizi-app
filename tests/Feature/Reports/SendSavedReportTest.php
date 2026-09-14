<?php

namespace Tests\Feature\Reports;

use App\Mail\SavedReportMail;
use App\Models\Automation;
use App\Models\Customer;
use App\Models\Job;
use App\Models\SavedReport;
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

    public function test_a_report_is_run_and_mailed_to_the_named_people(): void
    {
        Mail::fake();

        $tenant = $this->provisionTenant('Alpha');
        $this->makeJob($tenant, 'Sweep');

        $this->inTenant($tenant['org'], fn () => app(SendSavedReport::class)->send(
            $this->report($tenant),
            ['owner@example.test'],
            $this->rule($tenant),
            $tenant['admin'],
        ));

        Mail::assertSent(SavedReportMail::class, function (SavedReportMail $mail) {
            // Assert the ARTEFACT rather than that a send was attempted: a test
            // that only checks the mailable was queued passes on an empty one.
            return $mail->hasTo('owner@example.test')
                && $mail->result->count === 1;
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

    /** The positive half: a rule whose scope does cover the report may send. */
    public function test_an_organization_rule_may_mail_a_branch_report(): void
    {
        Mail::fake();

        $tenant = $this->provisionTenant('Alpha');

        $this->inTenant($tenant['org'], fn () => app(SendSavedReport::class)->send(
            $this->report($tenant, [
                'company_id' => $tenant['company']->id,
                'franchise_id' => $tenant['franchise']->id,
            ]),
            ['owner@example.test'],
            $this->rule($tenant),
            $tenant['admin'],
        ));

        Mail::assertSent(SavedReportMail::class);
    }

    public function test_a_rule_naming_nobody_refuses_rather_than_sending_to_nobody(): void
    {
        Mail::fake();

        $tenant = $this->provisionTenant('Alpha');

        $this->expectException(RuntimeException::class);

        $this->inTenant($tenant['org'], fn () => app(SendSavedReport::class)->send(
            $this->report($tenant),
            [],
            $this->rule($tenant),
            $tenant['admin'],
        ));
    }

    public function test_a_send_is_written_to_the_audit_log_with_its_recipients(): void
    {
        Mail::fake();

        $tenant = $this->provisionTenant('Alpha');
        $this->makeJob($tenant, 'Sweep');

        $this->inTenant($tenant['org'], fn () => app(SendSavedReport::class)->send(
            $this->report($tenant),
            ['owner@example.test'],
            $this->rule($tenant),
            $tenant['admin'],
        ));

        // A mailed report leaves the building, and the question afterwards is
        // always who sent what to whom.
        $row = $this->inTenant($tenant['org'], fn () => \App\Models\AuditEvent::query()
            ->where('action', 'report.sent')->latest('occurred_at')->first());

        $this->assertNotNull($row, 'a send wrote no audit row');

        // THE RECIPIENTS ARE THE POINT. Nothing bounds who a rule may mail a
        // report to - the addresses are free text, deliberately, the same way
        // phone.sms.send takes its destination from the rule. The bound is
        // accountability rather than a check, so asserting only that SOME row
        // exists would leave the one control unverified: a control nobody can
        // read is not a control.
        $this->assertSame(['owner@example.test'], $row->metadata['recipients'] ?? null);
        $this->assertSame('Open jobs', $row->metadata['name'] ?? null);
        $this->assertSame(1, $row->metadata['row_count'] ?? null);
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
}
