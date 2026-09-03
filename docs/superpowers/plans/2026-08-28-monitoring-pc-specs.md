# Monitoring — PC Specs Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Ship the Monitoring module's first tab — PC Specs — a roster of the
people in view and the devices they are logged in from, with a right-hand glance
panel, delivering value with no native agent installed.

**Architecture:** A new tenant-scoped module. Clients send a presence **heartbeat**
to one ingest endpoint; the server keeps one overwritten row per device
(`monitored_devices`) and an append log of tab dwell (`monitoring_activity`). A read
endpoint feeds the Flutter tab. A device declares its own **capability set**, so a
web session is shown as un-capturable rather than blank. Two automation triggers fire
through the outbox: `monitoring.new_device_seen` and `monitoring.device_offline`.

**Tech Stack:** Laravel 13 · PHP 8.4 · MySQL 8 (backend) · Flutter web (`client/`) ·
Sanctum auth · existing registries (`ModuleRegistry`, `PermissionCatalogue`,
`RetentionPolicy`, `EventEmitter`, `SettingsResolver`).

**Spec:** `docs/superpowers/specs/2026-08-28-monitoring-module-design.md`

## Global Constraints

Every task's requirements implicitly include this section. Values copied from the
spec, `StartUp.md`, `docs/ACTIVE-CONSTRAINTS.md`, and the deployment session.

- **Worktree, own index.** Work on branch `session/monitoring` via
  `scripts/session-worktree.sh monitoring` (`--with-vendor` for PHP tests). Hand the
  deployment session (`ezbizi-a1`) a branch + SHAs; it merges to main and deploys. Do
  NOT commit to `main` or push.
- **Commit discipline (StartUp §4).** Stage the exact paths and commit **in one tool
  call** (`GIT_INDEX_FILE` is per-shell). After committing, `git show <sha> --stat`
  and compare **line counts** to what you wrote; `git diff --cached
  --diff-filter=D --name-only` to catch staged deletions of healthy files.
- **UUIDv7 primary keys** (`HasUuids`). **`BelongsToTenant`** on every tenant table.
- **Every new table declares a `RetentionPolicy`** (or a `RetentionTest` exception
  with a reason). A swept (`window`) table must ALSO be named in the
  `RetentionPolicy::window()` docblock enumeration, or `RetentionTest` fails.
- **Personal data → `HoldsPersonalData`** (§9). Monitoring holds a person's IP,
  user-agent, device specs, and tab history: both models implement it.
- **No `DB::unprepared` / `CREATE TRIGGER`** in a migration (MySQL error 1419 on the
  gate). `device_offline` is a scheduled scan, never a DB trigger.
- **Testing:** run against `DB_DATABASE=ezbizi_testing_monitoring` (never the shared
  `ezbizi_testing`). MySQL is the gate — sqlite disagrees on JSON key order / VARCHAR
  / REGEXP. After writing each test, delete the line it guards and watch it go **red**,
  then restore. Assert at least one **literal the codebase cannot renegotiate with
  itself** (a real column name, a fixed sentence, a count).
- **App Store review freeze:** new module, new endpoints, new permissions are safe.
  Do NOT change an existing endpoint's response shape, tighten a permission answer,
  edit a `Refusal.php` sentence, or set/raise a minimum client version.
- **Client house rules:** no colour literals (read `client/lib/core/theme.dart`); no
  modal (inline expansion); must work at **375px**; a refusal shows the server's
  sentence. Register the screen in `module_screens.dart`.
- **Scope:** slice 1 declares only `view` + `specs.view` permissions and the two
  triggers above. `screenshots.view` / `control` / `cameras.manage` / `disk_low` /
  the screenshots table land with Sub-projects 2–3 (add-only, later).

---

## File Structure

**Backend**
- `database/migrations/2026_08_28_190000_create_monitoring_tables.php` — both tables.
- `app/Models/MonitoredDevice.php` — one overwritten row per (org, user, device).
- `app/Models/MonitoringActivity.php` — append log of tab dwell.
- `database/factories/MonitoredDeviceFactory.php`, `MonitoringActivityFactory.php`.
- `app/Support/Privacy/RetentionPolicy.php` — **modify** the `window()` docblock list.
- `app/Providers/ModuleServiceProvider.php` — **modify** `businessModules()`.
- `app/Http/Controllers/Api/Monitoring/HeartbeatController.php` — ingest.
- `app/Http/Controllers/Api/Monitoring/DevicesController.php` — read.
- `app/Policies/MonitoredDevicePolicy.php` — `viewAny` → `monitoring.view`.
- `routes/api.php` — **modify**: heartbeat group (no `idle`) + devices route.
- `app/Support/Monitoring/DeviceOfflineWorker.php` — the offline scan.
- `app/Console/Commands/DispatchMonitoringDeviceOffline.php` — schedules the scan.
- `routes/console.php` — **modify**: schedule the command.
- `tests/Feature/Monitoring/*Test.php`.

**Client**
- `client/lib/core/monitoring_heartbeat.dart` — the beat.
- `client/lib/screens/monitoring/monitoring_screen.dart` — tab shell + sub-tabs.
- `client/lib/screens/monitoring/pc_specs_tab.dart` — roster + glance panel.
- `client/lib/core/module_screens.dart` — **modify**: register `monitoring`.
- `client/test/monitoring_screen_test.dart`.

Register the policy in `AuthServiceProvider` (or wherever policies are mapped — grep
`Policy::class` there first and follow that pattern).

---

## Task 1: `monitored_devices` table + model

**Files:**
- Create: `database/migrations/2026_08_28_190000_create_monitoring_tables.php` (both
  tables in one migration; `monitoring_activity` is filled in Task 2)
- Create: `app/Models/MonitoredDevice.php`
- Create: `database/factories/MonitoredDeviceFactory.php`
- Test: `tests/Feature/Monitoring/MonitoredDeviceTest.php`

**Interfaces:**
- Produces: `MonitoredDevice` with columns `id, organization_id, user_id,
  device_key, label, platform, capabilities(json), user_agent, ip_address,
  last_seen_at, current_module, specs(json,null), offline_alerted_at(null),
  created_at, updated_at`. Unique `(organization_id, user_id, device_key)`.
  `retention()` → `RetentionPolicy::forever(...)`. `personalDataAbout(User)` →
  Builder of rows where `user_id` = that user.

- [ ] **Step 1: Write the failing test**

```php
<?php
namespace Tests\Feature\Monitoring;

use App\Models\MonitoredDevice;
use App\Support\Privacy\RetentionPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

class MonitoredDeviceTest extends TestCase
{
    use BuildsTenants, RefreshDatabase;

    public function test_a_device_is_a_single_overwritten_row_kept_forever(): void
    {
        $this->assertSame(RetentionPolicy::FOREVER, MonitoredDevice::retention()->kind);
    }

    public function test_a_device_is_personal_data_about_its_user(): void
    {
        $alpha = $this->provisionTenant('Alpha');
        $device = $this->inTenant($alpha['org'], fn () => MonitoredDevice::factory()->create([
            'organization_id' => $alpha['org']->id,
            'user_id'         => $alpha['admin']->getKey(),
        ]));

        $about = $this->inTenant($alpha['org'],
            fn () => MonitoredDevice::personalDataAbout($alpha['admin'])->pluck('id')->all());

        $this->assertContains($device->id, $about);
        // The purpose sentence is the §9 answer, not a colleague note.
        $this->assertNotEmpty(MonitoredDevice::personalDataPurpose());
    }
}
```

- [ ] **Step 2: Run it, verify it fails**

Run: `DB_DATABASE=ezbizi_testing_monitoring vendor/bin/phpunit tests/Feature/Monitoring/MonitoredDeviceTest.php`
Expected: FAIL — class `MonitoredDevice` not found.

- [ ] **Step 3: Write the migration**

```php
<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('monitored_devices', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('user_id')->constrained()->cascadeOnDelete();
            // A stable id the client persists locally; identifies a device across sessions.
            $table->string('device_key', 64);
            $table->string('label', 120)->nullable();
            $table->string('platform', 16); // web|android|ios|windows|macos|linux
            $table->json('capabilities');   // ['activity'] | ['activity','specs',...]
            $table->string('user_agent', 400)->nullable();
            $table->string('ip_address', 45)->nullable(); // IPv6 + v4 tail = 45
            $table->timestamp('last_seen_at')->nullable();
            $table->string('current_module', 64)->nullable();
            $table->json('specs')->nullable(); // agent devices only
            // Set when a device_offline event has been raised; cleared on the next
            // beat, so the event fires once per offline episode, not every scan.
            $table->timestamp('offline_alerted_at')->nullable();
            $table->timestamps();

            $table->unique(['organization_id', 'user_id', 'device_key']);
            $table->index(['organization_id', 'last_seen_at']);
        });

        // monitoring_activity is created in Task 2 (same migration file).
    }

    public function down(): void
    {
        Schema::dropIfExists('monitoring_activity');
        Schema::dropIfExists('monitored_devices');
    }
};
```

- [ ] **Step 4: Write the model** (pattern: `app/Models/PhonePresence.php` for the
  concerns; `app/Models/ApiToken.php` for a `HoldsPersonalData` example)

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

class MonitoredDevice extends Model implements HoldsPersonalData
{
    use BelongsToTenant, HasFactory, HasUuids;

    protected $fillable = [
        'organization_id', 'user_id', 'device_key', 'label', 'platform',
        'capabilities', 'user_agent', 'ip_address', 'last_seen_at',
        'current_module', 'specs', 'offline_alerted_at',
    ];

    protected $casts = [
        'capabilities'       => 'array',
        'specs'              => 'array',
        'last_seen_at'       => 'datetime',
        'offline_alerted_at' => 'datetime',
    ];

    public static function retention(): RetentionPolicy
    {
        return RetentionPolicy::forever(
            'One current row per person per device; it is replaced on each beat, not appended.'
        );
    }

    public static function personalDataPurpose(): string
    {
        return 'The devices a person is signed in from, so an organization can see who is '
            .'working and on what.';
    }

    /** @return PersonalDataEntry[] */
    public static function personalDataHeld(): array
    {
        return [
            new PersonalDataEntry('ip_address', PersonalDataEntry::TECHNICAL,
                'The address a device last connected from, so a session can be recognised.',
                Erasure::overwrite('Cleared when the person is erased.')),
            new PersonalDataEntry('user_agent', PersonalDataEntry::TECHNICAL,
                'The browser or app build a device reports, to tell sessions apart.',
                Erasure::overwrite('Cleared when the person is erased.')),
            new PersonalDataEntry('current_module', PersonalDataEntry::ACTIVITY,
                'The tab a person was last on, shown to their organization.',
                Erasure::overwrite('Cleared when the person is erased.')),
            new PersonalDataEntry('specs', PersonalDataEntry::TECHNICAL,
                'Hardware a desktop agent reports (RAM, disk, wifi, open apps).',
                Erasure::overwrite('Cleared when the person is erased.')),
        ];
    }

    public static function personalDataAbout(\App\Models\User $user): ?Builder
    {
        return static::query()->where('user_id', $user->getKey());
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
```

Check `Erasure`'s real factory names first (`grep -n 'public static' app/Support/Privacy/Erasure.php`) and match them; `overwrite` is illustrative.

- [ ] **Step 5: Write the factory** (pattern: `database/factories/` siblings)

```php
<?php
namespace Database\Factories;

use App\Models\MonitoredDevice;
use Illuminate\Database\Eloquent\Factories\Factory;

class MonitoredDeviceFactory extends Factory
{
    protected $model = MonitoredDevice::class;

    public function definition(): array
    {
        return [
            'device_key'     => $this->faker->uuid(),
            'label'          => 'Shop desktop',
            'platform'       => 'web',
            'capabilities'   => ['activity'],
            'user_agent'     => 'Mozilla/5.0',
            'ip_address'     => $this->faker->ipv4(),
            'last_seen_at'   => now(),
            'current_module' => 'jobs',
        ];
    }
}
```

- [ ] **Step 6: Run the tests, verify they pass**

Run: `DB_DATABASE=ezbizi_testing_monitoring vendor/bin/phpunit tests/Feature/Monitoring/MonitoredDeviceTest.php`
Expected: PASS. Then delete the `->where('user_id', ...)` in `personalDataAbout` and
confirm `test_a_device_is_personal_data_about_its_user` goes red; restore.

- [ ] **Step 7: Commit** (one tool call, exact paths)

```bash
git add database/migrations/2026_08_28_190000_create_monitoring_tables.php \
        app/Models/MonitoredDevice.php \
        database/factories/MonitoredDeviceFactory.php \
        tests/Feature/Monitoring/MonitoredDeviceTest.php
git commit -m "Monitoring: a device is one row, kept forever, and §9-declared"
```

---

## Task 2: `monitoring_activity` table + model + retention docblock

**Files:**
- Modify: `database/migrations/2026_08_28_190000_create_monitoring_tables.php` (add
  the second table inside `up()`)
- Create: `app/Models/MonitoringActivity.php`
- Create: `database/factories/MonitoringActivityFactory.php`
- Modify: `app/Support/Privacy/RetentionPolicy.php` (the `window()` docblock list)
- Test: `tests/Feature/Monitoring/MonitoringActivityTest.php` **and** the existing
  `tests/Feature/RetentionTest.php` must stay green.

**Interfaces:**
- Produces: `MonitoringActivity` with `id, organization_id, device_id, user_id,
  module_key, dwell_seconds, occurred_at`. No `created_at/updated_at`
  (`$timestamps = false`). `retention()` → `RetentionPolicy::window(3,
  'occurred_at', ...)`.

- [ ] **Step 1: Write the failing test**

```php
<?php
namespace Tests\Feature\Monitoring;

use App\Models\MonitoringActivity;
use App\Support\Privacy\RetentionPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

class MonitoringActivityTest extends TestCase
{
    use BuildsTenants, RefreshDatabase;

    public function test_activity_is_swept_after_three_months_measured_from_occurred_at(): void
    {
        $policy = MonitoringActivity::retention();
        $this->assertSame(RetentionPolicy::WINDOW, $policy->kind);
        $this->assertSame(3, $policy->months);
        $this->assertSame('occurred_at', $policy->column);
    }
}
```

- [ ] **Step 2: Run it + the retention suite, verify the new one fails**

Run: `DB_DATABASE=ezbizi_testing_monitoring vendor/bin/phpunit tests/Feature/Monitoring/MonitoringActivityTest.php`
Expected: FAIL — class not found.

- [ ] **Step 3: Add the table to the migration `up()`** (after `monitored_devices`)

```php
Schema::create('monitoring_activity', function (Blueprint $table) {
    $table->uuid('id')->primary();
    $table->foreignUuid('organization_id')->constrained()->cascadeOnDelete();
    $table->foreignUuid('device_id')->constrained('monitored_devices')->cascadeOnDelete();
    $table->foreignUuid('user_id')->constrained()->cascadeOnDelete();
    $table->string('module_key', 64);
    $table->unsignedInteger('dwell_seconds');
    // Append-only: timed by when it happened, not by row creation. The sweep
    // measures the 3-month window from this column.
    $table->timestamp('occurred_at')->index();
});
```

- [ ] **Step 4: Write the model**

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

class MonitoringActivity extends Model implements HoldsPersonalData
{
    use BelongsToTenant, HasFactory, HasUuids;

    public $timestamps = false;

    protected $fillable = [
        'organization_id', 'device_id', 'user_id', 'module_key',
        'dwell_seconds', 'occurred_at',
    ];

    protected $casts = ['occurred_at' => 'datetime', 'dwell_seconds' => 'integer'];

    public static function retention(): RetentionPolicy
    {
        return RetentionPolicy::window(3, 'occurred_at',
            'A three-month view of which tabs a person worked in, then gone.');
    }

    public static function personalDataPurpose(): string
    {
        return 'How long each person spent in each tab, so an organization can see how '
            .'its people work.';
    }

    /** @return PersonalDataEntry[] */
    public static function personalDataHeld(): array
    {
        return [
            new PersonalDataEntry('module_key', PersonalDataEntry::ACTIVITY,
                'The tab a person was in for a stretch of time.',
                Erasure::deleteRow('The whole row is a person’s activity; it is removed.')),
        ];
    }

    public static function personalDataAbout(\App\Models\User $user): ?Builder
    {
        return static::query()->where('user_id', $user->getKey());
    }
}
```

Match `Erasure`'s real method names (Task 1 note).

- [ ] **Step 5: Write the factory**

```php
<?php
namespace Database\Factories;

use App\Models\MonitoringActivity;
use Illuminate\Database\Eloquent\Factories\Factory;

class MonitoringActivityFactory extends Factory
{
    protected $model = MonitoringActivity::class;

    public function definition(): array
    {
        return [
            'module_key'    => 'jobs',
            'dwell_seconds' => 1320,
            'occurred_at'   => now(),
        ];
    }
}
```

- [ ] **Step 6: Update the `RetentionPolicy::window()` docblock** — SHARED §9 FILE,
  announce to `ezbizi-a1` first. In `app/Support/Privacy/RetentionPolicy.php`, the
  sentence beginning "The six tables this governs today are …": change **six → seven**
  and add `` `monitoring_activity` (occurred_at) `` to the enumerated list. Edit only
  that sentence; touch nothing else in the file.

- [ ] **Step 7: Run both suites, verify green**

Run: `DB_DATABASE=ezbizi_testing_monitoring vendor/bin/phpunit tests/Feature/Monitoring/MonitoringActivityTest.php tests/Feature/RetentionTest.php`
Expected: PASS. Then, to prove the docblock check bites: revert the "seven" edit and
watch `RetentionTest::test_this_constructor_names_every_table_it_governs` go red;
restore.

- [ ] **Step 8: Commit**

```bash
git add database/migrations/2026_08_28_190000_create_monitoring_tables.php \
        app/Models/MonitoringActivity.php \
        database/factories/MonitoringActivityFactory.php \
        app/Support/Privacy/RetentionPolicy.php \
        tests/Feature/Monitoring/MonitoringActivityTest.php
git commit -m "Monitoring: tab-dwell log, swept at three months, named in the sweep's own list"
```

---

## Task 3: Register the module

**Files:**
- Modify: `app/Providers/ModuleServiceProvider.php` — add a block inside
  `businessModules()` (returns near line 431).
- Test: `tests/Feature/Monitoring/MonitoringModuleRegistrationTest.php`; existing
  `tests/Feature/ModuleRegistryTest.php` must stay green.

**Interfaces:**
- Consumes: `ModuleDefinition`, `TriggerDefinition`, `AutomationSubject` (already
  imported in that file).
- Produces: module key `monitoring`; permission keys `monitoring.view`,
  `monitoring.specs.view`; triggers `monitoring.new_device_seen`,
  `monitoring.device_offline`; subject `MonitoredDevice`.

- [ ] **Step 1: Write the failing test**

```php
<?php
namespace Tests\Feature\Monitoring;

use App\Support\Modules\ModuleRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MonitoringModuleRegistrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_monitoring_is_a_module_with_view_and_specs_view_permissions(): void
    {
        $module = app(ModuleRegistry::class)->get('monitoring');

        $this->assertNotNull($module);
        $this->assertContains('view', $module->actions);
        $this->assertContains('specs.view', $module->actions);
    }

    public function test_monitoring_declares_its_two_triggers(): void
    {
        $module = app(ModuleRegistry::class)->get('monitoring');
        $keys = array_map(fn ($t) => $t->key, $module->triggers);

        $this->assertContains('monitoring.new_device_seen', $keys);
        $this->assertContains('monitoring.device_offline', $keys);
    }
}
```

Confirm `ModuleRegistry`'s accessor name first (`grep -n 'public function' app/Support/Modules/ModuleRegistry.php` — it may be `get`, `find`, or `for`); match it.

- [ ] **Step 2: Run it, verify it fails**

Run: `DB_DATABASE=ezbizi_testing_monitoring vendor/bin/phpunit tests/Feature/Monitoring/MonitoringModuleRegistrationTest.php`
Expected: FAIL — no module `monitoring`.

- [ ] **Step 3: Add the module** (inside `businessModules()`, mirroring any
  existing module block in the same file)

```php
new ModuleDefinition(
    key: 'monitoring',
    label: 'Monitoring',
    // Devices are reported by their owners, not created/edited by an admin,
    // so this is not CRUD. `specs.view` is a second grant so an org can let a
    // manager see who is online without seeing hardware detail.
    actions: ['view', 'specs.view'],
    triggers: [
        new TriggerDefinition(
            key: 'monitoring.new_device_seen',
            label: 'New device seen',
            description: 'A person signed in from a device not seen before.',
            isRaised: true,
        ),
        new TriggerDefinition(
            key: 'monitoring.device_offline',
            label: 'Device went offline',
            description: 'A device stopped reporting for longer than the allowed gap.',
            isRaised: true,
        ),
    ],
    subject: new AutomationSubject(\App\Models\MonitoredDevice::class, 'Device'),
    sidebar: true,
),
```

- [ ] **Step 4: Run the module tests, verify pass**

Run: `DB_DATABASE=ezbizi_testing_monitoring vendor/bin/phpunit tests/Feature/Monitoring/MonitoringModuleRegistrationTest.php tests/Feature/ModuleRegistryTest.php`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add app/Providers/ModuleServiceProvider.php \
        tests/Feature/Monitoring/MonitoringModuleRegistrationTest.php
git commit -m "Monitoring: the module declares itself — view, specs.view, two triggers"
```

---

## Task 4: Heartbeat ingest endpoint

**Files:**
- Create: `app/Http/Controllers/Api/Monitoring/HeartbeatController.php`
- Modify: `routes/api.php` — a **separate** authed group without `idle`.
- Test: `tests/Feature/Monitoring/HeartbeatTest.php`

**Interfaces:**
- Consumes: `MonitoredDevice`, `MonitoringActivity`, `EventEmitter::emit()`
  (`app/Support/Automations/EventEmitter.php:37`), `TenantContext`.
- Produces: `POST /api/v1/monitoring/heartbeat`. Body: `device_key` (required),
  `platform` (required, in the six), `capabilities` (array of the allowed set),
  `label?`, `current_module?`, `specs?` (object, stored only if `capabilities`
  contains `specs`), `segment?` `{module_key, dwell_seconds}` (appends one activity
  row). Returns `204`. Upserts one device row; emits `monitoring.new_device_seen`
  once, on creation.

- [ ] **Step 1: Write the failing tests** — the load-bearing one is idle-slide.

```php
<?php
namespace Tests\Feature\Monitoring;

use App\Models\AutomationEvent;
use App\Models\MonitoredDevice;
use App\Models\MonitoringActivity;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

class HeartbeatTest extends TestCase
{
    use BuildsTenants, RefreshDatabase;

    private function beat(array $tenant, $user, array $body)
    {
        return $this->signedInAs($user)->postJson('/api/v1/monitoring/heartbeat', $body);
    }

    public function test_first_beat_creates_one_device_and_raises_new_device_seen(): void
    {
        $alpha = $this->provisionTenant('Alpha');
        $this->beat($alpha, $alpha['admin'], [
            'device_key' => 'dk-1', 'platform' => 'web',
            'capabilities' => ['activity'], 'current_module' => 'jobs',
        ])->assertNoContent();

        $this->assertSame(1, MonitoredDevice::query()->count());
        $this->assertSame(1, AutomationEvent::query()
            ->where('event_key', 'monitoring.new_device_seen')->count());
    }

    public function test_second_beat_updates_in_place_and_raises_no_further_event(): void
    {
        $alpha = $this->provisionTenant('Alpha');
        $body = ['device_key' => 'dk-1', 'platform' => 'web', 'capabilities' => ['activity']];
        $this->beat($alpha, $alpha['admin'], $body);
        $this->beat($alpha, $alpha['admin'], $body + ['current_module' => 'customers']);

        $this->assertSame(1, MonitoredDevice::query()->count());
        $this->assertSame(1, AutomationEvent::query()
            ->where('event_key', 'monitoring.new_device_seen')->count());
    }

    public function test_a_segment_appends_exactly_one_activity_row(): void
    {
        $alpha = $this->provisionTenant('Alpha');
        $this->beat($alpha, $alpha['admin'], [
            'device_key' => 'dk-1', 'platform' => 'web', 'capabilities' => ['activity'],
            'current_module' => 'customers',
            'segment' => ['module_key' => 'jobs', 'dwell_seconds' => 1320],
        ]);
        $this->assertSame(1, MonitoringActivity::query()->count());
        $this->assertSame(1320, (int) MonitoringActivity::query()->value('dwell_seconds'));
    }

    public function test_specs_are_ignored_unless_the_device_reports_the_specs_capability(): void
    {
        $alpha = $this->provisionTenant('Alpha');
        $this->beat($alpha, $alpha['admin'], [
            'device_key' => 'dk-1', 'platform' => 'web', 'capabilities' => ['activity'],
            'specs' => ['ram_gb' => 16],
        ]);
        $this->assertNull(MonitoredDevice::query()->value('specs'));
    }

    public function test_a_heartbeat_does_not_slide_the_idle_window(): void
    {
        // The whole point of putting the route outside `idle`: a 60s beat must
        // not keep a walked-away session alive forever.
        $alpha = $this->provisionTenant('Alpha');
        $token = $alpha['admin']->createToken('t');
        $token->accessToken->forceFill(['expires_at' => now()->addMinutes(30)])->save();
        $before = $token->accessToken->fresh()->expires_at;

        $this->withHeaders(['Authorization' => 'Bearer '.$token->plainTextToken])
            ->postJson('/api/v1/monitoring/heartbeat', [
                'device_key' => 'dk-1', 'platform' => 'web', 'capabilities' => ['activity'],
            ])->assertNoContent();

        $this->assertEquals(
            $before->timestamp,
            $token->accessToken->fresh()->expires_at->timestamp,
            'A heartbeat slid the idle window — it must sit outside the `idle` middleware.'
        );
    }
}
```

Check the real token-expiry column/flow against `SlideIdleWindow` and
`app/Support/Auth/SessionLifetime.php`; the assertion above is the intent — adapt the
setup to how tokens carry `expires_at` in this codebase.

- [ ] **Step 2: Run, verify failure**

Run: `DB_DATABASE=ezbizi_testing_monitoring vendor/bin/phpunit tests/Feature/Monitoring/HeartbeatTest.php`
Expected: FAIL — route/controller missing.

- [ ] **Step 3: Write the controller**

```php
<?php
namespace App\Http\Controllers\Api\Monitoring;

use App\Http\Controllers\Api\Controller;
use App\Models\MonitoredDevice;
use App\Models\MonitoringActivity;
use App\Support\Automations\EventEmitter;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;

class HeartbeatController extends Controller
{
    private const PLATFORMS = ['web', 'android', 'ios', 'windows', 'macos', 'linux'];
    private const CAPABILITIES = ['activity', 'specs', 'screenshot', 'control'];

    public function __construct(
        private readonly EventEmitter $events,
        private readonly TenantContext $tenant,
    ) {}

    public function store(Request $request): Response
    {
        $data = $request->validate([
            'device_key'          => ['required', 'string', 'max:64'],
            'platform'            => ['required', Rule::in(self::PLATFORMS)],
            'capabilities'        => ['required', 'array'],
            'capabilities.*'      => [Rule::in(self::CAPABILITIES)],
            'label'               => ['nullable', 'string', 'max:120'],
            'current_module'      => ['nullable', 'string', 'max:64'],
            'specs'               => ['nullable', 'array'],
            'segment'             => ['nullable', 'array'],
            'segment.module_key'  => ['required_with:segment', 'string', 'max:64'],
            'segment.dwell_seconds' => ['required_with:segment', 'integer', 'min:0'],
        ]);

        $keepSpecs = in_array('specs', $data['capabilities'], true);

        $device = MonitoredDevice::query()->firstOrNew([
            'organization_id' => $this->tenant->idOrFail(),
            'user_id'         => $request->user()->getKey(),
            'device_key'      => $data['device_key'],
        ]);
        $isNew = ! $device->exists;

        $device->fill([
            'label'          => $data['label'] ?? $device->label,
            'platform'       => $data['platform'],
            'capabilities'   => $data['capabilities'],
            'user_agent'     => substr((string) $request->userAgent(), 0, 400),
            'ip_address'     => $request->ip(),
            'last_seen_at'   => now(),
            'current_module' => $data['current_module'] ?? $device->current_module,
            'offline_alerted_at' => null, // a beat re-arms the offline alert
        ]);
        if ($keepSpecs && isset($data['specs'])) {
            $device->specs = $data['specs'];
        }
        $device->save();

        if (isset($data['segment'])) {
            MonitoringActivity::create([
                'organization_id' => $this->tenant->idOrFail(),
                'device_id'       => $device->id,
                'user_id'         => $request->user()->getKey(),
                'module_key'      => $data['segment']['module_key'],
                'dwell_seconds'   => $data['segment']['dwell_seconds'],
                'occurred_at'     => now(),
            ]);
        }

        if ($isNew) {
            $this->events->emit('monitoring.new_device_seen', $device, [
                'platform' => $device->platform,
                'label'    => $device->label,
            ]);
        }

        return response()->noContent();
    }
}
```

- [ ] **Step 4: Add the route** — a group WITHOUT `idle`. Place it near the other
  authed groups in `routes/api.php`, after the main `v1` group.

```php
// Monitoring heartbeat — a person reports their own presence. Deliberately
// OUTSIDE `idle`: SlideIdleWindow pushes the token's expiry forward on every
// authenticated call, so a timer-driven beat would keep a walked-away session
// alive forever and silently void security.session_timeout_minutes. Presence is
// not user activity for the idle clock; genuine navigation (which hits the
// idle-bearing group) is.
Route::prefix('v1')
    ->middleware(['auth:sanctum', 'tenant'])
    ->group(function () {
        Route::post('monitoring/heartbeat',
            [\App\Http\Controllers\Api\Monitoring\HeartbeatController::class, 'store'])
            ->middleware('throttle:120,1');
    });
```

- [ ] **Step 5: Run tests, verify pass**

Run: `DB_DATABASE=ezbizi_testing_monitoring vendor/bin/phpunit tests/Feature/Monitoring/HeartbeatTest.php`
Expected: PASS. Then move the route INTO the `idle` group and watch
`test_a_heartbeat_does_not_slide_the_idle_window` go red; move it back.

- [ ] **Step 6: Commit**

```bash
git add app/Http/Controllers/Api/Monitoring/HeartbeatController.php routes/api.php \
        tests/Feature/Monitoring/HeartbeatTest.php
git commit -m "Monitoring: the heartbeat — upsert a device, log a segment, and never slide idle"
```

---

## Task 5: Devices read endpoint + policy

**Files:**
- Create: `app/Policies/MonitoredDevicePolicy.php`
- Create: `app/Http/Controllers/Api/Monitoring/DevicesController.php`
- Modify: `routes/api.php` (main `v1` authed group) + register the policy.
- Test: `tests/Feature/Monitoring/DevicesReadTest.php`

**Interfaces:**
- Consumes: `MonitoredDevice`, `TenantContext`, the §3 reach helpers used by
  Inventory/Fleet/Phone (grep `filingCompanyId`/subtree scoping in
  `app/Http/Controllers/Api/InventoryItemsController.php` or the Fleet controller and
  copy the scoping).
- Produces: `GET /api/v1/monitoring/devices` gated `monitoring.view`; each device
  includes `specs` **only** when the caller holds `monitoring.specs.view`.

- [ ] **Step 1: Write the failing tests** — the §3 leak test is load-bearing.

```php
<?php
namespace Tests\Feature\Monitoring;

use App\Models\MonitoredDevice;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

class DevicesReadTest extends TestCase
{
    use BuildsTenants, RefreshDatabase;

    public function test_no_monitoring_view_permission_is_refused_with_a_sentence(): void
    {
        $alpha = $this->provisionTenant('Alpha');
        $viewer = $this->userWithRole($alpha, 'NoAccess', []);
        $this->signedInAs($viewer)->getJson('/api/v1/monitoring/devices')
            ->assertForbidden()
            ->assertJsonStructure(['message']);
    }

    public function test_specs_are_withheld_without_specs_view_and_returned_with_it(): void
    {
        $alpha = $this->provisionTenant('Alpha');
        $this->inTenant($alpha['org'], fn () => MonitoredDevice::factory()->create([
            'organization_id' => $alpha['org']->id, 'user_id' => $alpha['admin']->getKey(),
            'capabilities' => ['activity', 'specs'], 'specs' => ['ram_gb' => 16],
        ]));

        $seer = $this->userWithRole($alpha, 'Seer', ['monitoring.view']);
        $resp = $this->signedInAs($seer)->getJson('/api/v1/monitoring/devices')->json('data.0');
        $this->assertArrayNotHasKey('specs', $resp);

        $full = $this->userWithRole($alpha, 'Full', ['monitoring.view', 'monitoring.specs.view']);
        $resp2 = $this->signedInAs($full)->getJson('/api/v1/monitoring/devices')->json('data.0');
        $this->assertSame(16, $resp2['specs']['ram_gb']);
    }

    public function test_a_company_view_does_not_list_a_sibling_companys_devices(): void
    {
        // §3: two companies under one org; a viewer scoped to company A must not
        // see a device owned by someone in company B. Copy the reach setup from
        // client/test/inventory_screen_test.dart's server-side analogue.
        $this->markTestIncomplete('Fill using the Inventory/Fleet reach fixture; assert the sibling device id is absent.');
    }
}
```

- [ ] **Step 2: Run, verify failure.**
Run: `DB_DATABASE=ezbizi_testing_monitoring vendor/bin/phpunit tests/Feature/Monitoring/DevicesReadTest.php`
Expected: FAIL.

- [ ] **Step 3: Write the policy** (pattern: any `app/Policies/*Policy.php`)

```php
<?php
namespace App\Policies;

use App\Models\MonitoredDevice;
use App\Models\User;

class MonitoredDevicePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission('monitoring.view');
    }
}
```

- [ ] **Step 4: Write the controller** — scope to reach, gate specs inline.

```php
<?php
namespace App\Http\Controllers\Api\Monitoring;

use App\Http\Controllers\Api\Controller;
use App\Models\MonitoredDevice;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DevicesController extends Controller
{
    public function __construct(private readonly TenantContext $tenant) {}

    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', MonitoredDevice::class);

        $canSeeSpecs = $request->user()->hasPermission('monitoring.specs.view');

        // Reach: restrict to devices whose user sits in the viewer's subtree.
        // Copy the exact scoping the Fleet/Inventory index uses — do NOT iterate
        // me.companies (that offers siblings; §3). Placeholder query below.
        $devices = MonitoredDevice::query()
            ->with('user:id,name,first_name,last_name')
            // ->whereIn('user_id', $reachableUserIds)   // ← from the reach helper
            ->orderByDesc('last_seen_at')
            ->get();

        return response()->json([
            'data' => $devices->map(fn (MonitoredDevice $d) => array_filter([
                'id'             => $d->id,
                'user'           => ['id' => $d->user->id, 'name' => $d->user->name],
                'device_key'     => $d->device_key,
                'label'          => $d->label,
                'platform'       => $d->platform,
                'capabilities'   => $d->capabilities,
                'user_agent'     => $d->user_agent,
                'ip_address'     => $d->ip_address,
                'last_seen_at'   => $d->last_seen_at?->toIso8601String(),
                'current_module' => $d->current_module,
                'specs'          => $canSeeSpecs ? $d->specs : null,
            ], fn ($v, $k) => $k !== 'specs' || $canSeeSpecs, ARRAY_FILTER_USE_BOTH))->all(),
        ]);
    }
}
```

- [ ] **Step 5: Add the route** (inside the MAIN `v1` group that carries `idle`,
  because an admin reading the tab IS activity) and register the policy in the
  policy map.

```php
Route::get('monitoring/devices',
    [\App\Http\Controllers\Api\Monitoring\DevicesController::class, 'index']);
```

- [ ] **Step 6: Complete the §3 leak test** using the Inventory/Fleet reach fixture,
  then run and verify all pass. Delete the reach `whereIn` and watch the sibling-leak
  test go red; restore.

Run: `DB_DATABASE=ezbizi_testing_monitoring vendor/bin/phpunit tests/Feature/Monitoring/DevicesReadTest.php`

- [ ] **Step 7: Commit**

```bash
git add app/Policies/MonitoredDevicePolicy.php \
        app/Http/Controllers/Api/Monitoring/DevicesController.php \
        routes/api.php app/Providers/AuthServiceProvider.php \
        tests/Feature/Monitoring/DevicesReadTest.php
git commit -m "Monitoring: read the roster — subtree-scoped, specs behind their own grant"
```

---

## Task 6: `device_offline` scheduled scan

**Files:**
- Create: `app/Support/Monitoring/DeviceOfflineWorker.php`
- Create: `app/Console/Commands/DispatchMonitoringDeviceOffline.php`
- Modify: `routes/console.php` — schedule it.
- Test: `tests/Feature/Monitoring/DeviceOfflineTest.php`

**Interfaces:**
- Consumes: `MonitoredDevice`, `EventEmitter`, `SettingsResolver` (threshold; default
  3× the 60s cadence = 180s if no setting).
- Produces: `DeviceOfflineWorker::sweep(): int`; command
  `ezbizi:dispatch-monitoring-device-offline`. Emits `monitoring.device_offline` once
  per offline episode (guarded by `offline_alerted_at`).

- [ ] **Step 1: Write the failing test** — fires once, not twice; re-arms on a beat.

```php
<?php
namespace Tests\Feature\Monitoring;

use App\Models\AutomationEvent;
use App\Models\MonitoredDevice;
use App\Support\Monitoring\DeviceOfflineWorker;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

class DeviceOfflineTest extends TestCase
{
    use BuildsTenants, RefreshDatabase;

    public function test_a_stale_device_raises_device_offline_exactly_once(): void
    {
        $alpha = $this->provisionTenant('Alpha');
        $this->inTenant($alpha['org'], fn () => MonitoredDevice::factory()->create([
            'organization_id' => $alpha['org']->id, 'user_id' => $alpha['admin']->getKey(),
            'last_seen_at' => now()->subMinutes(10), 'offline_alerted_at' => null,
        ]));

        app(DeviceOfflineWorker::class)->sweep();
        app(DeviceOfflineWorker::class)->sweep(); // second run must not re-fire

        $this->assertSame(1, AutomationEvent::query()
            ->where('event_key', 'monitoring.device_offline')->count());
    }

    public function test_a_fresh_beat_rearms_the_alert(): void
    {
        $alpha = $this->provisionTenant('Alpha');
        $device = $this->inTenant($alpha['org'], fn () => MonitoredDevice::factory()->create([
            'organization_id' => $alpha['org']->id, 'user_id' => $alpha['admin']->getKey(),
            'last_seen_at' => now()->subMinutes(10), 'offline_alerted_at' => null,
        ]));
        app(DeviceOfflineWorker::class)->sweep();

        $device->forceFill(['last_seen_at' => now(), 'offline_alerted_at' => null])->save();
        $device->forceFill(['last_seen_at' => now()->subMinutes(10)])->save();
        app(DeviceOfflineWorker::class)->sweep();

        $this->assertSame(2, AutomationEvent::query()
            ->where('event_key', 'monitoring.device_offline')->count());
    }
}
```

- [ ] **Step 2: Run, verify failure.**

- [ ] **Step 3: Write the worker** (an hourly command behind a scheduled
  dispatcher). Iterate per-organization through `TenantContext` so
  `emit()` has a tenant; select devices `last_seen_at < now()-threshold` AND
  `offline_alerted_at IS NULL`, emit, then set `offline_alerted_at = now()`.

```php
<?php
namespace App\Support\Monitoring;

use App\Models\MonitoredDevice;
use App\Models\Organization;
use App\Support\Automations\EventEmitter;
use App\Support\Settings\SettingsResolver;
use App\Support\Tenancy\TenantContext;

class DeviceOfflineWorker
{
    public function __construct(
        private readonly EventEmitter $events,
        private readonly TenantContext $tenant,
        private readonly SettingsResolver $settings,
    ) {}

    public function sweep(): int
    {
        $count = 0;
        foreach (Organization::query()->pluck('id') as $orgId) {
            $count += $this->tenant->run($orgId, function () use ($orgId) {
                $seconds = 180; // default 3× the 60s beat; make a tenant setting per §6
                $cutoff = now()->subSeconds($seconds);

                $stale = MonitoredDevice::query()
                    ->whereNotNull('last_seen_at')
                    ->where('last_seen_at', '<', $cutoff)
                    ->whereNull('offline_alerted_at')
                    ->get();

                foreach ($stale as $device) {
                    $this->events->emit('monitoring.device_offline', $device, [
                        'last_seen_at' => $device->last_seen_at?->toIso8601String(),
                    ]);
                    $device->forceFill(['offline_alerted_at' => now()])->save();
                }
                return $stale->count();
            });
        }
        return $count;
    }
}
```

Match `TenantContext`'s real scoping call (`run`/`for`/`inTenant`) — grep the class.
Wire the `180` to `SettingsResolver` with a `monitoring.offline_after_seconds` setting
(allowedScopes org+franchise) rather than the literal, per §6.

- [ ] **Step 4: Write the command** (pattern: `DispatchTaskOverdue.php`)

```php
<?php
namespace App\Console\Commands;

use App\Support\Monitoring\DeviceOfflineWorker;
use Illuminate\Console\Command;

final class DispatchMonitoringDeviceOffline extends Command
{
    protected $signature = 'ezbizi:dispatch-monitoring-device-offline';
    protected $description = 'Raise monitoring.device_offline for devices that stopped reporting';

    public function handle(DeviceOfflineWorker $worker): int
    {
        $this->info("Raised {$worker->sweep()} device-offline event(s).");
        return self::SUCCESS;
    }
}
```

- [ ] **Step 5: Schedule it** in `routes/console.php` (near the other dispatchers)

```php
Schedule::command('ezbizi:dispatch-monitoring-device-offline')
    ->everyFiveMinutes()->withoutOverlapping(10);
```

- [ ] **Step 6: Run tests, verify pass.** Delete the `->whereNull('offline_alerted_at')`
  guard and watch `test_a_stale_device_raises_device_offline_exactly_once` go red
  (it will fire twice); restore.

- [ ] **Step 7: Commit**

```bash
git add app/Support/Monitoring/DeviceOfflineWorker.php \
        app/Console/Commands/DispatchMonitoringDeviceOffline.php \
        routes/console.php tests/Feature/Monitoring/DeviceOfflineTest.php
git commit -m "Monitoring: a device going quiet raises one offline event, and re-arms on return"
```

---

## Task 7: Client — the heartbeat

**Files:**
- Create: `client/lib/core/monitoring_heartbeat.dart`
- Test: `client/test/monitoring_heartbeat_test.dart`

**Interfaces:**
- Consumes: `ApiClient` (`client/lib/core/api_client.dart`), the current route/module
  key, local storage for the device key.
- Produces: `MonitoringHeartbeat` — on a module change, POSTs the new
  `current_module` plus a `segment {module_key, dwell_seconds}` for the module just
  left; also beats every 60s to keep `last_seen_at` fresh. `device_key` is generated
  once and persisted; `capabilities` is `['activity']` on web.

- [ ] **Step 1: Write the failing test** — verify the segment carries the *previous*
  module and its dwell, and the device key is stable.

```dart
// Pattern: an existing core service test under client/test/. Use a fake ApiClient
// that records posted bodies. Assert:
//  - moving jobs -> customers posts current_module=customers and
//    segment={module_key: jobs, dwell_seconds: >0}
//  - device_key is identical across two beats
```

Write it concretely against the codebase's existing fake-ApiClient helper (grep
`class Fake` / `ApiClient` in `client/test/`).

- [ ] **Step 2: Run, verify failure.** `cd client && flutter test test/monitoring_heartbeat_test.dart`

- [ ] **Step 3: Implement `MonitoringHeartbeat`** — a small class holding the current
  module + a stopwatch; `enter(moduleKey)` closes the previous segment and posts;
  a 60s `Timer.periodic` posts a keep-alive beat with no segment. Read the device key
  from local storage, generating a v4 uuid on first run. No colour, no UI.

- [ ] **Step 4: Wire it** where route changes are observed (grep how the shell learns
  the active module — likely `main.dart`/the nav). Call `heartbeat.enter(key)` there.

- [ ] **Step 5: Run tests, verify pass.** Then break the segment to post the *current*
  module instead of the previous one and watch the test go red; restore.

- [ ] **Step 6: Commit**

```bash
git add client/lib/core/monitoring_heartbeat.dart client/test/monitoring_heartbeat_test.dart
git commit -m "Monitoring: the client beats — segment on tab change, keep-alive every 60s"
```

---

## Task 8: Client — the PC Specs tab (roster + glance panel)

**Files:**
- Create: `client/lib/screens/monitoring/monitoring_screen.dart`
- Create: `client/lib/screens/monitoring/pc_specs_tab.dart`
- Modify: `client/lib/core/module_screens.dart` — register `monitoring`.
- Test: `client/test/monitoring_screen_test.dart`

**Interfaces:**
- Consumes: `ApiClient` → `GET /monitoring/devices`; `theme.dart` colours.
- Produces: a `Monitoring` tab with a `PC Specs` sub-tab (plus disabled
  `Camera Monitoring` / `Desktop Monitoring` placeholders), rendering the roster
  (people → devices, capability, web-only note) and the glance panel (online count,
  agent devices, disk-low placeholder). Layout: `docs/previews/monitoring-layouts.html`
  (the "✓ Chosen" frame). Panel stacks below the roster below 720px. No modal.

- [ ] **Step 1: Write the failing widget test** — assert two literals the UI cannot
  renegotiate: a device row renders, and a web-only device shows the exact no-specs
  sentence.

```dart
// Pattern: client/test/inventory_screen_test.dart. Pump PcSpecsTab with a fake
// ApiClient returning one web device (capabilities ['activity'], no specs) and one
// agent device. Assert:
//  - find.text('Marco Reyes') (a rendered person)  — or the fixture's name
//  - find.textContaining('no specs') for the web device row  ← the literal rule
//  - the glance panel shows an online count
// Also add the §3 shape check the module copies: a company view with a sibling in
// /me does NOT render the sibling's device (mirror inventory_screen_test.dart's
// "does not offer sibling companies" test).
```

- [ ] **Step 2: Run, verify failure.** `cd client && flutter test test/monitoring_screen_test.dart`

- [ ] **Step 3: Build `pc_specs_tab.dart`** — a `FutureBuilder`/loader over the
  devices call; a `LayoutBuilder` splitting into roster + 320px panel above 720px and
  a single column below; each person an `ExpansionTile`-style inline expander (NOT a
  dialog); a web/mobile device row shows the no-specs sentence; specs rows show
  RAM/disk/wifi/apps when present. Colours from `Theme.of(context)` only.

- [ ] **Step 4: Build `monitoring_screen.dart`** — the sub-tab strip (PC Specs active;
  the other two disabled with a "soon" affordance) hosting `PcSpecsTab`.

- [ ] **Step 5: Register the screen** in `module_screens.dart`:

```dart
'monitoring': ModuleScreen(
  Icons.desktop_windows_outlined,
  (context) => const MonitoringScreen(),
),
```

(Import `monitoring/monitoring_screen.dart`; match the map's existing entry style.)

- [ ] **Step 6: Run tests + analyze, verify pass.** `cd client && flutter analyze && flutter test test/monitoring_screen_test.dart`
  Then delete the no-specs sentence widget and watch the literal assertion go red;
  restore.

- [ ] **Step 7: Commit**

```bash
git add client/lib/screens/monitoring/monitoring_screen.dart \
        client/lib/screens/monitoring/pc_specs_tab.dart \
        client/lib/core/module_screens.dart \
        client/test/monitoring_screen_test.dart
git commit -m "Monitoring: the PC Specs tab — roster, glance panel, and an honest web-only row"
```

---

## Hand-off

After Task 8, run the full backend module suite on MySQL and the client suite:

```bash
DB_DATABASE=ezbizi_testing_monitoring vendor/bin/phpunit tests/Feature/Monitoring
cd client && flutter analyze && flutter test
```

Then send `ezbizi-a1` the branch name and the task SHAs, flagging: **migrations**
(`monitored_devices`, `monitoring_activity`), **shared-file edit**
(`RetentionPolicy.php` docblock), **wire-shape** (new `monitoring` module in
`ModuleServiceProvider` — registry write, review-safe per the null-match proof),
**permissions** (`monitoring.view`, `monitoring.specs.view` — add-only), and the
**new scheduled command**. No min-client-version bump; no changed response shapes.

---

## Self-Review

**Spec coverage:**
- Device identity + capability report → Task 1 (`capabilities` column), Task 4
  (client declares it), Task 8 (rendered). ✓
- One tenant-scoped ingest → Task 4 (`TenantContext`, outbox emit). ✓
- Retention (specs forever, activity 90d/3mo, screenshots deferred) → Tasks 1–2. ✓
- Module registration, §4 gates, triggers → Tasks 3, 5. ✓
- §3 subtree / no sibling leak → Task 5 + Task 8 tests. ✓
- Automations `new_device_seen` + `device_offline` → Tasks 4, 6. `disk_low` +
  screenshots explicitly deferred to SP2 (spec §"Sub-project 2"). ✓
- Chosen layout (roster + glance panel, 375px, no modal) → Task 8. ✓
- Disclosure silent + T&C → no code (owner's call); §9 data-subject rights covered by
  `HoldsPersonalData` in Tasks 1–2. ✓
- Migration constraints (retention per table, no CREATE TRIGGER) → Global Constraints
  + Task 6 uses a scheduled scan, not a trigger. ✓

**Placeholder scan:** Task 5 and Task 7/8 leave the reach `whereIn`, the fake-ApiClient
wiring, and the §3 leak fixture to be filled from the named worked examples
(`inventory_screen_test.dart`, the Fleet/Inventory index) rather than reproduced here,
because copying the wrong reach query is worse than pointing at the right one — these
are marked with the exact file to copy, not left vague.

**Type consistency:** `device_key`, `capabilities`, `specs`, `offline_alerted_at`,
`current_module`, `occurred_at`, `dwell_seconds`, `monitoring.new_device_seen`,
`monitoring.device_offline`, `monitoring.view`, `monitoring.specs.view` are spelled
identically across every task. `DeviceOfflineWorker::sweep()` is the name used in both
the command (Task 6 step 4) and its tests (step 1).
