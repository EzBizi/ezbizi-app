# Inventory Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.
>
> **Do not deploy, `git push production`, or SSH** unless the owner says so in that session.
>
> **Stay off Email, HR, Phone, and Services files.** Inventory only. If a task seems to need those, the task is wrong.

**Goal:** Replace the Inventory coming-soon tab with the register, buying, and issuing specified in `docs/superpowers/specs/2026-08-24-inventory-design.md`.

**Architecture:** Items and locations sit on organization / company / franchise (Fleet’s tree). Quantity is `(item, location)` on `inventory_balances`. Movements (adjust, transfer, PO/receive, issue) are append-only headers+lines that post balances in one transaction. Costs strip in one presenter. Visibility is `InventoryVisibility`, the same shape as `VehicleVisibility`.

**Tech Stack:** Laravel 13 · PHP 8.4 · MySQL 8 (`ezbizi_testing`) · Flutter web · FileStore · ModuleRegistry · ViewScope · EventEmitter · RecordNumberGenerator · Refusal

**Spec:** `docs/superpowers/specs/2026-08-24-inventory-design.md`

## Global Constraints

- Front door: `StartUp.md`. If documents disagree, `docs/EZBIZI-STATUS.md` wins; this spec wins on Inventory.
- TDD: red test first; delete the production line once and watch it fail; restore.
- User-facing sentences from spec §14 — copy them, do not paraphrase. Client shows the server’s sentence.
- Never copy Fishbowl or A1 Tools code. Mechanics only.
- No modals. 375px: list or full-screen pane, Back returns. Touch 44px.
- Colours from `client/lib/core/theme.dart`. No purple. No literal hex in widgets.
- Tab permission `inventory.view`. Extra: `create` `edit` `delete` `financials`. Receive/transfer/issue/adjust travel with `edit`. Controls the caller cannot use are not drawn.
- Qty `decimal(12,3)` ≥ 0. Never negative. USD cents for money. No FX. Calendar days, not UTC midnights.
- SKU unique **inside the owning place**, not org-wide. Empty SKU is null, not `''`.
- Hard delete only when empty (no balances, no movement lines). Discontinued is the status, not a delete.
- Trigger keys are `item.*` (subject is `InventoryItem`). Spec’s `inventory.*` / `purchase_order.*` names are the same events; the registry forbids mixed prefixes when a subject is declared.
- Declare `isRaised: false` until `EventEmitter::emit('item.…')` exists in `app/` outside `ModuleServiceProvider`.
- Register server module actions and the real client screen in the commit that ships UI (`ClientModuleScreensTest`).
- Bump `client/VERSION` and add a `VersionHistory` row in the **same** commit that ships UI.
- MySQL gate: `php artisan test` against a database whose name contains `testing`. Never assert JSON key order.
- Stay off Email, HR, Phone, Services.

---

## File structure (locked)

| Path | Responsibility |
|---|---|
| `database/migrations/2026_08_24_160000_create_inventory_tables.php` | Items, locations, balances, vendors, POs, receives, transfers, issues, adjustments |
| `app/Support/Inventory/InventoryEnums.php` | Unit, item status, location kind/status, PO status |
| `app/Support/Inventory/InventoryVisibility.php` | Constrain item/location queries like VehicleVisibility |
| `app/Support/Inventory/InventoryFinancials.php` | Strip cost keys from arrays |
| `app/Support/Inventory/InventoryMover.php` | Post a movement onto balances in one transaction |
| `app/Models/InventoryItem.php` | Catalogue row |
| `app/Models/InventoryLocation.php` | Place stock sits |
| `app/Models/InventoryBalance.php` | Qty on hand |
| `app/Models/InventoryVendor.php` | Who we buy from |
| `app/Models/InventoryPurchaseOrder.php` + `InventoryPurchaseOrderLine.php` | PO |
| `app/Models/InventoryReceive.php` + `InventoryReceiveLine.php` | Receive against a PO |
| `app/Models/InventoryTransfer.php` + `InventoryTransferLine.php` | A→B |
| `app/Models/InventoryIssue.php` + `InventoryIssueLine.php` | Out (job XOR note) |
| `app/Models/InventoryAdjustment.php` | Counted-as |
| `app/Policies/InventoryItemPolicy.php` | Place write + view reach |
| `app/Policies/InventoryLocationPolicy.php` | Same; van uniqueness is the model |
| `app/Policies/InventoryVendorPolicy.php` | Same tree |
| `app/Policies/InventoryPurchaseOrderPolicy.php` | View/create/edit; receive is edit |
| `app/Policies/InventoryTransferPolicy.php` | From-location edit; to-location visible |
| `app/Policies/InventoryIssuePolicy.php` | From-location edit; job in view |
| `app/Http/Controllers/Api/InventoryItemsController.php` | List/create/show/update/destroy |
| `app/Http/Controllers/Api/InventoryLocationsController.php` | List/create/show/update/destroy |
| `app/Http/Controllers/Api/InventoryStockController.php` | Balance list (Stock chip) |
| `app/Http/Controllers/Api/InventoryAdjustmentsController.php` | POST adjust |
| `app/Http/Controllers/Api/InventoryTransfersController.php` | POST transfer |
| `app/Http/Controllers/Api/InventoryVendorsController.php` | CRUD vendors |
| `app/Http/Controllers/Api/InventoryPurchaseOrdersController.php` | CRUD PO, open, cancel |
| `app/Http/Controllers/Api/InventoryReceivesController.php` | POST receive |
| `app/Http/Controllers/Api/InventoryIssuesController.php` | POST issue, list |
| `app/Providers/ModuleServiceProvider.php` | Replace inventory stub |
| `app/Support/Numbering/RecordNumberGenerator.php` | `ITEMS` `PURCHASE_ORDERS` `ISSUES` `TRANSFERS` |
| `app/Support/Settings/SettingRegistry.php` | `numbering.item_prefix` `numbering.po_prefix` |
| `app/Support/Files/Attachables.php` | Four wire types |
| `routes/api.php` | `/api/v1/inventory/…` |
| `client/lib/screens/inventory_screen.dart` | Chips + 25/75 |
| `client/lib/screens/inventory/` | Lists and panes |
| `client/lib/core/module_screens.dart` | Point at `InventoryScreen` |
| `tests/Feature/Inventory/` | PHP tests |
| `client/test/inventory_screen_test.dart` | Widget tests |
| `tests/Feature/Api/ComingSoonModulesTest.php` | Drop `inventory` from the provider |
| `client/test/coming_soon_tabs_test.dart` | Drop Inventory cases |

Do **not** add columns on `jobs`. Do **not** add BOMs, lots, serials, barcode, or auto-PO.

---

### Task 1: Enums and tables

**Files:**
- Create: `app/Support/Inventory/InventoryEnums.php`
- Create: `database/migrations/2026_08_24_160000_create_inventory_tables.php`
- Test: `tests/Feature/Inventory/InventoryTablesTest.php`

**Interfaces:**
- Produces: backed string enums with `values(): array` for `Rule::in`
- Produces: tables listed below; every table `id` uuid PK, `organization_id` FK cascade, timestamps

Enums:

| Enum | Values |
|---|---|
| `InventoryUnit` | `each` `box` `ft` `in` `gal` `lb` `kit` `other` |
| `InventoryItemStatus` | `active` `discontinued` |
| `InventoryLocationKind` | `warehouse` `shop` `cage` `receiving` `van` `other` |
| `InventoryLocationStatus` | `active` `closed` |
| `InventoryPurchaseOrderStatus` | `draft` `open` `received` `cancelled` |

SKU uniqueness: generated stored `catalogue_key` on items: `CONCAT(IFNULL(company_id,'org'), ':', IFNULL(franchise_id,'*'))`. Unique `(organization_id, catalogue_key, sku)` — `sku` nullable. Same pattern for location `name` unique inside the owning place (`catalogue_key` on locations too).

`inventory_items`: `company_id` uuid nullable FK companies nullOnDelete, `franchise_id` uuid nullable FK franchises nullOnDelete, `number` string 32, `sku` string 64 nullable, `name` string 191, `unit` string 16, `status` string 16, `reorder_at` decimal(12,3) nullable, `last_cost_minor` unsignedBigInteger nullable, `catalogue_key` stored. Unique `(organization_id, number)`. Index `(organization_id, company_id, franchise_id)`, `(organization_id, status)`.

`inventory_locations`: same place columns + `name` string 191, `kind` string 16, `status` string 16, `vehicle_id` uuid nullable unique FK vehicles nullOnDelete.

`inventory_balances`: `item_id` FK cascade, `location_id` FK cascade, `qty_on_hand` decimal(12,3) default 0. Unique `(item_id, location_id)`. Check qty ≥ 0 in the mover, not MySQL.

`inventory_vendors`: place columns, `name` string 191, `phone` string 40 nullable, `email` string 191 nullable.

`inventory_purchase_orders`: place columns, `vendor_id` FK restrict, `number` string 32, `status` string 16, `ordered_on` date nullable. Unique `(organization_id, number)`.

`inventory_purchase_order_lines`: `purchase_order_id` FK cascade, `item_id` FK restrict, `qty_ordered` decimal(12,3), `qty_received` decimal(12,3) default 0, `unit_cost_minor` unsignedBigInteger nullable.

`inventory_receives`: `purchase_order_id` FK cascade, `location_id` FK restrict, `received_on` date, `received_by` uuid nullable FK users nullOnDelete.

`inventory_receive_lines`: `receive_id` FK cascade, `purchase_order_line_id` FK restrict, `qty` decimal(12,3).

`inventory_transfers`: `from_location_id` `to_location_id` FK restrict, `number` string 32, `transferred_on` date, `user_id` uuid nullable. Unique `(organization_id, number)`.

`inventory_transfer_lines`: `transfer_id` FK cascade, `item_id` FK restrict, `qty` decimal(12,3).

`inventory_issues`: `location_id` FK restrict, `job_id` uuid nullable FK jobs nullOnDelete, `note` text nullable, `number` string 32, `issued_on` date, `user_id` uuid nullable. Unique `(organization_id, number)`.

`inventory_issue_lines`: `issue_id` FK cascade, `item_id` FK restrict, `qty` decimal(12,3).

`inventory_adjustments`: `location_id` FK restrict, `item_id` FK restrict, `qty_before` `qty_after` decimal(12,3), `reason` text, `adjusted_on` date, `user_id` uuid nullable.

- [ ] **Step 1:** Failing test that two items in the same company cannot share a SKU, and two companies in one org **can**.

```php
public function test_sku_is_unique_inside_a_place_not_the_organization(): void
{
    $alpha = $this->provisionTenant('Alpha');
    $coA = $alpha['company'];
    $coB = Company::factory()->create(['organization_id' => $alpha['organization']->id]);

    $this->insertItem($alpha, $coA, null, 'LINER-6');
    $this->insertItem($alpha, $coB, null, 'LINER-6'); // allowed

    $this->expectException(\Illuminate\Database\QueryException::class);
    $this->insertItem($alpha, $coA, null, 'LINER-6');
}
```

- [ ] **Step 2:** Run `php artisan test --filter=InventoryTablesTest`. Expected: FAIL, tables missing.
- [ ] **Step 3:** Implement enums + migration. `vehicle_id` unique. Franchise on an item must not be stored with a company that is not its parent (enforced in the model/controller, not MySQL).
- [ ] **Step 4:** Test van location unique: two locations with the same `vehicle_id` → query exception. Qty insert of `-1` is allowed at SQL (mover refuses later).
- [ ] **Step 5:** Commit: `Add inventory tables with per-place SKU uniqueness.`

---

### Task 2: Visibility helper

**Files:**
- Create: `app/Support/Inventory/InventoryVisibility.php`
- Test: `tests/Feature/Inventory/InventoryVisibilityTest.php`

**Interfaces:**
- Consumes: `ViewScope`, `User::reachableCompanies()`, `User::reachableFranchises()`
- Produces: `InventoryVisibility::constrain(Builder $query, User $user, ViewScope $scope): Builder` — same predicate as `VehicleVisibility` (org rows `company_id` null; company rows in soft-reach companies; franchise null = General, or franchise in `$scope->franchiseIds()`).

Copy the structure of `app/Support/Fleet/VehicleVisibility.php`. Do not invent a second reach engine.

- [ ] **Step 1:** Provision tenant with two companies, two franchises under company A. Org item, company-A General location, franchise-A location, franchise-B location, company-B location.

```php
public function test_a_franchise_user_sees_own_general_and_org_not_sibling_or_other_company(): void
{
    // sign in as franchise-A-only user, ViewScope franchise A
    // assert: org + company A general + franchise A — not franchise B, not company B
}
```

- [ ] **Step 2:** Run the test. Expected: FAIL, class missing.
- [ ] **Step 3:** Implement `InventoryVisibility` by adapting `VehicleVisibility` (same `companyIdsFor` / `softReachCompanyIds`).
- [ ] **Step 4:** Also test organization view sees every company’s items; company-B user does not see company-A catalogue items.
- [ ] **Step 5:** Commit: `Constrain inventory lists the same way as the fleet.`

---

### Task 3: Models and factories

**Files:**
- Create models listed above
- Create: `database/factories/InventoryItemFactory.php`, `InventoryLocationFactory.php`, `InventoryVendorFactory.php`
- Each tenant model: `HasUuids`, `BelongsToTenant`. Item, PO, vendor, issue: `HasAttachments` where spec §9 lists them.

**Interfaces:**
- Produces: `InventoryItem::displayName(): string` — `sku · name` when sku set, else name
- Produces: `InventoryItem::isEmpty(): bool` — no balance rows with qty≠0, no PO/issue/transfer/adjust lines
- Produces: `InventoryLocation::isEmpty(): bool` — no balances with qty≠0
- Produces: `InventoryBalance::qtyOnHand(string $itemId, string $locationId): string` (decimal as string)
- Saving: `sku === ''` → null; refresh `catalogue_key` is generated so do not write it.

- [ ] **Step 1:** `InventoryItem::factory()` in a provisioned tenant stamps `organization_id`.
- [ ] **Step 2:** `displayName()` with and without sku.
- [ ] **Step 3:** Implement models. Fillable explicit. Cast qty to `decimal:3`, dates `date`, enums as backed enums, cents `integer`.
- [ ] **Step 4:** Run `php artisan test --filter=InventoryItem`. Expected: PASS.
- [ ] **Step 5:** Commit: `Add inventory Eloquent models.`

---

### Task 4: Module registry — Inventory is no longer a stub

**Files:**
- Modify: `app/Providers/ModuleServiceProvider.php` — replace the `inventory` definition (`view` + exemption) with full actions + triggers + subject
- Modify: `tests/Feature/Api/ComingSoonModulesTest.php` — **remove** `inventory` from `modules()`. Keep web_management, phone, services. Keep order: fleet → ~~inventory~~ wait: inventory is still a real module in the registry, only not “coming soon”. Remove from ComingSoonModulesTest data provider **in this task** only if the client screen is real; otherwise keep it until Task 14.

Prefer: expand permissions now; leave ComingSoon PHP tests until Task 14 so Roles already shows create/edit while the tab still says coming soon **or** ship an empty real screen in Task 14 only. **This task:** expand the ModuleDefinition. Leave ComingSoonModulesTest including inventory until the client screen exists (Task 14) — `ComingSoonModulesTest` asserts `permissionKeys() === ["{key}.view"]`, so it **must** drop inventory in **this** task or it fails.

Drop inventory from `ComingSoonModulesTest::modules()` here. Client may still show ComingSoonScreen until Task 14; that is a screen, not the coming-soon *module* test. `coming_soon_tabs_test.dart` can keep Inventory cases until Task 14.

**Interfaces:**
- Produces: actions `view, create, edit, delete, financials`
- Produces: `subject: new AutomationSubject(InventoryItem::class, 'Item')`
- Produces: triggers (all `isRaised: false` until Task 13):

| key | label |
|---|---|
| `item.created` | Item added |
| `item.updated` | Item updated |
| `item.received` | Stock received |
| `item.issued` | Stock issued |
| `item.transferred` | Stock transferred |
| `item.adjusted` | Count adjusted |
| `item.ordered` | Purchase order opened |
| `item.purchase_received` | Purchase order fully received |
| `item.low` | Stock is low (**stays** `isRaised: false` — no sweep in this plan) |

- Produces: no automation actions that move stock (spec §12).

- [ ] **Step 1:** Test `ModuleRegistry::get('inventory')->permissionKeys()` contains `inventory.financials`.
- [ ] **Step 2:** Implement the ModuleDefinition. Remove `automationExemptBecause`.
- [ ] **Step 3:** Drop inventory from ComingSoonModulesTest provider. Fix order assertions: fleet → services still works; inventory remains in `all()` keys between fleet and services (do not remove it from the registry).
- [ ] **Step 4:** `php artisan test --filter=ComingSoonModulesTest` and `ModuleRegistryTest`. Expected: PASS.
- [ ] **Step 5:** Commit: `Grant inventory create, edit, delete, and financials.`

---

### Task 5: Items API

**Files:**
- Create: `app/Policies/InventoryItemPolicy.php` — clone `VehiclePolicy` gates (`viewAny`, `view`, `create(User, ?Company, ?Franchise)`, `createOnOrganization`, `update`, `delete`). Delete refuses unless `isEmpty()` with spec sentence.
- Create: `app/Http/Controllers/Api/InventoryItemsController.php`
- Create: `app/Support/Inventory/InventoryFinancials.php` — `withoutCosts(array $row): array` unsets `last_cost_minor`, `unit_cost_minor`, `lines.*.unit_cost_minor`
- Modify: `app/Support/Numbering/RecordNumberGenerator.php` — `ITEMS = 'inventory_item'`; prefix from `numbering.item_prefix`
- Modify: `app/Support/Settings/SettingRegistry.php` — `numbering.item_prefix` default `I-`
- Modify: `routes/api.php` — `Route::apiResource('inventory/items', InventoryItemsController::class);`
- Test: `tests/Feature/Inventory/InventoryItemsApiTest.php`

**Interfaces:**
- `GET /api/v1/inventory/items` — ViewScope + InventoryVisibility, paginated
- `POST` body: `company_id` nullable, `franchise_id` nullable, `sku`, `name`, `unit`, `reorder_at`. Number allocated in the create transaction.
- `PATCH` identity/status/reorder. Discontinue is status=`discontinued`.
- `DELETE` empty only: `This item has history, so it cannot be removed. Mark it discontinued instead.`
- Without `financials`, `last_cost_minor` absent.

- [ ] **Step 1:** Franchise user POST an item on a sibling company → 403, sentence about reach.
- [ ] **Step 2:** Duplicate SKU in the same company → 422 `This place already has an item with that SKU.`
- [ ] **Step 3:** Implement policy + controller + numbering. `InventoryFinancials` on show/index.
- [ ] **Step 4:** Admin create org-level item when view is organization. Company-only user cannot.
- [ ] **Step 5:** Commit: `Add the inventory item catalogue API.`

---

### Task 6: Locations API

**Files:**
- Create: `app/Policies/InventoryLocationPolicy.php`
- Create: `app/Http/Controllers/Api/InventoryLocationsController.php`
- Modify: `routes/api.php` — `inventory/locations`
- Test: `tests/Feature/Inventory/InventoryLocationsApiTest.php`

**Interfaces:**
- Same place rules as items.
- `kind=van` requires `vehicle_id`. Vehicle must be visible (VehicleVisibility) and have no location yet: `That vehicle already is a location.`
- Close: `status=closed` refused unless `isEmpty()`: `This location still has stock. Move or adjust it to zero first.`
- Change `company_id` refused unless empty: `Move the stock out first.`

- [ ] **Step 1:** Two van locations on one vehicle → refused.
- [ ] **Step 2:** Vehicle from another company → refused.
- [ ] **Step 3:** Implement.
- [ ] **Step 4:** Tests pass.
- [ ] **Step 5:** Commit: `Add inventory locations, including a van as a location.`

---

### Task 7: Balances, Stock list, adjust

**Files:**
- Create: `app/Support/Inventory/InventoryMover.php`
- Create: `app/Http/Controllers/Api/InventoryStockController.php`
- Create: `app/Http/Controllers/Api/InventoryAdjustmentsController.php`
- Create: `app/Policies/InventoryIssuePolicy.php` is later; adjust authorizes location `update`
- Test: `tests/Feature/Inventory/InventoryAdjustTest.php`

**Interfaces:**
- Produces: `InventoryMover::adjust(InventoryLocation $location, InventoryItem $item, string $countedAs, string $reason, User $user): InventoryAdjustment`
- Locks the balance row (`lockForUpdate`), computes delta, refuses delta 0, refuses result < 0 (should not happen if countedAs ≥ 0). Insert adjustment + upsert balance in one transaction.
- `GET /api/v1/inventory/stock` — join balances with visible items+locations. Query `low=1` filters `qty_on_hand <= items.reorder_at` where reorder_at is not null.
- `POST /api/v1/inventory/adjustments` `{ location_id, item_id, counted_as, reason }`
- Sentence: none extra beyond empty reason 422.

```php
public function test_adjust_sets_on_hand_and_writes_history(): void
{
    // location+item, POST counted_as 10, GET stock shows 10
    // POST counted_as 10 again → refused (delta zero)
}
```

- [ ] **Step 1:** Write the test. Expected FAIL.
- [ ] **Step 2:** Implement mover + endpoints. `counted_as` must be ≥ 0.
- [ ] **Step 3:** Stock list does not leak sibling-company balances.
- [ ] **Step 4:** Commit: `Post inventory adjustments onto on-hand.`

---

### Task 8: Transfers

**Files:**
- Create: `app/Policies/InventoryTransferPolicy.php`
- Create: `app/Http/Controllers/Api/InventoryTransfersController.php`
- Modify: `InventoryMover.php` — `transfer(...)`
- Modify: `RecordNumberGenerator` — `TRANSFERS = 'inventory_transfer'`, prefix `T-` (hardcoded like customers unless you add a setting; spec says prefix `T-`. Do **not** add a numbering setting unless SettingsReaders requires it — hardcoded `T-` in the generator match arm is enough.)
- Test: `tests/Feature/Inventory/InventoryTransferTest.php`

**Interfaces:**
- `POST /api/v1/inventory/transfers` `{ from_location_id, to_location_id, lines: [{ item_id, qty }] }`
- From: caller can `update` the from-location. To: visible, not a sibling company. Sentence: `Stock does not move to a company you do not write.`
- On-hand on from must cover each line: `There is not enough at this location.` Whole POST rolls back.
- Totals A+B unchanged.

- [ ] **Step 1:** Transfer 4 of 10 A→B; A=6 B=4; second transfer 7 → refused, A still 6.
- [ ] **Step 2:** Transfer to sibling company location → 403 that sentence.
- [ ] **Step 3:** Implement. Number allocated in the same transaction.
- [ ] **Step 4:** Commit: `Transfer stock between inventory locations in one save.`

---

### Task 9: Vendors, purchase orders, receive

**Files:**
- Create vendor/PO/receive policies and controllers
- Modify: `RecordNumberGenerator` — `PURCHASE_ORDERS = 'inventory_po'`; setting `numbering.po_prefix` default `PO-`
- Modify: `InventoryMover::receive(...)`
- Modify: `InventoryFinancials` to strip PO line costs
- Test: `tests/Feature/Inventory/InventoryPurchaseTest.php`

**Interfaces:**
- Vendor CRUD on the same tree as items.
- `POST /api/v1/inventory/purchase-orders` draft with lines. `POST .../open` draft→open. `POST .../cancel` only draft or open with all `qty_received=0`.
- `POST /api/v1/inventory/purchase-orders/{id}/receives` `{ location_id, lines: [{ purchase_order_line_id, qty }] }`
- Over-receive: `This order does not have that many left to receive.`
- Partial: PO stays `open`. Full: `received`.
- Receive into closed location: `This location is closed.`
- With `financials`, receive writes `items.last_cost_minor` from that line’s unit cost.

- [ ] **Step 1:** Order 10, receive 4, receive 7 → second refused; on-hand 4; status open. Receive 6 → on-hand 10, status received.
- [ ] **Step 2:** Cancel after a receive → refused.
- [ ] **Step 3:** Without `financials`, show omits `unit_cost_minor` and `last_cost_minor`.
- [ ] **Step 4:** Implement.
- [ ] **Step 5:** Commit: `Buy inventory on a purchase order and receive it.`

---

### Task 10: Issues

**Files:**
- Create: `app/Policies/InventoryIssuePolicy.php`
- Create: `app/Http/Controllers/Api/InventoryIssuesController.php`
- Modify: `InventoryMover::issue(...)`
- Modify: `RecordNumberGenerator` — `ISSUES = 'inventory_issue'`, prefix `IS-`
- Test: `tests/Feature/Inventory/InventoryIssueTest.php`

**Interfaces:**
- `POST /api/v1/inventory/issues` `{ location_id, job_id?, note?, lines: [{ item_id, qty }] }`
- XOR: `job_id` set **or** `note` non-empty. Else: `Say which job this is for, or why it left.`
- Job must exist, not deleted, and appear in the caller’s job list (same ViewScope as `GET /jobs`). Else: `That job is not in this view.` Naming a job does not require `jobs.edit`.
- Discontinued item: `This item is discontinued, so it cannot go out.`
- Closed location: `This location is closed.`
- Insufficient: `There is not enough at this location.`
- Do **not** write `jobs` columns.

- [ ] **Step 1:** Issue 3 of 10 to a note; on-hand 7. Issue 8 → refused.
- [ ] **Step 2:** Issue to a job in another franchise the user cannot see → that sentence.
- [ ] **Step 3:** Issue with neither job nor note → that sentence.
- [ ] **Step 4:** Implement.
- [ ] **Step 5:** Commit: `Issue inventory to a job or with a note.`

---

### Task 11: Attachables

**Files:**
- Modify: `app/Support/Files/Attachables.php`
- Test: existing `AttachmentsTest` will fail if a type is listed without `HasAttachments` — add the four types and the traits (Task 3 should already have traits on those models).

**Interfaces:**
- `'inventory_items' => InventoryItem::class`
- `'inventory_purchase_orders' => InventoryPurchaseOrder::class`
- `'inventory_issues' => InventoryIssue::class`
- `'inventory_vendors' => InventoryVendor::class`
- PO files: authorize `view` only if `financials` (policy `view` on PO already true; **override** PO policy `view` of attachments — spec: costs on a PO and the files on it require `financials` to view). Implement as `InventoryPurchaseOrderPolicy::view` allowing the PO header without financials, and a `viewMoney` or attachable gate: `AttachmentsController` uses `update`/`view` on the subject. Simplest honest version: without `financials`, `view` still allows the PO but `Attachables` upload/list on POs requires `financials` checked in the PO policy via `viewFinancials`. Match Fleet payments: `VehiclePaymentPolicy::view` requires `financials`.

Add `InventoryPurchaseOrderPolicy::view` that allows header; create `viewAttachments` used only if AttachmentsController supports it. **If AttachmentsController only calls `view`:** then PO `view` without financials must still hide files — list attachments endpoint authorizes `view`. Conflict.

**Resolution (lock this):** `GET` PO JSON omits an `attachments` include without `financials`. Direct `GET /attachments?attachable_type=inventory_purchase_orders` authorizes `view` on the PO — **change** `InventoryPurchaseOrderPolicy::view` to allow, and in `AttachmentsController` if type is `inventory_purchase_orders` also require `inventory.financials`. Prefer a dedicated check in AttachmentsController for that type only, copied from how vehicle payments do it.

Read `AttachmentsController` before coding. Follow the payments pattern exactly.

- [ ] **Step 1:** Test upload on an item (photo) with `edit`. Test PO attachment without `financials` is 403.
- [ ] **Step 2:** Implement.
- [ ] **Step 3:** Commit: `Attach files to inventory items, vendors, POs, and issues.`

---

### Task 12: Raise item events

**Files:**
- Call `EventEmitter::emit` from mover/controllers after the transaction commits (same pattern as vehicle writes)
- Flip `isRaised: true` on every trigger except `item.low`
- Test: `ModuleRegistryTest` already checks emit vs flag. Add `tests/Feature/Inventory/InventoryEventsTest.php` that a receive emits `item.received` carrying the item.

**Interfaces:**
- Consumes: existing EventEmitter
- `item.low` stays false

- [ ] **Step 1:** Receive → event row (or fake) with key `item.received`.
- [ ] **Step 2:** Implement emits. Flip flags.
- [ ] **Step 3:** `php artisan test --filter=ModuleRegistryTest`. Expected: PASS.
- [ ] **Step 4:** Commit: `Emit inventory item events when stock moves.`

---

### Task 13: Client — replace coming soon

**Files:**
- Create: `client/lib/screens/inventory_screen.dart`
- Create: `client/lib/screens/inventory/` — `stock_list.dart`, `item_list.dart`, `order_list.dart`, `location_list.dart`, `item_pane.dart`, `location_pane.dart`, `purchase_order_pane.dart`, `issue_pane.dart`, `add_item_pane.dart`, `add_location_pane.dart`
- Modify: `client/lib/core/module_screens.dart` — `InventoryScreen` instead of `ComingSoonScreen`
- Modify: `client/test/coming_soon_tabs_test.dart` — remove Inventory cases
- Create: `client/test/inventory_screen_test.dart`
- Modify: `client/VERSION` + `version_history.dart` + `version_history_test.dart` in **this** commit

**Interfaces:**
- Chips: Stock · Items · Orders · Locations (`TabBarLike`)
- 25/75 split; `splitAbove = 600` like Fleet
- Stock: GET `/inventory/stock`. Open item pane (balances + adjust)
- Items: GET `/inventory/items`. Add in the pane
- Orders: GET POs + issues. Open PO → receive form; issue is read-only
- Locations: GET `/inventory/locations`. Van: pick from GET `/vehicles` if `fleet_management.view`, else hide van kind
- Amounts: hide unit cost / last cost unless `session.me!.can('inventory.financials')`
- Failed fetch: `the request failed`, not an empty list
- 375px: no overflow

- [ ] **Step 1:** Widget test: granted `inventory.view` shows chips, not “Coming soon”.
- [ ] **Step 2:** Receive posts and Stock qty updates (mock client).
- [ ] **Step 3:** Implement screens. Wire module_screens.
- [ ] **Step 4:** `flutter test test/inventory_screen_test.dart test/coming_soon_tabs_test.dart test/version_history_test.dart`
- [ ] **Step 5:** Commit: `Replace the Inventory coming-soon tab with stock, items, orders, and locations.` (VERSION bump in this commit.)

---

### Task 14: STATUS and board

**Files:**
- Modify: `docs/EZBIZI-STATUS.md` — Inventory is live, coming-soon sentence removed
- Work board: tick Inventory steps; waiting_on empty

- [ ] **Step 1:** Update STATUS in the same pass as the UI commit if that commit has not already; otherwise this commit.
- [ ] **Step 2:** Do not deploy unless the owner says so.

---

## Spec coverage

| Spec § | Task |
|---|---|
| 1–2 tab/chips/not-manufacturing | 13 |
| 3–4 tree + visibility | 2, 5, 6 |
| 5 items | 1, 3, 5 |
| 6 locations / van | 1, 6 |
| 7 balances / Stock / Low | 7 |
| 8.1 adjust | 7 |
| 8.2 transfer | 8 |
| 8.3 vendor/PO/receive | 9 |
| 8.4 issue / job XOR note | 10 |
| 9 files | 11 |
| 10 financials | 5, 9, 13 |
| 11 permissions | 4 |
| 12 automations | 4, 12 |
| 13 screens | 13 |
| 14 refusals | 5–10 |
| 15 dates/money | 7–10 |
| 16 tests | each task |
| 17 order | task order above |

`item.low` sweep is explicitly out (declared, not raised).
