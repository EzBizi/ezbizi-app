# Inventory — design

**Date:** 2026-08-24  
**Status:** drafted for owner review; not built  
**Product:** EzBizi customer app (`app.ezbizi.com`)  
**Reference:** Fishbowl’s inventory mechanics (parts, locations, POs, transfers, issues). Not a port. EzBizi structure first: organization / company / franchise.

Fishbowl is a warehouse and manufacturing ERP. EzBizi is used by field-service companies *and* by companies that buy, hold, and sell stock. v1 is the register, buying, and issuing. Manufacturing (BOMs, work orders, kitting) is out.

---

## 1. What it is

The company’s stock: what it keeps, where it sits, how it arrived, and how it left.

One sidebar tab (`inventory`). Four chips above the page, same strip as Email and HR: **Stock** · **Items** · **Orders** · **Locations**. The sidebar already says Inventory; there is no second heading.

A row opens in a **25% list / 75% detail** split. Nothing selected: the list is full width. At 375px the thing is the whole screen, with a way back. **No modal.**

---

## 2. What it is not

- Not Fishbowl’s module maze, desktop client, or QuickBooks-as-the-engine.
- Not manufacturing: no bill of materials, work order, kit, or assemble/disassemble.
- Not ecommerce, barcode hardware, or lot/serial tracking in v1. Tracking method on an item is `none`. Adding serial/lot later is a column, not a rewrite.
- Not Services. That tab names what the company sells as work. Inventory names what sits on a shelf or a van.
- Not a warehouse inside Jobs. Issuing *to a job* is an inventory act that **names** an existing job. Jobs does not grow bins, on-hand, or vendors.
- Not negative stock. A movement that would take a location below zero is refused in a sentence.
- Not tenant-invented item fields in v1. SKU, name, unit, and the movement shapes are platform invariants.

---

## 3. Settled decisions

| # | Decision |
|---|---|
| 1 | An **item** belongs to the organization, a company, or a franchise. Each company may have its own catalogue. Siblings do not share items. |
| 2 | A **location** belongs the same way. Warehouse, shop, cage, receiving, or a van (a Fleet vehicle may *be* a location). |
| 3 | **Quantity** is always *(item, location)*. Org view rolls up. Siblings do not share stock. |
| 4 | Visibility matches Fleet: franchise sees its locations, that company’s General, and organization stock. Authority does not flow upward. |
| 5 | v1 movements: **adjust**, **transfer**, **purchase/receive**, **issue** (to a job or a simple use/sale). |
| 6 | No manufacturing. No lots/serials. No in-transit location: a transfer is one save. |
| 7 | Money is in v1 on PO lines and last cost. Hidden without `inventory.financials`. |
| 8 | Qty is `decimal(12,3)` with a unit on the item (`each` `box` `ft` `in` `gal` `lb` `kit` `other`). |
| 9 | Permissions: `view` `create` `edit` `delete` `financials`. Receive, transfer, issue, and adjust travel with `edit`. |
| 10 | List + 25/75. Inner chips, not extra sidebar tabs. |
| 11 | Record numbers are organization-wide (`RecordNumberGenerator`). Format still cascades. |

---

## 4. Hierarchy and reach

```
Organization                         ← item/location with company_id null
 └── Company
      ├── General                    ← franchise_id null
      ├── Franchise A
      └── Franchise B
```

- Creating an item or location requires a place the caller can write: the organization when the view is the group; a company they can act on; a franchise in that company’s subtree. A franchise user cannot file an item on a sibling company, or on the organization.
- **General visibility:** anyone who can see the company sees its General locations and the stock there. Anyone looking at one franchise of that company sees that franchise’s locations **and** General **and** organization locations. Organization items and locations are visible throughout the tenant.
- Organization view rolls every company’s catalogues and stock up.
- Moving `company_id` / `franchise_id` on an item or location is an **edit**, audited. Stock does not teleport: a location that still holds qty cannot change company. Empty, it may.
- On screen, `franchise_id` null on a company is **General**. Empty `company_id` is the organization — offered when the current view is the group.

This is `VehicleVisibility` with different nouns, not a second reach engine. One helper: `InventoryVisibility`.

---

## 5. Items

UUIDv7. `organization_id` on every row. Soft-delete is **not** used: discontinued stays in the table; a true delete is only allowed when the item has never moved (no balances, no order lines) and is a hard delete.

| Field | Notes |
|---|---|
| sku | Unique **inside the owning place** (org, that company, or that franchise). Two companies may both have `LINER-6`. Org search shows both, each with its place. |
| name | Required. Display is `{sku} · {name}` when sku is set, else name. |
| number | Organization-wide sequence (`inventory_item`). Prefix setting `numbering.item_prefix`, default `I-`. |
| unit | Platform enum above. |
| status | `active` · `discontinued`. Discontinued: cannot PO, receive, issue, or transfer onto. History remains. |
| reorder_at | Nullable decimal. Compared to **sum of on-hand at locations this view can see** for Low. Per-location reorder is a later pass; v1 is one number on the item. |
| last_cost_minor | Integer cents. Omitted without `financials`. Written when a receive posts. |

No description-as-HTML, no custom fields, no images required. A photo on the item is allowed (`HasAttachments`, `kind=photo`).

---

## 6. Locations

Named places stock sits. Not a second org chart.

| Field | Notes |
|---|---|
| name | Required. Unique inside the owning place. |
| kind | `warehouse` `shop` `cage` `receiving` `van` `other`. |
| vehicle_id | Nullable. Set only when `kind=van`. At most **one** location per vehicle. The vehicle must sit on the same place (or General of that company / the organization). |
| status | `active` · `closed`. Closed: cannot receive, transfer onto, or issue from. Qty must be zero to close. |

A van location is how “what’s on the truck” is inventory. Fleet stays the register of vehicles; Inventory does not duplicate VIN or assignment.

---

## 7. Balances

`inventory_balances`: `(item_id, location_id)` unique, `qty_on_hand` decimal(12,3) ≥ 0.

There is no available/allocated split in v1. Issues post immediately. A draft PO does not hide qty.

The Stock chip lists **balance rows** (item at a location), not items. Filters: Low (on-hand at this location at or below the item’s `reorder_at` when that is set) · In stock · Zero. Search: sku, name, location name, item number.

A location with no row for an item is zero, not a missing record. Receive/adjust **creates** the row.

---

## 8. Movements

Every movement is an append-only header + lines, then one transaction that writes the balances. No editing a posted movement. Wrong qty: another adjustment, or a reverse issue, never a rewrite.

Posted movements are the audit. `audit_events` still fire for who did it.

### 8.1 Adjustment

Count was wrong.

- Location, item, **counted as** (the new on-hand), reason (required text).
- Delta is computed: `counted − current`. Delta zero is refused.
- One line. Status is posted on save.

### 8.2 Transfer

Location A → location B, one save. No in-transit.

- Both locations in the same organization.
- A is a location the caller can **edit** (issue from).
- B is a location the caller can **see** and that is not a sibling company’s. Writing onto B is allowed when B is General of A’s company, an organization location, or a franchise of the same company. Not onto another company’s warehouse.
- Lines: item, qty > 0. A’s on-hand must cover every line.
- Status: posted on save. Number sequence `inventory_transfer`, prefix `T-`.

### 8.3 Purchase order and receive

**Vendor** sits on the same tree as items. Name required. Phone/email optional. Delete only when no PO has ever named them.

**Purchase order**

| Field | Notes |
|---|---|
| number | Sequence `inventory_po`. Prefix setting `numbering.po_prefix`, default `PO-`. |
| vendor_id | Required. Vendor must be visible in this view (same reach as items). |
| place | `company_id` / `franchise_id` of the PO — where we are buying **for**. Same rules as creating an item. |
| status | `draft` · `open` · `received` · `cancelled` |
| lines | Item, qty ordered, unit cost (cents, `financials`). Item must be active and visible. |

Draft: editable, no stock change. **Open:** issued to the vendor; lines freeze except qty received. **Cancel** only from draft or open with zero received.

**Receive** (child of a PO, one location per receive):

- Location the caller can edit, in reach of the PO’s place (the PO’s franchise, that company’s General, or organization).
- Lines: PO line, qty this time. Cumulative received cannot exceed ordered.
- Posts: increase on-hand, write `last_cost_minor` on the item (if `financials`).
- PO becomes `received` when every line is fully received; stays `open` while partial.

Over-receive is refused. Partial is the normal path.

### 8.4 Issue

Stock leaves a location.

| Field | Notes |
|---|---|
| number | Sequence `inventory_issue`. Prefix `IS-`. |
| location_id | From; caller can edit. |
| job_id | Nullable. **XOR** with a required note when null. |
| note | Required when there is no job (“sold over the counter”, “used at the shop”). |
| lines | Item, qty > 0. On-hand must cover. |
| status | Posted on save. |

**Job:** the job exists, is not deleted, and is in the caller’s view ∩ reach (`jobs.view` is enough to *name* it; this is not a Jobs write). The issue does not change job status or amount. Jobs may later show issued lines; v1 does not add a card there. The inventory issue *is* the record.

A job is franchise-owned. Issuing from an organization warehouse onto that job is allowed when the caller can edit the warehouse and can see the job.

Cannot issue a discontinued item. Cannot issue from a closed location.

---

## 9. Files

Existing FileStore + `Attachables` + `HasAttachments`.

| Wire name | Subject | Typical files |
|---|---|---|
| `inventory_items` | the item | photo |
| `inventory_purchase_orders` | a PO | vendor quote, invoice |
| `inventory_issues` | an issue | ticket, photo of what left |
| `inventory_vendors` | a vendor | W-9, terms |

Costs on a PO and the files on it require `financials` to **view**. Item photos stay on `view` / `edit`.

---

## 10. Money and `financials`

USD cents. No FX.

Without `inventory.financials`, every read path omits unit cost, last cost, and PO money. This is an API contract, not a Flutter `if`. A technician with `edit` can still receive, transfer, and issue.

Writes of amounts require `financials` as well as `create`/`edit`.

---

## 11. Permissions

Four gates, in order: release flag → entitlement → permission → assignment. The tab is `inventory.view`. Administrator holds every action. Other roles only after a grant. Controls the caller cannot use are not drawn.

| Action | What it allows |
|---|---|
| `view` | Tab, stock, items, locations, orders **without amounts** |
| `create` | Add item, location, vendor, draft PO |
| `edit` | Change identity, open a PO, receive, transfer, issue, adjust |
| `delete` | Empty item / closed empty location / vendor with no POs |
| `financials` | See and change costs |

Remove `automationExemptBecause`. Declare triggers in `ModuleServiceProvider`. Replace the coming-soon client screen. `ComingSoonModulesTest` / `coming_soon_tabs_test` must stop treating Inventory as a placeholder.

---

## 12. Automations (§5.5)

Subject of the module is the **item**. A movement says how the quantity changed.

**Raised**

| Key | When |
|---|---|
| `item.created` | Item saved |
| `item.updated` | Identity / status / reorder saved |
| `inventory.received` | A receive posts |
| `inventory.issued` | An issue posts |
| `inventory.transferred` | A transfer posts |
| `inventory.adjusted` | An adjustment posts |
| `purchase_order.opened` | Draft → open |
| `purchase_order.received` | PO fully received |

**Declared, not raised** (clock; same honesty as `vehicle.service_due`):

| Key | When it will mean |
|---|---|
| `inventory.low` | On-hand at a visible location at or below `reorder_at` |

**Actions a rule may take:** none that move stock. A rule that could issue or receive without a person is the same objection Email records against sending. v1 actions: none, or “notify” if that generic action already exists. Do not invent auto-PO.

---

## 13. Screens

**Stock** — default chip. Rows of item@location. Qty, unit, place, Low tag. Open: balances for that item across visible locations, recent movements, Reorder.

**Items** — catalogue for this view. Add item in the 75% pane. Discontinued hidden until that filter is on.

**Orders** — open POs and recent issues. Filters: To receive · Open POs · Issues · Mine (this view). Opening a PO: lines, receive into a location. Opening an issue: read-only (posted).

**Locations** — the places. Add location; if kind is van, pick a Fleet vehicle in this view with no location yet.

**375px:** list full width; opening a row is a full-screen route with Back. Chips wrap. Touch first.

Palette from `client/lib/core/theme.dart`. No purple, no literal hex in widgets.

---

## 14. Refusals (sentences, from the server)

| Situation | Sentence |
|---|---|
| Issue or transfer more than on-hand | There is not enough at this location. |
| Receive more than the PO still owes | This order does not have that many left to receive. |
| Issue a discontinued item | This item is discontinued, so it cannot go out. |
| Receive into a closed location | This location is closed. |
| Transfer to a sibling company | Stock does not move to a company you do not write. |
| Delete an item that has moved | This item has history, so it cannot be removed. Mark it discontinued instead. |
| Close a location that still holds qty | This location still has stock. Move or adjust it to zero first. |
| Duplicate SKU in this place | This place already has an item with that SKU. |
| Name a job you cannot see | That job is not in this view. |
| Issue with neither a job nor a note | Say which job this is for, or why it left. |
| Amounts without `financials` | Costs on inventory are hidden for your role. |
| Van location on a vehicle that already has one | That vehicle already is a location. |
| Change a location’s company while it holds qty | Move the stock out first. |

Two copies of a refusal drift; the client shows the server’s sentence.

---

## 15. Dates and money

PO and movement dates are **calendar days** in the place’s timezone (franchise, or the company’s first franchise / org default for General and organization). Do not parse `YYYY-MM-DD` as UTC midnight.

Amounts are integer cents, USD only. Quantities are decimal, never cents.

---

## 16. Tests (MySQL)

Never assert JSON key order.

Backend, at least:

- Company A’s SKU is not visible as an item to company B; organization items are.
- Franchise user sees General + own locations, not a sibling franchise’s cage.
- Issue that would go negative is refused; balances unchanged.
- Receive then issue in one location: on-hand matches.
- Transfer A→B is one transaction; A+B totals unchanged.
- Partial receive leaves the PO open; full receive marks received; over-receive refused.
- Cancel of a PO that has received is refused.
- Issue to a job the caller cannot see is refused.
- `financials` omitted: unit cost and last cost absent from JSON.
- Empty item delete; item with a movement cannot delete.
- Van location unique per vehicle; vehicle from another company refused.

Client: four chips, coming-soon gone, Stock lists balances, a PO receive posts and the qty on Stock moves, 375px does not overflow.

---

## 17. Implementation order (when building)

Not this document’s job to schedule, but the seams are:

1. Visibility helper + items + locations + balances (adjust only).
2. Transfer.
3. Vendors + PO + receive.
4. Issue (note, then job).
5. Replace coming-soon screen; automations; Low filter.

---

## 18. Fishbowl mapping (so we do not re-litigate)

| Fishbowl | Here |
|---|---|
| Company | Organization (tenant) |
| Location group | The place: org / company / franchise |
| Location | `inventory_locations` row |
| Part | `inventory_items` row |
| Inventory module | Stock chip (balances) |
| Purchase order | `inventory_purchase_orders` |
| Sales order / WO | **Issue** (job or note). Not a second sales-order module. |
| Transfer order | `inventory_transfers`, posted immediately |
| Manufacture / BOM | Out of v1 |
| Lot / serial | Out of v1 |
| QuickBooks | Out. Costs live on the PO and last cost. |
