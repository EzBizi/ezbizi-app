# Dashboard glance enrichment Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.
>
> **Do not deploy, `git push production`, or SSH.** Only when the owner says so in that session.
>
> **Email:** another session owns connect, grants, and sync. Do not change mailbox files.

**Goal:** Fold the useful A1 Tools dashboard ideas into EzBizi’s existing one-box-per-module glances. No new boxes. No Open. No per-box calendar. Still one period for the page.

**Architecture:** Each live `Glance` already returns `GlancePayload` with `slices` / `series` / `sentences`. This plan only changes what those glances count. The client already draws slices, series, and sentences; Task 7 is the only client change (format money that is already on the wire, VERSION).

**Tech Stack:** Laravel 13 · PHP 8.4 · MySQL 8 (`ezbizi_testing`) · Flutter web · ViewScope · InventoryVisibility · VehicleVisibility · existing Widget counters.

**Spec:** `docs/superpowers/specs/2026-08-24-dashboard-module-boxes-design.md` — still the dashboard contract (one box, cannot hide, snapshots ignore period, empty is a sentence, amounts stay minor units).

**Owner-approved pack (2026-08-24)** overrides these original spec lines:

- Jobs may offer **line** as a presentation (revenue over the period). Default stays pie. This is not a second Jobs box.
- Inspection’s bar becomes **named inspections**. Status counts move to sentences. Submitted-in-period uses the dashboard period.
- Inventory is live (already) and this pack fills stock health, open POs, and financials-gated last-cost.
- HR chart slices become **active vs inactive**. Onboardings / stalled stay sentences.
- Customers empty only when the **book** in reach is empty, not when nobody new arrived this period.
- Fleet keeps the status pie and adds due-soon **sentences** (service + insurance). No money on this box.

## Global Constraints

- Front door: `StartUp.md`. If documents disagree, `docs/EZBIZI-STATUS.md` wins; the dashboard spec wins on box/layout/period; this pack wins on what each of the six glances counts.
- TDD: red test first; delete the production line once and watch it fail; restore. Report RED then GREEN with the command and output.
- User-facing sentences name what happened. No class names, `::`, or “immutable.”
- Reach is ViewScope ∩ the module’s visibility helper. Empty reach matches nothing.
- Amounts stay minor units on the wire. Never sum across currencies. Never divide by 100 in PHP.
- Empty data is a sentence, not an empty chart. Coming soon unchanged.
- Snapshots ignore the period. Time series and “this period” sentences use it.
- Cannot hide a reachable module. Tap does not navigate. No new glance keys.
- A1 Tools may be read. Its code is never copied in.
- MySQL gate: `php artisan test` against a database whose name contains `testing`. Never assert JSON key order.
- Dart tests from `client/`: `flutter test test/<file>.dart`. Bump `client/VERSION` and a `VersionHistory` row in the **same commit** that ships UI.
- Do not deploy from this plan. Do not put Fleet money on the dashboard. Do not add boxes. Do not add `jobs.financials`.
- Do not edit mailbox/email internals. Do not touch `MailboxesController`, grants, OAuth, or `WatchMailboxes`.

---

## File structure (locked)

| Path | Responsibility |
|---|---|
| `app/Support/Dashboard/Glances/InventoryGlance.php` | Stock-health pie + item/location/PO sentences + financials-gated `stock_value` |
| `tests/Feature/Dashboard/InventoryGlanceTest.php` | Create — Inventory had no glance test |
| `app/Support/Dashboard/Glances/FleetGlance.php` | Status pie + service/insurance due sentences |
| `tests/Feature/Dashboard/FleetGlanceTest.php` | Extend |
| `app/Support/Dashboard/Glances/CustomersGlance.php` | Total book + new-in-period line |
| `tests/Feature/Dashboard/CustomersGlanceTest.php` | Extend |
| `app/Support/Dashboard/Glances/JobsGlance.php` | Today sentence + revenue series + allow `line` |
| `tests/Feature/Dashboard/JobsGlanceTest.php` | Extend |
| `app/Support/Dashboard/Glances/HrGlance.php` | Active vs inactive slices |
| `tests/Feature/Dashboard/HrGlanceTest.php` | Extend |
| `app/Support/Dashboard/Glances/InspectionGlance.php` | Named-inspection bar + period submitted |
| `tests/Feature/Dashboard/InspectionGlanceTest.php` | Extend |
| `client/lib/core/money.dart` | Add `formatMinor` |
| `client/lib/screens/dashboard_screen.dart` | Draw `revenue` and `stock_value` as formatted money |
| `client/test/dashboard_screen_test.dart` | Cover formatted money + customers book |
| `client/VERSION` | `0.0.0084` |
| `client/lib/core/version_history.dart` | One row for 0.0.0084 |

Do **not** add glance classes. Do **not** change `GlanceRegistry::ORDER`. Do **not** delete Widget counters.

---

### Task 1: Inventory glance — stock health, open POs, financials

**Files:**
- Create: `tests/Feature/Dashboard/InventoryGlanceTest.php`
- Modify: `app/Support/Dashboard/Glances/InventoryGlance.php`

**Context:** `InventoryGlance` currently pies Items vs Locations. Inventory is live. There is no `InventoryGlanceTest`. Follow `FleetGlanceTest` for tenant setup (`ezbizi:sync-permissions`, `ProvisionOrganization`, `TenantContext`, `secondBranch`).

**Stock health (active items only, `InventoryItemStatus::Active`):**

- On-hand for an item is `SUM(inventory_balances.qty_on_hand)` across its balances. No balance row is 0.
- **Out of stock:** qty ≤ 0.
- **Low:** `reorder_at` is not null AND qty > 0 AND qty ≤ `reorder_at`.
- **In stock:** qty > 0 AND (`reorder_at` is null OR qty > `reorder_at`).
- Discontinued items are omitted from the three slices (they still count in the “items in reach” sentence).

**Open POs:** `InventoryPurchaseOrder` under `InventoryVisibility::constrain`, status `draft` or `open`. Not received, not cancelled.

**Financials:** if the caller `hasPermission('inventory.financials')`, set `data.stock_value` to one row `{currency: 'USD', amount_minor: N}` where N is Σ (qty_on_hand × last_cost_minor) over active items that have `last_cost_minor`, using integer arithmetic. Items with null last cost are skipped, not treated as 0. Without the permission, omit `stock_value` entirely — do not send 0. Do not divide by 100. Do not put the dollar amount in a sentence.

**Empty:** still “No items in reach.” only when there are **no items and no locations** in reach. Locations-only is not empty.

**Once any item exists (including discontinued):** slices are empty-at-zero:

```
['label' => 'In stock', 'total' => …],
['label' => 'Low', 'total' => …],
['label' => 'Out of stock', 'total' => …],
```

**Sentences (always when not empty):**

- `{n} item(s) in reach.`
- `{n} location(s) in reach.`
- `{n} open purchase order(s).` (include this even at 0 so the board does not grow a line when the first PO is cut)

Snapshot stays `true`. Period is ignored (assert week === year slices). Presentations stay `pie`, `bar`, `text`. Default pie.

**Tests (write first, watch fail, then implement):**

1. `test_a_branch_user_sees_only_items_in_their_reach` — two items on F1, one on second franchise; branch user In+Low+Out totals 1.
2. `test_zero_items_and_locations_is_a_sentence` — empty true, sentence `No items in reach.`
3. `test_qty_at_or_below_reorder_is_low_and_zero_is_out` — item A qty 10 reorder 4 → In stock; item B qty 3 reorder 4 → Low; item C qty 0 reorder 4 → Out; discontinued with qty 0 is not Out.
4. `test_open_purchase_orders_are_draft_or_open` — one draft, one open, one received, one cancelled → sentence `2 open purchase orders.`
5. `test_stock_value_is_omitted_without_financials_and_is_minor_units_with_it` — last_cost_minor 250, qty 2 → with a role that has `inventory.financials`, `stock_value[0].amount_minor === 500` and currency `USD`; administrator without that permission (strip it from the admin role for this test, or use a view-only role) has no `stock_value` key. Do not send the dollar amount in `sentences`.
6. `test_the_stock_slices_ignore_the_dashboard_period` — week slices === year slices.

Commit: `Count inventory stock health, open orders, and last-cost on the dashboard glance.`

---

### Task 2: Fleet glance — service and insurance due sentences

**Files:**
- Modify: `tests/Feature/Dashboard/FleetGlanceTest.php`
- Modify: `app/Support/Dashboard/Glances/FleetGlance.php`

Keep the `VehicleStatus` pie, empty-at-zero, no money keys.

**Service due** (same rule as `VehiclesController` `due_soon`, excluding `sold` and `returned`):

- `next_service_on` not null AND `next_service_on` ≤ today+30 days
- OR `next_service_miles` not null AND `odometer_miles` not null AND `odometer_miles >= next_service_miles - 500`

Count distinct vehicles. Sentence only when count > 0: `1 vehicle needs service soon.` / `{n} vehicles need service soon.`

**Insurance due:** current open policy (`ended_on` null) with `expires_on` not null AND `expires_on` ≤ today+30 days, on vehicles that are not sold/returned. Count distinct vehicles. Sentence only when count > 0: `1 vehicle has insurance due soon.` / `{n} vehicles have insurance due soon.`

Do not count premium / cents. Assert `revenue` and `amount_minor` still absent.

**Tests:**

1. Keep existing three tests green.
2. `test_service_due_matches_the_vehicle_list` — one van `next_service_on` yesterday, one 60 days out, one sold with yesterday’s date → `1 vehicle needs service soon.`
3. `test_insurance_due_counts_open_policies_within_thirty_days` — open policy `expires_on` tomorrow → insurance sentence; ended policy ignored.
4. Due sentences still present and identical on week vs year (snapshot).

Commit: `Say which vehicles need service or insurance on the fleet glance.`

---

### Task 3: Customers glance — total book plus new in the period

**Files:**
- Modify: `tests/Feature/Dashboard/CustomersGlanceTest.php`
- Modify: `app/Support/Dashboard/Glances/CustomersGlance.php`

**Empty** only when **zero customers in reach** (the book), not when the period added nobody.

When the book is non-empty:

- `empty` false even if the line is all zeros.
- Line / slices still bucket **new** customers (`created_at` in the period, owning franchise in reach). Zeros stay so the line does not skip quiet days.
- Sentences:
  - `{n} customer(s) in reach.` (snapshot of the book)
  - `{n} new this {period}.` (the line’s sum; 0 is spoken)
- `organization_total` is the **whole org book** (all customers in the tenant), not “new this period org-wide”.

When the book is empty: keep `No new customers this {period}.` — there is nobody to count, and that sentence already exists. Do not invent a second empty string.

**Tests:**

1. Keep reach, bar-slices-from-buckets, and changing-period tests.
2. Rewrite `test_empty_period_is_a_sentence` so it still uses an org with **no customers**.
3. `test_a_quiet_period_still_shows_the_book` — create a customer, `Carbon::setTestNow` two months later, period `week`: `empty` false, sentences contain `1 customer in reach.` and `0 new this week.`, series sums to 0.
4. `test_organization_total_is_the_whole_book` — two customers on F1, one on the second franchise; branch user’s `organization_total` is 3 while the line sums to the in-reach new count.

Commit: `Show the customer book on the dashboard even when nobody new arrived.`

---

### Task 4: Jobs glance — today sentence and revenue line

**Files:**
- Modify: `tests/Feature/Dashboard/JobsGlanceTest.php`
- Modify: `app/Support/Dashboard/Glances/JobsGlance.php`

Default stays pie. `allowedPresentations` becomes `['pie', 'bar', 'line', 'text']`. `snapshot` stays true (the pie ignores the period).

**Today:** count reachable jobs whose `scheduled_for` calendar date in the **franchise timezone** (UTC if none) equals today in that timezone. Sentence only when count > 0: `1 job today.` / `{n} jobs today.` Keep the existing upcoming sentence (`scheduled_for >= now` in that timezone). A job later today may appear in both; that is correct.

**Revenue series:** when `earned_statuses` is configured and revenue does not `needs_configuring`:

- Include `data.series` as **one series per currency**, label = currency code, points = DashboardPeriod buckets, `value` = **sum of `amount_minor`** in that bucket for earned jobs (`created_at` in the bucket). Never add currencies together. Never divide.
- Also fill `data.slices` from the **status pie** (unchanged) so pie/bar still work. Line reads `series`.
- Existing revenue sentences (job counts per currency) stay.

When unconfigured: no `series` key (or empty — pie still draws). Keep the choose-statuses sentence.

**Tests:**

1. Keep existing five tests. Pie still ignores the period.
2. `test_jobs_scheduled_for_today_are_spoken` — one job `scheduled_for` today noon in the franchise TZ, one tomorrow → `1 job today.`
3. `test_line_series_is_revenue_minor_units_per_currency` — two completed USD jobs created in-period (10000 and 20000), one CAD 5000; with `earned_statuses => ['completed']`, two series labelled CAD and USD; USD points sum to 30000; CAD to 5000.
4. `test_allowed_presentations_include_line` — payload `allowed_presentations` contains `line`, default presentation still `pie`.

Commit: `Offer a jobs revenue line and say how many jobs are today.`

---

### Task 5: HR glance — active vs inactive slices

**Files:**
- Modify: `tests/Feature/Dashboard/HrGlanceTest.php`
- Modify: `app/Support/Dashboard/Glances/HrGlance.php`

Replace the in-flight vs stalled **slices** with:

```
['label' => 'Active', 'total' => …],
['label' => 'Inactive', 'total' => …],
```

Count distinct people in HR reach (`ScopesToPlaces` / the same people Headcount would consider, **including inactive**). Active = `users.is_active` true. Inactive = false. The founder counts. Empty remains `Nobody on the books yet.` only when that distinct count is 0.

Keep onboardings-in-flight, invitations-stalled, joined, left, certifications as **sentences**. `{n} people on the books.` is now active + inactive.

`test_revoked_invitations_are_not_stalled` currently reads the stalled **slice** — change it to read the stalled **sentence** (`0 invitations have stalled.`).

**Tests:**

1. Founder still `1 person on the books.` and Active total 1, Inactive 0.
2. Revoked invitations still not stalled (via sentence).
3. `test_active_vs_inactive_slices_ignore_the_period`.
4. `test_deactivated_people_are_the_inactive_slice` — create a second active user assigned to the franchise, set `is_active` false, Active 1, Inactive 1, sentence `2 people on the books.`

Commit: `Chart people as active or inactive on the Human Resources glance.`

---

### Task 6: Inspection glance — named bar and submitted this period

**Files:**
- Modify: `tests/Feature/Dashboard/InspectionGlanceTest.php`
- Modify: `app/Support/Dashboard/Glances/InspectionGlance.php`

**Slices:** one bar per named inspection in reach, combining in-progress runs and submitted (not archived) reports. Label is `InspectionReport.definition_name` for reports, and the definition’s `name` for in-progress runs. Empty-at-zero once any inspection exists: include every name that has a count > 0 (do not pad names that have zero). Sort labels alphabetically so the board does not shuffle.

**Sentences when not empty:**

- `{n} in progress.`
- `{n} submitted this {period}.` — `InspectionReport.submitted_at` inside the period (UTC bind like Customers). Archived reports still count here if they were submitted in the window.
- `{n} archived.`

**Empty:** still `No inspections in reach.`

Snapshot stays true for the **named slices** (week === year slices). The submitted-this-period **sentence** changes with the period.

**Tests:**

1. Rewrite `test_a_branch_user_sees_only_runs_in_their_franchises` to assert the named-slice totals for that user are 1 (not the old “In progress” label).
2. Keep zero-inspections sentence.
3. `test_the_named_bars_ignore_the_dashboard_period` — slices equal week vs year.
4. `test_submitted_this_period_uses_the_period` — submit a report dated now; week sentence contains `1 submitted this week.`; freeze two months later and the year sentence still includes it while week says `0 submitted this week.`

Helper: if the test file has no report helper, create one by inserting `InspectionReport` with `definition_name`, `submitted_at`, `archived_at` null, same reach as `runOn`. Use the real submit path if one is short; otherwise insert the row the list would show. Do not weaken WrittenOnce — if `InspectionReport::create` is refused, use whatever `InspectionReports` tests already use.

Commit: `Bar inspections by name and say how many were submitted this period.`

---

### Task 7: Client — format money on glances, VERSION 0.0.0084

**Files:**
- Modify: `client/lib/core/money.dart` — add `formatMinor(int amountMinor, String currency)`
- Modify: `client/lib/screens/dashboard_screen.dart` — `_GlanceBody` draws `data.revenue` and `data.stock_value` rows under the sentences as `formatMinor` text
- Modify: `client/test/dashboard_screen_test.dart`
- Modify: `client/test/money` test if one exists; otherwise add `client/test/money_test.dart`
- Modify: `client/VERSION` → `0.0.0084`
- Modify: `client/lib/core/version_history.dart`
- Modify: `docs/EZBIZI-STATUS.md` Phase 4 sentence: glances now include stock health, fleet due, customer book, jobs today/line, HR active/inactive, inspections by name. Do **not** hand-edit the numbers block.

`formatMinor`: whole minor units in, a string out. For `JPY` (and other 0-decimal ISO codes if you already have a list — do not invent a library) print the integer and the code. For everything else print two decimal places and the code, e.g. `USD 125.00`. Do not copy A1 `NumberFormat.currency`. Do not copy `fleetCents`.

Dashboard: after `_sentences(data)`, if `data['revenue']` or `data['stock_value']` is a list of maps with `amount_minor` + `currency`, print each as `formatMinor`. Jobs already sends `revenue`; Inventory sends `stock_value` only with financials. A missing key draws nothing.

Customers fixture in `dashboard_screen_test.dart` uses empty `No new customers this month.` — keep that (no customers). Add a widget test that a glance with `stock_value: [{'currency': 'USD', 'amount_minor': 500}]` shows `USD 5.00`.

VERSION and VersionHistory in the **same commit** as the UI:

`The dashboard boxes now say stock health and orders, vehicles due for service or insurance, the customer book, jobs today, people active or not, and inspections by name.`

`flutter analyze` must stay clean (no `use_null_aware_elements` infos — use `?earned` style already on the screen).

Commit: `Show glance money as formatted minor units and stamp v0.0.0084.`

---

## Out of scope

- Deploy / production push / SSH
- New glance keys, Open buttons, per-box calendars
- Fleet premiums on the dashboard
- Inventory coming-soon copy (Inventory is live)
- Email / Marketing / Phone / Services / Automations / Web Management changes
- Copying A1 Tools widgets
