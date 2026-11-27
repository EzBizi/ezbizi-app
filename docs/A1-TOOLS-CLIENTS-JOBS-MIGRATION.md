# A1 Tools clients, jobs and money history into EzBizi

> **Status 2026-11-13: FINISHED 2026-09-29 — this is history, not a plan.** A1 Tools was
> switched off on 2026-09-28. The one-off commands named below (`customers:import-a1-tools`,
> `jobs:import-a1-estimates`, `ezbizi:workiz-merge-twins`, `inspection:import-a1-reports`) were
> deleted in `2795b58b2` (2026-10-09); only `ezbizi:backfill-workiz` remains. The scratchpad
> inventory path below no longer exists. The dumps and run logs named below are still in
> `/home/ubuntu/manual-dumps/` on the app box (checked 2026-11-13). "EzBizi has no billing"
> is no longer true: `estimates` and `invoices` are modules, and production holds the 2,231
> imported estimates — see `EZBIZI-TODO.md` #36.

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

## Decisions after measuring (2026-09-29)

**Old jobs:** Workiz measured that the v1 API returns jobs from before May 2026. `start_date` is the lower bound, and the pages run from the far future back. No Chrome export is needed.
- Workiz builds a paced, resumable backfill that stops on the first 429.
- It runs AFTER the clients import, so old jobs attach to the imported customers.
- Hard rule: no automation events and no customer-facing side effects. Old jobs landing as Done must not send review requests, texts, emails or notifications.
- Built: `ezbizi:backfill-workiz [--since=2023-01-01] [--pages=100] [--pause=10] [--restart] [--dry-run]` (Workiz).
  - Every run reports, per branch: jobs read, jobs new to the mirror, how many of those attach to an existing customer, and how many distinct clients will become new customers.
  - `--dry-run` reads and counts the same way and lands nothing. Its progress is its own, so it never moves the real run, but it spends the same API calls.
  - It reads each branch's pages from the farthest future back to `--since`, with a pause between pages and a page budget per run.
  - It stops on the first refusal. The next page per branch is saved after every page, and a resumed branch starts one page back.
  - It lands only jobs the mirror does not have, and writes nothing but the mirror. The projection then creates the jobs, and creating a job raises no event.
  - Run it again until it prints "Every branch is done." The projection creates up to 500 jobs a minute from what lands.

**Billing, Jobs' sketch.** Jobs measured that EzBizi's Workiz mirror already carries every A-1 job's money and refreshes it every minute ($5.09M total, $718,506 open). Jose's answers:

| Question | Answer |
|---|---|
| Hide job prices and all money from anyone without a new money permission (administrators only until granted) | "Yes, hide it (Recommended)" |
| Billing on Workiz's live money, read-only in EzBizi, plus a Money section, a Money list and EzBizi estimates | "Yes, as proposed (Recommended)" |
| Skip A1's invoice copies (the backfill brings the old jobs' money), import A1's 2,388 estimates | "Yes (Recommended)" |
| Sending invoices and estimates, and taking payments | "Later (Recommended)" |

Jobs builds Phase 1:
- `jobs.financials`, a per-role Financials toggle like Fleet's and Inventory's. Jose, answering Jobs directly: "it should be decided on roles y permissions as a financials toggle". Spec: docs/superpowers/specs/2026-09-29-jobs-billing-design.md (ef7ae140).
- the Money section on a job, and the Money list
- estimates with `estimate.accepted`
- the A1 estimates import, after the clients import

A1's 1,488 CSV invoices are skipped, because they would double-count the backfilled jobs' money.

## Clients importer (`customers:import-a1-tools`): the file it reads

Written by Workiz, 2026-09-29. It is for the Dismantler, who writes the file, and for the desk, who runs it. A key the importer does not know refuses the whole run before anything is written.

### The file

One UTF-8 JSON file. It goes to a staging directory like the people bundle's: owned by www-data, 0700, and deleted once the import is verified.

```json
{
  "format": "ezbizi.a1-clients/1",
  "exported_at": "2026-09-30T14:00:00Z",
  "organization": "A1-Group",
  "clients": [
    {
      "a1_id": 18234,
      "franchise": "NJ",
      "workiz": [{ "franchise": "NJ", "client_id": 4512 }],
      "first_name": "Ann", "last_name": "Lee",
      "display_name": null, "company_name": null,
      "email": "ann@example.com",
      "phone": "(201) 555-0100", "phone_secondary": null,
      "address_line1": "12 Oak St", "address_line2": null,
      "city": "Hoboken", "state": "NJ", "zip": "07030",
      "notes": null,
      "client_since": "2025-10-14"
    }
  ]
}
```

Rules for the exporter:

- **Export every client, the matched ones too.** Leave out only the 151 test rows.
  - The importer decides who is already in EzBizi, against the live database at run time.
  - The ~5,855 matched rows are how the desk checks the counts against the inventory.
- **`a1_id`** is `crm_clients.id`, unique. It is not stored on the customer. The manifest keeps it (below).
- **`franchise`** is required. It is an A1 code from HR's table: CT, GA, IL, NJ, OH, TX, DMV, NY or Nationwide.
  - A CSV row takes the branch of its file, and Full.csv becomes `Nationwide`.
  - Any other row takes A1's franchise link, or else the franchise of its A1 jobs.
  - A row with neither cannot be placed. Leave it out and report the count. A null refuses the run.
- **`workiz`** lists every Workiz key the row is known by: a franchise code plus the Workiz client number, as an integer.
  - A CSV row: its `workiz:<n>` tag, with the branch of its `import:<file>` tag. There are several when A1 merged rows across files.
  - A sync row: the location and ClientId in its A1 jobs' payloads.
  - `[]` when there are none.
- **Text is a string or null.**
  - Null, not a placeholder: NA, N/A, none, `-` and empty all become null.
  - Numbers go as strings, because a zip sent as a number loses its leading 0.
- **Emails and phones go as A1 holds them.** The importer blanks placeholders itself, using `Customer::PLACEHOLDER_EMAILS` and `PLACEHOLDER_PHONES`, so the list lives in one place.
- **`client_since`** is A1's `created_at` as `YYYY-MM-DD`. On CSV rows that is Workiz's own client date. It goes into `customers.customer_since`, the column Customers added for it.
- **`notes`** is A1's `notes`, unchanged.

### What the importer does

- **It checks the whole file first.** Any of these refuses the run, and nothing is written:
  - an unknown key or a franchise code it does not know
  - a repeated `a1_id` or a bad date
  - a value longer than its column
- **It gives each row one outcome, checked in this order:**
  1. `workiz`: one of the row's keys is already in `workiz_clients`. Matched.
  2. `phone`: a live customer has the same phone **and** the same name, ignoring case and spacing. Matched.
     - Phone alone is not a match. A1's own import already merged rows with the same phone and first name, so every same-phone group left is different people (inventory D).
     - Matching on phone alone would skip about 290 of them.
  3. `duplicate`: an earlier row in the file created the same key, or the same phone and name. The two become one customer.
  4. Otherwise, created.
- **A matched customer is never written to**, not even a blank field.
  - A phone+name match, and an in-file duplicate, register the row's unclaimed Workiz keys to the customer they matched (Customers' ruling). Otherwise the backfill's old jobs would create their twin, and nothing in EzBizi can merge two customers. The run's audit row lists every phone+name match and every in-file duplicate (A1 id, customer, keys taken, and for a duplicate the A1 id it repeats), so a wrong one can be found and undone.
  - Keys are checked before phones, so a key that two rows share names one customer.
  - Nothing is enriched. The matched rows' second phones and company names sit on Workiz clients whose payloads EzBizi already mirrors.
- **A created customer is built like this:**
  - Name: first + last; else the display name; else the company; else "A1 Tools client <a1_id>".
  - Email and phone are blanked when they are placeholders. The state goes through StateCode. The owning franchise comes from `franchise`.
  - Notes, one line for each value that is present:

    ```
    From A1 Tools, Workiz client #4512 (New Jersey).
    Company: …
    Second phone: …
    <A1's notes>
    ```

  - It gets one `workiz_clients` row per key, in the same transaction. The projection then attaches the client's next Workiz job to this customer instead of creating a second one.
- **It runs under the Workiz projection's lock.** `--wait` sets how long to wait for a pass already running; the default is 120 s. The rows are matched again under the lock.
- **It raises no automation event.** It writes one `customer.imported` audit row per run, with `source: a1tools`, the counts and the manifest path.
- **It writes a manifest.**
  - Path: `storage/app/private/a1-imports/clients-<time>.jsonl`, one line per row, written as each row lands: `{"a1_id", "status", "customer_id", "workiz_client_ids"}`.
  - The `created` lines are the exact list a rollback needs.
  - Every line maps an A1 client to its EzBizi customer. The A1 estimates import needs that map. Keep the file.
- **It prints counts only:** each outcome, the created customers per franchise, and the placeholders blanked. No name, phone or email.
- **Running it twice creates no second copy of any row with a key, or with a phone and a name.** Only a row that has none of these would be created again.

```
php8.4 -d memory_limit=1G artisan customers:import-a1-tools /path/to/clients.json --dry-run
```

## Desk decisions on the clients export (2026-09-29)

Written here because cross-session messaging was paused.
- **Mixed people across franchises:** the export emits only the Workiz keys in the placed franchise (the latest job's franchise). That drops 2,318 foreign keys across 1,543 rows, counted in counts.json. Those keys belong to other people, whom A1 merged into one row. Their real clients come from Workiz itself.
- **The 10 zero-key rows** (the A1 job franchise differs from the Workiz account's franchise): place them by the Workiz account's franchise, so they keep their key.
- **67 rows with 2+ keys inside one franchise:** keep all their keys. It is the same person with duplicate Workiz client records.
- **Phone + name matches** (~29 clients): the importer registers the Workiz key on the matched customer, so the backfill's old jobs attach instead of duplicating (Workiz's recommendation; the desk agrees).
- **20 unplaceable clients (no franchise) holding 161 estimates:** they are left out of the clients import. Jobs decides their placement when it builds the estimates import. The importer's manifest (`storage/app/private/a1-imports/clients-<time>.jsonl`) is the A1 client → customer join key.
- **Order:** 0.0.0310 (the clients importer) live → export → `--dry-run` → dump → real run → then `ezbizi:backfill-workiz` (merged in f86addc4, rides 0.0.0311), repeated until "Every branch is done."

## Estimates export format (desk, 2026-09-29)

**Jobs defines it,** as the owner of the A1 estimates importer, the same way HR, Clock In and Workiz each defined their own. Jobs appends a section here, "Estimates importer: the file it reads", and the A1 Tools Dismantler builds the export to it. The join key is the clients importer's manifest (`storage/app/private/a1-imports/clients-<time>.jsonl`, A1 client id → EzBizi customer). The import runs after the clients import. 20 unplaceable A1 clients hold 161 estimates, so Jobs decides whether to place them or skip them with a count.

## Estimates importer (`jobs:import-a1-estimates`): the file it reads

Written by Jobs (`local_aa58c8ac-…`), 2026-09-29, for the Dismantler, who writes the file, and the desk, who runs it. A key the importer does not know refuses the whole run before anything is written, as the clients import does.

### The file

One UTF-8 JSON file, staged like the clients bundle: owned by www-data, 0700, and deleted once the import is verified.

```json
{
  "format": "ezbizi.a1-estimates/1",
  "exported_at": "2026-09-30T14:00:00Z",
  "organization": "A1-Group",
  "estimates": [
    {
      "a1_id": 5521,
      "a1_client_id": 18234,
      "number": "1042",
      "title": "Relining and crown repair",
      "status": "accepted",
      "issued_on": "2025-06-02",
      "accepted_at": "2025-06-05T15:12:00Z",
      "created_at": "2025-06-02T13:40:00Z",
      "currency": "USD",
      "total_minor": 185000,
      "note": null,
      "job_workiz_uuid": null,
      "lines": [
        { "name": "Chimney liner", "description": null, "quantity": "1", "unit_price_minor": 185000 }
      ]
    }
  ]
}
```

Rules for the exporter:

- **Export every estimate except those on the 151 test clients.**
  - That includes the 161 estimates on the 20 clients the clients import left out. The importer skips and counts them (below), so the desk can check the total against the inventory's 2,388.
- **`a1_id`** is `crm_estimates.id`, unique. It becomes `estimates.external_ref`, with source `a1`, so a re-run skips what is already in.
- **`a1_client_id`** is `crm_estimates.client_id`, and it is required. It is the join to the clients import's manifest.
- **`number`** is A1's own estimate number, as a string exactly as A1 shows it, so people can still find an estimate by it. It is null when A1 has none, and the importer then mints an EzBizi EST- number.
- **`title`** is A1's estimate title, a string of at most 191 characters, or null. It is stored in `estimates.title`. 2,381 of the 2,383 have one, and it is what says what the money was for.
- **`status`** is one of `draft`, `sent`, `accepted`, `declined` or `archived`: A1's own words, which EzBizi uses unchanged. Anything else refuses the run.
- **`issued_on`** is `YYYY-MM-DD` or null.
- **`accepted_at`** and **`created_at`** are ISO 8601 instants in UTC. `created_at` is kept as the estimate's own date, because import time would file five years of history under today. `accepted_at` is null when A1 has no acceptance moment.
  - `created_at` is the true instant. A1's midnight-only values (2,365 of them) are local dates. Each goes out as that local midnight in UTC in A1's timezone (04:00Z or 05:00Z for Eastern), so it stays on its own day. A `T00:00:00Z` would show as the evening before.
- **`currency`** is ISO 4217, uppercase. A1 is `USD` throughout.
- **`total_minor`** is A1's total in integer cents, rounded and never truncated (1.15 dollars is 115). It is the stored total. The importer does not re-add the lines: most A1 estimates have none (26 lines on 12 of the 2,383 exported).
- **`note`** is A1's notes, unchanged, or null. A1's inspection report links (18) are left out. Each carries a live access token and points into A1, which is going away. The 8 reports that still exist are exported as PDFs for the Inspection module, which decides where they go.
- **`job_workiz_uuid`** is the A1 job's `external_workiz_uuid` for the 6 estimates that have a job, and null otherwise.
- **`lines`** is `[]` when there are none, and in A1's own order: the array order becomes each line's position. Each line has:
  - `name`, a string. A line with no name in A1 is named `(No description in A1)`, which states the absence rather than inventing an item;
  - `description`, a string or null;
  - `quantity`, a decimal as a string ("1.5"), never a float;
  - `unit_price_minor`, integer cents.
- **Text is a string or null.** As for clients, placeholders (NA, N/A, none, `-`, empty) become null.

### What the importer does

`jobs:import-a1-estimates {file} --clients-manifest=<storage/app/private/a1-imports/clients-<time>.jsonl> [--dry-run]`

- **Customer.** It maps `a1_client_id` to `customer_id` through the manifest, whatever the row's status there (`created`, `workiz`, `duplicate` or `phone`).
- **Unplaced clients are skipped with a count.** An estimate whose client is not in the manifest is not imported. It is counted and listed by A1 estimate id in the run report. This covers the 20 clients the clients import could not place, holding 161 estimates. `customer_id` is required, and an invented customer would attach money to nobody. If Jose wants them placed, the clients side places those 20 first, and a re-run here picks their estimates up; `external_ref` makes the re-run safe.
- **Franchise and currency.** The franchise is the customer's owning franchise. The currency must be that franchise's, or the row is refused and counted.
- **Job.** `job_workiz_uuid` links the EzBizi job that mirrors that Workiz job, when it is the same customer's at the same franchise. Otherwise it stays unlinked, and is counted.
- **What it writes.** It writes `source = 'a1'`, `external_ref = a1_id` and one `import_batch` uuid per run. It raises no automation events: `estimate.accepted` is not raised for history.
- **Safety.** It runs under the Workiz projection's lock. It writes one audit row, `estimate.imported`, with the counts and the run's `import_batch`.
- **Dry run.** `--dry-run` reads, matches and counts, and writes nothing.
- **Before the real run.** The desk takes a dump before the real run.
- **Rollback.** Deleting from `estimates` where `source = 'a1'` and `import_batch` is the run's uuid takes its lines with it (cascade).
- **Output.** It prints counts only and nothing about a person:
  - read;
  - created;
  - already in;
  - skipped for no customer;
  - refused for a currency mismatch;
  - job linked or unlinked.

## Clients import: results (desk, 2026-09-29)

The deploy desk ran it on release 54e37fec (0.0.0313), 18:30:46 to 18:37:10 UTC.

- **Export:**
  - 38,539 rows (sha256 52a4350c…), out of 38,904 in A1. Left out: 151 test rows and 214 unplaceable ones.
  - Customers reviewed the dry run's 17 phone+name matches plus a1_id 23999, and excluded 23999: a name and phone that may belong to someone booking for other people. So the run read 38,538.
- **Outcome:**
  - 5,663 matched by Workiz key, 18 by phone+name, 0 in-file duplicates. **32,857 created.**
  - Created by franchise: CT 3,648, DMV 1,451, GA 2,593, IL 2,761, Nationwide 3,806, NJ 9,837, OH 4,254, TX 4,507.
  - Blanked as placeholders: 3,532 emails, 524 phones.
  - The 18th phone match (a1_id 26936) is a customer the projection created four minutes before the run. The run re-matches under the lock, as designed. Customers reviews it after the fact.
- **Verified:**
  - All 32,857 created ids are live and unique.
  - 32,875 workiz_clients were registered, 18 of them on phone matches. All are live.
  - Every created customer has a number and a `customer_since`.
  - One `customer.imported` audit row. No customer automation events.
- **Extra keys** (Workiz's go, Customers' approval):
  - The problem: some key-matched rows also carried keys EzBizi did not hold. The importer registers nothing for a key match, so the backfill would have turned each of those keys into a twin customer.
  - The fix: measured after the run, then registered through the importer's own `register()`, one transaction per row, under the projection's lock. 34 keys on 34 rows.
  - 2 rows were skipped: their held keys already name two or three customers, so they are existing twins. They are listed for Customers.
  - One more `customer.imported` audit row, holding `key_matches` and `split_owners_skipped`.
- **Kept:**
  - the manifest, `storage/app/private/a1-imports/clients-20260929-183046.jsonl`: the estimates import's join key
  - the dump, `/home/ubuntu/manual-dumps/customers-before-a1-clients-import-20260929T183040Z.sql.gz` (customers, workiz_clients, record_sequences)
  - the extra keys' rollback list, `/home/ubuntu/manual-dumps/a1-extra-keys-run.log`
- **Deleted:** clients.json and the approved copy, once the import was verified.
- **Next:**
  1. The Workiz backfill: `ezbizi:backfill-workiz` with the defaults and no dry run, repeated until it prints "Every branch is done."
  2. A follow-up import, `clients-unplaced.json`, of the unplaceable rows that can be placed. 21 of the 214 are placeable so far: 5 by their own estimates or lead, 16 by address state. The other 193 stay out unless an A1 lead record, tied to the row by A1's own key, names one franchise. Most of them are staff leads with no address (184 have no state).

## Finished (desk, 2026-09-29, evening)

**Split-owner rows.** a1_id 4098 and 21065 each had one unheld key, and their held keys already named two or three existing twins. Customers chose the survivor for each: 4098 → 01a0cffc-74a0-…, 21065 → 01a0cffb-140d-…. Their keys were registered through the importer's `register()`, with one `customer.imported` audit row. Rollback list: `/home/ubuntu/manual-dumps/a1-split2-run.log`.

**Workiz backfill.** Runs 1 to 7, 18:44 to 20:59 UTC, defaults, ending "Every branch is done."
- 48,838 jobs read, 39,923 new, 36,529 of them on existing customers (91.5%), 3,195 new customers.
- New Jersey's first page needed more than Laravel's 30 s. Workiz's ef9432ca gives backfill pages 120 s; until it shipped, a warm-up request and a restart got past it.
- Ohio drew one 429. It is a burst limit, not an hourly one, and a 10-minute pause cleared it.
- The projection drained by 21:17. Every mirror row was projectable, and no job or customer event fired for a backfilled job (Workiz's check).
- Dump before it: `/home/ubuntu/manual-dumps/before-workiz-backfill-20260929T184358Z.sql.gz`.

**Unplaced clients.** `clients-unplaced.json`, 21 rows: 4 placed by their own estimates, 17 by address state.
- Customers reviewed and approved all 13 phone+name matches. 8 were created: CT 3, DMV 1, GA 1, NY 1, TX 2.
- Manifest: `a1-imports/clients-20260929-212005.jsonl`. Dump: `customers-before-a1-unplaced-import-20260929T212002Z`.
- 193 stay out: 184 staff leads with no state, 6 with a state that has no franchise, 2 with an unrecognised state, and 1 that is not an A-1 client. a1_client 33260 (inspection report 28, estimate 2372) is among them. It stays A1-only unless Jose names a franchise.

**Estimates.** `jobs:import-a1-estimates`, 21:20:53 UTC, on the combined manifest `a1-imports/clients-combined-20260929.jsonl` (both runs, 38,559 lines).
- 2,231 created, batch `dc518ad1-bed8-42f6-97dc-a20c90e9b018`, totalling 534,535,827 cents. 152 skipped because their client was never placed. 0 currency refusals.
- Rollback: `delete from estimates where source = 'a1' and import_batch = 'dc518ad1-bed8-42f6-97dc-a20c90e9b018'`. Dump: `estimates-before-a1-estimates-import-20260929T212029Z`.

**Inspection reports.** `inspection:import-a1-reports` on 0.0.0315: 7 attached to their jobs, report 28 held in the archive. All 8 archived PDFs match the manifest's sha256.

**Twins found after the backfill** (Workiz's check): 696 of the projection's new customers share a phone and name with an older customer.
- At first the desk and Customers ruled to leave the cross-franchise pairs alone. Customers then reversed that under Jose's decided rule in docs/EZBIZI-BUILD-PLAN.md §5.2: "A customer served by NJ and PA is one person". The desk's objection had been wrong: customers are readable across the organization, and jobs keep their own franchise.
- Merged on 0.0.0318, 22:40 UTC, by `ezbizi:workiz-merge-twins "2026-09-29 18:44:00" "2026-09-29 21:17:30"` (133d3cb0).
  - The rule: same phone, name and postal code, in any franchise, with a tie-break toward the twin's own branch. Skipped: placeholder addresses, twins a person has touched, and twins anything else names.
  - Result: 615 merged (216 in the same franchise, 399 in another) into 579 kept customers. 695 jobs and 615 Workiz keys moved. The twins were soft-deleted.
  - Left alone: 79 at a different address, and 2 that match several customers.
    **Closed 2026-10-01:** Jose ruled to keep the 2 ("keep them its fine"),
    relayed by Customers. Eve Sarfaty is staff and Shoval Ohayon is the
    owner. Both Eve twins (`01a0eec3-b9f1…`, `01a0eec3-ba08…`), the "Test ."
    records and every row booked under either name stay as they are. Any
    future bulk merge keeps the postal-code guard and the 800 SE 4th Ave
    exclusion: these names sit on unrelated households.
  - Manifest: `workiz-twins/merged-20260929-224045.jsonl`. Dump: `before-workiz-twin-merge-20260929T224000Z`. Audit row: `customer.twins_merged`.

**Staging deleted.** `/var/lib/a1-clients-export` is gone. The manifests and dumps named above are kept.
