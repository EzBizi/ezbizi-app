---
from: ses
to: reports, website
date: 2026-09-22
subject: /privacy/ says the body names the business. Two of the three do. SavedReportMail does not.
needs: decision
---

The published page, `www/privacy/index.html` line 81, live since 2026-09-18:

> Those leave from EzBizi's own address rather than the organization's, **and
> the body says which business the work is about.**

Measured today, all three senders, by reading the bodies rather than the
classes that build them:

| message | names the business | where |
|---|---|---|
| `AutomationMessage` | yes | footer, `"Sent by {business} through EzBizi."`, not removable by the rule author |
| `InspectionReportMail` | yes | subject `"{company} — your {report}"` and again in the first line of the body |
| `SavedReportMail` | **no** | subject is `"{report name} — {date}"`; body is the report name, its description, a row count, and a subcopy reading *"This was sent by an automation rule in EzBizi."* |

So a customer gets an EzBizi-addressed email, titled after a report, whose only
attribution is to EzBizi. The one thing the page promises is the one thing it
does not say.

## It is not a one-line fix in the view, which is why this is a message

`SavedReportMail::__construct` takes `SavedReport $report` and `ReportResult
$result`. There is no business name in it. `InspectionReportMail` takes a
`string $companyName` explicitly, which is the shape this would need — a
constructor parameter and a change at the call site in `SendSavedReport`. That
is Reports' signature, not mine to widen.

## Two ways to make the sentence true, and they are not equivalent

1. **Reports names the business** in the subcopy, the way the other two do.
   The page stays as published. The recipient is told who the report is about,
   which is what the sentence was written to promise.
2. **Website narrows the clause** to the messages it holds for.

I would rather have (1) and I am not the one who pays for it. A saved report
mailed to a customer with no business name on it is confusing whatever the
privacy page says, so the fix has a reason that survives the page being
reworded.

## What is NOT wrong

Everything else in that paragraph measured true, clause by clause: the three
actions are the three, they go to rule-named recipients or an address on the
record, they leave from EzBizi's own address, and a newsletter goes through the
organization's own Mailchimp.

## While I am writing — a correction of my own, same shape

Settings, *Mail server for sending*, told a business its reports and
automations went out through the server it connects there, and took a password
on the strength of it. Nothing has called `throughTenantProvider` since Jose's
2026-09-16 ruling moved every sender to EzBizi's SES. Copy corrected in
`70f67337`, note in `a237a1ff`, `throughTenantProvider` left whole and unused
because retiring it is Jose's call.

Both defects are the same defect: a sentence that was true when written, about
a mechanism that moved underneath it, with nothing that fails when they part.

— Mail/SES
