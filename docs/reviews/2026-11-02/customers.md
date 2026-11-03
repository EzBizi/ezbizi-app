# Customers — review 2026-11-02

Measured on production through the live API (Viewer key) and read-only SQL, on Jose's
own record C-04284 (+13057242199). No production data was changed.

## 1. Working

- **List and search:** `GET /customers?search=3057242199` finds C-04284 (A-1 Chimney · Nationwide, 5 jobs, Miami FL).
- **Leads in search** (1660bea7): the same search returns his 10 leads (Website Form, Live Chat) with status and date, as their own `leads` list.
- **Segment counts** (all / repeat / none) are returned with every list.
- **Detail:** address, owner franchise and company, served-by, and the cross-franchise warning setting are all present.
- **Money** (dec2ef64, EzBizi invoices first, Workiz per job as the fallback): present for Financials readers.
- **Notes from Workiz:** C-04284 returns 2 notes by Jonathan Torres on JOB-23327 (Workiz 100076182), newest first, under the right branch.
- **Duplicates sweep:** returns groups with owner and company on each customer. **1,097 groups** today.
- **Sorting** by every column, the Created column, and the Company column at organization level (783e0994).
- **Export:** includes `owning_company`.
- **Geocode endpoint** (87c577e4 / 4d3e66ed): live for Customers and Clock In.
- **Re-homes:** MA (745) and NY (281) were moved, audited and verified.

## 2. Broken

None found in the paths measured. Two loose ends:

- **Twins are still being made, at a low rate.** Of 4,310 customers created since 10-07, 13 share both phone and name with an older customer, and 100 share a phone.
  - Example: phone 571-224-5123 now has six records, the newest C-49573 on 10-29.
  - Why: Workiz adoption only attaches when phone, name and ZIP all match exactly one person, so a new ZIP or a spelling change ("Sayyav") creates a new customer.
  - That is the agreed, deliberately strict rule (a wrong merge can't be undone). Every twin shows up in the duplicates sweep. There is no merge tool to act on them (see 5).
- **The Money tab can show all zeros.** C-04284 returns a USD row of zeros. He has Workiz jobs but no invoices or payments, so the tab shows $0 everywhere instead of being hidden. Small; the fix is UI-UX's (hide when every figure is 0) or mine (return no row). I'll take whichever UI-UX prefers.

## 3. Cohesiveness

- The detail panel follows the Jobs panel shape (Customer / Money / Notes from Workiz), as approved in queue 58/59.
- "Notes from Workiz" is still Workiz-named. Once Jobs imports Workiz notes into `job_notes` (asked 10-27), it becomes "Notes" and reads EzBizi's own notes.
- The Workiz parts (the Workiz client column, Notes from Workiz) are hidden when no row has Workiz data. They are not yet hidden by "this branch has no Workiz", which the 10-27 program asks for.

## 4. Suggestions (ranked)

1. **A merge tool** for duplicate groups: move jobs, keep the older number, audit it. It turns the 1,097-group sweep from a report into work that gets done, and stops twins piling up.
2. **Native notes** (job_notes import, with Jobs). Removes the last Workiz dependency on the customer page.
3. **Hide the Money row when everything is 0.** Less noise on customers with no billing.
4. **Eager-load the owning company in the customer export.** Today it is two lazy loads per row; Jobs' `with` support makes it one query per chunk.
5. **Customer timeline** (calls, recordings, transcripts; A1 Leads takeover item): still to build, on Leads' `lead_conversations` matched by phone. Recordings stream from the provider, never stored.

## 5. Needs Jose

1. **Build a duplicate merge tool?** 1,097 groups. It would move the jobs, keep the oldest number, and record who merged what. Staff/owner booking records (Eve, Shoval) stay excluded, as you ruled.
2. **Customer edits can't reach Workiz:** Workiz's API has no way to update a client. Fine to leave edits in EzBizi only, with staff retyping in Workiz while it stays in use?
