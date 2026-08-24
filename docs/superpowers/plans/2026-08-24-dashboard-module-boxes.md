# Dashboard module boxes Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.
>
> **Do not deploy, `git push production`, or SSH.** Only when the owner says so in that session.
>
> **Email:** another session owns connect, grants, and sync. This plan may add `unreadCount()` on `MailboxReader` and implement it on existing readers. Do not change `MailboxesController`, grants, OAuth, or `WatchMailboxes`.

**Goal:** Replace the widget-picker dashboard with one glance box per reachable sidebar module, as specified in `docs/superpowers/specs/2026-08-24-dashboard-module-boxes-design.md`.

**Architecture:** A `Glance` is keyed by module (`jobs`, `hr`, `inventory`). `GlanceRegistry` lists one per tab module except `dashboard`. `GET /dashboard` returns every glance the caller may open, in sidebar order overlaid with their saved order. Existing `Widget` classes stay as counters the glances call. The client draws `slices` / `series` or a sentence; it does not aggregate.

**Tech Stack:** Laravel 13 · PHP 8.4 · MySQL 8 (`ezbizi_testing`) · Flutter web · ModuleRegistry · ViewScope · SettingsResolver · Widget counters already in `app/Support/Dashboard/Widgets`

**Spec:** `docs/superpowers/specs/2026-08-24-dashboard-module-boxes-design.md`

## Global Constraints

- Front door: `StartUp.md`. If documents disagree, `docs/EZBIZI-STATUS.md` wins; this spec wins on the dashboard.
- TDD: red test first; delete the production line once and watch it fail; restore.
- User-facing sentences name what happened and what to do. No class names, `::`, or “immutable.”
- Reach is ViewScope ∩ §4. A franchise technician’s Jobs box is not the organization’s. Empty reach matches nothing (`whereIn` on `[]`).
- Amounts stay minor units. Never sum across currencies.
- Empty data is a sentence, not an empty chart. Coming soon: sentence, no chart switcher.
- One period for the page: `week` `month` `quarter` `year`. Default `month`. Snapshots ignore it.
- `week_starts_on` is an organization setting (default `sunday`). Timezone is the current view’s franchise timezone, else the resolved `timezone` setting.
- Cannot hide a reachable module. A save that omits one appends it (200), it does not 422.
- Tap does not navigate. No “Choose panels.”
- `dashboard.widgets` is ignored on read after one optional copy of `jobs.revenue` `statuses` onto the Jobs glance options.
- `surface` on `PUT /dashboard/layout` is ignored, not refused.
- MySQL gate: `php artisan test` against a database whose name contains `testing`. Never assert JSON key order.
- Dart tests from `client/`: `flutter test test/<file>.dart`. Bump `client/VERSION` and a `VersionHistory` row in the same commit that ships UI.
- Same deploy ships PHP and Flutter. Do not deploy from this plan.
- Do not add `jobs.financials`. Do not put Fleet money on the dashboard. Do not build Inventory / Phone / compose.

---

## File structure (locked)

| Path | Responsibility |
|---|---|
| `app/Support/Dashboard/Glance.php` | Interface: key, label, permission, presentations, snapshot, resolve |
| `app/Support/Dashboard/GlancePayload.php` | Wire shape for one box |
| `app/Support/Dashboard/DashboardPeriod.php` | Calendar window + series buckets |
| `app/Support/Dashboard/GlanceRegistry.php` | Declared glances, `availableTo`, completeness vs tab modules |
| `app/Support/Dashboard/ComingSoonGlance.php` | Sentence-only box |
| `app/Support/Dashboard/Glances/JobsGlance.php` | Pie of statuses + revenue sentence |
| `app/Support/Dashboard/Glances/CustomersGlance.php` | Line of added customers |
| `app/Support/Dashboard/Glances/HrGlance.php` | Bar in-flight vs stalled + five sentences |
| `app/Support/Dashboard/Glances/InspectionGlance.php` | Bar: in progress / submitted / archived |
| `app/Support/Dashboard/Glances/FleetGlance.php` | Pie of `VehicleStatus` |
| `app/Support/Dashboard/Glances/AutomationsGlance.php` | Pie succeeded vs failed (last 100) |
| `app/Support/Dashboard/Glances/EmailGlance.php` | Bar unread by readable mailbox |
| `app/Support/Dashboard/Glances/MarketingGlance.php` | Sentence until compose (ComingSoonGlance is fine) |
| `app/Http/Controllers/Api/DashboardController.php` | `glances` payload, save order/period/presentation |
| `app/Http/Controllers/Api/HrDashboardController.php` | The one HR glance |
| `app/Support/Email/MailboxReader.php` | Add `unreadCount(Mailbox): int` |
| `client/lib/screens/dashboard_screen.dart` | Period chips, glances, no picker |
| `client/lib/screens/hr/dashboard_tab.dart` | One HR box |
| `client/lib/widgets/dashboard_chart.dart` | Pie, bar, line, timeline from payload |
| `client/lib/core/dashboard_panels.dart` | Icons by module key; drop HR-widget keys as required |
| `tests/Feature/Dashboard/` | PHP glance + HTTP tests |
| `client/test/dashboard_*.dart` | Flutter tests |

Do **not** delete the existing Widget classes. They are the counters.

Do **not** add a chart package unless a custom painter cannot draw the four shapes. Prefer `CustomPaint` and `EzBiziTheme`.

---

### Task 1: Glance contract, period, coming-soon registry

**Files:**
- Create: `app/Support/Dashboard/Glance.php`
- Create: `app/Support/Dashboard/GlancePayload.php`
- Create: `app/Support/Dashboard/DashboardPeriod.php`
- Create: `app/Support/Dashboard/ComingSoonGlance.php`
- Create: `app/Support/Dashboard/GlanceRegistry.php`
- Test: `tests/Feature/Dashboard/DashboardPeriodTest.php`
- Test: `tests/Feature/Dashboard/GlanceRegistryTest.php`

**Interfaces:**
- Produces: `Glance::key(): string` (module key)
- Produces: `Glance::label(): string`
- Produces: `Glance::permission(): string` (`{module}.view`)
- Produces: `Glance::allowedPresentations(): array` — empty means sentence-only
- Produces: `Glance::defaultPresentation(): string`
- Produces: `Glance::snapshot(): bool`
- Produces: `Glance::resolve(User $user, DashboardPeriod $period, array $options): GlancePayload`
- Produces: `GlancePayload::toArray(): array` matching spec §11 (`key`, `label`, `presentation`, `allowed_presentations`, `snapshot`, `coming_soon`, `options`, `data`)
- Produces: `DashboardPeriod::VALUES = ['week','month','quarter','year']`
- Produces: `DashboardPeriod::for(User $user, string $key = 'month'): self` with `key`, `startsOn`, `endsOn`, `timezone`
- Produces: `DashboardPeriod::bucket(Carbon $at): string` — `Y-m-d` for week, start-of-week `Y-m-d` for month/quarter, `Y-m` for year
- Produces: `GlanceRegistry::all(): Glance[]` — every registered glance, sidebar order
- Produces: `GlanceRegistry::availableTo(User $user): Glance[]` — permission + `ModuleAvailability`
- Produces: `GlanceRegistry::find(string $key, User $user): ?Glance`

Sidebar order is a constant on the registry, matching `ModuleServiceProvider` tab modules minus `dashboard`:

```php
public const ORDER = [
    'hr', 'customers', 'jobs', 'inspection', 'marketing',
    'web_management', 'phone', 'fleet_management', 'inventory',
    'services', 'email', 'automations',
];
```

`all()` / `availableTo()` sort by this list. A glance not yet registered is skipped, not defaulted. Later tasks only **add** a class; they do not restack the list.

Coming-soon sentences (copy, do not paraphrase):

| key | label | sentence |
|---|---|---|
| `web_management` | Web Management | Web Management is where a company will edit and publish its public website from EzBizi — the same place it already runs the rest of the business. The editor is not here yet. |
| `phone` | Phone | Phone is where the team will place and receive calls, and send texts, with customers without leaving EzBizi. Calling is not here yet. |
| `inventory` | Inventory | Inventory is where the company will keep what it has on the shelf — parts, materials, and the stock that jobs draw from. The register is not here yet. |
| `services` | Services | Services is where the company will name what it sells — the work a job is for, and the price it starts from. The catalogue is not here yet. |
| `marketing` | Marketing | Marketing will show scheduled and published posts here once compose is live. |

Register **only these five** in Task 1. Later tasks add the live modules. Do not register `dashboard`. `availableTo` for an administrator in Task 1 therefore returns those five, in ORDER (marketing → … → services), not HR.

`ComingSoonGlance`: `allowedPresentations() = []`, `defaultPresentation() = 'text'`, `snapshot() = true`, `resolve()` returns `comingSoon: true`, `data.sentence` as constructed, `empty: true`.

Period timezone: if `ViewScope` is a franchise, use that franchise’s `timezone` column when set, else `SettingsResolver::get('timezone', $organization)`. Company / organization view: `get('timezone', $companyOrOrg)`. `endsOn` is `now($timezone)`. Week start: `week_starts_on` `sunday` → `Carbon::SUNDAY`, `monday` → `Carbon::MONDAY`. Month: 1st 00:00. Quarter: 1 Jan / 1 Apr / 1 Jul / 1 Oct. Year: 1 Jan.

- [ ] **Step 1: Failing period tests**

```php
namespace Tests\Feature\Dashboard;

use App\Support\Dashboard\DashboardPeriod;
use Illuminate\Support\Carbon;
use Tests\TestCase;
// use the same ProvisionOrganization + TenantContext pattern as DashboardTest

public function test_a_month_starts_on_the_first_in_the_view_timezone(): void
{
    Carbon::setTestNow(Carbon::parse('2026-08-24 16:00:00', 'America/New_York'));
    // admin in Alpha, default timezone America/New_York
    $period = DashboardPeriod::for($this->alpha['admin'], 'month');
    $this->assertSame('month', $period->key);
    $this->assertSame('2026-08-01T00:00:00-04:00', $period->startsOn->toIso8601String());
    $this->assertSame('America/New_York', $period->timezone);
}

public function test_week_respects_week_starts_on(): void
{
    Carbon::setTestNow(Carbon::parse('2026-08-26 12:00:00', 'America/New_York')); // Wednesday
    $this->inTenant(fn () => app(\App\Support\Settings\SettingsResolver::class)
        ->set('week_starts_on', $this->alpha['org'], 'monday'));
    $period = DashboardPeriod::for($this->alpha['admin'], 'week');
    $this->assertSame('2026-08-24', $period->startsOn->toDateString()); // Monday
}
```

- [ ] **Step 2:** `php artisan test --filter=DashboardPeriodTest`. Expected: FAIL, class missing.

- [ ] **Step 3: Implement `DashboardPeriod`.** Then the tests pass.

- [ ] **Step 4: Failing registry tests**

```php
public function test_inventory_view_alone_sees_only_the_inventory_glance(): void
{
    $user = $this->userWithPermissions(['inventory.view']);
    $keys = array_map(fn ($g) => $g->key(), app(GlanceRegistry::class)->availableTo($user));
    $this->assertSame(['inventory'], $keys);
}

public function test_a_coming_soon_glance_has_no_chart_presentations(): void
{
    $user = $this->userWithPermissions(['inventory.view']);
    $payload = app(GlanceRegistry::class)->find('inventory', $user)
        ->resolve($user, DashboardPeriod::for($user), []);
    $array = $payload->toArray();
    $this->assertTrue($array['coming_soon']);
    $this->assertSame([], $array['allowed_presentations']);
    $this->assertSame('text', $array['presentation']);
    $this->assertStringContainsString('register is not here yet', $array['data']['sentence']);
}

public function test_dashboard_is_not_a_glance(): void
{
    $this->assertNull(app(GlanceRegistry::class)->find('dashboard', $this->alpha['admin']));
}
```

`userWithPermissions` is the Jobs-only role helper from `DashboardTest` (sync `ezbizi:sync-permissions`, create Role, attach those keys, assign to the franchise).

- [ ] **Step 5:** `php artisan test --filter=GlanceRegistryTest`. Expected: FAIL.

- [ ] **Step 6: Implement Glance, GlancePayload, ComingSoonGlance, GlanceRegistry** with the five coming-soon modules in **sidebar order** among themselves: `marketing`, `web_management`, `phone`, `inventory`, `services` (that is their order in `ModuleServiceProvider::businessModules`). `availableTo` checks `$user->hasPermission($glance->permission())` and `ModuleAvailability::isAvailable($glance->key(), $user->organization)`.

- [ ] **Step 7:** Both test files PASS.

- [ ] **Step 8: Commit**

```
git add app/Support/Dashboard tests/Feature/Dashboard
git commit -m "Declare dashboard glances and a calendar period, starting with coming-soon modules."
```

---

### Task 2: Jobs glance

**Files:**
- Create: `app/Support/Dashboard/Glances/JobsGlance.php`
- Modify: `app/Support/Dashboard/GlanceRegistry.php` — register `JobsGlance` (ORDER already places `jobs`)
- Modify: `app/Support/Dashboard/Widgets/Revenue.php` — if `$options['since']` is a `Carbon`, use it instead of `$this->since($options)`
- Test: `tests/Feature/Dashboard/JobsGlanceTest.php`

**Interfaces:**
- Produces: `JobsGlance::key() = 'jobs'`
- Produces: `allowedPresentations() = ['pie','bar','text']`, default `'pie'`, `snapshot() = true`
- Consumes: `JobsByStatus`, `Revenue` (with `since` + `statuses` from `$options['earned_statuses']`)
- `data.slices` = `[{label: status, total: n}, …]` every configured status once any job exists
- Zero jobs: `empty: true`, sentence `No jobs in reach.`
- Revenue: `data.revenue` is the widget’s `rows` (`currency`, `amount_minor`, `jobs`) when statuses are chosen — two currencies, never summed. `sentences` one per currency. Unconfigured: no `revenue` key; `sentences` = `['Choose which of your job statuses count as earned.']`
- Upcoming: if any reachable job has `scheduled_for` after now, a sentence `{n} jobs coming up.` / `1 job coming up.`; omit when zero

Among registered glances, ORDER puts `jobs` before the coming-soon modules.

- [ ] **Step 1: Failing tests**

```php
public function test_a_branch_user_sees_only_their_jobs_on_the_pie(): void
{
    [$second, $branch] = $this->secondBranch(); // copy helper from DashboardTest
    $this->job($this->alpha['franchise']);
    $this->job($this->alpha['franchise']);
    $this->job($second);
    $payload = app(JobsGlance::class)->resolve($branch, DashboardPeriod::for($branch), [])->toArray();
    $this->assertSame(1, array_sum(array_column($payload['data']['slices'], 'total')));
}

public function test_zero_jobs_is_a_sentence_not_slices(): void
{
    $payload = app(JobsGlance::class)->resolve($this->alpha['admin'], DashboardPeriod::for($this->alpha['admin']), [])->toArray();
    $this->assertTrue($payload['data']['empty']);
    $this->assertSame('No jobs in reach.', $payload['data']['sentence']);
}

public function test_revenue_is_not_added_across_currencies(): void
{
    $this->job($this->alpha['franchise'], ['amount_minor' => 10000, 'currency' => 'USD', 'status' => 'completed']);
    $this->job($this->alpha['franchise'], ['amount_minor' => 20000, 'currency' => 'CAD', 'status' => 'completed']);
    $payload = app(JobsGlance::class)->resolve(
        $this->alpha['admin'],
        DashboardPeriod::for($this->alpha['admin'], 'year'),
        ['earned_statuses' => ['completed']],
    )->toArray();
    $this->assertCount(2, $payload['data']['revenue']);
    $this->assertSame(['CAD', 'USD'], array_column($payload['data']['revenue'], 'currency'));
    $this->assertSame([20000, 10000], array_column($payload['data']['revenue'], 'amount_minor'));
    $this->assertCount(2, $payload['data']['sentences']);
}

public function test_unconfigured_revenue_asks_for_statuses(): void
{
    $this->job($this->alpha['franchise']);
    $payload = app(JobsGlance::class)->resolve($this->alpha['admin'], DashboardPeriod::for($this->alpha['admin']), [])->toArray();
    $this->assertTrue(collect($payload['data']['sentences'] ?? [])->contains(
        fn ($s) => str_contains($s, 'Choose which of your job statuses count as earned')
    ) || ($payload['data']['sentence'] ?? '') === 'Choose which of your job statuses count as earned.');
}

public function test_the_pie_ignores_the_dashboard_period(): void
{
    $this->job($this->alpha['franchise']);
    $week = app(JobsGlance::class)->resolve($this->alpha['admin'], DashboardPeriod::for($this->alpha['admin'], 'week'), [])->toArray();
    $year = app(JobsGlance::class)->resolve($this->alpha['admin'], DashboardPeriod::for($this->alpha['admin'], 'year'), [])->toArray();
    $this->assertSame($week['data']['slices'], $year['data']['slices']);
}
```

Pin the unconfigured-revenue assertion to the exact sentence `Choose which of your job statuses count as earned.` in `sentences`.

- [ ] **Step 2:** `php artisan test --filter=JobsGlanceTest`. Expected: FAIL.

- [ ] **Step 3: Implement `JobsGlance`.** Reuse `JobsByStatus::resolve` for slices (`label` = `status`). Empty when `total === 0`. Call `Revenue::resolve` with `statuses` = `earned_statuses` and `since` = `$period->startsOn`. Map revenue rows to sentences with `amount_minor` still in the payload if useful (`data.revenue` is allowed as extra; client may ignore it). Register in `GlanceRegistry`.

- [ ] **Step 4:** Tests PASS. `GlanceRegistryTest` still passes (inventory-only user does not see `jobs`).

- [ ] **Step 5: Commit**

```
git commit -m "Count jobs by status on the dashboard Jobs glance, with revenue as a sentence."
```

---

### Task 3: Customers glance

**Files:**
- Create: `app/Support/Dashboard/Glances/CustomersGlance.php`
- Modify: `GlanceRegistry` — register `customers` immediately before `jobs`
- Modify: `CustomersAdded` only if you pass `since`; otherwise query in the glance with `$period->startsOn`
- Test: `tests/Feature/Dashboard/CustomersGlanceTest.php`

**Interfaces:**
- Produces: key `customers`; presentations `line`,`bar`,`text`; default `line`; `snapshot() = false`
- `data.series[0].points` = every bucket in the period (zeros included) once at least one customer exists in the period; otherwise empty + sentence `No new customers this month.` (worded with the period: week/month/quarter/year)
- Sentence also names the in-reach total; organization-wide total sits beside it as today (`CustomersAdded`)

- [ ] **Step 1: Failing tests**

```php
public function test_the_line_counts_only_customers_owned_in_reach(): void
{
    [$second, $branch] = $this->secondBranch();
    $this->customer($this->alpha['franchise']);
    $this->customer($second);
    $payload = app(CustomersGlance::class)->resolve($branch, DashboardPeriod::for($branch, 'year'), [])->toArray();
    $sum = array_sum(array_column($payload['data']['series'][0]['points'], 'value'));
    $this->assertSame(1, $sum);
}

public function test_empty_period_is_a_sentence(): void
{
    $payload = app(CustomersGlance::class)->resolve(
        $this->alpha['admin'], DashboardPeriod::for($this->alpha['admin'], 'week'), []
    )->toArray();
    $this->assertTrue($payload['data']['empty']);
    $this->assertSame('No new customers this week.', $payload['data']['sentence']);
}

public function test_changing_period_changes_the_series(): void
{
    Carbon::setTestNow(Carbon::parse('2026-08-24 12:00:00', 'America/New_York'));
    $this->customer($this->alpha['franchise']); // created now
    $week = app(CustomersGlance::class)->resolve($this->alpha['admin'], DashboardPeriod::for($this->alpha['admin'], 'week'), [])->toArray();
    $this->assertFalse($week['data']['empty']);
}
```

- [ ] **Step 2:** `php artisan test --filter=CustomersGlanceTest`. FAIL.

- [ ] **Step 3: Implement.** Filter `Customer` by `owning_franchise_id` in `ViewScope::franchiseIds()`, `created_at` in `[$period->startsOn, $period->endsOn]`. Bucket with `$period->bucket($createdAt)`.

- [ ] **Step 4:** PASS. Commit: `Count new customers on the dashboard as a line over the chosen period.`

---

### Task 4: HR glance

**Files:**
- Create: `app/Support/Dashboard/Glances/HrGlance.php`
- Modify: `GlanceRegistry` — register `hr` first (sidebar: HR is the first business tab)
- Test: `tests/Feature/Dashboard/HrGlanceTest.php`

**Interfaces:**
- Produces: key `hr`; presentations `bar`,`pie`,`text`; default `bar`; `snapshot() = true`
- Slices: `Onboardings in flight` = sum of `OnboardingsInFlight` rows; `Invitations stalled` = sum of `InvitationsStalled` rows
- `sentences` always include headcount (`1 person on the books.` / `{n} people on the books.`), in-flight split, stalled split, starters/leavers (period-bound via `since` = `$period->startsOn`), certifications “not built yet” until `is_expiry` exists
- Empty when both slice totals are 0 **and** headcount is 0 — but a one-person org is never empty: headcount ≥ 1 for the founder. Spec: a business with nobody hired yet is the empty sentence. Founder counts. So empty only if headcount is 0 (should not happen for a provisioned org).

Keep using `ScopesToPlaces` widgets so organization-level assignments are not dropped.

- [ ] **Step 1: Failing tests** (mirror `HrDashboardTest` failures that look like success)

```php
public function test_a_one_person_organization_counts_the_founder(): void
{
    $payload = app(HrGlance::class)->resolve($this->alpha['admin'], DashboardPeriod::for($this->alpha['admin']), [])->toArray();
    $this->assertContains(
        '1 person on the books.',
        $payload['data']['sentences'],
        'The founder is assigned at organization level; counting by franchise_id would say nobody.',
    );
}

public function test_revoked_invitations_are_not_stalled(): void
{
    // create a revoked invitation the way HrDashboardTest does; stalled slice stays 0
}

public function test_in_flight_vs_stalled_slices_ignore_the_period(): void
{
    $week = app(HrGlance::class)->resolve($this->alpha['admin'], DashboardPeriod::for($this->alpha['admin'], 'week'), [])->toArray()['data']['slices'];
    $year = app(HrGlance::class)->resolve($this->alpha['admin'], DashboardPeriod::for($this->alpha['admin'], 'year'), [])->toArray()['data']['slices'];
    $this->assertSame($week, $year);
}
```

Copy the revoked-invitation setup from `tests/Feature/Api/HrDashboardTest.php` rather than inventing statuses.

- [ ] **Step 2:** FAIL. **Step 3:** Implement by calling the five HR widgets. Starters: pass `since`. Certifications: ignore period; keep the widget’s own “not built yet” message in `sentences`. **Step 4:** PASS.

- [ ] **Step 5: Commit** `Put Human Resources on the dashboard as one in-flight vs stalled glance.`

---

### Task 5: Inspection, Fleet, Automations

**Files:**
- Create: `app/Support/Dashboard/Glances/InspectionGlance.php`
- Create: `app/Support/Dashboard/Glances/FleetGlance.php`
- Create: `app/Support/Dashboard/Glances/AutomationsGlance.php`
- Modify: `GlanceRegistry` — register the three classes. ORDER already slots them.
- Test: `tests/Feature/Dashboard/InspectionGlanceTest.php`
- Test: `tests/Feature/Dashboard/FleetGlanceTest.php`
- Test: `tests/Feature/Dashboard/AutomationsGlanceTest.php`

**Inspection:** presentations `bar`,`pie`,`text`; default `bar`; snapshot. Slices: `In progress` = `InspectionRun` in view (same franchise filter as reports: run’s job in `franchiseIds`, or no job); `Submitted` = `InspectionReport` `archived_at` null; `Archived` = `archived_at` set. Copy the `where` from `InspectionReportsController::index`. Empty sentence: `No inspections in reach.`

**Fleet:** presentations `pie`,`bar`,`text`; default `pie`; snapshot. `VehicleVisibility::constrain` + group by `status`. Slices in `VehicleStatus::values()` order, empty-at-zero once any vehicle exists. Empty: `No vehicles in reach.` No money keys.

**Automations:** presentations `pie`,`bar`,`text`; default `pie`; snapshot. Last **100** `AutomationRun` rows for the org by `started_at` desc; slices `Succeeded` vs `Failed` (`status === 'failed'` vs the rest that are not `failed` — treat `succeeded` as succeeded, `matched`/`skipped` as neither failed nor the healthy slice: **two slices only: succeeded + failed**. Skip `matched`/`skipped` in the pie, or fold non-failed into Succeeded. Spec: “succeeded vs failed”. Count `status === 'succeeded'` vs `status === 'failed'`. Waiting: `AutomationEvent` `delivered_at` null. Idle (no runs): sentence `No automations have run.` plus waiting if > 0. No franchise filter.

- [ ] **Step 1:** Write the three failing test files (reach for Inspection/Fleet; idle sentence for Automations; snapshot week=year).

- [ ] **Step 2:** `php artisan test --filter=InspectionGlanceTest --filter=FleetGlanceTest` is wrong (second filter wins). Run three commands. FAIL.

- [ ] **Step 3: Implement the three classes and register them.**

- [ ] **Step 4:** PASS. Commit: `Add Inspection, Fleet, and Automations dashboard glances.`

---

### Task 6: Email glance (unread only)

**Files:**
- Modify: `app/Support/Email/MailboxReader.php` — add `unreadCount(Mailbox $mailbox): int`
- Modify: `RecordingMailboxReader` — wrap `unreadCount` in `$this->recording(...)`
- Modify: `GmailMailboxReader`, `WorkspaceMailboxReader`, `MicrosoftMailboxReader`, `ImapMailboxReader`
- Modify test fakes that `implements MailboxReader`:
  - `tests/Feature/Api/MailboxHealthTest.php` `ScriptedMailboxReader`
  - `tests/Feature/Api/MailboxMessagesApiTest.php` `FakeMailboxReader`
  - `tests/Feature/MailboxWatchTest.php` `WatchedInbox`
- Create: `app/Support/Dashboard/Glances/EmailGlance.php`
- Modify: `GlanceRegistry`
- Test: `tests/Feature/Dashboard/EmailGlanceTest.php`

**Interfaces:**
- Produces: `MailboxReader::unreadCount(Mailbox $mailbox): int` — provider mailbox-level unread, **not** a message list. Throws `CannotReadMailbox` when the mailbox cannot be asked.
- Produces: Email glance key `email`; presentations `bar`,`pie`,`text`; default `bar`; snapshot
- Readable ∩ in view only (`MailboxAccess::readable` then `inView`). Do not call the provider when the list is empty.
- No grants: `empty`, sentence `No inbox is assigned to your role.`
- Unreadable mailbox: omit from slices; name it in `sentences`. Others still draw.

Gmail / Workspace: `GET {API}/profile`, read `messagesUnread` (int). Reuse the existing authenticated HTTP helper on `GmailMailboxReader` — grep for the method that already hits `{API}/messages`. Do not list messages.

IMAP: `STATUS INBOX (UNSEEN)`.

Microsoft: Graph inbox `unreadItemCount` on the well-known inbox folder. If the Graph helper has no such call yet, throw `CannotReadMailbox` with a sentence rather than listing mail.

Do **not** edit `MailboxesController`, grant tables, or OAuth.

- [ ] **Step 1:** Add `unreadCount` to the interface and fakes returning `0` so existing mail tests still compile. Run `php artisan test --filter=MailboxHealthTest`. Expected: PASS (fakes compile). If FAIL because of missing methods, add them.

- [ ] **Step 2: Failing EmailGlance tests**

```php
public function test_no_inbox_grant_is_a_sentence_and_does_not_hit_the_reader(): void
{
    $user = $this->userWithPermissions(['email.view']);
    $payload = app(EmailGlance::class)->resolve($user, DashboardPeriod::for($user), [])->toArray();
    $this->assertTrue($payload['data']['empty']);
    $this->assertSame('No inbox is assigned to your role.', $payload['data']['sentence']);
}

public function test_one_bar_per_readable_mailbox(): void
{
    // Provision a mailbox the user may read (copy MailboxesApiTest grant setup).
    // Bind a ScriptedMailboxReader that returns unreadCount = 4 for that id.
    $payload = app(EmailGlance::class)->resolve($user, DashboardPeriod::for($user), [])->toArray();
    $this->assertSame(4, $payload['data']['slices'][0]['total']);
}
```

The second test should use the existing scripted reader, not a live Google call.

- [ ] **Step 3:** `php artisan test --filter=EmailGlanceTest`. FAIL (class missing).

- [ ] **Step 4: Implement EmailGlance + real unreadCount on readers.** Catch `CannotReadMailbox` per mailbox.

- [ ] **Step 5:** EmailGlanceTest PASS. Existing mailbox tests still PASS.

- [ ] **Step 6: Commit** `Show unread mail per inbox on the dashboard without storing messages.`

---

### Task 7: HTTP dashboard

**Files:**
- Modify: `app/Http/Controllers/Api/DashboardController.php`
- Modify: `routes/api.php` — keep `GET dashboard`, `PUT dashboard/layout`; **keep** `GET dashboard/catalogue` returning `{ "glances": [ {key,label,allowed_presentations,default_presentation} ] }` so an old client does not 404 mid-deploy. Do not return widgets.
- Rewrite: `tests/Feature/Api/DashboardTest.php` to the new payload (`glances` not `panels`)
- Test additions in the same file

**Interfaces:**
- `GET /api/v1/dashboard` → `{ period, period_starts_on, period_ends_on, glances: GlancePayload[] }`
- `PUT /api/v1/dashboard/layout` body `{ period?, glances: [{key, presentation?, options?}] }`
- Stores `dashboard.period` and `dashboard.glances`
- Ignores `surface`
- Omitting a reachable key appends it with default presentation
- Unknown / unreachable keys kept in the stored list, not drawn
- `dashboard.widgets` ignored except one copy of `jobs.revenue` `options.statuses` → jobs glance `earned_statuses` when `dashboard.glances` is absent
- `asDeclared`: presentation must be in `allowedPresentations` or dropped to default; `earned_statuses` intersect current job statuses

`layoutFor`: if `dashboard.glances` missing, default order = `availableTo` keys, presentations = each default. Then overlay saved order with the same algorithm as `panelsInSavedOrder` (honour saved, append new).

- [ ] **Step 1: Rewrite DashboardTest** so the old `panels` / `jobs.by_status` assertions fail against the still-old controller (they already expect `panels` — change them to `glances` / `jobs` **first**; run; they fail because the controller still returns `panels`).

```php
public function test_a_branch_user_sees_only_their_own_franchises_jobs(): void
{
    // … same setup …
    $glances = $this->as($branchUser)->getJson('/api/v1/dashboard')->assertOk()->json('glances');
    $jobs = collect($glances)->firstWhere('key', 'jobs');
    $this->assertSame(1, array_sum(array_column($jobs['data']['slices'], 'total')));
}

public function test_inventory_view_returns_the_inventory_box(): void
{
    $limited = $this->userWithPermissions(['inventory.view']);
    $keys = array_column($this->as($limited)->getJson('/api/v1/dashboard')->assertOk()->json('glances'), 'key');
    $this->assertSame(['inventory'], $keys);
}

public function test_omitting_a_reachable_glance_appends_it(): void
{
    $this->as($this->alpha['admin'])->putJson('/api/v1/dashboard/layout', [
        'glances' => [['key' => 'jobs', 'presentation' => 'pie']],
    ])->assertOk();
    $keys = array_column($this->as($this->alpha['admin'])->getJson('/api/v1/dashboard')->json('glances'), 'key');
    $this->assertSame('jobs', $keys[0]);
    $this->assertContains('hr', $keys);
    $this->assertContains('inventory', $keys);
}

public function test_old_dashboard_widgets_do_not_500_and_copy_earned_statuses(): void
{
    $this->inTenant(fn () => User::withoutGlobalScopes()->find($this->alpha['admin']->id)
        ->forceFill(['preferences' => ['dashboard.widgets' => [
            ['key' => 'jobs.revenue', 'options' => ['statuses' => ['completed']]],
        ]]])->save());
    $jobs = collect($this->as($this->alpha['admin'])->getJson('/api/v1/dashboard')->assertOk()->json('glances'))
        ->firstWhere('key', 'jobs');
    $this->assertSame(['completed'], $jobs['options']['earned_statuses'] ?? null);
}

public function test_surface_on_a_layout_save_is_ignored(): void
{
    $this->as($this->alpha['admin'])->putJson('/api/v1/dashboard/layout', [
        'surface' => 'hr',
        'glances' => [['key' => 'jobs', 'presentation' => 'pie']],
    ])->assertOk();
}

public function test_snapshot_glances_do_not_change_with_period(): void
{
    $this->job($this->alpha['franchise']);
    $this->as($this->alpha['admin'])->putJson('/api/v1/dashboard/layout', ['period' => 'week', 'glances' => []]);
    $week = collect($this->as($this->alpha['admin'])->getJson('/api/v1/dashboard')->json('glances'))->firstWhere('key', 'jobs')['data']['slices'];
    $this->as($this->alpha['admin'])->putJson('/api/v1/dashboard/layout', ['period' => 'year', 'glances' => []]);
    $year = collect($this->as($this->alpha['admin'])->getJson('/api/v1/dashboard')->json('glances'))->firstWhere('key', 'jobs')['data']['slices'];
    $this->assertSame($week, $year);
}
```

Delete catalogue-hides-widgets tests or retarget them at `GET /dashboard/catalogue` `glances` keys.

Delete tests that 422 on an unseeable widget key: omitting is append, naming `customers` without permission stores but does not render.

- [ ] **Step 2:** `php artisan test --filter=DashboardTest`. FAIL (payload still `panels`).

- [ ] **Step 3: Rewrite `DashboardController`.** Inject `GlanceRegistry`. Remove `ORDERABLE` / `saveOrder` for `hr` (HR order is gone). Keep comments explaining why a save is not audited (§2.1 category 6).

- [ ] **Step 4:** PASS.

- [ ] **Step 5: Commit** `Serve one dashboard glance per reachable module and remember only order, period, and chart type.`

---

### Task 8: HTTP HR dashboard

**Files:**
- Modify: `app/Http/Controllers/Api/HrDashboardController.php`
- Rewrite: `tests/Feature/Api/HrDashboardTest.php` — one glance, not five panels; keep the founder / reach / revoked tests against `glances[0]` or a single object

**Interfaces:**
- `GET /api/v1/hr/dashboard` → `{ period, period_starts_on, period_ends_on, glance: GlancePayload }` (singular). Same `HrGlance::resolve` as `/dashboard`. 403 without `hr.view` (existing `authorize('viewAny', User::class)`).
- Does not apply `dashboard.order['hr']`.

If `GlanceRegistry::find('hr', $user)` is null (kill switch), return `{ glance: null }` 200 rather than 500.

- [ ] **Step 1:** Change HrDashboardTest to read `glance` / `data.slices` / `data.sentences`. Run. FAIL.

- [ ] **Step 2: Implement.**

- [ ] **Step 3:** `php artisan test --filter=HrDashboardTest`. PASS. Also `DashboardTest` still PASS.

- [ ] **Step 4: Completeness** — add to `GlanceRegistryTest`:

```php
public function test_every_sidebar_tab_except_dashboard_has_a_glance(): void
{
    $registry = app(\App\Support\Modules\ModuleRegistry::class);
    $tabs = collect($registry->all())
        ->filter(fn ($m) => $m->rolesGroup === \App\Support\Modules\ModuleDefinition::ROLES_TAB && $m->key !== 'dashboard')
        ->keys()->sort()->values()->all();
    $glances = collect(app(GlanceRegistry::class)->all())->map(fn ($g) => $g->key())->sort()->values()->all();
    $this->assertSame($tabs, $glances);
}
```

Need `GlanceRegistry::all(): Glance[]` unfiltered. FAIL if a tab is missing; add it. PASS.

- [ ] **Step 5: Commit** `Drive the Human Resources dashboard chip from the same HR glance.`

---

### Task 9: Client dashboard (no picker, period, glances)

**Files:**
- Modify: `client/lib/screens/dashboard_screen.dart`
- Modify: `client/lib/core/dashboard_panels.dart` — `dashboardPanelIcon` keys are now module keys (`hr`, `jobs`, …). Keep prefix fallback.
- Modify: `client/test/dashboard_screen_test.dart`
- Modify: `client/test/dashboard_cards_test.dart` (payload + drop “Choose panels” tests; keep equal-height rows)
- Modify: `client/VERSION` → `0.0.0076`
- Modify: `client/lib/core/version_history.dart` — newest row `0.0.0076`
- Test: `client/test/version_history_test.dart` already asserts VERSION matches first release

**UI:**
- Fetch only `GET /dashboard`. Stop calling `/dashboard/catalogue`.
- Header: title, period chips Week/Month/Quarter/Year (selected from `period`), refresh. **No** tune / Choose panels.
- `DashboardGrid` on `glances`. Title = `label`. Icon = `dashboardPanelIcon(key)`.
- Coming soon: `Tag('Coming soon')` (same `Tag` as `ComingSoonScreen`), `DashboardNote(data.sentence)`, no presentation control.
- Empty: `DashboardNote` from `data.sentence` or first of `data.sentences`.
- Otherwise: for this task, render `sentences` as text (charts are Task 10). Still send `presentation` on save.
- Reorder: PUT `{ glances: [{key, presentation, options}] }` in new order. Optimistic move, reload on failure (same as today).
- Period chip: PUT `{ period, glances: current }`.
- Cards are not `InkWell` / `GestureDetector` to another module. Drag handle stays.

Replace `dashboard_screen_test` “Joined in the last 30 days” with: HR sentences from the glance payload appear; period chips exist; `Choose panels` is absent; tapping a card does not change routes (no `Navigator.push`).

Stub handler: `GET /dashboard` returns a spec-shaped body. `PUT /dashboard/layout` 200.

- [ ] **Step 1:** Rewrite the Dart tests. `cd client; flutter test test/dashboard_screen_test.dart` FAIL.

- [ ] **Step 2: Implement the screen.**

- [ ] **Step 3:** `flutter test test/dashboard_screen_test.dart test/dashboard_cards_test.dart test/version_history_test.dart`. PASS.

- [ ] **Step 4: Commit** (VERSION in this commit)

```
git commit -m "Draw the dashboard as one box per module, with one period and no panel picker."
```

---

### Task 10: Charts and presentation switcher

**Files:**
- Create: `client/lib/widgets/dashboard_chart.dart`
- Modify: `dashboard_screen.dart` — if `presentation` is pie/bar/line/timeline and `data` has slices/series and `empty` is not true, draw the chart **above** `sentences`; text presentation draws only sentences
- Modify: card header `controls` — a compact `PopupMenuButton` or `SegmentedButton` of `allowed_presentations` + implied `text` when allowed is non-empty. Selecting one PUTs the layout. Hidden when `allowed_presentations` is empty.
- Test: `client/test/dashboard_chart_test.dart`

**Chart widget:**

```dart
class DashboardChart extends StatelessWidget {
  const DashboardChart({
    super.key,
    required this.presentation, // pie | bar | line | timeline
    this.slices,
    this.series,
  });
  // CustomPaint. Colours from Theme.of(context).colorScheme, not hex.
}
```

- Pie: slices as annular sectors; skip drawing when all totals are 0 (parent should have used empty sentence).
- Bar: one bar per slice, equal width.
- Line / timeline: `series[0].points`; x = `at`, y = `value`. Timeline may use the same painter with point markers.

No new pubspec dependency unless CustomPaint is genuinely not enough; if you add one, it must be already compatible with Flutter web.

- [ ] **Step 1:**

```dart
testWidgets('a pie is drawn for slices and not for coming soon', (tester) async {
  await _pumpDashboard(tester, _glances(presentation: 'pie', slices: [
    {'label': 'scheduled', 'total': 2},
    {'label': 'completed', 'total': 1},
  ]));
  expect(find.byType(DashboardChart), findsOneWidget);
});

testWidgets('coming soon has no chart and no presentation control', (tester) async {
  await _pumpDashboard(tester, _comingSoon());
  expect(find.byType(DashboardChart), findsNothing);
  expect(find.byTooltip('Choose panels'), findsNothing);
  expect(find.text('Coming soon'), findsOneWidget);
});
```

- [ ] **Step 2:** FAIL. **Step 3:** Implement. **Step 4:** `flutter test test/dashboard_chart_test.dart test/dashboard_screen_test.dart`. PASS.

- [ ] **Step 5: Commit** `Draw dashboard glances as pie, bar, or line from the server’s slices.`

---

### Task 11: HR chip client + leftover tests

**Files:**
- Modify: `client/lib/screens/hr/dashboard_tab.dart` — one `DashboardCard` from `GET /hr/dashboard` `glance`. Period chips writing the same PUT `/dashboard/layout` `{period}` (include current `dashboard.glances` from session preferences if you have them; otherwise PUT `{period, glances: [{key: 'hr', presentation: glance.presentation}]}` and let the server append the rest).
- Modify: `client/test/hr_dashboard_test.dart` — one card titled Human Resources; sentences for the one-person org; no five widget titles.
- Modify: `client/test/hr_screen_test.dart` if it stubs five HR dashboard panels.
- Grep `client/test` and `tests/Feature` for `jobs.by_status`, `Choose panels`, `dashboard/catalogue`, `panels` on dashboard — fix leftovers.

**HR chip:** no drag (one card). Presentation switcher same as main dashboard (same preference). `DashboardChart` reused.

- [ ] **Step 1:** Rewrite `hr_dashboard_test.dart`. FAIL.

- [ ] **Step 2: Implement `dashboard_tab.dart`.**

- [ ] **Step 3:** `flutter test test/hr_dashboard_test.dart test/hr_screen_test.dart test/dashboard_cards_test.dart`. PASS.

- [ ] **Step 4:** `php artisan test --filter=Dashboard` and `--filter=HrDashboard` and `--filter=Glance`. PASS.

- [ ] **Step 5:** `cd client; flutter analyze`. No new issues in files you touched.

- [ ] **Step 6: Commit** `Show the Human Resources dashboard chip as the same one glance.`

---

### Task 12: STATUS and board (no deploy)

**Files:**
- Modify: `docs/EZBIZI-STATUS.md` — Phase 4 sentence: the widget picker is replaced by module glances (spec path). Do not invent test counts; leave the `numbers` block to deploy.
- Work board `dashboard-module-boxes`: tick Build; leave Deploy unchecked; `waiting_on` = owner to deploy.

- [ ] **Step 1:** Update STATUS in the same words as the spec’s success line, not a marketing rewrite.

- [ ] **Step 2: Commit** `Record that the dashboard is one glance per reachable module.`

- [ ] **Step 3:** Do **not** run `scripts/deploy.sh`.

---

## Self-review (plan vs spec)

| Spec | Task |
|---|---|
| One box per granted module, including coming soon | 1, 7 |
| Coming soon = sentence, no switcher | 1, 9, 10 |
| Chart first; empty = sentence | 2–6, 10 |
| Tap stays on Dashboard | 9 |
| Sidebar order, drag, cannot hide | 7, 9 |
| HR chip = same HR box | 4, 8, 11 |
| One calendar period; snapshots ignore it | 1, 2, 7 |
| Jobs pie, Customers line, Inspection bar, Marketing sentence, Fleet pie, HR bar, Email unread, Automations healthy vs failed | 2–6 |
| Revenue on Jobs, `jobs.view` | 2 |
| Not boxes: Dashboard / Organization / Settings / Roles | 1 completeness, 7 |
| Email: no mirror; unread via reader | 6 |
| `dashboard.widgets` ignored | 7 |
| Tests listed in spec §14 | 1–8, 9–11 |
)
