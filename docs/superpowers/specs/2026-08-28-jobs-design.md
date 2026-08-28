# Jobs — design

**Date:** 2026-08-28
**Status:** designed, not built
**Product:** EzBizi customer app (`app.ezbizi.com`)
**Supersedes:** nothing. Extends the §18 Phase-2 vertical slice finished 2026-08-19.

The work order. What was sold, when it happens, whose trade it is, who did it, what
came off the truck, and the photographs the argument will be about.

Not an estimate. Not an invoice. Not dispatch optimisation, not routing, not payroll.

---

## 1. Where this starts from

Jobs has been the vertical slice since Phase 2 and has not grown since. It is a list,
a right-side panel, and a price somebody types. Everything below is either a gap the
slice was never asked to fill, or wiring that already exists and no screen shows.

Already true and reused rather than rebuilt:

| Already there | Where |
|---|---|
| Subtree reach scoping | `JobsController::index` via `ViewScope::forUser` |
| Tenant-scoped `exists` (no id oracle) | `JobsController::existsInTenant` |
| Statuses as tenant data, not an enum | `Job::allowedStatuses`, renameable in Settings |
| Wall time in the franchise timezone | `Job::$casts` → `FranchiseWallTime` |
| Attachments on a job | `Job` uses `HasAttachments` — **never shown on a screen** |
| Materials issued against a job | `InventoryIssue.job_id` — **never shown on a screen** |
| Inspections bound to a job | `InspectionRun.job_id`, `InspectionReport.job_id` — **never shown** |
| Five automation triggers | `ModuleServiceProvider` jobs block |
| Earned-status revenue setting | `DashboardController` `earned_statuses` on the jobs glance |

Three of those are surfacing work, not building work. That is the cheap half of this spec.

---

## 2. What it is not

- **Not an invoice.** No tax, no payments, no balance due, no document to send. A job
  total is what the work came to, not a bill.
- **Not an estimate.** Nothing is quoted and accepted. A job exists because the work
  is happening.
- **Not payroll or a timesheet.** Clock-in already owns hours worked and is per user,
  not per job. This module does not tie the two.
- **Not a route planner.** The schedule view shows a week; it does not order a day or
  optimise travel.
- **Not margin reporting.** See §8.

---

## 3. Settled

| # | Decision | Why it is not the other thing |
|---|---|---|
| 1 | A job is a **work order only** — no estimate, no invoice. | Those are their own modules if they are ever wanted. A job that also bills is two records wearing one name. |
| 2 | A job names **exactly one role**, and the organization defines its roles. | Work needing two trades is two jobs against one customer. One job in one row keeps the schedule honest and keeps "is this mine?" a single comparison. |
| 3 | A job records **exactly one user** as having done it. | Accountability needs a name, not a roster. A crew table can arrive later if somebody asks; nothing here forecloses it. |
| 4 | The **role is not a permission**. Naming a role on a job grants nothing and denies nothing. | Roles already carry permissions. A job naming a role says whose trade this is, not who may open it — reach still decides that. |
| 5 | Service lines come from the **Services catalogue**, with the name and price **snapshotted onto the line**. | The catalogue changes. A price edit in September must not restyle a job done in August — the same reason Inspection freezes report versions. |
| 6 | **Job total is derived** from service lines. `amount_minor` stops being typed. | The `Revenue` widget already reports the typed number to people as earnings. It is currently reporting whatever somebody keyed. |
| 7 | **Materials are a consumption list with no money on them.** Item and quantity, never cost, never price. | The customer is billed for the service; "cap replacement" includes the cap. Inventory has no sale price to read (see §7), and cost belongs to `inventory.financials`, not here. |
| 8 | **No `jobs.financials` permission.** `jobs.view` carries price. | Jobs is the module where money is the point of the record, not an attribute of it. An unused permission is a grantable lie on every organization's Roles screen. It arrives with margin or not at all. |
| 9 | **Cost never enters a job.** Not on a line, not in a total, not in a tooltip. | Then there is no aggregate to gate and no arithmetic bridge to close. This is modelling, not a permission check — see §8. |
| 10 | **Two views on one tab**: List and Schedule. Not two sidebar destinations. | Jobs is one thing looked at two ways. Settings already proved chips-inside-one-destination (v0.0.0080). |
| 11 | A completed job **does not freeze**. | A job is corrected after the fact for good reasons, and `logChange` already records who changed what. An inspection report freezes because it is a signed document; a job is not one. See §11 for what this depends on. |
| 12 | Revenue is bucketed on **`earned_at`**, not `created_at`, and the pre-existing bug is fixed in this build. | Without it, an editable total plus creation-date bucketing lets an October edit rewrite June. §11 and §8.1 hold each other up — neither survives alone. |

---

## 4. Hierarchy and reach

Unchanged from the slice, and the slice was right. Restated because a new writer will
need it:

- A job belongs to exactly **one franchise**. Never shared, never co-owned (plan §5.2).
- The list is scoped to `ViewScope::forUser(...)->franchiseIds()` — a **subtree**, so a
  company-level user sees every franchise beneath them, and `whereIn` on an empty list
  correctly matches nothing rather than everything.
- **Filing follows the view, not reach.** A new job files against the franchise in
  `franchisesInView` / `filingCompanyId`, never a `me.franchises` loop or `.first`.
  Somebody standing in A-1 Chimney cannot book on Everdoors. Copy Inventory, Fleet and
  Phone — they are the worked examples (AGENTS.md:46).
- Roles are **organization-global**. A role named on a job is an organization's role,
  so `assigned_role_id` validates against `roles` scoped to the tenant, not to the
  franchise.

---

## 5. The job record

`jobs` gains two columns and keeps everything it has.

| Column | Type | Notes |
|---|---|---|
| `assigned_role_id` | uuid, nullable, FK → `roles` | Whose trade this is. Nullable: a job may be booked before anyone decides. Org-paired FK, same as the existing `jobs_customer_same_org`. |
| `performed_by_user_id` | uuid, nullable, FK → `users` | Who actually did it. Nullable until the work happens. |
| `earned_at` | timestamp, nullable | When the job first entered an earned status. Set by the same transition that fires `job.completed`. This is the column revenue periods are keyed on — see §8.1. |

`amount_minor` **stays on the table** and stops being written by hand. It holds the
derived total so that list queries, sorting and the `Revenue` widget do not each
re-sum lines. It is recomputed inside the same transaction as any line change.

Deleting a role is refused while a job still names it, the same way Services refuses to
delete a group a service still names. Deleting a user is already governed by §9 erasure;
`performed_by_user_id` nulls rather than blocking, and the audit trail retains the act.

### §9 retention

- `assigned_role_id` holds **no personal data** — a role is not somebody's name. Same
  reasoning as `InspectionDefinitionGrant`.
- `performed_by_user_id` **is** personal data and needs a `StatesRetention` sentence.

> **Corrected 2026-08-28, during implementation.** The first version of this bullet said the
> key is "nulled on erasure while the job survives". That is **wrong**, and it was wrong
> about how this codebase works rather than merely imprecise. §9 erasure never deletes a
> `User` row — `User::retention()` (`app/Models/User.php:60-68`) says erasing a person
> "means anonymising it rather than removing the row", and `Anonymiser` is explicit that it
> anonymises in place. So the migration's `nullOnDelete()` never fires under erasure, and a
> policy promising nulling would be a false statement in the repo's own compliance test —
> exactly what stating a policy here was meant to prevent.
>
> The true sentence: the key is **kept**. The person's identity is erased at its one home,
> `users`, and `jobs.performed_by_user_id` then points at a record with no name in it. The
> existing correct precedent is `Attachment::personalDataHeld()`'s `uploaded_by_user_id`,
> which uses `Erasure::keep(...)` for this exact situation — copy its conclusion, not just
> its shape.
>
> Anyone writing a retention policy in this project should check what erasure ACTUALLY does
> to the referenced table before describing what happens to a foreign key. A plausible
> guess produces a policy that is confidently false.

---

## 6. Role and person on screen

Two fields, two different questions, never merged:

- **Assigned role** — "this is Chimney Technician work." A selector of the
  organization's roles. Filterable on the list, and the row axis of the schedule view.
- **Done by** — "Rafael ran it." A selector of users who can reach that franchise.

Naming a role does **not** narrow who may open the job. Reach decides that, as it does
today. A job assigned to Inspector is fully visible to a Chimney Technician standing in
the same franchise — the field is information, not a lock. Stating this because the
obvious reading of "assigned to a role" is that it gates, and it does not.

---

## 7. Service lines and the total

New table `job_lines`. Service lines only.

| Column | Notes |
|---|---|
| `organization_id`, `job_id` | Tenant + parent |
| `service_id` | nullable FK → `services`. Nulls if the service is later deleted; the line survives. |
| `name` | **Snapshot** of the service name at the moment it was added |
| `unit_price_minor` | **Snapshot** of the catalogue price at that moment |
| `qty` | unsigned int, default 1 |
| `amount_minor` | `unit_price_minor * qty`, stored |
| `position` | ordering |

**Why snapshot.** A catalogue price is a current fact. A job is a historical one. If a
line read live from `services`, raising a price in September would rewrite August's
jobs and the `Revenue` widget would report a past month differently each time it was
opened. Inspection already settled this shape for report versions; this is the same
decision in a second place.

**The total.** `jobs.amount_minor` = Σ `job_lines.amount_minor`, recomputed in the same
transaction as any line insert, update or delete.

**Migration and the existing typed price.** Every job with a non-null `amount_minor`
gets one `job_lines` row: `service_id` null, `name` = "Job total (entered before
itemisation)", `unit_price_minor` = the old amount, `qty` 1. No job loses its number,
no total changes, and the legacy value is visible as what it is rather than silently
becoming a line item pretending to be a service.

---

## 8. Money: what is here and what is deliberately absent

**On a job:** price only. Service price, and the total those sum to. Visible with
`jobs.view`.

**Not on a job:** cost. Not per material, not as a subtotal, not as a hidden field
stripped at presentation. It is not in the payload.

**Why this is modelling and not a permission.** `inventory.financials` gates what the
business **paid**. A job total is what the customer **owes**. They are different numbers
about the same material, and a job total built from cost would be wrong even for
somebody who holds every permission — it would bill at cost. Once material cost is
simply not part of the job, the aggregate problem disappears rather than being defended
against: there is no total that encodes a hidden figure, so no amount of subtracting two
visible jobs isolates one.

**Margin** is the only figure that legitimately needs both, and it is not here. When it
is wanted it is a separate view behind a financials gate, shipped with that feature.

> **For whoever builds margin later:** the aggregate rule binds the margin *view* as
> well as the margin *row*. A margin total over jobs is as much an answer about costs
> the viewer cannot see as a single margin figure is. Do not add "sum this column" to a
> margin list — that is the obvious thing to do and it is wrong. This warning belongs in
> the code next to the margin query, not only in this document.

**The `Revenue` widget** (`app/Support/Dashboard/Widgets/Revenue.php`, owned by this
module) sums price and never cost.

Its **scoping is already correct and needs no work**: `ScopesToReach::reachable()` at
`app/Support/Dashboard/Widgets/ScopesToReach.php:37` routes through
`ViewScope::forUser($user)->franchiseIds()` — the same rule the jobs list uses, held once
in a shared trait rather than copied. An aggregate is an answer about rows, and this one
already answers only about rows the viewer could open. Leave it that way; do not inline it.

Its **period arithmetic is wrong**, and that is §8.1.

---

## 8.1 The Revenue period defect — pre-existing, and this design makes it worse

`Revenue.php:80-81` reads:

```php
->whereIn('status', $statuses)          // the organization's earned statuses
->where('created_at', '>=', $since)     // bucketed on CREATION
```

Two different clocks. It filters by the job's **current** status and buckets by the job's
**creation** date. Both halves are wrong:

- A job booked in June and completed in September is counted in **June's** revenue, or
  falls out of September's entirely depending on the window.
- The status test is evaluated at read time, so **re-running the same widget over the
  same past period returns different answers as statuses change.** A closed month is not
  closed.

This is a defect today, before any of this build. But it is exactly what makes §11's
no-freeze decision risky: with the total editable after completion **and** the period
keyed on creation, an edit in October silently rewrites June's reported revenue with
nothing on screen saying it moved. That is the same category as the write-once parent
rule — history rewritten so that it looks as though it was always that way.

**The fix is not to freeze the job. It is to bucket on when the money was earned.**

`jobs` gains `earned_at` (timestamp, nullable): the moment the job first entered one of
the organization's earned statuses. §14 already fires `job.completed` on exactly that
transition, so the timestamp costs nothing extra to capture. `Revenue` then sums on
`earned_at`, not `created_at`.

With that:

- June's revenue means jobs **earned** in June, and keeps meaning that.
- A later correction moves the money to the period it actually belongs to, which is the
  honest behaviour, and `logChange` records who moved it.
- Nothing has to freeze, so §11 survives intact.

**Scope: in. Decided by Jose 2026-08-28.** Fixing `created_at` is not strictly part of
building Jobs — it is a pre-existing bug in a file this module happens to own — but it
was taken into this build rather than left, because Jobs would otherwise ship with a
headline number that this design makes wronger, and this is the first time anyone has
opened that file with a reason to understand it.

---

## 8.2 `earned_statuses` is in the wrong place, and §8.1 cannot be built until it moves

Found while planning §8.1, and it invalidates the first draft of it.

**Where the setting actually lives today:** `user.preferences['dashboard.glances']`, per
user, handled at `DashboardController:110` and explicitly documented there as "category 6"
— a personal preference. `SettingRegistry.php:708` defines `jobs.statuses`, the
organization's status *vocabulary*, but **there is no `jobs.earned_statuses` anywhere**.

**Why that makes `earned_at` unbuildable as first written.** `earned_at` is one column on
one row. If which statuses count as earned is a per-user preference, then two people
looking at the same job disagree about whether — and when — it became revenue, and the
column would need a different value per viewer. That is not a schema that exists.

It is also wrong on its own terms, independent of this build: **what counts as company
revenue is a property of the company, not a display option on somebody's dashboard.**
One person reordering their dashboard can currently change what the business calls
earned, for themselves, silently and with no audit. That is the shape §5 of the coherency
rules warns about — a setting offered at a scope that should not control it.

**The fix, and it is small.** Add `jobs.earned_statuses` to `SettingRegistry` beside the
`jobs.statuses` it depends on, resolved through `SettingsResolver` (franchise → owning
company → parent company → organization → default) like every other setting. Then:

- `earned_at` stamps on the first transition into an organization-earned status. One
  value, well defined, the same for every viewer.
- `Revenue` reads the organization's answer, not the reader's.
- The existing per-user dashboard preference is **migrated into the organization setting**
  rather than deleted, and afterwards the dashboard control reads the organization's list.
  Nobody loses a choice they had made; it stops being private to them.

**This is a prerequisite, not an extension.** §8.1 does not ship without it. It is the
one place in this spec where the work grew after the scope decision was taken, so it is
recorded here as its own section rather than folded quietly into §8.1.

`SettingRegistry.php` is a shared file — one definition added, announced before editing,
staged as a partial commit.

---

## 9. Materials

Read-only on the job. `InventoryIssue` already carries `job_id` and already posts stock
movements; this module displays what is there and writes nothing to Inventory's tables.

Shown per issued line: **item, quantity, date, who issued it.** No unit cost, no
extended cost, no total. A material list with money on it would put `inventory.financials`
data on a `jobs.view` screen.

Issuing stock stays Inventory's action. If a convenience "issue against this job" control
is wanted on the Jobs screen later, it calls Inventory's existing endpoint and obeys
Inventory's permissions — it does not grow a second write path. Not in this build.

---

## 10. Photographs and the inspection

Both are surfacing work.

- **Photographs** — **client work only. There is no server side to build.** `Job`
  already uses `HasAttachments`, which keeps files through a soft delete and takes them
  on a force delete, and `jobs` is already registered in the attachables registry
  (`app/Support/Files/Attachables.php:83`). The panel gains a tab that lists and
  uploads, using the established pattern: `Files.pickImage()` + `api.postFile()` to
  `/attachments`.
- **Inspection** — `InspectionReport.job_id` already points here. The panel lists the
  reports bound to this job and opens them. Read-only; Inspection owns those records and
  their frozen state.

---

## 11. Status, and the freeze question

Statuses stay what they are: **tenant data**, named by the organization, renameable in
Settings, with existing jobs renamed along with them.

**A completed job does not freeze.** A job is an operational record that is legitimately
corrected after the fact — a photograph nobody uploaded, a service line nobody added, a
date typed wrong. This is the opposite of an inspection report, which freezes because it
is a rendered document that was signed.

**This decision rests on one line and will break silently if that line changes.**
`JobsController:181` calls `audit->logChange('job.updated', $job)` — `logChange`, which
records before and after, not a plain `log`, which records only that something happened.
No-freeze is only defensible because an edit to a completed job leaves a diff naming who
changed what. Anyone "simplifying" that call to `log` removes the entire basis for this
section and must freeze the job instead. Jobs is on the right side of an audit gap that
other modules are not, so do not assume the equivalent line exists elsewhere.

No-freeze also depends on §8.1: an editable total is only safe if the money is bucketed
on when it was earned rather than when the job was created. The two decisions hold each
other up.

> **Open — the one decision in this spec taken without being asked.** If a job should
> lock once its status counts as earned, say so and it becomes: lines and total
> read-only in an earned status, everything else still editable, with a refusal sentence
> from `Refusal.php`. That is a small change to this spec and a large one to make later.

---

## 12. Screens

One sidebar destination. Two views on a chip row, the way Settings holds Organization,
the cascade and Roles as chips inside one destination.

**Chips:** `List` · `Schedule` — then the status filters, then the role filters.

### 12.1 List view

Today's list, plus a **Role** column and role filters. Search unchanged (number, title,
customer name, customer number) and gains role. Sort unchanged —
`COALESCE(scheduled_for, created_at) DESC`, which exists because a job created without a
date otherwise sorted last under MySQL and the person who just made it watched it fail
to appear.

### 12.2 Schedule view

Rows are the organization's **roles**. Columns are **days**, one week at a time. A card
is a job: title, time, customer. Unscheduled jobs sit in a tray, because a job with no
date is the common case at booking time and hiding it makes the view lie.

- The week is drawn in the **franchise wall time**, consistent with `FranchiseWallTime`.
  Moving a card sets a wall time, never a UTC instant.
- A job with no `assigned_role_id` sits in an **Unassigned** row rather than vanishing.
- 375px: the week collapses to one day with day-to-day paging. Touch and pinch are
  first-class; drag is not the only way to move a job — the panel's date field always
  works, because drag on a phone is a bad sole affordance.

### 12.3 Detail panel

Right-side panel in both views. No modal, ever — including "add a service", which is an
inline row on the Work tab or the panel itself, never `showDialog`.

Tabs: **Work** (role, done by, service lines, total) · **Materials** · **Photos** ·
**Inspection** · **Details** (customer, date, duration, notes, delete).

---

## 13. Permissions

No new keys. The module keeps `jobs.view` / `create` / `edit` / `delete` / `export`.

All four gates in order — release flag → entitlement → permission → assignment. A
permission check alone is not access control, and if the answer would be 403 the button
is not drawn enabled.

- Reading a job, its lines, its total, its materials and its photographs: `jobs.view`.
- Adding, editing or removing a service line: `jobs.edit`. Editing a line edits the job.
- Setting the assigned role or the person who did it: `jobs.edit`.
- Materials are Inventory's to write. Nothing on this screen writes stock.

Refusals carry the sentence from `app/Support/Access/Refusal.php` and the client shows
**that** sentence. No second copy of the wording in Dart.

---

## 14. Automations

Existing five triggers are unchanged: `job.created`, `job.updated`, `job.deleted`,
`job.status_changed`, `job.scheduled`.

Two added:

| Trigger | Fires when |
|---|---|
| `job.assigned` | `assigned_role_id` is set, changed or cleared. Carries old and new, as `job.status_changed` does — the value alone cannot say it just changed. |
| `job.completed` | The status moves **into** one of the organization's earned statuses. Distinct from `job.status_changed` because "when the work is done" is the rule people actually write, and making them hand-pick statuses in every rule duplicates a setting the organization already made. |

**`earned_statuses` is load-bearing twice over, and that is deliberate.** It defines
*which* jobs count as revenue and — through the transition this trigger fires on, stamped
as `earned_at` — *when* they count (§8.1). The transition timestamp is not a
nice-to-have that can be dropped to save a column; the reporting period depends on it.

Line changes raise `job.updated` and nothing more specific. A per-line trigger would fire
three times for one edit and no useful rule hangs off it.

**Every write path inserts an outbox row inside the same transaction as the record and
its audit row.** Nothing dispatches inline. `ezbizi:dispatch-automation-events` drains
it. That is why a failing rule cannot fail the request that triggered it.

If a trigger cannot be raised by ship time it is declared with `isRaised: false`, which
appends "Nothing raises this yet, so a rule built on it will not run." in the builder.
Declared-and-flagged, never hidden — the registry validates the key either way, so a
hidden trigger lets a tenant save a rule that then waits forever with no warning.

---

## 15. Refusals — sentences, from the server

| Situation | Sentence |
|---|---|
| Naming a role from another organization | "That role is not one of yours." |
| Naming a service from outside the view | "That service is not available where you are filing." |
| Person who cannot reach the franchise as "done by" | "That person is not assigned anywhere this job can be seen." |
| Deleting a role a job still names | "This role is named on jobs and cannot be deleted." |
| Line quantity of zero or less | "A line needs a quantity of at least one." |
| Booking against a franchise outside the current view | "You are filing on {franchise}, so this job cannot be booked on {other}." |

---

## 16. Dates and money

- **Money:** integer minor units throughout, `USD` default, no FX. `amount_minor` is
  cast to integer so a JSON string `'1999'` cannot silently concatenate.
- **Dates:** `scheduled_for` is wall time in the franchise timezone (`FranchiseWallTime`),
  already true and unchanged. A correction to a franchise timezone must not move a job
  that already happened.

---

## 17. Tests (MySQL, `ezbizi_testing_jobs`)

Never against the shared `ezbizi_testing` schema — `RefreshDatabase` drops it and two
sessions testing at once destroy each other silently. Real env var wins over
`phpunit.xml`:

```
DB_DATABASE=ezbizi_testing_jobs vendor/bin/phpunit --filter=Job
```

Each of these must be broken on purpose once before it is believed:

1. **Total is derived.** Add two lines, assert the job total. Change one line's qty,
   assert it moved. Delete a line, assert it moved back.
2. **Snapshot holds.** Add a service line, change the catalogue price, reopen the job —
   the line still reads the old price. This is the test that pins §7.
3. **Cost is absent.** Issue inventory against a job, then assert the job payload
   contains no cost field at any nesting level, for a caller holding
   `inventory.financials` *and* for one without. Absence is the assertion.
4. **Revenue borrows the list's scope.** A job in a franchise the viewer cannot reach is
   excluded from the widget total, not only from the list. (Already true via
   `ScopesToReach` — this test pins it so an inlined query cannot quietly replace it.)
4b. **A closed month stays closed.** Complete a job in an earned period, read the widget,
   then change something about the job in a later period and read the same past window
   again. The earlier figure must be unchanged.
   This is the test the whole of §8.1 exists for; write it first and watch it fail
   against `created_at` before writing the fix.
5. **Migration backfill.** A job with a typed `amount_minor` and no lines keeps its exact
   total after the migration and shows one legacy line.
6. **Role is not a gate.** A user whose role differs from the job's `assigned_role_id`
   can still open the job in the same franchise.
7. **Cross-organization role is refused** with the §15 sentence, not a 404 and not a 500.
8. **View, not reach** (Flutter widget test). A sibling company genuinely present in the
   `/me` payload and still `findsNothing` — and **the organization renamed**, because the
   default `_me` fixture also calls the org "Everdoors" and an unrenamed assertion passes
   against the org while proving nothing. Shape: `client/test/inventory_screen_test.dart:496`.
9. **Schedule view at 375px** renders one day and can page, and a job can be moved
   without drag.

Assert on values, never on the key order of anything read back from MySQL. Read the
summary line's clauses — this repo has recorded a run that exited 0 while printing 3
failures and 17 errors.

---

## 18. Implementation order

Each step is a commit that leaves the app working.

1. Migration: two columns on `jobs`, `job_lines` table, backfill of the typed price.
2. Model + policy: relations, retention sentences, `assigned_role_id` validation scoped
   to the tenant.
3. API: lines CRUD nested under the job, total recomputation in-transaction, role and
   person on the payload. Wire shape changes here — flag it to whoever owns deploys.
4. Automations: two new triggers, outbox rows, `ModuleServiceProvider` jobs block.
5. Client: List view gains the role column and filters; panel gains Work tab and lines.
6. Client: Materials, Photos and Inspection tabs — the three surfacing jobs.
7. Client: Schedule view.
8. `Revenue` widget onto the derived total. **Its scoping is already correct via
   `ScopesToReach` — do not touch it.**
9. `earned_at` column, stamped by the same transition that fires `job.completed`, and
   `Revenue` bucketed on it instead of `created_at`.
   Historical rows backfill `earned_at = updated_at` where the job is already in an
   earned status — approximate for the past, exact from the migration forward. The
   approximation is stated in the migration rather than hidden, because a backfilled
   June figure that shifts once is better than one that shifts every time it is read.

Steps 1–4 ship without any client change and break nothing: the extra columns are
nullable and the derived total equals the typed one until somebody edits a line.

---

## 19. What this deliberately leaves

- **No invoice, no estimate, no payments.** Their own modules, if ever.
- **No margin.** §8 says where it goes when it comes.
- **No crew.** One person per job; a join table can arrive without disturbing anything here.
- **No route or day optimisation.** The schedule shows a week; it does not plan one.
- **No material pricing.** Settled in §7 and §9 — the service carries the money.
- **No clock-in tie.** Hours are per user and already owned elsewhere.
- **No convenience issuing from the Jobs screen.** §9.

---

## 20. Shared files this touches

One owner per file. These are not mine and are announced before editing:

| File | Edit |
|---|---|
| `routes/api.php` | Nested line routes under `jobs`. **Stage HEAD plus my lines only** via `hash-object` / `update-index` — it currently carries several sessions' uncommitted work with untracked controllers behind it. Never `git add` the whole file. |
| `app/Providers/ModuleServiceProvider.php` | The jobs block (~line 819) only, for two triggers. Same staging rule. |
| `app/Support/Dashboard/GlanceRegistry.php` | Narrow edit if the glance changes shape. Announced first. |

Mine outright: `app/Models/Job.php`, `app/Http/Controllers/Api/JobsController.php`,
`app/Policies/JobPolicy.php`, `app/Support/Dashboard/Glances/JobsGlance.php`,
`app/Support/Dashboard/Widgets/Revenue.php`, `JobsByStatus.php`, `UpcomingJobs.php`,
`database/factories/JobFactory.php`, `tests/Feature/Api/Job*.php`,
`client/lib/screens/jobs_screen.dart`, `client/lib/screens/jobs/`,
`client/lib/core/job_status.dart`, and the Jobs tests under `client/test/`.
