# Fleet Management — design

**Date:** 2026-08-21  
**Status:** approved in session; not yet built  
**Product:** EzBizi customer app (`app.ezbizi.com`)  
**Layouts:** one tab, list + 25/75 split; mockups in `.superpowers/brainstorm/` (session 1655)

This is a new module. It does not copy A1 Tools code. A1 Tools may be read for what a field-service fleet needs; the shape here is EzBizi’s: registries, tab permissions, FileStore, Refusal sentences, automations declared on day one.

Another session owns Email. This work does not touch mailbox or email files.

---

## 1. What it is

The company’s register of vehicles — owned or rented — with everything that hangs off one van: identity, photos, who has the keys, how we hold it, insurance, service, and money.

One sidebar tab (`fleet_management`). No inner chip destinations. Owned / rented / status / due / unassigned are **filters on the list**, not homes.

A row opens the vehicle in a **25% list / 75% detail** split inside the tab. Nothing selected: the list is full width. At 375px the vehicle is the whole screen, with a way back to the list. **No modal.**

---

## 2. What it is not

- Not a GPS / tracker product. Odometer is typed (on the vehicle and on a service visit).
- Not SMS or oil-change texts. Due dates and mileage are data; automations can fire later. The clock-based triggers are declared and marked not-raised until a sweep exists.
- Not tenant-invented vehicle fields in v1. Year, make, VIN, body type and the ledger shapes are platform invariants. Extra custom fields are a later pass.
- Not a Users tab. Assignment picks an existing person who already works in that company’s subtree.
- Not Email, Jobs, or Inspection. A vehicle does not attach itself to a job in v1; a rule may do that later if Jobs grows a field for it. Do not invent a `job.vehicle_id` in this module.

---

## 3. Settled decisions

| # | Decision |
|---|---|
| 1 | Vehicle sits on the **organization**, a **company**, or a **franchise**. Empty franchise on a company = **General**. Empty company = the group. |
| 2 | Assignment is to a **person** (driver / technician), with dated hand-offs. |
| 3 | Ownership is **periods**: owned or rented, counterparty, started, ended, why it ended. Current = the open row. |
| 4 | Insurance is **policy periods** on the vehicle. Current = the open row. |
| 5 | Maintenance is a **service log** plus **next-due** (date and/or mileage). |
| 6 | **Photos** on the vehicle. **Papers** hang off the ownership / insurance / service / payment they belong to. |
| 7 | Status: `active` · `in_shop` · `out_of_service` · `sold` · `returned`. |
| 8 | **Money is in v1** — amounts on the ledgers plus a **payments** table. |
| 9 | Permissions: `view` `create` `edit` `delete` `assign` `financials`. Service visits travel with `edit`. |
| 10 | EzBizi tables and FileStore, not a port of A1 Tools. |
| 11 | List + 25/75 split. One scrolling page of cards. Add is the same pane: identity + first ownership, one save. |

---

## 4. Hierarchy and reach

```
Organization                         ← company_id null (group General)
 └── Company
      ├── (General)                  ← franchise_id null
      ├── Franchise A  → vehicles tagged A, plus General
      └── Franchise B  → vehicles tagged B, plus General
```

- Creating a vehicle requires a company the caller can write, or the organization when the view is the group. The franchise, if set, must belong to that company.
- **General visibility:** anyone who can see the company sees its General vehicles. Anyone looking at one franchise of that company sees that franchise’s vehicles **and** General. Organization vehicles are visible throughout the tenant.
- Organization view rolls every company’s vehicles up (existing scope switcher), and is where you file a van on the group.
- Moving `franchise_id` is an **edit**, audited. It is not assignment.
- Authority does not flow upward. A franchise user cannot file a vehicle on a sibling company.
- The assigned person must be an **active user reachable in that company’s subtree**. Org-level people who can see the company may be assigned. A person who only works in another company cannot.

On screen, `franchise_id` null is labelled **General**, never “no franchise.” An empty `company_id` is the organization — offered when the current view is the group.

---

## 5. The vehicle

UUIDv7 primary key. `organization_id` on every row. Soft-delete is **not** used: sold/returned stay in the table as statuses; a true delete is only allowed when the vehicle is empty (no ledger rows, no photos) and is a hard delete.

**Identity (typed):** year, make, model, plate, VIN, colour, body type, fuel type, current odometer (miles, integer).

**Body type** (platform enum): `van` `truck` `car` `suv` `trailer` `other`.  
**Fuel type:** `gas` `diesel` `hybrid` `electric` `other`.

**Display name is derived, never a form field.** Same degrade as the live product’s intent, rewritten whenever identity changes, stored only so search and sort have a stable column:

```
{year} {make} {model} · {plate}
→ description without plate
→ plate
→ VIN
→ Unidentified vehicle
```

**VIN** is unique inside the organization, including sold/returned rows. A VIN is one vehicle. Re-buying is a new ownership period on the same row, not a second vehicle. Plate is not unique.

**Status** is current: `active` `in_shop` `out_of_service` `sold` `returned`.

- `sold` is refused unless the open ownership period is `owned`. The same save **closes** that period (`ended_on` today, reason `sold`).
- `returned` is refused unless the open period is `rented`. Closes it with reason `returned`.
- After `sold` or `returned` there is no open period. Putting the vehicle back on the road is **one save**: status to `active` (or `in_shop` / `out_of_service`) **and** a new ownership period. Neither half is allowed on its own.

**Next-due** on the vehicle: `next_service_on` (date, nullable) and `next_service_miles` (nullable). Either, both, or neither. The list filter **Due soon** is: date within 30 days, or current odometer within 500 miles of `next_service_miles`, or either already past.

**Record numbers** are organization-wide (`RecordNumberGenerator` sequence key `vehicle`), so an org list never shows two `V-00001`. Format still cascades (§6). Pool vehicles have no franchise: the generator must accept a **company** as the format scope, not invent a franchise to please the current signature.

**Create is one transaction:** vehicle + first ownership period. There is no vehicle with unknown owned/rented.

---

## 6. Ledgers

Current row = `ended_on` (or `released_at`) is null. At most **one** open ownership, **one** open insurance policy, **one** open assignment.

### 6.1 Ownership periods

| Field | Notes |
|---|---|
| kind | `owned` or `rented` |
| counterparty | Dealer, previous owner, rental company — always visible |
| started_on, ended_on | Calendar dates, not instants |
| ended_reason | `sold` `returned` `bought` `replaced` `other` — null while open |

Starting a new period closes the previous one in the same transaction. Changing kind is this act, not a dropdown on the vehicle.

**Money on the period** (stripped without `financials`):

- Owned: purchase price (USD cents), lienholder, loan account, monthly payment, payoff date, transport cost.
- Rented: rate (cents) + period (`day` `week` `month`), deposit, account number, payment terms (`due_on_receipt` `net_15` `net_30` `net_45` `net_60`), payment method.

Papers (title, lease, bill of sale) attach **here**.

### 6.2 Insurance policies

Carrier, policy number, coverage (short text), `started_on`, `expires_on`, `ended_on`.

A new open policy closes the previous one. Renewing is a new period, not an overwrite.

**Money:** premium (cents) + period (`month` `six_months` `year`).

Cards and PDFs attach **here**.

### 6.3 Assignments

`user_id`, `assigned_at` (datetime), `released_at`, note.

Assign while someone already has it: release the open row, insert the new one, one transaction. That raises `vehicle.unassigned` then `vehicle.assigned`. Unassign with nobody assigned is refused in a sentence.

`fleet_management.assign` is required. `edit` alone cannot hand keys.

### 6.4 Service visits

Kind (platform enum): `oil` `tires` `brakes` `repair` `inspection` `registration` `other`.  
`occurred_on`, odometer (optional), who did it (text — vendor or a name; not necessarily a user), notes.

If the visit odometer is **higher** than the vehicle’s, the vehicle odometer updates. The visit may also set the next due (date and/or miles); those write through to the vehicle.

Receipts and photos of the work attach **here**.

### 6.5 Payments

One table for money paid against the vehicle.

Category: `purchase` `loan` `registration` `insurance` `maintenance` `rental` `toll` `deposit` `other`.  
Amount (USD cents), `occurred_on`, method (`cash` `check` `credit_card` `debit_card` `ach` `net_30` `other`), note.

Optional link to **one** of: ownership period, insurance policy, service visit — so a premium sits on the policy it paid. The vehicle is always the parent.

The whole table is absent from the API without `financials`. Receipts attach **here**.

---

## 7. Files

Existing FileStore + `Attachables` + `HasAttachments`. No `attachments.view` permission; confidentiality is the subject’s.

Add these attachable types:

| Wire name | Subject | Typical files |
|---|---|---|
| `vehicles` | the vehicle | photo gallery, any number, no required angles |
| `vehicle_ownership_periods` | a period | title, lease, bill of sale |
| `vehicle_insurance_policies` | a policy | card, declarations |
| `vehicle_service_visits` | a visit | receipt, work photos |
| `vehicle_payments` | a payment | receipt |

Photos on the vehicle are `kind=photo`. Documents on child rows are `kind=document`. Reorder photos on the vehicle (existing attachments already have an order on jobs — match that).

Deleting an empty vehicle deletes its files (the trait already does this on hard delete).

---

## 8. Money and `financials`

USD cents everywhere. No FX.

Without `fleet_management.financials`, **every** read path omits amount fields and omits the payments relation. This is an API contract, not a Flutter `if`. A technician with `edit` can still log oil and change a plate.

The Payments card is **not drawn** without the grant. Do not show a locked card that 403s.

Writes of amounts require `financials` as well as `edit` (payments: `financials`; there is no separate payments permission).

A payment and the files on it require `financials` to **view**, not only to change. Otherwise a receipt URL is a back door to the ledger. Ownership / insurance / service papers that are not amounts stay on `view` / `edit`.

---

## 9. Permissions

Four gates, in order: release flag → entitlement → permission → assignment. The tab is `fleet_management.view`. Administrator holds every action. Other roles only after a grant. Controls the caller cannot use are not drawn.

| Action | What it allows |
|---|---|
| `view` | Tab, list, vehicle, photos, ledgers **without amounts** |
| `create` | Add a vehicle (and its first ownership period) |
| `edit` | Identity, status, franchise tag, insurance, service, photos, papers |
| `delete` | Empty vehicle only |
| `assign` | Hand keys / take them back |
| `financials` | See and change amounts and payments |

Remove the current `automationExemptBecause` on this module. Declare the triggers and actions in `ModuleServiceProvider` with the rest.

Replace the coming-soon client screen with the real one. `ComingSoonModulesTest` / `coming_soon_tabs_test` must stop treating Fleet as a placeholder.

---

## 10. Automations (§5.5)

Subject of the module is the **vehicle**. Child ledgers are how an event happened, not a second field-picker record.

**Raised**

| Key | When |
|---|---|
| `vehicle.created` | The create transaction commits |
| `vehicle.updated` | Identity / franchise / odometer / next-due saved. Not fired when the only change is status, assignment, or a ledger (those have their own events) |
| `vehicle.status_changed` | Status moved. Payload includes old and new |
| `vehicle.assigned` | Keys handed to a person |
| `vehicle.unassigned` | Keys taken back |
| `vehicle.ownership_started` | A new ownership period opened |
| `vehicle.insurance_started` | A new policy opened |
| `vehicle.service_logged` | A visit saved |
| `vehicle.payment_recorded` | A payment saved |

**Declared, not raised** (clock; same honesty as `invitation.expired`):

| Key | When it will mean |
|---|---|
| `vehicle.insurance_expiring` | Open policy `expires_on` inside 30 days |
| `vehicle.service_due` | Next-due date or mileage crossed |

**Actions a rule may take:** assign to a user, unassign, change status, update a non-money field (plate, odometer, next-due, colour). **No action that writes amounts.**

---

## 11. Screens

**List**

- Search: derived name, plate, VIN, number, current driver’s name.
- Filters (chips, multi-select where it helps): Owned · Rented · Active · In shop · Out of service · Sold · Returned · Unassigned · Due soon.
- Default, with no status chip: the **active fleet** — `active`, `in_shop`, `out_of_service`. Sold and returned are hidden until those chips (or an explicit “include ended”) are on. Owned / Rented further narrow that set.
- Each row: derived name, plate, status tag, owned/rented tag, driver or “Nobody has the keys”, pool or franchise name.
- Failed fetch: **the request failed**, not an empty list. Empty-and-successful: there are no vehicles, with Add if `create`.
- Add vehicle: same 75% pane, title “New vehicle”.

**Vehicle (one scroll of cards)**

1. Header: derived name, status, owned/rented, company, pool or franchise, current driver.
2. Photo gallery.
3. Identity (year, make, model, plate, VIN, body, fuel, colour, odometer, next-due). Display name is a preview, not an input.
4. Assignment: current person + history. Assign / Unassign only with `assign`.
5. Ownership: open period prominent, older periods below. “Start a new period” is the kind change.
6. Insurance: open policy, history below, papers on the period.
7. Service: visits newest first; logging one can roll odometer and next-due.
8. Payments: only if `financials`.

**Add form (one save)**

Identity + “How do we hold it?” card (Owned / Rented) with the matching counterparty and start date (and money fields if `financials`). Save writes vehicle + first period. Cancel leaves nothing.

**375px:** list full width; opening a vehicle is a full-screen route with Back. Cards stack to one column. Touch first; no hover-only.

Palette from `client/lib/core/theme.dart`. No purple, no literal hex in widgets.

---

## 12. Refusals (sentences, from the server)

| Situation | Sentence |
|---|---|
| Delete with any ledger or photo | This vehicle has history, so it cannot be removed. Mark it sold or returned instead. |
| `sold` while rented | Sold is for a vehicle the company owns. Return a rental instead. |
| `returned` while owned | Return is for a rental. Mark an owned vehicle sold instead. |
| Assign a person outside the company | You can assign people who work in this company. This person does not. |
| Assign without `assign` | You can view this vehicle, but you cannot hand over the keys. |
| Amounts without `financials` | Amounts on the fleet are hidden for your role. |
| Duplicate VIN | This organization already has a vehicle with that VIN. |
| Create without a first ownership period | A vehicle has to be owned or rented. Say which, then save. |
| Unassign when nobody has it | Nobody has the keys. |
| Franchise not in this company | That place is not part of this company. |

Two copies of a refusal drift; the client shows the server’s sentence.

---

## 13. Dates and money

Compliance and service dates are **calendar days**. Do not parse `YYYY-MM-DD` as UTC midnight. “Due soon” and “expires” compare dates in the vehicle’s franchise timezone, or the company’s first franchise / org default when the vehicle is in the pool.

Amounts are integer cents, USD only.

---

## 14. Tests (MySQL)

Never assert JSON key order.

Backend, at least:

- Create writes vehicle + ownership in one transaction; a vehicle with zero periods cannot be read back.
- VIN unique per organization; two companies in one org cannot both hold it.
- Pool vehicle is listed for a franchise-scoped user of that company, and not for a user who cannot see the company.
- `sold` closes the open owned period; refused on a rental.
- `returned` closes the open rented period; refused on owned.
- Delete refused once any ledger or photo exists; allowed when empty.
- Assign refuses a user who is not in the company subtree.
- Reassign releases the previous person in the same transaction.
- Without `financials`, purchase price / premium / payments are absent from JSON; with it, they are present.
- Permission catalogue includes the six keys; `ModuleRegistryTest` automation rule passes (no exemption).
- Record number is unique per organization.

Client, at least:

- Fleet is no longer a coming-soon destination.
- 375px: list, then full-screen vehicle, back returns to the list.
- No modal.
- Payments card absent when the session lacks `financials`.
- Add form includes the ownership card; save is one action.

---

## 15. Implementation notes (not a plan)

- Extend `RecordNumberGenerator` so format scope can be a company (pool vehicles).
- `Attachables` + morph map + `HasAttachments` on each new model.
- `VehiclePolicy` is the Gate target; child controllers authorize through the parent vehicle.
- Strip financials in the API resource, one helper, every read.
- Bump `client/VERSION` and add a `VersionHistory` row in the same commit that ships UI.
- Stay off Email files.

A1 Tools remains reference for field checklists (photo angles, payment categories). Do not import its permission fallback chain, JSON blobs, or UI.

---

## 16. Out of v1

GPS / live odometer, SMS reminders, tenant-defined extra fields, attaching a vehicle onto a job, a company-level master insurance policy that vehicles inherit, inner chip tabs, six required photo angles.

Clock-based `vehicle.insurance_expiring` and `vehicle.service_due` are named so the builder is honest; they do not fire until a later sweep.

---

## 17. Open questions

None that block the spec. Implementation plan is next (`docs/superpowers/plans/`), same grain as Inspection.
