# Inspection Scope Owner Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** A named inspection can belong to the whole organization, to one company (and every franchise beneath it), or to one franchise — so A1Chimney's inspection is A1Chimney's and not a1-group's.

**Architecture:** Add the `scope_type` / `scope_id` pair `Automation`, `Mailbox` and `PhoneConnection` already carry. Scope decides where a definition **exists**; the existing per-role `InspectionDefinitionGrant` still decides who may **run** it. Both must pass. Visibility resolves through a franchise's company chain, so a franchise-level technician still sees the inspection their parent company owns.

**Tech Stack:** Laravel 12 / PHP 8.3, MySQL, PHPUnit. Flutter client. No new dependencies.

**Spec:** `docs/superpowers/specs/2026-08-28-inspection-engine-design.md` — this plan implements §4 only. Phases 2–9 get their own plans.

## Global Constraints

- **App Store review is live** (`docs/ACTIVE-CONSTRAINTS.md`). Do not remove or rename an existing response field, do not make an always-present field null or absent, do not tighten a permission answer, do not alter a `Refusal.php` sentence. **Adding** `scope` to a response is safe. Do not set or raise a minimum client version.
- **Test against your own database:** `DB_DATABASE=ezbizi_testing_inspection php -d memory_limit=512M vendor/bin/phpunit --filter=...`. Never the shared `ezbizi_testing`.
- **Never a bare `git commit`.** Follow `StartUp.md` §4: resolve `BASE` once and write it to a file, private `GIT_INDEX_FILE`, `git diff-index --cached --stat "$BASE"` as its own step, `commit-tree -p "$BASE"`, `update-ref` with the old-value guard. After committing, check the shared index again for your paths and `git reset "$BASE" -- <paths>` if any remain.
- **MySQL, not sqlite.** Never assert on key order read back from the database.
- **Colours from `client/lib/core/theme.dart`**, never a literal. No modals — a panel or a screen.
- `flutter analyze` must be clean before any commit.
- Do **not** bump `client/VERSION` — that is the release cutter's, once per release.

---

### Task 1: The columns, and what they mean

**Files:**
- Create: `database/migrations/2026_08_29_090000_add_scope_to_inspection_definitions.php`
- Modify: `app/Models/InspectionDefinition.php`
- Test: `tests/Feature/Inspection/InspectionScopeTest.php`

**Interfaces:**
- Consumes: nothing.
- Produces: `inspection_definitions.scope_type` (`varchar(20)`, nullable) and `.scope_id` (`char(36)`, nullable); `InspectionDefinition::SCOPE_COMPANY = 'company'`, `SCOPE_FRANCHISE = 'franchise'`; both keys added to the model's `$fillable`.

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature\Inspection;

use App\Models\InspectionDefinition;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/**
 * Where a named inspection exists.
 *
 * Scope is not a permission. `InspectionDefinitionGrant` still decides who may
 * RUN a definition; scope decides which places it exists in at all. Both have
 * to pass, and the tests below are careful to fail for one reason at a time.
 */
class InspectionScopeTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    public function test_a_definition_is_organization_wide_until_it_is_told_otherwise(): void
    {
        $this->assertTrue(Schema::hasColumn('inspection_definitions', 'scope_type'));
        $this->assertTrue(Schema::hasColumn('inspection_definitions', 'scope_id'));

        $tenant = $this->provisionTenant('Alpha');

        $definition = $this->inTenant($tenant['org'], fn () => InspectionDefinition::create([
            'name' => 'Level 1',
            'created_by' => $tenant['admin']->id,
            'screens' => [],
        ]));

        // Null is the organization. A default of anything else would make every
        // existing definition belong to a place nobody chose.
        $this->assertNull($definition->scope_type);
        $this->assertNull($definition->scope_id);
    }

    public function test_a_scope_can_be_stored_on_a_definition(): void
    {
        $tenant = $this->provisionTenant('Alpha');

        $definition = $this->inTenant($tenant['org'], fn () => InspectionDefinition::create([
            'name' => 'Level 1',
            'created_by' => $tenant['admin']->id,
            'screens' => [],
            'scope_type' => InspectionDefinition::SCOPE_FRANCHISE,
            'scope_id' => $tenant['franchise']->id,
        ]));

        $fresh = $this->inTenant(
            $tenant['org'],
            fn () => InspectionDefinition::findOrFail($definition->id),
        );

        $this->assertSame('franchise', $fresh->scope_type);
        $this->assertSame($tenant['franchise']->id, $fresh->scope_id);
    }
}
```

- [ ] **Step 2: Run it and watch it fail**

Run: `DB_DATABASE=ezbizi_testing_inspection php -d memory_limit=512M vendor/bin/phpunit --filter=InspectionScopeTest`

Expected: FAIL — `Failed asserting that false is true` on `hasColumn`.

- [ ] **Step 3: Write the migration**

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Where a named inspection exists (engine design §4).
 *
 * The same `scope_type`/`scope_id` pair Automation, Mailbox and PhoneConnection
 * carry. NULL is the organization, and it is the default so that every
 * definition written before this migration keeps behaving exactly as it did —
 * a backfill to some chosen company would be this migration deciding something
 * the owner never asked for.
 *
 * No foreign key on `scope_id`, matching `automations`: the column names a row
 * in one of TWO tables depending on `scope_type`, and there is no constraint
 * that expresses that. The controller checks the id belongs to this
 * organization, and `InspectionScopeTest` pins that it refuses one that does
 * not.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inspection_definitions', function (Blueprint $table) {
            $table->string('scope_type', 20)->nullable()->after('organization_id');
            $table->char('scope_id', 36)->nullable()->after('scope_type');

            // The picker asks "what exists for this place" on every load of New
            // Inspection, so the pair is worth an index even on a small table.
            $table->index(['organization_id', 'scope_type', 'scope_id'], 'inspection_definitions_scope_idx');
        });
    }

    public function down(): void
    {
        Schema::table('inspection_definitions', function (Blueprint $table) {
            $table->dropIndex('inspection_definitions_scope_idx');
            $table->dropColumn(['scope_type', 'scope_id']);
        });
    }
};
```

- [ ] **Step 4: Add the constants and make the columns fillable**

In `app/Models/InspectionDefinition.php`, add the two constants directly above the existing `$fillable` declaration, and add both keys to that array:

```php
    /** Where this inspection exists. NULL scope_type means the organization. */
    public const SCOPE_COMPANY   = 'company';
    public const SCOPE_FRANCHISE = 'franchise';
```

Then add `'scope_type', 'scope_id',` to `$fillable`.

- [ ] **Step 5: Run the test and watch it pass**

Run: `DB_DATABASE=ezbizi_testing_inspection php -d memory_limit=512M vendor/bin/phpunit --filter=InspectionScopeTest`

Expected: PASS, 2 tests.

- [ ] **Step 6: Commit**

Follow the Global Constraints commit recipe. Paths:

```
database/migrations/2026_08_29_090000_add_scope_to_inspection_definitions.php
app/Models/InspectionDefinition.php
tests/Feature/Inspection/InspectionScopeTest.php
```

Message: `An inspection can name the place it belongs to`

---

### Task 2: Who a scope is visible to

**Files:**
- Create: `app/Support/Inspection/InspectionScope.php`
- Test: `tests/Feature/Inspection/InspectionScopeTest.php` (append)

**Interfaces:**
- Consumes: `InspectionDefinition::SCOPE_COMPANY`, `SCOPE_FRANCHISE` from Task 1.
- Produces: `InspectionScope::visibleTo(User $user): Closure` returning a predicate, and `InspectionScope::companyChainFor(User $user): string[]`. Tasks 3 and 4 both call `constrain(Builder $query, User $user): void`.

**Why this is its own class rather than a scope on the model:** the visibility rule is the *inverse* of `ScopeReach::covers`. Reach answers "may this person act on that place", which is what Task 5 needs for authoring. Visibility answers "is that place at or above this person", which is a different question with a different answer — a technician assigned to one franchise reaches only that franchise, and must still see the inspection their parent company owns. Putting both in one class would invite the wrong one being used.

- [ ] **Step 1: Write the failing test**

Append to `tests/Feature/Inspection/InspectionScopeTest.php`:

```php
    /**
     * The case the whole class exists for.
     *
     * A technician assigned to one franchise does NOT reach the company above
     * them — `ScopeReach::covers` says false, correctly, because they may not
     * act on it. They must still see the inspection that company owns, or a
     * company-scoped definition is invisible to everybody who would run it and
     * the feature is worse than useless.
     */
    public function test_a_franchise_technician_sees_the_inspection_their_company_owns(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $tech = $this->userWithRole($tenant, 'Rodrigo', ['inspection.view', 'inspection.create']);

        $owned = $this->inTenant($tenant['org'], fn () => InspectionDefinition::create([
            'name' => 'Company wide',
            'created_by' => $tenant['admin']->id,
            'screens' => [],
            'scope_type' => InspectionDefinition::SCOPE_COMPANY,
            'scope_id' => $tenant['company']->id,
        ]));

        $visible = $this->inTenant($tenant['org'], fn () => InspectionDefinition::query()
            ->tap(fn ($q) => app(InspectionScope::class)->constrain($q, $tech->fresh()))
            ->pluck('id')
            ->all());

        $this->assertContains($owned->id, $visible);
    }

    public function test_a_sibling_franchises_inspection_is_not_visible(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $tech = $this->userWithRole($tenant, 'Rodrigo', ['inspection.view', 'inspection.create']);
        $other = $this->extraFranchise($tenant, 'Second');

        $theirs = $this->inTenant($tenant['org'], fn () => InspectionDefinition::create([
            'name' => 'Not yours',
            'created_by' => $tenant['admin']->id,
            'screens' => [],
            'scope_type' => InspectionDefinition::SCOPE_FRANCHISE,
            'scope_id' => $other->id,
        ]));

        $visible = $this->inTenant($tenant['org'], fn () => InspectionDefinition::query()
            ->tap(fn ($q) => app(InspectionScope::class)->constrain($q, $tech->fresh()))
            ->pluck('id')
            ->all());

        $this->assertNotContains($theirs->id, $visible,
            'a franchise-scoped inspection must not leak sideways to a sibling');
    }

    public function test_an_organization_wide_inspection_is_visible_to_everybody(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $tech = $this->userWithRole($tenant, 'Rodrigo', ['inspection.view', 'inspection.create']);

        $all = $this->inTenant($tenant['org'], fn () => InspectionDefinition::create([
            'name' => 'Everyone',
            'created_by' => $tenant['admin']->id,
            'screens' => [],
        ]));

        $visible = $this->inTenant($tenant['org'], fn () => InspectionDefinition::query()
            ->tap(fn ($q) => app(InspectionScope::class)->constrain($q, $tech->fresh()))
            ->pluck('id')
            ->all());

        $this->assertContains($all->id, $visible);
    }
```

Add to the file's `use` block: `use App\Models\User;` and `use App\Support\Inspection\InspectionScope;`.

**Verified against `tests/Concerns/BuildsTenants.php` on 2026-08-28:** `provisionTenant()` already returns `org`, `admin`, `company`, `franchise` — so `$tenant['company']` works as written and needs no change.

**There is no second-franchise helper, so this task adds one.** Append to `tests/Concerns/BuildsTenants.php`:

```php
    /**
     * A second franchise under the same company.
     *
     * For the tests that are about one franchise not seeing another's work.
     * Additive: it does not touch what `provisionTenant` returns, which every
     * module's tests read.
     */
    protected function extraFranchise(array $tenant, string $name): Franchise
    {
        return $this->inTenant($tenant['org'], fn () => Franchise::create([
            'company_id' => $tenant['company']->id,
            'name'       => $name,
        ]));
    }
```

**Read `Franchise`'s fillable before writing this** — if it requires more than `company_id` and `name` (a `number`, say), supply them the way `provisionTenant` does rather than guessing. Add `use App\Models\Franchise;` to the concern's imports if absent.

- [ ] **Step 2: Run it and watch it fail**

Run: `DB_DATABASE=ezbizi_testing_inspection php -d memory_limit=512M vendor/bin/phpunit --filter=InspectionScopeTest`

Expected: FAIL — `Class "App\Support\Inspection\InspectionScope" not found`.

- [ ] **Step 3: Write the class**

```php
<?php

namespace App\Support\Inspection;

use App\Models\Company;
use App\Models\Franchise;
use App\Models\InspectionDefinition;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * Which named inspections exist for a given person.
 *
 * ── Not the same question as reach, and that is the whole file ───
 *
 * `ScopeReach::covers` answers "may this person ACT on that place". This
 * answers "is that place AT OR ABOVE this person". They disagree in exactly the
 * case that matters: a technician assigned to one franchise reaches that
 * franchise and nothing above it — correctly, because they may not administer
 * the company — and yet the inspection their company owns is precisely the one
 * they are meant to run.
 *
 * Getting this backwards makes a company-scoped inspection invisible to every
 * technician who would use it, which looks like the picker being empty rather
 * than like a bug in a scope rule. `Automation::coversScope` records the same
 * hazard from the other side: a scope the API accepted and the engine could
 * never match.
 *
 * ── Sideways is closed, deliberately ────────────────────────────
 *
 * The chain walks UP from the person's franchises and never down. A franchise
 * sees itself, its company, that company's parents, and the organization. It
 * never sees a sibling franchise's inspection, and never a Sub Company's — the
 * settings cascade runs the other way and reusing it here would hand one
 * franchise another's forms.
 */
final class InspectionScope
{
    /**
     * Narrow a definitions query to what this person's places can see.
     *
     * An Administrator sees everything: they are the one role that administers
     * the organization, and a Builder that hid a company's inspection from the
     * person who has to fix it would be unusable.
     */
    public function constrain(Builder $query, User $user): void
    {
        if ($user->isAdministrator()) {
            return;
        }

        $franchiseIds = $user->reachableFranchises()->modelKeys();
        $companyIds = $this->companyChainFor($user);

        $query->where(function (Builder $q) use ($franchiseIds, $companyIds) {
            // NULL is the organization, and it is everybody's.
            $q->whereNull('scope_type');

            if ($companyIds !== []) {
                $q->orWhere(fn (Builder $inner) => $inner
                    ->where('scope_type', InspectionDefinition::SCOPE_COMPANY)
                    ->whereIn('scope_id', $companyIds));
            }

            if ($franchiseIds !== []) {
                $q->orWhere(fn (Builder $inner) => $inner
                    ->where('scope_type', InspectionDefinition::SCOPE_FRANCHISE)
                    ->whereIn('scope_id', $franchiseIds));
            }
        });
    }

    /**
     * Every company at or above this person's franchises.
     *
     * Walks up through `parent`, so a Sub Company's franchise also sees the
     * parent company's inspections — the same upward roll-up
     * `AutomationEngine::companyChain` performs, and for the same reason.
     *
     * @return string[]
     */
    public function companyChainFor(User $user): array
    {
        $chain = [];

        $franchises = Franchise::query()
            ->whereIn('id', $user->reachableFranchises()->modelKeys())
            ->with('company')
            ->get();

        foreach ($franchises as $franchise) {
            $company = $franchise->company;

            while ($company instanceof Company) {
                $chain[$company->getKey()] = true;
                $company = $company->parent;
            }
        }

        return array_keys($chain);
    }
}
```

- [ ] **Step 4: Run the test and watch it pass**

Run: `DB_DATABASE=ezbizi_testing_inspection php -d memory_limit=512M vendor/bin/phpunit --filter=InspectionScopeTest`

Expected: PASS, 5 tests.

- [ ] **Step 5: Break it on purpose**

House rule — a test that has never failed has not been shown to work. Change `whereNull('scope_type')` to `whereNotNull('scope_type')` and re-run.

Expected: `test_an_organization_wide_inspection_is_visible_to_everybody` FAILS. Then change `$franchiseIds` to `$companyIds` in the franchise branch and re-run; expected: `test_a_sibling_franchises_inspection_is_not_visible` or the company test FAILS. **Restore both** and confirm 5 pass again before committing.

- [ ] **Step 6: Commit**

Paths: `app/Support/Inspection/InspectionScope.php`, `tests/Feature/Inspection/InspectionScopeTest.php`, and `tests/Concerns/BuildsTenants.php` if you extended it.

Message: `A franchise sees the inspection its company owns, never its sibling's`

---

### Task 3: The picker only offers what exists here

**Files:**
- Modify: `app/Support/Inspection/InspectionAccess.php` (the `startable()` method)
- Test: `tests/Feature/Api/InspectionRunsTest.php` (append)

**Interfaces:**
- Consumes: `InspectionScope::constrain()` from Task 2.
- Produces: nothing new. `startable()` keeps its signature `(User $user): Collection`.

- [ ] **Step 1: Write the failing test**

Append to `tests/Feature/Api/InspectionRunsTest.php`:

```php
    /**
     * Scope narrows; the grant still gates. Both, and in that order.
     *
     * This definition is GRANTED to the technician's role and published — so
     * every existing rule says they may run it — and it belongs to a franchise
     * that is not theirs. If scope were merely advisory the picker would offer
     * it and the start would succeed.
     */
    public function test_the_picker_omits_an_inspection_scoped_to_another_franchise(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $tech = $this->tech($tenant);
        $seeded = $this->seedOffered($tenant, $tech);
        $elsewhere = $this->extraFranchise($tenant, 'Second');

        $this->inTenant($tenant['org'], fn () => $seeded['definition']->update([
            'scope_type' => \App\Models\InspectionDefinition::SCOPE_FRANCHISE,
            'scope_id' => $elsewhere->id,
        ]));

        $customer = $seeded['customer'];

        $response = $this->signedInAs($tech)
            ->getJson('/api/v1/inspections/startable?customer_id='.$customer->id)
            ->assertOk();

        $this->assertSame([], $response->json('data'),
            'a definition scoped elsewhere must not be offered, grant or no grant');
    }

    public function test_starting_an_inspection_scoped_elsewhere_is_refused(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $tech = $this->tech($tenant);
        $seeded = $this->seedOffered($tenant, $tech);
        $elsewhere = $this->extraFranchise($tenant, 'Second');

        $this->inTenant($tenant['org'], fn () => $seeded['definition']->update([
            'scope_type' => \App\Models\InspectionDefinition::SCOPE_FRANCHISE,
            'scope_id' => $elsewhere->id,
        ]));

        // Refused at the API, not merely hidden in the picker. A hidden thing
        // that still starts when its id is posted is not scoped at all.
        $this->signedInAs($tech)->postJson('/api/v1/inspections/runs', [
            'customer_id' => $seeded['customer']->id,
            'inspection_definition_id' => $seeded['definition']->id,
        ])->assertStatus(422);
    }
```

- [ ] **Step 2: Run it and watch it fail**

Run: `DB_DATABASE=ezbizi_testing_inspection php -d memory_limit=512M vendor/bin/phpunit --filter=InspectionRunsTest`

Expected: FAIL — the picker still returns the definition, and the start returns 201.

- [ ] **Step 3: Constrain `startable`**

In `app/Support/Inspection/InspectionAccess.php`, inject the new class and apply it. Add to the constructor:

```php
    public function __construct(
        private readonly InspectionScope $scope,
    ) {}
```

(If the class already has a constructor, add the parameter to it rather than writing a second one.) Add `use App\Support\Inspection\InspectionScope;` to the imports, then inside `startable()`, immediately after the `$query` is built and **before** the grant filter:

```php
        // Scope first, then grants. Two different questions: scope says where
        // this inspection exists, the grant says who may run it. A definition
        // has to pass both, and answering them in one clause would make a
        // failure impossible to read.
        $this->scope->constrain($query, $user);
```

`mayStart()` already asks `startable()`, so the 422 in the second test comes for free — the controller's existing "not offered to this role, or is no longer published" message covers it. **Do not add a second message**; a refusal has one home.

- [ ] **Step 4: Run the tests and watch them pass**

Run: `DB_DATABASE=ezbizi_testing_inspection php -d memory_limit=512M vendor/bin/phpunit --filter=InspectionRunsTest`

Expected: PASS, all tests in the file.

- [ ] **Step 5: Commit**

Paths: `app/Support/Inspection/InspectionAccess.php`, `tests/Feature/Api/InspectionRunsTest.php`

Message: `The picker offers only what exists in this place`

---

### Task 4: The Builder lists and sets a scope

**Files:**
- Modify: `app/Http/Controllers/Api/InspectionDefinitionsController.php` (`index()`, `store()`, `update()`, `present()`)
- Test: `tests/Feature/Api/InspectionBuilderScopeTest.php` (create)

**Interfaces:**
- Consumes: `InspectionScope::constrain()` (Task 2), `ScopeReach::covers()` (existing, `app/Support/Access/ScopeReach.php`).
- Produces: response key `scope` on every definition payload — `{"type": null|"company"|"franchise", "id": string|null}`. Accepts `scope_type` and `scope_id` on `store` and `update`.

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature\Api;

use App\Models\InspectionDefinition;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/**
 * Setting the place a named inspection belongs to.
 *
 * Authoring is a REACH question, unlike running it — see InspectionScope. A
 * person may only give an inspection to a place they could act on, or a
 * franchise manager could quietly take an inspection away from the rest of the
 * group by scoping it to themselves.
 */
class InspectionBuilderScopeTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    public function test_a_definition_reports_its_scope(): void
    {
        $tenant = $this->provisionTenant('Alpha');

        $id = $this->signedInAs($tenant['admin'])
            ->postJson('/api/v1/inspections', [])
            ->assertCreated()
            ->json('data.id');

        // Absent scope reads as the organization, and says so with nulls rather
        // than by omitting the key — a client that has to tell "no scope" from
        // "field missing" will get it wrong.
        $this->signedInAs($tenant['admin'])
            ->getJson("/api/v1/inspections/{$id}")
            ->assertOk()
            ->assertJsonPath('data.scope.type', null)
            ->assertJsonPath('data.scope.id', null);

        $this->signedInAs($tenant['admin'])
            ->patchJson("/api/v1/inspections/{$id}", [
                'scope_type' => 'franchise',
                'scope_id' => $tenant['franchise']->id,
            ])
            ->assertOk()
            ->assertJsonPath('data.scope.type', 'franchise')
            ->assertJsonPath('data.scope.id', $tenant['franchise']->id);
    }

    public function test_a_scope_from_another_organization_is_refused(): void
    {
        $alpha = $this->provisionTenant('Alpha');
        $beta = $this->provisionTenant('Beta');

        $id = $this->signedInAs($alpha['admin'])
            ->postJson('/api/v1/inspections', [])
            ->assertCreated()
            ->json('data.id');

        $this->signedInAs($alpha['admin'])
            ->patchJson("/api/v1/inspections/{$id}", [
                'scope_type' => 'franchise',
                'scope_id' => $beta['franchise']->id,
            ])
            ->assertStatus(422);
    }

    public function test_clearing_the_scope_returns_it_to_the_organization(): void
    {
        $tenant = $this->provisionTenant('Alpha');

        $id = $this->signedInAs($tenant['admin'])
            ->postJson('/api/v1/inspections', [])
            ->assertCreated()
            ->json('data.id');

        $this->signedInAs($tenant['admin'])->patchJson("/api/v1/inspections/{$id}", [
            'scope_type' => 'franchise',
            'scope_id' => $tenant['franchise']->id,
        ])->assertOk();

        // scope_id must be dropped with scope_type, or the row keeps a franchise
        // id under a null type and the next reader has to guess which won.
        $this->signedInAs($tenant['admin'])
            ->patchJson("/api/v1/inspections/{$id}", ['scope_type' => null])
            ->assertOk()
            ->assertJsonPath('data.scope.type', null)
            ->assertJsonPath('data.scope.id', null);
    }

    public function test_a_scope_id_without_a_type_is_refused(): void
    {
        $tenant = $this->provisionTenant('Alpha');

        $id = $this->signedInAs($tenant['admin'])
            ->postJson('/api/v1/inspections', [])
            ->assertCreated()
            ->json('data.id');

        $this->signedInAs($tenant['admin'])
            ->patchJson("/api/v1/inspections/{$id}", ['scope_id' => $tenant['franchise']->id])
            ->assertStatus(422);
    }
}
```

- [ ] **Step 2: Run it and watch it fail**

Run: `DB_DATABASE=ezbizi_testing_inspection php -d memory_limit=512M vendor/bin/phpunit --filter=InspectionBuilderScopeTest`

Expected: FAIL — `data.scope.type` missing from the payload.

- [ ] **Step 3: Present the scope**

In `present()`, add to `$row` immediately after `'name'`:

```php
            // Always present, both keys, nulls and all. A client that had to
            // distinguish "organization" from "the server did not say" would
            // guess, and half of them would guess wrong.
            'scope' => [
                'type' => $definition->scope_type,
                'id'   => $definition->scope_id,
            ],
```

- [ ] **Step 4: Validate and store it**

Add a private method to the controller, and call it from both `store()` and `update()` before the write:

```php
    /**
     * The place this inspection belongs to, checked before it is written.
     *
     * Two separate checks, because they refuse for different reasons:
     *
     *   1. The place must be THIS organization's. `scope_id` has no foreign key
     *      — it names a row in one of two tables — so nothing else would catch
     *      an id from elsewhere on the platform. It would never match anything,
     *      but a tenant boundary that is stated and not enforced is one that
     *      stops being true later. AutomationsController makes the same check
     *      for the same column.
     *
     *   2. The author must REACH the place. Otherwise a franchise manager could
     *      scope the group's inspection to their own franchise and take it away
     *      from everybody else with one PATCH.
     *
     * @return array{scope_type: ?string, scope_id: ?string}
     */
    private function scopeFrom(Request $request, ?InspectionDefinition $existing): array
    {
        $input = $request->validate([
            'scope_type' => ['nullable', Rule::in([
                InspectionDefinition::SCOPE_COMPANY,
                InspectionDefinition::SCOPE_FRANCHISE,
            ])],
            'scope_id' => ['nullable', 'uuid'],
        ]);

        $type = array_key_exists('scope_type', $input)
            ? $input['scope_type']
            : $existing?->scope_type;
        $id = array_key_exists('scope_id', $input)
            ? $input['scope_id']
            : $existing?->scope_id;

        // Clearing the type clears the id with it.
        if (array_key_exists('scope_type', $input) && $input['scope_type'] === null) {
            $id = null;
        }

        if ($type === null && $id !== null) {
            throw ValidationException::withMessages([
                'scope_id' => 'Choose a company or a franchise before choosing which one.',
            ]);
        }

        if ($type !== null && $id === null) {
            throw ValidationException::withMessages([
                'scope_id' => 'Choose which '.$type.' this inspection belongs to.',
            ]);
        }

        if ($type !== null) {
            $table = $type === InspectionDefinition::SCOPE_COMPANY ? 'companies' : 'franchises';

            $exists = DB::table($table)
                ->where('id', $id)
                ->where('organization_id', $this->tenant->idOrFail())
                ->exists();

            if (! $exists) {
                throw ValidationException::withMessages([
                    'scope_id' => "That is not one of this organization's {$table}.",
                ]);
            }

            $this->reach->assertCovers($request->user(), $type, $id);
        }

        return ['scope_type' => $type, 'scope_id' => $id];
    }
```

Add to the constructor: `private readonly ScopeReach $reach,` and `private readonly TenantContext $tenant,` (add to the existing constructor rather than writing a second one). Add imports: `App\Support\Access\ScopeReach`, `App\Support\Tenancy\TenantContext`, `Illuminate\Validation\Rule`, `Illuminate\Support\Facades\DB`, `Illuminate\Validation\ValidationException` — check each before adding; several are likely present already.

In `store()`, spread the result into the `InspectionDefinition::create([...])` array. In `update()`, spread it into the update payload.

**Read `ScopeReach::assertCovers` before using it** (`app/Support/Access/ScopeReach.php:127`) and confirm its `$field` default is `'scope_id'`; if not, pass `'scope_id'` explicitly so the error lands on the right field.

- [ ] **Step 5: Constrain the Builder list**

In `index()`, add immediately after the `authorize` call:

```php
        $query = InspectionDefinition::query()->with('currentVersion')->orderBy('name');

        // The Builder lists what exists HERE, the same as the picker does.
        // Without this a franchise manager browses every company's inspections
        // and can open one they may not run.
        $this->scope->constrain($query, request()->user());
```

and build `$rows` from `$query->get()` instead of the inline query. Add `private readonly InspectionScope $scope,` to the constructor and import it.

- [ ] **Step 6: Run the tests and watch them pass**

Run: `DB_DATABASE=ezbizi_testing_inspection php -d memory_limit=512M vendor/bin/phpunit --filter="InspectionBuilderScopeTest|InspectionScopeTest|InspectionRunsTest"`

Expected: PASS, all.

- [ ] **Step 7: Run the whole inspection surface**

Run: `DB_DATABASE=ezbizi_testing_inspection php -d memory_limit=512M vendor/bin/phpunit --filter="Inspection|Attachments"`

Expected: PASS. Anything red here is a regression in this task, not a flake — read it.

- [ ] **Step 8: Commit**

Paths: `app/Http/Controllers/Api/InspectionDefinitionsController.php`, `tests/Feature/Api/InspectionBuilderScopeTest.php`

Message: `The Builder says where an inspection belongs, and refuses a place you cannot reach`

---

### Task 5: The scope picker on screen

**Files:**
- Modify: `client/lib/screens/inspection/builder_tab.dart`
- Test: `client/test/inspection_builder_test.dart` (append)

**Interfaces:**
- Consumes: the `scope` key on the definition payload and the `scope_type`/`scope_id` PATCH keys from Task 4.
- Produces: nothing other tasks consume.

**Where the options come from:** the Builder already loads `/api/v1/inspections/catalogue` for layout presets (see `_load` around `builder_tab.dart:136`). Add a `scopes` key to that response. Adding a key to an existing response is safe under the App Store constraint.

`ScopeReach::optionsFor($user)` (`app/Support/Access/ScopeReach.php:194`) is the source, but **it does not return the shape the picker wants** — verified 2026-08-28, it returns three separate lists:

```php
['organization' => ?['id','name'], 'companies' => [['id','name','parent_company_id','is_active'], …],
 'franchises' => [['id','name','company_id','is_active'], …]]
```

Flatten it in the catalogue action into one ordered list the dropdown can render directly:

```php
        $options = $this->reach->optionsFor($request->user());

        $scopes = [];

        // The organization first, and only when this person may act on it —
        // `optionsFor` returns null otherwise, and a franchise manager offered
        // "Whole organization" would be offered a scope the save then refuses.
        if ($options['organization'] !== null) {
            $scopes[] = ['type' => null, 'id' => null, 'label' => 'Whole organization'];
        }

        foreach ($options['companies'] as $company) {
            $scopes[] = ['type' => 'company', 'id' => $company['id'], 'label' => $company['name']];
        }

        foreach ($options['franchises'] as $franchise) {
            $scopes[] = ['type' => 'franchise', 'id' => $franchise['id'], 'label' => $franchise['name']];
        }
```

Return `$scopes` under a `scopes` key alongside the existing `layout_presets`. Two names can repeat across levels — a company and its only franchise are often both "A1Chimney" — so the label is not unique and the dropdown must key on `type:id`, which Step 3 does.

- [ ] **Step 1: Write the failing widget test**

Append to `client/test/inspection_builder_test.dart`:

```dart
  testWidgets('the scope picker offers the organization and the places you reach',
      (tester) async {
    await _pumpBuilder(tester, catalogue: _catalogueWithScopes, definition: _definition);

    expect(find.byKey(const Key('inspection-scope-picker')), findsOneWidget);

    // The organization is an option, not an absence. "Belongs to nobody" and
    // "belongs to everybody" read identically on screen otherwise.
    expect(find.text('Whole organization'), findsOneWidget);
    expect(find.text('A1Chimney'), findsOneWidget);
  });
```

You will need to extend the existing `_pumpBuilder` helper in that file to accept a `catalogue` argument, and add a `_catalogueWithScopes` constant whose `scopes` list contains `{"type": null, "id": null, "label": "Whole organization"}` and `{"type": "company", "id": "c-1", "label": "A1Chimney"}`. **Read the existing helper first** and follow its shape — do not write a second pump helper.

- [ ] **Step 2: Run it and watch it fail**

Run (from `client/`): `flutter test test/inspection_builder_test.dart`

Expected: FAIL — no widget with key `inspection-scope-picker`.

- [ ] **Step 3: Add the picker**

In the settings pane of `builder_tab.dart`, beside the existing name field and layout-preset chips (around line 492–514), add a `DropdownButtonFormField<String>` keyed `inspection-scope-picker`. Compose its value as `'${type ?? "organization"}:${id ?? ""}'` so the organization is a real selectable value rather than a null, and map back on change:

```dart
        DropdownButtonFormField<String>(
          key: const Key('inspection-scope-picker'),
          initialValue: _scopeValue(current),
          isExpanded: true,
          decoration: const InputDecoration(labelText: 'Belongs to'),
          items: [
            for (final scope in _scopes)
              DropdownMenuItem(
                value: '${scope['type'] ?? 'organization'}:${scope['id'] ?? ''}',
                child: Text('${scope['label'] ?? ''}'),
              ),
          ],
          onChanged: (value) {
            if (value == null) return;
            final parts = value.split(':');
            final type = parts.first == 'organization' ? null : parts.first;
            final id = parts.length > 1 && parts[1].isNotEmpty ? parts[1] : null;
            _mutate((row) => row['scope'] = {'type': type, 'id': id});
          },
        ),
```

Add `_scopes` beside `_presets` and populate it in `_load` from `data['scopes']`. In the PATCH body (around line 169) send `'scope_type'` and `'scope_id'` read from `current['scope']` — **not** a nested `scope` object, because that is not what Task 4 accepts.

Use `Theme.of(context)` for any colour. No literals.

- [ ] **Step 4: Run the test and watch it pass**

Run: `flutter test test/inspection_builder_test.dart`

Expected: PASS.

- [ ] **Step 5: Analyze and run the whole client suite**

Run: `flutter analyze` — expected: `No issues found!`
Run: `flutter test test/` — expected: all pass.

- [ ] **Step 6: Commit**

Paths: `client/lib/screens/inspection/builder_tab.dart`, `client/test/inspection_builder_test.dart`, plus the controller file if you added `scopes` to the catalogue there.

Message: `Choose in the Builder which place an inspection belongs to`

---

### Task 6: Say it in the docs, and hand it over

**Files:**
- Modify: `docs/EZBIZI-TODO.md`
- Modify: `docs/EZBIZI-STATUS.md`

- [ ] **Step 1: Record it**

Add to the Done section of `docs/EZBIZI-TODO.md`, dated, in the voice of the surrounding entries — what changed, and the one thing a reader would otherwise get wrong:

> **Inspections belong to a place** — *2026-08-29.* A named inspection is the
> organization's, a company's, or a franchise's, using the same
> `scope_type`/`scope_id` pair Automation and Mailbox carry. Scope decides where
> a definition EXISTS; the per-role grant still decides who may run it, and both
> must pass. Visibility walks UP a franchise's company chain and never down, so
> a technician sees the inspection their parent company owns and never a
> sibling franchise's — the case `InspectionScope` exists for, because
> `ScopeReach::covers` answers the other question and would hide a
> company-scoped inspection from everybody meant to run it.

Update the Inspection row on `docs/EZBIZI-STATUS.md` and the page date in the same commit as the last green test, never before.

- [ ] **Step 2: Commit**

Message: `docs: an inspection belongs to a place`

- [ ] **Step 3: Hand the SHAs to the deploy session**

Send `ezbizi-a1` the commit SHAs, one line on what changed, and these flags explicitly: **one migration** (`add_scope_to_inspection_definitions`, additive, nullable, no backfill), **no permission changes**, **one response-shape addition** (`scope` on definition payloads — additive, safe under `ACTIVE-CONSTRAINTS.md`), and **no minimum client version**.

- [ ] **Step 4: Tick the board**

Add a step to the `inspection` track on the integrations board (`http://localhost:8787`, `PUT /api/step` with `{id, label, detail}`) in this same turn. Leave it unticked until the release lands — on that board `done` has meant shipped.

---

## Self-Review

**Spec coverage.** This plan implements §4 of the design and nothing else. §5 (conditions, nesting, variants, field types, code library, services, PDF mapping), §6 (Builder UI for those), and §9 (the port) are out of scope by design and get their own plans — the spec's own §8 sequence lists them as phases 2–9. No part of §4 is unimplemented: the scope pair (Task 1), the company-subtree reach and the refusal of sideways visibility (Task 2), the picker (Task 3), the Builder list and authoring rules (Task 4), the UI (Task 5), the record (Task 6).

**Placeholder scan.** No TBD, no "add validation", no "similar to Task N". Three steps deliberately say *read this file first* — `BuildsTenants`, `ScopeReach::assertCovers`, `ScopeReach::optionsFor`, and the existing `_pumpBuilder` — because each is shared code whose current shape the plan must not guess at. That is an instruction to verify, not a gap.

**Type consistency.** `InspectionScope::constrain(Builder, User): void` is defined in Task 2 and called with that signature in Tasks 3 and 4. `InspectionDefinition::SCOPE_COMPANY` / `SCOPE_FRANCHISE` are defined in Task 1 and used in Tasks 2, 3 and 4. The response key is `scope: {type, id}` in Task 4 and read as `data['scope']` in Task 5; the PATCH keys are `scope_type` / `scope_id` in both, and Task 5 says so explicitly because sending the nested object would silently do nothing.

**Two assumptions checked rather than left as risks** (2026-08-28):

- `provisionTenant()` already returns `company`, so Task 2 needs no change there. It has **no** second-franchise helper, so Task 2 adds `extraFranchise()` — a file every module's tests read, kept strictly additive.
- `ScopeReach::optionsFor()` returns three lists, **not** the flat `{type,id,label}` the dropdown wants. Task 5 now carries the flattening code rather than telling the executor to "match its shape", which is the kind of instruction that produces two incompatible guesses.

**The remaining risk is Task 3's blast radius.** Constraining `startable()` changes what every existing technician's picker offers. Every current definition has a null scope and is therefore organization-wide, so the correct behaviour is *no change at all* for existing data — and that is exactly what makes a mistake here invisible. `test_an_organization_wide_inspection_is_visible_to_everybody` in Task 2 is the guard, and Task 3's Step 4 runs the whole `InspectionRunsTest` file rather than just the new cases for the same reason.
