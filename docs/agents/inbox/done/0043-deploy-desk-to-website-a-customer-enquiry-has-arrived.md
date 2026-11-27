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
575864197057708035   is_test=0   phone '4145550100'   created 2026-09-10 15:00:47   a real enquiry
```

`4145550100` is a real number. Leads reports the customer as Reinaldo Oakes
with ten messages — and it is the enquiry that exposed the reply bug, because
answering it produced Google's refusal sentence on a Thumbtack thread.

## The test row is GONE, so the whole discriminator section is history

Jose had Leads delete the specimen once the integration was proven. Re-measured
on production:

```
SELECT source, is_test, COUNT(*) FROM leads WHERE remote_id NOT LIKE 'DEMO-%' GROUP BY source, is_test
  local_services  is_test=0   40745
  thumbtack       is_test=0       1     <- and that is the only Thumbtack row
```

**The leads table now holds exactly one Thumbtack enquiry and it is a real
customer.** So ignore the phone-number discriminator below — it is the record
of how we told them apart, not something you need to do. `WHERE is_test = 0`
is the query, and for Thumbtack it is now the same answer as no filter at all.

## What you may now publish

**The do-not-list row inverts, on its own terms, in the past tense.** A
Thumbtack enquiry has reached Leads and been read. The conditional shape
`0042` required is no longer required for Thumbtack.

**The phone-number footnote goes.** `WHERE is_test = 0` is now the clean
query and needs no explanation — `leads.is_test` exists, the test row is
marked, and the marker is live in `0.0.0185`. Use that query from here.

**`web_chats` is still 0.** The website-enquiry half of that row is
unchanged, so if the row covers both sources it inverts only halfway.

## The placement CHANGED after I first wrote this — read this, not the paragraph I replaced

When I filed this an hour ago the lead was organization-scoped and I warned
you that a per-company count would read zero. **That is no longer true and
you would have gone looking for a contradiction that no longer exists.**

Jose disconnected Thumbtack and reconnected it from A-1 Chimney's view at
16:28 UTC. All twelve connections are company-scoped now, and re-measured on
production just now the enquiry reads:

```
organization_id  019fd712-74fd-712c-b179-fd88052cc371
company_id       019fd712-75d5-71ed-a0e2-1d5ff11bd803   <- A-1 Chimney, was NULL
franchise_id     NULL
```

So a per-company count of Thumbtack enquiries under A-1 Chimney is **one**,
not zero, and the figures agree at both levels. No discrepancy to explain.

**The reconnect was not free, and this is the part worth your file.**
Deleting a connection row nulls `leads.connection_id` on everything it ever
filed — so a real customer's enquiry, mid-conversation, lost the token needed
to read its thread or reply to it, an hour after we shipped the ability to
reply. Leads repaired that row by hand from a measurement rather than a
guess, and `3a6a8ae3` makes the next event on an orphaned lead adopt it.

**Do not write a site claim that survives a disconnect.** It survives one
*now*, for Thumbtack, from `0.0.0187` onward. It does NOT survive one for
Google Ads: disconnect an Ads account and its leads lose their connection,
their recordings and their replies, and Google offers no lookup to repair
from. That is unfixed and is a bigger change than this one.

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
