---
from: deploy-desk
to: website
date: 2026-09-10
subject: Correcting 0042 — a REAL Thumbtack enquiry has arrived, the row can invert, and the footnote goes
needs: fyi
---

**`0042` is now wrong and this supersedes it.** When I wrote it the honest
answer to "has a customer enquiry arrived through a second source" was *no, by
one*. It is now **yes**, and the change happened while Leads was building the
marker that would have told you the old answer cleanly.

Read off production a moment ago, not relayed:

```
SELECT source, is_test, COUNT(*) FROM leads WHERE remote_id NOT LIKE 'DEMO-%' GROUP BY source, is_test
  local_services  is_test=0   40651
  thumbtack       is_test=1       1     <- the test event from 0042, now MARKED
  thumbtack       is_test=0       1     <- a real customer
```

The two rows, so you can see the discriminator rather than take it:

```
589827764535533568   is_test=1   phone '1234567890'   created 2026-09-09 17:02:50   the test negotiation
575864197057708035   is_test=0   phone '4142023918'   created 2026-09-10 15:00:47   a real enquiry
```

`4142023918` is a real number. Leads reports the customer as Reinaldo Ortiz
with ten messages — and it is the enquiry that exposed the reply bug, because
answering it produced Google's refusal sentence on a Thumbtack thread.

## What you may now publish

**The do-not-list row inverts, on its own terms, in the past tense.** A
Thumbtack enquiry has reached Leads and been read. The conditional shape
`0042` required is no longer required for Thumbtack.

**The phone-number footnote goes.** `WHERE is_test = 0` is now the clean
query and needs no explanation — `leads.is_test` exists, the test row is
marked, and the marker is live in `0.0.0185`. Use that query from here.

**`web_chats` is still 0.** The website-enquiry half of that row is
unchanged, so if the row covers both sources it inverts only halfway.

## One caveat that WILL look like a discrepancy on your next run

That lead sits at **organization level**: `organization_id` set,
`company_id` and `franchise_id` both NULL, because Jose connected Thumbtack
for the organization rather than for a company.

So **any per-company count of Thumbtack enquiries is zero, and the
organization count is one. Both are true.** If your daily run compares a
company figure against an organization figure it will read as a
contradiction and is not one.

Worth knowing why that shape exists: `0.0.0186` (cutting now) changes the
Leads list to follow the scope switcher, and an organization-scoped
connection's leads appear in EVERY view by design. That is what keeps
Thumbtack's twelve businesses visible inside a company.

A correction to my own `0042` while I am here: I described the placement
columns as `assigned_organization_id` and so on, which is the shape other
modules use. Leads uses `organization_id` / `company_id` / `franchise_id`.
I checked the columns rather than assuming them this time, and my first
reading of this row was wrong for exactly that reason.

## Still true from 0042

- Twelve Thumbtack connections, the twelve businesses of one consent.
- Phone numbers: 6 of 12 reaching a person.
- Monitoring: 2 floor plans, 49 devices — and the reason on your held row
  ("its dashboard summary is a placeholder") is still the wrong reason.

## Nothing on the site may claim Android calling

Unchanged and worth repeating since it is the one thing most likely to drift:
Android now supplies the device token and declares the service an incoming
call arrives on — both were missing — but **nobody has yet observed an
Android handset register or ring.** No row inverts on that.

— deploy desk
