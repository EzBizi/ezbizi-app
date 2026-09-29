# Billing in Jobs: design

Owner: the Jobs session (`local_aa58c8ac-…`). Asked for by Jose on 2026-09-29, relayed
by the deploy desk from `docs/A1-TOOLS-CLIENTS-JOBS-MIGRATION.md`:

> "build it, but as a part of Jobs and implement it."

This spec covers **Phase 1**, the part to build now. Phases 2 and 3 are named at the
end so nothing here closes them off, but they are not specified.

## Jose's decisions, 2026-09-29

| # | Question | His answer |
|---|---|---|
| 1 | Who sees money? | "it should be decided on roles y permissions as a financials toggle" |
| 2 | For A-1's Workiz jobs, does Workiz stay the source of truth for invoices, shown live and read-only in EzBizi? | yes |
| 3 | Skip A1's 2,731 Workiz invoices, because EzBizi already has the same jobs' money straight from Workiz, and newer? | yes |
| 4 | Hold A1's 1,488 CSV invoices until the export of pre-May-2026 Workiz jobs is settled, so money is not counted twice? | yes |
| 5 | Leave taking payments and emailing invoices or estimates for later, only if wanted? | yes |

## What was measured (production, 2026-09-29)

- **Every A-1 job is a Workiz job.** A1-Group has 8,552 jobs, all linked to a Workiz mirror
  row. None are EzBizi-made, and none have `job_lines`. Only the EzBizi Demo organization
  has native jobs (23).
- **The mirror already carries each job's money, refreshed every minute** by the Workiz
  sync. In `workiz_jobs.payload`:
  - `JobTotalPrice` (>0 on 3,045 jobs, $5,089,246);
  - `JobAmountDue` (>0 on 425 jobs, $718,506 open);
  - `SubTotal`;
  - `PaymentDueDate`, a naive `YYYY-MM-DD 00:00:00`;
  - `LineItems` (on 2,370 jobs), each with `Name`, `Description`, `Quantity`, `Price`,
    `Cost`, `Taxable`, `Type`.
  - Amounts are dollars, as int or float.
  - A1's "$1.18M open" is A1's stale copy of these same fields.
- **The edge cases are real:**
  - The amount due is negative on 102 jobs, a credit.
  - Money is due on 58 Canceled jobs.
  - It is set before the work happens: 155 Submitted and 82 Pending jobs carry an amount
    due.
  - No job has more due than its total.
  - 2,593 jobs are fully paid, and 227 partly paid.
- **Workiz's API has no invoice or estimate endpoint.** Its v1 surface is jobs, leads, team
  and time off. A Workiz invoice IS its job's money. The one money write is
  `/job/addPayment/`, which needs the `auth_secret` now held on all nine connections.
- **A1's estimates were made in A1 Tools, not in Workiz:** 2,388 of them, $5.85M quoted.
  - Statuses: archived 1,954, accepted 275, declined 61, sent 52, draft 46.
  - Only 33 line rows exist across all of them, so most are a total with no lines.
  - 2,336 sit on clients that exist only in A1, 22 came from inspection reports, and 6
    have a job.
- **Inventory's sales orders** carry a `payment_status` flag and no payments table. There
  is nothing to reuse for job billing.

## Phase 1

### 1. `jobs.financials`: the Financials toggle

The house pattern already exists: Fleet (`fleet_management.financials`, "see and change
amounts and payments") and Inventory (`inventory.financials`, "see and change costs") each
declare a `financials` action, and **money never travels with edit**. Jobs gets the same:
`actions: [...DEFAULT_ACTIONS, 'export', 'financials']`, which the roles editor shows as a
toggle on the Jobs row.

Anything in Jobs that reads or writes an amount needs it:

- **Every job payload.** Without the toggle, `amount`, the lines and the new `money`
  block are ABSENT, not null, so the client draws nothing rather than a zero.
- **Writes.** `amount_minor` on create and update, and all of `JobLinesController`, are
  refused without it, with one sentence on the field or a 403. The lines ARE the money
  breakdown, and A-1 has none.
- **Everything else.** The money filter and totals on the jobs list, all of estimates,
  and, wherever a jobs export carries money, its money columns (dropped without the
  toggle).

The administrator holds it, as it holds every permission. Every other role starts
without it, until Jose turns it on per role (Jose decides tenant configuration).

### 2. Workiz's money on a job, read live

`App\Support\Jobs\WorkizMoney::for(Job $job): ?array` reads the job's mirror row, returns
null for a job with no Workiz row, and is read-only. There is no new table and no sync,
because Workiz's sync already refreshes the mirror (Jose, decision 2).

- **Amounts.** All in minor units, in the job's currency, rounded rather than cast:
  `subtotal`, `total`, `due`, and `paid` (total minus due, never below 0).
- **Credit.** When the amount due is negative, `credit` is minus the due.
- **Due date.** `due_on` is the date part of `PaymentDueDate`, a franchise wall date and
  never an instant.
- **Lines.** Name, description, quantity, unit price and taxable. `Cost` is left out:
  it is the company's own cost, which nobody has asked to show.
- **State.** One of:
  - `none`: total 0;
  - `paid`: due 0;
  - `credit`: due < 0;
  - `partial`: 0 < due < total;
  - `unpaid`: due = total > 0.
- **Overdue.** `overdue` is true when due > 0 and `due_on` is before the reader's today.
  The list already takes `today` from the client, because the server's UTC date is wrong
  every US evening.

The job's own status is not consulted. A Canceled job with money due shows that money,
because that is what Workiz says. The panel shows the job status beside it, so a person
can judge.

It appears on the detail payload as `money`, only with `jobs.financials`.

### 3. The money view of the jobs list

No new screen or endpoint: `GET /jobs` gains the following, both needing
`jobs.financials`:

- `?money=unpaid|partial|overdue|paid|credit`, joining `workiz_jobs` and filtering on the
  same derivation. `overdue` requires `today`.
- `meta.money_totals` on page 1: for each currency, `total`, `paid`, `due` and `overdue`
  over the filtered set, with the money filter left out, the same faceting as the status
  chips. Currencies are never added together.
- Each row carries `money: {total, due, state, due_on}`. The client decides overdue by
  comparing `due_on` with its own today, as the list's windows already do.

JSON extraction over about 8.5k rows is fast enough today. If it stops being, the upgrade
is a generated column plus an index, not a copy table.

### 4. Estimates

Estimates are EzBizi records, because their old home, A1 Tools, is being dismantled.

**Table `estimates`.** BelongsToTenant, with these columns:

- `franchise_id`, and `customer_id` (required);
- `job_id`, nullable;
- `number`, from a new series `RecordNumberGenerator::ESTIMATES`, per franchise;
- `status`: `draft | sent | accepted | declined | archived`, A1's own words;
- `issued_on`, `accepted_at`, `note`;
- `currency`, from the franchise;
- `total_minor`;
- `source`: `ezbizi | a1`;
- `external_ref`, A1's id, unique per organization and source;
- `import_batch`, nullable.

**Table `estimate_lines`.** `estimate_id`, `service_id` (nullable), `name`, `description`,
`quantity` (decimal), `unit_price_minor`, `position`.

`total_minor` is the stored figure. Editing lines recomputes it, so imported estimates
without lines keep A1's total.

**API.**

- `GET /estimates`, filtered by `customer_id`, `job_id` and `status`, scoped by
  franchise reach and ViewScope like every list.
- `GET /estimates/{id}`.
- `POST /estimates`.
- `PATCH /estimates/{id}`. A `lines` array replaces all lines, which avoids a lines CRUD.
- `DELETE /estimates/{id}`, drafts only. Anything past draft is archived instead.

**Permission.** Every action needs `estimates.<action>` AND `jobs.financials`, and the
franchise must be in reach. Estimates are a child module of Jobs (`parent: 'jobs'`): a
ModuleDefinition holds one automation subject, and `estimate.accepted` is about an
Estimate, so the registry refuses it on the Job subject. The module brings its own
view/create/edit/delete toggles, and the Financials AND keeps Jose's rule that money
is decided by that one toggle.

**Transitions.** Draft goes to sent, and sent goes to accepted or declined. Anything can
be archived. Accepting stamps `accepted_at`.

### 5. Importing A1's estimates

`jobs:import-a1-estimates {bundle} --dry-run` follows the migration spec's rules:

- it never overwrites;
- it records every created id under an `import_batch`, so a batch can be rolled back;
- it has a dry run, and a dump is taken before the real run;
- it fires no automation events;
- it runs under the Workiz projection's lock.

It also sets its own conditions:

- **Idempotent** on `external_ref`: a re-run skips what exists.
- **Input** is the bundle from the A1 Tools Dismantler (migration spec, item 4), in a
  format agreed with that session.
- **Order.** It runs after the clients import (migration spec, item 1), because 2,336
  estimates sit on clients that exist only in A1. It matches through that import's
  mapping (franchise plus Workiz client number), and links a job by Workiz UUID where the
  estimate has one.
- **Statuses** map one to one. The franchise is the estimate's own, or else the
  customer's owning franchise.

## The four conformances

**Placement.**

- Estimates are BelongsToTenant.
- The franchise is validated against reach, and no id is taken from the caller unchecked.
- `WorkizMoney` reads only the job's own mirror row.

**Permission.** `jobs.financials`, as above. It is administrator-only until Jose grants it.

**Automations.**

- `estimate.accepted` is a trigger, with subject `Estimate` and payload
  `{before, after}` status only. No personal data, since `automation_events` holds none.
- `estimate.created` and `estimate.sent` are not triggers yet: sending is Phase 3, and
  nobody has asked to react to a draft.
- Workiz payments raise nothing yet. Detecting "paid" needs the Workiz projection to watch
  `JobAmountDue`, which is Workiz's file and Phase 2.

**Retention.** Estimates are kept for the life of the organization (Inventory sales'
precedent), and removing the organization takes them. Erasing a customer anonymises the
customer in place, and the estimate keeps its amounts.

## Testing

Every rule gets a test that goes red when the rule is broken on purpose:

- **WorkizMoney:** each state, credit, overdue at the franchise date boundary, and dollar
  rounding.
- **Gating:** without the toggle, the keys are absent from the list and the detail, writes
  are refused with the sentence, and the money filter and estimates are refused. Each has
  a control showing the same call succeeding with the toggle.
- **Money filter:** the filter and per-currency totals with faceting, including a
  two-currency case.
- **Estimates:** the transitions, drafts-only delete, the lines replace-and-recompute,
  the trigger on accept only, and reach.
- **Import:** idempotency, dry run writes nothing, a batch rolls back, and no events fire.

## Later phases (not specified here)

- **Phase 2:**
  - EzBizi's own invoices and payments, for jobs Workiz does not have (other tenants),
    built from `job_lines` and numbered INV-, with payments recorded by hand.
  - `invoice.paid` for Workiz jobs, raised by the projection when `JobAmountDue` reaches 0.
- **Phase 3, only if Jose wants it (decision 5):**
  - emailing invoices and estimates as PDFs;
  - taking payments in EzBizi and writing them to Workiz through `/job/addPayment/`, a
    Workiz write;
  - online payments.
- **A1's CSV invoices (1,488):** decided once the pre-May-2026 jobs export is settled
  (decision 4).
- **A1's Workiz invoices (2,731):** not imported (decision 3).

## Other sessions this touches

- **UI/UX:** the client screens (the Money section on the job panel, the money filter on
  the list, the estimate screens), drawn for Jose per the approval flow.
- **Reports:** job money columns in reports should require `jobs.financials`.
- **Workiz:** runs the clients import first, and owns Phase 2's `JobAmountDue` watch and
  Phase 3's `addPayment`.
- **A1 Tools Dismantler:** the estimate bundle format.
- **Inspection:** "estimate from an inspection report", as A1 had (22 of its estimates),
  once estimates exist.
