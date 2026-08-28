# Inspection engine — design

*2026-08-28. Owner: the Inspection session. Supersedes nothing; extends
`2026-08-18-inspection-design.md`, which stands.*

The A1 Tools inspection is to be rebuilt inside EzBizi's Inspection module, on
our structure and our rules, and **authored entirely through the Builder** — no
part of it hardcoded. This document is the design for the engine that makes that
possible, and for the port that follows it.

---

## 1 · What is being ported, and what it actually is

`C:\Users\jose\Desktop\Old Inspection Tool` holds two copies of one Next.js
application. `safe-chimney-technicians-main` is the newer (browser extension,
DB backfills); `inspectplus-reference-main` differs only in Workiz account
handling. Read the first; the second answers nothing the first does not.

**It is a Workiz satellite.** Jobs sync in from Workiz, the priced item
catalogue syncs in from Workiz, and submitting an inspection creates a Workiz
*estimate* — items, attachments, signatures — then renders a PDF and notifies
the customer by email and SMS. None of that plumbing ports. EzBizi already owns
customers, jobs, a priced Services catalogue and job lines; the Workiz half is
replaced by what we have rather than reproduced.

**The inspection itself is 2,818 lines of hardcoded TypeScript**
(`src/app/inspect/[jobID]/form/_components/config.tsx`). Changing a single
question is a code change and a deploy. That is the property we are inverting:
the same inspection, as ordinary tenant data, editable in the Builder.

### Its shape

Four steps, roughly 120 fields:

| Step | What it holds |
|---|---|
| `customer` | Names, two emails, two phones, address, exterior photo, inspection date, time slot, level, reason |
| `inspection` | Branches hard on **system type** — built-in gas, electric, furnace, masonry, wood stove — each a long independent branch |
| `inspection_level_2` | Exterior: height from roof line, cricket, flashing, siding, chase cover, exterior pipe, masonry work, crown, rain cap, spark arrestor |
| `signature` | Note, customer-on-site, customer signature + acceptance, technician signature |

Field types used, by frequency: `radio-group` (35), `number` (13), `divider`
(13), `text` (10), `checkbox-group` (6), `textarea` (5), `select` (5), `image`
(5), `alert` (5), `group` (4), `tel` (2), `signature` (2), `email` (2), and one
each of `time-slot`, `spacer`, `invoice-items`, `date`, `checkbox`.

### The one pattern worth stealing wholesale

`createOkNotOk` in `form-generator/utils.ts` generates a Pass / Fail /
Not-Applicable radio group where the **Fail branch carries its own required
fields**: a photograph (required, with a caption naming the item), an
explanation, and priced invoice items.

> **A finding that fails demands its evidence, structurally.** The technician
> cannot record a failure and move on. That is why their sample report has a
> code citation and a $3,199 liner kit hanging off one failed item, and it is
> the single most valuable idea in the old tool.

---

## 2 · The gap

| Old tool | Ours today |
|---|---|
| 19 field types | 7 |
| `dependsOn`: `eq`/`ne`/`in`/`not-in`, nested `and`/`or`, on **fields and whole steps** | `required_if`, one field equals one value, and it changes requiredness only — never visibility |
| Options carry **nested fields** | nothing |
| Option `variant` (success / destructive / toned) drives pass-fail colour | nothing |
| `details` — a building-code citation shown to the technician and printed as the violation | nothing |
| `isPassing` on a number — a measurement can fail with a reason | nothing |
| `calculated` — square footage from height × width | nothing |
| `invoice-items` — priced work attached to a finding | nothing |
| Per-field PDF mapping (`{fieldId, isRoot}` / `"text"` / `"ignore"`) | every field rendered uniformly |
| Steps with `dependsOn` and `allowDirectAccess` | screens, unconditional |

---

## 3 · Decisions taken

Answered by the owner, 2026-08-28.

1. **Pricing** — pick from EzBizi's **Services** catalogue; a submitted
   inspection produces a quote on the Job.
2. **Scope** — an inspection belongs to an **organization, a company, or a
   franchise**.
3. **Code citations** — a **reusable per-organization library**, cited per field.
4. **Sequencing** — **engine first**, then port the content as data.

---

## 4 · Scope owner on a definition

Not a new invention. `Automation`, `Mailbox` and `PhoneConnection` already carry
a `scope_type` / `scope_id` pair, and `InspectionDefinition` gains the same one.

- `scope_type = null` — the organization. Every franchise sees it.
- `scope_type = 'company'` — that company **and every franchise beneath it**,
  including its Sub Companies' franchises. `Automation::coversScope` argues this
  at length and is the implementation to copy: without the downward reach the API
  accepts a scope the runtime can never match, so the thing looks configured and
  never appears.
- `scope_type = 'franchise'` — that franchise only. Deliberately **not** the
  settings cascade in reverse: a franchise-scoped inspection must not appear for
  a sibling franchise because they share a parent.

**Scope narrows; grants still gate.** The existing per-role
`InspectionDefinitionGrant` is unchanged and still decides who may run a
definition. Scope decides where it exists at all. Both must pass. A1Chimney's
inspection gets `scope_type = 'company'`, `scope_id = <A1Chimney>`.

**Reports and runs are unaffected.** A report is already frozen and reachable by
its job's franchise; scope is a property of the *definition*, read when the
picker is built and when the Builder lists. A definition whose scope narrows
after a report exists does not retract the report — the report is evidence of a
visit that happened.

---

## 5 · The engine

### 5.1 Conditions

One `Condition` value object, stored on a field or a screen as `visible_if`, and
evaluated against the run's answers.

```
{ "field": "system_type", "op": "in", "values": ["masonry","wood"] }
{ "op": "and", "conditions": [ … ] }
```

Operators: `eq`, `ne`, `in`, `not_in`, plus `and` / `or` taking nested
conditions. This is the old tool's `DependsOn` with the names spelled our way.

Two rules that are not obvious and must be tested:

- **A hidden field is not a missing answer.** `RequiredFields::assertFilled`
  must skip anything whose condition is false, or a masonry inspection is blocked
  by an unanswered wood-stove question it never showed. This is the single most
  likely bug in the whole engine.
- **A hidden field's stored answer is dropped at submit**, not carried. A
  technician who answers, then changes system type, must not freeze an answer to
  a question the report will not show. `BoundAnswers::freeze` is where that
  happens, beside the bind freezing that already lives there.

`required_if` stays and keeps working — it is a different question (this answer
is *required* when…) from visibility, and existing definitions use it.

### 5.2 Nested fields on an option

A `choice` option may carry its own `fields`, shown only while that option is
selected. This is what makes a failure demand evidence, and it is why nesting is
worth the complexity rather than being a flourish.

Depth is capped at **one level**. The old tool never goes deeper, and an
uncapped tree is a Builder nobody can draw and a validator that can recurse
forever. `FieldSet` refuses depth 2 with a sentence saying so.

Answer keys stay flat — a nested field has its own `key` like any other. Nesting
is a *display and validity* relationship, not a data one, which keeps `answers` a
flat map and leaves the report renderer, the purge and the PDF unchanged.

> **This changes an existing rule and the change needs the same care the
> optionless-choice refusal got.** `FieldSet` today enforces key uniqueness
> **per screen** (`$keysOnScreen`). Conditions reference a field by key from
> anywhere in the definition, so a key that repeats across two screens makes
> `visible_if` ambiguous — it must become unique **per definition**.
>
> That is a tightening, and a tightening can refuse a definition somebody
> already has. Before it ships: scan every `inspection_definitions` and
> `inspection_definition_versions` row, without global scopes, for a key that
> repeats across screens. If the count is non-zero, grandfather — refuse the new
> collision, leave the old definition publishable, and let a condition refuse to
> reference an ambiguous key rather than blocking the whole form. Record the
> count **and the population it was drawn from** at the refusal, as
> `FieldSet::fromInput` already does for optionless choices: "zero" and "zero out
> of two" are different facts and only one of them is evidence.

### 5.3 Option variants

`variant` on an option: `neutral` (default), `pass`, `fail`, `not_applicable`.
It drives the colour in the run screen, the pass/fail summary on the report, and
nothing else. It is presentation plus one derived fact — **an inspection's
findings can be counted** — which is what makes a report scannable.

### 5.4 New field types

Added: `textarea`, `email`, `phone`, `address`, `checkbox`, `divider`, `alert`,
`services`.

Deliberately **not** added, with reasons, because a field type is forever:

- `select` — our `choice` already covers it; the old tool's split is a rendering
  detail, and `choice` gains a `display` hint (`radio` | `dropdown`) instead.
- `group` / `spacer` — layout for a two-column desktop form. Our runner is
  phone-first and one field per row; `divider` carries the only grouping that
  survives on a 375px screen.
- `time-slot` — a Workiz booking-window concept. EzBizi schedules on the Job.
- `calculated` and `isPassing` — real, and deferred to §9. Both need an
  expression evaluated on a phone and on a server without becoming a language.

### 5.5 The code library

A new per-organization catalogue: `inspection_codes`, holding `code`
(`R1003.11`), `title` and `body`. A field cites one by id.

Written once, corrected once, reused everywhere — the old tool's `spec.ts` has
`R1003.11` cited from five different fields, and free text would be five copies
that drift.

**The citation freezes into the report** the way a bound customer name already
does. Correcting the library must not rewrite what a past report said the
violation was. The report stores the code, title and body as text at submit.

### 5.6 Services on a finding

A `services` field lets the technician pick from the organization's Services
catalogue with a quantity. The answer holds `service_id`, plus a **snapshot** of
name and unit price, for the same reason `JobLine` does: a price raised in
September must not rewrite what an August inspection recommended.

**On submit, with a job, the picks become `JobLine` rows.** `Job::amount_minor`
is already derived from its lines and `JobLine` already snapshots name and
price, so the quote falls out of what exists — no estimate model, no second
place a price lives.

Three things to settle in the plan rather than here:

- A customer-only inspection has no job to quote. The lines are held on the
  report and offered when a job is created from it.
- Re-submitting is impossible (a run submits once), so double-quoting is not a
  case. A *second inspection* on the same job appends, and that is correct.
- `services.view` gates the picker. A technician without it sees the finding and
  not the prices.

### 5.7 PDF mapping

`ReportDocument` learns three per-field behaviours from the old tool's `pdf`
key: **root** (the finding heading, with its variant, citation, photos,
explanation and recommended work grouped beneath it), **text** (a plain
label-and-value line), and **ignore** (answered for branching, never printed —
`system_type` drives everything and belongs on the report once, not eleven
times).

Without this the ported report is 120 undifferentiated rows instead of a
document.

---

## 6 · The Builder

Everything above is authorable or it has not been done. The Builder gains:

- a **scope picker** — organization, company, or franchise
- a **condition editor** on fields and screens — field, operator, value(s), and
  `and`/`or` grouping
- **option editing** with variant, and nested fields beneath an option
- a **code citation picker**, and a screen to maintain the library
- a **Services picker** for `services` fields
- a **PDF behaviour** control per field

The phone preview must render conditions live, or an author cannot tell what a
masonry inspection looks like without running one.

---

## 7 · What does not change

Stated because the value of this module is in what it refuses:

- **Versions are written once.** Publishing an edited definition never restyles
  a submitted report.
- **Reports are frozen** apart from `archived_at` and §9 erasure.
- **Drafts are personal**, one per person per inspection per customer/job.
- **Grants are per role**, the way inboxes are.
- **Photographs and signatures** are attachments re-pointed to the report at
  submit.
- No response field is removed or renamed, no permission answer tightened, and
  no `Refusal` sentence altered while App Store review is live
  (`docs/ACTIVE-CONSTRAINTS.md`).

---

## 8 · Sequence

Engine first, content second, as decided. Each phase ends green and is
independently shippable.

| # | Phase | Why here |
|---|---|---|
| 1 | Scope owner on definitions | Smallest, independent, and A1Chimney needs it regardless |
| 2 | Conditions on fields and screens | The largest correctness risk; everything else sits on it |
| 3 | Nested option fields + variants | The pattern the port depends on |
| 4 | Code library + citations | Independent of 2 and 3; can be built in parallel |
| 5 | New field types | Mechanical once the runner handles conditions |
| 6 | Services on findings → job lines | Touches Jobs; wants its own gate |
| 7 | PDF mapping | Needs 3 and 4 to have anything to group |
| 8 | Builder UI for all of it | Continuous, but provable only once each lands |
| 9 | **Port A1's inspection as data** | A seed command, reviewed against the original field by field |

Phase 9 is not a code change. It authors ~120 fields through the same API the
Builder uses, which is the proof that the engine is sufficient: if any part of
A1's inspection cannot be expressed as data, the engine is not finished.

---

## 9 · Deferred, deliberately

- **Calculated fields** (square footage from height × width) and **number pass
  rules** (`isPassing`). Both need an expression evaluated identically on a phone
  and a server. A small declarative form — `multiply`, `divide`, `compare` — is
  probably right; a general expression language is certainly wrong. One
  screenful of the old tool, and not on the critical path.
- **Estimate delivery** — emailing or texting the customer the report and quote.
  The old tool does it; EzBizi has both mail and phone modules, so it is a wiring
  job for later rather than a design question now.
- **`time-slot`, `group`, `spacer`** — see §5.4.

---

## 10 · Open questions

1. **Does the ported inspection replace A1's use of the old tool, or run
   alongside it?** If it replaces, historical inspections in Workiz may need to
   be readable somewhere, and that is a separate import project.
2. **Who maintains the code library?** It reads like an
   `inspection.edit`-holder's job, but it is closer to a settings catalogue than
   to a form. Proposed: `inspection.edit`, revisited if a compliance role appears.
3. **Level 1 vs Level 2** is a field (`inspection_level`) that gates a whole
   step in the old tool. Under §5.1 that is an ordinary screen condition and
   needs no special handling — worth confirming against A1's intent rather than
   assuming.
