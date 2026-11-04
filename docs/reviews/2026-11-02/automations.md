# Automations — review 2026-11-02

Measured on prod (release `/var/www/ezbizi-current`) on 2026-11-02 at about 14:50 UTC. All counts are non-test runs from the last 14 days, per rule, from `automation_runs`. Triplex/A1-Group has 35 rules (33 on); EzBizi Demo has 2 (both off).

## 1. Working

- Event delivery: no event is stuck for more than an hour, and no automation job is in `failed_jobs` in 14 days.
- Lead auto-replies: Google LS Text (320 sent), A1Chimney livechat (74), Everdoors livechat (5), Closed Office initial (46) and Closed Office message received (37). Every other lead correctly skips them.
- Lead alerts: New lead (755), Lead reply (1,356), Customer Waiting Warning (298), Customer waiting – Ana (298), Missed call (460) and Missed call (calls and voicemails) (581), all 0 failed.
- Email and reports: New email (170 matched; 10.5k bulk/no-match skipped), Weekly Work Hours Email Report (24), Overbooking Email Report (5), Empty Spots Tomorrow Email (6), Weekly AI summary (2) and Booked on the call, no job (12).
- Job Status Done – Send Review: 1,271 sent. It is meant to be on (Jose, 10-30). It had 4 failures, all for customers with no phone number, which is correct.
- Booking confirmed (2), WhatsApp morning leads digest (8).
- Core rules (82484dbad) are live with core_reason on the PM payment rule, the Membership renewal reminder and the 5 initial auto-replies.
- Earlier fixes are live:
  - An answered call is no longer a reply that triggers the closed-office text (919f31194).
  - job.set mirrors to Workiz through WorkizMirror (b89988dee).

## 2. Broken

| Rule | Evidence | Why | Fix |
|---|---|---|---|
| **Sad review** (01a13764-db21) | 11 of 24 runs failed, 10-20 to 11-01. `SQLSTATE[22001] Data too long for column 'title'` | Its alert step puts `{{comment}}` into the notification title, which is a 255-character column. A long review comment overflowed it, so the alert was lost; the task was still created. | **Fixed:** the title is cut at 250 characters and the full alert goes in the body. Regression test in `AlertStepTest`. 2330aa3b3. |
| **7 WhatsApp daily/weekly report rules** (01a188cc-*) | Each has 12 failed and 10 succeeded since 10-30. Meta `(#132001) Template name does not exist in the translation` | The report goes 1:1 to each chosen person. When that person has not written to the number in the last 24h, Meta needs the `report_ready` template, and that template does not exist (in that language) on the sending number **+1 877-450-8572**. Recipients with an open 24h window get the report; the others fail. | Not mine (WhatsApp/Meta setup): `report_ready` has to be created and approved on +1 877-450-8572's WhatsApp account, or these rules have to send from a number that has it. |
| **Complaint call** (01a13259-0bd4) | 10 of 272 failed: `The person who made this rule cannot change that task board.` 4 on 10-29 15:00; none since; last success 11-02 14:44. | The rule runs its task step as its creator. On 10-29 that creator could not edit the board the step targets. Now it works again; same pattern as the 10-26 outage. | Watching. The cause is permissions/board membership, not code. If it recurs, give the creator edit rights on the board or move the rule to a creator who has them. |

**Rules that never fire, or no longer fire:**

- **Google LS Text – Follow Up – 3 Hours** (on): 0 runs ever. Prod has never emitted the event `lead.follow_up_due`, so the rule cannot fire. Leads owns that emitter, `LeadsFollowUpDue`; it is either not scheduled or its condition never holds.
- **Inspection report to Workiz** (on): 0 runs ever. Prod has never emitted `inspection.submitted`, meaning no inspection has been submitted on prod yet. It is dormant, not broken.
- **Membership – Renewal Reminder** (on): no runs in 14 days. The last `membership.renewal_due` was on 10-05. Membership Program should confirm that no renewals are due, as opposed to the emitter having stopped.
- **Thumbtack Text – Initial Auto Reply** (on): 2,899 skipped and 0 sent in 14 days. Its conditions match no lead that arrived. Either Thumbtack leads are not arriving under the source/kind it checks, or none came in.
- **Happy review – Membership offer** (off) and the two Demo rules (off): never run, as expected.
- **PM payment rule 01a15f6d** (off, core): left OFF per Jose. Its last real failure was `Workiz refused it (502)` on 10-23.

## 3. Cohesiveness

Measured from data only; I did not click through the screens in this pass. For UI-UX:

- A rule's Runs history counts "skipped" (a condition did not match) beside "failed". On busy rules that is thousands of rows of noise; for example, New email shows 10.5k skipped and 170 real.
- A failure the rule can never fix itself (such as a missing WhatsApp template or a missing board right) shows the same as a transient one, and nothing on the card says "needs setup".

## 4. Suggestions (ranked)

1. **"Needs attention" badge on a rule card** when ≥3 of its last 10 runs failed, showing the last error. *Payoff:* both the 7 WhatsApp rules and Sad review failed silently for days or weeks.
2. **Hide "skipped" runs by default in a rule's history.** *Payoff:* the real sends and failures become visible.
3. **"Never fired" marker** on an active rule whose trigger event has never occurred in the org (e.g. Follow Up – 3 Hours). *Payoff:* dead rules are found without a review like this one.
4. **Check the WhatsApp template when a rule is saved:** warn if the sending number lacks `report_ready`. *Payoff:* prevents the 132001 failures.
5. **Cut every notification title in one place** (NotificationDispatcher) instead of in each step. *Payoff:* no other step can hit the 255 limit (Notifications owns it).

## 5. Needs Jose

- **Follow Up – 3 Hours** has never sent anything: should Leads make `lead.follow_up_due` fire, or should the rule be retired?
- **Thumbtack initial auto-reply** sent nothing in 14 days: are Thumbtack leads still expected to get it?
- **WhatsApp reports** reach only people who have written to +1 877-450-8572 in the last day. OK to have `report_ready` set up on that number (WhatsApp module), or should these rules send from a different number?
