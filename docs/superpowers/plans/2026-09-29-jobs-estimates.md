# Estimates in Jobs (Billing Phase 1, part 2): Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Estimates become EzBizi records inside Jobs: numbered, on a customer (a job is
optional), made of lines, moving draft → sent → accepted/declined, with an
`estimate.accepted` automation trigger. Every estimate action needs the Jobs Financials
toggle.

**Architecture:**
- Two tables, `estimates` and `estimate_lines`, and two models, both BelongsToTenant.
- Estimates are a **child module of Jobs** (`parent: 'jobs'`), because a ModuleDefinition
  holds one automation subject and `assertKeysMatchSubject` refuses `estimate.*` on the
  Job subject. The module brings the standard view/create/edit/delete toggles.
- `EstimatePolicy` ANDs each of them with `jobs.financials`: estimates are money, and
  Jose ruled that money is "a financials toggle".
- The columns the A1 import needs (`source`, `external_ref`, `import_batch`) are created
  now, so that import needs no second migration.

**Tech Stack:** Laravel 12 / PHP 8.4 / MySQL 8.4.

**Spec:** `docs/superpowers/specs/2026-09-29-jobs-billing-design.md`, Phase 1 §4 and the
conformances. §5, the A1 import, gets its own plan once the clients import and the
Dismantler's bundle format exist.

## Global Constraints

- Money is integer minor units, in the franchise's currency (`strtoupper($franchise->currency ?: 'USD')`).
- Statuses are exactly `draft | sent | accepted | declined | archived`, A1's words.
- Transitions: `draft → sent`; `sent → accepted | declined`; any → `archived`. Nothing
  else. Lines may change only while `draft` or `sent`.
- Delete is for drafts only. Anything past draft is archived instead.
- Every action needs `estimates.<action>` AND `jobs.financials` AND reach
  (`canActOnFranchise`). The refusal sentence without Financials, verbatim:
  `Money on jobs is hidden for your role.`
- `estimate.accepted` payload is `{before: {status}, after: {status}}` only. No personal
  data: `automation_events` holds none.
- Retention: estimates are `RetentionPolicy::forever` (Inventory sales' precedent); lines
  are `withParent`.
- Test DB, commits, sabotage and verification rules as in
  `docs/superpowers/plans/2026-09-29-jobs-money-visibility.md`. A change to
  `ModuleServiceProvider` also runs `tests/Feature/Automations` and the
  permission-catalogue and module-registry tests.

---

### Task 1: Tables, models, numbers

**Files:**
- Create: `database/migrations/2026_09_29_100000_create_estimates_tables.php`
- Create: `app/Models/Estimate.php`, `app/Models/EstimateLine.php`
- Modify: `app/Support/Numbering/RecordNumberGenerator.php`: `ESTIMATES` series, prefix from `numbering.estimate_prefix` (already in `SettingRegistry:534`)
- Test: `tests/Feature/Api/EstimateModelTest.php` (new), and `tests/Feature/RetentionTest.php` must stay green

**Interfaces:**
- Produces:
  - `Estimate` with fillable `franchise_id, customer_id, job_id, number, status, issued_on,
    note, currency, total_minor, source, external_ref, import_batch`, and relations
    `franchise(), customer(), job(), lines()`;
  - `Estimate::STATUSES`, `Estimate::recomputeTotal(): void`;
  - `EstimateLine` with fillable `estimate_id, service_id, name, description, quantity,
    unit_price_minor, position`;
  - `RecordNumberGenerator::ESTIMATES = 'estimate'`.

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature\Api;

use App\Models\Customer;
use App\Models\Estimate;
use App\Support\Numbering\RecordNumberGenerator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

class EstimateModelTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    public function test_an_estimate_is_numbered_from_its_own_series_and_totals_its_lines(): void
    {
        $alpha = $this->provisionTenant('Alpha');

        $estimate = $this->inTenant($alpha['org'], function () use ($alpha) {
            $customer = Customer::create(['owning_franchise_id' => $alpha['franchise']->id,
                'number' => 'C-1', 'name' => 'A Customer']);
            $number = app(RecordNumberGenerator::class)->next(RecordNumberGenerator::ESTIMATES, $alpha['franchise']);
            $e = Estimate::create(['franchise_id' => $alpha['franchise']->id, 'customer_id' => $customer->id,
                'number' => $number, 'status' => 'draft', 'currency' => 'USD']);
            $e->lines()->create(['name' => 'Sweep', 'quantity' => 2, 'unit_price_minor' => 15000, 'position' => 0]);
            $e->lines()->create(['name' => 'Cap', 'quantity' => 1, 'unit_price_minor' => 8950, 'position' => 1]);
            $e->recomputeTotal();

            return $e->fresh();
        });

        $this->assertNotSame('', $estimate->number);
        $this->assertSame(38950, $estimate->total_minor);
    }
}
```

- [ ] **Step 2: Run it to verify it fails.** Expected: `Class "App\Models\Estimate" not found`.

- [ ] **Step 3: Migration**

```php
Schema::create('estimates', function (Blueprint $table) {
    $table->uuid('id')->primary();
    $table->foreignUuid('organization_id')->constrained()->cascadeOnDelete();
    $table->foreignUuid('franchise_id')->constrained();
    $table->foreignUuid('customer_id')->constrained();
    $table->foreignUuid('job_id')->nullable()->constrained('jobs')->nullOnDelete();
    $table->string('number', 32);
    $table->string('status', 16)->default('draft');
    $table->date('issued_on')->nullable();
    $table->timestamp('accepted_at')->nullable();
    $table->text('note')->nullable();
    $table->char('currency', 3);
    $table->unsignedBigInteger('total_minor')->default(0);
    // Where it came from. `a1` rows are A1 Tools' estimates, imported by batch
    // so a batch can be rolled back; `external_ref` makes re-runs skip.
    $table->string('source', 16)->default('ezbizi');
    $table->string('external_ref', 64)->nullable();
    $table->uuid('import_batch')->nullable();
    $table->timestamps();

    $table->unique(['organization_id', 'source', 'external_ref']);
    $table->index(['organization_id', 'customer_id']);
    $table->index(['organization_id', 'status']);
});

Schema::create('estimate_lines', function (Blueprint $table) {
    $table->uuid('id')->primary();
    $table->foreignUuid('organization_id')->constrained()->cascadeOnDelete();
    $table->foreignUuid('estimate_id')->constrained('estimates')->cascadeOnDelete();
    $table->foreignUuid('service_id')->nullable()->constrained('services')->nullOnDelete();
    $table->string('name', 191);
    $table->text('description')->nullable();
    $table->decimal('quantity', 10, 2)->default(1);
    $table->unsignedBigInteger('unit_price_minor')->default(0);
    $table->unsignedInteger('position')->default(0);
    $table->timestamps();
});
```

`down()` drops `estimate_lines`, then `estimates`.

- [ ] **Step 4: Models.**
  - `Estimate` follows `InventorySale`: `BelongsToTenant`, `HasUuids`, `StatesRetention`,
    and the fillable list above.
  - Casts: `issued_on` date, `accepted_at` datetime, `total_minor` integer.
  - `retention()` is `RetentionPolicy::forever('What was quoted to a customer, and whether they took it. Kept with the organization; erasing the customer anonymises the customer in place and this row keeps its amounts. Removing the organization takes it.')`.
  - `STATUSES = ['draft', 'sent', 'accepted', 'declined', 'archived']`.
  - `lines(): HasMany` ordered by `position`.
  - `recomputeTotal()` sums `round(quantity * unit_price_minor)` per line, then
    `forceFill(['total_minor' => …])->save()`. Round per line, not over the sum, as an
    invoice prints it.
  - `EstimateLine`: `BelongsToTenant`, `HasUuids`, and
    `retention(): RetentionPolicy::withParent('A line of an estimate: a service and a price, and nothing about any person. Removing the estimate takes it.')`;
    casts `quantity` as `decimal:2` and `unit_price_minor` as integer.

- [ ] **Step 5: Numbering.** Add `public const ESTIMATES = 'estimate';` and, in the
`match ($sequenceKey)`, the arm
`self::ESTIMATES => (string) $this->settings->get('numbering.estimate_prefix', $scope),`.

- [ ] **Step 6: Run it to verify it passes,** plus `tests/Feature/RetentionTest.php`,
which checks that every table in the schema is decided about. Expected: all PASS.

- [ ] **Step 7: Sabotage, then restore.** Each must turn exactly its test red:
- `recomputeTotal` ignoring quantity (the total test);
- `EstimateLine::retention` removed (RetentionTest).

- [ ] **Step 8: Commit** the migration, both models, the generator and the test.

---

### Task 2: The Estimates module and its policy

**Files:**
- Modify: `app/Providers/ModuleServiceProvider.php`: a new `ModuleDefinition` after
  `jobs`
- Create: `app/Policies/EstimatePolicy.php`
- Test: `tests/Feature/Api/EstimatePermissionsTest.php` (new)

**Interfaces:**
- Produces:
  - permissions `estimates.view|create|edit|delete`;
  - `EstimatePolicy::{viewAny, view, create(?Franchise), update, delete}`;
  - trigger `estimate.accepted`.

- [ ] **Step 1: Declare the module.** Model the declaration on `newsletter`
(ModuleServiceProvider, `parent: 'marketing'`):

```php
            /*
             * Estimates -- drawn inside Jobs (`parent`), a module of its own only
             * because a ModuleDefinition holds one automation subject and
             * `estimate.accepted` is about an Estimate, not a Job. Every action
             * here also needs `jobs.financials` (EstimatePolicy): an estimate is
             * money, and Jose ruled money a financials toggle, 2026-09-29.
             */
            new ModuleDefinition(
                key: 'estimates',
                label: 'Estimates',
                parent: 'jobs',
                auditActionPrefixes: ['estimate'],
                subject: new AutomationSubject(Estimate::class, 'Estimate'),
                triggers: [
                    new TriggerDefinition(
                        key: 'estimate.accepted',
                        label: 'Estimate accepted',
                        description: 'A customer accepted an estimate. The usual next step is booking the work.',
                    ),
                ],
            ),
```

- [ ] **Step 2: Write the failing test**

```php
<?php

namespace Tests\Feature\Api;

use App\Models\Customer;
use App\Models\Estimate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/** Estimates are money: `estimates.*` is not enough without the Jobs Financials toggle. */
class EstimatePermissionsTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    public function test_estimate_rights_without_financials_see_nothing(): void
    {
        $alpha = $this->provisionTenant('Alpha');
        $id = $this->anEstimate($alpha);
        $clerk = $this->userWithRole($alpha, 'Clerk', ['jobs.view', 'estimates.view']);

        $this->signedInAs($clerk)->getJson("/api/v1/estimates/{$id}")
            ->assertForbidden()->assertJsonPath('message', 'Money on jobs is hidden for your role.');
    }

    public function test_estimate_rights_with_financials_see_it(): void
    {
        $alpha = $this->provisionTenant('Alpha');
        $id = $this->anEstimate($alpha);
        $manager = $this->userWithRole($alpha, 'Manager', ['jobs.view', 'jobs.financials', 'estimates.view']);

        $this->signedInAs($manager)->getJson("/api/v1/estimates/{$id}")->assertOk();
    }

    public function test_financials_without_estimate_rights_is_refused(): void
    {
        $alpha = $this->provisionTenant('Alpha');
        $id = $this->anEstimate($alpha);
        $bookkeeper = $this->userWithRole($alpha, 'Bookkeeper', ['jobs.view', 'jobs.financials']);

        $this->signedInAs($bookkeeper)->getJson("/api/v1/estimates/{$id}")->assertForbidden();
    }

    public function test_reach_still_applies(): void
    {
        $alpha = $this->provisionTenant('Alpha');
        $elsewhere = $this->extraFranchise($alpha, 'Elsewhere');
        $id = $this->anEstimate($alpha);   // in Alpha's own franchise
        $branch = $this->userWithRole($alpha, 'Branch', ['jobs.view', 'jobs.financials', 'estimates.view'], onlyAt: $elsewhere);

        $this->signedInAs($branch)->getJson("/api/v1/estimates/{$id}")->assertForbidden();
    }

    private function anEstimate(array $tenant): string
    {
        return $this->inTenant($tenant['org'], function () use ($tenant) {
            $customer = Customer::create(['owning_franchise_id' => $tenant['franchise']->id, 'number' => 'C-1', 'name' => 'A Customer']);

            return Estimate::create(['franchise_id' => $tenant['franchise']->id, 'customer_id' => $customer->id,
                'number' => 'E-1', 'status' => 'draft', 'currency' => 'USD'])->id;
        });
    }
}
```

These run against the `show` route from Task 3. Keep this test red until Task 3 lands,
then commit Tasks 2 and 3 together, so no route ships ungated or unrouted.

- [ ] **Step 3: The policy.** It is `JobPolicy`'s shape, with one extra first check:

```php
<?php

namespace App\Policies;

use App\Models\Estimate;
use App\Models\Franchise;
use App\Models\User;
use App\Support\Auth\Refusal;
use Illuminate\Auth\Access\Response;

/** Estimates are money: every action needs Jobs' Financials toggle too (Jose, 2026-09-29). */
class EstimatePolicy
{
    public function viewAny(User $user): Response
    {
        return $this->money($user) ?? ($user->hasPermission('estimates.view')
            ? Response::allow() : Refusal::permission('see estimates'));
    }

    public function view(User $user, Estimate $estimate): Response
    {
        return $this->check($user, 'estimates.view', 'see estimates', $estimate->franchise, 'see this estimate');
    }

    public function create(User $user, ?Franchise $franchise = null): Response
    {
        if ($franchise === null) {
            return $this->money($user) ?? ($user->hasPermission('estimates.create')
                ? Response::allow() : Refusal::permission('add an estimate'));
        }

        return $this->check($user, 'estimates.create', 'add an estimate', $franchise, 'add an estimate');
    }

    public function update(User $user, Estimate $estimate): Response
    {
        return $this->check($user, 'estimates.edit', 'change an estimate', $estimate->franchise, 'change this estimate');
    }

    public function delete(User $user, Estimate $estimate): Response
    {
        return $this->check($user, 'estimates.delete', 'delete an estimate', $estimate->franchise, 'delete this estimate');
    }

    private function money(User $user): ?Response
    {
        return $user->hasPermission('jobs.financials')
            ? null
            : Response::deny('Money on jobs is hidden for your role.');
    }

    private function check(User $user, string $key, string $noun, Franchise $franchise, string $act): Response
    {
        if ($refused = $this->money($user)) {
            return $refused;
        }
        if (! $user->hasPermission($key)) {
            return Refusal::permission($noun);
        }

        return $user->canActOnFranchise($franchise)
            ? Response::allow()
            : Refusal::noReach($user, Refusal::AT_FRANCHISE, $act, $franchise->name);
    }
}
```

Check `Refusal`'s namespace before writing the `use` line: `grep -rn "class Refusal" app`.

---

### Task 3: The estimates API, and `estimate.accepted`

**Files:**
- Create: `app/Http/Controllers/Api/EstimatesController.php`
- Modify: `routes/api.php`, after the job lines routes (line ~699):
  `Route::apiResource('estimates', EstimatesController::class);`
- Test: `tests/Feature/Api/EstimatesApiTest.php` (new)

**Interfaces:**
- Consumes: Task 1's models and numbering; Task 2's policy and trigger.
- Produces:
  - `GET /estimates?customer_id=&job_id=&status=`, scoped by ViewScope;
  - `GET /estimates/{id}`;
  - `POST /estimates {franchise_id, customer_id, job_id?, note?, issued_on?, lines[]}`;
  - `PATCH /estimates/{id} {status?, note?, issued_on?, lines?}`;
  - `DELETE /estimates/{id}`.
  - The payload: `{id, number, status, franchise{id,name}, customer{id,name,number},
    job{id,number}|null, issued_on, accepted_at, currency, total_minor, note, source,
    lines[{id, service_id, name, description, quantity, unit_price_minor}]}`.

- [ ] **Step 1: Write the failing tests.** Each is a PHPUnit method, signed in as
`$alpha['admin']`, who holds everything:
  - `test_creating_numbers_it_and_totals_its_lines`: POST two lines → 201, a number
    present, `total_minor` equal to the sum of `round(quantity × unit_price_minor)`,
    status `draft`, the franchise's currency.
  - `test_the_list_is_scoped_to_view`: create in the tenant's franchise and in an
    `extraFranchise`. A user who reaches only the first sees only the first.
  - `test_the_transitions_are_the_documented_ones`: draft→accepted is 422 on `status`;
    draft→sent→accepted is OK, with `accepted_at` stamped; accepted→sent is 422; any →
    archived is OK.
  - `test_accepting_raises_estimate_accepted_once`: sent→accepted creates one
    `AutomationEvent` row with `event_key = 'estimate.accepted'`, `subject_id` the
    estimate, and payload `{before: {status: sent}, after: {status: accepted}}`. A second
    PATCH with the same status raises none. Model the query on `JobAssignmentTest:166`.
  - `test_lines_replace_and_recompute_and_freeze_after_a_decision`: PATCH `lines` on a
    sent estimate replaces them and recomputes; on an accepted one it is 422 on `lines`.
  - `test_only_a_draft_can_be_deleted`: DELETE on a draft is 204; on sent it is 422, and
    the message says to archive instead.
  - `test_the_job_must_be_the_customers_and_the_franchises`: a `job_id` of another
    customer is 422 on `job_id`.
  - `test_another_franchise_is_refused_on_create`: POST with an `extraFranchise` the user
    cannot reach is 403.

- [ ] **Step 2: Run them to verify they fail** (404 on the missing routes), together with
Task 2's test.

- [ ] **Step 3: Controller.** Follow `JobsController`'s shape:
  - `existsInTenant` for `customer_id`, `franchise_id` and `job_id`;
  - `authorize` with the resolved Franchise on create;
  - everything inside `DB::transaction`: the write, `$this->audit->log(...)` or
    `logChange(...)`, then `$this->events->emit(...)`.
  - Rules:
    - `lines` is `array`, each with `service_id` (nullable, existsInTenant `services`),
      `name` (required, string, max 191), `description` (nullable, string, max 2000),
      `quantity` (required, numeric, min 0.01, max 99999) and `unit_price_minor`
      (required, integer, min 0);
    - `status` is `Rule::in(Estimate::STATUSES)`;
    - `note` and `issued_on` (date) are nullable.
  - Transitions: a private `const NEXT = ['draft' => ['sent', 'archived'], 'sent' =>
    ['accepted', 'declined', 'archived'], 'accepted' => ['archived'], 'declined' =>
    ['archived'], 'archived' => []];`. A status equal to the current one is a no-op, not
    a refusal: the panel sends it on every save. Anything else not in `NEXT` is a 422 on
    `status`: "An estimate cannot go from {from} to {to}."
  - Lines are replaced (delete then create) only when `lines` is present and the status
    is `draft` or `sent`. Otherwise it is a 422 on `lines`: "A decided estimate's lines
    are fixed." Then `recomputeTotal()`.
  - Accepting sets `accepted_at = now()` and emits `estimate.accepted` with
    `['before' => ['status' => $before], 'after' => ['status' => 'accepted']]`.
  - `destroy`: a draft only. Otherwise a 422 on `status`: "Only a draft can be deleted;
    archive it instead."
  - The job, when given, must have the same `customer_id` and `franchise_id`. Otherwise a
    422 on `job_id`: "That job is another customer's or another franchise's."
  - Audit actions: `estimate.created`, `estimate.updated` via `logChange`, and
    `estimate.deleted`.

- [ ] **Step 4: Routes.** Add the `apiResource` line.

- [ ] **Step 5: Run to verify they pass.** Run Tasks 2 and 3's tests, then
`tests/Feature/Automations`, and the tests naming `ModuleRegistry`, `PermissionCatalogue`
or `/roles`. `ModuleRegistryTest` checks that `estimate.accepted`'s `isRaised` claim
matches an emit in the source. Expected: all PASS.

- [ ] **Step 6: Sabotage each rule, then restore.** Each must turn exactly its test red:
- the `money()` check removed from the policy;
- `NEXT` allowing draft→accepted;
- the emit removed (the trigger test and `ModuleRegistryTest`);
- the lines freeze removed;
- the draft-only delete removed;
- the job/customer check removed;
- reach removed.

- [ ] **Step 7: Commit Tasks 2 and 3 together:** `ModuleServiceProvider`, the policy, the
controller, the routes and both tests.

---

### Task 4: Gates, hand-over, board

- [ ] **Step 1:** Run the full backend gate, as in the money plan's Task 6, with logs in
the scratchpad and never `$TEMP`.
- [ ] **Step 2:** Send UI/UX the estimates API, asking for 3+ options for the estimates
screens inside Jobs: a list on the customer and on the job, and an editor with lines.
- [ ] **Step 3:** Send the desk the SHAs. The release note should say that Estimates is a
new area in Jobs, needing its own toggles plus Financials.
- [ ] **Step 4:** Update the board.
