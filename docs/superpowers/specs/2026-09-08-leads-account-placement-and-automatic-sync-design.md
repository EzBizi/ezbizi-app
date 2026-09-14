# Leads — account placement and automatic sync

| | |
|---|---|
| Date | 8 September 2026 |
| Status | Design approved in chat, not yet planned |
| Module | Leads ([docs/LEADS.md](../../LEADS.md)) |
| Split | Server here. The account list and assignment screen go to UI/UX. |

---

## 1 · What this is

Two things Jose asked for on 8 September, after real Local Services enquiries
started arriving:

1. **All connected accounts visible, and placeable.** One connect action on
   3 September created **22 connections in the same second**, all on the A-1
   Chimney company. The tab shows one. At company level he wants all of them,
   and wants to say which franchise an account's leads belong to — or leave it
   at company level.
2. **Fetching should happen by itself**, server-side, not behind a button.

## 2 · Two facts about a connection, deliberately kept apart

A connection already carries `scope_type` / `scope_id`: **who can see it.**
Assignment is a different question — **where its leads land** — and Jose chose
to keep them separate:

> New leads land at that franchise; the account stays visible at company level.

The alternative, re-scoping the connection itself, was rejected because
`SocialConnectionAccess::inView` makes a franchise-scoped connection invisible
to the company above it. Assigning an account would have hidden it from the
person who assigned it.

> **CORRECTION, 2026-09-14 — the paragraph above is false.** `inView()`'s
> COMPANY branch admits a franchise-scoped connection whose franchise belongs to
> the viewer's company, and has since `d3f17b70` on 2026-08-21 — before this
> spec was written. `canActOnFranchise()` likewise lets a company-level
> assignment act on its franchises, so re-scoping would not have removed
> anybody's authority either. Re-scoping would NOT have hidden the account from
> the person who assigned it.
>
> **Jose's requirement is still met** — "new leads land at that franchise; the
> account stays visible at company level" is true of the column and would have
> been true of re-scoping. What was wrong was the reason for choosing between
> them, not the outcome. The one real difference is narrower: a company-scoped
> connection is visible to *every* franchise in that company, so re-scoping to
> one hides it from its siblings.
>
> The column is deployed and stays. `inView`'s company branch is now pinned by
> `FranchiseScopedConnectionInCompanyViewTest`, which nothing covered before.
> Found when the Reports session quoted this reasoning back while choosing where
> to put a per-franchise Workiz binding; the full correction is in the migration.

**So: one nullable column, `social_connections.leads_franchise_id`.** Null means
company level, which is today's behaviour, so the migration needs no backfill —
and per the standing rule, it is a **new migration file**, never an edit to a
deployed one.

**The franchise must belong to the connection's own organization.** Assignment
is a write that names a row by id, so it is refused unless that franchise sits
under the same `organization_id` — otherwise a valid-looking id from another
tenant would route one customer's enquiries into another's list. Checked in the
controller, not left to the foreign key, because the FK constrains existence and
not ownership.

## 3 · Where a lead lands

`LocalServicesLeads::store` already stamps from the connection:

```php
'company_id'   => $connection->scope_type === SCOPE_COMPANY   ? $connection->scope_id : null,
'franchise_id' => $connection->scope_type === SCOPE_FRANCHISE ? $connection->scope_id : null,
```

It gains one rule, ahead of those: **if `leads_franchise_id` is set, the lead
takes that franchise.** The company stays as it is, so the row remains visible
to the company view — which is what §2 promised.

## 4 · Re-assignment is retroactive

Jose's ruling: assigning an account re-stamps **every lead it has already
produced**, not just new ones. A manager setting this up on Monday expects the
branch to see its own history, not an empty list.

The cost, recorded because it will be discovered otherwise: **a lead's
`franchise_id` stops being a record of where it arrived** and becomes a
reflection of the account's current assignment. Re-assigning later moves the
history again. That is the accepted trade, not an oversight.

Mechanics: the re-stamp runs in **chunks of 500 by connection id**, each chunk
its own transaction, with **one** audit row at the end naming the connection,
both franchises and the total count. Chunked rather than one statement because
400 leads today is not 400 forever, and a single unbounded UPDATE on a growing
table is a lock somebody eventually notices; one audit row rather than one per
chunk because the event is the re-assignment, not the batching.

## 5 · The sync command

`ezbizi:sync-leads`, `everyFiveMinutes()->withoutOverlapping(10)` in
`routes/console.php`.

**Five minutes is a quota decision, not a taste one.** Basic Access is 15,000
operations/day against a ceiling that belongs to the whole Cloud project and is
shared by every tenant. Per-account polling costs 1,440 calls per account per
day at one minute, so the cadence is really a budget for how many accounts the
platform can carry:

| Cadence | Per account/day | Accounts the 15,000 supports |
|---|---|---|
| 1 min | 1,440 | 10 |
| **5 min** | **288** | **52** |
| 15 min | 96 | 156 |

At 22 accounts a one-minute cadence is already 2.1× over. Five minutes is 6,336
calls/day — 42% — leaving room for somebody pressing refresh and for Reviews
when its own approval lands.

**Not built: busy/quiet tiering.** It is the right eventual answer, because it
decouples cost from account count in a way a flat cadence never can. It is the
wrong first one: Google grants quota increases on demonstrated need, and five
minutes across a real fleet is what builds that record.

### Two things the command must do differently from the button

- **Enter each tenant explicitly.** The scheduler is nobody. Following
  `PublishDueSocialPosts`: find work with `withoutGlobalScopes()`, then run each
  connection's sync inside `TenantContext::run($organization, …)`. Without that
  the tenant scope filters every row to nothing.
- **Not apply `inView()`.** That filter answers *"what may this person see"* and
  is a request concern. A background job legitimately syncs every connected
  account; reusing the request path here would sync nothing, because there is no
  user to view anything.

Failure stays per-account, as the manual sync already behaves: one suspended
account must not stop the other 21.

## 6 · Storage

| Table | Change |
|---|---|
| `social_connections` | **+ `leads_franchise_id`** — nullable, FK to `franchises`, null = company level |
| `leads` | none. `company_id` and `franchise_id` already exist and already carry the placement |

## 7 · Testing

Five that would catch a real defect:

1. The scheduler reaches connections in **more than one organization** — the
   tenancy trap in §5, and the one most likely to pass in a single-tenant test
   and fail in production.
2. A lead from an assigned account lands at the **franchise**, and stays visible
   to the **company**.
3. Re-assigning **moves existing leads** and writes the audit row.
4. **One failing account does not stop the rest** — the guarantee already
   established for the manual sync.
5. Assignment is refused for a franchise **outside the connection's own
   organization**.

## 8 · Not in this spec

- **The screen.** Twenty-two accounts with per-account assignment is a new
  arrangement, so it goes to UI/UX as a brief: three desktop options and three
  mobile options, Jose picks, **and UI/UX builds it** — per his ruling of
  2026-09-01 recorded in memory. Note that `docs/MODULE-OWNERS.md:151` still
  says the module session implements it; that row contradicts the ruling and is
  the UI/UX owner's to correct.
- **Connect storing every reachable account.** The 22 rows are the symptom of a
  connect flow that never asks which account you meant. Placement makes them
  manageable; it does not make 22 correct. Its own change.
- **Tiering** (§5).
