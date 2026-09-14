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
 * ── NOTHING CAN ACTUALLY SEND TODAY, and that is a ruling ───────
 *
 * Jose, 2026-09-14: "Email should not be sent as EzBizi, EZBIZI EMAIL IS ONLY
 * FOR EZBIZI ACTIONS LIKE ACCOUNT RECOVERY, PASSWORD, RESET, VERIFICATIONS,
 * ETC." The SERVICE, not the from-address — so `MailDispatcher::asTenant` now
 * refuses unconditionally and there is no identity state that lets a report
 * through.
 *
 * This class is left intact rather than gutted. The scope guard, the
 * one-address-at-a-time rule and the audit row are all correct for whatever
 * transport replaces `asTenant` — the shape the product already reached
 * independently is the TENANT's own provider, the way a newsletter leaves
 * through their Mailchimp key and never touches EzBizi's SES. Deleting the
 * machinery would throw away three rulings to save a file.
 *
 * I also deleted my own guard here, which used to refuse a tenant with no
 * sending identity before the loop. It was redundant once `asTenant` refused
 * everything — and worse than redundant: its sentence said "set one up under
 * Settings, Sending Identity", and that panel is being retired. Confident,
 * specific, wrong, and FIRST, which makes it the one somebody would read.
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
     * @throws CannotSendMail ALWAYS, at the first recipient, until a business
     *                        connects its own email provider. Jose's ruling,
     *                        2026-09-14: "EZBIZI EMAIL IS ONLY FOR EZBIZI
     *                        ACTIONS LIKE ACCOUNT RECOVERY, PASSWORD, RESET,
     *                        VERIFICATIONS, ETC." `asTenant` now refuses
     *                        unconditionally, so this method reaches the
     *                        transport and is turned away there.
     */
    public function send(SavedReport $report, array $recipients, Automation $rule, User $as): SentReport
    {
        $this->refuseIfOutOfScope($report, $rule);

        if ($recipients === []) {
            throw new RuntimeException('This rule names nobody to send the report to.');
        }

        $result = $this->runner->run($report->definition, $as);
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
                $this->mail->asTenant($scope, new SavedReportMail($report, $result), $address);
                $sent[] = $address;
            } catch (CannotSendMail $e) {
                // NOT a per-address problem: the tenant has no verified sending
                // identity, so no address would work. Let it out, so the run
                // fails and the engine's consecutive-failure count does its job
                // — that is a persistent configuration fault, which is exactly
                // what auto-disable is for.
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
