# Suggestions Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Any signed-in user can send EzBizi a written suggestion, correct or withdraw it while nobody has read it, and see whether it was approved, denied or is in review — with staff working the queue from a new Development sub-tab.

**Architecture:** One table with a status column. A tenant-scoped controller that only ever returns the caller's own rows, and a staff controller that reads across every tenant behind the `staff` middleware. A decision writes one `notifications` row directly, because the dispatcher needs a tenant in context and a staff request has none. Nothing is registered in `ModuleRegistry` — that absence is what makes the module unremovable.

**Tech Stack:** Laravel 13 · PHP 8.4 · MySQL 8 · Flutter web client.

**Spec:** `docs/superpowers/specs/2026-09-02-suggestions-design.md`

## Global Constraints

- **Work in your own worktree.** `./scripts/session-worktree.sh suggestions --with-vendor` gives you `H:/Development/wt/suggestions` on `session/suggestions`. Do not work in `H:/Development/EzBizi` — main is checked out there and other sessions are live in it.
- **Never symlink or junction `vendor/` between trees.** `git worktree remove` follows a junction and deletes through it.
- **Tests run on your own schema, never the shared one:** `DB_DATABASE=ezbizi_testing_suggestions vendor/bin/phpunit --filter=<Name>`. `RefreshDatabase` drops the schema it is pointed at.
- **Never edit a deployed migration file.** New table, new file.
- **Statuses are class constants**, not methods returning literals.
- **Do not bump `client/VERSION` or write `version_history.dart`.** The deploy desk owns releases; a pre-bumped VERSION forces them into `--no-bump` and a missed bump strands desktop.
- **Any change under `client/lib` must pass `flutter build web`.** `flutter analyze` and `flutter test` do not catch a `dart:io` import, and that import breaks web silently.
- Status strings, exact: `new`, `reviewing`, `approved`, `denied`.
- Body limit, exact: 2000 characters. Note limit, exact: 2000 characters.

---

### Task 1: The table and the model

**Files:**
- Create: `database/migrations/2026_09_02_160000_create_suggestions_table.php`
- Create: `app/Models/Suggestion.php`
- Test: covered by the existing `tests/Feature/RetentionTest.php` and `tests/Feature/Api/DataInventoryTest.php`, which fail for a table nobody has decided about

**Interfaces:**
- Consumes: nothing
- Produces: `App\Models\Suggestion` with constants `STATUS_NEW='new'`, `STATUS_REVIEWING='reviewing'`, `STATUS_APPROVED='approved'`, `STATUS_DENIED='denied'`; relations `author(): BelongsTo` (to `User`, foreign key `user_id`) and `organization(): BelongsTo`; fillable `['organization_id','user_id','body','status','decision_note','decided_by','decided_at']`

- [ ] **Step 1: Run the retention test to see it pass before you start**

Run: `DB_DATABASE=ezbizi_testing_suggestions vendor/bin/phpunit --filter=RetentionTest`
Expected: PASS. This is the baseline — it is about to fail for a reason you introduce, and you need to know it was green first.

- [ ] **Step 2: Write the migration**

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What somebody thinks the software should do differently.
 *
 * Addressed to EzBizi, not to the customer's business — which is why this is
 * the one tenant-owned table whose rows the organization's own administrators
 * cannot read. See the model, and the design doc's §3.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('suggestions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('organization_id');
            $table->uuid('user_id');

            $table->text('body');

            // 'new', 'reviewing', 'approved', 'denied'. Not an enum, matching
            // every other status column here: a migration to add a state is a
            // deploy, and a state is the kind of thing that gets added.
            $table->string('status', 20)->default('new');

            // What staff wrote back. The author reads it verbatim, so it is
            // stored as they typed it rather than composed at display time.
            $table->text('decision_note')->nullable();
            $table->uuid('decided_by')->nullable();
            $table->timestamp('decided_at')->nullable();

            $table->timestamps();

            $table->foreign('organization_id')->references('id')->on('organizations')->cascadeOnDelete();
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();

            // The author's own list, which is the query made every time the tab
            // is opened.
            $table->index(['organization_id', 'user_id', 'created_at'], 'suggestions_mine');

            // The control-plane queue: undecided first, across every tenant.
            $table->index(['status', 'created_at'], 'suggestions_queue');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('suggestions');
    }
};
```

- [ ] **Step 3: Write the model**

```php
<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Support\Privacy\Erasure;
use App\Support\Privacy\HoldsPersonalData;
use App\Support\Privacy\PersonalDataEntry;
use App\Support\Privacy\RetentionPolicy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * What somebody thinks the software should do differently.
 *
 * ── Why this module is not in the registry ───────────────────────
 *
 * There is no ModuleDefinition for suggestions and there must not be. Every
 * surface that can take a module away is keyed off a registry entry — the roles
 * editor composes from PermissionCatalogue, ModuleAvailability refuses a flag
 * for a key it cannot find, and the client rail draws me.modules — so a module
 * with no entry has nothing for any of them to point at. Registering it and
 * declining to ship a PlatformModuleFlag would NOT be equivalent: the moment
 * `suggestions.view` exists, an org administrator can switch it off for every
 * role but their own.
 *
 * ── Why nothing here writes an audit row ─────────────────────────
 *
 * Deliberate, and not the silence docs/EZBIZI-TODO.md records against five
 * other modules. The audit trail belongs to the ORGANIZATION, and an audit row
 * saying "Jose created a suggestion" publishes the existence, the timing and the
 * frequency of every private suggestion to exactly the people this record is
 * kept from. The content would stay hidden and the channel would be dead.
 */
class Suggestion extends Model implements HoldsPersonalData
{
    use BelongsToTenant;
    use HasFactory;
    use HasUuids;

    /** Nobody has read it. The only state in which the author may still edit or withdraw it. */
    public const STATUS_NEW = 'new';

    /** Somebody is reading it. Silent to the author's bell — nothing has been decided. */
    public const STATUS_REVIEWING = 'reviewing';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_DENIED = 'denied';

    /** What `decide` accepts. `new` is absent on purpose — see StaffSuggestionsController. */
    public const DECIDABLE = [self::STATUS_REVIEWING, self::STATUS_APPROVED, self::STATUS_DENIED];

    /** The two that reach the author's bell. */
    public const ANNOUNCED = [self::STATUS_APPROVED, self::STATUS_DENIED];

    protected $fillable = [
        'organization_id', 'user_id', 'body', 'status',
        'decision_note', 'decided_by', 'decided_at',
    ];

    protected function casts(): array
    {
        return ['decided_at' => 'datetime'];
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    // ── §9 data inventory ────────────────────────────────────────

    public static function personalDataPurpose(): string
    {
        return 'Suggestions you have sent EzBizi about the software, and what we answered. '
            .'Only you and EzBizi staff can read them — nobody at your own organization can.';
    }

    /**
     * Kept, deliberately.
     *
     * An approved suggestion is the record of why a feature exists, and a
     * window that deletes it throws away the only account of that decision. The
     * usual reason to expire a table does not apply either: this one takes a row
     * when a person chooses to type one, so it cannot run away.
     */
    public static function retention(): RetentionPolicy
    {
        return RetentionPolicy::forever(
            'An approved suggestion is the reason a feature exists and a denied one is the '
            .'reason it does not; both are the account of a decision, and the table only '
            .'grows when somebody deliberately writes something.'
        );
    }

    public static function personalDataHeld(): array
    {
        return [
            new PersonalDataEntry('user_id', PersonalDataEntry::ACTIVITY,
                'Who sent it, so we can tell them what we decided.',
                Erasure::drop('A suggestion has exactly one author. With that person erased '
                    .'there is nobody to answer, and the column is NOT NULL and is what the '
                    .'list is queried by — an anonymised suggestion is one nobody could ever '
                    .'read or withdraw.')),
            new PersonalDataEntry('body, decision_note', PersonalDataEntry::CONTENT,
                'What they asked for, and what we wrote back. Free text, so it can name a '
                .'colleague or a customer rather than only the person who sent it.',
                Erasure::drop('Free text with no schema: nothing can tell which words in it '
                    .'are a name, so it cannot be selectively cleaned. It goes with the row '
                    .'above.')),
        ];
    }

    public static function personalDataAbout(Model $subject): ?Builder
    {
        return $subject instanceof User
            ? static::query()->withoutGlobalScopes()->where('user_id', $subject->getKey())
            : null;
    }
}
```

- [ ] **Step 4: Run the privacy tests**

Run: `DB_DATABASE=ezbizi_testing_suggestions vendor/bin/phpunit --filter="RetentionTest|DataInventoryTest|PersonalDataPurgeTest"`
Expected: PASS, with no entry added to any exception list. If `PersonalDataPurgeTest::test_every_erasable_entry_names_columns_that_exist` fails, the `field` string names a column that is not in the migration — the field string is read as a comma-separated list of real columns.

- [ ] **Step 5: Commit**

```bash
git add database/migrations/2026_09_02_160000_create_suggestions_table.php app/Models/Suggestion.php
git commit -m "Suggestions: the table, and what it says about itself"
```

---

### Task 2: The author reads and writes their own

**Files:**
- Create: `app/Http/Controllers/Api/SuggestionsController.php`
- Create: `tests/Feature/SuggestionsTest.php`
- Modify: `routes/api.php` — inside the `Route::prefix('v1')->middleware(['auth:sanctum', 'tenant', 'subscription', 'two-factor', 'idle'])` group that opens at line 471

**Interfaces:**
- Consumes: `App\Models\Suggestion` from Task 1
- Produces: `GET /api/v1/suggestions` returning `{"data": [...]}`; `POST /api/v1/suggestions` returning `{"data": {...}}` 201. Each row is `{id, body, status, decision_note, decided_at, created_at}` — no author field, because the only person who can read it is the author.

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature;

use App\Models\Suggestion;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/**
 * A private channel from one person to EzBizi.
 *
 * The tenant scope is NOT the boundary here and the tests say so: a colleague
 * in the same organization is inside the tenant scope and must still see
 * nothing. That is the difference between this module and every other one.
 */
class SuggestionsTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    public function test_a_suggestion_comes_back_to_its_author(): void
    {
        $t = $this->provisionTenant();

        $this->signedInAs($t['admin'])
            ->postJson('/api/v1/suggestions', ['body' => 'Let the board remember its filter.'])
            ->assertCreated()
            ->assertJsonPath('data.status', Suggestion::STATUS_NEW);

        $this->signedInAs($t['admin'])
            ->getJson('/api/v1/suggestions')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.body', 'Let the board remember its filter.');
    }

    public function test_a_colleague_in_the_same_organization_sees_nothing(): void
    {
        $t = $this->provisionTenant();
        $colleague = $this->userWithRole($t, 'Manager', []);

        $this->signedInAs($t['admin'])
            ->postJson('/api/v1/suggestions', ['body' => 'Mine alone.'])
            ->assertCreated();

        $this->signedInAs($colleague)
            ->getJson('/api/v1/suggestions')
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    public function test_another_organization_sees_nothing(): void
    {
        $mine = $this->provisionTenant('Alpha');
        $theirs = $this->provisionTenant('Beta', 'beta@example.test');

        $this->signedInAs($mine['admin'])
            ->postJson('/api/v1/suggestions', ['body' => 'Alpha only.'])
            ->assertCreated();

        $this->signedInAs($theirs['admin'])
            ->getJson('/api/v1/suggestions')
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    public function test_an_empty_suggestion_is_refused(): void
    {
        $t = $this->provisionTenant();

        $this->signedInAs($t['admin'])
            ->postJson('/api/v1/suggestions', ['body' => '   '])
            ->assertStatus(422);
    }

    public function test_a_suggestion_over_the_limit_is_refused(): void
    {
        $t = $this->provisionTenant();

        $this->signedInAs($t['admin'])
            ->postJson('/api/v1/suggestions', ['body' => str_repeat('a', 2001)])
            ->assertStatus(422);
    }

    /**
     * No permission key exists for this, and that is the point.
     *
     * A user holding NO permissions at all still reaches it. If this test ever
     * fails, somebody has registered the module — see Suggestion's docblock.
     */
    public function test_a_user_with_no_permissions_at_all_can_still_send_one(): void
    {
        $t = $this->provisionTenant();
        $nobody = $this->userWithRole($t, 'No permissions', []);

        $this->signedInAs($nobody)
            ->postJson('/api/v1/suggestions', ['body' => 'I hold nothing and may still speak.'])
            ->assertCreated();
    }
}
```

- [ ] **Step 2: Run it to verify it fails**

Run: `DB_DATABASE=ezbizi_testing_suggestions vendor/bin/phpunit --filter=SuggestionsTest`
Expected: FAIL — 404s, because the routes do not exist yet.

- [ ] **Step 3: Write the controller**

```php
<?php

namespace App\Http\Controllers\Api;

use App\Models\Suggestion;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The author's half of Suggestions.
 *
 * ── Why every query filters user_id as well as the tenant ────────
 *
 * BelongsToTenant already scopes reads to the organization, and for every other
 * module that is the whole boundary. Here it is only half of one: the point of
 * this table is that a colleague — inside the same tenant, possibly holding
 * every permission the organization can grant — must not be able to read it.
 * So the filter is explicit on every path, including the ones that look like
 * they cannot need it.
 *
 * ── Why there is no permission check ─────────────────────────────
 *
 * There is no permission to check. Suggestions is deliberately absent from
 * ModuleRegistry so that no organization can take it away, which means
 * PermissionCatalogue publishes no key for it and Role::hasPermission has
 * nothing to answer. Anybody signed in reaches this. See Suggestion's docblock.
 */
class SuggestionsController extends Controller
{
    /** GET /api/v1/suggestions — your own, newest first. */
    public function index(Request $request): JsonResponse
    {
        $rows = Suggestion::query()
            ->where('user_id', $request->user()->getKey())
            ->latest('created_at')
            ->get()
            ->map(fn (Suggestion $row) => $this->row($row))
            ->all();

        return response()->json(['data' => $rows]);
    }

    /** POST /api/v1/suggestions */
    public function store(Request $request): JsonResponse
    {
        $input = $request->validate([
            'body' => ['required', 'string', 'max:2000'],
        ]);

        $body = trim($input['body']);

        if ($body === '') {
            return response()->json([
                'message' => 'Write the suggestion first.',
                'errors' => ['body' => ['Write the suggestion first.']],
            ], 422);
        }

        $row = Suggestion::create([
            'user_id' => $request->user()->getKey(),
            'body' => $body,
            'status' => Suggestion::STATUS_NEW,
        ]);

        return response()->json(['data' => $this->row($row)], 201);
    }

    /** @return array<string, mixed> */
    private function row(Suggestion $row): array
    {
        return [
            'id' => $row->id,
            'body' => $row->body,
            'status' => $row->status,
            'decision_note' => $row->decision_note,
            'decided_at' => $row->decided_at?->toIso8601String(),
            'created_at' => $row->created_at?->toIso8601String(),
        ];
    }
}
```

- [ ] **Step 4: Add the routes**

In `routes/api.php`, inside the group opening at line 471, add:

```php
        /*
         * Suggestions — the one module with no permission behind it.
         *
         * Deliberately absent from ModuleRegistry so that no organization can
         * remove it (see App\Models\Suggestion), which means there is no key for
         * EnsureModuleAvailable or a permission middleware to check. Reachable
         * by anybody signed in, which is the requirement.
         */
        Route::get('suggestions', [SuggestionsController::class, 'index']);
        Route::post('suggestions', [SuggestionsController::class, 'store']);
```

Add `use App\Http\Controllers\Api\SuggestionsController;` to the imports at the top of the file, in alphabetical position.

- [ ] **Step 5: Run the test to verify it passes**

Run: `DB_DATABASE=ezbizi_testing_suggestions vendor/bin/phpunit --filter=SuggestionsTest`
Expected: PASS, 6 tests.

- [ ] **Step 6: Commit**

```bash
git add app/Http/Controllers/Api/SuggestionsController.php tests/Feature/SuggestionsTest.php routes/api.php
git commit -m "Suggestions: an author can send one and read their own"
```

---

### Task 3: Correcting and withdrawing, while nobody has read it

**Files:**
- Modify: `app/Http/Controllers/Api/SuggestionsController.php`
- Modify: `tests/Feature/SuggestionsTest.php`
- Modify: `routes/api.php`

**Interfaces:**
- Consumes: `SuggestionsController::row()` from Task 2
- Produces: `PATCH /api/v1/suggestions/{suggestion}` returning `{"data": {...}}`; `DELETE /api/v1/suggestions/{suggestion}` returning 204 no content. Both 404 for somebody else's row and 422 once status has left `new`.

- [ ] **Step 1: Write the failing tests**

Add to `tests/Feature/SuggestionsTest.php`:

```php
    public function test_an_author_can_correct_one_nobody_has_read(): void
    {
        $t = $this->provisionTenant();

        $id = $this->signedInAs($t['admin'])
            ->postJson('/api/v1/suggestions', ['body' => 'Frist draft.'])
            ->json('data.id');

        $this->signedInAs($t['admin'])
            ->patchJson("/api/v1/suggestions/{$id}", ['body' => 'First draft.'])
            ->assertOk()
            ->assertJsonPath('data.body', 'First draft.');
    }

    public function test_an_author_can_withdraw_one_nobody_has_read(): void
    {
        $t = $this->provisionTenant();

        $id = $this->signedInAs($t['admin'])
            ->postJson('/api/v1/suggestions', ['body' => 'Never mind.'])
            ->json('data.id');

        $this->signedInAs($t['admin'])
            ->deleteJson("/api/v1/suggestions/{$id}")
            ->assertNoContent();

        $this->signedInAs($t['admin'])
            ->getJson('/api/v1/suggestions')
            ->assertJsonCount(0, 'data');
    }

    /**
     * The rule that matters: once somebody is reading it, the text is frozen.
     *
     * A suggestion rewritten under a reviewer produces a decision about words
     * that no longer exist, and a delete after a denial erases the reason.
     */
    public function test_editing_stops_once_it_is_in_review(): void
    {
        $t = $this->provisionTenant();

        $id = $this->signedInAs($t['admin'])
            ->postJson('/api/v1/suggestions', ['body' => 'Under a reviewer.'])
            ->json('data.id');

        $this->inTenant($t['org'], function () use ($id) {
            $row = Suggestion::query()->findOrFail($id);
            $row->status = Suggestion::STATUS_REVIEWING;
            $row->save();
        });

        $this->signedInAs($t['admin'])
            ->patchJson("/api/v1/suggestions/{$id}", ['body' => 'Changed my mind.'])
            ->assertStatus(422);

        $this->signedInAs($t['admin'])
            ->deleteJson("/api/v1/suggestions/{$id}")
            ->assertStatus(422);
    }

    public function test_a_colleague_cannot_edit_or_delete_it(): void
    {
        $t = $this->provisionTenant();
        $colleague = $this->userWithRole($t, 'Manager', []);

        $id = $this->signedInAs($t['admin'])
            ->postJson('/api/v1/suggestions', ['body' => 'Mine alone.'])
            ->json('data.id');

        $this->signedInAs($colleague)
            ->patchJson("/api/v1/suggestions/{$id}", ['body' => 'Not yours.'])
            ->assertNotFound();

        $this->signedInAs($colleague)
            ->deleteJson("/api/v1/suggestions/{$id}")
            ->assertNotFound();

        $this->assertDatabaseHas('suggestions', ['id' => $id, 'body' => 'Mine alone.']);
    }
```

- [ ] **Step 2: Run to verify they fail**

Run: `DB_DATABASE=ezbizi_testing_suggestions vendor/bin/phpunit --filter=SuggestionsTest`
Expected: FAIL on the four new tests — 405 or 404, because `PATCH` and `DELETE` are not routed.

- [ ] **Step 3: Add the two actions to the controller**

Add to `SuggestionsController`, after `store`:

```php
    /** PATCH /api/v1/suggestions/{suggestion} */
    public function update(Request $request, string $id): JsonResponse
    {
        $input = $request->validate([
            'body' => ['required', 'string', 'max:2000'],
        ]);

        $row = $this->mine($request, $id);

        if ($row === null) {
            return response()->json(['message' => 'That suggestion no longer exists.'], 404);
        }

        if ($row->status !== Suggestion::STATUS_NEW) {
            return $this->frozen($row);
        }

        $body = trim($input['body']);

        if ($body === '') {
            return response()->json([
                'message' => 'Write the suggestion first.',
                'errors' => ['body' => ['Write the suggestion first.']],
            ], 422);
        }

        $row->body = $body;
        $row->save();

        return response()->json(['data' => $this->row($row)]);
    }

    /** DELETE /api/v1/suggestions/{suggestion} */
    public function destroy(Request $request, string $id): JsonResponse
    {
        $row = $this->mine($request, $id);

        if ($row === null) {
            return response()->json(['message' => 'That suggestion no longer exists.'], 404);
        }

        if ($row->status !== Suggestion::STATUS_NEW) {
            return $this->frozen($row);
        }

        $row->delete();

        return response()->json([], 204);
    }

    /**
     * Yours, or nothing.
     *
     * 404 rather than 403 for a colleague's row, deliberately: 403 would confirm
     * that a suggestion with that id exists, which is a fact this module keeps
     * from them.
     */
    private function mine(Request $request, string $id): ?Suggestion
    {
        return Suggestion::query()
            ->where('user_id', $request->user()->getKey())
            ->find($id);
    }

    /**
     * Why it can no longer be touched, in words that name the actual state.
     *
     * Two different sentences because they are two different situations to be
     * in: one is "somebody is reading it right now", the other is "this has been
     * answered and the answer is on screen above".
     */
    private function frozen(Suggestion $row): JsonResponse
    {
        $message = $row->status === Suggestion::STATUS_REVIEWING
            ? 'Somebody at EzBizi is reading this one, so it is fixed while they do. '
                .'Send another if there is more to say.'
            : 'This one has been answered, so it stays as it was written. '
                .'Send another if there is more to say.';

        return response()->json([
            'message' => $message,
            'errors' => ['body' => [$message]],
        ], 422);
    }
```

- [ ] **Step 4: Add the routes**

Beside the two from Task 2:

```php
        Route::patch('suggestions/{suggestion}', [SuggestionsController::class, 'update']);
        Route::delete('suggestions/{suggestion}', [SuggestionsController::class, 'destroy']);
```

- [ ] **Step 5: Run the test to verify it passes**

Run: `DB_DATABASE=ezbizi_testing_suggestions vendor/bin/phpunit --filter=SuggestionsTest`
Expected: PASS, 10 tests.

- [ ] **Step 6: Commit**

```bash
git add app/Http/Controllers/Api/SuggestionsController.php tests/Feature/SuggestionsTest.php routes/api.php
git commit -m "Suggestions: correct or withdraw one, while nobody has read it"
```

---

### Task 4: The Development queue

**Files:**
- Create: `app/Http/Controllers/Api/StaffSuggestionsController.php`
- Create: `tests/Feature/Api/StaffSuggestionsTest.php`
- Modify: `routes/api.php` — inside the `Route::prefix('v1/staff')->middleware(['auth:sanctum', 'staff'])` group that opens at line 1821

**Interfaces:**
- Consumes: `App\Models\Suggestion` from Task 1
- Produces: `GET /api/v1/staff/suggestions` returning `{"data": [...]}`, each row `{id, organization, organization_id, author, body, status, decision_note, decided_at, created_at}`

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature\Api;

use App\Models\PlatformStaff;
use App\Models\Suggestion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PragmaRX\Google2FA\Google2FA;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/**
 * The control-plane half of Suggestions.
 *
 * Reads across every tenant on purpose — that is the whole point of the queue —
 * and is the only place in the application allowed to do so for this table.
 */
class StaffSuggestionsTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    private Google2FA $totp;

    protected function setUp(): void
    {
        parent::setUp();
        $this->totp = app(Google2FA::class);
    }

    private function staffToken(): string
    {
        $staff = PlatformStaff::create([
            'name' => 'Staffer',
            'email' => 'lead@ezbizi.test',
            'password' => 'a-sufficiently-long-password',
            'tier' => PlatformStaff::TIER_LEAD,
            'is_active' => true,
        ]);

        $secret = $this->totp->generateSecretKey(32);
        $staff->totp_secret = $secret;
        $staff->totp_confirmed_at = now();
        $staff->save();

        return $this->postJson('/api/v1/staff/auth/login', [
            'email' => $staff->email,
            'password' => 'a-sufficiently-long-password',
            'code' => $this->totp->getCurrentOtp($secret),
        ])->assertOk()->json('token');
    }

    /**
     * Rows are built through the model inside inTenant(), NOT by signing in and
     * posting.
     *
     * `signedInAs` calls Sanctum::actingAs, which resolves the guard for the
     * rest of the test — a later `withToken` is then ignored, and the staff
     * call authenticates as the tenant administrator and 403s. The failure
     * reads as a broken `staff` middleware rather than as a test-harness
     * problem. StaffPhoneRequestsTest::pendingRequest avoids it the same way.
     *
     * The reverse order is safe: `signedInAs` calls forgetGuards() first, so a
     * tenant call AFTER a staff call works.
     */
    private function suggestionIn(array $t, string $body): Suggestion
    {
        return $this->inTenant($t['org'], fn () => Suggestion::create([
            'user_id' => $t['admin']->id,
            'body' => $body,
            'status' => Suggestion::STATUS_NEW,
        ]));
    }

    public function test_the_queue_spans_organizations(): void
    {
        $alpha = $this->provisionTenant('Alpha');
        $beta = $this->provisionTenant('Beta', 'beta@example.test');

        $this->suggestionIn($alpha, 'From Alpha.');
        $this->suggestionIn($beta, 'From Beta.');

        $body = $this->withToken($this->staffToken())
            ->getJson('/api/v1/staff/suggestions')
            ->assertOk()
            ->json('data');

        $this->assertCount(2, $body);
        $this->assertEqualsCanonicalizing(
            ['Alpha', 'Beta'],
            array_column($body, 'organization'),
        );
    }

    public function test_the_queue_names_the_author_and_the_organization(): void
    {
        $t = $this->provisionTenant('Alpha');

        $this->suggestionIn($t, 'Name me.');

        $this->withToken($this->staffToken())
            ->getJson('/api/v1/staff/suggestions')
            ->assertOk()
            ->assertJsonPath('data.0.organization', 'Alpha')
            ->assertJsonPath('data.0.author', 'Alpha Admin')
            ->assertJsonPath('data.0.status', Suggestion::STATUS_NEW);
    }

    public function test_a_tenant_token_cannot_reach_the_queue(): void
    {
        $t = $this->provisionTenant();

        $this->signedInAs($t['admin'])
            ->getJson('/api/v1/staff/suggestions')
            ->assertForbidden();
    }
}
```

- [ ] **Step 2: Run to verify it fails**

Run: `DB_DATABASE=ezbizi_testing_suggestions vendor/bin/phpunit --filter=StaffSuggestionsTest`
Expected: FAIL — 404 on the two queue tests, because the route does not exist.

- [ ] **Step 3: Write the controller**

```php
<?php

namespace App\Http\Controllers\Api;

use App\Models\Suggestion;
use Illuminate\Http\JsonResponse;

/**
 * The Development-side queue: what everybody has asked for, and answering it.
 *
 * ── Why this reads across tenants ────────────────────────────────
 *
 * Everything else in the API is scoped to one organization by TenantContext.
 * This is the control plane: the whole point is to see every customer's
 * suggestion in one list, and it sits behind the `staff` middleware rather than
 * `tenant`. Queries here are deliberately unscoped and must stay in this
 * controller — the same query on a tenant-facing route is a leak, and for this
 * table it is a leak to the author's own colleagues as well as to strangers.
 */
class StaffSuggestionsController extends Controller
{
    /** GET /api/v1/staff/suggestions */
    public function index(): JsonResponse
    {
        $rows = Suggestion::query()
            ->withoutGlobalScopes()
            ->with(['organization:id,name', 'author:id,name'])
            // Undecided first, and among those the untouched ones — that is the
            // order somebody working the queue actually wants, rather than the
            // order things arrived.
            ->orderByRaw('CASE status WHEN ? THEN 0 WHEN ? THEN 1 ELSE 2 END', [
                Suggestion::STATUS_NEW,
                Suggestion::STATUS_REVIEWING,
            ])
            ->latest('created_at')
            ->limit(200)
            ->get()
            ->map(fn (Suggestion $row) => $this->row($row))
            ->all();

        return response()->json(['data' => $rows]);
    }

    /** @return array<string, mixed> */
    private function row(Suggestion $row): array
    {
        return [
            'id' => $row->id,
            'organization' => $row->organization?->name ?? 'Unknown',
            'organization_id' => $row->organization_id,
            'author' => $row->author?->name ?? 'Somebody who has since left',
            'body' => $row->body,
            'status' => $row->status,
            'decision_note' => $row->decision_note,
            'decided_at' => $row->decided_at?->toIso8601String(),
            'created_at' => $row->created_at?->toIso8601String(),
        ];
    }
}
```

- [ ] **Step 4: Add the route**

In `routes/api.php`, inside the `v1/staff` group, beside `phone-requests`:

```php
        // The suggestion queue. Reads across every tenant on purpose — control
        // plane, behind the `staff` middleware, not `tenant`.
        Route::get('suggestions', [StaffSuggestionsController::class, 'index']);
```

Add `use App\Http\Controllers\Api\StaffSuggestionsController;` to the imports.

- [ ] **Step 5: Run the test to verify it passes**

Run: `DB_DATABASE=ezbizi_testing_suggestions vendor/bin/phpunit --filter=StaffSuggestionsTest`
Expected: PASS, 3 tests.

- [ ] **Step 6: Commit**

```bash
git add app/Http/Controllers/Api/StaffSuggestionsController.php tests/Feature/Api/StaffSuggestionsTest.php routes/api.php
git commit -m "Suggestions: the Development queue, across every tenant"
```

---

### Task 5: Deciding, and telling the author

**Files:**
- Modify: `app/Http/Controllers/Api/StaffSuggestionsController.php`
- Modify: `tests/Feature/Api/StaffSuggestionsTest.php`
- Modify: `routes/api.php`

**Interfaces:**
- Consumes: `StaffSuggestionsController::row()` from Task 4; `App\Models\Notification` (fillable includes `organization_id, user_id, source, title, body, subject_type, subject_id, created_at`; `Notification::UPDATED_AT` is null, so `updated_at` must not be written)
- Produces: `POST /api/v1/staff/suggestions/{suggestion}/decide` taking `{status, note}`, returning `{"data": {...}}`

- [ ] **Step 1: Write the failing tests**

Add to `tests/Feature/Api/StaffSuggestionsTest.php` (add `use App\Models\Notification;` to the imports):

```php
    /**
     * Built through the model, not over HTTP — see suggestionIn's docblock for
     * why signing in first would make every staff call in these tests 403.
     *
     * @return array{0: string, 1: array}
     */
    private function oneSuggestion(): array
    {
        $t = $this->provisionTenant('Alpha');

        return [$this->suggestionIn($t, 'Decide about me.')->id, $t];
    }

    public function test_approving_tells_the_author(): void
    {
        [$id, $t] = $this->oneSuggestion();

        $this->withToken($this->staffToken())
            ->postJson("/api/v1/staff/suggestions/{$id}/decide", [
                'status' => Suggestion::STATUS_APPROVED,
                'note' => 'Going in the next release.',
            ])
            ->assertOk()
            ->assertJsonPath('data.status', Suggestion::STATUS_APPROVED);

        $notification = Notification::query()
            ->withoutGlobalScopes()
            ->where('user_id', $t['admin']->id)
            ->get();

        $this->assertCount(1, $notification);
        $this->assertSame($t['org']->id, $notification->first()->organization_id);
        $this->assertSame(Suggestion::class, $notification->first()->subject_type);
        $this->assertSame($id, $notification->first()->subject_id);
    }

    /**
     * A refusal without a reason is the dead end §6 forbids, and the author
     * reads this sentence word for word.
     */
    public function test_denying_without_a_note_is_refused(): void
    {
        [$id] = $this->oneSuggestion();

        $this->withToken($this->staffToken())
            ->postJson("/api/v1/staff/suggestions/{$id}/decide", [
                'status' => Suggestion::STATUS_DENIED,
                'note' => '',
            ])
            ->assertStatus(422);

        $this->assertDatabaseHas('suggestions', [
            'id' => $id, 'status' => Suggestion::STATUS_NEW,
        ]);
    }

    public function test_approving_without_a_note_is_allowed(): void
    {
        [$id] = $this->oneSuggestion();

        $this->withToken($this->staffToken())
            ->postJson("/api/v1/staff/suggestions/{$id}/decide", [
                'status' => Suggestion::STATUS_APPROVED,
                'note' => null,
            ])
            ->assertOk();
    }

    /**
     * Starting to read is not news, and the bell is the wrong place to learn it.
     */
    public function test_moving_it_into_review_tells_nobody(): void
    {
        [$id, $t] = $this->oneSuggestion();

        $this->withToken($this->staffToken())
            ->postJson("/api/v1/staff/suggestions/{$id}/decide", [
                'status' => Suggestion::STATUS_REVIEWING,
                'note' => null,
            ])
            ->assertOk();

        $this->assertSame(0, Notification::query()
            ->withoutGlobalScopes()
            ->where('user_id', $t['admin']->id)
            ->count());
    }

    /**
     * Restoring `new` would hand the author back the right to rewrite text a
     * reviewer has already read.
     */
    public function test_it_cannot_be_put_back_to_new(): void
    {
        [$id] = $this->oneSuggestion();

        $this->withToken($this->staffToken())
            ->postJson("/api/v1/staff/suggestions/{$id}/decide", [
                'status' => Suggestion::STATUS_NEW,
                'note' => 'Never mind.',
            ])
            ->assertStatus(422);
    }

    public function test_a_reversal_replaces_the_answer_and_tells_them_again(): void
    {
        [$id, $t] = $this->oneSuggestion();
        $token = $this->staffToken();

        $this->withToken($token)->postJson("/api/v1/staff/suggestions/{$id}/decide", [
            'status' => Suggestion::STATUS_DENIED,
            'note' => 'Not this quarter.',
        ])->assertOk();

        $this->withToken($token)->postJson("/api/v1/staff/suggestions/{$id}/decide", [
            'status' => Suggestion::STATUS_APPROVED,
            'note' => 'Changed our minds.',
        ])->assertOk()->assertJsonPath('data.decision_note', 'Changed our minds.');

        $this->assertSame(2, Notification::query()
            ->withoutGlobalScopes()
            ->where('user_id', $t['admin']->id)
            ->count());
    }

    /** The author reads the note; a colleague still reads nothing. */
    public function test_the_author_sees_the_decision_and_the_note(): void
    {
        [$id, $t] = $this->oneSuggestion();

        $this->withToken($this->staffToken())
            ->postJson("/api/v1/staff/suggestions/{$id}/decide", [
                'status' => Suggestion::STATUS_DENIED,
                'note' => 'The board already does this — see the filter menu.',
            ])->assertOk();

        $this->signedInAs($t['admin'])
            ->getJson('/api/v1/suggestions')
            ->assertOk()
            ->assertJsonPath('data.0.status', Suggestion::STATUS_DENIED)
            ->assertJsonPath('data.0.decision_note', 'The board already does this — see the filter menu.');
    }
```

- [ ] **Step 2: Run to verify they fail**

Run: `DB_DATABASE=ezbizi_testing_suggestions vendor/bin/phpunit --filter=StaffSuggestionsTest`
Expected: FAIL on the seven new tests — 404, because `decide` is not routed.

- [ ] **Step 3: Write `decide`**

Add to `StaffSuggestionsController` (imports: `App\Models\Notification`, `Illuminate\Http\Request`, `Illuminate\Support\Facades\DB`, `Illuminate\Support\Str`, `Illuminate\Validation\ValidationException`):

```php
    /**
     * POST /api/v1/staff/suggestions/{suggestion}/decide
     *
     * `new` is not accepted. It is the state of a suggestion nobody has touched,
     * and restoring it would hand the author back the right to rewrite text a
     * reviewer has already read. A second decide on an already-decided one IS
     * accepted — a reversal is a thing that happens — and replaces both the
     * status and the note.
     */
    public function decide(Request $request, string $id): JsonResponse
    {
        $input = $request->validate([
            'status' => ['required', 'string', 'in:'.implode(',', Suggestion::DECIDABLE)],
            'note' => ['nullable', 'string', 'max:2000'],
        ]);

        /** @var Suggestion|null $row */
        $row = Suggestion::query()->withoutGlobalScopes()->find($id);

        if ($row === null) {
            return response()->json(['message' => 'That suggestion no longer exists.'], 404);
        }

        $note = filled($input['note'] ?? null) ? trim((string) $input['note']) : null;

        if ($input['status'] === Suggestion::STATUS_DENIED && $note === null) {
            throw ValidationException::withMessages([
                'note' => 'Say why, in a sentence. The person who sent it reads it.',
            ]);
        }

        DB::transaction(function () use ($row, $input, $note, $request) {
            $row->status = $input['status'];
            $row->decision_note = $note;
            $row->decided_by = $request->user()?->getKey();
            $row->decided_at = now();
            $row->save();

            if (in_array($input['status'], Suggestion::ANNOUNCED, true)) {
                $this->tell($row);
            }
        });

        return response()->json(['data' => $this->row($row->fresh([
            'organization:id,name', 'author:id,name',
        ]))]);
    }

    /**
     * One notification row, composed here rather than by NotificationDispatcher.
     *
     * The dispatcher stamps organization_id from TenantContext::idOrFail, and a
     * staff request is in PLATFORM SCOPE with no tenant to ask — calling it here
     * would throw from the control plane. The suggestion already carries the
     * organization, so the row is written directly. Nothing else the dispatcher
     * does is wanted either: its audience resolution answers "who could have
     * seen this record", and this record has exactly one reader, already known.
     *
     * `source` is `system`, which NotificationDispatcher::SOURCE_SETTINGS leaves
     * out deliberately and permanently — so an organization's notification
     * toggles cannot suppress it. That is consistent with the rest of this
     * module: the organization does not control this channel.
     */
    private function tell(Suggestion $row): void
    {
        Notification::insert([
            'id' => (string) Str::uuid7(),
            'organization_id' => $row->organization_id,
            'user_id' => $row->user_id,
            'source' => 'system',
            'title' => $row->status === Suggestion::STATUS_APPROVED
                ? 'Your suggestion was approved'
                : 'Your suggestion was answered',
            'body' => $row->decision_note,
            'subject_type' => Suggestion::class,
            'subject_id' => $row->id,
            'created_at' => now(),
        ]);
    }
```

- [ ] **Step 4: Add the route**

Beside the queue route from Task 4:

```php
        Route::post('suggestions/{suggestion}/decide', [StaffSuggestionsController::class, 'decide']);
```

- [ ] **Step 5: Run the tests to verify they pass**

Run: `DB_DATABASE=ezbizi_testing_suggestions vendor/bin/phpunit --filter=StaffSuggestionsTest`
Expected: PASS, 10 tests.

- [ ] **Step 6: Run both suites together plus the privacy tests**

Run: `DB_DATABASE=ezbizi_testing_suggestions vendor/bin/phpunit --filter="Suggestion|RetentionTest|DataInventoryTest|PersonalDataPurgeTest"`
Expected: PASS. `Notification::insert` bypasses the model's `creating` hooks by design; if `RetentionTest` complains about `updated_at`, the insert has written a column the notifications table does not have — `Notification::UPDATED_AT` is null.

- [ ] **Step 7: Commit**

```bash
git add app/Http/Controllers/Api/StaffSuggestionsController.php tests/Feature/Api/StaffSuggestionsTest.php routes/api.php
git commit -m "Suggestions: deciding one, and telling the person who sent it"
```

---

### Task 6: The design gate — numbered mockups

**Files:**
- Create: `docs/previews/suggestions-1.html`, `docs/previews/suggestions-2.html`, `docs/previews/suggestions-3.html`
- Create: `docs/previews/suggestions-queue-1.html`, `docs/previews/suggestions-queue-2.html`, `docs/previews/suggestions-queue-3.html`

**Interfaces:**
- Consumes: the response shapes from Tasks 2–5, so the mockups show real fields and not invented ones
- Produces: a chosen number for each of the two screens, which Tasks 7 and 8 build against

`docs/UI-DESIGN-QUEUE.md`: a new tab or major screen is presented as several alternatives in the app's own chrome, with the real palette and real data, **numbered so the owner answers by number**. Arrangement is design's decision, not the module session's — do not skip this and do not pick one yourself.

- [ ] **Step 1: Read the worked examples and the palette**

Read two existing files in `docs/previews/` to match the chrome, and take colours from `client/lib/core/theme.dart`. Do not invent a palette.

- [ ] **Step 2: Build three alternatives for the author's tab**

Each must show, with realistic content: the compose box; a suggestion in each of the four states; the decision note on a denied one; and the edit/withdraw controls present on a `new` one and absent on the others. That last pair is the part most likely to be got wrong, so it must be visible in the mockup rather than described.

- [ ] **Step 3: Build three alternatives for the Development sub-tab**

Each must show: the queue ordered `new` → `reviewing` → decided; the organization and author on each row; and the decide control with its note field, including the state where denying without a note is refused.

- [ ] **Step 4: Send them to Jose, numbered**

Do not proceed to Task 7 or 8 without an answer. Record the chosen numbers in `docs/UI-DESIGN-QUEUE.md` under the settled section, so the decision outlives this session.

- [ ] **Step 5: Commit**

```bash
git add docs/previews/suggestions-*.html docs/UI-DESIGN-QUEUE.md
git commit -m "Suggestions: numbered mockups for the two new screens"
```

---

### Task 7: The author's tab

**Files:**
- Create: `client/lib/screens/suggestions_screen.dart`
- Modify: `client/lib/main.dart` — the `pinned` list at line 704, and `_apply`
- Test: `client/test/suggestions_screen_test.dart`

**Interfaces:**
- Consumes: `GET/POST/PATCH/DELETE /api/v1/suggestions` from Tasks 2–3; `ApiClient.get(String path)`, `.post(String path, Map<String, dynamic> body)`, `.patch(...)`, `.delete(String path)`, each returning `ApiResponse` with `bool ok`, `Map<String, dynamic> body`, `String? message`; `Destination(String label, IconData icon, Widget screen, {String? id})`
- Produces: `SuggestionsScreen({required ApiClient api})`

The **arrangement** comes from the number Jose chose in Task 6. Everything below — the wiring, the routes called, the states handled — does not, and is fixed regardless of which number won.

- [ ] **Step 1: Write the failing widget test**

The house pattern for a screen test is `MockClient` from `package:http/testing.dart`
handed to `ApiClient(client: ...)`, with a private `_handler`/`_pump` pair local to
the file. There is no shared `client/test/support/` directory and this task does not
add one. Copy the shape from `client/test/client_versions_screen_test.dart`.

Note the package name is `ezbizi_client`, not `ezbizi`.

```dart
import 'dart:convert';

import 'package:ezbizi_client/core/api_client.dart';
import 'package:ezbizi_client/core/theme.dart';
import 'package:ezbizi_client/screens/suggestions_screen.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';

void main() {
  testWidgets('a new suggestion offers edit and withdraw', (tester) async {
    await _pump(tester, _listing([_row(status: 'new')]));

    expect(find.text('Let the board remember its filter.'), findsOneWidget);
    expect(find.byIcon(Icons.edit_outlined), findsOneWidget);
    expect(find.byIcon(Icons.delete_outline), findsOneWidget);
  });

  /// The client half of the server rule: a control the server will refuse must
  /// not be offered. See SuggestionsController::frozen.
  testWidgets('a decided suggestion shows the note and no controls', (
    tester,
  ) async {
    await _pump(tester, _listing([
      _row(status: 'denied', note: 'It is in Settings already.'),
    ]));

    expect(find.text('It is in Settings already.'), findsOneWidget);
    expect(find.byIcon(Icons.edit_outlined), findsNothing);
    expect(find.byIcon(Icons.delete_outline), findsNothing);
  });
}

Map<String, dynamic> _row({required String status, String? note}) => {
  'id': 'a',
  'body': 'Let the board remember its filter.',
  'status': status,
  'decision_note': note,
  'decided_at': note == null ? null : '2026-09-02T11:00:00+00:00',
  'created_at': '2026-09-02T10:00:00+00:00',
};

MockClientHandler _listing(List<Map<String, dynamic>> rows) {
  return (http.Request request) async {
    if (request.url.path.endsWith('/suggestions')) {
      return http.Response(
        jsonEncode({'data': rows}),
        200,
        headers: {'content-type': 'application/json'},
      );
    }

    return http.Response('{}', 404, headers: {'content-type': 'application/json'});
  };
}

Future<void> _pump(WidgetTester tester, MockClientHandler handler) async {
  tester.view.physicalSize = const Size(1600, 2200);
  tester.view.devicePixelRatio = 1.0;
  addTearDown(tester.view.reset);

  await tester.pumpWidget(
    MaterialApp(
      theme: EzBiziTheme.light(),
      home: Scaffold(
        body: SuggestionsScreen(api: ApiClient(client: MockClient(handler))),
      ),
    ),
  );
  await tester.pumpAndSettle();
}
```

- [ ] **Step 2: Run to verify it fails**

Run: `cd client && flutter test test/suggestions_screen_test.dart`
Expected: FAIL — the file `suggestions_screen.dart` does not exist.

- [ ] **Step 3: Build the screen against the chosen mockup**

Required behaviour, independent of arrangement:

- Loads `GET /suggestions` on mount; shows the error from `ApiResponse.message` when `!ok`, not a generic string.
- A compose field with a 2000-character limit, disabled while a send is in flight so a second tap cannot post twice.
- Each row renders its state in words — New, In review, Approved, Denied — never a bare status key.
- Edit and withdraw controls appear **only** when `status == 'new'`. This is the client half of the server rule in Task 3; the server refuses regardless, and the screen must not offer an action it knows will be refused.
- A `decision_note` renders whenever present.
- Empty state says what the tab is for, since most people will open it with nothing in it.

- [ ] **Step 4: Run the test to verify it passes**

Run: `cd client && flutter test test/suggestions_screen_test.dart`
Expected: PASS.

- [ ] **Step 5: Register the destination**

In `client/lib/main.dart`, in the `pinned` list at line 704, after the Settings destination:

```dart
      // Suggestions is pinned rather than a rail destination because it is not
      // in `me.modules` and never varies. The business rail is assembled per
      // person and can change length while somebody is looking at it; this
      // cannot. It is deliberately not gated on anything — there is no module
      // key, no permission and no availability flag behind it, which is what
      // makes it unremovable. See App\Models\Suggestion.
      Destination(
        'Suggestions',
        Icons.lightbulb_outline,
        SuggestionsScreen(api: widget.api),
        id: 'suggestions',
      ),
```

And in `_apply`, extend the pinned branch so an old or shared `/suggestions` URL lands here:

```dart
    if (id == 'organization' || id == 'settings') {
      _rail = _Rail.pinned;
      _selected = 'settings';
      return;
    }

    if (id == 'suggestions') {
      _rail = _Rail.pinned;
      _selected = id;
      return;
    }
```

Add `import 'screens/suggestions_screen.dart';` in alphabetical position.

- [ ] **Step 6: Run the client suite and the web build**

Run: `cd client && flutter analyze && flutter test`
Expected: PASS.

Run: `cd client && flutter build web`
Expected: succeeds. This is the only gate that catches a `dart:io` import; analyze and test are both blind to it.

- [ ] **Step 7: Commit**

```bash
git add client/lib/screens/suggestions_screen.dart client/lib/main.dart client/test/suggestions_screen_test.dart
git commit -m "Suggestions: the author's tab, pinned for everybody"
```

---

### Task 8: The Development sub-tab

**Files:**
- Create: `client/lib/screens/staff/suggestions_queue_screen.dart`
- Modify: `client/lib/screens/development_screen.dart` — the `tabs` list at line 174
- Test: `client/test/suggestions_queue_screen_test.dart`

**Interfaces:**
- Consumes: `GET /api/v1/staff/suggestions` and `POST /api/v1/staff/suggestions/{id}/decide` from Tasks 4–5; `StaffSession`, as `phone_requests_screen.dart` uses it
- Produces: `SuggestionsQueueScreen({required StaffSession staff})`

Read `client/lib/screens/staff/phone_requests_screen.dart` first. It is the same screen with different nouns — a cross-tenant queue with a decision that carries a mandatory sentence — and following it keeps the two control-plane queues looking like one product.

- [ ] **Step 1: Write the failing widget test**

A staff screen takes a `StaffSession`, which must be signed in before the screen is
pumped — so the handler has to answer `/staff/me` as well as the queue, and `_pump`
awaits `staff.adopt('tok')` inside `tester.runAsync`. Copy
`client/test/client_versions_screen_test.dart`'s `_pump` and reuse its `_me` stub
shape rather than inventing one.

```dart
import 'dart:convert';

import 'package:ezbizi_client/core/api_client.dart';
import 'package:ezbizi_client/core/staff_session.dart';
import 'package:ezbizi_client/core/theme.dart';
import 'package:ezbizi_client/screens/staff/suggestions_queue_screen.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';

void main() {
  testWidgets('the queue names the organization and the author', (tester) async {
    await _pump(tester, _handler());

    expect(find.text('A-1 Chimney'), findsOneWidget);
    expect(find.text('Jose'), findsOneWidget);
    expect(find.text('Let the board remember its filter.'), findsOneWidget);
  });
}

MockClientHandler _handler() {
  return (http.Request request) async {
    final path = request.url.path;

    if (path.endsWith('/staff/me')) return _json({'data': _me});

    if (path.endsWith('/staff/suggestions') && request.method == 'GET') {
      return _json({
        'data': [
          {
            'id': 'a',
            'organization': 'A-1 Chimney',
            'organization_id': 'org-1',
            'author': 'Jose',
            'body': 'Let the board remember its filter.',
            'status': 'new',
            'decision_note': null,
            'decided_at': null,
            'created_at': '2026-09-02T10:00:00+00:00',
          },
        ],
      });
    }

    return _json({}, 404);
  };
}

/// Copy the real shape from client_versions_screen_test.dart — StaffSession
/// refuses to sign in on a payload it does not recognise, and the failure looks
/// like an empty screen rather than an error.
const _me = <String, dynamic>{};

http.Response _json(Map<String, dynamic> body, [int status = 200]) =>
    http.Response(jsonEncode(body), status,
        headers: {'content-type': 'application/json'});

Future<void> _pump(WidgetTester tester, MockClientHandler handler) async {
  tester.view.physicalSize = const Size(1600, 2200);
  tester.view.devicePixelRatio = 1.0;
  addTearDown(tester.view.reset);

  final api = ApiClient(client: MockClient(handler));
  final staff = StaffSession(api);

  await tester.runAsync(() => staff.adopt('tok'));

  expect(staff.signedIn, isTrue,
      reason: 'The /staff/me stub did not produce a session.');

  await tester.pumpWidget(
    MaterialApp(
      theme: EzBiziTheme.light(),
      home: Scaffold(body: SuggestionsQueueScreen(staff: staff)),
    ),
  );
  await tester.pumpAndSettle();
}
```

- [ ] **Step 2: Run to verify it fails**

Run: `cd client && flutter test test/suggestions_queue_screen_test.dart`
Expected: FAIL — the file does not exist.

- [ ] **Step 3: Build the screen against the chosen mockup**

Required behaviour, independent of arrangement:

- Loads `GET /suggestions` on the staff client on mount.
- Rows show organization, author, when it arrived, the body in full, and the state in words.
- The decide control offers In review, Approved and Denied — never New, which the server refuses.
- The note field is required to deny and the button is disabled until it has content, so the server's 422 is a backstop rather than the way a person discovers the rule.
- A server refusal renders its `message` verbatim; that sentence is the one the author will read.

- [ ] **Step 4: Run the test to verify it passes**

Run: `cd client && flutter test test/suggestions_queue_screen_test.dart`
Expected: PASS.

- [ ] **Step 5: Add the tab**

In `client/lib/screens/development_screen.dart`, in the `tabs` list at line 174, after Health and before the lead-only Staff entry:

```dart
      (
        'Suggestions',
        Icons.lightbulb_outline,
        SuggestionsQueueScreen(staff: _staff),
      ),
```

Add `import 'staff/suggestions_queue_screen.dart';` in alphabetical position.

Placed before the `if (me.isLead)` entry deliberately: that one is conditional, and a fixed tab added after it would change position depending on who is looking.

- [ ] **Step 6: Run the client suite and the web build**

Run: `cd client && flutter analyze && flutter test`
Expected: PASS. If a golden test for the Development tab strip exists, it will fail on the new tab — regenerate it and check the diff shows only the added tab.

Run: `cd client && flutter build web`
Expected: succeeds.

- [ ] **Step 7: Commit**

```bash
git add client/lib/screens/staff/suggestions_queue_screen.dart client/lib/screens/development_screen.dart client/test/suggestions_queue_screen_test.dart
git commit -m "Suggestions: the Development queue sub-tab"
```

---

### Task 9: Hand it over

**Files:**
- Modify: `docs/EZBIZI-BUILD-LOG.md`
- Modify: `docs/MODULE-OWNERS.md`

- [ ] **Step 1: Run the whole backend suite on your own schema**

Run: `DB_DATABASE=ezbizi_testing_suggestions vendor/bin/phpunit`
Expected: PASS, or a failure list identical to main's before your branch. Compare against main rather than assuming — a pre-existing failure is not yours to fix and not yours to hide either.

- [ ] **Step 2: Run the whole client suite and the web build**

Run: `cd client && flutter analyze && flutter test && flutter build web`

- [ ] **Step 3: Write the build-log entry**

One paragraph: what shipped, and the one thing a future reader needs — that Suggestions is deliberately absent from `ModuleRegistry`, and that a well-meaning later commit registering it would hand organizations the ability to switch it off.

- [ ] **Step 4: Add the row to MODULE-OWNERS.md**

Module, files, and your commit SHAs — a SHA is checkable by anyone, a session name is checkable by nobody.

- [ ] **Step 5: Commit and hand the branch to the deploy desk**

```bash
git add docs/EZBIZI-BUILD-LOG.md docs/MODULE-OWNERS.md
git commit -m "Suggestions: build log and owners row"
git log --oneline main..HEAD
```

Give the deploy desk the branch name `session/suggestions` and that SHA list. You cannot merge to main yourself — git refuses to push into a branch checked out in the primary tree, and that refusal is the design.

Do **not** bump `client/VERSION` or write `client/lib/core/version_history.dart`. The deploy desk owns both; a version bumped here forces them into `--no-bump`, and a note written here goes stale the moment the release slips.
