<?php

namespace Tests\Feature\Reports;

use App\Mail\SavedReportMail;
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
use App\Support\Reports\SentReport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use RuntimeException;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/**
 * `report.send` — running a saved report and mailing it.
 *
 * ── IT SENDS. THIS FILE SAID THE OPPOSITE FOR TWO DAYS ──────────
 *
 * Jose, 2026-09-14: "EZBIZI EMAIL IS ONLY FOR EZBIZI ACTIONS LIKE ACCOUNT
 * RECOVERY, PASSWORD, RESET, VERIFICATIONS, ETC." Every path here refused, and
 * the docblock that used to sit in this spot explained at length why refusing
 * was correct rather than broken.
 *
 * Jose, 2026-09-16, reversing it: "Email ( the Ezbizi SES one, not the company
 * one )". So the case this file spent two days pinning as impossible is the one
 * it now has to prove.
 *
 * The two are not in conflict. `asTenant` put a TENANT's address on EzBizi's
 * transport — a claim about who wrote the message — and stays retired.
 * `platformForTenant` puts EZBIZI's address on it and the body says which
 * business it is about.
 *
 * ── WHAT IS LEFT TO REFUSE ──────────────────────────────────────
 *
 * Three things, and none of them is the transport: a rule whose scope cannot
 * read the report it names, a rule naming nobody, and a business past its
 * hourly allowance. The first is a disclosure guard and fires before anything
 * is rendered.
 *
 * ── A WARNING THAT NO LONGER APPLIES TO THIS PATH, KEPT ─────────
 *
 * `MailFake` has no `build()` override, so `Mail::build()` forwards to the REAL
 * manager — a faked test that reached it would open an actual socket to
 * whatever host a fixture named. That was live here while this class used
 * `throughTenantProvider`, which builds a mailer per send. `platformForTenant`
 * goes through the Mail facade and fakes properly, so `Mail::assertSent` below
 * is honest.
 *
 * Kept because the trap is one method call away: anything that moves this class
 * back onto a per-send mailer reinstates it silently, and a socket opened by a
 * green test is not a thing anybody notices.
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

    /**
     * IT SENDS NOW, and that is a reversal rather than a new feature.
     *
     * Jose retired EzBizi's own mail for tenant traffic on 2026-09-14 and
     * reversed it on 2026-09-16 — "Email ( the Ezbizi SES one, not the company
     * one )". So the case this file spent a day asserting could NOT happen is
     * the case that now has to.
     *
     * Kept as one test per fact rather than one big one, because "it sent" and
     * "it sent to the right people" fail for different reasons.
     */
    public function test_a_report_is_run_and_mailed_to_the_named_people(): void
    {
        Mail::fake();

        $tenant = $this->provisionTenant('Alpha');
        $this->makeJob($tenant, 'Sweep');

        $outcome = $this->inTenant($tenant['org'], fn () => app(SendSavedReport::class)->send(
            $this->report($tenant),
            ['owner@example.test', 'accountant@example.test'],
            $this->rule($tenant),
            $tenant['admin'],
        ));

        $this->assertTrue($outcome->ok());
        $this->assertSame(['owner@example.test', 'accountant@example.test'], $outcome->sent);

        Mail::assertSent(SavedReportMail::class, 2);
    }

    /**
     * THE AUTOMATIONS RULING, live again now that anything can send at all.
     *
     * Five recipients with one malformed must report OK and NAME the refused
     * address. Failing the whole action for one bad address in five is how a
     * rule that is working for four people auto-disables itself: the engine
     * stops a rule after five consecutive failures, so a weekly report with one
     * permanently-bad address would switch off after five weeks while four
     * people were receiving it fine.
     */
    public function test_one_bad_address_does_not_fail_the_others(): void
    {
        Mail::fake();

        $tenant = $this->provisionTenant('Alpha');
        $this->makeJob($tenant, 'Sweep');

        $outcome = $this->inTenant($tenant['org'], fn () => app(SendSavedReport::class)->send(
            $this->report($tenant),
            ['good@example.test', 'not-an-address', 'also-good@example.test'],
            $this->rule($tenant),
            $tenant['admin'],
        ));

        $this->assertTrue($outcome->ok(), 'one bad address failed the whole send');
        $this->assertSame(['good@example.test', 'also-good@example.test'], $outcome->sent);

        // NAMED rather than counted: "one address failed" is not something
        // anybody can act on.
        $this->assertArrayHasKey('not-an-address', $outcome->refused);
        $this->assertStringContainsString('not-an-address', $outcome->summary());
    }

    /** ok:false only when NOTHING left the building. */
    public function test_a_send_where_every_address_is_refused_fails(): void
    {
        Mail::fake();

        $tenant = $this->provisionTenant('Alpha');

        $this->expectException(RuntimeException::class);

        $this->inTenant($tenant['org'], fn () => app(SendSavedReport::class)->send(
            $this->report($tenant),
            ['not-an-address', 'also-not-one'],
            $this->rule($tenant),
            $tenant['admin'],
        ));
    }

    /**
     * The hourly allowance is the one refusal left, and it is a BACKSTOP.
     *
     * Counted across every rule a business owns, because ten rules each under
     * their own cap are still one SES reputation — and nothing ingests bounce
     * or complaint notifications yet, so this cap is doing the work monitoring
     * would otherwise do.
     *
     * THIS TEST PASSED FOR MONTHS WHILE THE CAP DID NOTHING (2026-09-16), and
     * that is worth knowing before trusting it. It runs on `CACHE_STORE=array`,
     * where a missing key seeds and returns 1. Production runs the DATABASE
     * store, where `Cache::increment` returns FALSE for a missing key — so the
     * counter never started and `false > $allowance` never threw. Green here,
     * inert there, and no amount of thoroughness in this file could reach it.
     *
     * Fixed in MailDispatcher at 84ee6cf9 by seeding with `Cache::add` first,
     * and pinned against the database store in PlatformMailTest, which is where
     * a store regression belongs. This assertion is about the CALLER: that a
     * scheduled report is subject to the cap at all.
     */
    public function test_a_business_past_its_hourly_allowance_is_refused(): void
    {
        Mail::fake();
        config(['mail.tenant_hourly_allowance' => 1]);

        $tenant = $this->provisionTenant('Alpha');
        $this->makeJob($tenant, 'Sweep');

        $this->expectException(CannotSendMail::class);

        $this->inTenant($tenant['org'], fn () => app(SendSavedReport::class)->send(
            $this->report($tenant),
            ['one@example.test', 'two@example.test'],
            $this->rule($tenant),
            $tenant['admin'],
        ));
    }

    /**
     * NOTHING HERE CLAIMS ARRIVAL, and that is the vocabulary decision.
     *
     * EzBizi ingests no SES events — no SNS route, no bounce handler, measured
     * rather than assumed — so delivered, bounced and opened are not facts it
     * holds. A word that claimed arrival would be "reported success, delivered
     * nothing" wearing a different hat.
     */
    public function test_the_outcome_never_claims_delivery(): void
    {
        Mail::fake();

        $tenant = $this->provisionTenant('Alpha');

        $outcome = $this->inTenant($tenant['org'], fn () => app(SendSavedReport::class)->send(
            $this->report($tenant),
            ['owner@example.test'],
            $this->rule($tenant),
            $tenant['admin'],
        ));

        foreach (['delivered', 'arrived', 'received', 'read'] as $word) {
            $this->assertStringNotContainsStringIgnoringCase(
                $word,
                $outcome->summary(),
                'the summary claims something this application cannot observe',
            );
        }
    }

    /**
     * A SCHEDULED REPORT RENDERS AT ITS OWN PLACE, NOT AT ITS AUTHOR'S VIEW.
     *
     * Automations asked who the actor is when a rule fires at 3am, and the
     * sharp half was that the actor decided what the report CONTAINED. It did,
     * and from `preferences['navigation.scope']` — the chip its author last
     * clicked in their own top bar, months earlier, on a morning unrelated to
     * the report.
     *
     * So this test puts the author's stored view at the ORGANIZATION and the
     * report at ONE BRANCH, and the two disagree on purpose: before the fix the
     * send carried both branches' jobs, which is a disclosure nobody chose and
     * nothing in the output mentions.
     *
     * The CSV is the artefact, so the CSV is what is asserted — an outcome
     * object saying "2 rows" would have been just as green either way.
     */
    public function test_a_send_renders_at_the_reports_place_not_the_authors_view(): void
    {
        Mail::fake();

        $tenant = $this->provisionTenant('Alpha');
        $other = $this->extraFranchise($tenant, 'Alpha Second');

        $this->makeJob($tenant, 'Job in the report\'s branch');
        $this->inTenant($tenant['org'], function () use ($other) {
            $customer = Customer::factory()->create(['owning_franchise_id' => $other->id]);
            Job::factory()->create([
                'franchise_id' => $other->id,
                'customer_id' => $customer->id,
                'title' => 'Job somewhere else',
            ]);
        });

        // The author is looking at the whole organization, which is the case
        // the old code took its scope from.
        $author = $this->inTenant($tenant['org'], function () use ($tenant) {
            $tenant['admin']->forceFill(['preferences' => ['navigation.scope' => 'organization']])->save();

            return $tenant['admin']->fresh();
        });

        $report = $this->report($tenant, [
            'company_id' => $tenant['company']->id,
            'franchise_id' => $tenant['franchise']->id,
        ]);

        $this->inTenant($tenant['org'], fn () => app(SendSavedReport::class)->send(
            $report,
            ['owner@example.test'],
            $this->rule($tenant),
            $author,
        ));

        // The mailable carries the rendered ReportResult, so the ROWS are
        // assertable directly - better than reaching into the attachment's
        // bytes, which is an internal shape of Laravel's rather than of this
        // report.
        $sent = Mail::sent(SavedReportMail::class)->first();
        $titles = json_encode($sent->result->rows);

        $this->assertStringContainsString("Job in the report's branch", $titles);
        $this->assertStringNotContainsString(
            'Job somewhere else',
            $titles,
            "the report rendered at the author's view rather than its own place",
        );
    }

    /**
     * THE SENTENCE PRODUCTION PRINTS IS THE ONE NO TEST REACHED.
     *
     * `MAIL_MAILER=array` in phpunit.xml is one of MailStatus::NON_DELIVERING,
     * so EVERY send test in this file runs with `transportDelivers` false and
     * reads the "does not deliver" branch. The ordinary sentence — the only one
     * a working box ever prints — was produced by nothing, and the assertions
     * above would have passed with it written any way at all.
     *
     * Found by the mirror image of it: the Automations session's caller test
     * had three refusals and never reached a successful send, so their one
     * wrong line sat in their one unexercised branch. Same shape, opposite end.
     *
     * Constructed directly rather than sent, because the branch is in the value
     * object and reaching it through the pipeline would mean pointing the test
     * at a delivering transport — real config for a sentence.
     */
    public function test_a_delivering_transport_counts_addresses_and_names_refusals(): void
    {
        $this->assertSame(
            'Sent to 2 addresses. Refused: not-an-address (not a valid address).',
            (new SentReport(
                sent: ['owner@example.test', 'accountant@example.test'],
                refused: ['not-an-address' => 'not a valid address'],
                rowCount: 3,
                capped: false,
                transportDelivers: true,
            ))->summary(),
        );

        // Singular, because "Sent to 1 addresses" is the kind of thing that
        // survives for a year in a run log nobody reads twice.
        $this->assertSame(
            'Sent to 1 address.',
            (new SentReport(
                sent: ['owner@example.test'],
                refused: [],
                rowCount: 3,
                capped: false,
                transportDelivers: true,
            ))->summary(),
        );
    }

    /**
     * THE BODY NAMES THE BUSINESS, because ezbizi.com/privacy says it does.
     *
     * Published in 0.2.0240, of mail EzBizi sends on a tenant's behalf:
     *
     *   "Those leave from EzBizi's own address rather than the organization's,
     *    and the body says which business the work is about."
     *
     * True of InspectionReportMail and of AutomationMessage's footer, and false
     * here until 2026-09-22: this subcopy named the RULE and never the business.
     * So a customer received a CSV about their own data from noreply@ezbizi.com
     * and was told an automation rule had sent it — which reads as EzBizi
     * writing to somebody it has no relationship with. Found by the SES session
     * reading the live page against all three callers.
     *
     * ── Asserted on the RENDERED BODY, not on the mailable ──────
     *
     * `$mail->businessName` being set proves the value was passed and says
     * nothing about whether the template prints it — and the template is where
     * this was wrong. The claim is about what the recipient reads.
     */
    public function test_the_body_names_the_business_the_report_is_about(): void
    {
        Mail::fake();

        $tenant = $this->provisionTenant('Alpha');
        $this->makeJob($tenant, 'Sweep');

        $this->inTenant($tenant['org'], fn () => app(SendSavedReport::class)->send(
            $this->report($tenant, ['company_id' => $tenant['company']->id]),
            ['owner@example.test'],
            $this->rule($tenant),
            $tenant['admin'],
        ));

        $body = Mail::sent(SavedReportMail::class)->first()->render();

        // The scope the report renders at is the company, so that is the name a
        // recipient must see — not the organization above it, which would name
        // a business the report is not about.
        $this->assertStringContainsString($tenant['company']->name, $body);
        $this->assertStringContainsString('through EzBizi', $body);

        // And the half that was already right survives: who can stop it.
        $this->assertStringContainsString('change or stop it', $body);
    }

    // ── a warning that found nothing ────────────────────

    /**
     * NOTHING SENT, AND THE RUN SUCCEEDED.
     *
     * The success half is the point, not the silence. `AutomationEngine:260`
     * marks a run failed on any action returning `ok: false`, and
     * `Automation::FAILURE_LIMIT` (5) disables the rule at five consecutive
     * ones — so a daily "warn me if a service area is booked out" rule that
     * correctly found nothing Monday to Friday would be DISABLED on Friday and
     * switched off on the Saturday it mattered.
     *
     * Asserting only "no mail went" would pass against that bug perfectly.
     */
    public function test_a_report_that_found_nothing_sends_nothing_and_still_succeeds(): void
    {
        Mail::fake();

        $tenant = $this->provisionTenant('Alpha');

        // No job made, so the report matches nothing.
        $outcome = $this->inTenant($tenant['org'], fn () => app(SendSavedReport::class)->send(
            $this->report($tenant),
            ['owner@example.test'],
            $this->rule($tenant),
            $tenant['admin'],
            skipWhenNoRows: true,
        ));

        Mail::assertNothingSent();

        $this->assertTrue(
            $outcome->ok(),
            'a rule doing exactly what it was asked reported failure, which '
            .'auto-disables it on the fifth quiet day',
        );

        // "Ran and sent nothing" and "did not run" are different facts, and a
        // run record showing neither is how somebody concludes it is broken.
        $this->assertSame('Ran, found nothing, sent nothing.', $outcome->summary());
        $this->assertNotNull($outcome->suppressed);
    }

    /**
     * A SUPPRESSED RUN IS NOT AN EVERY-ADDRESS-REFUSED RUN.
     *
     * Both end with an empty `sent`, and before `suppressed` existed they were
     * indistinguishable to the caller — which is the whole reason `ok()` could
     * not answer the question. This is the case that tells them apart: a real
     * failure must still fail.
     */
    public function test_a_send_where_everything_was_refused_still_fails(): void
    {
        Mail::fake();

        $tenant = $this->provisionTenant('Alpha');
        $this->makeJob($tenant, 'Sweep');

        $this->expectException(RuntimeException::class);

        $this->inTenant($tenant['org'], fn () => app(SendSavedReport::class)->send(
            $this->report($tenant),
            ['not-an-address'],
            $this->rule($tenant),
            $tenant['admin'],
            skipWhenNoRows: true,
        ));
    }

    /**
     * THE DEFAULT IS UNCHANGED, which is what makes this safe to land alone.
     *
     * Every rule that exists today mails an empty report and should keep doing
     * it — "nothing happened this week" is the answer a digest was asked for.
     * Only a caller that says `skipWhenNoRows` gets the new behaviour.
     */
    public function test_by_default_an_empty_report_is_still_sent(): void
    {
        Mail::fake();

        $tenant = $this->provisionTenant('Alpha');

        $outcome = $this->inTenant($tenant['org'], fn () => app(SendSavedReport::class)->send(
            $this->report($tenant),
            ['owner@example.test'],
            $this->rule($tenant),
            $tenant['admin'],
        ));

        Mail::assertSent(SavedReportMail::class);
        $this->assertNull($outcome->suppressed);
        $this->assertTrue($outcome->ok());
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
