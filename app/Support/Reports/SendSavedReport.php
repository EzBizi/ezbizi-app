<?php

namespace App\Support\Reports;

use App\Mail\SavedReportMail;
use App\Models\Automation;
use App\Models\SavedReport;
use App\Models\User;
use App\Support\Audit\AuditLogger;
use App\Support\Automations\EventScope;
use App\Support\Mail\MailDispatcher;
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
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @param  list<string>  $recipients
     *
     * @throws RuntimeException when the rule's scope does not cover the report
     */
    public function send(SavedReport $report, array $recipients, Automation $rule, User $as): void
    {
        $this->refuseIfOutOfScope($report, $rule);

        if ($recipients === []) {
            throw new RuntimeException('This rule names nobody to send the report to.');
        }

        $result = $this->runner->run($report->definition, $as);

        $this->mail->asTenant(
            $report->franchise ?? $report->company ?? $as->organization,
            new SavedReportMail($report, $result),
            $recipients,
        );

        /*
         * Written down BEFORE anything can go wrong with the delivery, and
         * separately from the run: a mailed report leaves the building, and the
         * question afterwards is always who sent what to whom. The row names the
         * recipients because that is the part no other record holds.
         */
        $this->audit->log('report.sent', $report, [], [
            'name'       => $report->name,
            'row_count'  => $result->count,
            'capped'     => $result->capped,
            'recipients' => $recipients,
            'rule'       => $rule->getKey(),
        ]);
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
