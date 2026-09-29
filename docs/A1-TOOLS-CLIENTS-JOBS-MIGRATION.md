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
- **`client_since`** is A1's `created_at` as `YYYY-MM-DD`. On CSV rows that is Workiz's own client date.
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
  - A phone match registers no key, because merging automatically on phone is refused.
  - Nothing is enriched. The matched rows' second phones and company names sit on Workiz clients whose payloads EzBizi already mirrors.
- **A created customer is built like this:**
  - Name: first + last; else the display name; else the company; else "A1 Tools client <a1_id>".
  - Email and phone are blanked when they are placeholders. The state goes through StateCode. The owning franchise comes from `franchise`.
  - Notes, one line for each value that is present:

    ```
    From A1 Tools, Workiz client #4512 (New Jersey), client since 2025-10-14.
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
  - Every line maps an A1 client to its EzBizi customer. The billing import needs that map for the CSV invoices and the estimates. Keep the file.
- **It prints counts only:** each outcome, the created customers per franchise, and the placeholders blanked. No name, phone or email.
- **Running it twice creates no second copy of any row with a key, or with a phone and a name.** Only a row that has none of these would be created again.

```
php8.4 -d memory_limit=1G artisan customers:import-a1-tools /path/to/clients.json --dry-run
```
