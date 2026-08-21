# Fleet Management Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.
>
> **Do not deploy, `git push production`, or SSH.** Only when Mia says so in that session.
>
> **Do not touch Email.** Another session owns it. Off limits: `app/Support/Email/`, `app/Http/Controllers/Api/*Mail*`, `app/Http/Controllers/Api/Mailbox*`, `client/lib/screens/email/`, mailbox models, `WatchMailboxes`. If a task seems to need those files, the task is wrong.

**Goal:** Replace the Fleet Management coming-soon tab with the register specified in `docs/superpowers/specs/2026-08-21-fleet-management-design.md`: vehicles on a company (optional franchise / pool), ownership and insurance periods, driver assignment, service log, payments, photos, and the six permission keys.

**Architecture:** One `vehicles` row per van. Child ledgers (ownership, insurance, assignments, service, payments) with at most one open row each, enforced by a generated `open` column that is `1` while the end date is null and `NULL` once closed — unique `(vehicle_id, open)` so two current drivers cannot hide behind SQL NULL. Display name is derived in `VehicleName`, never typed. Money is stripped in one presenter (`VehicleFinancials`) on every read. Files go through existing FileStore / Attachables. Automations emit from the same transaction as the write.

**Tech Stack:** Laravel 13 · PHP 8.4 · MySQL 8 (`ezbizi_testing`) · Flutter web · FileStore · ModuleRegistry · ViewScope · EventEmitter · RecordNumberGenerator

**Spec:** `docs/superpowers/specs/2026-08-21-fleet-management-design.md`

## Global Constraints

- Front door: `StartUp.md`. If documents disagree, `docs/EZBIZI-STATUS.md` wins; this spec wins on Fleet.
- TDD: red test first; delete the production line once and watch it fail; restore.
- User-facing sentences name what happened and what to do. No class names, `::`, or “immutable.” Spec §12 sentences are the refusals — copy them, do not paraphrase.
- Never copy A1 Tools code. Read it only for field checklists already in the spec.
- No modals. 375px: list or full-screen vehicle, Back returns to the list. Touch 44px.
- Colours from `client/lib/core/theme.dart`. No purple. No literal hex in widgets.
- Tab permission is `fleet_management.view`. Extra actions: `create` `edit` `delete` `assign` `financials`. Controls the caller cannot use are not drawn. Payments card omitted without `financials` (not a locked card).
- VIN unique per organization among non-null values. Empty VIN is null, not `''`.
- Calendar dates, not UTC midnights. USD cents. No FX.
- Create is one transaction: vehicle + first ownership period.
- `sold` / `returned` close the open ownership period on the same save. Buy-back is one save: status back to an on-the-road value **and** a new period.
- Delete is hard, and only when the vehicle has no ledger rows and no photos.
- MySQL gate: `php artisan test` against a database whose name contains `testing`. Never assert JSON key order.
- Register server module and client screen in the same commit (`ClientModuleScreensTest`).
- Triggers: declare `isRaised: false` until `EventEmitter::emit('vehicle.…')` exists in `app/` outside `ModuleServiceProvider`; `ModuleRegistryTest` checks the claim against source.
- Subject of the module is `Vehicle` — trigger keys **must** be `vehicle.*` (`ModuleDefinition::assertKeysMatchSubject`).
- Bump `client/VERSION` and add a `VersionHistory` row in the **same** commit that ships UI.
- Stay off Email files (list in the banner).

---

## File structure (locked)

| Path | Responsibility |
|---|---|
| `database/migrations/2026_08_21_120000_create_fleet_tables.php` | Six tables |
| `app/Support/Fleet/VehicleName.php` | Derived display name |
| `app/Support/Fleet/VehicleEnums.php` | Status, body, fuel, ownership kind, service kind, payment category/method, rate/premium periods, ended reasons |
| `app/Support/Fleet/VehicleFinancials.php` | Strip money from arrays; list of money keys |
| `app/Support/Fleet/VehicleVisibility.php` | List/query: company + pool rule against ViewScope |
| `app/Models/Vehicle.php` | Register row; `label` cached from VehicleName |
| `app/Models/VehicleOwnershipPeriod.php` | Open = current owned/rented |
| `app/Models/VehicleInsurancePolicy.php` | Open = current policy |
| `app/Models/VehicleAssignment.php` | Open = who has the keys |
| `app/Models/VehicleServiceVisit.php` | Service log row |
| `app/Models/VehiclePayment.php` | Money trail row |
| `app/Policies/VehiclePolicy.php` | Four gates + reach + assign/financials |
| `app/Policies/VehiclePaymentPolicy.php` | `view` requires `financials` (closes the receipt URL hole) |
| `app/Policies/VehicleOwnershipPeriodPolicy.php` | Delegates to vehicle view/update |
| `app/Policies/VehicleInsurancePolicyPolicy.php` | Delegates to vehicle view/update |
| `app/Policies/VehicleServiceVisitPolicy.php` | Delegates to vehicle view/update |
| `app/Policies/VehicleAssignmentPolicy.php` | Delegates assign to vehicle `assign` |
| `app/Http/Controllers/Api/VehiclesController.php` | List, create, show, update, destroy |
| `app/Http/Controllers/Api/VehicleAssignmentsController.php` | Assign, unassign, assignable drivers |
| `app/Http/Controllers/Api/VehicleOwnershipController.php` | Start a new period |
| `app/Http/Controllers/Api/VehicleInsuranceController.php` | Start a new policy |
| `app/Http/Controllers/Api/VehicleServiceController.php` | Log a visit |
| `app/Http/Controllers/Api/VehiclePaymentsController.php` | Record / list payments |
| `app/Providers/ModuleServiceProvider.php` | Replace fleet exemption with full declaration |
| `app/Support/Numbering/RecordNumberGenerator.php` | `VEHICLES`; format scope `Organization\|Company\|Franchise` |
| `app/Support/Settings/SettingRegistry.php` | `numbering.vehicle_prefix` default `V-` |
| `app/Support/Files/Attachables.php` | Five wire types |
| `app/Support/Automations/ActionRunner.php` | `WRITABLE` for Vehicle (non-money fields) |
| `routes/api.php` | `/api/v1/vehicles…` |
| `database/factories/VehicleFactory.php` | |
| `client/lib/core/module_screens.dart` | Map already exists; point at real screen |
| `client/lib/screens/fleet_screen.dart` | List + 25/75 split |
| `client/lib/screens/fleet/` | `vehicle_pane.dart`, `add_vehicle_pane.dart`, cards |
| `tests/Feature/Fleet/` | PHP tests |
| `client/test/fleet_*.dart` | Widget tests |
| `tests/Feature/Api/ComingSoonModulesTest.php` | Drop fleet from the provider |
| `client/test/coming_soon_tabs_test.dart` | Drop fleet cases |

Do **not** add `job.vehicle_id`. Do **not** add GPS, SMS, or dashboard widgets in this plan.

---

### Task 1: Name, enums, tables

**Files:**
- Create: `app/Support/Fleet/VehicleName.php`
- Create: `app/Support/Fleet/VehicleEnums.php`
- Create: `database/migrations/2026_08_21_120000_create_fleet_tables.php`
- Test: `tests/Feature/Fleet/VehicleNameTest.php`
- Test: `tests/Feature/Fleet/FleetTablesTest.php`

**Interfaces:**
- Produces: `VehicleName::derive(?int $year, ?string $make, ?string $model, ?string $plate, ?string $vin): string`
- Produces: `VehicleEnums::*` backed string enums with a `values(): array` used by validation `Rule::in`

Display name degrade (spec §5), pinned so the form preview and the stored `label` cannot drift:

```
{year} {make} {model} · {plate}  →  description  →  plate  →  VIN  →  Unidentified vehicle
```

Schema — every table: `id` uuid PK, `organization_id` FK cascade, timestamps unless noted.

`vehicles`: `company_id` FK companies restrict, `franchise_id` uuid nullable FK franchises nullOnDelete, `number` string 32, `label` string 191, `year` unsignedSmallInteger nullable, `make` `model` `plate` `vin` `colour` string nullable, `body_type` string 16, `fuel_type` string 16, `odometer_miles` unsignedInteger nullable, `next_service_on` date nullable, `next_service_miles` unsignedInteger nullable, `status` string 32. Unique `(organization_id, number)`. Unique `(organization_id, vin)` — `vin` nullable. Index `(organization_id, company_id, franchise_id)`, `(organization_id, status)`.

`vehicle_ownership_periods`: `vehicle_id` FK cascade, `kind` (`owned`/`rented`), `counterparty` string nullable, `started_on` date, `ended_on` date nullable, `ended_reason` string 32 nullable, money columns nullable unsignedBigInteger cents + string helpers (lienholder, loan_account, loan_payoff_date date, rental_rate_period, rental_account_number, rental_payment_terms, rental_payment_method). Generated stored `open` tinyint nullable: `CASE WHEN ended_on IS NULL THEN 1 ELSE NULL END`. Unique `(vehicle_id, open)`.

`vehicle_insurance_policies`: `vehicle_id`, `carrier`, `policy_number` nullable, `coverage` text nullable, `started_on` date, `expires_on` date nullable, `ended_on` date nullable, `premium_cents` unsignedBigInteger nullable, `premium_period` string 16 nullable. Same `open` generated unique.

`vehicle_assignments`: `vehicle_id`, `user_id` FK users restrict, `assigned_at` timestamp, `released_at` timestamp nullable, `note` text nullable. Generated `open`: `CASE WHEN released_at IS NULL THEN 1 ELSE NULL END`. Unique `(vehicle_id, open)`.

`vehicle_service_visits`: `vehicle_id`, `kind`, `occurred_on` date, `odometer_miles` unsignedInteger nullable, `performed_by` string nullable, `notes` text nullable, `next_service_on` date nullable, `next_service_miles` unsignedInteger nullable.

`vehicle_payments`: `vehicle_id`, `category`, `amount_cents` unsignedBigInteger, `occurred_on` date, `method` string 32, `note` text nullable, `ownership_period_id` `insurance_policy_id` `service_visit_id` uuid nullable FKs nullOnDelete. Check in the model, not MySQL: at most one of the three links is set.

Enums (all `string` backed):

| Enum | Values |
|---|---|
| `VehicleStatus` | `active` `in_shop` `out_of_service` `sold` `returned` |
| `VehicleBodyType` | `van` `truck` `car` `suv` `trailer` `other` |
| `VehicleFuelType` | `gas` `diesel` `hybrid` `electric` `other` |
| `VehicleOwnershipKind` | `owned` `rented` |
| `VehicleEndedReason` | `sold` `returned` `bought` `replaced` `other` |
| `VehicleServiceKind` | `oil` `tires` `brakes` `repair` `inspection` `registration` `other` |
| `VehiclePaymentCategory` | `purchase` `loan` `registration` `insurance` `maintenance` `rental` `toll` `deposit` `other` |
| `VehiclePaymentMethod` | `cash` `check` `credit_card` `debit_card` `ach` `net_30` `other` |
| `VehicleRatePeriod` | `day` `week` `month` |
| `VehiclePremiumPeriod` | `month` `six_months` `year` |
| `VehicleRentalTerms` | `due_on_receipt` `net_15` `net_30` `net_45` `net_60` |

- [ ] **Step 1: Failing name tests**

```php
public function test_name_degrades_through_plate_then_vin(): void
{
    $this->assertSame(
        '2021 Ford Transit · KGN-4412',
        VehicleName::derive(2021, 'Ford', 'Transit', 'KGN-4412', '1FT…'),
    );
    $this->assertSame('KGN-4412', VehicleName::derive(null, null, null, 'KGN-4412', '1FT'));
    $this->assertSame('1FT', VehicleName::derive(null, null, null, null, '1FT'));
    $this->assertSame('Unidentified vehicle', VehicleName::derive(null, null, null, null, null));
}
```

- [ ] **Step 2:** Run `php artisan test --filter=VehicleNameTest`. Expected: FAIL, class missing.
- [ ] **Step 3:** Implement `VehicleName` and `VehicleEnums`. Then migration. For generated `open` columns use `storedAs` with the CASE expression above — do not unique on nullable `ended_on` (MySQL allows several NULLs; that is how two current drivers would both insert).
- [ ] **Step 4:** `FleetTablesTest` against MySQL: insert two ownership rows with `ended_on` null on the same vehicle → query exception. Two rows with one ended → allowed. Same for assignments (`released_at`) and insurance.
- [ ] **Step 5:** Commit: `Add fleet tables with one-open-row uniqueness for ownership, insurance, and assignment.`

---

### Task 2: Models and factory

**Files:**
- Create the six models listed above
- Create: `database/factories/VehicleFactory.php` (and period/assignment factories as needed by later tests, or states on VehicleFactory)
- Each tenant model: `HasUuids`, `BelongsToTenant`. Vehicle also `HasAttachments` (photos). Child file-bearing models also `HasAttachments`.
- Vehicle implements `HoldsPersonalData` if the current driver name is stored only via `user_id` — declare `user_id` on **assignments** as identity (same shape as `EmploymentEvent`). Payments and counterparties are commercial; still declare what is personal (driver).

**Interfaces:**
- Produces: `Vehicle::openOwnership(): ?VehicleOwnershipPeriod`, `openInsurance()`, `openAssignment()`
- Produces: `Vehicle::refreshLabel(): void` — sets `label` from `VehicleName::derive` on saving identity fields
- Produces: `Vehicle::isEmpty(): bool` — no periods, assignments, visits, payments, or attachments

```php
protected static function booted(): void
{
    static::saving(function (Vehicle $vehicle): void {
        $vehicle->label = VehicleName::derive(
            $vehicle->year, $vehicle->make, $vehicle->model, $vehicle->plate, $vehicle->vin,
        );
        if ($vehicle->vin === '') {
            $vehicle->vin = null;
        }
    });
}
```

- [ ] **Step 1:** Test `Vehicle::factory()` in a provisioned tenant stamps `organization_id` and derives `label` from year/make/model/plate.
- [ ] **Step 2:** Test `openOwnership()` returns the row with `ended_on` null, not a closed one.
- [ ] **Step 3:** Implement models. Fillable lists explicit. Cast dates as `date`, cents as `integer`, enums as the backed enums.
- [ ] **Step 4:** Run `php artisan test --filter=VehicleFactory` / model tests. Expected: PASS.
- [ ] **Step 5:** Commit: `Add fleet Eloquent models with derived labels and open-ledger helpers.`

---

### Task 3: Module registry — Fleet is no longer a stub

**Files:**
- Modify: `app/Providers/ModuleServiceProvider.php` — replace the `fleet_management` `ModuleDefinition` that only has `view` + `automationExemptBecause`
- Modify: `tests/Feature/Api/ComingSoonModulesTest.php` — **remove** `fleet_management` from `modules()`. Web Management and Phone stay.
- Modify: `client/test/coming_soon_tabs_test.dart` — remove the two Fleet tests (the real screen lands in Task 14; until then `module_screens.dart` still points at `ComingSoonScreen` — that is OK for one task if this task only changes the **server** declaration and the coming-soon **PHP** test. To keep `ClientModuleScreensTest` green, leave the Dart map pointing at ComingSoonScreen until Task 14, **or** point it at an empty `FleetScreen` that says there are no vehicles yet. Prefer the empty real screen in this same commit so Roles already showing Assign/Financials matches a tab that is no longer “coming soon.”)

**Interfaces:**
- Produces: module key `fleet_management`, actions `view, create, edit, delete, assign, financials`
- Produces: subject `new AutomationSubject(Vehicle::class, 'Vehicle')`
- Produces: triggers (all `isRaised: false` until Task 13):

| key | label |
|---|---|
| `vehicle.created` | Vehicle added |
| `vehicle.updated` | Vehicle updated |
| `vehicle.status_changed` | Status changed |
| `vehicle.assigned` | Keys handed over |
| `vehicle.unassigned` | Keys taken back |
| `vehicle.ownership_started` | Ownership period started |
| `vehicle.insurance_started` | Insurance policy started |
| `vehicle.service_logged` | Service logged |
| `vehicle.payment_recorded` | Payment recorded |
| `vehicle.insurance_expiring` | Insurance expiring (stays `isRaised: false` — no sweep in this plan) |
| `vehicle.service_due` | Service due (stays `isRaised: false`) |

- Produces: actions `vehicle.assign_to_user`, `vehicle.unassign`, `vehicle.change_status`, `vehicle.update_field` (engine will run the generic ones in Task 13)

Every trigger/action needs a label **and** a description ending in `.` (`ModuleRegistryTest`).

- [ ] **Step 1:** Run `php artisan test --filter=ComingSoonModulesTest`. Currently fleet asserts `permissionKeys() === [view]` and a non-empty exemption. After the declaration change those assertions must not run on fleet.
- [ ] **Step 2:** Change the provider. Drop `automationExemptBecause`. Add subject, actions, triggers (`isRaised: false`), automation actions.
- [ ] **Step 3:** Remove fleet from `ComingSoonModulesTest::modules()`. Add `tests/Feature/Fleet/FleetModuleTest.php` asserting the six permission keys, the Vehicle subject, and that `vehicle.insurance_expiring` is `isRaised: false`.
- [ ] **Step 4:** Run `php artisan test --filter=ModuleRegistryTest` and `ComingSoonModulesTest` and `FleetModuleTest` and `ClientModuleScreensTest`. All PASS.
- [ ] **Step 5:** Commit: `Declare Fleet as a real module with assign, financials, and vehicle automations.`

---

### Task 4: Vehicle numbers for pool vehicles

**Files:**
- Modify: `app/Support/Numbering/RecordNumberGenerator.php` — `next(string $sequenceKey, Organization|Company|Franchise $scope)` (SettingsResolver already accepts all three)
- Modify: `app/Support/Settings/SettingRegistry.php` — `numbering.vehicle_prefix`, default `V-`, in effect (no `notYetInEffect` — this task is the reader; `SettingReaders` fails either way)
- Test: existing numbering tests must still pass with a Franchise
- Test: `tests/Feature/Fleet/VehicleNumberingTest.php`

**Interfaces:**
- Produces: `RecordNumberGenerator::VEHICLES = 'vehicle'`
- Produces: `next(RecordNumberGenerator::VEHICLES, $company)` works for pool vehicles

```php
$prefix = match ($sequenceKey) {
    self::JOBS      => (string) $this->settings->get('numbering.job_prefix', $scope),
    self::CUSTOMERS => 'C-',
    self::VEHICLES  => (string) $this->settings->get('numbering.vehicle_prefix', $scope),
    default         => '',
};
```

- [ ] **Step 1:** Failing test: provision tenant, `next(VEHICLES, $company)` returns a string starting `V-` and a second call is different. Two companies in one org share the sequence (org-wide), so they do not both get `V-00001`.
- [ ] **Step 2:** Run it; FAIL (constant missing / type error on Company).
- [ ] **Step 3:** Widen the type, add the setting, add the match arm. Do not change job/customer behaviour.
- [ ] **Step 4:** Run `php artisan test --filter=VehicleNumberingTest` and the existing job numbering tests. PASS.
- [ ] **Step 5:** Commit: `Number vehicles per organization and take the prefix from the company when the van is in the pool.`

---

### Task 5: Policy, visibility, financials helper

**Files:**
- Create: `app/Policies/VehiclePolicy.php`
- Create: `app/Support/Fleet/VehicleVisibility.php`
- Create: `app/Support/Fleet/VehicleFinancials.php`
- Create child policies listed in the file map
- Test: `tests/Feature/Fleet/VehicleVisibilityTest.php`
- Test: `tests/Feature/Fleet/VehiclePolicyTest.php`
- Test: `tests/Feature/Fleet/VehicleFinancialsTest.php`

**Interfaces:**
- Produces: `VehicleVisibility::constrain(Builder $query, User $user, ViewScope $scope): Builder`
- Produces: `VehicleFinancials::keys(): array` (every money attribute name)
- Produces: `VehicleFinancials::strip(array $payload, User $user): array` — if the user lacks `fleet_management.financials`, unset those keys and unset `payments`
- Produces: `VehiclePolicy::assign` and `financials` methods (Laravel will not call them unless we `authorize('assign', $vehicle)`)

Visibility (spec §4), already intersected with reach:

- `company_id` ∈ companies the view covers ∩ `user->reachableCompanies()`
- and (`franchise_id` is null **or** `franchise_id` ∈ `$scope->franchiseIds()`)
- Franchise view: pool of **that franchise’s company** only, plus vehicles tagged to that franchise
- Empty reach → matches nothing (same as jobs)

Policy:

| method | permission | reach |
|---|---|---|
| viewAny | `fleet_management.view` | — |
| view | `view` | company reachable; franchise null or reachable |
| create($user, Company $company, ?Franchise $franchise) | `create` | `canActOnCompany`; franchise belongs to company and is reachable if set |
| update | `edit` | same as view |
| delete | `delete` | same as view; emptiness is **not** the policy — controller 422 |
| assign | `assign` | same as view |
| financials | `financials` | same as view |

`VehiclePaymentPolicy::view` = vehicle view **and** `financials`, sentence: `Amounts on the fleet are hidden for your role.` Other child policies `Gate::inspect('view'|'update', $parentVehicle)`. Assignment child: `assign` not `update`.

- [ ] **Step 1:** Visibility tests with `BuildsTenants`: two companies; franchise-only user sees own franchise + **that** company’s pool, not the sibling company’s pool, not the other franchise’s tagged vans.
- [ ] **Step 2:** Policy test: `userWithRole(..., ['fleet_management.view'])` is forbidden to assign; with `assign` is allowed. Financials strip removes `purchase_price_cents` and `payments`.
- [ ] **Step 3:** Implement. Do not add a new Refusal helper unless a sentence does not fit `permission` / `noReach`; financials sentence may be `Response::deny('Amounts on the fleet are hidden for your role.')` so the client can show it.
- [ ] **Step 4:** Run the three test files. PASS.
- [ ] **Step 5:** Commit: `Authorize fleet by company reach, including the company pool, and strip money without financials.`

---

### Task 6: Create and show

**Files:**
- Create: `app/Http/Controllers/Api/VehiclesController.php` — `store`, `show`
- Modify: `routes/api.php` — inside the authenticated `v1` group, after jobs:

```php
Route::apiResource('vehicles', VehiclesController::class);
```

- Test: `tests/Feature/Api/VehiclesApiTest.php`

**Interfaces:**
- `POST /api/v1/vehicles` body: identity fields + `company_id` + optional `franchise_id` + `ownership: { kind, counterparty, started_on, …money }`
- `GET /api/v1/vehicles/{vehicle}` — vehicle + open and historical ledgers; money present only with `financials`
- Presenter on the controller or `Vehicle::present(User $user): array` calling `VehicleFinancials::strip`

Create transaction:

1. Authorize `create` with company + franchise.
2. Franchise, if set, must belong to that company (`422` spec sentence: `That place is not part of this company.`).
3. VIN unique in org (`This organization already has a vehicle with that VIN.`).
4. `ownership.kind` required (`A vehicle has to be owned or rented. Say which, then save.`).
5. Allocate number with `RecordNumberGenerator::VEHICLES` against `$franchise ?? $company`.
6. Insert vehicle (status default `active`) + first ownership period (`started_on` default today).
7. Do **not** emit yet (Task 13). Tests in this task must not require outbox rows.

Show: `authorize view`; 404 if visibility would hide it (policy already 404s other orgs).

- [ ] **Step 1:** Failing test: signed-in admin `postJson('/api/v1/vehicles', […])` → 201, one vehicle, one ownership, `label` derived, `number` starts `V-`. Same VIN again → 422 with the spec sentence. POST without ownership → 422 spec sentence.
- [ ] **Step 2:** Test show without `financials` has no `purchase_price_cents` / no `payments` key; with `financials` they appear.
- [ ] **Step 3:** Implement store/show. `existsInTenant` pattern from `JobsController`.
- [ ] **Step 4:** Run `php artisan test --filter=VehiclesApiTest`. PASS.
- [ ] **Step 5:** Commit: `Create a vehicle and its first ownership period in one request.`

---

### Task 7: Update, status close, delete

**Files:**
- Modify: `VehiclesController.php` — `update`, `destroy`
- Test: same `VehiclesApiTest.php`

Update may change identity, `franchise_id`, `odometer_miles`, next-due, `status`. Changing `kind` is **not** an update field — Task 10.

When `status` becomes `sold`: open ownership must be `owned`, else 422 `Sold is for a vehicle the company owns. Return a rental instead.` Then set `ended_on` today, `ended_reason` `sold`.

When `status` becomes `returned`: open kind must be `rented`, else 422 `Return is for a rental. Mark an owned vehicle sold instead.` Close similarly.

Buy-back: status from `sold`/`returned` to `active` (or `in_shop` / `out_of_service`) **requires** a new `ownership` object on the same request. Either half alone → 422.

Delete: if `!$vehicle->isEmpty()` → 422 `This vehicle has history, so it cannot be removed. Mark it sold or returned instead.` Else hard delete (files go with `HasAttachments`).

- [ ] **Step 1:** Tests for sold-on-rental 422, sold-on-owned closes period, delete with a visit 422, delete empty 204.
- [ ] **Step 2:** Run; FAIL (methods missing).
- [ ] **Step 3:** Implement. Status change and period close in one DB transaction.
- [ ] **Step 4:** PASS.
- [ ] **Step 5:** Commit: `Close ownership when a vehicle is sold or returned, and refuse delete once it has history.`

---

### Task 8: List and filters

**Files:**
- Modify: `VehiclesController::index`
- Test: `tests/Feature/Api/VehicleListApiTest.php`

Query params: `search` (label, plate, vin, number, current assignee name), `ownership` (`owned`/`rented` — filter by **open** period kind), `status[]` or comma list, `unassigned` bool, `due_soon` bool (date within 30 days **or** odometer within 500 miles of `next_service_miles` **or** either already past).

Default status set when the client sends none: `active`, `in_shop`, `out_of_service`. Client “include ended” sends `sold,returned` too.

Always apply `VehicleVisibility`. Paginate like customers.

Failed search is a 5xx/4xx the client already treats as “the request failed” — do not catch and return empty.

- [ ] **Step 1:** Tests: franchise viewer sees pool + own tag; due_soon hits a vehicle with `next_service_on` yesterday; default index hides `sold`.
- [ ] **Step 2–4:** Implement, run, pass.
- [ ] **Step 5:** Commit: `List vehicles with pool visibility, ownership, status, unassigned, and due-soon filters.`

---

### Task 9: Assign and unassign

**Files:**
- Create: `app/Http/Controllers/Api/VehicleAssignmentsController.php`
- Routes:

```php
Route::get('vehicles/drivers', [VehicleAssignmentsController::class, 'drivers']);
Route::post('vehicles/{vehicle}/assignments', [VehicleAssignmentsController::class, 'store']);
Route::post('vehicles/{vehicle}/unassign', [VehicleAssignmentsController::class, 'unassign']);
```

`drivers` is **not** nested under `{vehicle}` so it does not collide with id `drivers`. Query: `company_id`. Permission: `fleet_management.assign`. Returns active users who `canActOnCompany` that company (id, name). Do not require `hr.view`.

`store`: `user_id`, optional `note`. Authorize `assign`. User must be active and `canActOnCompany($vehicle->company)` else 422 `You can assign people who work in this company. This person does not.` If an open assignment exists, set `released_at` now then insert, one transaction.

`unassign`: if nobody has the keys → 422 `Nobody has the keys.`

- [ ] **Step 1:** Tests: viewer without assign → 403; assign sibling-company user → 422 spec sentence; reassign releases previous; unassign empty → 422.
- [ ] **Step 2–4:** Implement, run, pass.
- [ ] **Step 5:** Commit: `Assign and unassign vehicle keys to people in the company subtree.`

---

### Task 10: Ownership and insurance periods

**Files:**
- Create: `VehicleOwnershipController`, `VehicleInsuranceController`
- Routes: `POST vehicles/{vehicle}/ownership`, `POST vehicles/{vehicle}/insurance`

Starting a period: authorize `update` on the vehicle. Close the open row (`ended_on` today, reason from the request or `replaced`) and insert the new open row, one transaction. Status must not stay `sold`/`returned` while opening — if status is ended, this endpoint is the wrong door; use update-with-ownership (Task 7 buy-back).

Insurance: new open policy closes the previous. Fields: carrier, policy_number, coverage, started_on, expires_on, money if financials.

Without `financials`, money keys on the request are ignored (not 403 — they cannot set what they cannot see). With `financials`, they persist.

Papers: not this task (Task 12).

- [ ] **Step 1:** Tests: owned → rented closes owned; two open insurance rows cannot exist after two POSTs; money on ownership ignored without financials.
- [ ] **Step 2–4:** Implement, run, pass.
- [ ] **Step 5:** Commit: `Start ownership and insurance periods by closing the open row, never by overwriting it.`

---

### Task 11: Service visits and payments

**Files:**
- Create: `VehicleServiceController`, `VehiclePaymentsController`
- Routes: `POST/GET vehicles/{vehicle}/service`, `POST/GET vehicles/{vehicle}/payments`

Service: authorize `update`. Insert visit. If `odometer_miles` is greater than `vehicle.odometer_miles` (or vehicle is null), write through. If the visit includes next-due fields, write through to the vehicle.

Payments: authorize `financials` (and view for GET). List omitted entirely (403) without financials — the client will not draw the card. Optional link to exactly one of ownership/insurance/service ids, which must belong to this vehicle.

- [ ] **Step 1:** Tests: visit with higher odometer updates the vehicle; payment GET as view-only role 403; payment POST without financials 403; linked policy id from another vehicle 422.
- [ ] **Step 2–4:** Implement, run, pass.
- [ ] **Step 5:** Commit: `Log fleet service visits and payments, rolling odometer and next-due from the visit.`

---

### Task 12: Attachables

**Files:**
- Modify: `app/Support/Files/Attachables.php` — add:

```php
'vehicles' => Vehicle::class,
'vehicle_ownership_periods' => VehicleOwnershipPeriod::class,
'vehicle_insurance_policies' => VehicleInsurancePolicy::class,
'vehicle_service_visits' => VehicleServiceVisit::class,
'vehicle_payments' => VehiclePayment::class,
```

- Existing `AttachmentsController` + morph map already read this list. Child models must `use HasAttachments`. AttachmentsTest (or a new `FleetAttachmentsTest`) must cover: photo on vehicle with `view`; insurance PDF with `view`; **payment receipt 403 without financials**, 200 with.

- [ ] **Step 1:** Failing test: upload `attachable_type=vehicle_payments` as a view-only user → 403 spec money sentence; as financials role → 201.
- [ ] **Step 2–4:** Implement types + policies already from Task 5. Run `php artisan test --filter=Attachments` and the new test. PASS. (`Attachables` comment says adding a type without the trait fails AttachmentsTest — add the trait in this task if Task 2 did not.)
- [ ] **Step 5:** Commit: `Attach fleet photos to the vehicle and papers to the period, visit, or payment they belong to.`

---

### Task 13: Raise automations and wire actions

**Files:**
- Modify: the controllers from Tasks 6–11 to `$this->events->emit(...)` **inside** the same DB transaction as the write
- Modify: `ModuleServiceProvider` — set `isRaised: true` on every trigger except `vehicle.insurance_expiring` and `vehicle.service_due`
- Modify: `app/Support/Automations/ActionRunner.php` `WRITABLE`:

```php
\App\Models\Vehicle::class => [
    'plate' => 'Plate',
    'colour' => 'Colour',
    'odometer_miles' => 'Odometer (miles)',
    'next_service_on' => 'Next service date',
    'next_service_miles' => 'Next service miles',
],
```

Do **not** put cents fields in WRITABLE.

Emit map:

| Event | Where |
|---|---|
| `vehicle.created` | store |
| `vehicle.updated` | update when identity/franchise/odometer/next-due changed and status did not (if both, emit updated **and** status_changed) |
| `vehicle.status_changed` | update when status changed; payload `old` + `new` |
| `vehicle.assigned` / `unassigned` | assignment controller |
| `vehicle.ownership_started` | ownership POST and create’s first period **and** buy-back |
| `vehicle.insurance_started` | insurance POST |
| `vehicle.service_logged` | service POST |
| `vehicle.payment_recorded` | payment POST |

Payloads must go through `AuditLogger::redact` (EventEmitter already does). Do not put loan account numbers in payload if they are marked sensitive; cents may appear on `payment_recorded` (admin-authored rules).

Implement generic assign/unassign/change_status in ActionRunner the same way job status is done, **or** declare the actions and leave `implemented` false until a follow-up — spec says rules may assign/unassign/change status. Prefer implementing: `vehicle.change_status` sets status through the same close-period path as the controller (extract a `VehicleStatus::apply(Vehicle, VehicleStatus, ?array $ownership): void` used by both).

- [ ] **Step 1:** Test create inserts `automation_events` with `event_key = vehicle.created`. Test `ModuleRegistryTest` still green after flipping `isRaised`.
- [ ] **Step 2:** Emit from controllers. Flip flags. Extract status apply so a rule cannot mark a rental `sold` without the 422.
- [ ] **Step 3:** Run `php artisan test --filter=ModuleRegistryTest` and a new `FleetAutomationsTest`. PASS.
- [ ] **Step 4:** Commit: `Raise fleet automation events from the write path and allow rules to change non-money fields.`

---

### Task 14: Client — list, 25/75, add

**Files:**
- Modify: `client/lib/core/module_screens.dart` — `fleet_management` builds `FleetScreen` not `ComingSoonScreen`
- Create: `client/lib/screens/fleet_screen.dart`
- Create: `client/lib/screens/fleet/add_vehicle_pane.dart`
- Create: `client/lib/screens/fleet/vehicle_list.dart`
- Test: `client/test/fleet_screen_test.dart`
- Modify: `client/test/coming_soon_tabs_test.dart` — fleet cases already removed in Task 3; if not, remove them here

Layout (spec §11, mockups session 1655):

- Width ≥ ~700: if nothing selected, list is full width of the tab (plus Add). If a vehicle (or Add) is selected, `Row`: list `flex: 1`, pane `flex: 3` (25/75).
- Width < 375-ish (use `maxWidth < 600` as the existing screens do if they have a breakpoint — match Jobs/Customers; do not invent a new number if one exists): selected vehicle is the whole body with a Back control that clears selection.
- Filters: Owned, Rented, Active, In shop, Out of service, Sold, Returned, Unassigned, Due soon. No status chip → send no `status` (server default hides sold/returned).
- Search box. Failed load: existing error pattern (`the request failed`), not an empty list.
- Empty success: there are no vehicles; Add if `create`.
- Add: same pane, identity + “How do we hold it?” Owned/Rented card, one Save. Cancel clears selection. No modal.
- `Refused` / hide Add without `create`. Hide Assign without `assign`.

API via `ApiClient` — add methods next to customers, same error surfacing.

- [ ] **Step 1:** Widget test: pump Fleet with a granted module; expect no `Coming soon`; expect search; tap Add; expect “How do we hold it?”
- [ ] **Step 2:** `flutter test test/fleet_screen_test.dart` — FAIL until the screen exists.
- [ ] **Step 3:** Implement. No `showDialog`. No literal colours.
- [ ] **Step 4:** `flutter analyze` + the new test + `coming_soon_tabs_test.dart`. PASS.
- [ ] **Step 5:** Commit: `Replace the Fleet coming-soon tab with a list and a 25/75 add pane.`

---

### Task 15: Client — vehicle cards and version stamp

**Files:**
- Create: `client/lib/screens/fleet/vehicle_pane.dart` and small card widgets (identity, assignment, ownership, insurance, service, payments, photos)
- Modify: `client/lib/core/version_history.dart` + `client/VERSION` **in this commit**
- Test: `client/test/fleet_vehicle_pane_test.dart`

Cards in spec order. Photos use existing attachments endpoints (`attachable_type=vehicles`). Papers: upload with the child type. Payments card **absent** when `/me` lacks `fleet_management.financials` — assert `findsNothing`.

375px: Back from pane to list (Task 14 breakpoint).

- [ ] **Step 1:** Test: session without financials → no Payments heading; with financials → heading present. Test 375 width: open a vehicle, expect Back, not a 25% list.
- [ ] **Step 2–4:** Implement, analyze, pass.
- [ ] **Step 5:** Bump VERSION (next after whatever `client/VERSION` is at commit time — do not assume 0049; Email may have moved). Add `VersionHistory` row: fleet register, owned/rented periods, insurance, service, assignment, payments hidden without financials. Commit: `Open a vehicle as one scrolling page of cards, and stamp the Fleet release.`

---

## Spec coverage (self-review)

| Spec section | Task |
|---|---|
| §3–5 company + pool + derived name + VIN + status | 1, 2, 5, 6, 7 |
| §6 ledgers, one open row | 1 (generated unique), 10, 11 |
| §7 files | 12 |
| §8 financials API strip | 5, 6, 11, 12, 15 |
| §9 six permissions, coming-soon gone | 3, 14 |
| §10 automations + unraised clock | 3, 13 |
| §11 25/75, one scroll, add one save, 375px | 14, 15 |
| §12 sentences | 6, 7, 9 |
| §13 dates / USD | 1 (date columns), 11 (cents) |
| §14 tests listed | spread across 5–15 |
| §16 out of v1 (GPS, SMS, job link, clock sweep) | not scheduled |
| Email isolation | banner + every task |

No remaining spec requirement without a task. `vehicle.insurance_expiring` / `vehicle.service_due` stay declared and not raised, as specified.
