<?php

namespace App\Support\Reports;

use App\Mail\SavedReportMail;
use App\Models\Automation;
use App\Models\SavedReport;
use App\Models\User;
use App\Support\Audit\AuditLogger;
use App\Support\Automations\EventScope;
use App\Support\Mail\CannotSendMail;
use App\Support\Mail\MailDispatcher;
use App\Support\Mail\MailStatus;
use App\Support\Scoping\ViewScope;
use RuntimeException;

/**
 * Run a saved report and email it (spec 2026-09-10, `report.send`).
 *
 * ── The guard lives HERE, not in the ActionRunner arm ────────────
 *
 * A rule must not mail a report its own scope cannot read. Put that check in
 * the arm and a second caller skips it; put it here and it travels with the
 * thing it guards. Raised by the Automations session in September and their
 * reasoning is the right one: `ActionRunner::sendSms` already refuses when the
 * chosen line does not belong to the rule's company, because otherwise a
 * company that can write rules can send as anybody in the organization.
 *
 * The analogue is worse, because a report is a FILE of business data rather
 * than one message: a franchise-scoped rule naming an organization-wide report
 * would mail the whole business's numbers to an address of its author's
 * choosing, and nothing about that looks wrong in the builder.
 *
 * ── RECIPIENTS ARE NOT BOUNDED, AND THAT IS A DECISION ──────────
 *
 * The guard above bounds WHICH report a rule may mail. Nothing bounds WHO it
 * may be mailed to: the addresses are free text from the rule. Raised by the
 * Training session, whose own module shipped two cross-franchise disclosures by
 * confusing exactly this pair — content scope decides what is VISIBLE and says
 * nothing about who may be TOLD.
 *
 * It is deliberate, for the same reason `phone.sms.send` takes its destination
 * from the rule: a scheduled report is about no record, so there is nobody to
 * derive a recipient from. And the obvious use is real — a company mailing its
 * own figures to its own accountant, who is in nobody's user table.
 *
 * What that means, said plainly rather than left implied: **anybody who can
 * write automation rules can route business data to any address.** The bound on
 * it is not technical, it is accountability — every send writes an audit row
 * naming the report, the row count, the rule and THE RECIPIENTS, because that
 * is the part no other record holds. `SendSavedReportTest` asserts the
 * recipients are actually in that row, since a control nobody can read is not a
 * control.
 *
 * If that trade ever stops being acceptable, the place to change it is here,
 * and the change is a policy about addresses rather than a change to the scope
 * guard — they answer different questions and fixing one will not fix the other.
 *
 * ── WHOSE ADDRESS IT LEAVES FROM, and that has now moved twice ──
 *
 * Jose, 2026-09-14: "Email should not be sent as EzBizi, EZBIZI EMAIL IS ONLY
 * FOR EZBIZI ACTIONS LIKE ACCOUNT RECOVERY, PASSWORD, RESET, VERIFICATIONS,
 * ETC." `asTenant` was retired and this class refused every send.
 *
 * Jose, 2026-09-16, reversing it: "Email ( the Ezbizi SES one, not the company
 * one )". So a report leaves from EZBIZI's address, and the body says which
 * business it is about.
 *
 * THE TWO RULINGS ARE NOT IN CONFLICT AND IT IS WORTH SAYING WHY, because
 * reading them side by side they look it. `asTenant` put a TENANT's address on
 * EzBizi's transport, which is a claim about who wrote the message.
 * `platformForTenant` puts EZBIZI's address on it. The first was retired for
 * impersonating the business; the second does not, so nothing here reopens the
 * thing he closed.
 *
 * Not `platform()` either: that one has no tenant lookup at all, on purpose, so
 * sign-in and password-reset mail cannot accidentally start consulting one.
 * This traffic is a tenant's, so it goes through the method that knows whose it
 * is — which is what makes it countable and suppressible separately from an
 * invitation. SES reputation is shared across every business on the platform.
 *
 * ── It is run as somebody, and that somebody matters ─────────────
 *
 * `ReportRunner` scopes every table to the CALLER's view and reach, so the rows
 * that go out are the rows that person could have seen on screen. A rule has no
 * user of its own, so the arm passes the automation's creator — which means a
 * rule keeps mailing what its author could read, not what its recipients can.
 * That is the correct reading of "the report is run with the scope of the rule
 * itself", and it is why the scope check above is not redundant with it: the
 * author may legitimately read the whole organization.
 */
final class SendSavedReport
{
    public function __construct(
        private readonly ReportRunner $runner,
        private readonly MailDispatcher $mail,
        private readonly MailStatus $transport,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @param  list<string>  $recipients
     *
     * @throws RuntimeException when the rule's scope does not cover the report,
     *                          when it names nobody, or when NOTHING left the
     *                          building
     * @throws CannotSendMail when this business has spent its hourly allowance.
     *                        A backstop counted across every rule it owns,
     *                        because ten rules under their own caps are still
     *                        one reputation.
     */
    public function send(SavedReport $report, array $recipients, Automation $rule, User $as): SentReport
    {
        $this->refuseIfOutOfScope($report, $rule);

        if ($recipients === []) {
            throw new RuntimeException('This rule names nobody to send the report to.');
        }

        /*
         * ── RENDERED AT THE REPORT'S PLACE, NOT AT THE AUTHOR'S VIEW ──
         *
         * Automations asked who `$as` is when a rule fires at 3am, and the
         * sharp half of their question was that the actor does not merely
         * authorise the send — it decides WHAT THE REPORT CONTAINS.
         *
         * It did, and from the wrong thing. `run()` derived the scope from
         * `ViewScope::forUser`, which reads the author's stored
         * `navigation.scope` preference: the report rendered at whichever chip
         * that person last clicked in their own top bar. A preference nobody
         * thinks of as part of a report, deciding a report's figures, months
         * later, silently.
         *
         * A saved report carries its own company or franchise. That is the
         * answer to "what is this report about", it was chosen deliberately
         * when the report was saved, and it does not move.
         *
         * THE PERSON IS STILL REQUIRED, as the reach floor rather than as the
         * scope: `forStoredAutomationScope` refuses when the author no longer
         * reaches the place the report names — "The automation creator no
         * longer reaches its saved scope." Somebody who has moved from
         * administrator to one branch stops being able to send an
         * organization-wide report, which is the correct outcome and a legible
         * one, rather than the report silently shrinking to their new reach.
         */
        $result = $this->runner->run($report->definition, $as, ViewScope::forStoredAutomationScope(
            $as,
            $report->franchise_id !== null ? ViewScope::FRANCHISE : ($report->company_id !== null ? ViewScope::COMPANY : null),
            $report->franchise_id ?? $report->company_id,
        ));
        $scope = $report->franchise ?? $report->company ?? $as->organization;

        $sent = [];
        $refused = [];

        /*
         * ONE ADDRESS AT A TIME, and that is the Automations ruling rather than
         * a preference: a rule mailing five people where ONE address is
         * malformed must report ok and name the refused one. Handing the whole
         * list to the transport in a single call makes one bad address fail all
         * five — and five consecutive failures auto-disable the rule, so a
         * report that works for four people would switch itself off.
         *
         * The cost is one transport call per recipient, and a recipient list is
         * a handful of addresses rather than a mailing.
         */
        foreach ($recipients as $address) {
            /*
             * Syntax checked HERE rather than left to the transport, and not
             * only so the loop has something to refuse. A malformed address
             * handed to SES comes back as a rejection, and rejections land on
             * the metric AWS reads - the same metric that cost this project a
             * denied production-access appeal in August. Garbage should not
             * reach the transport at all.
             */
            if (filter_var($address, FILTER_VALIDATE_EMAIL) === false) {
                $refused[$address] = 'not an email address';

                continue;
            }

            try {
                $this->mail->platformForTenant($scope, new SavedReportMail($report, $result, (string) ($scope->name ?? '')), $address);
                $sent[] = $address;
            } catch (CannotSendMail $e) {
                // NOT a per-address problem: the business has no mail server of
                // its own connected, so no address would work. Let it out, so
                // the run fails and the engine's consecutive-failure count does
                // its job — that is a persistent configuration fault, which is
                // exactly what auto-disable is for.
                throw $e;
            } catch (\Throwable $e) {
                $refused[$address] = $e->getMessage();
            }
        }

        $outcome = new SentReport(
            sent: $sent,
            refused: $refused,
            rowCount: $result->count,
            capped: $result->capped,
            transportDelivers: $this->transport->delivers(),
        );

        /*
         * Audited whatever happened, including a send where everything was
         * refused: a mailed report leaves the building, and the question
         * afterwards is always who sent what to whom. The row names the
         * recipients AND the refusals because that is the part no other record
         * holds — and because "why did they not get it" is the question that
         * follows.
         */
        $this->audit->log('report.sent', $report, [], [
            'name' => $report->name,
            'row_count' => $result->count,
            'capped' => $result->capped,
            'recipients' => $sent,
            'refused' => $refused,
            'delivers' => $outcome->transportDelivers,
            'rule' => $rule->getKey(),
        ]);

        if (! $outcome->ok()) {
            // Nothing left the building, so the run failed - ok:false is for
            // exactly this and for nothing less.
            throw new RuntimeException(
                'Could not send "'.$report->name.'" to anybody. '.$outcome->summary()
            );
        }

        return $outcome;
    }

    /**
     * The rule's scope must cover the report's place.
     *
     * `EventScope::capture` builds the (type, id, company chain) triple that
     * `coversScope` wants, and it is reused rather than re-derived because the
     * chain is the part that is easy to get wrong — the third argument defaults
     * to an empty array and then silently answers "does not cover" for every
     * company-scoped rule.
     */
    private function refuseIfOutOfScope(SavedReport $report, Automation $rule): void
    {
        $scope = EventScope::capture($report);

        if ($rule->coversScope($scope['type'], $scope['id'], companyChain: $scope['company_chain'])) {
            return;
        }

        throw new RuntimeException(
            'This rule cannot send "'.$report->name.'": the report covers a wider place than '
            .'the rule does. A rule belonging to one branch cannot mail a report about the '
            .'whole organization.'
        );
    }
}
