# Importing Workiz into EzBizi — design

Workiz session, `local_ea9c0484-3b23-40b6-8262-10a1fccc1a3a`, 2026-09-23.
Status: **awaiting Jose's approval. Nothing is built.**

Jose, 2026-09-23: *"i need to move away from A1Tools and into EzBizi, so we need
to make some concessions and start importing information from Workiz into EzBizi
so we can start automating some stuff... Using the Integrations we already have
for the branches, i want you to basically pull whatever workiz has on Clients and
Jobs, all the available data, so we can display it properly on ezbizi, remember
to respect ownership as in org/company/franchise, etc. as well as work with the
automation module for new possibilities, new fields, etc."*

---

## Four decisions still open

1. **Chase the ten `auth_secret`s now, or ship the mirror first?** Two-way sync
   needs a credential we do not hold (§6).
2. **Is duplicate-flagging mine or a Customers ask?** (§4.3)
3. **Do the 31 unmatched Workiz area names fold or become their own areas?**
   Scheduling's question, but it decides what the importer does with 21% of
   A-1's job history (§4.4).
4. **Does the projection into `customers` / `jobs` ship with the landing table,
   or after it?** The landing table alone unblocks Scheduling's Availability
   work; the projection is what puts jobs on screen.

---

## 1 · What Workiz actually offers — measured, not read off the doc

The whole v1 surface is **Jobs, Leads, Team, TimeOff**. Base
`https://api.workiz.com/api/v1/{api_token}/`, token in the path.

### There is no client endpoint

Clients exist only as a `ClientId` integer plus contact fields **denormalised
onto every job and lead**: FirstName, LastName, Company, Email, Phone,
SecondPhone (+Ext), Address, Unit, City, State, PostalCode, Country, Latitude,
Longitude.

Three consequences, none of them a design choice:

- "Import clients" can only mean reconstructing them from the job/lead stream.
- **A client with no job and no lead is invisible.** Nothing recovers them.
- The contact details on a job are a **snapshot at that job**. Latest job wins
  for a client's current details.

`ClientId` is unique only *within* a Workiz account. A-1 runs ten disjoint
accounts — measured 2026-09-14, each token answers 204 for another account's job
— so the key is **(connection, ClientId)** and the accounts cannot collide.

### The published spec is wrong in both directions

`developer.workiz.com/api.json` documents 41 Job fields. The real payloads carry
43, and they are not the same 41:

| | |
|---|---|
| In the payload, **undocumented** | `JobName`, `LineItems`, `LocationId`, `LocationKey` |
| Documented, **absent** from real payloads | `Timezone`, `ReferralCompany` |

**The control is 7,739 real captured v1 payloads**, and it needs no live token:
`a1tools_workiz_jobs.job_data` on the app box, which A1 Tools has been storing
since long before this work. `AGENTS.md` permits A1 Tools reads. Any future
question about a Workiz field is answered there in one query rather than by
trusting the vendor's file or spending a live credential.

Field presence across all 7,739:

    ServiceArea  7,733   PostalCode  7,724   Status       7,738
    City         7,647   State       7,702   JobDateTime  7,738
    UUID         7,738   SerialId    7,738   CreatedDate  7,738
    Latitude     7,732

Effectively 100% on everything that matters.

---

## 2 · Decisions Jose already made

| Question | His answer |
|---|---|
| Source of truth during the move | **Workiz stays truth, EzBizi mirrors.** Continuous one-way sync. *"but it would be nice if it were to be a 2 way sync, so if i modify on ezbizi it gets modified on workiz"* → §6 |
| Same person in several branch accounts | **One EzBizi customer per Workiz client.** Never auto-merge. |
| Scope | Jobs + derived clients, **Leads**, **Team**. Not TimeOff. |
| Team | *"make sure that we are also able to link the workiz team member to ezbizi accounts as well"* → §7 |

---

## 3 · Shape: a landing table, then a projection

**Raw Workiz rows land in a table this session owns. Customers and Jobs project
out of it into their own models.**

I first proposed writing straight into `customers` and `jobs` with no landing
table, and that was wrong. Scheduling found it:

- The real payload has **43 fields**. `Job` has **12 fillable columns**.
- **`jobs` has no address column at all** — no zip, no line1, nothing. The
  service address today is the *customer's* postal code, which is a different
  fact.
- `PostalCode` and `ServiceArea` are the two fields A-1's entire zip→service-area
  map is derived from, and neither has anywhere to live in `jobs`.

Writing direct would have silently dropped ~30 fields including both of those,
against an instruction that said **all the available data**.

A1 Tools already solved this the same way — `a1tools_workiz_jobs` flattens the
queried columns beside a `job_data` JSON blob and projects into `crm_jobs`. This
copies that split rather than inventing one.

### 3.1 · `workiz_jobs` (proposed columns)

    remote_id        Workiz UUID          <- the stable external id
    serial_id        SerialId
    client_id        Workiz ClientId (int, account-scoped)
    connection_id    social_connections
    organization_id / franchise_id        <- derived from the connection
    service_area     TRIMMED at write
    postal_code / city / state / country / address / unit / latitude / longitude
    status / sub_status
    job_type / job_source
    booked_at            CreatedDate
    scheduled_for        JobDateTime
    scheduled_end        JobEndDateTime
    last_status_update
    created_by           name string
    payload              full raw JSON, all 43 keys
    synced_at

    UNIQUE (connection_id, remote_id)

Flatten only what something queries; keep `payload` for the rest. `service_area`
is trimmed on the way in — four live values carry a trailing space
(`"West Connecticut "`, `"Milwaukee "`, `"Connecticut "`, `"Pennsylvania "`) —
with the raw value preserved in `payload`.

`workiz_leads` mirrors this for the 32-field Lead shape.

**`created_at` is import time and says nothing about the business.** Workiz
`CreatedDate` gets its own column, `booked_at`.

### 3.2 · Who reads the landing table

- **Scheduling** builds the zip→service-area map: `GROUP BY service_area,
  postal_code` with `COUNT(*)` as the tie-break. No JSON extraction. This is what
  unblocks Availability becoming *remaining* capacity rather than *configured*.
- **Customers / Jobs** project rows into `customers` and `jobs`.
- **Leads** — the Workiz lead reader hands off to the Google API session, which
  owns that module.

---

## 4 · Ownership

### 4.1 · Placement falls out of the connection

The Workiz connections are already scoped `franchise`, one account per branch.

- **Job** → `franchise_id` = the connection's franchise. Never shared, which is
  `Job`'s existing contract.
- **Customer** → organization-shared, `owning_franchise_id` = that same
  franchise. Which is `Customer`'s existing contract.

No id ever comes from the caller; it comes from the connection row.

### 4.2 · A franchise is not a service area

Measured: **57 distinct service areas across 10 accounts, one account carrying up
to 30.** So `franchise_id` is right for a *job* and wrong as a key for the *zip
map* — Scheduling's importer groups on `(service_area, postal_code)` and never
reads `franchise_id` (their `3a2b2f40`).

**420 zips map to more than one service area. 339 of those sit inside a single
account**, so perfect franchise attribution does not rescue a
`UNIQUE(franchise_id, zip)`.

One inversion to carry, because it fails silently: on **31 jobs (0.5%)** the
`service_area` belongs to a different franchise than the connection does — a
branch doing a handful of jobs in another territory. `Job`'s own docblock
(`app/Models/Job.php:23-27`) settles that the job belongs to whoever *did* it.
So `franchise_id` from the connection is correct, **and anything that later reads
`service_area` as implying `franchise_id` out of this table is wrong 31 times
with nothing logged.** Recorded in the table's docblock.

### 4.3 · Duplicates

One EzBizi customer per Workiz client, keyed `(connection, ClientId)`. The same
real person in three branch accounts becomes three customers, merged by a human.

**Correction to what I first told Jose: `DuplicateCustomerFinder` does not
exist.** `docs/MODULE-OWNERS.md` names it in the Customers row; the file is not
in the tree. So "duplicates get flagged for a human" is something to build, not
something waiting. Open decision #2.

### 4.4 · Area names

**26 of 26** EzBizi area names match a Workiz `ServiceArea` exactly once
trimmed and lowercased. **31 Workiz names have no EzBizi area — 1,603 jobs, 21%
of history.** Several are naming drift beside areas that exist (`Connecticut`
next to East/West Connecticut; `Dallas` + `Fort Worth` next to `DFW`; `Atlanta`
next to Atlanta North/South).

**The importer will not auto-create areas and the landing table implies none.**
Auto-creating would mint a `Dallas` beside `DFW` and split one territory's
history across two capacity allowances — the exact failure per-area capacity
exists to prevent. The 31 are visible in the landing table by construction, so
when Jose rules, the answer is a query over rows that already exist rather than a
re-sync. Open decision #3.

---

## 5 · Sync

One command in the shape of `SyncLeads`, on a schedule, plus a manual "fetch
now". Re-runnable and idempotent on `(connection_id, remote_id)`.

Cadence is a **wall-clock** decision, not a quota one: Workiz advertises no
rate-limit header, measured 2026-09-15. This is the opposite of `SyncLeads`,
whose five-minute tick is a Google quota budget.

`WorkizJobs::all()` already reads every connected branch concurrently in rounds
and tags each job with its branch, and already records a per-branch failure by
name rather than dropping the branch silently. The importer reuses it; what
changes is `shape()`, which currently keeps 5 of 43 fields.

**Imported rows are read-only in EzBizi until §6 exists.** A mirror that
overwrites is only safe if nothing local can be lost.

---

## 6 · Two-way — possible, and it costs a credential

Jose: *"it would be nice if it were to be a 2 way sync."*

**What the API supports.** `/job/update/` accepts `Status`, `SubStatus`, both
dates, `JobType`, notes, tags, service area **and** the client contact fields.
`/job/assign/` does assignment, `/job/addPayment/` payments. `/lead/update/` the
same for leads. There are no delete endpoints in the documented surface.

**What it costs.** Every write endpoint requires **`auth_secret`** — a second
per-account secret. The connect form takes only `api_token`, and nothing stores
it. A-1 would have to pull **ten** of them out of Workiz.
`social_connections.credentials` is an encrypted array and already holds two
keys, so this needs no schema change — only the secret.

**What it changes.** Jose's 2026-09-14 ruling — *"it's fine though, we are using
it for reports, no one's deleting anything"* — approved holding a write-capable
credential for a **read-only** use. This is a write path. That ruling does not
reach it, and this is the new decision it said would be needed.

**What cannot round-trip.** A client cannot be edited as an entity, because there
is no client endpoint. A client edit can only be pushed as part of a job update
carrying `ClientId`. Whether that updates the client record or only that job's
snapshot is **unverified** and cannot be verified without a live write against
A-1's account.

**Proposed sequencing.** Ship the mirror first, read-only. Then flip fields to
editable one at a time behind a per-field allowlist, each proven to round-trip
before it is opened. Open decision #1.

---

## 7 · Team

`/team/all/` and `/team/get/{id}` return `id, name, email, role, fieldTech,
active, serviceAreas, skills`.

**Email is the link.** An email match *proposes* a link between a Workiz team
member and an EzBizi user; a human confirms it. Unconfirmed members stay a name
string rather than being guessed at.

This is what turns `CreatedBy` — currently a bare name like "Trey Brannan" — into
a real EzBizi user on an imported job, which is what per-person reporting and
automations need.

---

## 8 · Automations

New triggers, so A-1 can automate on real job movement:

    workiz.job.imported
    workiz.job.status_changed
    workiz.client.imported
    workiz.lead.imported

`workiz.job.status_changed` is the valuable one — it is the event A-1 currently
has no way to react to at all.

Per `docs/SESSIONS.md`: a change to `ModuleServiceProvider` runs
`tests/Feature/Automations` as part of its gate, whatever module it is for, and
a trigger needs an `emits:` declaration. Registering with the Automations
session, not around them.

---

## 9 · Permissions

- A `workiz.*` grant for connecting an account and running a sync. Connect today
  is `reports.build`, which is the Experimental report's key and the wrong name
  for this once Workiz feeds Customers and Jobs.
- Imported `customers` and `jobs` rows obey the **existing** customer and job
  permissions. They are ordinary rows in ordinary tables; that is the point of
  the projection.
- Whoever holds the tab must not thereby gain the capability — the rule Reports
  pinned as `test_the_tab_alone_does_not_open_the_pane_without_the_capability`.

---

## 10 · Not in scope

TimeOff · mirror tables as a permanent home · auto-merging customers · creating
jobs in Workiz from EzBizi · Workiz UI scraping, which `docs/EZBIZI-STATUS.md`
records as a permanent boundary rather than a backlog item.

---

## 11 · Sessions

| Session | What they own here |
|---|---|
| Customers | `source` / `remote_id` columns, the projection into `customers`, duplicate-flagging if it is theirs |
| Jobs | the projection into `jobs`, the imported status list |
| Automations | the four triggers |
| Scheduling | the zip→service-area map, reading the landing table |
| Google API / Leads | the Workiz lead reader, as a new source in their module |

---

## 12 · Verification

- `--filter=Workiz` is **not** the regression command. Two whole files:
  `tests/Feature/Reports/WorkizConnectTest.php` and
  `ExperimentalCallReportApiTest.php` — 23 passed, 71 assertions at `5dbdffeb`.
  The name filter misses `test_a_branch_with_a_full_page_is_asked_for_the_next_one`,
  the only test of the paging loop.
- Fixtures here are invented, and inventing them is how the first `WorkizJobs`
  draft asked for `records=1000` — refused by the live API as invalid paging,
  caught by nothing. **Import tests are built from real captured payloads** out
  of `a1tools_workiz_jobs`, not from hand-written JSON.
