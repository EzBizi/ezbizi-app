# Work schedule, shift-end reminder and lock screen — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.
>
> **Do not deploy, add a `production` remote, or SSH to ship.** The deploy desk cuts releases; hand it a SHA (docs/SESSIONS.md).
>
> **Stay off** Email, Phone, Inventory, Fleet, Inspection, Marketing, `GlanceRegistry`, `moduleScreens`.

**Goal:** A per-person weekday schedule, a countdown reminder before the scheduled end with Extend or Clock out, a server-side per-minute close of shifts past their end, and a lock screen gated by an org switch, a role action and a per-person exemption.

**Architecture:** Server decides, client shows. `TimeClock::clockIn` stamps `scheduled_end_at` from the person's schedule in its snapshotted timezone; `extend` moves it; `ezbizi:close-ended-shifts` closes past-due shifts through the same `clockOut`. `/me` carries `lock`, `scheduled_end_at`, the two org minutes and today's window; the client arms one timer, shows one dialog, and swaps the shell for a lock route when `lock` is true. Desktop adds `window_manager` behind the existing io/web split.

**Tech Stack:** Laravel 13 · PHP 8.4 · MySQL 8 (test DB name contains `testing`) · Flutter 3.41 / Dart ^3.11 · `window_manager` · ModuleRegistry · SettingRegistry · SettingsResolver · ViewScope · EventEmitter · AuditLogger · TenantContext

**Spec:** `docs/superpowers/specs/2026-09-08-work-schedule-and-lock-screen-design.md`

## Global Constraints

- Front door `StartUp.md`; `docs/EZBIZI-STATUS.md` wins disagreements; this spec wins on Clock in.
- TDD: failing test first, watch it fail, then the code, then green. One runnable check per non-trivial branch.
- Backend tests: `php artisan test --filter=<Class>` against the `ezbizi_testing` database. Client tests from `client/`: `flutter test test/<file>.dart`.
- **Gate every client change with `flutter build web`** — analyze/test/build-windows are blind to a `dart:io` import.
- Never edit a deployed migration; every schema change is a new file.
- The client never sends a time. Server `now()`, stored UTC. Device timezone is never read.
- User-facing sentences name what happened and what to do. No class names, no `::`.
- The reminder is a **barrier dialog** (Jose's override of the no-modal rule, 2026-09-08). The lock is a **route**.
- `window_manager` is called only from `*_io.dart` behind `Platform.isWindows || Platform.isMacOS`. Android and iOS never enter the lock route.
- Role action key is `time_clock.lock_screen` (roles label derives from the key: "Lock screen"). Spec §4 said `lock`; Task 1 amends the spec.
- Response shapes are additive only. Existing keys keep their meaning.
- Do not touch `client/VERSION` or `version_history.dart`: the deploy desk places both. Send it bullets with the SHA.
- Shared working tree: stage only your paths; report counts with paths.
- Tick the board track `work-schedule-lock` (`H:\Development\integrations-board`, patch script convention) as steps land.

---

## File structure

**Backend, new**
- `app/Support/TimeClock/WorkSchedule.php` — validate the JSON, resolve today's window in the stored zone.
- `app/Console/Commands/CloseEndedShifts.php` — the per-minute close.
- `database/migrations/2026_09_08_100000_add_work_schedule_to_users.php`
- `database/migrations/2026_09_08_100100_add_schedule_stamps_to_time_clock_shifts.php`
- `database/migrations/2026_09_08_100200_add_work_schedule_ui_strings.php`
- `tests/Feature/TimeClock/WorkScheduleTest.php`, `TimeClockScheduleStampsTest.php`, `TimeClockExtendTest.php`, `CloseEndedShiftsTest.php`, `TimeClockLockRuleTest.php`

**Backend, modified**
- `app/Providers/ModuleServiceProvider.php` — `lock_screen` action on `time_clock`.
- `app/Support/Settings/SettingRegistry.php` — four settings in `hr()`.
- `app/Models/User.php`, `app/Models/TimeClockShift.php` — columns, casts, privacy entries.
- `app/Support/TimeClock/TimeClock.php` — stamps, `extend`, `endedBy`, `lock`, `today`.
- `app/Http/Controllers/Api/TimeClockController.php` — `extend`, `me`.
- `app/Http/Controllers/Api/HrPeopleController.php` — `updateSchedule`, `lock_screen_exempt`, presenter.
- `app/Http/Controllers/Api/ProfileController.php` — `updateSchedule`, presenter.
- `routes/api.php`, `routes/console.php`.

**Client, new**
- `client/lib/core/clock_window.dart` + `clock_window_io.dart` + `clock_window_web.dart` — the desktop window facade.
- `client/lib/widgets/shift_reminder.dart` — controller + dialog.
- `client/lib/widgets/work_schedule_panel.dart` — the weekday editor panel, shared by Records and Your account.
- `client/lib/screens/lock_screen.dart`.
- `client/test/shift_reminder_test.dart`, `client/test/lock_screen_test.dart`, `client/test/work_schedule_panel_test.dart`.

**Client, modified**
- `client/pubspec.yaml`, `client/lib/main.dart`, `client/lib/core/session.dart`, `client/lib/core/ui_strings.dart`, `client/lib/screens/hr/records_tab.dart`, `client/lib/screens/hr/time_clock_tab.dart`, `client/lib/screens/profile_screen.dart`, `client/test/hr_time_clock_tab_test.dart`.

---

### Task 1: Settings, the role action, and the spec amendment

**Files:**
- Modify: `app/Support/Settings/SettingRegistry.php:826-838` (the `hr()` group)
- Modify: `app/Providers/ModuleServiceProvider.php:617-621`
- Modify: `tests/Feature/TimeClock/TimeClockModuleTest.php`
- Modify: `docs/superpowers/specs/2026-09-08-work-schedule-and-lock-screen-design.md` §4

**Interfaces:**
- Produces setting keys `time_clock.lock_screen` (bool), `time_clock.warning_minutes` (enum int), `time_clock.extension_minutes` (enum int), `time_clock.self_schedule` (bool); permission key `time_clock.lock_screen`.

- [ ] **Step 1: Write the failing tests**

Replace `test_the_module_is_grantable_and_not_a_sidebar_tab` and add a settings test in `tests/Feature/TimeClock/TimeClockModuleTest.php`:

```php
    public function test_the_module_is_grantable_and_not_a_sidebar_tab(): void
    {
        $module = app(ModuleRegistry::class)->get('time_clock');

        $this->assertNotNull($module);
        $this->assertSame('Clock in', $module->label);
        $this->assertSame(['view', 'lock_screen'], $module->actions);
        $this->assertSame(['time_clock.view', 'time_clock.lock_screen'], $module->permissionKeys());
        $this->assertFalse($module->sidebar);
        $this->assertSame(ModuleDefinition::ROLES_TAB, $module->rolesGroup);
    }

    public function test_lock_screen_does_not_imply_human_resources(): void
    {
        $alpha = $this->provisionTenant('Alpha');
        $locked = $this->userWithRole($alpha, 'Locked', ['time_clock.lock_screen']);

        $this->inTenant($alpha['org'], function () use ($locked) {
            $this->assertTrue($locked->hasPermission('time_clock.lock_screen'));
            $this->assertTrue($locked->hasPermission('time_clock.view'), 'any action implies view');
            $this->assertFalse($locked->hasPermission('hr.view'));
        });
    }

    public function test_the_schedule_settings_are_organization_only_with_these_defaults(): void
    {
        $registry = app(SettingRegistry::class);

        foreach ([
            'time_clock.lock_screen'       => [SettingDefinition::TYPE_BOOL, false],
            'time_clock.warning_minutes'   => [SettingDefinition::TYPE_ENUM, 5],
            'time_clock.extension_minutes' => [SettingDefinition::TYPE_ENUM, 30],
            'time_clock.self_schedule'     => [SettingDefinition::TYPE_BOOL, false],
        ] as $key => [$type, $default]) {
            $definition = $registry->getOrFail($key);
            $this->assertSame($type, $definition->type, $key);
            $this->assertSame($default, $definition->default, $key);
            $this->assertSame([SettingDefinition::SCOPE_ORGANIZATION], $definition->allowedScopes, $key);
            $this->assertSame('hr', $registry->groupOf($key)['key'], $key);
        }

        $this->assertContains(1, $registry->getOrFail('time_clock.warning_minutes')->options);
        $this->assertContains(120, $registry->getOrFail('time_clock.extension_minutes')->options);
    }
```

- [ ] **Step 2: Run to verify they fail**

Run: `php artisan test --filter=TimeClockModuleTest`
Expected: FAIL — `['view']` is not `['view', 'lock_screen']`; `getOrFail('time_clock.lock_screen')` throws.

- [ ] **Step 3: Register the action and the settings**

In `app/Providers/ModuleServiceProvider.php`, the `time_clock` module:

```php
            new ModuleDefinition(
                key: 'time_clock',
                label: 'Clock in',
                // `lock_screen`, not `lock`: the roles label is derived from
                // the key (PermissionCatalogue::actionLabel), so this reads
                // "Lock screen" beside the checkbox with no override. Any
                // action implies `view`, which is right — a locked person
                // must be able to punch.
                actions: ['view', 'lock_screen'],
                sidebar: false,
```

In `app/Support/Settings/SettingRegistry.php`, extend `hr()`:

```php
    private function hr(): array
    {
        $orgOnly = [SettingDefinition::SCOPE_ORGANIZATION];

        return [
            new SettingDefinition(
                key: 'time_clock.enabled',
                label: 'Clock in',
                type: SettingDefinition::TYPE_BOOL,
                default: false,
                allowedScopes: $orgOnly,
                help: 'When on, people whose role includes Clock in see a button in the top bar. Human Resources shows the day list.',
            ),
            new SettingDefinition(
                key: 'time_clock.lock_screen',
                label: 'Lock screen',
                type: SettingDefinition::TYPE_BOOL,
                default: false,
                allowedScopes: $orgOnly,
                help: 'When on, people whose role includes Lock screen see only a Clock In screen '
                    .'while they are not clocked in on a working day. Mark somebody exempt on '
                    .'Human Resources › Records.',
            ),
            // Enums rather than free ints: TYPE_INT has no bounds, and a
            // 0-minute warning or a 9-hour extension is a support ticket.
            new SettingDefinition(
                key: 'time_clock.warning_minutes',
                label: 'Minutes of warning before a shift ends',
                type: SettingDefinition::TYPE_ENUM,
                default: 5,
                options: [1, 2, 3, 5, 10, 15],
                allowedScopes: $orgOnly,
                help: 'How long before the scheduled end the reminder appears.',
            ),
            new SettingDefinition(
                key: 'time_clock.extension_minutes',
                label: 'Extension length, minutes',
                type: SettingDefinition::TYPE_ENUM,
                default: 30,
                options: [15, 30, 45, 60, 90, 120],
                allowedScopes: $orgOnly,
                help: 'How much each Extend adds to the scheduled end. There is no limit on how many times.',
            ),
            new SettingDefinition(
                key: 'time_clock.self_schedule',
                label: 'People can edit their own schedule',
                type: SettingDefinition::TYPE_BOOL,
                default: false,
                allowedScopes: $orgOnly,
                help: 'When on, Your account offers Edit on the work schedule. Human Resources can always edit it.',
            ),
        ];
    }
```

- [ ] **Step 4: Run to verify they pass**

Run: `php artisan test --filter=TimeClockModuleTest`
Expected: PASS (6 tests).

- [ ] **Step 5: Amend the spec and check the permission sync**

In the spec §4 replace every `time_clock.lock` with `time_clock.lock_screen` and add one sentence: "The key is `lock_screen` so the roles label reads "Lock screen" without a label override." Then confirm nothing else enumerates the action list:

Run: `grep -rn "time_clock.view'\]" tests app | grep -v TimeClockModuleTest`
Expected: no output. If a test elsewhere asserts the full permission count (e.g. "47 total"), update that number and say so in the commit.

- [ ] **Step 6: Commit**

```bash
git add app/Providers/ModuleServiceProvider.php app/Support/Settings/SettingRegistry.php tests/Feature/TimeClock/TimeClockModuleTest.php docs/superpowers/specs/2026-09-08-work-schedule-and-lock-screen-design.md
git commit -m "Clock in: Lock screen action and the four schedule settings"
```

---

### Task 2: Columns, casts, privacy inventory, copy

**Files:**
- Create: `database/migrations/2026_09_08_100000_add_work_schedule_to_users.php`
- Create: `database/migrations/2026_09_08_100100_add_schedule_stamps_to_time_clock_shifts.php`
- Create: `database/migrations/2026_09_08_100200_add_work_schedule_ui_strings.php`
- Modify: `app/Models/User.php:72-127` (inventory), `:159-186` (casts)
- Modify: `app/Models/TimeClockShift.php`
- Modify: `tests/Feature/TimeClock/TimeClockUiStringsTest.php`, `tests/Feature/TimeClock/TimeClockShiftTest.php`

**Interfaces:**
- Produces `User::$work_schedule` (array|null, cast), `User::$lock_screen_exempt` (bool); `TimeClockShift::$scheduled_end_at` (Carbon|null), `$extensions` (int), `$outside_schedule` (bool), `$ended_by` (string|null) with constants `ENDED_BY_PERSON = 'person'`, `ENDED_BY_SCHEDULE = 'schedule'`, `ENDED_BY_AUTOMATION = 'automation'`.

- [ ] **Step 1: Write the failing tests**

Append to `tests/Feature/TimeClock/TimeClockShiftTest.php`:

```php
    public function test_the_schedule_stamps_exist_with_their_defaults(): void
    {
        $alpha = $this->provisionTenant('Alpha');
        $person = $this->userWithRole($alpha, 'Tech', ['time_clock.view']);

        $shift = $this->inTenant($alpha['org'], fn () => TimeClockShift::query()->create([
            'organization_id' => $alpha['org']->id,
            'user_id' => $person->id,
            'clocked_in_at' => '2026-09-08 12:00:00',
        ])->fresh());

        $this->assertNull($shift->scheduled_end_at);
        $this->assertSame(0, $shift->extensions);
        $this->assertFalse($shift->outside_schedule);
        $this->assertNull($shift->ended_by);

        $person->forceFill(['work_schedule' => ['timezone' => 'Etc/GMT+5', 'days' => []], 'lock_screen_exempt' => true])->save();
        $person = $person->fresh();
        $this->assertSame('Etc/GMT+5', $person->work_schedule['timezone']);
        $this->assertTrue($person->lock_screen_exempt);
    }

    public function test_the_two_user_columns_are_on_the_personal_data_inventory(): void
    {
        $columns = array_map(fn ($entry) => $entry->columns, User::personalDataHeld());

        $this->assertContains('work_schedule', $columns);
        $this->assertContains('lock_screen_exempt', $columns);
    }
```

Check the property name on `PersonalDataEntry` first: `grep -n 'public readonly' app/Support/Privacy/PersonalDataEntry.php`. Use whatever the first string property is called (`columns` above is a guess to verify, not a fact).

Replace the body of `TimeClockUiStringsTest::test_clock_in_copy_ships_in_english_and_spanish` with the same assertions plus:

```php
        foreach ([
            'time_clock.reminder_title', 'time_clock.reminder_body', 'time_clock.extend',
            'time_clock.lock_sentence', 'time_clock.sign_out', 'time_clock.auto',
            'time_clock.outside', 'time_clock.day_off', 'time_clock.work_schedule',
            'time_clock.not_scheduled', 'time_clock.overnight', 'time_clock.exempt_on',
            'time_clock.exempt_off',
        ] as $key) {
            $this->assertSame(2, UiString::query()->where('key', $key)->count(), $key);
        }
        $this->assertSame('Extend {minutes} min', UiString::query()->where('key', 'time_clock.extend')->where('locale', 'en')->value('text'));
```

- [ ] **Step 2: Run to verify they fail**

Run: `php artisan test --filter='TimeClockShiftTest|TimeClockUiStringsTest'`
Expected: FAIL — unknown columns; string count 0.

- [ ] **Step 3: Write the three migrations**

`database/migrations/2026_09_08_100000_add_work_schedule_to_users.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A person's work schedule and their lock-screen exemption (design 2026-09-08 §5.1).
 *
 * JSON, not a table: one scope (this person), no cascade, no query that asks
 * "who set Monday". The shape is validated in code (WorkSchedule::validate).
 * HR data rather than a preference — somebody else sets it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->json('work_schedule')->nullable()->after('preferences');
            $table->boolean('lock_screen_exempt')->default(false)->after('work_schedule');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['work_schedule', 'lock_screen_exempt']);
        });
    }
};
```

`database/migrations/2026_09_08_100100_add_schedule_stamps_to_time_clock_shifts.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What the schedule said about this shift (design 2026-09-08 §5.2).
 *
 * `scheduled_end_at` is the effective end after extensions and is what the
 * per-minute close reads. `ended_by` says who closed it: a person, the
 * schedule, or an automation. Additive; `open_slot` is untouched.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('time_clock_shifts', function (Blueprint $table) {
            $table->dateTime('scheduled_end_at')->nullable()->after('clocked_out_at');
            $table->unsignedTinyInteger('extensions')->default(0)->after('scheduled_end_at');
            $table->boolean('outside_schedule')->default(false)->after('extensions');
            $table->string('ended_by', 16)->nullable()->after('outside_schedule');
            $table->index(['scheduled_end_at', 'clocked_out_at'], 'time_clock_shifts_due_index');
        });
    }

    public function down(): void
    {
        Schema::table('time_clock_shifts', function (Blueprint $table) {
            $table->dropIndex('time_clock_shifts_due_index');
            $table->dropColumn(['scheduled_end_at', 'extensions', 'outside_schedule', 'ended_by']);
        });
    }
};
```

`database/migrations/2026_09_08_100200_add_work_schedule_ui_strings.php` — same shape as `2026_08_25_120100_add_time_clock_ui_strings.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/** Schedule, reminder and lock screen copy (design 2026-09-08 §11). */
return new class extends Migration
{
    private const KEYS = [
        'time_clock.reminder_title', 'time_clock.reminder_body', 'time_clock.extend',
        'time_clock.lock_sentence', 'time_clock.sign_out', 'time_clock.auto',
        'time_clock.outside', 'time_clock.day_off', 'time_clock.work_schedule',
        'time_clock.not_scheduled', 'time_clock.overnight', 'time_clock.exempt_on',
        'time_clock.exempt_off',
    ];

    public function up(): void
    {
        $now = now();

        $en = [
            'time_clock.reminder_title' => 'Your shift is ending',
            'time_clock.reminder_body' => 'Your shift ends at {time}. Clock out, or extend it.',
            'time_clock.extend' => 'Extend {minutes} min',
            'time_clock.lock_sentence' => 'Clock in to start your day.',
            'time_clock.sign_out' => 'Sign out',
            'time_clock.auto' => 'Auto',
            'time_clock.outside' => 'Outside schedule',
            'time_clock.day_off' => 'Day off',
            'time_clock.work_schedule' => 'Work schedule',
            'time_clock.not_scheduled' => 'You are not on a scheduled shift.',
            'time_clock.overnight' => 'A shift that crosses midnight is not supported yet.',
            'time_clock.exempt_on' => 'Exempt from lock screen',
            'time_clock.exempt_off' => 'Lock screen applies',
        ];

        $es = [
            'time_clock.reminder_title' => 'Su turno está por terminar',
            'time_clock.reminder_body' => 'Su turno termina a las {time}. Registre la salida o extiéndalo.',
            'time_clock.extend' => 'Extender {minutes} min',
            'time_clock.lock_sentence' => 'Registre su entrada para empezar el día.',
            'time_clock.sign_out' => 'Cerrar sesión',
            'time_clock.auto' => 'Automático',
            'time_clock.outside' => 'Fuera de horario',
            'time_clock.day_off' => 'Día libre',
            'time_clock.work_schedule' => 'Horario de trabajo',
            'time_clock.not_scheduled' => 'No está en un turno programado.',
            'time_clock.overnight' => 'Un turno que cruza la medianoche todavía no está soportado.',
            'time_clock.exempt_on' => 'Exento de la pantalla de bloqueo',
            'time_clock.exempt_off' => 'La pantalla de bloqueo aplica',
        ];

        $rows = [];
        foreach (['en' => $en, 'es' => $es] as $locale => $strings) {
            foreach ($strings as $key => $text) {
                $rows[] = ['locale' => $locale, 'key' => $key, 'text' => $text, 'created_at' => $now, 'updated_at' => $now];
            }
        }

        DB::table('ui_strings')->insert($rows);
    }

    public function down(): void
    {
        DB::table('ui_strings')->whereIn('key', self::KEYS)->delete();
    }
};
```

- [ ] **Step 4: Casts, constants and privacy entries**

`app/Models/User.php` casts, after `'preferences' => 'array',`:

```php
            // HR data, not a preference: Records sets it. Shape in WorkSchedule.
            'work_schedule'       => 'array',
            'lock_screen_exempt'  => 'boolean',
```

`app/Models/User.php` inventory, after the `totp_secret` entry:

```php
            new PersonalDataEntry('work_schedule', PersonalDataEntry::ACTIVITY,
                'Which weekdays and hours they are expected to work, in the zone of the '
                .'place that set it. Read by the shift-end reminder and the lock screen.',
                Erasure::erase()),
            new PersonalDataEntry('lock_screen_exempt', PersonalDataEntry::TECHNICAL,
                'Whether the lock screen is switched off for this person (a remote worker).',
                Erasure::erase()),
```

If `Erasure::erase()` with no argument does not null a boolean cleanly, use `Erasure::erase(false)` — check `app/Support/Privacy/Erasure.php` for what a scalar argument does before choosing.

`app/Models/TimeClockShift.php`:

```php
    public const ENDED_BY_PERSON = 'person';
    public const ENDED_BY_SCHEDULE = 'schedule';
    public const ENDED_BY_AUTOMATION = 'automation';

    protected $fillable = [
        'organization_id', 'user_id', 'clocked_in_at', 'clocked_out_at',
        'scheduled_end_at', 'extensions', 'outside_schedule', 'ended_by',
    ];

    protected function casts(): array
    {
        return [
            'clocked_in_at' => 'datetime',
            'clocked_out_at' => 'datetime',
            'scheduled_end_at' => 'datetime',
            'extensions' => 'integer',
            'outside_schedule' => 'boolean',
        ];
    }
```

Add to `personalDataHeld()` after the instants entry:

```php
            new PersonalDataEntry('scheduled_end_at, extensions, outside_schedule, ended_by',
                PersonalDataEntry::ACTIVITY,
                'What the schedule said about this shift and who closed it.',
                Erasure::keep('Facts about the punch, not about the person; they go with the row.')),
```

- [ ] **Step 5: Migrate the test database and run**

Run: `php artisan test --filter='TimeClockShiftTest|TimeClockUiStringsTest|PrivacyInventory'`
Expected: PASS. If a privacy-inventory test (`grep -rl personalDataHeld tests`) asserts an exact column list for `users` or `time_clock_shifts`, extend that list.

- [ ] **Step 6: Commit**

```bash
git add database/migrations/2026_09_08_100000_add_work_schedule_to_users.php database/migrations/2026_09_08_100100_add_schedule_stamps_to_time_clock_shifts.php database/migrations/2026_09_08_100200_add_work_schedule_ui_strings.php app/Models/User.php app/Models/TimeClockShift.php tests/Feature/TimeClock/TimeClockShiftTest.php tests/Feature/TimeClock/TimeClockUiStringsTest.php
git commit -m "Clock in: schedule columns, shift stamps and copy"
```

---

### Task 3: WorkSchedule support class and the three schedule endpoints

**Files:**
- Create: `app/Support/TimeClock/WorkSchedule.php`
- Create: `tests/Feature/TimeClock/WorkScheduleTest.php`
- Modify: `app/Http/Controllers/Api/HrPeopleController.php` (`update`, `present`, new `updateSchedule`)
- Modify: `app/Http/Controllers/Api/ProfileController.php` (`show`, new `updateSchedule`)
- Modify: `routes/api.php:806` and `:1485`

**Interfaces:**
- Produces `WorkSchedule::validate(array $days): array` (throws `ValidationException`), `WorkSchedule::windowFor(?array $schedule, Carbon $at): ?array` returning `['start_at' => Carbon UTC, 'end_at' => Carbon UTC, 'start' => 'HH:MM', 'end' => 'HH:MM']` for the calendar day of `$at` in the schedule's zone, or null.
- Produces `PATCH /api/v1/hr/people/{user}/schedule` body `{days: {...}}`; `PATCH /api/v1/hr/people/{user}` accepts `lock_screen_exempt`; `PATCH /api/v1/me/schedule` body `{days: {...}}`. People rows and `GET /me/profile` carry `work_schedule` (or null) and `lock_screen_exempt`; profile also carries `can_edit_schedule`.

- [ ] **Step 1: Write the failing tests**

`tests/Feature/TimeClock/WorkScheduleTest.php`:

```php
<?php

namespace Tests\Feature\TimeClock;

use App\Models\User;
use App\Support\Settings\SettingsResolver;
use App\Support\Tenancy\TenantContext;
use App\Support\TimeClock\WorkSchedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

class WorkScheduleTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    private const WEEK = [
        'monday' => ['start' => '08:00', 'end' => '17:00'],
        'tuesday' => ['start' => '08:00', 'end' => '17:10'],
        'wednesday' => ['start' => '08:00', 'end' => '17:15'],
        'thursday' => ['start' => '08:00', 'end' => '17:07'],
        'friday' => ['start' => '08:00', 'end' => '16:35'],
        'saturday' => null,
        'sunday' => null,
    ];

    public function test_window_for_resolves_today_in_the_schedule_zone_across_midnight(): void
    {
        $schedule = ['timezone' => 'Etc/GMT+5', 'days' => self::WEEK];

        // 03:00 UTC Tuesday 2026-09-08 is 22:00 Monday in UTC-5: Monday's window.
        $window = WorkSchedule::windowFor($schedule, Carbon::parse('2026-09-08 03:00:00', 'UTC'));

        $this->assertSame('2026-09-07 13:00:00', $window['start_at']->toDateTimeString());
        $this->assertSame('2026-09-07 22:00:00', $window['end_at']->toDateTimeString());
        $this->assertSame('08:00', $window['start']);
        $this->assertSame('17:00', $window['end']);

        // Saturday in that zone: no window.
        $this->assertNull(WorkSchedule::windowFor($schedule, Carbon::parse('2026-09-12 15:00:00', 'UTC')));
        $this->assertNull(WorkSchedule::windowFor(null, Carbon::parse('2026-09-08 15:00:00', 'UTC')));
    }

    public function test_validate_refuses_overnight_and_missing_days(): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('crosses midnight');

        WorkSchedule::validate(['monday' => ['start' => '22:00', 'end' => '06:00']] + self::WEEK);
    }

    public function test_hr_edit_saves_a_schedule_and_snapshots_the_view_zone(): void
    {
        $alpha = $this->provisionTenant('Alpha');
        $hr = $this->userWithRole($alpha, 'Hr', ['hr.view', 'hr.edit']);
        $person = $this->userWithRole($alpha, 'Tech', ['time_clock.view']);

        $this->inTenant($alpha['org'], fn () => app(SettingsResolver::class)->set('timezone', $alpha['org'], 'Etc/GMT+6'));

        $this->signedInAs($hr)
            ->patchJson("/api/v1/hr/people/{$person->id}/schedule", ['days' => self::WEEK, 'timezone' => 'Europe/Madrid'])
            ->assertOk()
            ->assertJsonPath('data.work_schedule.timezone', 'Etc/GMT+6')
            ->assertJsonPath('data.work_schedule.days.friday.end', '16:35')
            ->assertJsonPath('data.work_schedule.days.saturday', null);

        $this->assertSame('Etc/GMT+6', $person->fresh()->work_schedule['timezone']);
    }

    public function test_hr_edit_toggles_the_exemption(): void
    {
        $alpha = $this->provisionTenant('Alpha');
        $hr = $this->userWithRole($alpha, 'Hr', ['hr.view', 'hr.edit']);
        $person = $this->userWithRole($alpha, 'Tech', ['time_clock.view']);

        $this->signedInAs($hr)
            ->patchJson("/api/v1/hr/people/{$person->id}", ['lock_screen_exempt' => true])
            ->assertOk();

        $this->assertTrue($person->fresh()->lock_screen_exempt);

        $row = collect($this->signedInAs($hr)->getJson('/api/v1/hr/people')->json('data'))
            ->firstWhere('id', $person->id);
        $this->assertTrue($row['lock_screen_exempt']);
        $this->assertNull($row['work_schedule']);
    }

    public function test_self_schedule_is_refused_until_the_organization_allows_it(): void
    {
        $alpha = $this->provisionTenant('Alpha');
        $person = $this->userWithRole($alpha, 'Tech', ['time_clock.view']);

        $this->signedInAs($person)->getJson('/api/v1/me/profile')
            ->assertOk()->assertJsonPath('can_edit_schedule', false);

        $this->signedInAs($person)->patchJson('/api/v1/me/schedule', ['days' => self::WEEK])
            ->assertForbidden();

        $this->inTenant($alpha['org'], fn () => app(SettingsResolver::class)->set('time_clock.self_schedule', $alpha['org'], true));

        $this->signedInAs($person)->patchJson('/api/v1/me/schedule', ['days' => self::WEEK])
            ->assertOk()->assertJsonPath('work_schedule.days.monday.start', '08:00');
        $this->signedInAs($person)->getJson('/api/v1/me/profile')
            ->assertOk()->assertJsonPath('can_edit_schedule', true);
    }
}
```

- [ ] **Step 2: Run to verify they fail**

Run: `php artisan test --filter=WorkScheduleTest`
Expected: FAIL — class `WorkSchedule` not found.

- [ ] **Step 3: Write `WorkSchedule`**

`app/Support/TimeClock/WorkSchedule.php`:

```php
<?php

namespace App\Support\TimeClock;

use App\Support\Settings\SettingDefinition;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

/**
 * The shape of `users.work_schedule` and the one question asked of it:
 * what is today's window, in the zone the schedule was written in.
 *
 * `{ timezone: 'Etc/GMT+5', days: { monday: {start, end} | null, … } }`.
 * The zone is snapshotted at save from the editor's view, so a shift can be
 * stamped and closed with no view in hand (design 2026-09-08 §3.2).
 */
final class WorkSchedule
{
    /**
     * Validate the seven days as sent. Returns them normalised, week order.
     *
     * @throws ValidationException
     */
    public static function validate(mixed $days): array
    {
        if (! is_array($days)) {
            throw ValidationException::withMessages(['days' => 'Send one entry per weekday.']);
        }

        $clean = [];

        foreach (SettingDefinition::WEEKDAYS as $day) {
            if (! array_key_exists($day, $days)) {
                throw ValidationException::withMessages(['days' => "Send an entry for {$day}, or null for a day off."]);
            }

            $window = $days[$day];

            if ($window === null) {
                $clean[$day] = null;
                continue;
            }

            $start = is_array($window) ? ($window['start'] ?? null) : null;
            $end = is_array($window) ? ($window['end'] ?? null) : null;

            foreach ([$start, $end] as $time) {
                if (! is_string($time) || ! preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $time)) {
                    throw ValidationException::withMessages(["days.{$day}" => 'Times are HH:MM on a 24-hour clock.']);
                }
            }

            if ($end <= $start) {
                throw ValidationException::withMessages(["days.{$day}" => 'A shift that crosses midnight is not supported yet.']);
            }

            $clean[$day] = ['start' => $start, 'end' => $end];
        }

        return $clean;
    }

    /**
     * Today's window for the calendar day `$at` falls on in the schedule zone.
     *
     * @return null|array{start_at: Carbon, end_at: Carbon, start: string, end: string}
     */
    public static function windowFor(?array $schedule, Carbon $at): ?array
    {
        $tz = $schedule['timezone'] ?? null;
        $days = $schedule['days'] ?? null;

        if (! is_string($tz) || ! is_array($days)) {
            return null;
        }

        $local = $at->copy()->timezone($tz);
        $window = $days[strtolower($local->format('l'))] ?? null;

        if (! is_array($window) || ! isset($window['start'], $window['end'])) {
            return null;
        }

        $date = $local->toDateString();

        return [
            'start_at' => Carbon::parse("{$date} {$window['start']}", $tz)->utc(),
            'end_at' => Carbon::parse("{$date} {$window['end']}", $tz)->utc(),
            'start' => $window['start'],
            'end' => $window['end'],
        ];
    }
}
```

- [ ] **Step 4: The HR endpoints**

`app/Http/Controllers/Api/HrPeopleController.php` — in `update()` add to the validation array:

```php
            'lock_screen_exempt' => ['sometimes', 'required', 'boolean'],
```

(`$user->fill($input)` already saves it and `logChange('user.updated')` records it.)

Add the method (after `update`):

```php
    /**
     * PATCH /api/v1/hr/people/{user}/schedule
     *
     * The timezone is the CALLER's view, never the body: a schedule is
     * written by somebody standing somewhere, and that somewhere is the clock
     * it means (design 2026-09-08 §3.2).
     */
    public function updateSchedule(Request $request, User $user): JsonResponse
    {
        $this->authorize('update', $user);

        $days = WorkSchedule::validate($request->input('days'));
        $zone = (string) $this->settings->get('timezone', ViewScope::forUser($request->user())->settingsScope());

        $user->work_schedule = ['timezone' => $zone, 'days' => $days];
        $this->audit->logChange('user.updated', $user);
        $user->save();

        return response()->json(['data' => $this->present($user->fresh(['role', 'assignments']), $request->user())]);
    }
```

Add `use App\Support\Scoping\ViewScope; use App\Support\Settings\SettingsResolver; use App\Support\TimeClock\WorkSchedule;` and a `private readonly SettingsResolver $settings` constructor parameter (read the constructor at `:50` and append it). In `present()` add:

```php
            'work_schedule'      => $person->work_schedule,
            'lock_screen_exempt' => (bool) $person->lock_screen_exempt,
```

- [ ] **Step 5: The profile endpoints**

`app/Http/Controllers/Api/ProfileController.php` — `show()` becomes:

```php
    public function show(Request $request): array
    {
        $user = $request->user();

        return ['email' => $user->email]
            + $this->present($user)
            + [
                'work_schedule' => $user->work_schedule,
                'can_edit_schedule' => (bool) $this->settings->get('time_clock.self_schedule', $user->organization),
            ];
    }

    /** PATCH /api/v1/me/schedule — only when the organization allows it. */
    public function updateSchedule(Request $request): array
    {
        $user = $request->user();

        if (! $this->settings->get('time_clock.self_schedule', $user->organization)) {
            abort(403, 'Your organization sets work schedules from Human Resources. Ask them to change yours.');
        }

        $days = WorkSchedule::validate($request->input('days'));
        $zone = (string) $this->settings->get('timezone', ViewScope::forUser($user)->settingsScope());

        $user->work_schedule = ['timezone' => $zone, 'days' => $days];
        $this->audit->logChange('user.updated', $user);
        $user->save();

        return ['work_schedule' => $user->work_schedule];
    }
```

Add the same three `use` lines and a `SettingsResolver $settings` constructor parameter if the controller does not already hold one (`grep -n SettingsResolver app/Http/Controllers/Api/ProfileController.php`).

`routes/api.php`: after line 806 (`patch hr/people/{user}`):

```php
        Route::patch('hr/people/{user}/schedule', [HrPeopleController::class, 'updateSchedule']);
```

After line 1485 (`patch me`):

```php
        Route::patch('me/schedule', [ProfileController::class, 'updateSchedule']);
```

- [ ] **Step 6: Run to verify they pass**

Run: `php artisan test --filter='WorkScheduleTest|HrPeople'`
Expected: PASS, and the existing HrPeople tests still green (the presenter grew two keys).

- [ ] **Step 7: Commit**

```bash
git add app/Support/TimeClock/WorkSchedule.php tests/Feature/TimeClock/WorkScheduleTest.php app/Http/Controllers/Api/HrPeopleController.php app/Http/Controllers/Api/ProfileController.php routes/api.php
git commit -m "Clock in: a work schedule per person, set by HR or by themselves when allowed"
```

---

### Task 4: Stamps at clock in, `endedBy` at clock out, the grown `/me` block, and `GET /time-clock/me`

**Files:**
- Modify: `app/Support/TimeClock/TimeClock.php`
- Modify: `app/Http/Controllers/Api/TimeClockController.php`
- Modify: `app/Support/Automations/ActionRunner.php:285-288` (no signature change needed; see step 4)
- Modify: `routes/api.php:788-789`
- Create: `tests/Feature/TimeClock/TimeClockScheduleStampsTest.php`, `tests/Feature/TimeClock/TimeClockLockRuleTest.php`

**Interfaces:**
- `TimeClock::clockOut(User $user, ?AutomationEvent $causedBy = null, string $endedBy = TimeClockShift::ENDED_BY_PERSON): array`. When `$causedBy !== null` the row gets `ENDED_BY_AUTOMATION` regardless of `$endedBy`.
- `TimeClock::payload(User $user): array` gains `lock` (bool), `scheduled_end_at` (ISO Z|null), `warning_minutes` (int), `extension_minutes` (int), `today` (null|{start, end}).
- `GET /api/v1/time-clock/me` → `{ data: { time_clock } }`.

- [ ] **Step 1: Write the failing tests**

`tests/Feature/TimeClock/TimeClockScheduleStampsTest.php`:

```php
<?php

namespace Tests\Feature\TimeClock;

use App\Models\Organization;
use App\Models\TimeClockShift;
use App\Models\User;
use App\Support\Settings\SettingsResolver;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

class TimeClockScheduleStampsTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    private const WEEK = [
        'monday' => ['start' => '08:00', 'end' => '17:00'],
        'tuesday' => ['start' => '08:00', 'end' => '17:00'],
        'wednesday' => ['start' => '08:00', 'end' => '17:00'],
        'thursday' => ['start' => '08:00', 'end' => '17:00'],
        'friday' => ['start' => '08:00', 'end' => '17:00'],
        'saturday' => null,
        'sunday' => null,
    ];

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** @return array{0: array, 1: User} */
    private function scheduled(): array
    {
        $alpha = $this->provisionTenant('Alpha');
        $this->enableClockIn($alpha['org']);
        $person = $this->userWithRole($alpha, 'Tech', ['time_clock.view']);
        $person->forceFill(['work_schedule' => ['timezone' => 'Etc/GMT+5', 'days' => self::WEEK]])->save();

        return [$alpha, $person->fresh()];
    }

    public function test_clock_in_on_a_working_day_stamps_the_end_in_the_schedule_zone(): void
    {
        [, $person] = $this->scheduled();
        // Tuesday 2026-09-08 09:00 in UTC-5 = 14:00 UTC.
        Carbon::setTestNow(Carbon::parse('2026-09-08 14:00:00', 'UTC'));

        $block = $this->signedInAs($person)->postJson('/api/v1/time-clock/clock-in')->assertOk()->json('data.time_clock');

        $this->assertSame('2026-09-08T22:00:00Z', $block['scheduled_end_at']);
        $this->assertSame(['start' => '08:00', 'end' => '17:00'], $block['today']);
        $this->assertSame(5, $block['warning_minutes']);
        $this->assertSame(30, $block['extension_minutes']);

        $shift = TimeClockShift::openFor($person);
        $this->assertFalse($shift->outside_schedule);
        $this->assertSame('2026-09-08 22:00:00', $shift->scheduled_end_at->toDateTimeString());
    }

    public function test_thirty_one_minutes_early_is_outside_and_twenty_nine_is_not(): void
    {
        [, $person] = $this->scheduled();

        Carbon::setTestNow(Carbon::parse('2026-09-08 12:29:00', 'UTC')); // 07:29 local
        $this->signedInAs($person)->postJson('/api/v1/time-clock/clock-in')->assertOk();
        $this->assertTrue(TimeClockShift::openFor($person)->outside_schedule);
        $this->signedInAs($person)->postJson('/api/v1/time-clock/clock-out')->assertOk();

        Carbon::setTestNow(Carbon::parse('2026-09-08 12:31:00', 'UTC')); // 07:31 local
        $this->signedInAs($person)->postJson('/api/v1/time-clock/clock-in')->assertOk();
        $this->assertFalse(TimeClockShift::openFor($person)->outside_schedule);
    }

    public function test_a_day_off_is_outside_with_no_end_and_after_the_end_is_neither(): void
    {
        [, $person] = $this->scheduled();

        Carbon::setTestNow(Carbon::parse('2026-09-12 15:00:00', 'UTC')); // Saturday
        $block = $this->signedInAs($person)->postJson('/api/v1/time-clock/clock-in')->assertOk()->json('data.time_clock');
        $this->assertNull($block['scheduled_end_at']);
        $this->assertNull($block['today']);
        $this->assertTrue(TimeClockShift::openFor($person)->outside_schedule);
        $this->signedInAs($person)->postJson('/api/v1/time-clock/clock-out')->assertOk();

        Carbon::setTestNow(Carbon::parse('2026-09-08 23:30:00', 'UTC')); // Tuesday 18:30 local, after 17:00
        $block = $this->signedInAs($person)->postJson('/api/v1/time-clock/clock-in')->assertOk()->json('data.time_clock');
        $this->assertNull($block['scheduled_end_at']);
        $shift = TimeClockShift::openFor($person);
        $this->assertFalse($shift->outside_schedule);
        $this->assertNull($shift->scheduled_end_at);
    }

    public function test_a_person_closes_with_ended_by_person_and_an_automation_with_automation(): void
    {
        [$alpha, $person] = $this->scheduled();
        Carbon::setTestNow(Carbon::parse('2026-09-08 14:00:00', 'UTC'));

        $this->signedInAs($person)->postJson('/api/v1/time-clock/clock-in')->assertOk();
        $this->signedInAs($person)->postJson('/api/v1/time-clock/clock-out')->assertOk();

        $closed = TimeClockShift::query()->where('user_id', $person->id)->first();
        $this->assertSame(TimeClockShift::ENDED_BY_PERSON, $closed->ended_by);
    }

    public function test_time_clock_me_returns_the_same_block_as_me(): void
    {
        [, $person] = $this->scheduled();
        Carbon::setTestNow(Carbon::parse('2026-09-08 14:00:00', 'UTC'));

        $viaMe = $this->signedInAs($person)->getJson('/api/v1/me')->assertOk()->json('data.time_clock');
        $direct = $this->signedInAs($person)->getJson('/api/v1/time-clock/me')->assertOk()->json('data.time_clock');

        $this->assertSame($viaMe, $direct);
        $this->assertArrayHasKey('lock', $direct);
    }

    private function enableClockIn(Organization $org): void
    {
        app(TenantContext::class)->run($org, function () use ($org) {
            app(SettingsResolver::class)->set('time_clock.enabled', $org, true);
        });
    }
}
```

`tests/Feature/TimeClock/TimeClockLockRuleTest.php`:

```php
<?php

namespace Tests\Feature\TimeClock;

use App\Models\Organization;
use App\Models\User;
use App\Support\Settings\SettingsResolver;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/** The four gates of design 2026-09-08 §3.8, one at a time. */
class TimeClockLockRuleTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    private const WEEK = [
        'monday' => ['start' => '08:00', 'end' => '17:00'],
        'tuesday' => ['start' => '08:00', 'end' => '17:00'],
        'wednesday' => ['start' => '08:00', 'end' => '17:00'],
        'thursday' => ['start' => '08:00', 'end' => '17:00'],
        'friday' => ['start' => '08:00', 'end' => '17:00'],
        'saturday' => null,
        'sunday' => null,
    ];

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function lockedPerson(array $permissions = ['time_clock.lock_screen'], bool $lockSetting = true, bool $exempt = false): array
    {
        $alpha = $this->provisionTenant('Alpha');
        $this->set($alpha['org'], 'time_clock.enabled', true);
        $this->set($alpha['org'], 'time_clock.lock_screen', $lockSetting);
        $person = $this->userWithRole($alpha, 'Locked', $permissions);
        $person->forceFill([
            'work_schedule' => ['timezone' => 'Etc/GMT+5', 'days' => self::WEEK],
            'lock_screen_exempt' => $exempt,
        ])->save();
        Carbon::setTestNow(Carbon::parse('2026-09-08 14:00:00', 'UTC')); // Tuesday 09:00 local

        return [$alpha, $person->fresh()];
    }

    private function lock(User $person): bool
    {
        return $this->signedInAs($person)->getJson('/api/v1/me')->assertOk()->json('data.time_clock.lock');
    }

    public function test_all_gates_true_and_not_clocked_in_locks(): void
    {
        [, $person] = $this->lockedPerson();
        $this->assertTrue($this->lock($person));
    }

    public function test_an_open_shift_does_not_lock(): void
    {
        [, $person] = $this->lockedPerson();
        $this->signedInAs($person)->postJson('/api/v1/time-clock/clock-in')->assertOk();
        $this->assertFalse($this->lock($person));
    }

    public function test_org_lock_off_does_not_lock(): void
    {
        [, $person] = $this->lockedPerson(lockSetting: false);
        $this->assertFalse($this->lock($person));
    }

    public function test_view_without_lock_screen_does_not_lock(): void
    {
        [, $person] = $this->lockedPerson(permissions: ['time_clock.view']);
        $this->assertFalse($this->lock($person));
    }

    public function test_an_exempt_person_does_not_lock(): void
    {
        [, $person] = $this->lockedPerson(exempt: true);
        $this->assertFalse($this->lock($person));
    }

    public function test_a_day_off_does_not_lock(): void
    {
        [, $person] = $this->lockedPerson();
        Carbon::setTestNow(Carbon::parse('2026-09-12 15:00:00', 'UTC')); // Saturday
        $this->assertFalse($this->lock($person));
    }

    public function test_after_the_scheduled_end_still_locks_on_a_working_day(): void
    {
        [, $person] = $this->lockedPerson();
        Carbon::setTestNow(Carbon::parse('2026-09-08 23:30:00', 'UTC')); // Tuesday 18:30 local
        $this->assertTrue($this->lock($person));
    }

    private function set(Organization $org, string $key, mixed $value): void
    {
        app(TenantContext::class)->run($org, fn () => app(SettingsResolver::class)->set($key, $org, $value));
    }
}
```

- [ ] **Step 2: Run to verify they fail**

Run: `php artisan test --filter='TimeClockScheduleStampsTest|TimeClockLockRuleTest'`
Expected: FAIL — `scheduled_end_at` key missing; route `time-clock/me` 404; `lock` missing.

- [ ] **Step 3: Grow `TimeClock`**

In `app/Support/TimeClock/TimeClock.php` add `use App\Support\TimeClock\WorkSchedule;` (same namespace, no `use` needed) and rewrite `payload`:

```php
    /** How early a punch may be before it is "outside schedule" (design §6). */
    private const EARLY_GRACE_MINUTES = 30;

    public function payload(User $user): array
    {
        $organization = $user->organization;
        $enabled = $this->organizationEnabled($organization);
        $canClock = $user->hasPermission('time_clock.view');
        $open = TimeClockShift::openFor($user);
        $window = WorkSchedule::windowFor($user->work_schedule, now());

        // The four gates of design 2026-09-08 §3.8. A day off never locks;
        // after the scheduled end on a working day still does.
        $lock = $open === null
            && $enabled
            && (bool) $this->settings->get('time_clock.lock_screen', $organization)
            && $user->hasPermission('time_clock.lock_screen')
            && ! $user->lock_screen_exempt
            && $window !== null;

        return [
            'organization_enabled' => $enabled,
            'can_clock' => $canClock,
            'show_button' => ($enabled && $canClock) || $open !== null,
            'open' => $open === null ? null : [
                'id' => $open->id,
                'clocked_in_at' => $this->instant($open->clocked_in_at),
            ],
            'lock' => $lock,
            'scheduled_end_at' => $this->instant($open?->scheduled_end_at),
            'warning_minutes' => (int) $this->settings->get('time_clock.warning_minutes', $organization),
            'extension_minutes' => (int) $this->settings->get('time_clock.extension_minutes', $organization),
            'today' => $window === null ? null : ['start' => $window['start'], 'end' => $window['end']],
        ];
    }
```

In `clockIn`, replace the `create([...])` call:

```php
                $now = now();
                $window = WorkSchedule::windowFor($user->work_schedule, $now);

                $shift = TimeClockShift::query()->create([
                    'organization_id' => $user->organization_id,
                    'user_id' => $user->getKey(),
                    'clocked_in_at' => $now,
                    'clocked_out_at' => null,
                    // After the scheduled end there is nothing to count down
                    // to: they are working late, and the chip carries them.
                    'scheduled_end_at' => $window !== null && $now->lt($window['end_at']) ? $window['end_at'] : null,
                    'outside_schedule' => $window === null
                        || $now->lt($window['start_at']->copy()->subMinutes(self::EARLY_GRACE_MINUTES)),
                ]);
```

Change `clockOut`:

```php
    public function clockOut(
        User $user,
        ?AutomationEvent $causedBy = null,
        string $endedBy = TimeClockShift::ENDED_BY_PERSON,
    ): array {
        return DB::transaction(function () use ($user, $causedBy, $endedBy) {
            $open = TimeClockShift::query()
                ->open()
                ->where('user_id', $user->getKey())
                ->lockForUpdate()
                ->first();

            if ($open === null) {
                throw ValidationException::withMessages([
                    'time_clock' => 'You are not clocked in.',
                ]);
            }

            $open->clocked_out_at = now();
            // A rule's punch is an automation's, whatever the caller said.
            $open->ended_by = $causedBy !== null ? TimeClockShift::ENDED_BY_AUTOMATION : $endedBy;
            $open->save();
            // … audit, emit, return payload — unchanged
```

Add to `eventPayload()`:

```php
            'scheduled_end_at' => $this->instant($shift->scheduled_end_at),
            'ended_by' => $shift->ended_by,
```

- [ ] **Step 4: Controller and route**

`app/Http/Controllers/Api/TimeClockController.php` add:

```php
    /** The `/me` block on its own, for the 60-second poll (design §3.12). */
    public function me(Request $request): JsonResponse
    {
        return response()->json([
            'data' => ['time_clock' => $this->timeClock->payload($request->user())],
        ]);
    }
```

`routes/api.php` after line 789:

```php
        Route::get('time-clock/me', [TimeClockController::class, 'me']);
```

`ActionRunner::punch` needs no change: it already passes `$event` as `$causedBy`, which now stamps `automation`.

- [ ] **Step 5: Run the whole TimeClock folder**

Run: `php artisan test --filter=TimeClock`
Expected: PASS. `TimeClockAutomationsTest` still green (the event payload only grew).

- [ ] **Step 6: Commit**

```bash
git add app/Support/TimeClock/TimeClock.php app/Http/Controllers/Api/TimeClockController.php routes/api.php tests/Feature/TimeClock/TimeClockScheduleStampsTest.php tests/Feature/TimeClock/TimeClockLockRuleTest.php
git commit -m "Clock in: a shift knows when it is meant to end, and /me says whether to lock"
```

---

### Task 5: Extend

**Files:**
- Modify: `app/Support/TimeClock/TimeClock.php`, `app/Http/Controllers/Api/TimeClockController.php`, `routes/api.php`
- Create: `tests/Feature/TimeClock/TimeClockExtendTest.php`

**Interfaces:**
- `TimeClock::extend(User $user): array` (returns the block). `POST /api/v1/time-clock/extend`, empty body.

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature\TimeClock;

use App\Models\AuditEvent;
use App\Models\Organization;
use App\Models\TimeClockShift;
use App\Support\Settings\SettingsResolver;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

class TimeClockExtendTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    private const WEEK = [
        'monday' => ['start' => '08:00', 'end' => '17:00'], 'tuesday' => ['start' => '08:00', 'end' => '17:00'],
        'wednesday' => ['start' => '08:00', 'end' => '17:00'], 'thursday' => ['start' => '08:00', 'end' => '17:00'],
        'friday' => ['start' => '08:00', 'end' => '17:00'], 'saturday' => null, 'sunday' => null,
    ];

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_extend_moves_the_end_by_the_org_length_and_counts(): void
    {
        $alpha = $this->provisionTenant('Alpha');
        $this->set($alpha['org'], 'time_clock.enabled', true);
        $this->set($alpha['org'], 'time_clock.extension_minutes', 45);
        $person = $this->userWithRole($alpha, 'Tech', ['time_clock.view']);
        $person->forceFill(['work_schedule' => ['timezone' => 'Etc/GMT+5', 'days' => self::WEEK]])->save();
        Carbon::setTestNow(Carbon::parse('2026-09-08 14:00:00', 'UTC'));

        $this->signedInAs($person)->postJson('/api/v1/time-clock/clock-in')->assertOk();

        $block = $this->signedInAs($person)->postJson('/api/v1/time-clock/extend')->assertOk()->json('data.time_clock');
        $this->assertSame('2026-09-08T22:45:00Z', $block['scheduled_end_at']);

        $block = $this->signedInAs($person)->postJson('/api/v1/time-clock/extend')->assertOk()->json('data.time_clock');
        $this->assertSame('2026-09-08T23:30:00Z', $block['scheduled_end_at']);
        $this->assertSame(2, TimeClockShift::openFor($person)->extensions);

        $this->assertSame(2, AuditEvent::query()->withoutGlobalScopes()->where('action', 'time_clock.extended')->count());
    }

    public function test_extend_is_refused_without_a_scheduled_shift(): void
    {
        $alpha = $this->provisionTenant('Alpha');
        $this->set($alpha['org'], 'time_clock.enabled', true);
        $person = $this->userWithRole($alpha, 'Tech', ['time_clock.view']);

        $response = $this->signedInAs($person)->postJson('/api/v1/time-clock/extend');
        $this->assertSame(422, $response->status());
        $this->assertStringContainsString('You are not on a scheduled shift.', $response->getContent());

        // Open but unscheduled (no work_schedule at all): same sentence.
        $this->signedInAs($person)->postJson('/api/v1/time-clock/clock-in')->assertOk();
        $this->signedInAs($person)->postJson('/api/v1/time-clock/extend')->assertStatus(422);
    }

    public function test_extend_works_after_the_grant_is_taken_away(): void
    {
        $alpha = $this->provisionTenant('Alpha');
        $this->set($alpha['org'], 'time_clock.enabled', true);
        $person = $this->userWithRole($alpha, 'Tech', ['time_clock.view']);
        $person->forceFill(['work_schedule' => ['timezone' => 'Etc/GMT+5', 'days' => self::WEEK]])->save();
        Carbon::setTestNow(Carbon::parse('2026-09-08 14:00:00', 'UTC'));
        $this->signedInAs($person)->postJson('/api/v1/time-clock/clock-in')->assertOk();

        $this->inTenant($alpha['org'], fn () => $person->role->syncPermissionKeys([]));

        $this->signedInAs($person)->postJson('/api/v1/time-clock/extend')->assertOk();
    }

    private function set(Organization $org, string $key, mixed $value): void
    {
        app(TenantContext::class)->run($org, fn () => app(SettingsResolver::class)->set($key, $org, $value));
    }
}
```

- [ ] **Step 2: Run to verify it fails**

Run: `php artisan test --filter=TimeClockExtendTest`
Expected: FAIL — 404 on `time-clock/extend`.

- [ ] **Step 3: Implement**

`TimeClock.php`:

```php
    /**
     * Push the scheduled end out by the organization's extension length.
     * Needs only an open scheduled shift — allowed after the grant or the
     * org switch is gone, like clock out (design §7).
     */
    public function extend(User $user): array
    {
        return DB::transaction(function () use ($user) {
            $open = TimeClockShift::query()
                ->open()
                ->where('user_id', $user->getKey())
                ->lockForUpdate()
                ->first();

            if ($open === null || $open->scheduled_end_at === null) {
                throw ValidationException::withMessages([
                    'time_clock' => 'You are not on a scheduled shift.',
                ]);
            }

            $minutes = (int) $this->settings->get('time_clock.extension_minutes', $user->organization);

            // ponytail: adds to the stamped end, not to now(). If the close
            // ran a second earlier this is a race the row already lost.
            $open->scheduled_end_at = $open->scheduled_end_at->copy()->addMinutes($minutes);
            $open->extensions = $open->extensions + 1;
            $open->save();

            $this->audit->log('time_clock.extended', $open, metadata: ['minutes' => $minutes]);

            return $this->payload($user);
        });
    }
```

Controller:

```php
    public function extend(Request $request): JsonResponse
    {
        return response()->json([
            'data' => ['time_clock' => $this->timeClock->extend($request->user())],
        ]);
    }
```

Route, beside `clock-out`:

```php
        Route::post('time-clock/extend', [TimeClockController::class, 'extend']);
```

- [ ] **Step 4: Run to verify it passes**

Run: `php artisan test --filter=TimeClockExtendTest`
Expected: PASS (3 tests).

- [ ] **Step 5: Commit**

```bash
git add app/Support/TimeClock/TimeClock.php app/Http/Controllers/Api/TimeClockController.php routes/api.php tests/Feature/TimeClock/TimeClockExtendTest.php
git commit -m "Clock in: Extend pushes the scheduled end out by the org's length"
```

---

### Task 6: `ezbizi:close-ended-shifts`

**Files:**
- Create: `app/Console/Commands/CloseEndedShifts.php`
- Create: `tests/Feature/TimeClock/CloseEndedShiftsTest.php`
- Modify: `routes/console.php` (after the newsletter line, ~`:58`)

- [ ] **Step 1: Check the audit table accepts a system actor**

Run: `grep -n "user_id\|actor" database/migrations/*create_audit_events*.php`
Expected: `user_id` nullable. If it is not, the command passes `actor: $shift->user` and the test below asserts that instead; say which in the commit.

- [ ] **Step 2: Write the failing test**

```php
<?php

namespace Tests\Feature\TimeClock;

use App\Models\AuditEvent;
use App\Models\AutomationEvent;
use App\Models\Organization;
use App\Models\TimeClockShift;
use App\Support\Settings\SettingsResolver;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

class CloseEndedShiftsTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_only_past_due_open_shifts_are_closed_and_stamped(): void
    {
        $alpha = $this->provisionTenant('Alpha');
        $beta = $this->provisionTenant('Beta');
        $a = $this->userWithRole($alpha, 'A', ['time_clock.view']);
        $b = $this->userWithRole($beta, 'B', ['time_clock.view']);
        $c = $this->userWithRole($alpha, 'C', ['time_clock.view']);
        $d = $this->userWithRole($alpha, 'D', ['time_clock.view']);

        Carbon::setTestNow(Carbon::parse('2026-09-08 22:00:30', 'UTC'));

        $due = $this->row($alpha['org'], $a, '2026-09-08 22:00:00');          // past due, alpha
        $dueElsewhere = $this->row($beta['org'], $b, '2026-09-08 21:59:00');  // past due, beta
        $future = $this->row($alpha['org'], $c, '2026-09-08 22:01:00');       // not yet
        $unscheduled = $this->row($alpha['org'], $d, null);                    // never

        $this->artisan('ezbizi:close-ended-shifts')->assertSuccessful();

        $this->assertSame('2026-09-08 22:00:30', $due->fresh()->clocked_out_at->toDateTimeString());
        $this->assertSame(TimeClockShift::ENDED_BY_SCHEDULE, $due->fresh()->ended_by);
        $this->assertSame(TimeClockShift::ENDED_BY_SCHEDULE, $dueElsewhere->fresh()->ended_by);
        $this->assertNull($future->fresh()->clocked_out_at);
        $this->assertNull($unscheduled->fresh()->clocked_out_at);

        $this->assertSame(2, AuditEvent::query()->withoutGlobalScopes()->where('action', 'time_clock.clocked_out')->count());
        $this->assertSame(2, AutomationEvent::query()->withoutGlobalScopes()->where('key', 'time_clock.clocked_out')->count());
    }

    public function test_a_second_run_finds_nothing(): void
    {
        $alpha = $this->provisionTenant('Alpha');
        $a = $this->userWithRole($alpha, 'A', ['time_clock.view']);
        Carbon::setTestNow(Carbon::parse('2026-09-08 22:00:30', 'UTC'));
        $this->row($alpha['org'], $a, '2026-09-08 22:00:00');

        $this->artisan('ezbizi:close-ended-shifts')->assertSuccessful();
        $this->artisan('ezbizi:close-ended-shifts')->assertSuccessful();

        $this->assertSame(1, AuditEvent::query()->withoutGlobalScopes()->where('action', 'time_clock.clocked_out')->count());
    }

    private function row(Organization $org, $user, ?string $end): TimeClockShift
    {
        return $this->inTenant($org, fn () => TimeClockShift::query()->create([
            'organization_id' => $org->id,
            'user_id' => $user->id,
            'clocked_in_at' => '2026-09-08 13:00:00',
            'scheduled_end_at' => $end,
        ]));
    }
}
```

Check the `AutomationEvent` column that holds the trigger key first (`grep -n "'key'\|trigger" app/Models/AutomationEvent.php`) and use that name in the assertion.

- [ ] **Step 3: Run to verify it fails**

Run: `php artisan test --filter=CloseEndedShiftsTest`
Expected: FAIL — command not found.

- [ ] **Step 4: Write the command and schedule it**

`app/Console/Commands/CloseEndedShifts.php`:

```php
<?php

namespace App\Console\Commands;

use App\Models\TimeClockShift;
use App\Support\Tenancy\TenantContext;
use App\Support\TimeClock\TimeClock;
use Illuminate\Console\Command;
use Illuminate\Validation\ValidationException;

/**
 * Clock out anybody whose scheduled end has passed (design 2026-09-08 §8).
 *
 * A sweep, not a delayed job, for the reason the social publisher gives: the
 * row IS the state. Extend moves `scheduled_end_at` and the row simply stops
 * being due. Goes through TimeClock::clockOut so it is audited, raised and
 * listed exactly like a person's punch, with `ended_by = schedule`.
 *
 * Without tenant scope: the scheduler is nobody. Each close re-enters the
 * shift's own organization, which is where the boundary lives.
 *
 * ponytail: no chunking. Due rows are "people whose day just ended this
 * minute"; if that ever exceeds a sweep, `->chunkById()` on the same query.
 */
class CloseEndedShifts extends Command
{
    protected $signature = 'ezbizi:close-ended-shifts';

    protected $description = 'Clock out open shifts whose scheduled end has passed';

    public function __construct(
        private readonly TimeClock $timeClock,
        private readonly TenantContext $tenant,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $due = TimeClockShift::query()
            ->withoutGlobalScopes()
            ->open()
            ->whereNotNull('scheduled_end_at')
            ->where('scheduled_end_at', '<=', now())
            ->with('user.organization')
            ->get();

        $closed = 0;

        foreach ($due as $shift) {
            $user = $shift->user;

            if ($user === null || $user->organization === null) {
                continue;
            }

            try {
                $this->tenant->run($user->organization, fn () => $this->timeClock->clockOut(
                    $user,
                    endedBy: TimeClockShift::ENDED_BY_SCHEDULE,
                ));
                $closed++;
            } catch (ValidationException) {
                // Closed by the person between the query and the lock. Not a fault.
            }
        }

        if ($closed > 0) {
            $this->info("Closed {$closed} shift(s) past their scheduled end.");
        }

        return self::SUCCESS;
    }
}
```

`routes/console.php`, after the newsletter line:

```php
// Shifts past their scheduled end (design 2026-09-08 §8). The row is the
// state — Extend moves the end and the row stops being due — and this is the
// only thing that closes one without a person or a rule. Every minute
// because the reminder counts down to a minute the person was shown.
Schedule::command('ezbizi:close-ended-shifts')->everyMinute()->withoutOverlapping(5);
```

- [ ] **Step 5: Run to verify it passes**

Run: `php artisan test --filter=CloseEndedShiftsTest`
Expected: PASS (2 tests). If `TenantContext::run` has a different signature than `run(Organization, callable)`, read `app/Support/Tenancy/TenantContext.php` and match it.

- [ ] **Step 6: Commit**

```bash
git add app/Console/Commands/CloseEndedShifts.php routes/console.php tests/Feature/TimeClock/CloseEndedShiftsTest.php
git commit -m "Clock in: a shift past its scheduled end is closed by the server, every minute"
```

---

### Task 7: Day list tags

**Files:**
- Modify: `app/Support/TimeClock/TimeClock.php` (`presentShift`)
- Modify: `tests/Feature/TimeClock/TimeClockDayListTest.php`

- [ ] **Step 1: Write the failing test**

Append to `TimeClockDayListTest`:

```php
    public function test_rows_carry_ended_by_outside_and_extensions(): void
    {
        $alpha = $this->provisionTenant('Alpha');
        $this->enableClockIn($alpha['org']);
        $viewer = $this->userWithRole($alpha, 'Hr', ['hr.view']);
        $puncher = $this->userWithRole($alpha, 'Tech', ['time_clock.view']);

        $this->inTenant($alpha['org'], fn () => TimeClockShift::query()->create([
            'organization_id' => $alpha['org']->id,
            'user_id' => $puncher->id,
            'clocked_in_at' => '2026-08-25 12:00:00',
            'clocked_out_at' => '2026-08-25 22:30:00',
            'scheduled_end_at' => '2026-08-25 22:30:00',
            'extensions' => 2,
            'outside_schedule' => true,
            'ended_by' => TimeClockShift::ENDED_BY_SCHEDULE,
        ]));

        $row = $this->signedInAs($viewer)->getJson('/api/v1/hr/time-clock?on=2026-08-25')->assertOk()->json('data.shifts.0');

        $this->assertSame('schedule', $row['ended_by']);
        $this->assertTrue($row['outside_schedule']);
        $this->assertSame(2, $row['extensions']);
    }
```

- [ ] **Step 2: Run to verify it fails**

Run: `php artisan test --filter=TimeClockDayListTest::test_rows_carry`
Expected: FAIL — undefined index `ended_by`.

- [ ] **Step 3: Add the three keys to `presentShift`**

```php
            'open' => $open,
            'ended_by' => $shift->ended_by,
            'outside_schedule' => (bool) $shift->outside_schedule,
            'extensions' => (int) $shift->extensions,
```

- [ ] **Step 4: Run the folder, then commit**

Run: `php artisan test --filter=TimeClock`
Expected: PASS.

```bash
git add app/Support/TimeClock/TimeClock.php tests/Feature/TimeClock/TimeClockDayListTest.php
git commit -m "Clock in: the day list says who closed a shift, and whether it was on schedule"
```

---

### Task 8: Client session model, `window_manager`, and the window facade

**Files:**
- Modify: `client/pubspec.yaml`
- Modify: `client/lib/core/session.dart:836-890`
- Modify: `client/lib/core/ui_strings.dart:70-78` (English fallbacks)
- Create: `client/lib/core/clock_window.dart`, `clock_window_io.dart`, `clock_window_web.dart`
- Modify: `client/lib/main.dart` `main()` (`:48`)
- Test: `client/test/time_clock_status_test.dart` (new)

**Interfaces:**
- `TimeClockStatus` gains `lock` (bool), `scheduledEndAt` (DateTime? UTC), `warningMinutes` (int, default 5), `extensionMinutes` (int, default 30), `today` (`TimeClockWindow?` with `start`, `end` strings).
- `ClockWindow.init()` (call once in `main()`), `ClockWindow.canLock` (bool: not android/ios), `ClockWindow.lock()`, `ClockWindow.release()`, `ClockWindow.raise()` — all `Future<void>`, all no-ops off Windows/macOS.

- [ ] **Step 1: Write the failing test**

`client/test/time_clock_status_test.dart`:

```dart
import 'package:ezbizi_client/core/session.dart';
import 'package:flutter_test/flutter_test.dart';

void main() {
  test('an old server without the schedule keys parses with safe defaults', () {
    final status = TimeClockStatus.fromJson({
      'organization_enabled': true,
      'can_clock': true,
      'show_button': true,
      'open': null,
    });

    expect(status.lock, isFalse);
    expect(status.scheduledEndAt, isNull);
    expect(status.warningMinutes, 5);
    expect(status.extensionMinutes, 30);
    expect(status.today, isNull);
  });

  test('the schedule keys parse, and the end instant is UTC', () {
    final status = TimeClockStatus.fromJson({
      'organization_enabled': true,
      'can_clock': true,
      'show_button': true,
      'open': {'id': 's-1', 'clocked_in_at': '2026-09-08T14:00:00Z'},
      'lock': true,
      'scheduled_end_at': '2026-09-08T22:00:00Z',
      'warning_minutes': 3,
      'extension_minutes': 45,
      'today': {'start': '08:00', 'end': '17:00'},
    });

    expect(status.lock, isTrue);
    expect(status.scheduledEndAt, DateTime.utc(2026, 9, 8, 22));
    expect(status.warningMinutes, 3);
    expect(status.extensionMinutes, 45);
    expect(status.today!.start, '08:00');
    expect(status.today!.end, '17:00');
  });
}
```

- [ ] **Step 2: Run to verify it fails**

Run (from `client/`): `flutter test test/time_clock_status_test.dart`
Expected: FAIL — no getter `lock`.

- [ ] **Step 3: Grow `TimeClockStatus`**

In `client/lib/core/session.dart` replace the class:

```dart
class TimeClockStatus {
  const TimeClockStatus({
    required this.organizationEnabled,
    required this.canClock,
    required this.showButton,
    this.open,
    this.lock = false,
    this.scheduledEndAt,
    this.warningMinutes = 5,
    this.extensionMinutes = 30,
    this.today,
  });

  /// Key missing on `/me`: an older server. No button.
  static const absent = TimeClockStatus(
    organizationEnabled: false,
    canClock: false,
    showButton: false,
  );

  final bool organizationEnabled;
  final bool canClock;

  /// `(org enabled && can_clock) || open != null` — Clock out stays if they
  /// are still in even when the organization has switched Clock in off.
  final bool showButton;
  final TimeClockOpen? open;

  /// The server's answer to "show the lock screen now" (design 2026-09-08
  /// §3.8). Never derived from permissions on this side.
  final bool lock;

  /// Effective end of the open shift after extensions, UTC. Null when today
  /// has no window, or the shift started after it.
  final DateTime? scheduledEndAt;

  final int warningMinutes;
  final int extensionMinutes;

  /// Today's window as wall clock in the schedule's own zone, for display.
  final TimeClockWindow? today;

  factory TimeClockStatus.fromJson(Object? json) {
    if (json is! Map) return absent;
    final map = json is Map<String, dynamic>
        ? json
        : Map<String, dynamic>.from(json);
    final openRaw = map['open'];
    final todayRaw = map['today'];
    final endRaw = map['scheduled_end_at'];

    return TimeClockStatus(
      organizationEnabled: map['organization_enabled'] == true,
      canClock: map['can_clock'] == true,
      showButton: map['show_button'] == true,
      open: openRaw is Map<String, dynamic>
          ? TimeClockOpen.fromJson(openRaw)
          : openRaw is Map
          ? TimeClockOpen.fromJson(Map<String, dynamic>.from(openRaw))
          : null,
      lock: map['lock'] == true,
      scheduledEndAt: endRaw is String ? DateTime.tryParse(endRaw)?.toUtc() : null,
      warningMinutes: map['warning_minutes'] is int ? map['warning_minutes'] as int : 5,
      extensionMinutes: map['extension_minutes'] is int ? map['extension_minutes'] as int : 30,
      today: todayRaw is Map
          ? TimeClockWindow.fromJson(Map<String, dynamic>.from(todayRaw))
          : null,
    );
  }
}

class TimeClockWindow {
  const TimeClockWindow({required this.start, required this.end});

  final String start;
  final String end;

  factory TimeClockWindow.fromJson(Map<String, dynamic> json) => TimeClockWindow(
    start: json['start'] as String? ?? '',
    end: json['end'] as String? ?? '',
  );
}
```

Add the English fallbacks to `ui_strings.dart` after `'time_clock.not_in'`:

```dart
    'time_clock.reminder_title': 'Your shift is ending',
    'time_clock.reminder_body': 'Your shift ends at {time}. Clock out, or extend it.',
    'time_clock.extend': 'Extend {minutes} min',
    'time_clock.lock_sentence': 'Clock in to start your day.',
    'time_clock.sign_out': 'Sign out',
    'time_clock.auto': 'Auto',
    'time_clock.outside': 'Outside schedule',
    'time_clock.day_off': 'Day off',
    'time_clock.work_schedule': 'Work schedule',
    'time_clock.not_scheduled': 'You are not on a scheduled shift.',
    'time_clock.overnight': 'A shift that crosses midnight is not supported yet.',
    'time_clock.exempt_on': 'Exempt from lock screen',
    'time_clock.exempt_off': 'Lock screen applies',
```

- [ ] **Step 4: Run to verify it passes, then add the dependency**

Run: `flutter test test/time_clock_status_test.dart` — PASS.

Run (from `client/`): `flutter pub add window_manager`
Expected: `pubspec.yaml` gains a `window_manager:` line under dependencies with the resolved version. If resolution fails against the pinned `file_selector`/`win32` set, pin `window_manager: ^0.5.1` (the version A1 Tools resolves on the same Flutter) and run `flutter pub get`.

- [ ] **Step 5: The facade, three files**

`client/lib/core/clock_window.dart`:

```dart
import 'client_platform.dart';
import 'clock_window_io.dart'
    if (dart.library.js_interop) 'clock_window_web.dart'
    as impl;

/// The lock screen's grip on the desktop window (design 2026-09-08 §10.2).
///
/// Same shape as client_platform.dart: one facade, two implementations, the
/// compiler picks. `window_manager` is only imported by the io half, and that
/// half only acts on Windows and macOS. Web covers the app and nothing else;
/// Android and iOS never lock (A1 Tools removed theirs 2026-07-17).
///
/// What this cannot do, and says so: stop Ctrl-Alt-Del, Task Manager, or a
/// second monitor's taskbar. It is a cover, not an OS lock.
class ClockWindow {
  /// Once, before `runApp`. A no-op everywhere the window is not ours.
  static Future<void> init() => impl.init();

  /// Whether this build ever shows the lock route at all.
  static bool get canLock =>
      ClientPlatform.name != 'android' && ClientPlatform.name != 'ios';

  /// Fullscreen, always on top, refuse close, take focus.
  static Future<void> lock() => impl.lock();

  /// Give all three back, in the order that does not leave a topmost window
  /// painted at the restored size.
  static Future<void> release() => impl.release();

  /// Show, restore if minimized, focus — for the reminder.
  static Future<void> raise() => impl.raise();
}
```

`client/lib/core/clock_window_io.dart`:

```dart
import 'dart:io' show Platform;

import 'package:flutter/foundation.dart';
import 'package:window_manager/window_manager.dart';

bool get _desktop => Platform.isWindows || Platform.isMacOS;

Future<void> init() async {
  if (!_desktop) return;
  await windowManager.ensureInitialized();
}

Future<void> lock() async {
  if (!_desktop) return;
  await _attempt('fullScreen', () => windowManager.setFullScreen(true));
  await _attempt('alwaysOnTop', () => windowManager.setAlwaysOnTop(true));
  await _attempt('preventClose', () => windowManager.setPreventClose(true));
  await _attempt('focus', windowManager.focus);
}

Future<void> release() async {
  if (!_desktop) return;
  // Topmost and prevent-close first, fullscreen last: dropping fullscreen
  // while still topmost repaints a topmost window at the restored size.
  await _attempt('alwaysOnTop', () => windowManager.setAlwaysOnTop(false));
  await _attempt('preventClose', () => windowManager.setPreventClose(false));
  await _attempt('fullScreen', () => windowManager.setFullScreen(false));
}

Future<void> raise() async {
  if (!_desktop) return;
  await _attempt('show', windowManager.show);
  if (await windowManager.isMinimized()) {
    await _attempt('restore', windowManager.restore);
  }
  await _attempt('focus', windowManager.focus);
}

/// Each call on its own: one failure must not strand the others, which is
/// exactly how a half-released lock screen happens.
Future<void> _attempt(String what, Future<void> Function() op) async {
  try {
    await op();
  } catch (e) {
    if (kDebugMode) debugPrint('[ClockWindow] $what failed: $e');
  }
}
```

`client/lib/core/clock_window_web.dart`:

```dart
/// A browser tab has no window to hold. The lock route covers the app.
Future<void> init() async {}
Future<void> lock() async {}
Future<void> release() async {}
Future<void> raise() async {}
```

In `client/lib/main.dart` `main()`, after `WidgetsFlutterBinding.ensureInitialized();`:

```dart
  await ClockWindow.init();
```

(make `main` `async` if it is not; add `import 'core/clock_window.dart';`).

- [ ] **Step 6: Gate**

Run (from `client/`):
```
flutter analyze
flutter test test/time_clock_status_test.dart test/time_clock_button_test.dart
flutter build web
```
Expected: all clean. `flutter build web` is the one that catches a stray `dart:io` — do not skip it.

- [ ] **Step 7: Commit**

```bash
git add client/pubspec.yaml client/pubspec.lock client/lib/core/session.dart client/lib/core/ui_strings.dart client/lib/core/clock_window.dart client/lib/core/clock_window_io.dart client/lib/core/clock_window_web.dart client/lib/main.dart client/test/time_clock_status_test.dart
git commit -m "Client: the schedule keys on /me, and a window facade for the lock"
```

---

### Task 9: The reminder and the 60-second poll

**Files:**
- Create: `client/lib/widgets/shift_reminder.dart`
- Modify: `client/lib/main.dart` (`_HomeShellState`: fields, `initState`, `dispose`, `_punch`)
- Create: `client/test/shift_reminder_test.dart`

**Interfaces:**
- `ShiftReminderController({required ApiClient api, required Session session, required BuildContext Function() context})` with `void sync()`, `void dispose()`. It reads `session.me?.timeClock`, arms one `Timer` for `scheduledEndAt − warningMinutes`, shows `ShiftReminderDialog` once per `scheduledEndAt` value.
- `ShiftReminderDialog({required DateTime endAt, required int extensionMinutes, required String endLabel, required Future<void> Function() onExtend, required Future<void> Function() onClockOut, required VoidCallback onExpired})`.

- [ ] **Step 1: Write the failing tests**

`client/test/shift_reminder_test.dart` — reuse the `_pumpShell`, `_clock`, `_me`, `_json` helpers from `time_clock_button_test.dart` by copying them into this file verbatim (tests do not import each other), then:

```dart
  testWidgets('the reminder appears at end minus warning, not a minute earlier', (
    tester,
  ) async {
    final end = DateTime.now().toUtc().add(const Duration(minutes: 10));

    await _pumpShell(
      tester,
      timeClock: _clock(
        open: {'id': 's-1', 'clocked_in_at': '2026-09-08T14:00:00Z'},
        scheduledEndAt: end.toIso8601String(),
        warningMinutes: 5,
        extensionMinutes: 30,
      ),
    );

    await tester.pump(const Duration(minutes: 4));
    expect(find.text('Your shift is ending'), findsNothing);

    await tester.pump(const Duration(minutes: 1, seconds: 1));
    await tester.pump();
    expect(find.text('Your shift is ending'), findsOneWidget);
    expect(find.text('Extend 30 min'), findsOneWidget);
    expect(find.widgetWithText(FilledButton, 'Clock Out'), findsOneWidget);
  });

  testWidgets('Extend posts, applies the new end, and the dialog closes', (
    tester,
  ) async {
    final end = DateTime.now().toUtc().add(const Duration(minutes: 6));
    final posts = <String>[];

    await _pumpShell(
      tester,
      timeClock: _clock(
        open: {'id': 's-1', 'clocked_in_at': '2026-09-08T14:00:00Z'},
        scheduledEndAt: end.toIso8601String(),
      ),
      onRequest: (request) async {
        if (request.method == 'POST') {
          posts.add(request.url.path);
          return _json({
            'data': {
              'time_clock': _clock(
                open: {'id': 's-1', 'clocked_in_at': '2026-09-08T14:00:00Z'},
                scheduledEndAt: end.add(const Duration(minutes: 30)).toIso8601String(),
              ),
            },
          });
        }
        return null;
      },
    );

    await tester.pump(const Duration(minutes: 1, seconds: 1));
    await tester.pump();
    expect(find.text('Your shift is ending'), findsOneWidget);

    await tester.tap(find.text('Extend 30 min'));
    await tester.pump();
    await tester.pump();

    expect(posts, ['/api/v1/time-clock/extend']);
    expect(find.text('Your shift is ending'), findsNothing);
  });

  testWidgets('the dialog shows once per end value', (tester) async {
    final end = DateTime.now().toUtc().add(const Duration(minutes: 6));

    await _pumpShell(
      tester,
      timeClock: _clock(
        open: {'id': 's-1', 'clocked_in_at': '2026-09-08T14:00:00Z'},
        scheduledEndAt: end.toIso8601String(),
      ),
      onRequest: (request) async {
        // The poll and the expiry refetch both answer with the same end.
        if (request.url.path == '/api/v1/time-clock/me') {
          return _json({
            'data': {
              'time_clock': _clock(
                open: {'id': 's-1', 'clocked_in_at': '2026-09-08T14:00:00Z'},
                scheduledEndAt: end.toIso8601String(),
              ),
            },
          });
        }
        return null;
      },
    );

    await tester.pump(const Duration(minutes: 1, seconds: 1));
    await tester.pump();
    expect(find.text('Your shift is ending'), findsOneWidget);

    await tester.tap(find.widgetWithText(TextButton, 'Not now'));
    await tester.pump();
    await tester.pump(const Duration(minutes: 1));
    await tester.pump();
    expect(find.text('Your shift is ending'), findsNothing);
  });
```

Extend the copied `_clock` helper with the new named parameters:

```dart
Map<String, Object?> _clock({
  bool showButton = true,
  bool organizationEnabled = true,
  bool canClock = true,
  Map<String, Object?>? open,
  String? scheduledEndAt,
  int warningMinutes = 5,
  int extensionMinutes = 30,
}) => {
  'organization_enabled': organizationEnabled,
  'can_clock': canClock,
  'show_button': showButton,
  'open': open,
  'scheduled_end_at': scheduledEndAt,
  'warning_minutes': warningMinutes,
  'extension_minutes': extensionMinutes,
};
```

- [ ] **Step 2: Run to verify they fail**

Run: `flutter test test/shift_reminder_test.dart`
Expected: FAIL — "Your shift is ending" never appears.

- [ ] **Step 3: Write `shift_reminder.dart`**

```dart
import 'dart:async';

import 'package:flutter/material.dart';

import '../core/api_client.dart';
import '../core/clock_window.dart';
import '../core/session.dart';
import '../core/ui_strings.dart';

/// Arms one timer from the end the server stated, and shows one dialog
/// (design 2026-09-08 §10.1).
///
/// The server closes the shift at `scheduled_end_at`; this only tells the
/// person it is coming. Shown ONCE per end value — extending changes the
/// value, so it comes back before the new end. That latch is what A1 Tools
/// had to add after its two-minute poll turned the countdown into a loop.
class ShiftReminderController {
  ShiftReminderController({
    required this.api,
    required this.session,
    required this.context,
  });

  final ApiClient api;
  final Session session;
  final BuildContext Function() context;

  Timer? _timer;
  DateTime? _armedFor;
  DateTime? _shownFor;
  bool _showing = false;

  /// Call whenever the session's time_clock block may have changed.
  void sync() {
    final status = session.me?.timeClock;
    final end = status?.open == null ? null : status?.scheduledEndAt;

    if (end == null) {
      _timer?.cancel();
      _timer = null;
      _armedFor = null;
      return;
    }

    if (end == _armedFor) return;

    _timer?.cancel();
    _armedFor = end;

    final fireAt = end.subtract(Duration(minutes: status!.warningMinutes));
    final wait = fireAt.difference(DateTime.now().toUtc());

    _timer = Timer(wait.isNegative ? Duration.zero : wait, () => _show(end));
  }

  void dispose() {
    _timer?.cancel();
    _timer = null;
  }

  Future<void> _show(DateTime end) async {
    if (_showing || _shownFor == end) return;
    final status = session.me?.timeClock;
    if (status == null || status.open == null || status.scheduledEndAt != end) return;

    _showing = true;
    _shownFor = end;

    await ClockWindow.raise();

    final ctx = context();
    if (!ctx.mounted) {
      _showing = false;
      return;
    }

    await showDialog<void>(
      context: ctx,
      barrierDismissible: false,
      builder: (_) => ShiftReminderDialog(
        endAt: end,
        extensionMinutes: status.extensionMinutes,
        endLabel: TimeOfDay.fromDateTime(end.toLocal()).format(ctx),
        onExtend: () => _post('/time-clock/extend'),
        onClockOut: () => _post('/time-clock/clock-out'),
        onExpired: _refetchSoon,
      ),
    );

    _showing = false;
  }

  Future<void> _post(String path) async {
    final response = await api.post(path, {});
    if (response.ok && response.data is Map) {
      final block = (response.data as Map)['time_clock'];
      if (block is Map) session.applyTimeClock(Map<String, dynamic>.from(block));
    }
    sync();
  }

  /// The server closes the shift on the same instant. Two seconds is the
  /// scheduler's slack plus the wire; the 60-second poll is the safety net.
  void _refetchSoon() {
    Timer(const Duration(seconds: 2), () async {
      final response = await api.get('/time-clock/me');
      if (response.ok && response.data is Map) {
        final block = (response.data as Map)['time_clock'];
        if (block is Map) session.applyTimeClock(Map<String, dynamic>.from(block));
      }
      sync();
    });
  }
}

class ShiftReminderDialog extends StatefulWidget {
  const ShiftReminderDialog({
    super.key,
    required this.endAt,
    required this.extensionMinutes,
    required this.endLabel,
    required this.onExtend,
    required this.onClockOut,
    required this.onExpired,
  });

  final DateTime endAt;
  final int extensionMinutes;
  final String endLabel;
  final Future<void> Function() onExtend;
  final Future<void> Function() onClockOut;
  final VoidCallback onExpired;

  @override
  State<ShiftReminderDialog> createState() => _ShiftReminderDialogState();
}

class _ShiftReminderDialogState extends State<ShiftReminderDialog> {
  Timer? _tick;
  bool _busy = false;

  Duration get _left {
    final d = widget.endAt.difference(DateTime.now().toUtc());
    return d.isNegative ? Duration.zero : d;
  }

  @override
  void initState() {
    super.initState();
    _tick = Timer.periodic(const Duration(seconds: 1), (_) {
      if (!mounted) return;
      if (_left == Duration.zero) {
        _tick?.cancel();
        Navigator.of(context).pop();
        widget.onExpired();
        return;
      }
      setState(() {});
    });
  }

  @override
  void dispose() {
    _tick?.cancel();
    super.dispose();
  }

  Future<void> _run(Future<void> Function() action) async {
    if (_busy) return;
    setState(() => _busy = true);
    _tick?.cancel();
    await action();
    if (mounted) Navigator.of(context).pop();
  }

  @override
  Widget build(BuildContext context) {
    final left = _left;
    final mm = left.inMinutes;
    final ss = (left.inSeconds % 60).toString().padLeft(2, '0');

    return AlertDialog(
      title: Text(UiStrings.t('time_clock.reminder_title')),
      content: Column(
        mainAxisSize: MainAxisSize.min,
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Text(
            UiStrings.t('time_clock.reminder_body').replaceFirst('{time}', widget.endLabel),
          ),
          const SizedBox(height: 12),
          Text('$mm:$ss', style: Theme.of(context).textTheme.headlineMedium),
        ],
      ),
      actions: [
        TextButton(
          onPressed: _busy ? null : () => Navigator.of(context).pop(),
          child: const Text('Not now'),
        ),
        OutlinedButton(
          onPressed: _busy ? null : () => _run(widget.onExtend),
          child: Text(
            UiStrings.t('time_clock.extend').replaceFirst('{minutes}', '${widget.extensionMinutes}'),
          ),
        ),
        FilledButton(
          onPressed: _busy ? null : () => _run(widget.onClockOut),
          child: Text(UiStrings.t('time_clock.clock_out')),
        ),
      ],
    );
  }
}
```

"Not now" closes the dialog without action; the server still closes the shift at the end. It exists so a dialog is never the only thing on screen with no way to dismiss it, and the test above relies on it.

- [ ] **Step 4: Wire it into the shell**

In `_HomeShellState` (`client/lib/main.dart:376`):

```dart
  late final ShiftReminderController _reminder;
  Timer? _clockPoll;
  VoidCallback? _stopClockListening;
```

In `initState`, after `_remoteControl = ...`:

```dart
    _reminder = ShiftReminderController(
      api: widget.api,
      session: widget.session,
      context: () => context,
    );
    _reminder.sync();
    _stopClockListening = () => widget.session.removeListener(_reminder.sync);
    widget.session.addListener(_reminder.sync);

    // One small block every minute while the organization has the clock on,
    // so an exemption, a schedule change or a server-side close reaches an
    // open app (design 2026-09-08 §3.12). Same cadence as the bell.
    _clockPoll = Timer.periodic(const Duration(seconds: 60), (_) async {
      final status = widget.session.me?.timeClock;
      if (status == null || (!status.organizationEnabled && status.open == null)) return;
      final response = await widget.api.get('/time-clock/me');
      if (!mounted || !response.ok || response.data is! Map) return;
      final block = (response.data as Map)['time_clock'];
      if (block is Map) widget.session.applyTimeClock(Map<String, dynamic>.from(block));
    });
```

In `dispose`, before `super.dispose()`:

```dart
    _clockPoll?.cancel();
    _stopClockListening?.call();
    _reminder.dispose();
```

Add `import 'widgets/shift_reminder.dart';` and `import 'dart:async';` if missing.

- [ ] **Step 5: Gate**

Run (from `client/`):
```
flutter analyze
flutter test test/shift_reminder_test.dart test/time_clock_button_test.dart
flutter build web
```
Expected: clean. If `time_clock_button_test` now fails with "Unexpected GET /api/v1/time-clock/me", the poll fired inside a `pumpAndSettle`; make the poll skip when `status.open == null && !status.organizationEnabled` (it does) and check the `_clock()` in that test has `organizationEnabled: true` — return `null` from the mock for that path so the 500 fallback does not trip the test, or answer it with the same block.

- [ ] **Step 6: Commit**

```bash
git add client/lib/widgets/shift_reminder.dart client/lib/main.dart client/test/shift_reminder_test.dart
git commit -m "Client: a countdown before the shift ends, with Extend or Clock Out"
```

---

### Task 10: The lock screen route

**Files:**
- Create: `client/lib/screens/lock_screen.dart`
- Modify: `client/lib/main.dart:300` (root selector), plus `_punch`
- Create: `client/test/lock_screen_test.dart`

**Interfaces:**
- `LockScreen({required ApiClient api, required Session session})`. Shown at the root instead of `HomeShell` when `session.me!.timeClock.lock && ClockWindow.canLock`.

- [ ] **Step 1: Write the failing tests**

`client/test/lock_screen_test.dart`:

```dart
import 'dart:convert';

import 'package:ezbizi_client/core/api_client.dart';
import 'package:ezbizi_client/core/session.dart';
import 'package:ezbizi_client/core/theme.dart';
import 'package:ezbizi_client/screens/lock_screen.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';

void main() {
  testWidgets('shows the sentence, today, Clock In and Sign out', (tester) async {
    await _pump(tester);

    expect(find.text('Clock in to start your day.'), findsOneWidget);
    expect(find.textContaining('08:00'), findsOneWidget);
    expect(find.widgetWithText(FilledButton, 'Clock In'), findsOneWidget);
    expect(find.text('Sign out'), findsOneWidget);
  });

  testWidgets('a refused punch shows the server sentence', (tester) async {
    await _pump(
      tester,
      onRequest: (r) async => r.url.path == '/api/v1/time-clock/clock-in'
          ? http.Response(
              jsonEncode({'message': 'Clock in is switched off for this organization.'}),
              422,
              headers: {'content-type': 'application/json'},
            )
          : null,
    );

    await tester.tap(find.widgetWithText(FilledButton, 'Clock In'));
    await tester.pump();
    await tester.pump();

    expect(find.text('Clock in is switched off for this organization.'), findsOneWidget);
  });

  testWidgets('a successful punch writes lock false onto the session', (tester) async {
    final session = await _pump(
      tester,
      onRequest: (r) async => r.url.path == '/api/v1/time-clock/clock-in'
          ? http.Response(
              jsonEncode({
                'data': {
                  'time_clock': {
                    'organization_enabled': true,
                    'can_clock': true,
                    'show_button': true,
                    'open': {'id': 's-1', 'clocked_in_at': '2026-09-08T14:00:00Z'},
                    'lock': false,
                  },
                },
              }),
              200,
              headers: {'content-type': 'application/json'},
            )
          : null,
    );

    await tester.tap(find.widgetWithText(FilledButton, 'Clock In'));
    await tester.pump();
    await tester.pump();

    expect(session.me!.timeClock.lock, isFalse);
  });
}

Future<Session> _pump(
  WidgetTester tester, {
  Future<http.Response?> Function(http.Request)? onRequest,
}) async {
  final api = ApiClient(
    client: MockClient((request) async {
      final handled = await onRequest?.call(request);
      if (handled != null) return handled;
      if (request.url.path == '/api/v1/me') {
        return http.Response(
          jsonEncode({
            'data': {
              'id': 'u-1',
              'name': 'Jose Silva',
              'email': 'jose@example.com',
              'has_avatar': false,
              'role': {'id': 'r', 'name': 'Tech'},
              'permissions': ['time_clock.view', 'time_clock.lock_screen'],
              'modules': [],
              'preferences': {},
              'organization': {'id': 'o', 'name': 'Everdoors', 'franchise_label': 'Franchise', 'is_read_only': false},
              'scope': {'type': 'organization', 'id': 'o', 'label': 'Everdoors', 'path': ['Everdoors']},
              'companies': [],
              'franchises': [],
              'time_clock': {
                'organization_enabled': true,
                'can_clock': true,
                'show_button': true,
                'open': null,
                'lock': true,
                'today': {'start': '08:00', 'end': '17:00'},
              },
            },
          }),
          200,
          headers: {'content-type': 'application/json'},
        );
      }
      return http.Response('{"message":"unexpected"}', 500, headers: {'content-type': 'application/json'});
    }),
  );
  final session = Session(api);
  await tester.runAsync(() => session.adopt('tok'));

  await tester.pumpWidget(
    MaterialApp(
      theme: EzBiziTheme.light(),
      home: ListenableBuilder(
        listenable: session,
        builder: (_, _) => LockScreen(api: api, session: session),
      ),
    ),
  );
  await tester.pump();
  return session;
}
```

- [ ] **Step 2: Run to verify it fails**

Run: `flutter test test/lock_screen_test.dart`
Expected: FAIL — `lock_screen.dart` does not exist.

- [ ] **Step 3: Write the screen**

`client/lib/screens/lock_screen.dart`:

```dart
import 'dart:async';

import 'package:flutter/material.dart';

import '../core/api_client.dart';
import '../core/clock_window.dart';
import '../core/session.dart';
import '../core/theme.dart';
import '../core/ui_strings.dart';

/// Replaces the shell while the server says `lock` (design 2026-09-08 §10.2).
///
/// On web this covers the app. On Windows and macOS [ClockWindow] also takes
/// the window: fullscreen, always on top, refuses close — released the moment
/// the punch succeeds, and before signing out. It cannot stop Ctrl-Alt-Del.
class LockScreen extends StatefulWidget {
  const LockScreen({super.key, required this.api, required this.session});

  final ApiClient api;
  final Session session;

  @override
  State<LockScreen> createState() => _LockScreenState();
}

class _LockScreenState extends State<LockScreen> with WidgetsBindingObserver {
  Timer? _tick;
  bool _busy = false;
  String? _error;

  @override
  void initState() {
    super.initState();
    WidgetsBinding.instance.addObserver(this);
    unawaited(ClockWindow.lock());
    _tick = Timer.periodic(const Duration(seconds: 1), (_) {
      if (mounted) setState(() {});
    });
  }

  @override
  void didChangeAppLifecycleState(AppLifecycleState state) {
    // Back from sleep or a minimize: re-assert the grip.
    if (state == AppLifecycleState.resumed) unawaited(ClockWindow.lock());
  }

  @override
  void dispose() {
    _tick?.cancel();
    WidgetsBinding.instance.removeObserver(this);
    unawaited(ClockWindow.release());
    super.dispose();
  }

  Future<void> _clockIn() async {
    if (_busy) return;
    setState(() {
      _busy = true;
      _error = null;
    });

    final response = await widget.api.post('/time-clock/clock-in', {});
    if (!mounted) return;

    if (response.ok && response.data is Map) {
      final block = (response.data as Map)['time_clock'];
      if (block is Map) {
        // Release BEFORE the session flips, so the window is ours again by
        // the time the root swaps this route for the shell.
        await ClockWindow.release();
        widget.session.applyTimeClock(Map<String, dynamic>.from(block));
      }
      if (mounted) setState(() => _busy = false);
      return;
    }

    setState(() {
      _busy = false;
      _error = response.reason;
    });
  }

  Future<void> _signOut() async {
    await ClockWindow.release();
    await widget.api.post('/auth/logout', {});
    widget.session.signOut();
  }

  @override
  Widget build(BuildContext context) {
    final me = widget.session.me;
    final today = me?.timeClock.today;
    final now = TimeOfDay.now().format(context);
    final theme = Theme.of(context);

    return Scaffold(
      backgroundColor: theme.colorScheme.surface,
      body: Center(
        child: ConstrainedBox(
          constraints: const BoxConstraints(maxWidth: 420),
          child: Padding(
            padding: const EdgeInsets.all(32),
            child: Column(
              mainAxisSize: MainAxisSize.min,
              crossAxisAlignment: CrossAxisAlignment.stretch,
              children: [
                Text('EzBizi', textAlign: TextAlign.center, style: theme.textTheme.headlineSmall),
                const SizedBox(height: 24),
                Text(now, textAlign: TextAlign.center, style: theme.textTheme.displayMedium),
                const SizedBox(height: 8),
                Text(me?.name ?? '', textAlign: TextAlign.center, style: theme.textTheme.titleMedium),
                const SizedBox(height: 4),
                Text(
                  today == null
                      ? UiStrings.t('time_clock.day_off')
                      : '${today.start} – ${today.end}',
                  textAlign: TextAlign.center,
                  style: theme.textTheme.bodyMedium,
                ),
                const SizedBox(height: 24),
                Text(UiStrings.t('time_clock.lock_sentence'), textAlign: TextAlign.center),
                const SizedBox(height: 16),
                FilledButton.icon(
                  onPressed: _busy ? null : _clockIn,
                  style: FilledButton.styleFrom(backgroundColor: EzBiziTheme.clockIn(context)),
                  icon: const Icon(Icons.login),
                  label: Text(UiStrings.t('time_clock.clock_in')),
                ),
                if (_error != null) ...[
                  const SizedBox(height: 12),
                  Text(_error!, textAlign: TextAlign.center, style: TextStyle(color: theme.colorScheme.error)),
                ],
                const SizedBox(height: 24),
                TextButton(
                  onPressed: _busy ? null : _signOut,
                  child: Text(UiStrings.t('time_clock.sign_out')),
                ),
              ],
            ),
          ),
        ),
      ),
    );
  }
}
```

- [ ] **Step 4: Gate the root**

In `client/lib/main.dart` just before `return HomeShell(api: _api, session: _session, address: _address);` (line ~300):

```dart
      // The lock is the server's answer, never a permission read here
      // (design 2026-09-08 §3.9). Phones never lock, whatever it says.
      if (_session.me!.timeClock.lock && ClockWindow.canLock) {
        return LockScreen(api: _api, session: _session);
      }
```

Add `import 'screens/lock_screen.dart';`. Nothing in `_punch` changes: a Clock Out response with `lock: true` flips the root on the next build because the session notifies.

- [ ] **Step 5: Gate**

Run (from `client/`):
```
flutter analyze
flutter test test/lock_screen_test.dart test/time_clock_button_test.dart test/shift_reminder_test.dart
flutter build web
```
Expected: clean.

- [ ] **Step 6: Commit**

```bash
git add client/lib/screens/lock_screen.dart client/lib/main.dart client/test/lock_screen_test.dart
git commit -m "Client: a lock screen while a locked person is not clocked in"
```

---

### Task 11: Records schedule panel, exemption toggle, day-list tags

**Files:**
- Create: `client/lib/widgets/work_schedule_panel.dart`
- Create: `client/test/work_schedule_panel_test.dart`
- Modify: `client/lib/screens/hr/records_tab.dart` (menu items `:322-375`, handlers near `:519`)
- Modify: `client/lib/screens/hr/time_clock_tab.dart` (`_row`)
- Modify: `client/test/hr_time_clock_tab_test.dart`

**Interfaces:**
- `Future<Map<String, dynamic>?> showWorkSchedulePanel(BuildContext context, {required String title, required Map<String, dynamic>? days, required DisplayFormats formats})` — returns the seven-day map (`{monday: {start, end} | null, …}`) or null on Cancel.

- [ ] **Step 1: Write the failing tests**

`client/test/work_schedule_panel_test.dart`:

```dart
import 'package:ezbizi_client/core/timestamps.dart';
import 'package:ezbizi_client/widgets/work_schedule_panel.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';

void main() {
  testWidgets('seven rows, a day off switch, and Save returns the week', (tester) async {
    Map<String, dynamic>? saved;

    await tester.pumpWidget(
      MaterialApp(
        home: Builder(
          builder: (context) => TextButton(
            onPressed: () async {
              saved = await showWorkSchedulePanel(
                context,
                title: 'Work schedule',
                days: {
                  'monday': {'start': '08:00', 'end': '17:00'},
                  'tuesday': null,
                  'wednesday': null,
                  'thursday': null,
                  'friday': null,
                  'saturday': null,
                  'sunday': null,
                },
                formats: DisplayFormats.fallback,
              );
            },
            child: const Text('open'),
          ),
        ),
      ),
    );

    await tester.tap(find.text('open'));
    await tester.pumpAndSettle();

    expect(find.text('Mon'), findsOneWidget);
    expect(find.text('Sun'), findsOneWidget);
    expect(find.text('Day off'), findsNWidgets(6));

    // Switch Tuesday on: it starts as the ordinary working day.
    await tester.tap(find.byType(Switch).at(1));
    await tester.pumpAndSettle();
    expect(find.text('Day off'), findsNWidgets(5));

    await tester.tap(find.text('Save'));
    await tester.pumpAndSettle();

    expect(saved!['tuesday'], {'start': '08:00', 'end': '17:00'});
    expect(saved!['sunday'], isNull);
  });
}
```

Append to `client/test/hr_time_clock_tab_test.dart` (reusing its `_pump`, `_clock`, `_day` helpers):

```dart
  testWidgets('a row shows Auto, Outside schedule and the extension count', (tester) async {
    await _pump(
      tester,
      timeClock: _clock(organizationEnabled: true),
      onClock: (uri, _) => _day(
        date: '2026-08-25',
        timezoneLabel: 'UTC-5',
        shifts: [
          {..._bea, 'ended_by': 'schedule', 'outside_schedule': true, 'extensions': 2},
        ],
      ),
    );
    await tester.tap(_tab('Clock in\u2013out'));
    await tester.pumpAndSettle();

    expect(find.text('Auto'), findsOneWidget);
    expect(find.text('Outside schedule'), findsOneWidget);
    expect(find.text('+2'), findsOneWidget);
  });
```

(Copy how the existing tests in that file select the chip — `_tab(...)` and the tap — from the second test at `:31`.)

- [ ] **Step 2: Run to verify they fail**

Run: `flutter test test/work_schedule_panel_test.dart test/hr_time_clock_tab_test.dart`
Expected: FAIL — no `showWorkSchedulePanel`; no "Auto".

- [ ] **Step 3: Write the panel**

`client/lib/widgets/work_schedule_panel.dart`:

```dart
import 'package:flutter/material.dart';

import '../core/timestamps.dart';
import '../core/ui_strings.dart';
import 'setting_editors.dart' show TimeField;
import 'side_panel.dart';

const _weekdays = [
  'monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday',
];

String _title(String day) => day[0].toUpperCase() + day.substring(1, 3);

/// One row per weekday, a Day off switch, start and end (design 2026-09-08
/// §10.3). Shared by Human Resources › Records and Your account.
///
/// Not HoursButton: that editor's keys are open/close and its help text
/// blesses overnight ranges, which the server refuses here.
Future<Map<String, dynamic>?> showWorkSchedulePanel(
  BuildContext context, {
  required String title,
  required Map<String, dynamic>? days,
  required DisplayFormats formats,
}) {
  final week = <String, dynamic>{
    for (final day in _weekdays)
      day: days?[day] is Map ? Map<String, dynamic>.from(days![day] as Map) : null,
  };

  return showSidePanel<Map<String, dynamic>>(
    context,
    builder: (context) => StatefulBuilder(
      builder: (context, repaint) => SidePanel(
        title: title,
        width: 480,
        actions: [
          TextButton(
            onPressed: () => Navigator.of(context).pop(),
            child: const Text('Cancel'),
          ),
          FilledButton(
            onPressed: () => Navigator.of(context).pop(week),
            child: const Text('Save'),
          ),
        ],
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            for (final day in _weekdays)
              Padding(
                padding: const EdgeInsets.symmetric(vertical: 2),
                child: Row(
                  children: [
                    SizedBox(
                      width: 46,
                      child: Text(_title(day), style: Theme.of(context).textTheme.titleSmall),
                    ),
                    Switch(
                      value: week[day] != null,
                      onChanged: (on) => repaint(() {
                        week[day] = on ? {'start': '08:00', 'end': '17:00'} : null;
                      }),
                    ),
                    const SizedBox(width: 8),
                    if (week[day] == null)
                      Text(UiStrings.t('time_clock.day_off'), style: Theme.of(context).textTheme.bodyMedium)
                    else ...[
                      TimeField(
                        value: week[day]['start'] as String,
                        formats: formats,
                        onChanged: (v) => repaint(() => week[day]['start'] = v),
                      ),
                      const Text(' – '),
                      TimeField(
                        value: week[day]['end'] as String,
                        formats: formats,
                        onChanged: (v) => repaint(() => week[day]['end'] = v),
                      ),
                    ],
                  ],
                ),
              ),
            const SizedBox(height: 14),
            Text(UiStrings.t('time_clock.overnight'), style: Theme.of(context).textTheme.bodySmall),
          ],
        ),
      ),
    ),
  );
}
```

Check `TimeField` is exported from `setting_editors.dart` (`grep -n 'class TimeField' client/lib/widgets/setting_editors.dart`); if it is private, make it public in that file (rename `_TimeField` → `TimeField` with `replace_all` inside that file only) and say so in the commit.

- [ ] **Step 4: Records menu and handlers**

In `records_tab.dart` menu `itemBuilder`, after the `'active'` item's divider:

```dart
                  refusedMenuItem(
                    context,
                    value: 'schedule',
                    label: UiStrings.t('time_clock.work_schedule'),
                    reason: noEdit,
                  ),
                  refusedMenuItem(
                    context,
                    value: 'exempt',
                    label: person['lock_screen_exempt'] == true
                        ? UiStrings.t('time_clock.exempt_off')
                        : UiStrings.t('time_clock.exempt_on'),
                    reason: noEdit,
                  ),
                  const PopupMenuDivider(),
```

In `onSelected`:

```dart
                  'schedule' => _editSchedule(person),
                  'exempt' => _patch(person, {
                    'lock_screen_exempt': person['lock_screen_exempt'] != true,
                  }),
```

New method beside `_changeRole`:

```dart
  Future<void> _editSchedule(Map<String, dynamic> person) async {
    final schedule = person['work_schedule'];
    final days = schedule is Map ? schedule['days'] : null;

    final saved = await showWorkSchedulePanel(
      context,
      title: '${UiStrings.t('time_clock.work_schedule')} · ${person['name'] ?? ''}',
      days: days is Map ? Map<String, dynamic>.from(days) : null,
      formats: widget.session.me?.display ?? DisplayFormats.fallback,
    );

    if (saved == null || !mounted) return;

    final response = await widget.api.patch('/hr/people/${person['id']}/schedule', {'days': saved});
    if (!mounted) return;
    if (!response.ok) {
      _snack(response.reason, isError: true);
      return;
    }
    await _load();
  }
```

Add imports `'../../core/ui_strings.dart'` and `'../../widgets/work_schedule_panel.dart'`.

- [ ] **Step 5: Day-list tags**

In `time_clock_tab.dart` `_row`, add after `final seconds = ...`:

```dart
    final endedBy = shift['ended_by'];
    final outside = shift['outside_schedule'] == true;
    final extensions = shift['extensions'];
```

and change the `title:` to:

```dart
        title: Wrap(
          spacing: 8,
          crossAxisAlignment: WrapCrossAlignment.center,
          children: [
            Text(name, style: Theme.of(context).textTheme.titleMedium),
            if (endedBy == 'schedule') Tag(UiStrings.t('time_clock.auto')),
            if (outside) Tag(UiStrings.t('time_clock.outside'), warn: true),
            if (extensions is int && extensions > 0) Tag('+$extensions'),
          ],
        ),
```

Add `import '../../widgets/tag.dart';`.

- [ ] **Step 6: Gate**

Run (from `client/`):
```
flutter analyze
flutter test test/work_schedule_panel_test.dart test/hr_time_clock_tab_test.dart
flutter build web
```
Expected: clean.

- [ ] **Step 7: Commit**

```bash
git add client/lib/widgets/work_schedule_panel.dart client/lib/widgets/setting_editors.dart client/lib/screens/hr/records_tab.dart client/lib/screens/hr/time_clock_tab.dart client/test/work_schedule_panel_test.dart client/test/hr_time_clock_tab_test.dart
git commit -m "Client: HR sets a work schedule and the lock-screen exemption; the day list carries the tags"
```

---

### Task 12: Your account — the schedule card

**Files:**
- Modify: `client/lib/screens/profile_screen.dart` (`rest` list near `:180`, and the phone `_rows` group near `:230`)
- Modify: `client/test/profile_screen_test.dart` if it exists (`ls client/test | grep -i profile`), else create `client/test/profile_schedule_card_test.dart`

**UI/UX gate:** the card's layout goes through the UI/UX session per `docs/MODULE-OWNERS.md` — message its desktop session id from `docs/SESSIONS.md` with Jose's screenshot as the reference and three HTML options; implement the one Jose picks. The step below is the reference layout so the tests can be written now; if the approved design differs, adjust the widget and keep the assertions on content, not geometry.

- [ ] **Step 1: Write the failing test**

Pump `ProfileScreen` the way the existing profile test does (copy its `_pump`); stub `GET /api/v1/me/profile` with `work_schedule: {timezone: 'Etc/GMT+5', days: {monday: {start: '08:00', end: '17:03'}, …saturday: null, sunday: null}}` and `can_edit_schedule: false`, then:

```dart
    expect(find.text('Work schedule'), findsOneWidget);
    expect(find.text('Mon'), findsOneWidget);
    expect(find.text('08:00'), findsWidgets);
    expect(find.text('17:03'), findsOneWidget);
    expect(find.text('Day off'), findsNWidgets(2));
    expect(find.text('Edit'), findsNothing);
```

and a second case with `can_edit_schedule: true` expecting `find.text('Edit')` once.

- [ ] **Step 2: Run to verify it fails**

Run: `flutter test test/profile_schedule_card_test.dart`
Expected: FAIL — "Work schedule" not found.

- [ ] **Step 3: The card**

Add to `profile_screen.dart` (private widget at the bottom of the file):

```dart
class _WorkScheduleCard extends StatelessWidget {
  const _WorkScheduleCard({
    required this.schedule,
    required this.canEdit,
    required this.onEdit,
  });

  final Map<String, dynamic>? schedule;
  final bool canEdit;
  final VoidCallback onEdit;

  static const _days = ['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday'];

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final days = schedule?['days'] is Map ? Map<String, dynamic>.from(schedule!['days'] as Map) : const {};

    return Card(
      child: Padding(
        padding: const EdgeInsets.all(16),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            Row(
              children: [
                const Icon(Icons.schedule_outlined, size: 18),
                const SizedBox(width: 8),
                Expanded(child: Text(UiStrings.t('time_clock.work_schedule'), style: theme.textTheme.titleSmall)),
                if (canEdit)
                  TextButton.icon(
                    onPressed: onEdit,
                    icon: const Icon(Icons.edit_outlined, size: 16),
                    label: const Text('Edit'),
                  ),
              ],
            ),
            const SizedBox(height: 8),
            for (final day in _days)
              Padding(
                padding: const EdgeInsets.symmetric(vertical: 6),
                child: Row(
                  children: [
                    SizedBox(width: 44, child: Text(day[0].toUpperCase() + day.substring(1, 3), style: theme.textTheme.titleSmall)),
                    if (days[day] is Map) ...[
                      _chip(context, Icons.login, (days[day] as Map)['start'] as String? ?? '', EzBiziTheme.clockIn(context)),
                      const Padding(padding: EdgeInsets.symmetric(horizontal: 8), child: Icon(Icons.arrow_forward, size: 14)),
                      _chip(context, Icons.logout, (days[day] as Map)['end'] as String? ?? '', EzBiziTheme.clockOut(context)),
                    ] else
                      Text(UiStrings.t('time_clock.day_off'), style: theme.textTheme.bodyMedium?.copyWith(fontStyle: FontStyle.italic)),
                  ],
                ),
              ),
          ],
        ),
      ),
    );
  }

  Widget _chip(BuildContext context, IconData icon, String text, Color color) => Container(
    padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 3),
    decoration: BoxDecoration(color: color.withValues(alpha: 0.12), borderRadius: BorderRadius.circular(6)),
    child: Row(mainAxisSize: MainAxisSize.min, children: [
      Icon(icon, size: 14, color: color),
      const SizedBox(width: 4),
      Text(text, style: TextStyle(color: color, fontWeight: FontWeight.w600)),
    ]),
  );
}
```

Insert it into `rest` (wide) right after `PasswordCard`, and into the phone `_rows` as its own group, both as:

```dart
      _WorkScheduleCard(
        schedule: _profile['work_schedule'] is Map ? Map<String, dynamic>.from(_profile['work_schedule'] as Map) : null,
        canEdit: _profile['can_edit_schedule'] == true,
        onEdit: _editSchedule,
      ),
```

with the handler:

```dart
  Future<void> _editSchedule() async {
    final schedule = _profile['work_schedule'];
    final days = schedule is Map ? schedule['days'] : null;

    final saved = await showWorkSchedulePanel(
      context,
      title: UiStrings.t('time_clock.work_schedule'),
      days: days is Map ? Map<String, dynamic>.from(days) : null,
      formats: widget.session.me?.display ?? DisplayFormats.fallback,
    );
    if (saved == null || !mounted) return;

    final response = await widget.api.patch('/me/schedule', {'days': saved});
    if (!mounted) return;
    if (!response.ok) {
      ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text(response.reason)));
      return;
    }
    setState(() => _profile['work_schedule'] = (response.data as Map)['work_schedule']);
  }
```

Imports: `'../core/ui_strings.dart'`, `'../core/timestamps.dart'`, `'../widgets/work_schedule_panel.dart'`, `'../core/theme.dart'`. Confirm `_profile` is the map `show()` fills (`grep -n '_profile =' client/lib/screens/profile_screen.dart`).

- [ ] **Step 4: Gate and commit**

```
flutter analyze
flutter test test/profile_schedule_card_test.dart
flutter build web
```

```bash
git add client/lib/screens/profile_screen.dart client/test/profile_schedule_card_test.dart
git commit -m "Client: Your account shows the work schedule, and edits it when the organization allows"
```

---

### Task 13: Full gates, board, and the hand-off

**Files:** none new.

- [ ] **Step 1: Backend suite for the touched areas**

Run: `php artisan test --filter='TimeClock|WorkSchedule|CloseEndedShifts|HrPeople|Profile|Automation|Privacy|Settings|Roles'`
Expected: PASS. Then `vendor/bin/pint` on **each changed PHP file by path** (never on a directory).

- [ ] **Step 2: Client suite**

Run (from `client/`): `flutter analyze && flutter test && flutter build web && flutter build windows`
Expected: all clean. `build windows` is what proves `window_manager` links.

- [ ] **Step 3: Desktop check on this machine**

Run the Windows build, sign in as a user in a test org with `time_clock.enabled`, `time_clock.lock_screen`, a role with Lock screen, and a schedule covering now. Confirm: the lock route appears; the window is fullscreen and refuses close and minimize; Clock In releases it; Clock Out re-locks; a schedule ending in 6 minutes with warning 5 raises the reminder in one minute and the countdown reaches zero into the lock. Write what was observed (not what was expected) into the commit message of a docs-only commit that ticks the board.

- [ ] **Step 4: Board and STATUS**

Run a board patch in `H:\Development\integrations-board` ticking `wsl-backend`, `wsl-client`, `wsl-uiux` (if the mockup round happened) on track `work-schedule-lock`, and add one sentence to `docs/EZBIZI-STATUS.md` beside "Clock in is live" naming the schedule, reminder, close command and lock, with the SHA.

- [ ] **Step 5: Hand-off to the deploy desk**

`send_message` to the Deployment desktop session id in `docs/SESSIONS.md` with: the tip SHA on `main` (or the branch and `git rev-list --count main..HEAD`), `git diff --name-only <base>..HEAD`, **migration rides: yes, three files, additive**, the gates run and their results, and these release bullets:

- Work schedule per person: Human Resources › Records sets it; Your account shows it, and edits it when the organization allows.
- Five minutes before a scheduled shift ends, a countdown offers Extend or Clock Out; when it reaches zero the shift is closed on the server.
- Lock screen: when the organization turns it on and a role includes Lock screen, the app shows only Clock In while the person is not clocked in on a working day. Windows and macOS cover the desktop.
- The Clock in–out list marks shifts closed automatically, started outside schedule, or extended.

Say that this session carries Jose's word of 2026-09-08 ("once you're done talk to EzBizi - Deployment Lead for deployment").
