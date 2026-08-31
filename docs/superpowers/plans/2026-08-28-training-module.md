# Training Module Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Ship a Training module where a company authors guides (screens of text, linked images and linked video) and multiple-choice tests, assigns them or offers them as a library, and watches progress and results.

**Architecture:** Five tables carrying the same `company_id`/`franchise_id` place pair every other module uses, so `InventoryVisibility::constrain` is the whole scope engine and no new one is written. Screens and questions are JSON columns rather than child tables. Grading is server-side and the answer key never reaches a learner. Events go through the automations outbox, never dispatched inline.

**Tech Stack:** Laravel 13 · PHP 8.4 · MySQL 8 · Flutter web (`client/`).

**Spec:** `docs/superpowers/specs/2026-08-28-training-module-design.md` — read it before Task 1. Decisions are numbered there and cited here.

**Branch:** `session/training`, worktree `H:/Development/wt/training`, based on `0c0c21f`. Commit here. Do not commit in the shared tree.

## Global Constraints

- **Tests:** `DB_CONNECTION=mysql DB_DATABASE=ezbizi_testing_training vendor/bin/phpunit --filter=<Test>`. Never the shared `ezbizi_testing` schema — `RefreshDatabase` drops it, and two sessions testing at once destroy each other with no useful error.
- **`DB_CONNECTION=mysql` is mandatory on anything that is not the test runner, and this is the trap most likely to waste an afternoon.** `phpunit.xml:26` pins the connection, so under phpunit `DB_DATABASE=` names a *schema*. Outside it, `.env` says sqlite and Laravel reads `DB_DATABASE` as a **file path** — so `DB_DATABASE=ezbizi_testing_training php artisan migrate` **exits 0, prints every migration as though it ran, creates an empty SQLite file in the repo root, and touches no MySQL at all.** Nothing is red. Reported by the deploy session on 2026-08-28.
- **Never** `DB::unprepared` or `CREATE TRIGGER`. MySQL refuses both without SUPER while binary logging is on.
- **No assertion may depend on JSON key order.** MySQL sorts object keys by length then lexicographically. Array *element* order is safe and is what screen and question ordering relies on.
- **An App Store reviewer is running a frozen client against live production.** New tables, endpoints, routes and all client work ship freely. Do not change an existing response's fields, tighten an existing permission answer, or alter a `Refusal` sentence. Registering `training.assign` is the one action to check against `git show 8327833:client/lib/screens/automation_builder.dart` first — actions are *guarded* rather than tolerant.
- **Never use a literal colour in the client.** Take every colour from `client/lib/core/theme.dart`.
- **No modals.** A selection is a pane or a screen.
- **Must work at 375px.** No assumption of a wide viewport, a mouse, or hover.
- **Never write "certification" in Training.** HR's panel already owns that word for licence expiries typed at onboarding. Training says **"due for retaking"** (spec decision 8).
- **Never read a setting from a row** — ask `SettingsResolver`. **Never use `me.companies.first`** — use `filingCompanyId` / `companiesInView`.
- **A test that cannot fail is not a test.** After each one passes, delete the line of production code it targets, watch it go red, restore.
- Shared files get one block each and are announced before editing: `routes/api.php`, `app/Providers/ModuleServiceProvider.php`, `client/lib/core/module_screens.dart`.

---

### Task 1: Schema and models

**Files:**
- Create: `database/migrations/<timestamp>_create_training_tables.php`
- Create: `app/Models/TrainingGuide.php`, `TrainingTest.php`, `TrainingAssignment.php`, `TrainingProgress.php`, `TrainingAttempt.php`
- Create: `database/factories/TrainingGuideFactory.php`, `TrainingTestFactory.php`
- Test: `tests/Feature/Training/TrainingSchemaTest.php`

**Interfaces:**
- Consumes: nothing.
- Produces: the five models. Every one uses `HasUuids` + `BelongsToTenant`. `TrainingGuide::$screens` and `TrainingTest::$questions` cast to `array`. `TrainingAttempt::$answers` casts to `array`. `TrainingTest` has `requires_guide:bool`, `pass_mark:int`, `max_attempts:?int`, `valid_for_days:?int`.

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature\Training;

use App\Models\TrainingGuide;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

class TrainingSchemaTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    public function test_a_guide_keeps_its_screens_in_the_order_they_were_written(): void
    {
        $tenant = $this->provisionTenant('Alpha');

        $guide = $this->inTenant($tenant['org'], fn () => TrainingGuide::create([
            'company_id' => $tenant['company']->id,
            'title' => 'Ladder Safety',
            'status' => TrainingGuide::PUBLISHED,
            'screens' => [
                ['title' => 'Why this exists', 'body' => 'a', 'media_kind' => 'none', 'media_url' => null],
                ['title' => 'The four-to-one rule', 'body' => 'b', 'media_kind' => 'video', 'media_url' => 'https://youtu.be/x'],
            ],
        ]));

        // Element order survives MySQL. Key order deliberately not asserted.
        $screens = $guide->fresh()->screens;

        $this->assertCount(2, $screens);
        $this->assertSame('Why this exists', $screens[0]['title']);
        $this->assertSame('The four-to-one rule', $screens[1]['title']);
        $this->assertSame('video', $screens[1]['media_kind']);
    }
}
```

- [ ] **Step 2: Run it and watch it fail**

```bash
DB_CONNECTION=mysql DB_DATABASE=ezbizi_testing_training vendor/bin/phpunit --filter=TrainingSchemaTest
```

Expected: FAIL — `Class "App\Models\TrainingGuide" not found`.

- [ ] **Step 3: Write the migration**

One file, five tables, matching `2026_08_24_160000_create_inventory_tables.php`.

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Guides, tests, and who has done them
 * (docs/superpowers/specs/2026-08-28-training-module-design.md).
 *
 * Screens and questions are JSON rather than child tables: neither has an
 * identity anything queries, a permission of its own, or a life outside its
 * parent, and reordering is a list splice instead of a position-column
 * rewrite. "Which guides embed this video" is the query this gives up.
 *
 * The place pair (company_id, franchise_id) is the same one Inventory and
 * Services carry, which is what lets InventoryVisibility::constrain serve
 * this module without a new engine.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('training_guides', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained()->cascadeOnDelete();
            $this->placeColumns($table);
            $table->string('title', 191);
            $table->string('summary', 500)->nullable();
            $table->string('status', 16);
            $table->json('screens');
            $table->timestamps();

            $table->index(['organization_id', 'company_id', 'franchise_id']);
            $table->index(['organization_id', 'status']);
        });

        Schema::create('training_tests', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained()->cascadeOnDelete();
            $this->placeColumns($table);
            $table->foreignUuid('guide_id')->nullable()
                ->constrained('training_guides')->nullOnDelete();
            $table->string('title', 191);
            $table->string('status', 16);
            $table->json('questions');
            $table->boolean('requires_guide')->default(false);
            $table->unsignedTinyInteger('pass_mark')->default(80);
            $table->unsignedSmallInteger('max_attempts')->nullable();
            $table->unsignedSmallInteger('valid_for_days')->nullable();
            $table->timestamps();

            $table->index(['organization_id', 'company_id', 'franchise_id']);
        });

        Schema::create('training_assignments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained()->cascadeOnDelete();
            $this->placeColumns($table);
            $table->foreignUuid('guide_id')->nullable()
                ->constrained('training_guides')->cascadeOnDelete();
            $table->foreignUuid('test_id')->nullable()
                ->constrained('training_tests')->cascadeOnDelete();
            $table->string('assignee_type', 8);          // user | role
            $table->uuid('assignee_id');
            $table->date('due_on')->nullable();
            $table->foreignUuid('assigned_by_user_id')->constrained('users')->cascadeOnDelete();
            $table->timestamps();

            $table->index(['organization_id', 'assignee_type', 'assignee_id']);
        });

        Schema::create('training_progress', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('user_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('guide_id')->constrained('training_guides')->cascadeOnDelete();
            $table->unsignedSmallInteger('last_screen')->default(0);
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'guide_id']);
        });

        Schema::create('training_attempts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('user_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('test_id')->constrained('training_tests')->cascadeOnDelete();
            $table->json('answers');
            $table->unsignedTinyInteger('score');
            $table->boolean('passed');
            $table->timestamp('taken_at');
            $table->date('expires_on')->nullable();
            $table->timestamps();

            $table->index(['organization_id', 'test_id', 'user_id']);
            $table->index(['organization_id', 'expires_on']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('training_attempts');
        Schema::dropIfExists('training_progress');
        Schema::dropIfExists('training_assignments');
        Schema::dropIfExists('training_tests');
        Schema::dropIfExists('training_guides');
    }

    private function placeColumns(Blueprint $table): void
    {
        $table->foreignUuid('company_id')->nullable()->constrained('companies')->nullOnDelete();
        $table->foreignUuid('franchise_id')->nullable()->constrained('franchises')->nullOnDelete();
    }
};
```

Then `TrainingGuide`:

```php
<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Support\Privacy\RetentionPolicy;
use App\Support\Privacy\StatesRetention;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * An ordered run of screens somebody reads.
 *
 * Screens are a JSON array rather than a table — see the migration for why.
 */
class TrainingGuide extends Model implements StatesRetention
{
    use HasUuids;
    use BelongsToTenant;

    public const DRAFT = 'draft';

    public const PUBLISHED = 'published';

    protected $fillable = [
        'company_id', 'franchise_id', 'title', 'summary', 'status', 'screens',
    ];

    protected $casts = ['screens' => 'array'];

    public static function retention(): RetentionPolicy
    {
        return RetentionPolicy::forever(
            'Authored content, not a record of anybody. A guide is deleted when its '
            .'organization deletes it; ageing one out would silently remove training '
            .'people are still recorded as having passed.'
        );
    }

    public function tests(): HasMany
    {
        return $this->hasMany(TrainingTest::class, 'guide_id');
    }

    public function screenCount(): int
    {
        return count($this->screens ?? []);
    }
}
```

`TrainingTest` follows the same shape with `protected $casts = ['questions' => 'array', 'requires_guide' => 'bool']` and the same `forever` retention reason adapted. `TrainingAssignment`, `TrainingProgress` and `TrainingAttempt` are written in Task 2, where their §9 declarations are tested.

- [ ] **Step 4: Run it and watch it pass**

```bash
DB_CONNECTION=mysql DB_DATABASE=ezbizi_testing_training vendor/bin/phpunit --filter=TrainingSchemaTest
```

Expected: PASS. Then delete the `'screens' => 'array'` cast, re-run, confirm RED, restore.

- [ ] **Step 5: Commit**

```bash
git add database/migrations database/factories app/Models/Training*.php tests/Feature/Training/TrainingSchemaTest.php
git commit -m "Give Training its five tables"
```

---

### Task 2: The §9 declarations, and the retention gate

**Files:**
- Modify: `app/Models/TrainingAssignment.php`, `TrainingProgress.php`, `TrainingAttempt.php`
- Test: `tests/Feature/Training/TrainingPersonalDataTest.php`

**Interfaces:**
- Consumes: the five models from Task 1.
- Produces: `TrainingAttempt`, `TrainingProgress` and `TrainingAssignment` implement `HoldsPersonalData` — `personalDataPurpose()`, `retention()`, `personalDataHeld(): PersonalDataEntry[]`, `personalDataAbout(Model $subject): ?Builder`.

`RetentionTest` refused two deploys the day this was written. It walks every model that accumulates rows and fails the build for one that has not decided. Doing this in its own task is deliberate: it is the gate, not a detail of Task 1.

- [ ] **Step 1: Write the failing test**

```php
public function test_every_training_table_has_decided_about_retention(): void
{
    foreach ([TrainingGuide::class, TrainingTest::class, TrainingAssignment::class,
              TrainingProgress::class, TrainingAttempt::class] as $model) {
        $this->assertNotSame('', trim($model::retention()->sentence()),
            "{$model} must state a retention policy with a reason.");
    }
}

public function test_an_attempt_is_findable_as_personal_data_about_its_learner(): void
{
    $tenant = $this->provisionTenant('Alpha');
    $learner = $tenant['admin'];

    $this->assertNotNull(
        TrainingAttempt::personalDataAbout($learner),
        'A test attempt is a record of a named person being examined.'
    );
}
```

- [ ] **Step 2: Run it and watch it fail**

```bash
DB_CONNECTION=mysql DB_DATABASE=ezbizi_testing_training vendor/bin/phpunit --filter=TrainingPersonalDataTest
```

Expected: FAIL — `Call to undefined method App\Models\TrainingAttempt::retention()`.

- [ ] **Step 3: Declare it on `TrainingAttempt`**

```php
public static function personalDataPurpose(): string
{
    return 'Which test somebody took, when, what they answered and whether they passed. '
        .'It is the record an organization relies on to show that the person doing the '
        .'work had been trained for it.';
}

public static function retention(): RetentionPolicy
{
    return RetentionPolicy::forever(
        'The record of who passed what, and when, is the whole reason this module '
        .'exists. An age-based window would delete the oldest pass first — the one a '
        .'later dispute or an insurer is most likely to ask about. The account itself '
        .'is anonymised at its one home when somebody exercises erasure.'
    );
}

public static function personalDataHeld(): array
{
    return [
        new PersonalDataEntry('user_id', PersonalDataEntry::IDENTITY,
            'Which person sat this test.',
            Erasure::keep('A foreign key to the account, which the purge anonymises at '
                .'its one home. Nulling it here would orphan a result from the person '
                .'it is about without removing anything about them.')),
        new PersonalDataEntry('answers, score, passed, taken_at', PersonalDataEntry::ACTIVITY,
            'What they answered, what they scored, and whether that was a pass.',
            Erasure::keep('The result is the record. Blanking it would leave a row '
                .'saying somebody was tested without saying how it went, which is worse '
                .'than a result against an already-anonymised account.')),
    ];
}

public static function personalDataAbout(Model $subject): ?Builder
{
    return $subject instanceof User
        ? static::query()->where('user_id', $subject->getKey())
        : null;
}
```

`TrainingProgress` and `TrainingAssignment` take the same shape. Progress holds `user_id`, `guide_id`, `last_screen`; assignment holds `assignee_id` and `assigned_by_user_id` and its `personalDataAbout` matches **both** columns with an `orWhere`, because an assignment is a fact about the person who made it as well as the person who received it.

- [ ] **Step 4: Run both the new test and the real gate**

```bash
DB_CONNECTION=mysql DB_DATABASE=ezbizi_testing_training vendor/bin/phpunit --filter=TrainingPersonalDataTest
DB_CONNECTION=mysql DB_DATABASE=ezbizi_testing_training vendor/bin/phpunit --filter=RetentionTest
```

Expected: both PASS. `RetentionTest` is the one that matters — it is the deploy gate.

- [ ] **Step 5: Commit**

```bash
git add app/Models/Training*.php tests/Feature/Training/TrainingPersonalDataTest.php
git commit -m "Say what Training keeps about people, and why it keeps it"
```

---

### Task 3: Declare the module

**Files:**
- Modify: `app/Providers/ModuleServiceProvider.php` — one new block in `businessModules()`
- Modify: `app/Http/Controllers/Api/AutomationsController.php` — the `IMPLEMENTED_ACTIONS` const only
- Test: `tests/Feature/Training/TrainingModuleTest.php`

**Interfaces:**
- Consumes: nothing from earlier tasks.
- Produces: module key `training`, actions `view`, `monitor`, `manage`, `assign` — so permission keys `training.view`, `training.monitor`, `training.manage`, `training.assign`. Triggers `training.assigned`, `training.completed`, `training.passed`, `training.failed`, `training.expired`. Action `training.assign`.

**Announce before editing** — `ModuleServiceProvider.php` is a contested file; every module writes its own block.

**Check the frozen client first.** Actions are guarded rather than tolerant:

```bash
git show 8327833:client/lib/screens/automation_builder.dart | sed -n '160,200p;520,540p'
```

Confirm `knownTypes` is enumerated and an unknown key returns `enabled: false` rather than throwing. Record what you saw in the commit message.

**Settle the automation subject here.** `AutomationSubject` binds a module to one record class and Training has three. Read `app/Support/Modules/AutomationSubject.php` and `ModuleRegistryTest`, pick the class whose fields a rule would sensibly set, and write the reasoning into the block as a comment. This is the spec's one open thread (§12); it is resolved in code, not guessed.

- [ ] **Step 1: Write the failing test**

```php
public function test_training_declares_four_actions_and_its_five_triggers(): void
{
    $module = app(ModuleRegistry::class)->get('training');

    $this->assertSame(['view', 'monitor', 'manage', 'assign'], $module->actions);

    $triggers = collect($module->triggers)->pluck('key')->all();
    $this->assertEqualsCanonicalizing([
        'training.assigned', 'training.completed', 'training.passed',
        'training.failed', 'training.expired',
    ], $triggers);
}

public function test_the_assign_action_is_actually_implemented_not_just_declared(): void
{
    // AutomationsController holds a second, private const. An action absent from
    // it publishes as implemented:false and is refused at run time — silently,
    // with nothing red anywhere.
    $reflected = new \ReflectionClass(\App\Http\Controllers\Api\AutomationsController::class);

    $this->assertContains(
        'training.assign',
        $reflected->getConstant('IMPLEMENTED_ACTIONS'),
        'Declaring the action in ModuleServiceProvider is only half of it.'
    );
}
```

- [ ] **Step 2: Run it and watch it fail**

```bash
DB_CONNECTION=mysql DB_DATABASE=ezbizi_testing_training vendor/bin/phpunit --filter=TrainingModuleTest
```

Expected: FAIL — no module registered under `training`.

- [ ] **Step 3: Add the block**

```php
new ModuleDefinition(
    key: 'training',
    label: 'Training',
    actions: ['view', 'monitor', 'manage', 'assign'],
    triggers: [
        new TriggerDefinition(
            key: 'training.assigned',
            label: 'Training assigned',
            description: 'Somebody was given a guide or a test to do. It carries what '
                .'they were given, who gave it to them, and the date it is due if there '
                .'is one.',
        ),
        new TriggerDefinition(
            key: 'training.completed',
            label: 'Guide finished',
            description: 'Somebody reached the end of a guide. This is about reading it, '
                .'not about passing anything — a guide with no test raises only this.',
        ),
        new TriggerDefinition(
            key: 'training.passed',
            label: 'Test passed',
            description: 'Somebody took a test and met its pass mark. It carries the '
                .'score and, when the test expires, the date the pass runs out.',
        ),
        new TriggerDefinition(
            key: 'training.failed',
            label: 'Test failed',
            description: 'Somebody took a test and did not meet the pass mark. Whether '
                .'they may try again is the test\'s own retake setting.',
        ),
        /*
         * Expiry is a fact about the CLOCK, and this is the event that makes the
         * whole "a pass runs out" option worth having: "re-assign the ladder guide
         * when the pass lapses" is the rule an organization writes once.
         *
         * The nightly sweep INSERTS this into the outbox. It must never dispatch:
         * one rule failing while re-assigning one lapsed pass would otherwise take
         * down the sweep for everybody else's.
         */
        new TriggerDefinition(
            key: 'training.expired',
            label: 'Pass ran out',
            description: 'A test pass reached the end of the period it was good for. '
                .'The training reappears in that person\'s list as due for retaking.',
        ),
    ],
    actionDefinitions: [
        new ActionDefinition(
            key: 'training.assign',
            label: 'Assign training',
            description: 'Give somebody a guide or a test, optionally with a date it is '
                .'due by. Paired with "Account activated" this is how an induction '
                .'arrives on its own.',
        ),
    ],
),
```

Match the real constructor parameter names in `ModuleDefinition` — read the class rather than trusting this block's argument labels.

Then add `'training.assign'` to `IMPLEMENTED_ACTIONS` in `AutomationsController`, and wire the runner arm in Task 9.

- [ ] **Step 4: Run the module gate**

```bash
DB_CONNECTION=mysql DB_DATABASE=ezbizi_testing_training vendor/bin/phpunit --filter=TrainingModuleTest
DB_CONNECTION=mysql DB_DATABASE=ezbizi_testing_training vendor/bin/phpunit --filter=ModuleRegistryTest
```

Expected: both PASS. `ModuleRegistryTest` fails the build for a module that declares no triggers without saying why — Training declares five, so it has nothing to exempt.

- [ ] **Step 5: Commit**

```bash
git add app/Providers/ModuleServiceProvider.php app/Http/Controllers/Api/AutomationsController.php tests/Feature/Training/TrainingModuleTest.php
git commit -m "Declare Training, its four permissions and its five triggers"
```

---

### Task 4: Guides API, and the scope rule that must not leak

**Files:**
- Create: `app/Http/Controllers/Api/Training/GuidesController.php`
- Create: `app/Policies/TrainingGuidePolicy.php`
- Modify: `routes/api.php` — one Training block
- Test: `tests/Feature/Training/TrainingGuideScopeTest.php`

**Interfaces:**
- Consumes: `TrainingGuide` (Task 1), permission keys (Task 3).
- Produces: `GET|POST /training/guides`, `GET|PATCH|DELETE /training/guides/{guide}`. Response per guide: `{id, title, summary, status, company_id, franchise_id, screen_count, screens}`.

- [ ] **Step 1: Write the failing test — the sibling-franchise case is the point**

```php
public function test_a_franchise_sees_organization_guides_but_never_a_siblings(): void
{
    $tenant = $this->provisionTenant('Alpha');
    $riverside = $tenant['franchise'];
    $northgate = $this->addFranchise($tenant['company'], 'Northgate');

    $this->inTenant($tenant['org'], function () use ($tenant, $riverside, $northgate) {
        TrainingGuide::create(['company_id' => null, 'franchise_id' => null,
            'title' => 'Org wide', 'status' => 'published', 'screens' => []]);
        TrainingGuide::create(['company_id' => $tenant['company']->id, 'franchise_id' => $riverside->id,
            'title' => 'Riverside only', 'status' => 'published', 'screens' => []]);
        TrainingGuide::create(['company_id' => $tenant['company']->id, 'franchise_id' => $northgate->id,
            'title' => 'Northgate only', 'status' => 'published', 'screens' => []]);
    });

    $titles = collect($this->actingAs($this->userInFranchise($riverside, ['training.view']))
        ->getJson('/api/training/guides')->json('data'))->pluck('title');

    $this->assertContains('Org wide', $titles, 'An organization guide must reach a franchise.');
    $this->assertContains('Riverside only', $titles);
    $this->assertNotContains('Northgate only', $titles, 'A sibling franchise must never leak.');
}
```

Use the tenant helpers that already exist in `Tests\Concerns\BuildsTenants`; read it and use its real method names rather than the illustrative ones above.

- [ ] **Step 2: Run it and watch it fail**

```bash
DB_CONNECTION=mysql DB_DATABASE=ezbizi_testing_training vendor/bin/phpunit --filter=TrainingGuideScopeTest
```

Expected: FAIL — 404, the route does not exist.

- [ ] **Step 3: Write the controller index against the existing engine**

```php
public function index(Request $request): JsonResponse
{
    $this->authorize('viewAny', TrainingGuide::class);

    $user = $request->user();
    $scope = ViewScope::forUser($user);

    // Not a new engine, and deliberately not a TrainingVisibility wrapper: this
    // class already IS the shared place-scope engine — organization rows to the
    // whole tenant, company General to anyone who can see that company,
    // franchise rows only when that franchise is in view. Services delegates to
    // it for the same reason. It is misnamed for the job, and renaming it edits
    // two other modules' files.
    $query = InventoryVisibility::constrain(TrainingGuide::query(), $user, $scope);

    $guides = $query->orderBy('title')->get();

    return response()->json([
        'data' => $guides->map(fn (TrainingGuide $g) => $this->present($g)),
    ]);
}
```

The policy mirrors `InventoryItemPolicy`: `viewAny` checks `training.view` and refuses with `Refusal::permission('see training')`; `create`/`update` check `training.manage` and then reach, refusing with `Refusal::noReach(...)`.

- [ ] **Step 4: Run it and watch it pass**

```bash
DB_CONNECTION=mysql DB_DATABASE=ezbizi_testing_training vendor/bin/phpunit --filter=TrainingGuideScopeTest
```

Expected: PASS. Then replace `InventoryVisibility::constrain($q, ...)` with a bare `TrainingGuide::query()`, re-run, confirm the sibling assertion goes RED, restore.

- [ ] **Step 5: Commit**

```bash
git add app/Http/Controllers/Api/Training/GuidesController.php app/Policies/TrainingGuidePolicy.php routes/api.php tests/Feature/Training/TrainingGuideScopeTest.php
git commit -m "Let a franchise read its own training and the organization's, and nobody else's"
```

---

### Task 5: Tests API, with the four builder options

**Files:**
- Create: `app/Http/Controllers/Api/Training/TestsController.php`, `app/Policies/TrainingTestPolicy.php`
- Modify: `routes/api.php`
- Test: `tests/Feature/Training/TrainingTestAuthoringTest.php`

**Interfaces:**
- Consumes: `TrainingTest` (Task 1), `training.manage` (Task 3).
- Produces: `GET|POST /training/tests`, `GET|PATCH|DELETE /training/tests/{test}`. A test accepts `guide_id`, `questions`, `requires_guide`, `pass_mark` (1–100), `max_attempts` (null or ≥1), `valid_for_days` (null or ≥1).

- [ ] **Step 1: Write the failing test**

```php
public function test_the_four_options_are_stored_as_written(): void
{
    $tenant = $this->provisionTenant('Alpha');
    $author = $this->userInCompany($tenant['company'], ['training.manage']);

    $response = $this->actingAs($author)->postJson('/api/training/tests', [
        'company_id' => $tenant['company']->id,
        'title' => 'Ladder Safety',
        'requires_guide' => true,
        'pass_mark' => 80,
        'max_attempts' => 3,
        'valid_for_days' => 365,
        'questions' => [[
            'prompt' => 'A ladder rests at 16 feet. How far out does the base go?',
            'choices' => [
                ['text' => 'Two feet', 'correct' => false],
                ['text' => 'Four feet', 'correct' => true],
            ],
        ]],
    ]);

    $response->assertCreated();
    $test = TrainingTest::findOrFail($response->json('data.id'));

    $this->assertTrue($test->requires_guide);
    $this->assertSame(80, $test->pass_mark);
    $this->assertSame(3, $test->max_attempts);
    $this->assertSame(365, $test->valid_for_days);
}

public function test_a_pass_mark_outside_one_to_a_hundred_is_refused(): void
{
    $tenant = $this->provisionTenant('Alpha');
    $author = $this->userInCompany($tenant['company'], ['training.manage']);

    $this->actingAs($author)->postJson('/api/training/tests', [
        'company_id' => $tenant['company']->id,
        'title' => 'Nonsense', 'pass_mark' => 140, 'questions' => [],
    ])->assertStatus(422);
}
```

- [ ] **Step 2: Run it and watch it fail**

```bash
DB_CONNECTION=mysql DB_DATABASE=ezbizi_testing_training vendor/bin/phpunit --filter=TrainingTestAuthoringTest
```

Expected: FAIL — 404.

- [ ] **Step 3: Write the controller with validation**

```php
$data = $request->validate([
    'company_id' => ['nullable', 'uuid'],
    'franchise_id' => ['nullable', 'uuid'],
    'guide_id' => ['nullable', 'uuid'],
    'title' => ['required', 'string', 'max:191'],
    'requires_guide' => ['boolean'],
    'pass_mark' => ['integer', 'min:1', 'max:100'],
    'max_attempts' => ['nullable', 'integer', 'min:1', 'max:99'],
    'valid_for_days' => ['nullable', 'integer', 'min:1', 'max:3650'],
    'questions' => ['array'],
    'questions.*.prompt' => ['required', 'string', 'max:1000'],
    'questions.*.choices' => ['required', 'array', 'min:2'],
    'questions.*.choices.*.text' => ['required', 'string', 'max:500'],
    'questions.*.choices.*.correct' => ['required', 'boolean'],
]);
```

Validation at a trust boundary is not a place to be lazy: a question with no correct choice can never be passed, and one with two is ambiguous. Add a rule that each question has exactly one `correct => true`, and refuse with a sentence a person can act on: *"Every question needs exactly one right answer. Question 3 has none."*

- [ ] **Step 4: Run it and watch it pass**

```bash
DB_CONNECTION=mysql DB_DATABASE=ezbizi_testing_training vendor/bin/phpunit --filter=TrainingTestAuthoringTest
```

Expected: PASS. Delete the `max:100` rule, re-run, confirm the second test goes RED, restore.

- [ ] **Step 5: Commit**

```bash
git add app/Http/Controllers/Api/Training/TestsController.php app/Policies/TrainingTestPolicy.php routes/api.php tests/Feature/Training/TrainingTestAuthoringTest.php
git commit -m "Build a test, and decide in the builder how it is passed"
```

---

### Task 6: The Learn read — and the answer key that must not travel

**Files:**
- Create: `app/Http/Controllers/Api/Training/LearnController.php`
- Create: `app/Support/Training/LearnerView.php`
- Modify: `routes/api.php`
- Test: `tests/Feature/Training/TrainingAnswerKeyTest.php`

**Interfaces:**
- Consumes: guides and tests, `training.view`.
- Produces: `GET /training/learn` returning `{assigned: [...], library: [...]}`, and `GET /training/learn/tests/{test}` returning the test **with every `correct` key removed at every depth**. `LearnerView::stripAnswerKey(array $questions): array`.

This is a trust boundary. It gets the most direct test in the module.

- [ ] **Step 1: Write the failing test**

```php
public function test_a_learner_never_receives_the_answer_key(): void
{
    $tenant = $this->provisionTenant('Alpha');
    $learner = $this->userInCompany($tenant['company'], ['training.view']);

    $test = $this->inTenant($tenant['org'], fn () => TrainingTest::create([
        'company_id' => $tenant['company']->id,
        'title' => 'Ladder Safety', 'status' => 'published',
        'pass_mark' => 80, 'requires_guide' => false,
        'questions' => [[
            'prompt' => 'How far out does the base go?',
            'choices' => [
                ['text' => 'Two feet', 'correct' => false],
                ['text' => 'Four feet', 'correct' => true],
            ],
        ]],
    ]));

    $body = $this->actingAs($learner)
        ->getJson("/api/training/learn/tests/{$test->id}")
        ->assertOk()
        ->getContent();

    // Not a field-by-field check: the string must not contain it anywhere,
    // at any depth, under any future reshaping of the response.
    $this->assertStringNotContainsString('correct', $body);
    $this->assertStringContainsString('Four feet', $body);
}
```

- [ ] **Step 2: Run it and watch it fail**

```bash
DB_CONNECTION=mysql DB_DATABASE=ezbizi_testing_training vendor/bin/phpunit --filter=TrainingAnswerKeyTest
```

Expected: FAIL — 404 first; once routed, FAIL on the `correct` assertion.

- [ ] **Step 3: Strip it**

```php
<?php

namespace App\Support\Training;

/**
 * What a learner is allowed to see of a test.
 *
 * The answer key lives in the same JSON column as the question, because a
 * choice and its correctness are one fact and splitting them would let the two
 * drift. The consequence is that EVERY learner-facing read must pass through
 * here — grading happens server-side against the stored row, never against
 * anything the client was told.
 */
final class LearnerView
{
    /** @return array<int, array<string, mixed>> */
    public static function stripAnswerKey(array $questions): array
    {
        return array_map(static fn (array $question): array => [
            'prompt' => $question['prompt'] ?? '',
            'choices' => array_map(
                static fn (array $choice): array => ['text' => $choice['text'] ?? ''],
                $question['choices'] ?? [],
            ),
        ], $questions);
    }
}
```

Rebuilding the shape rather than unsetting `correct` is the point: a key added to a choice later is excluded by default instead of leaking until somebody notices.

- [ ] **Step 4: Run it and watch it pass**

```bash
DB_CONNECTION=mysql DB_DATABASE=ezbizi_testing_training vendor/bin/phpunit --filter=TrainingAnswerKeyTest
```

Expected: PASS. Then change `stripAnswerKey` to `return $questions;`, re-run, confirm RED, restore. This is the one test in the module most worth seeing fail.

- [ ] **Step 5: Commit**

```bash
git add app/Http/Controllers/Api/Training/LearnController.php app/Support/Training/LearnerView.php routes/api.php tests/Feature/Training/TrainingAnswerKeyTest.php
git commit -m "Show a learner the questions and not the answers"
```

---

### Task 7: Progress through a guide

**Files:**
- Create: `app/Http/Controllers/Api/Training/ProgressController.php`
- Modify: `routes/api.php`
- Test: `tests/Feature/Training/TrainingProgressTest.php`

**Interfaces:**
- Consumes: `TrainingProgress`, `TrainingGuide::screenCount()`.
- Produces: `POST /training/learn/guides/{guide}/seen` with `{screen: int}`. Sets `last_screen` to the furthest reached and stamps `completed_at` when the final screen is seen. Returns `{last_screen, completed_at, screen_count}`.

- [ ] **Step 1: Write the failing test**

```php
public function test_seeing_the_last_screen_finishes_the_guide_and_going_back_does_not_unfinish_it(): void
{
    [$learner, $guide] = $this->aLearnerAndAnEightScreenGuide();

    $this->actingAs($learner)->postJson("/api/training/learn/guides/{$guide->id}/seen", ['screen' => 7])
        ->assertOk()->assertJsonPath('completed_at', fn ($v) => $v !== null);

    // Going back to re-read screen 2 must not undo it.
    $this->actingAs($learner)->postJson("/api/training/learn/guides/{$guide->id}/seen", ['screen' => 1])
        ->assertOk()
        ->assertJsonPath('last_screen', 7)
        ->assertJsonPath('completed_at', fn ($v) => $v !== null);
}
```

- [ ] **Step 2: Run it and watch it fail**

```bash
DB_CONNECTION=mysql DB_DATABASE=ezbizi_testing_training vendor/bin/phpunit --filter=TrainingProgressTest
```

Expected: FAIL — 404.

- [ ] **Step 3: Implement, keeping the high-water mark**

```php
$progress = TrainingProgress::firstOrNew(['user_id' => $user->id, 'guide_id' => $guide->id]);

// A high-water mark, not a cursor. Re-reading screen 2 of a guide you have
// finished is ordinary, and must not take the completion away.
$progress->last_screen = max($progress->last_screen ?? 0, $screen);

if ($progress->last_screen >= $guide->screenCount() - 1 && $progress->completed_at === null) {
    $progress->completed_at = now();
    // Outbox, never dispatch — see Task 9.
    $this->emitter->queue('training.completed', $progress);
}

$progress->save();
```

- [ ] **Step 4: Run it and watch it pass**

```bash
DB_CONNECTION=mysql DB_DATABASE=ezbizi_testing_training vendor/bin/phpunit --filter=TrainingProgressTest
```

Expected: PASS. Then change `max(...)` to plain assignment, re-run, confirm the second half goes RED, restore.

- [ ] **Step 5: Commit**

```bash
git add app/Http/Controllers/Api/Training/ProgressController.php routes/api.php tests/Feature/Training/TrainingProgressTest.php
git commit -m "Remember how far somebody got, and let them re-read without losing it"
```

---

### Task 8: Grading — the gate, the pass mark, the retake cap, the expiry date

**Files:**
- Create: `app/Support/Training/TestGrader.php`
- Modify: `app/Http/Controllers/Api/Training/LearnController.php` — add `submit`
- Test: `tests/Feature/Training/TrainingGradingTest.php`

**Interfaces:**
- Consumes: `TrainingTest`, `TrainingProgress`, `TrainingAttempt`, `LearnerView`.
- Produces: `POST /training/learn/tests/{test}/attempts` with `{answers: [int]}` — index of the chosen choice per question. `TestGrader::grade(TrainingTest $test, array $answers): array` returning `['score' => int, 'passed' => bool]`. Sets `expires_on = today + valid_for_days` when the test expires and the attempt passed.

- [ ] **Step 1: Write the failing tests**

```php
public function test_a_gated_test_refuses_until_the_guide_is_finished(): void
{
    [$learner, $guide, $test] = $this->aGatedTest();   // requires_guide = true

    $this->actingAs($learner)
        ->postJson("/api/training/learn/tests/{$test->id}/attempts", ['answers' => [1]])
        ->assertStatus(403)
        ->assertJsonPath('message', fn (string $m) => str_contains($m, 'guide'));
}

public function test_the_pass_mark_is_the_tests_own_and_not_a_platform_constant(): void
{
    // Two questions, one right => 50%. Pass mark 40 passes; pass mark 60 does not.
    [$learner, $lenient] = $this->aTwoQuestionTest(passMark: 40);
    [$other, $strict]    = $this->aTwoQuestionTest(passMark: 60);

    $this->actingAs($learner)
        ->postJson("/api/training/learn/tests/{$lenient->id}/attempts", ['answers' => [1, 0]])
        ->assertOk()->assertJsonPath('passed', true)->assertJsonPath('score', 50);

    $this->actingAs($other)
        ->postJson("/api/training/learn/tests/{$strict->id}/attempts", ['answers' => [1, 0]])
        ->assertOk()->assertJsonPath('passed', false)->assertJsonPath('score', 50);
}

public function test_the_retake_cap_is_enforced_and_null_means_unlimited(): void
{
    [$learner, $test] = $this->aTwoQuestionTest(passMark: 100, maxAttempts: 2);

    $this->actingAs($learner)->postJson("/api/training/learn/tests/{$test->id}/attempts", ['answers' => [0, 0]])->assertOk();
    $this->actingAs($learner)->postJson("/api/training/learn/tests/{$test->id}/attempts", ['answers' => [0, 0]])->assertOk();
    $this->actingAs($learner)->postJson("/api/training/learn/tests/{$test->id}/attempts", ['answers' => [0, 0]])
        ->assertStatus(403);
}

public function test_a_pass_on_an_expiring_test_carries_the_date_it_runs_out(): void
{
    [$learner, $test] = $this->aTwoQuestionTest(passMark: 40, validForDays: 365);

    $this->actingAs($learner)
        ->postJson("/api/training/learn/tests/{$test->id}/attempts", ['answers' => [1, 0]])
        ->assertOk()
        ->assertJsonPath('expires_on', now()->addDays(365)->toDateString());
}
```

- [ ] **Step 2: Run them and watch them fail**

```bash
DB_CONNECTION=mysql DB_DATABASE=ezbizi_testing_training vendor/bin/phpunit --filter=TrainingGradingTest
```

Expected: FAIL — 404.

- [ ] **Step 3: Write the grader**

```php
<?php

namespace App\Support\Training;

use App\Models\TrainingTest;

/**
 * Marks an attempt against the STORED test, never against anything the client
 * sent back. The learner's copy has had the answer key removed (LearnerView),
 * so there is nothing on the wire to mark against even if somebody tried.
 */
final class TestGrader
{
    /**
     * @param  array<int, int|null>  $answers  chosen choice index per question
     * @return array{score:int, passed:bool}
     */
    public static function grade(TrainingTest $test, array $answers): array
    {
        $questions = $test->questions ?? [];

        if ($questions === []) {
            // No questions is not a 100% pass. An empty test is unpassable
            // rather than trivially passed, which is the safer of the two.
            return ['score' => 0, 'passed' => false];
        }

        $right = 0;

        foreach ($questions as $i => $question) {
            $chosen = $answers[$i] ?? null;

            if ($chosen !== null && ($question['choices'][$chosen]['correct'] ?? false) === true) {
                $right++;
            }
        }

        $score = (int) round($right / count($questions) * 100);

        return ['score' => $score, 'passed' => $score >= $test->pass_mark];
    }
}
```

`submit` on the controller then, in order: refuse if `requires_guide` and the guide is unfinished (with a sentence); refuse if `max_attempts` is not null and that many attempts exist (with a sentence naming the number); grade; write the attempt with `expires_on` set when `valid_for_days` is not null **and** it passed; queue `training.passed` or `training.failed` on the outbox.

- [ ] **Step 4: Run them and watch them pass**

```bash
DB_CONNECTION=mysql DB_DATABASE=ezbizi_testing_training vendor/bin/phpunit --filter=TrainingGradingTest
```

Expected: all four PASS. Then change `$score >= $test->pass_mark` to `$score >= 50`, re-run, confirm the second test goes RED, restore.

- [ ] **Step 5: Commit**

```bash
git add app/Support/Training/TestGrader.php app/Http/Controllers/Api/Training/LearnController.php tests/Feature/Training/TrainingGradingTest.php
git commit -m "Mark a test against the stored answers, on the terms its author set"
```

---

### Task 9: Assignment, bounded by reach

**Files:**
- Create: `app/Http/Controllers/Api/Training/AssignmentsController.php`, `app/Support/Training/AssignmentReach.php`
- Modify: `routes/api.php`; the `training.assign` arm of the automation `ActionRunner`
- Test: `tests/Feature/Training/TrainingAssignmentReachTest.php`

**Interfaces:**
- Consumes: `TrainingAssignment`, `training.assign`.
- Produces: `POST /training/assignments` with `{guide_id?, test_id?, assignee_type, assignee_id, due_on?}`. `AssignmentReach::mayAssignTo(User $assigner, User $assignee): bool`.

**The rule, and where it comes from.** `docs/EZBIZI-HR-PLAN.md`: *"he should only be able to see same level or downwards when his options to assign an employee are presented"* — and it cites §4, not anything HR-specific. A flat permission would let a Riverside supervisor pile mandatory training onto Northgate's crew, which is the same shape as the leak StartUp records shipping in Inventory, Fleet and Phone.

- [ ] **Step 1: Write the failing test**

```php
public function test_a_franchise_supervisor_cannot_assign_sideways_into_another_franchise(): void
{
    $tenant = $this->provisionTenant('Alpha');
    $riverside = $tenant['franchise'];
    $northgate = $this->addFranchise($tenant['company'], 'Northgate');

    $supervisor = $this->userInFranchise($riverside, ['training.assign']);
    $theirs     = $this->userInFranchise($riverside, ['training.view']);
    $notTheirs  = $this->userInFranchise($northgate, ['training.view']);
    $guide      = $this->aPublishedOrgGuide($tenant);

    $this->actingAs($supervisor)->postJson('/api/training/assignments', [
        'guide_id' => $guide->id, 'assignee_type' => 'user', 'assignee_id' => $theirs->id,
    ])->assertCreated();

    $refused = $this->actingAs($supervisor)->postJson('/api/training/assignments', [
        'guide_id' => $guide->id, 'assignee_type' => 'user', 'assignee_id' => $notTheirs->id,
    ]);

    $refused->assertStatus(403);
    // §4: a refusal carries a sentence, and the client shows that sentence.
    $this->assertNotSame('', trim($refused->json('message')));
}
```

- [ ] **Step 2: Run it and watch it fail**

```bash
DB_CONNECTION=mysql DB_DATABASE=ezbizi_testing_training vendor/bin/phpunit --filter=TrainingAssignmentReachTest
```

Expected: FAIL — 404, then 201 on the second call once routed.

- [ ] **Step 3: Bound it**

`AssignmentReach::mayAssignTo` asks whether every place the assignee holds is one the assigner `canActOnCompany`/`canActOnFranchise` — same level or downwards, never sideways. Read `User::reachableFranchises()` and `canActOnFranchise()` and use them rather than re-deriving reach. Refuse through `Refusal`, in plain words: *"You can assign training to people in Riverside. Sam works out of Northgate."*

A role assignment is checked the same way against the role's scope, and is **not** expanded into per-user rows — the Learn query unions "assigned to me" with "assigned to a role I hold", so adding somebody to a role gives them the training with no sync job to go stale.

Queue `training.assigned` on the outbox. Wire the `training.assign` automation action to the same service so a rule and a person take the identical path.

- [ ] **Step 4: Run it and watch it pass**

```bash
DB_CONNECTION=mysql DB_DATABASE=ezbizi_testing_training vendor/bin/phpunit --filter=TrainingAssignmentReachTest
```

Expected: PASS. Then make `mayAssignTo` return `true` unconditionally, re-run, confirm RED, restore.

- [ ] **Step 5: Commit**

```bash
git add app/Http/Controllers/Api/Training/AssignmentsController.php app/Support/Training/AssignmentReach.php routes/api.php tests/Feature/Training/TrainingAssignmentReachTest.php
git commit -m "Assign training downwards only, the way onboarding already assigns people"
```

---

### Task 10: The expiry sweep, through the outbox

**Files:**
- Create: `app/Console/Commands/ExpireTrainingPasses.php`
- Modify: `routes/console.php` — nightly schedule
- Test: `tests/Feature/Training/TrainingExpiryTest.php`

**Interfaces:**
- Consumes: `TrainingAttempt::$expires_on`.
- Produces: `ezbizi:expire-training-passes`. For each passed attempt whose `expires_on` is today or earlier and which has not already been announced, **insert** a `training.expired` outbox row. Idempotent: running twice produces one row.

- [ ] **Step 1: Write the failing test**

```php
public function test_a_lapsed_pass_queues_an_event_rather_than_dispatching_one(): void
{
    [$learner, $test] = $this->aPassedAttemptExpiring(daysAgo: 1);

    Bus::fake();                       // nothing may be dispatched inline
    $this->artisan('ezbizi:expire-training-passes')->assertSuccessful();

    Bus::assertNothingDispatched();
    $this->assertDatabaseHas('automation_events', ['trigger' => 'training.expired']);
}

public function test_running_the_sweep_twice_announces_a_lapsed_pass_once(): void
{
    [$learner, $test] = $this->aPassedAttemptExpiring(daysAgo: 1);

    $this->artisan('ezbizi:expire-training-passes');
    $this->artisan('ezbizi:expire-training-passes');

    $this->assertSame(1, DB::table('automation_events')
        ->where('trigger', 'training.expired')->count());
}
```

Use the real outbox table and column names — read `app/Support/Automations/` and `app/Models/AutomationEvent.php` first; the names above are illustrative.

- [ ] **Step 2: Run them and watch them fail**

```bash
DB_CONNECTION=mysql DB_DATABASE=ezbizi_testing_training vendor/bin/phpunit --filter=TrainingExpiryTest
```

Expected: FAIL — the command does not exist.

- [ ] **Step 3: Write the command**

The idempotence needs a marker. Prefer querying the outbox for an existing `training.expired` row carrying this attempt over adding an `announced_at` column — a second column that must agree with the outbox is a second copy of the same fact. If that query is awkward, add the column and say why in a comment.

```php
/*
 * INSERTS, never dispatches (StartUp §10). One rule failing while re-assigning
 * one lapsed ladder-safety pass must not take the sweep down for everybody
 * else's — which is exactly what an inline dispatch inside this loop would do.
 */
```

- [ ] **Step 4: Run them and watch them pass**

```bash
DB_CONNECTION=mysql DB_DATABASE=ezbizi_testing_training vendor/bin/phpunit --filter=TrainingExpiryTest
```

Expected: both PASS. Then remove the already-announced guard, re-run, confirm the second test goes RED, restore.

- [ ] **Step 5: Commit**

```bash
git add app/Console/Commands/ExpireTrainingPasses.php routes/console.php tests/Feature/Training/TrainingExpiryTest.php
git commit -m "Notice a training pass running out, once, on the outbox"
```

---

### Task 11: Dashboard and HR reads

**Files:**
- Create: `app/Http/Controllers/Api/Training/DashboardController.php`
- Modify: `routes/api.php`
- Test: `tests/Feature/Training/TrainingDashboardTest.php`

**Interfaces:**
- Consumes: everything above.
- Produces: `GET /training/dashboard` → per guide/test `{id, title, assigned, passed, in_progress, overdue, retaking}`, gated on `training.monitor`. `GET /training/dashboard/{id}/people` → the pane rows. `GET /training/records?user=` → that person's training, gated on `training.monitor`, for HR to render.

**Training writes nothing into HR's tables** (spec decision 7). And nothing here says *certification* — that word belongs to HR's licence-expiry panel and a second one would disagree with it correctly (decision 8).

- [ ] **Step 1: Write the failing test**

```php
public function test_the_dashboard_counts_overdue_separately_from_due_for_retaking(): void
{
    $tenant = $this->provisionTenant('Alpha');
    $monitor = $this->userInCompany($tenant['company'], ['training.monitor']);
    $guide = $this->aGuideWithAnOverdueAssignmentAndALapsedPass($tenant);

    $row = collect($this->actingAs($monitor)->getJson('/api/training/dashboard')->json('data'))
        ->firstWhere('id', $guide->id);

    $this->assertSame(1, $row['overdue'], 'Never started and past its date.');
    $this->assertSame(1, $row['retaking'], 'Passed once, and the pass ran out.');
}

public function test_the_dashboard_never_says_certification(): void
{
    $tenant = $this->provisionTenant('Alpha');
    $monitor = $this->userInCompany($tenant['company'], ['training.monitor']);

    $body = $this->actingAs($monitor)->getJson('/api/training/dashboard')->getContent();

    // HR's panel owns that word for licence expiries typed at onboarding.
    // Two panels of the same name from different tables would disagree, correctly.
    $this->assertStringNotContainsStringIgnoringCase('certification', $body);
}
```

- [ ] **Step 2: Run them and watch them fail**

```bash
DB_CONNECTION=mysql DB_DATABASE=ezbizi_testing_training vendor/bin/phpunit --filter=TrainingDashboardTest
```

Expected: FAIL — 404.

- [ ] **Step 3: Implement, scoped the same way as everything else**

Constrain through `InventoryVisibility::constrain` so the Dashboard shows exactly what the viewer may see. Two people at different franchises looking at the same guide see different counts, and that is correct.

- [ ] **Step 4: Run them and watch them pass**

```bash
DB_CONNECTION=mysql DB_DATABASE=ezbizi_testing_training vendor/bin/phpunit --filter=TrainingDashboardTest
```

Expected: both PASS. Then fold `retaking` into `overdue`, re-run, confirm the first goes RED, restore.

- [ ] **Step 5: Commit**

```bash
git add app/Http/Controllers/Api/Training/DashboardController.php routes/api.php tests/Feature/Training/TrainingDashboardTest.php
git commit -m "Count who owes training, and keep retakes a separate number from overdue"
```

---

### Task 12: The tab shell and Learn — layout 1

**Files:**
- Create: `client/lib/screens/training_screen.dart`, `client/lib/screens/training/learn_shelves.dart`
- Modify: `client/lib/core/module_screens.dart` — one entry
- Test: `client/test/training_screen_test.dart`

**Interfaces:**
- Consumes: `GET /training/learn`.
- Produces: `TrainingScreen({required ApiClient api, required Session session})`. Three tabs via `TabBarLike(labels: List<(String, IconData)>, selected: int, onSelected: ValueChanged<int>)`, bodies kept in an `Offstage` stack, exactly as `InventoryScreen` does.

Layout **1**: *Assigned to you* shelf first, *Library* below. The assigned shelf is not filterable — that was the deciding difference against a single filtered list.

**Announce before editing `module_screens.dart`** — contested file.

- [ ] **Step 1: Write the failing widget test**

```dart
testWidgets('the assigned shelf is drawn above the library and cannot be filtered away',
    (tester) async {
  await tester.pumpWidget(_wrap(TrainingScreen(api: fakeApi, session: session)));
  await tester.pumpAndSettle();

  final assigned = tester.getTopLeft(find.text('Assigned to you'));
  final library = tester.getTopLeft(find.text('Library'));

  expect(assigned.dy, lessThan(library.dy));
  expect(find.text('Ladder Safety'), findsOneWidget);
});

testWidgets('a company view does not offer sibling companies', (tester) async {
  // The §3 leak that shipped in Inventory, Fleet and Phone. /me carries a
  // sibling; standing in one company it must not be offered.
  await tester.pumpWidget(_wrap(TrainingScreen(api: fakeApi, session: sessionWithSibling)));
  await tester.pumpAndSettle();

  expect(find.text('Everdoors'), findsNothing);
});
```

Copy the harness from `client/test/inventory_screen_test.dart` — its sibling-company test is the shape this must match.

- [ ] **Step 2: Run it and watch it fail**

```bash
cd client && flutter test test/training_screen_test.dart
```

Expected: FAIL — `TrainingScreen` is not defined.

- [ ] **Step 3: Build the shell and the shelves**

Mirror `InventoryScreen`: a `TabBarLike`, a `Divider(height: 1)`, an `Expanded(Stack(...))` of `Offstage` bodies with a `_shown` set so a tab is built only once visited. Wrap each body in `AsyncBody(loading:, error:, onRetry:, child:)`.

Colours come from `Theme.of(context).colorScheme` and `client/lib/core/theme.dart`. **No literal colour.** Filing uses `session.me.filingCompanyId` and `companiesInView`, never `me.companies.first`.

- [ ] **Step 4: Run the client gate**

```bash
cd client && flutter analyze && flutter test test/training_screen_test.dart
```

Expected: analyze clean, tests PASS. Then swap the shelf order, re-run, confirm RED, restore.

- [ ] **Step 5: Commit**

```bash
git add client/lib/screens/training_screen.dart client/lib/screens/training/learn_shelves.dart client/lib/core/module_screens.dart client/test/training_screen_test.dart
git commit -m "Open Training on what you owe, then what you may browse"
```

---

### Task 13: The guide player and the test player — layout 4

**Files:**
- Create: `client/lib/screens/training/guide_player.dart`, `client/lib/screens/training/test_player.dart`
- Test: `client/test/training_player_test.dart`

**Interfaces:**
- Consumes: `POST /training/learn/guides/{id}/seen`, `GET /training/learn/tests/{id}`, `POST /training/learn/tests/{id}/attempts`.
- Produces: `GuidePlayer({required ApiClient api, required Session session, required String guideId, required VoidCallback onLeave})`.

Layout **4**: a screen rail on the left with ticks for what has been seen, the reader on the right, the test as the last rail item reading *"Unlocks when all 8 are seen"* while gated. **Below 600px the rail collapses and the reader is the whole body** — the same `splitAbove` constant `InventoryScreen` uses.

Video: an embedded player on web; where an iframe is unavailable, an **Open video** link. Nothing may assume a browser.

- [ ] **Step 1: Write the failing test**

```dart
testWidgets('below 600px the rail is gone and the reader is the whole body', (tester) async {
  tester.view.physicalSize = const Size(375, 800);
  tester.view.devicePixelRatio = 1.0;
  addTearDown(tester.view.reset);

  await tester.pumpWidget(_wrap(GuidePlayer(
    api: fakeApi, session: session, guideId: 'g1', onLeave: () {})));
  await tester.pumpAndSettle();

  expect(find.byKey(const Key('training.screen-rail')), findsNothing);
  expect(find.text('The four-to-one rule'), findsOneWidget);
});

testWidgets('a gated test says so instead of offering a control that would refuse',
    (tester) async {
  await tester.pumpWidget(_wrap(GuidePlayer(
    api: fakeApiWithGatedTest, session: session, guideId: 'g1', onLeave: () {})));
  await tester.pumpAndSettle();

  // §4: a control that is offered must be a control that works.
  expect(find.text('Unlocks when all 8 are seen'), findsOneWidget);
  final button = tester.widget<ElevatedButton>(find.byKey(const Key('training.start-test')));
  expect(button.onPressed, isNull);
});
```

- [ ] **Step 2: Run it and watch it fail**

```bash
cd client && flutter test test/training_player_test.dart
```

Expected: FAIL — `GuidePlayer` is not defined.

- [ ] **Step 3: Build both players**

- [ ] **Step 4: Run the client gate**

```bash
cd client && flutter analyze && flutter test
```

Expected: analyze clean, whole suite PASS. Then remove the width branch, re-run, confirm the first test goes RED, restore.

- [ ] **Step 5: Commit**

```bash
git add client/lib/screens/training/guide_player.dart client/lib/screens/training/test_player.dart client/test/training_player_test.dart
git commit -m "Read a guide with the rail beside it, and lose the rail on a phone"
```

---

### Task 14: Dashboard — layout 6

**Files:**
- Create: `client/lib/screens/training/dashboard_list.dart`, `client/lib/screens/training/dashboard_pane.dart`
- Test: `client/test/training_dashboard_test.dart`

**Interfaces:**
- Consumes: `GET /training/dashboard`, `GET /training/dashboard/{id}/people`.
- Produces: `TrainingDashboard({required ApiClient api, required Session session})`.

Layout **6**: one row per guide or test with a completion bar; selecting one opens a pane of people. Chosen over the matrix because a list is already one column at 375px and a matrix is not. **No modal** — selection is a pane, and below 600px the pane is the whole body with Back.

- [ ] **Step 1: Write the failing test**

```dart
testWidgets('overdue and due-for-retaking are shown as two different numbers',
    (tester) async {
  await tester.pumpWidget(_wrap(TrainingDashboard(api: fakeApi, session: session)));
  await tester.pumpAndSettle();

  expect(find.text('2 overdue'), findsOneWidget);
  expect(find.text('3 due for retaking'), findsOneWidget);
  expect(find.textContaining('certification'), findsNothing);
});
```

- [ ] **Step 2: Run it and watch it fail**

```bash
cd client && flutter test test/training_dashboard_test.dart
```

Expected: FAIL — `TrainingDashboard` is not defined.

- [ ] **Step 3: Build the list and the pane**

- [ ] **Step 4: Run the client gate**

```bash
cd client && flutter analyze && flutter test test/training_dashboard_test.dart
```

Expected: PASS. Then merge the two counts into one label, re-run, confirm RED, restore.

- [ ] **Step 5: Commit**

```bash
git add client/lib/screens/training/dashboard_list.dart client/lib/screens/training/dashboard_pane.dart client/test/training_dashboard_test.dart
git commit -m "Show a guide's progress as a row, and its people in the pane"
```

---

### Task 15: Settings — layout 8, the storyboard

**Files:**
- Create: `client/lib/screens/training/guide_builder.dart`, `client/lib/screens/training/test_builder.dart`
- Test: `client/test/training_builder_test.dart`

**Interfaces:**
- Consumes: the guides and tests APIs (Tasks 4 and 5).
- Produces: `GuideBuilder`, `TestBuilder`.

Layout **8**: screens as a horizontal strip of thumbnails showing the guide's shape, with the screen editor and the test editor below. The four per-test options are edited here.

**This is the one pattern that exists nowhere else in this app** — every other module uses the list-beside-editor split. It was chosen deliberately; keep it self-contained so it does not become a second convention by accident.

At 375px the strip scrolls horizontally inside its own container and the editors stack. The strip is the only horizontally-scrolling thing on the screen; the page itself must never scroll sideways.

- [ ] **Step 1: Write the failing test**

```dart
testWidgets('the four test options are all editable in the builder', (tester) async {
  await tester.pumpWidget(_wrap(TestBuilder(api: fakeApi, session: session, testId: 't1')));
  await tester.pumpAndSettle();

  expect(find.text('Must finish the guide before the test'), findsOneWidget);
  expect(find.text('Pass mark'), findsOneWidget);
  expect(find.text('Retakes allowed'), findsOneWidget);
  expect(find.text('A pass runs out after'), findsOneWidget);
});

testWidgets('adding a screen adds a card to the strip and never opens a dialog',
    (tester) async {
  await tester.pumpWidget(_wrap(GuideBuilder(api: fakeApi, session: session, guideId: 'g1')));
  await tester.pumpAndSettle();

  await tester.tap(find.text('Add screen'));
  await tester.pumpAndSettle();

  expect(find.byType(Dialog), findsNothing);        // house rule: no modals
  expect(find.byType(AlertDialog), findsNothing);
  expect(find.byKey(const Key('training.screen-editor')), findsOneWidget);
});
```

- [ ] **Step 2: Run them and watch them fail**

```bash
cd client && flutter test test/training_builder_test.dart
```

Expected: FAIL — `TestBuilder` is not defined.

- [ ] **Step 3: Build the storyboard and both editors**

- [ ] **Step 4: Run the whole client gate**

```bash
cd client && flutter analyze && flutter test
```

Expected: analyze clean, whole suite PASS.

- [ ] **Step 5: Commit**

```bash
git add client/lib/screens/training/guide_builder.dart client/lib/screens/training/test_builder.dart client/test/training_builder_test.dart
git commit -m "Build a guide as a storyboard, and set how its test is passed"
```

---

### Task 16: Close it out

**Files:**
- Modify: `docs/MODULE-OWNERS.md` — add the Training row
- Modify: `docs/EZBIZI-TODO.md` if anything was deferred

- [ ] **Step 1: Run both suites in full**

```bash
DB_CONNECTION=mysql DB_DATABASE=ezbizi_testing_training vendor/bin/phpunit --filter=Training
DB_CONNECTION=mysql DB_DATABASE=ezbizi_testing_training vendor/bin/phpunit --filter=RetentionTest
DB_CONNECTION=mysql DB_DATABASE=ezbizi_testing_training vendor/bin/phpunit --filter=ModuleRegistryTest
cd client && flutter analyze && flutter test
```

Read the output. Do not call a gate green you have not seen pass.

- [ ] **Step 2: Add the ownership row, citing SHAs not a session name**

`docs/MODULE-OWNERS.md`, in the same commit as the work. Use commit SHAs in the session column — a SHA is checkable by anyone at any time and a session name stops being true without saying so.

```
| **Training** | `Api/Training/` · `Models/Training*.php` · `Policies/Training*Policy.php` · `Support/Training/` · `Console/Commands/ExpireTrainingPasses.php` · `screens/training_screen.dart` · `screens/training/` · `test/training_*.dart` · `tests/Feature/Training/` | <SHAs> |
```

- [ ] **Step 3: Hand the branch to the deploy session**

Message `ezbizi-a1` with the branch name, the SHAs, one line on what changed, and explicitly flag: **new tables** (migration), **four new permission keys**, **five new triggers and one new action**, and that no existing response shape, permission answer or `Refusal` sentence was altered.

- [ ] **Step 4: Commit**

```bash
git add docs/MODULE-OWNERS.md docs/EZBIZI-TODO.md
git commit -m "Put Training on the ownership map"
```

---

## Notes for whoever executes this

**Three things in this plan are deliberately not settled**, and guessing them is worse than asking:

1. **The automation subject** (Task 3). `AutomationSubject` binds a module to one record class; Training has three. Resolve it against `ModuleRegistryTest` and write the reasoning in the block.
2. **Whether a passed test can be retaken before it expires.** `max_attempts` currently counts all attempts, so somebody wanting to improve an 81% is blocked by a cap meant for failures. Ask the owner.
3. **The outbox table and column names** in Task 10 are illustrative. Read `app/Support/Automations/` first.

**Tasks 1–11 are backend and 12–15 are client.** They are independent after Task 11 — the client tasks consume only HTTP shapes. Tasks 4 through 11 each touch `routes/api.php`; keep every one of them inside the single Training block.
