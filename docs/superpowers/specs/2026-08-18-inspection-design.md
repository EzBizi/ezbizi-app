# Inspection module — design

**Date:** 2026-08-18  
**Status:** approved in conversation; four gaps from `docs/agents/inbox/0001` closed in this file 2026-08-18 (erasure vs freeze, wall-time freeze, missing-required sentence, unpublish/grants vs open runs). Implementation plan not written yet.  
**Product:** EzBizi customer app (`app.ezbizi.com`)  
**Layouts:** Builder = list + work + live phone; Generated Reports = search + list + report; New Inspection = search first (not a layout picker)

This is a new module. It does not copy A1 Tools code. A1 Tools may be read for what a field business needs; the shape here is EzBizi’s: registries, tab permissions, contracts-style versions, FileStore, automations declared on day one.

---

## 1. What it is

Companies design the inspections they run, fill them in the field, and keep the finished reports.

Three chip tabs, same strip as Human Resources (no page title repeating “Inspection” above the chips — the sidebar already said that):

| Tab | What it is |
|---|---|
| **New Inspection** | Start and fill. Same for every person who can use it. |
| **Generated Reports** | Finished reports only. Read, search, archive. |
| **Builder** | Create and publish the named inspections. |

The **Tool** is that New Inspection surface — singular. Inside Builder, a company may have **several named inspections** (Level 1, dryer vent, …). Those are not called tools. The picker after search lists the named inspections that role is allowed to start.

---

## 2. What it is not

- Not a page builder (no per-inspection colours, no arbitrary blocks). Look is a **layout preset** plus optional name, cover, and intro.
- Not a second Customers or Jobs module. Search that finds nobody says **Client not found** or **Job not found**. Technicians do not create those records here.
- Not a filing cabinet onto a job. Finishing a run **only** creates a Generated Report. Attach to job, email, follow-up work — **Automations**. Builder has no “submit to.”
- Not a shared draft. Rodrigo’s in-progress work is invisible to Carlos. Carlos’s New Inspection is the same search everyone gets.

---

## 3. Layouts (locked)

### Builder

Left: list of named inspections, then the screens of the selected one.  
Middle: the selected field (type, required, photo-if, bind, lock-after-prefill, choice one-or-several).  
Right: live phone preview of that screen under the chosen preset.

At 375px the three panes **stack**. No extra tab, no modal.

### New Inspection

Not a layout we pick. The **named inspection’s preset** is how the run looks.

Home is a **search** (client name or job number). After a hit: **picker** of named inspections this role may start. Click starts it (or the resume / start-again choice if *this person* already has a draft of that inspection on that client/job).

### Generated Reports

Search (same keys: client name or job number). Hits on the left, the filled report on the right. The report body is the preset that inspection published — read-only. This tab does not restyle it.

---

## 4. Layout presets (per named inspection)

In code, not tenant-invented. v1:

| Key | What the technician sees |
|---|---|
| `step_strip` | One numbered screen at a time. **Default** on create. |
| `document` | Screens as headings, fields as lines (checklist). |
| `quiet_stack` | One scrolling column of fields. |

New Inspection and Generated Reports **render the preset the version was published with**. Changing the preset is a new version.

Optional on the named inspection, all set in Builder, all allowed empty except that **publish requires a name** (so the picker is not a blank row):

- **Name**
- **Cover image** (FileStore, same path as contract covers)
- **Short intro** shown at the start of a run

Org colours stay in `client/lib/core/theme.dart`. No purple. No per-inspection palette.

---

## 5. New Inspection flow

1. Open the tab → search.
2. Type a **client name** or a **job number** (the job’s `number`, e.g. J-1042 — that is what people mean by job id).
3. Pick a hit. Search only returns customers and jobs this person **reaches** (§4 assignment). Customers are org-shared; jobs are franchise-scoped — existing reach rules, not a new one.
4. Picker: named inspections that are **published** and **granted** to this role (see §9). Unpublished / ungranted do not appear.
5. Click one:
   - No draft of **yours** for this inspection + this customer/job → start a run. Raise `inspection.started`.
   - You already have an in-progress run of that pair → a **screen or right panel** (not a modal): **resume**, or **start again** (discards *your* draft, raises `inspection.discarded`, then starts). A **completed** report on the same pair is left alone; this is a second inspection.

Empty search: **Job not found** when the query looks like a job number (the organization’s number pattern, e.g. `J-1042`); otherwise **Client not found**. No create controls.

Leaving a run saves as they go. Coming back through this flow is how they resume. There is no org-wide in-progress list.

Carlos never sees Rodrigo’s draft, cannot resume it, cannot discard it. Two people may each submit a report on the same job; those are two reports.

---

## 6. Filling a run

**Step strip:** screens in order. **Next** stays blocked until every **required** field on this screen is answered (including a photo that is required because another field has a given answer). **Back** is allowed. **Submit** is on the last screen.

When Next or Submit is blocked, the first missing required field is **named in a sentence under the control** (“Crown needs an answer before you can continue”) and that field is **scrolled into view and focused**. A long document preset must not fail as a button that does nothing.

**Document** and **quiet stack:** more than one screen can be on screen at once. **Submit** still waits until every required field in the whole inspection is filled. The same sentence-and-focus rule applies.

Prefill: a field may **wire** to one entry in the bindable catalogue (§8). Builder marks each wired field **locked** or **editable**. Editing never writes the customer or job. Empty source (customer-only run, job field wired) → the field is empty; locked empty is shown empty, not invented.

Submit creates a **Generated Report** and removes the in-progress run. Answers, photos, and signatures freeze. Raise `inspection.submitted`. The payload carries the report, the customer, and the job when there is one — so a rule can update the job or email later. Inspection itself does not attach, file, or send.

---

## 7. Generated Reports

- Only **submitted** work.
- Search by client name or job number.
- **Archived** reports are hidden until the Archived filter is on. Archive does not delete. Raise `inspection.archived`.
- No delete of reports. `delete` on the module is **discard own in-progress** only.
- Opening a report shows the frozen answers in that version’s preset, plus facts that are always stamped (not Builder fields): inspection **name**, **customer**, **job** if any, **who submitted**, **when submitted**. Do not show class names, version numbers, or the word “immutable.” A version pin in the database is how we reopen it; the person sees the name and the submit time.

A correction is a **new run**. The old report stays.

---

## 8. Builder

Same contracts idea: a named inspection is a pointer; **publish** writes a new **written-once version** and points the name at it.

- **In-progress** runs keep the version they started on.
- **New** runs get the current published version.
- **Reports** stay on the version they were submitted with.

**Unpublish** (retire from the picker): leaves the picker. **New starts** are blocked. An **already-open in-progress run may still be submitted** — the work is done and the report is the evidence. Resume still works for the owner. **Start again** is a new start, so it is blocked once unpublished. Old reports still open. Same idea as retiring a contract template from use without erasing history.

### Field types (in code, Inspection’s catalogue — do not add `heading` / `photo` to HR `FieldType`)

| Type | Notes |
|---|---|
| `text` | |
| `number` | |
| `date` | |
| `choice` | Options list. Builder sets **one answer** or **several** per field. |
| `file` | Generic upload via FileStore. |
| `photo` | Camera if the browser allows, otherwise pick a file. Builder sets **max count** (default 1). Optional / required / **required when another field on the same screen has a given answer** (“at least one” when the condition holds). |
| `signature` | One type. A report **may** have two (technician and client) as **two fields** with different labels. Not hardcoded on every inspection. Screen **names** are the headings on the document preset. Intro copy is the optional Builder intro, not a field type. |

### Bindable sources

Closed list **published by Customers and Jobs**, consumed by Builder. Inspection does not invent customer columns.

v1 keys (extend the registry when those modules grow — not a one-off in Inspection):

**Customer:** `name`, `email`, `phone`, `number`, `address_line1`, `address_line2`, `city`, `state`, `postal_code`.

**Job:** `number`, `title`, `scheduled_for`, `status`, `description` — and, when a job is selected, the customer keys via that job’s customer (same catalogue, same labels).

`scheduled_for` is franchise wall time (`FranchiseWallTime` since `4f214e3`). A bound value is copied onto the run as that wall clock, and at submit it is **frozen onto the report as already-rendered text** in the job’s franchise timezone. Do not store an instant and re-render on read — correcting a franchise timezone would silently rewrite every old report’s time.

A customer-only start leaves job keys empty.

### Screens

Ordered list of screens, each an ordered list of fields. Builder can add/reorder screens and fields in the list (no drag-from-palette required in v1; add + move up/down is enough).

---

## 9. Permissions (organization, not hardcoded roles)

§4: release flag → entitlement → permission → assignment. The tab is `inspection.view`. Nothing is on for everybody. Administrator remains the immutable role that cannot lose the module.

**Declared actions** (schema in `ModuleServiceProvider`, ticked on Roles):

| Action | What it opens |
|---|---|
| `view` | The Inspection tab. Generated Reports. |
| `create` | New Inspection (search, start, fill, submit). |
| `edit` | Builder. |
| `delete` | Discard **your** in-progress run (including “start again”). |
| `archive` | Archive / unarchive a submitted report. |

Chips the person cannot use are **not drawn** (do not offer a control that 403s).

**Which named inspections a role may start** is not a compiled action per Level 1. It is a **grant**, same idea as `MailboxGrant`: tenant rows, edited under Inspection on the Roles screen. `create` without any grants → picker empty, with a sentence that the organization has not offered any inspections to this role. Builder (`edit`) can still create kinds that nobody can run yet — same as connecting an inbox before granting it.

Reach still applies: grants do not leak jobs above the person’s assignment.

A **revoked grant** is the same as unpublish for that role: **block new starts**, let an already-open draft **submit**. The picker no longer offers it. Start again is a new start, so it is refused.

---

## 10. Automations (§5.5)

Declare on day one. Raise when the code actually does the thing (`isRaised: true` only once it emits — follow `ModuleRegistryTest`).

**Triggers** (wording is what the catalogue shows):

| Key | Label | When |
|---|---|---|
| `inspection.started` | Inspection started | A run begins. |
| `inspection.submitted` | Inspection submitted | A report exists. The useful one to wire. |
| `inspection.archived` | Report archived | Hidden unless the Archived filter is on. |
| `inspection.discarded` | In-progress discarded | Own draft thrown away. |

No `screen_completed`. No “emailed” until mail actually sends.

**Subject:** none (`null`), for the same reason HR is null — events are about a **run** and a **report**. A rule that needs to change a job or customer uses **those** modules’ actions.

**Automation actions on Inspection:** none. Reason, stated on the module: a submitted report does not change; extra work is a rule on `inspection.submitted` that updates the job, the customer, or notifies. Do not invent `inspection.update_field`.

---

## 11. Data

Approximate tables (names can match Laravel conventions; the split is the decision):

**`inspection_definitions`** — named inspection: organization, name, cover path, intro, `current_version_id`, published/unpublished, created_by.

**`inspection_definition_versions`** — written once (`WrittenOnce`): layout preset, screens+fields JSON, published_at, published_by. Cover bytes copied onto the version if present (same provenance idea as contract covers).

**`inspection_runs`** — in-progress only. `user_id` (who started — the only person who can see/resume/discard it), `customer_id`, `job_id` nullable, definition + **version** pin, answers JSON, photo/file/signature attachments via FileStore. One draft per person per inspection per customer per job, enforced by a unique index on `(user_id, definition_id, scope_key)` where `scope_key` is `customer:{id}|job:{id}` or `customer:{id}|job:none`. Do not unique on nullable `job_id` — MySQL allows several NULLs and that would permit two customer-only drafts.

**`inspection_reports`** — submitted, frozen. Copy of answers and version pin, customer, job, submitted_by, submitted_at, `archived_at` nullable. Two write exceptions only: **`archived_at`**, and **§9 erasure** (anonymise in place). Erasure is the second explicit hole in the write-once guard — without it the first purge request fails because the guard refuses the row. Erasure blanks or replaces personal columns (names, signatures, photos, stamped customer facts) per `HoldsPersonalData`; it is not a correction of the inspection. Retention: keep the row for the life of the organization (evidence), same argument as `ContractVersion`.

Signatures store enough to show they were signed (image + signer label + time). Technician field does not secretly overwrite the label with another user; it is whatever they signed on that field.

Photos/files: `FileStore`, never `Storage::disk()` in a controller.

---

## 12. Client

- Register `inspection` in `ModuleServiceProvider` **and** `client/lib/core/module_screens.dart` (or the tab never appears — `ClientModuleScreensTest`).
- `InspectionScreen` with chip tabs (`tab_bar_like.dart`), lazy fetch like HR (a tab not opened does not fetch).
- Empty states are sentences: no published inspections, picker empty for this role, search miss, no reports, no reports unless Archived.
- Touch targets 44px. Works at 375px.
- No modals. Resume / start again is a panel or a full screen.
- Product copy: what happened and what to do. No class names, no `::`, no “immutable.”

---

## 13. Testing and seed data

TDD. PHP feature tests on a MySQL database whose name contains `testing` (`tests/TestCase.php`). Confirm `php artisan test` actually talks to it in the implementing session before treating it as the gate — do not assume from an older STATUS line.

Client widget tests for the three tabs, search miss copy, picker grants, resume-vs-start-again for the **same** user, invisibility of another user’s draft, frozen report, archive filter, missing-required sentence, submit-after-unpublish.

**Seed:** an artisan command that takes a **named organization**, e.g. `inspection:seed-demo {organization}` — 10 fake customers and 10 jobs via factories, no real people’s emails. Default is **not** the live org. Prefer `ezbizi_testing`. Do not run it against production while TODO #9 (demo-org suppression) is still open: the fakes would show up in dashboards and roll-ups. Tests must not depend on the seed.

---

## 14. Out of v1

- Emailing or PDF of a report (automation + mail, later).
- Creating customers/jobs from Inspection.
- Per-inspection colours or a freeform page builder.
- Per-screen automation events.
- Editing or deleting submitted answers.
- Seeing or taking over another person’s in-progress run.
- Offline-first.
- Starting a run from the Jobs screen (can be a later automation or a Jobs control; not required to ship this module).

---

## 15. Success

An organization can publish two named inspections with different presets, grant only one to a role, have a technician search a job, fill screens in order with a photo required on Fail, submit, and find that report under Generated Reports. A second technician on the same job never sees the first draft. A rule on `inspection.submitted` can update the job. Changing the Builder does not rewrite the report.
