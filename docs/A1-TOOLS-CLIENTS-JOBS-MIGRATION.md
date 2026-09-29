# A1 Tools clients, jobs and money history into EzBizi

Coordinated by the deploy desk. This file is the single source of truth for this
part: Jose's decisions, what was measured, who builds what, and the order. It
holds no customer data. The inventory stays on this machine only:
`C:\Users\jose\AppData\Local\Temp\claude\H--Development-EzBizi\c0ae8ada-92d0-48a2-8f10-54e767b03b7e\scratchpad\a1-clients-jobs-inventory.md`.
Read it from there. Do not copy it into git.

It runs after the staff migration (`docs/A1-TOOLS-PEOPLE-MIGRATION.md`).

## Jose's words

2026-09-29:

> Once you are done with everything we also need to import the A1Tools Client List, right now on ezbizi we have around 7000 customers for a1chimney, while on a1tools we have 38867
> so handle that as well, same way as ur handling the users, fit and adapt to our system.
> same for jobs 1000 on ezbizi, 8474 on a1tools

His answers, 2026-09-29:

| Question | Answer |
|---|---|
| Which of the ~32,800 A1-only clients to import (duplicates merged, junk emails and phones blanked) | "All of them" (32,865) |
| The money history: $4.65M in Workiz invoices, $0.9M in older CSV invoices, $5.85M in estimates. EzBizi has no billing | "build it, but as a part of Jobs and implement it." |
| Jobs from before May 2026, which exist only in Workiz | "See if you can find a way to export them you can take control of chrome and export them from workiz." |

## What was measured (read-only, 2026-09-29)

- **Jobs:**
  - All 8,477 of A1's jobs are already live in EzBizi, which has 8,526.
  - Jose's "1000" is the Jobs screen's Upcoming tab (959).
  - No job import is needed for May 2026 onward.
  - Jobs before about May 2026 are in neither system, only in Workiz.
- **Clients:**
  - A1's `crm_clients` holds 38,870 rows:
    - 33,227 from the Workiz client-list CSV A1 imported on 2026-03-16
    - 5,272 from A1's Workiz sync
    - 200 staff-created
    - 151 test
  - 5,855 match EzBizi, covering 7,907 of its 7,943 customers, mostly by Workiz client number.
  - A1-only to import: **32,865** (the CSV clients plus the rest, minus test rows).
  - Workiz client numbers repeat across the 9 accounts, so the key is **franchise + client number**. A1 keyed by company only, so about 1,430 A1 rows mix different people, and A1's client-to-job links cannot be trusted.
  - Junk contact values (22 shared emails on 3,421 rows, 5 shared phones on 487) are blanked on import.
- **Money:**
  - 2,731 Workiz invoices ($4.65M, $1.18M open as A1 last saw them)
  - 1,488 CSV invoices ($0.9M, Dec 2024 to Mar 2026, almost all Nationwide)
  - 2,388 estimates ($5.85M quoted)
  - EzBizi has no invoice, estimate or payment tables.

## Rules any import must follow

- Never overwrite the 7,907 matched customers or any existing job. Enrich only where EzBizi is empty, if at all.
- For every imported client, write its `workiz_clients` row (franchise + client number) in the same transaction. Otherwise the next Workiz job for that client creates a duplicate customer.
- Run under the Workiz projection's lock, and fire no automation events.
- Record every id it creates, so the run can be rolled back.
- Offer `--dry-run`, and take a dump before the real run.

## Who builds what

1. **Clients** (Workiz `local_ea9c0484-…`, with Customers `local_90d427a5-…` reviewing the customer fields): `customers:import-a1-tools --dry-run`.
   - Dedupe on franchise + client number, then phone.
   - Company name and secondary phone go into notes, as does the "client since" date, unless a column is added.
2. **Billing, as part of Jobs** (Jobs `local_aa58c8ac-…`): invoices, estimates and payments on a job, "build it, but as a part of Jobs and implement it". Then import A1's money history into it: Workiz invoices by job UUID, CSV invoices and estimates by client.
3. **Old jobs:** first ask whether the Workiz API can return jobs from before May 2026 (Workiz). If it can't, the deploy desk exports them from the Workiz website in Jose's Chrome, as he offered. Then they're imported through the existing projection.
4. **Export** (A1 Tools Dismantler): a client, invoice and estimate bundle in the format the importers agree, with the same safety rules as the staff export.

## Also found, for the Customers owner

- 1,064 live EzBizi customers share 15 email addresses; 8 look like placeholders, the biggest used 451 times. This skews duplicate detection, and any email automation that uses the customer email.
- No job has a technician set, and only 4 of 434 Workiz team members are linked to users. The staff migration's Workiz links (33) address part of this.
