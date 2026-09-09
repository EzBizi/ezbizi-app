# Inventory General tab — design

**Date:** 2026-09-09
**Status:** drafted for owner review; not built
**Product:** EzBizi customer app (`app.ezbizi.com`)
**Parent spec:** `docs/superpowers/specs/2026-08-24-inventory-design.md` — this adds a chip to §13 and changes nothing else in it.
**Asked for by:** Jose, 2026-09-09: *"I feel we are missing a General Tab where it works like a dashboard showing a summary of everything, graphs, warnings, etc"* — and, asked which of three scopes, *"all of the above"*.

---

## 1. What it is

A fifth chip on Inventory, first in the row, that opens when you open the module.
It answers three questions before you have tapped anything: what do I hold, what
has moved lately, and what needs attention. Every number on it is a way in — a
figure you can tap lands on the list that produced it, already filtered.

## 2. What it is not

- **Not a second Dashboard.** The Dashboard's Inventory box stays exactly as it
  is. This is the same facts inside the module, where you can act on them.
- **Not a new source of truth.** It computes nothing the module does not already
  know. Where a definition already exists — low, out, stock value — it is reused,
  not restated.
- **Not a report writer.** No custom date ranges, no export, no saved views. One
  period control, the same one the Dashboard uses.
- **Not the `item.low` automation sweep.** Showing low stock on a screen and
  raising `item.low` as an event are different pieces of work. This tab does not
  close that one, and does not emit anything.

## 3. Settled decisions

| | |
|---|---|
| Scope | All three of: actionable summary, movement over time, warnings list. Owner's answer, 2026-09-09. |
| Position | **First chip**, left of Stock. |
| Landing | **General opens first.** Stock is no longer the default pane. |
| Label | `General`. |
| New permissions | **None.** The chip is `inventory.view`, like the module. Money obeys the existing `inventory.financials`. |
| New tables, new columns | **None.** |
| Aggregation | One class, two consumers. See §5. |

## 4. Where it sits

`client/lib/screens/inventory_screen.dart` holds the chips as a list of
`(label, icon, builder)` tuples. General is inserted at index 0 and the default
selected index stays 0, so the change to landing behaviour is the insertion
itself. Stock, Items, Orders and Locations keep their order and shift right.

Existing widget tests that assert Stock is what opens will fail, correctly, and
are updated in the same commit as the insertion.

## 5. One aggregator, two consumers

The counts this tab needs already exist, computed inside
`app/Support/Dashboard/Glances/InventoryGlance.php`: active items classified in
stock / low / out against each item's `reorder_at`, counts of items, locations
and open purchase orders, and a stock value totalled from `last_cost_minor`.

That computation moves to **`app/Support/Inventory/InventorySummary.php`**.
`InventoryGlance` calls it. The new endpoint calls it. Neither restates it.

**Why this rather than a fresh aggregation:** two independent definitions of
"low" drift, and the failure is a Dashboard box saying four while the tab says
five — a bug nobody can reproduce because both are working as written. The repo
collapsed 23 identical copies of one method in `b6723a3` for this reason.

`InventoryGlance.php` lives under the Dashboard module's directory and is a
shared edge. Its owner is told before the file is touched, and the extraction
is behaviour-preserving: same inputs, same numbers, verified by the Dashboard's
own existing `InventoryGlanceTest` passing unchanged.

## 6. The endpoint

    GET /api/v1/inventory/summary?period=<DashboardPeriod>

One request, because the screen always needs all three blocks and three requests
would render the tab in three stages. Returns `health`, `movement`, `warnings`.

Period comes from the existing `DashboardPeriod`. No second period vocabulary.

Every query underneath passes through `InventoryVisibility::constrain`, so the
tab can never total a company the caller cannot reach. The organization arm of
that filter answers with the caller's own reach rather than the view — see the
comment at `InventoryVisibility::companyIdsFor`.

## 7. `health`

The snapshot. Fields, all from `InventorySummary`:

| Field | Meaning |
|---|---|
| `in_stock`, `low`, `out` | Active items, counted once each. `low` is on-hand at or below `reorder_at`; an item with `reorder_at` null is never low, only in stock or out. |
| `items`, `locations` | In reach. |
| `open_purchase_orders` | Status draft or open. |
| `value` | **Only with `inventory.financials`.** `{currency, amount_minor}`, on-hand times `last_cost_minor`, rounded half-up per item. |

Each figure carries the query that produced it, so the client links rather than
guesses: `low` carries `stock?low=1`, `open_purchase_orders` carries the Orders
filter, and so on. A figure with no meaningful destination carries none, and the
client draws it as text rather than a dead control.

**Empty state.** No items and no locations in reach returns
`{"empty": true, "sentence": "No items in reach."}` — the sentence the Dashboard
box already uses, from the server, not restated in the client.

## 8. `movement`

A series over the period, bucketed by calendar day in the view's timezone — not
by UTC midnight. Every movement header already carries the date it needs:
`received_on`, `issued_on`, `transferred_on`, `adjusted_on`, `fulfilled_on`.

| Line | Source | Direction |
|---|---|---|
| In | `inventory_receive_lines` | Adds to on-hand |
| Out | `inventory_issue_lines` + `inventory_sale_fulfillment_lines` | Removes from on-hand |
| Adjusted | `inventory_adjustments` | Either, by the sign of `qty_after` minus `qty_before` |
| Transferred | `inventory_transfer_lines` | **Neither** |

**Transfers are net zero and must not sit in the in-and-out lines.** Moving ten
from the warehouse to a van changes where stock is, not how much is held.
Counting a transfer as both an in and an out inflates both sides and makes a
quiet week look busy. It gets its own line, labelled as internal movement, or it
is left off the chart entirely — a design choice for §11, not a data one.

Quantities only. **No costed movement figure ships in v1**, with or without
`financials`: cost of goods issued is an accounting claim, and this module has
no cost method settled (`last_cost_minor` is a last-paid price, not FIFO or
average). Adding one later is a new block, not a change to this one.

## 9. `warnings`

A list of what the data can actually prove today. Each row is
`{key, sentence, count, link}` and links into an existing filtered list. **The
order is the order of this table** — out of stock first, because it is the only
one where work has already stopped — and it is fixed on the server, not sorted
by count in the client. Two callers looking at the same organization see the
same order.

| Warning | Rule |
|---|---|
| Out of stock | Active item, total on-hand at or below zero. |
| Below reorder point | `reorder_at` set, on-hand at or below it. The existing `stock?low=1` filter. |
| Purchase order open a long time | Status draft or open, `ordered_on` more than **30 days** ago. |
| Location holding nothing | Location in reach with no balance row above zero. |
| No cost recorded | `last_cost_minor` null. **Only with `inventory.financials`** — a gap in money is a money fact. |

**There is no "overdue purchase order" warning, and there cannot be one yet.**
`inventory_purchase_orders` has `ordered_on` and no expected-delivery column, so
there is no date for a PO to be late against. "Open a long time" is the honest
weaker claim. Giving POs an expected date is a schema change and its own piece
of work; if it happens, this warning is replaced rather than joined.

`ordered_on` is nullable. A PO with no `ordered_on` **cannot be aged and is
excluded** from that warning — it is not treated as zero days old, which would
silently make every undated PO permanently fine.

A warning with a count of zero is not sent. An empty `warnings` array means
nothing needs attention, and the client says so in a sentence rather than
drawing an empty list.

## 10. Money and `financials`

The rule from the rules desk, which this tab is the first Inventory surface to
have to obey at aggregate level:

> An aggregate is still an answer about rows you cannot see.

A total, an average or a count over costs leaks `inventory.financials` through
arithmetic even when every individual row was stripped correctly. So `value` and
the "no cost recorded" warning are **omitted server-side**, never sent and
hidden in the client. Without the permission the tab is complete and honest: it
simply has no money on it. There is no refusal sentence, because nothing was
refused — the correct absence is a missing figure, not a disabled control.

## 11. Screen

**UI/UX owns this screen and builds it.** A new tab names a new element, so it
goes to design as numbered options — three desktop and three mobile, per
`docs/UI-DESIGN-QUEUE.md` — and design implements the chosen one. The Inventory
session's deliverable is this spec, the endpoint, the aggregator and their tests.

What design is choosing between, so the brief is not vague: how the three blocks
stack at 375px and at desktop width; whether movement is a bar, a line or a pair
of stacked areas; whether transfers appear on the chart at all; and whether
warnings lead the screen or sit under the health figures.

Constraints that are not design's to choose: no modals; colours from
`client/lib/core/theme.dart`; 44px touch targets; a figure the caller cannot act
on is not drawn as a control.

## 12. Dates

Calendar days in the view's timezone. The movement buckets are days, not UTC
midnights, and the PO age is a count of calendar days from `ordered_on`. This
matches §15 of the parent spec.

## 13. Tests (MySQL, own schema)

Run as `DB_DATABASE=ezbizi_testing_inventory php -d memory_limit=1G vendor/bin/phpunit`.

- `InventorySummary` returns the same numbers the glance returned before the
  extraction — the existing `InventoryGlanceTest` passes unchanged, which is the
  proof the move was behaviour-preserving.
- An item with `reorder_at` null is never counted low.
- A transfer moves no total: in and out are unchanged by one, and the transfer
  line is not zero.
- An adjustment downward counts as out; upward counts as in.
- A caller without `inventory.financials` receives no `value` key and no
  "no cost recorded" warning — asserted by key absence, not by value.
- A sibling company's items are absent from every block, including the totals.
- A PO with `ordered_on` null is absent from the age warning.
- The empty state returns the sentence, not a zeroed payload.
- Each test's production line is deleted once and watched to fail. A count
  assertion states the paths it counted, not only the number.

## 14. Out

- `item.low` as a raised automation event. Declared, still not raised.
- Expected-delivery dates on purchase orders.
- Cost of goods issued, and any cost method beyond last paid.
- Export, custom ranges, saved views.
- Any change to the Dashboard's Inventory box beyond calling the extracted class.

## 15. Build order

1. Extract `InventorySummary`; glance calls it; `InventoryGlanceTest` green unchanged.
2. `health` block and endpoint, with visibility and financials tests.
3. `warnings`.
4. `movement`.
5. Brief to UI/UX with the endpoint live and a real payload to draw against.
6. Chip insertion and default-landing change, in the commit that ships the screen, with `client/VERSION` and its release note handled by the deploy desk.
