# Jobs money visibility (Billing Phase 1, part 1): Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** A "Financials" toggle in Roles & Permissions decides who sees money in Jobs, and
people with it see each Workiz job's live money on the job and can filter and total the
list by it.

**Architecture:**
- `jobs.financials` is declared as an extra action on the Jobs module, the same way as
  Fleet's and Inventory's. `JobsController` and `JobLinesController` check it once per
  request: they omit money keys and refuse money writes without it.
- `WorkizMoney` derives a job's money from its `workiz_jobs.payload` mirror row, which the
  Workiz sync refreshes every minute. Nothing is copied.
- The jobs list filters on the same derivation through a correlated `whereExists`, and
  totals it per currency.

**Tech Stack:** Laravel 12 / PHP 8.4 / MySQL 8.4 (JSON functions); Flutter client.

**Spec:** `docs/superpowers/specs/2026-09-29-jobs-billing-design.md` (Phase 1, sections 1–3).
Estimates (section 4) and the A1 estimate import (section 5) get their own plans.

## Global Constraints

- Permission key `jobs.financials`. The refusal sentence, verbatim: `Money on jobs is hidden for your role.`
- Without the toggle, money keys are ABSENT from payloads, never null.
- Money is integer minor units. Dollars are converted by `round(x * 100)`, never a cast.
- Currencies are never summed together.
- `due_on` is a wall date (`Y-m-d`), never an instant.
- The reader's today comes from the client's `today` parameter, never the server clock.
- Workiz money is read-only in EzBizi (Jose, decision 2).
- Test DB: `DB_DATABASE=ezbizi_testing_jobs php -d memory_limit=1G vendor/bin/phpunit …`.
  Client: `cd client && flutter test …`.
- Every new test is sabotage-verified: break the rule it guards, watch exactly that test go
  red (`--teamcity` gives the line), then restore.
- Commits are path-limited (`git commit -F msg -- <paths>`) and end with
  `Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>`.
- Verify each commit in a separate call. Never `git stash` and never `--amend`.

---

### Task 1: The Financials toggle gates money in the jobs API

**Files:**
- Modify: `app/Providers/ModuleServiceProvider.php`, the `jobs` ModuleDefinition's
  `actions:` (`[...ModuleDefinition::DEFAULT_ACTIONS, 'export']`) and the docblock above it
- Modify: `app/Http/Controllers/Api/JobsController.php`: `present()`, `index()`, `show()`,
  `store()`, `update()`
- Modify: `app/Http/Controllers/Api/JobLinesController.php`: every action
- Test: `tests/Feature/Api/JobFinancialsTest.php` (new)

**Interfaces:**
- Produces:
  - `JobsController::present(Job $job, bool $detailed = false, bool $money = false): array`
  - the private const `JobsController::MONEY_HIDDEN = 'Money on jobs is hidden for your role.'`
  - the permission key `jobs.financials`

- [ ] **Step 1: Write the failing tests**

```php
<?php

namespace Tests\Feature\Api;

use App\Models\Customer;
use App\Models\Job;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/**
 * Jose, 2026-09-29: money in Jobs is "decided on roles y permissions as a
 * financials toggle". Without `jobs.financials` no amount reaches a person, and
 * none can be written. Money never travels with edit.
 */
class JobFinancialsTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    public function test_without_the_toggle_no_amount_reaches_the_list_or_the_job(): void
    {
        $alpha = $this->provisionTenant('Alpha');
        $job = $this->aPricedJob($alpha);
        $dispatcher = $this->userWithRole($alpha, 'Dispatcher', ['jobs.view', 'jobs.edit']);

        $row = $this->signedInAs($dispatcher)->getJson('/api/v1/jobs')->assertOk()->json('data.0');
        $this->assertArrayNotHasKey('amount', $row);

        $detail = $this->signedInAs($dispatcher)->getJson("/api/v1/jobs/{$job}")->assertOk()->json('data');
        $this->assertArrayNotHasKey('amount', $detail);
        $this->assertArrayNotHasKey('lines', $detail);
    }

    /** The control: the same person with the toggle sees it all. */
    public function test_the_toggle_shows_the_money(): void
    {
        $alpha = $this->provisionTenant('Alpha');
        $job = $this->aPricedJob($alpha);
        $manager = $this->userWithRole($alpha, 'Manager', ['jobs.view', 'jobs.edit', 'jobs.financials']);

        $this->signedInAs($manager)->getJson('/api/v1/jobs')->assertOk()
            ->assertJsonPath('data.0.amount.minor', 12345);
        $this->signedInAs($manager)->getJson("/api/v1/jobs/{$job}")->assertOk()
            ->assertJsonPath('data.amount.minor', 12345)
            ->assertJsonPath('data.lines', []);
    }

    public function test_without_the_toggle_money_cannot_be_written_but_edit_still_works(): void
    {
        $alpha = $this->provisionTenant('Alpha');
        $job = $this->aPricedJob($alpha);
        $dispatcher = $this->userWithRole($alpha, 'Dispatcher', ['jobs.view', 'jobs.edit']);

        $this->signedInAs($dispatcher)->patchJson("/api/v1/jobs/{$job}", ['amount_minor' => 1])
            ->assertForbidden()->assertJsonPath('message', 'Money on jobs is hidden for your role.');
        $this->signedInAs($dispatcher)->postJson("/api/v1/jobs/{$job}/lines", [])
            ->assertForbidden()->assertJsonPath('message', 'Money on jobs is hidden for your role.');

        $this->signedInAs($dispatcher)->patchJson("/api/v1/jobs/{$job}", ['title' => 'Renamed'])
            ->assertOk()->assertJsonPath('data.title', 'Renamed');
    }

    private function aPricedJob(array $tenant): string
    {
        return $this->inTenant($tenant['org'], function () use ($tenant) {
            $customer = Customer::create([
                'owning_franchise_id' => $tenant['franchise']->id,
                'number' => 'C-'.bin2hex(random_bytes(3)),
                'name' => 'A Customer',
            ]);

            return Job::create([
                'franchise_id' => $tenant['franchise']->id,
                'customer_id' => $customer->id,
                'number' => 'J-'.bin2hex(random_bytes(3)),
                'title' => 'A job',
                'status' => 'scheduled',
                'amount_minor' => 12345,
                'currency' => 'USD',
            ])->id;
        });
    }
}
```

- [ ] **Step 2: Run them to verify they fail**

Run: `DB_DATABASE=ezbizi_testing_jobs php -d memory_limit=1G vendor/bin/phpunit tests/Feature/Api/JobFinancialsTest.php`
Expected: 2 FAIL (`amount` present for the dispatcher; the PATCH returns 200). The control passes.

- [ ] **Step 3: Declare the toggle.** In `ModuleServiceProvider`'s `jobs` block:

```php
                actions: [...ModuleDefinition::DEFAULT_ACTIONS, 'export', 'financials'],
```

Add to its docblock, in the Fleet and Inventory wording:

```php
             *   financials — see and change prices, and every amount on a job (Jose,
             *                2026-09-29: "a financials toggle"). Money never travels
             *                with edit.
```

- [ ] **Step 4: Gate `JobsController`.**

Add the constant:

```php
    /** Jose, 2026-09-29: money in Jobs is a financials toggle on the role. */
    private const MONEY_HIDDEN = 'Money on jobs is hidden for your role.';
```

`present()` gains `bool $money = false`. Its base array builds `amount` only when
`$money`:

```php
            ...($money ? ['amount' => [
                'minor'    => $job->amount_minor,
                'currency' => $job->currency,
                'display'  => $job->amountForDisplay(),
            ]] : []),
```

In the detailed branch, `lines` goes behind `$money` the same way:
`...($money ? ['lines' => $job->lines->map(...)->all()] : [])`.

Compute `$money = $request->user()->hasPermission('jobs.financials');` once per action,
never in a property: the route keeps its controller instance in tests. Then:

- `index()`: `->map(fn (Job $j) => $this->present($j, money: $money))`.
- `show()`: add `Request $request` as the first parameter, then use
  `$this->present($job, detailed: true, money: $money)`.
- `store()` and `update()`: right after `validate()`, add the check below, and pass
  `money: $money` to their final `present()`.

```php
        abort_if(! $money && array_key_exists('amount_minor', $input), Response::HTTP_FORBIDDEN, self::MONEY_HIDDEN);
```

- [ ] **Step 5: Gate `JobLinesController`.** Add this as the first line of each action,
after its existing `authorize` call:

```php
        abort_unless($request->user()->hasPermission('jobs.financials'), Response::HTTP_FORBIDDEN, 'Money on jobs is hidden for your role.');
```

- [ ] **Step 6: Run them to verify they pass**

Run: the Step 2 command, plus `tests/Feature/Api/JobLinesTest.php tests/Feature/Api/JobListQueryCountTest.php`.
Expected: all pass. `JobLinesTest` signs in as administrator, who holds every permission.
If a test there signs in as a non-admin, add `jobs.financials` to that user's keys and say
so in the commit.

- [ ] **Step 7: Sabotage each rule, then restore**

Each of these must turn exactly its test red:
- `present()` ignores `$money`;
- the `amount_minor` `abort_if` is removed;
- the `JobLinesController` check is removed.

- [ ] **Step 8: Commit** the four files, with a message citing Jose's words.

---

### Task 2: The Jobs screens hide money without the toggle

**Files:**
- Modify: `client/lib/screens/jobs_screen.dart`, the `TableColumn('Amount', …)` in the
  columns list (about line 720)
- Modify: `client/lib/screens/jobs/job_lines_tab.dart`: add a `final bool money;`
  constructor field, used in `build()`
- Modify: `client/lib/screens/jobs/job_detail_panel.dart`, the `JobLinesTab(` call (about
  line 421)
- Test: `client/test/job_financials_test.dart` (new)

**Interfaces:**
- Consumes: the server omits `amount` and `lines` without `jobs.financials` (Task 1), and
  `Me.can('jobs.financials')` (`client/lib/core/session.dart:657`).
- Produces: `JobLinesTab({…, required bool money})`.

- [ ] **Step 1: Write the failing test.** Copy the harness from
`client/test/job_service_area_test.dart`: `_open` (lines 262–358), `_me` (366 on) and
`_json` (360). Two changes: take `List<String> permissions` as a parameter feeding the
`/me` stub's `'permissions'`, and drop the area arguments. Then add:

```dart
void main() {
  testWidgets('without Financials the list has no Amount column', (tester) async {
    await _open(tester, permissions: ['jobs.view', 'jobs.edit']);
    expect(find.text('Amount'), findsNothing);
  });

  testWidgets('with Financials the Amount column is there', (tester) async {
    await _open(tester, permissions: ['jobs.view', 'jobs.edit', 'jobs.financials']);
    expect(find.text('Amount'), findsOneWidget);
  });

  testWidgets('without Financials the Work section shows no Total', (tester) async {
    await _open(tester, permissions: ['jobs.view', 'jobs.edit']);
    await tester.tap(find.text(_jobs.first['title'] as String));
    await tester.pumpAndSettle();
    expect(find.text('Work'), findsOneWidget);   // the role and performer remain
    expect(find.text('Total'), findsNothing);
    expect(find.textContaining('Performed by'), findsOneWidget);
  });
}
```

In this file's `_jobs` fixture and detail stub, leave out `amount` and `lines`: that is
what the server now sends to a person without the toggle.

- [ ] **Step 2: Run it to verify it fails**

Run: `cd client && flutter test test/job_financials_test.dart`
Expected: "without Financials the list has no Amount column" FAILS, because the column is
always there. The Work test FAILS, because it shows `Total $0.00`.

- [ ] **Step 3: Implement.**

In `jobs_screen.dart`, add the Amount column only when the reader holds the toggle:

```dart
      if (widget.session.me!.can('jobs.financials'))
        TableColumn(
          'Amount',
          width: 110,
          numeric: true,
          cell: (_, job) => Text(listAmount(job['amount']) ?? '—'),
        ),
```

In `job_lines_tab.dart`, add `required this.money` and `final bool money;`, and stop
`build()`'s children after the "Performed by" `Text` when `!money`:

```dart
        const SizedBox(height: 16),
        if (money) ...[
          for (final line in lines) _lineRow(context, line),
          // … every existing child from here to the end of the Column, unchanged …
        ],
```

In `job_detail_panel.dart`, pass `money: _me.can('jobs.financials'),` to `JobLinesTab(`.

- [ ] **Step 4: Run it to verify it passes,** then the whole client suite and analyze.

Run: `cd client && flutter test test/job_financials_test.dart && flutter test && flutter analyze`
Expected: all pass, and analyze reports no issues. `jobs_lines_test.dart` builds
`JobLinesTab` directly: pass `money: true` there, the only edit to an existing test, named
in the commit.

- [ ] **Step 5: Sabotage each gate, then restore.** Remove the `if (…can…)` on the
column, and separately the `if (money)` in the tab. Each must turn exactly its test red.

- [ ] **Step 6: Commit** the three screens and the new test, plus `jobs_lines_test.dart`
if it changed.

---

### Task 3: Workiz's money on a job

**Files:**
- Modify: `app/Models/Job.php`: a `workizJob(): HasOne` relation
- Create: `app/Support/Jobs/WorkizMoney.php`
- Modify: `app/Http/Controllers/Api/JobsController.php`: `money` on the detailed payload
- Test: `tests/Feature/Api/JobWorkizMoneyTest.php` (new)

**Interfaces:**
- Consumes: `JobsController::present(…, bool $money)` from Task 1.
- Produces:
  - `WorkizMoney::for(Job $job): ?array`, returning keys `source, currency, subtotal,
    total, due, paid, credit, due_on, state, lines`;
  - `WorkizMoney::state(int $total, int $due): string`, one of
    `none|paid|credit|partial|unpaid`;
  - `Job::workizJob()`.

- [ ] **Step 1: Write the failing tests**

```php
<?php

namespace Tests\Feature\Api;

use App\Models\Customer;
use App\Models\Job;
use App\Models\WorkizJob;
use App\Support\Jobs\WorkizMoney;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/**
 * A Workiz job's money, read live from the mirror (spec 2026-09-29 §2). Workiz
 * sends dollars as int or float; EzBizi speaks integer minor units.
 */
class JobWorkizMoneyTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    public function test_a_partly_paid_job(): void
    {
        [$alpha, $job] = $this->workizJob(['JobTotalPrice' => 2413, 'JobAmountDue' => 1206.5,
            'SubTotal' => 2413, 'PaymentDueDate' => '2026-10-02 00:00:00',
            'LineItems' => [['Name' => 'Sweep', 'Description' => '', 'Quantity' => 1, 'Price' => 2413, 'Cost' => 900, 'Taxable' => 0]]]);

        $money = $this->inTenant($alpha['org'], fn () => WorkizMoney::for(Job::findOrFail($job)));

        $this->assertSame(241300, $money['total']);
        $this->assertSame(120650, $money['due']);
        $this->assertSame(120650, $money['paid']);
        $this->assertSame('partial', $money['state']);
        $this->assertSame('2026-10-02', $money['due_on']);
        $this->assertSame([['name' => 'Sweep', 'description' => null, 'quantity' => 1.0, 'unit_price' => 241300, 'taxable' => false]], $money['lines']);
    }

    /** round, never cast: (int) (1.15 * 100) is 114 in binary floating point. */
    public function test_dollars_are_rounded_to_cents(): void
    {
        [$alpha, $job] = $this->workizJob(['JobTotalPrice' => 1.15, 'JobAmountDue' => 1.15]);
        $money = $this->inTenant($alpha['org'], fn () => WorkizMoney::for(Job::findOrFail($job)));
        $this->assertSame(115, $money['total']);
        $this->assertSame('unpaid', $money['state']);
    }

    public function test_the_states(): void
    {
        $this->assertSame('none', WorkizMoney::state(0, 0));
        $this->assertSame('paid', WorkizMoney::state(5000, 0));
        $this->assertSame('credit', WorkizMoney::state(5000, -500));
        $this->assertSame('partial', WorkizMoney::state(5000, 2000));
        $this->assertSame('unpaid', WorkizMoney::state(5000, 5000));
    }

    public function test_a_credit_is_shown_as_a_credit_not_a_negative_due(): void
    {
        [$alpha, $job] = $this->workizJob(['JobTotalPrice' => 100, 'JobAmountDue' => -25]);
        $money = $this->inTenant($alpha['org'], fn () => WorkizMoney::for(Job::findOrFail($job)));
        $this->assertSame([0, 2500, 10000], [$money['due'], $money['credit'], $money['paid']]);
    }

    public function test_a_job_with_no_workiz_row_has_no_workiz_money(): void
    {
        $alpha = $this->provisionTenant('Alpha');
        $job = $this->aJob($alpha);
        $this->assertNull($this->inTenant($alpha['org'], fn () => WorkizMoney::for(Job::findOrFail($job))));
    }

    public function test_the_job_carries_it_only_with_the_toggle(): void
    {
        [$alpha, $job] = $this->workizJob(['JobTotalPrice' => 100, 'JobAmountDue' => 100]);
        $this->signedInAs($alpha['admin'])->getJson("/api/v1/jobs/{$job}")->assertOk()
            ->assertJsonPath('data.money.state', 'unpaid');

        $dispatcher = $this->userWithRole($alpha, 'Dispatcher', ['jobs.view']);
        $this->assertArrayNotHasKey('money',
            $this->signedInAs($dispatcher)->getJson("/api/v1/jobs/{$job}")->assertOk()->json('data'));
    }

    /** @return array{0: array, 1: string} */
    private function workizJob(array $payload): array
    {
        $alpha = $this->provisionTenant('Alpha');
        $job = $this->aJob($alpha);
        $this->inTenant($alpha['org'], function () use ($alpha, $job, $payload) {
            $row = WorkizJob::create(['franchise_id' => $alpha['franchise']->id,
                'remote_id' => strtoupper(bin2hex(random_bytes(4))), 'status' => 'Submitted',
                'payload' => $payload, 'synced_at' => now()]);
            $row->job_id = $job;
            $row->save();
        });

        return [$alpha, $job];
    }

    private function aJob(array $tenant): string
    {
        return $this->inTenant($tenant['org'], function () use ($tenant) {
            $customer = Customer::create(['owning_franchise_id' => $tenant['franchise']->id,
                'number' => 'C-'.bin2hex(random_bytes(3)), 'name' => 'A Customer']);

            return Job::create(['franchise_id' => $tenant['franchise']->id, 'customer_id' => $customer->id,
                'number' => 'J-'.bin2hex(random_bytes(3)), 'title' => 'A job', 'status' => 'scheduled',
                'currency' => 'USD'])->id;
        });
    }
}
```

- [ ] **Step 2: Run them to verify they fail**

Run: `DB_DATABASE=ezbizi_testing_jobs php -d memory_limit=1G vendor/bin/phpunit tests/Feature/Api/JobWorkizMoneyTest.php`
Expected: FAIL with `Class "App\Support\Jobs\WorkizMoney" not found`.

- [ ] **Step 3: Add the relation** to `Job.php`, next to `serviceArea()`:

```php
    /** The Workiz mirror row this job was projected from, if any. */
    public function workizJob(): HasOne
    {
        return $this->hasOne(WorkizJob::class);
    }
```

- [ ] **Step 4: Create `app/Support/Jobs/WorkizMoney.php`**

```php
<?php

namespace App\Support\Jobs;

use App\Models\Job;

/**
 * A Workiz job's money, read live from its mirror row (spec 2026-09-29 §2).
 *
 * Workiz has no invoice endpoint: its invoice IS the job's money, refreshed into
 * `workiz_jobs.payload` every minute by the Workiz sync. Reading it here keeps
 * one copy, and Workiz stays the source of truth (Jose, decision 2). Nothing is
 * written.
 *
 * `Cost` on a line is left out: it is the company's own cost, and nobody has
 * asked to show it.
 */
final class WorkizMoney
{
    public static function for(Job $job): ?array
    {
        $payload = $job->workizJob?->payload;
        if (! is_array($payload)) {
            return null;
        }

        $total = self::minor($payload['JobTotalPrice'] ?? 0);
        $due = self::minor($payload['JobAmountDue'] ?? 0);
        $dueOn = substr((string) ($payload['PaymentDueDate'] ?? ''), 0, 10);

        return [
            'source' => 'workiz',
            'currency' => $job->currency,
            'subtotal' => self::minor($payload['SubTotal'] ?? 0),
            'total' => $total,
            'due' => max($due, 0),
            'paid' => max($total - max($due, 0), 0),
            'credit' => $due < 0 ? -$due : 0,
            'due_on' => $dueOn === '' ? null : $dueOn,
            'state' => self::state($total, $due),
            'lines' => array_map(static fn (array $l): array => [
                'name' => (string) ($l['Name'] ?? ''),
                'description' => ($l['Description'] ?? '') === '' ? null : (string) $l['Description'],
                'quantity' => (float) ($l['Quantity'] ?? 0),
                'unit_price' => self::minor($l['Price'] ?? 0),
                'taxable' => (bool) ($l['Taxable'] ?? false),
            ], array_values(array_filter($payload['LineItems'] ?? [], 'is_array'))),
        ];
    }

    /** Measured 2026-09-29: 102 jobs owe a negative amount, which is a credit. */
    public static function state(int $total, int $due): string
    {
        return match (true) {
            $due < 0 => 'credit',
            $total <= 0 => 'none',
            $due === 0 => 'paid',
            $due < $total => 'partial',
            default => 'unpaid',
        };
    }

    /** Rounded, never cast: (int) (1.15 * 100) is 114. */
    private static function minor(mixed $dollars): int
    {
        return is_numeric($dollars) ? (int) round((float) $dollars * 100) : 0;
    }
}
```

- [ ] **Step 5: Put it on the detailed payload** in `JobsController::present()`. In the
detailed branch, add `workizJob` to its `loadMissing([...])`, and next to `lines`:

```php
            ...($money ? ['money' => WorkizMoney::for($job)] : []),
```

- [ ] **Step 6: Run to verify they pass.** Use the Step 2 command, plus Task 1's test.
Expected: all PASS.

- [ ] **Step 7: Sabotage each rule, then restore.** Each must turn exactly its test red:
- `(int) ((float) $dollars * 100)` in `minor()` (the rounding test);
- `$due < 0` removed from `state()` (the states and credit tests);
- `Cost` kept in the lines (the partial test);
- the `$money` guard on `money` removed (the toggle test).

- [ ] **Step 8: Commit** `Job.php`, `WorkizMoney.php`, `JobsController.php` and the test.

---

### Task 4: The money filter, totals and row money on the jobs list

**Files:**
- Modify: `app/Http/Controllers/Api/JobsController.php`: `index()` validation, the
  `$filtered` closure, page-1 meta, the list eager loads, and `present()`'s base
- Test: `tests/Feature/Api/JobMoneyListTest.php` (new)

**Interfaces:**
- Consumes: `WorkizMoney::for()` and `Job::workizJob()` from Task 3; `MONEY_HIDDEN` and
  `present(…, bool $money)` from Task 1.
- Produces:
  - `GET /jobs?money=unpaid|partial|overdue|paid|credit`, where `overdue` requires `today`;
  - `meta.money_totals`, `{CUR: {total, paid, due, overdue}}`, always a JSON object;
  - rows carrying `money: {total, due, state, due_on}` or null.

- [ ] **Step 1: Write the failing tests**

```php
<?php

namespace Tests\Feature\Api;

use App\Models\Customer;
use App\Models\Job;
use App\Models\WorkizJob;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/** The list filtered and totalled by Workiz's money (spec 2026-09-29 §3). */
class JobMoneyListTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    private array $alpha;
    private array $ids = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->alpha = $this->provisionTenant('Alpha');
        $this->ids = [
            'unpaid'  => $this->job(['JobTotalPrice' => 100, 'JobAmountDue' => 100, 'PaymentDueDate' => '2026-10-05 00:00:00']),
            'late'    => $this->job(['JobTotalPrice' => 50, 'JobAmountDue' => 50, 'PaymentDueDate' => '2026-09-01 00:00:00']),
            'partial' => $this->job(['JobTotalPrice' => 200, 'JobAmountDue' => 80, 'PaymentDueDate' => '2026-10-05 00:00:00']),
            'paid'    => $this->job(['JobTotalPrice' => 300, 'JobAmountDue' => 0]),
            'credit'  => $this->job(['JobTotalPrice' => 40, 'JobAmountDue' => -10]),
        ];
    }

    public function test_each_state_filters(): void
    {
        $this->assertSame(['late', 'unpaid'], $this->filtered('unpaid'));
        $this->assertSame(['partial'], $this->filtered('partial'));
        $this->assertSame(['paid'], $this->filtered('paid'));
        $this->assertSame(['credit'], $this->filtered('credit'));
        $this->assertSame(['late'], $this->filtered('overdue'));
    }

    /** Faceted like the chips: the money filter is left out of its own totals. */
    public function test_totals_ignore_the_money_filter_and_keep_the_others(): void
    {
        $meta = $this->signedInAs($this->alpha['admin'])
            ->getJson('/api/v1/jobs?money=paid&today=2026-09-29')->assertOk()->json('meta');

        $this->assertSame(['total' => 69000, 'paid' => 46000, 'due' => 23000, 'overdue' => 5000],
            $meta['money_totals']['USD']);
    }

    public function test_a_row_carries_its_money(): void
    {
        $row = collect($this->signedInAs($this->alpha['admin'])->getJson('/api/v1/jobs')->json('data'))
            ->firstWhere('id', $this->ids['partial']);

        $this->assertSame(['total' => 20000, 'due' => 8000, 'state' => 'partial', 'due_on' => '2026-10-05'], $row['money']);
    }

    public function test_without_the_toggle_the_filter_is_refused_and_nothing_leaks(): void
    {
        $dispatcher = $this->userWithRole($this->alpha, 'Dispatcher', ['jobs.view']);

        $this->signedInAs($dispatcher)->getJson('/api/v1/jobs?money=unpaid')
            ->assertForbidden()->assertJsonPath('message', 'Money on jobs is hidden for your role.');

        $body = $this->signedInAs($dispatcher)->getJson('/api/v1/jobs')->assertOk()->json();
        $this->assertArrayNotHasKey('money_totals', $body['meta']);
        $this->assertArrayNotHasKey('money', $body['data'][0]);
    }

    public function test_totals_are_an_object_even_when_empty(): void
    {
        $raw = $this->signedInAs($this->alpha['admin'])
            ->getJson('/api/v1/jobs?search=nothing-matches-this')->assertOk()->getContent();

        $this->assertStringContainsString('"money_totals":{}', $raw);
    }

    /** @return list<string> the fixture names the filter returned, sorted */
    private function filtered(string $state): array
    {
        $ids = array_column($this->signedInAs($this->alpha['admin'])
            ->getJson("/api/v1/jobs?money={$state}&today=2026-09-29")->assertOk()->json('data'), 'id');
        $names = array_keys(array_intersect($this->ids, $ids));
        sort($names);

        return $names;
    }

    private function job(array $payload): string
    {
        return $this->inTenant($this->alpha['org'], function () use ($payload) {
            $customer = Customer::create(['owning_franchise_id' => $this->alpha['franchise']->id,
                'number' => 'C-'.bin2hex(random_bytes(3)), 'name' => 'A Customer']);
            $job = Job::create(['franchise_id' => $this->alpha['franchise']->id, 'customer_id' => $customer->id,
                'number' => 'J-'.bin2hex(random_bytes(3)), 'title' => 'A job', 'status' => 'scheduled',
                'currency' => 'USD']);
            $row = WorkizJob::create(['franchise_id' => $this->alpha['franchise']->id,
                'remote_id' => strtoupper(bin2hex(random_bytes(4))), 'status' => 'Submitted',
                'payload' => $payload, 'synced_at' => now()]);
            $row->job_id = $job->id;
            $row->save();

            return $job->id;
        });
    }
}
```

Totals check. Total is 100 + 50 + 200 + 300 + 40 = $690 (69000). Due counts positives
only: 100 + 50 + 80 = $230 (23000). Paid is total minus due, $460 (46000). Overdue is
`late` only, $50 (5000).

- [ ] **Step 2: Run them to verify they fail**

Run: `DB_DATABASE=ezbizi_testing_jobs php -d memory_limit=1G vendor/bin/phpunit tests/Feature/Api/JobMoneyListTest.php`
Expected: FAIL. The filter is ignored, so every state returns all five, and
`money_totals` and `money` are absent.

- [ ] **Step 3: Add the SQL fragments** to `JobsController`, next to `MONEY_HIDDEN`:

```php
    // Workiz's money in its mirror row. Dollars as JSON numbers; see WorkizMoney.
    private const DUE    = "CAST(JSON_UNQUOTE(JSON_EXTRACT(workiz_jobs.payload, '$.JobAmountDue')) AS DECIMAL(12,2))";
    private const TOTAL  = "CAST(JSON_UNQUOTE(JSON_EXTRACT(workiz_jobs.payload, '$.JobTotalPrice')) AS DECIMAL(12,2))";
    private const DUE_ON = "LEFT(JSON_UNQUOTE(JSON_EXTRACT(workiz_jobs.payload, '$.PaymentDueDate')), 10)";

    /** The same states as WorkizMoney::state(), in SQL. `overdue` takes today. */
    private const MONEY_STATES = [
        'credit'  => self::DUE.' < 0',
        'paid'    => self::TOTAL.' > 0 AND '.self::DUE.' = 0',
        'partial' => self::DUE.' > 0 AND '.self::DUE.' < '.self::TOTAL,
        'unpaid'  => self::DUE.' > 0 AND '.self::DUE.' >= '.self::TOTAL,
        'overdue' => self::DUE.' > 0 AND '.self::DUE_ON.' < ?',
    ];
```

- [ ] **Step 4: Validate and gate** in `index()`. Extend the existing `validate()`:

```php
            'money' => ['nullable', Rule::in(array_keys(self::MONEY_STATES))],
            'today' => ['nullable', 'required_if:when,upcoming,past', 'required_if:money,overdue', 'date_format:Y-m-d'],
```

Then compute the flag and refuse the filter without it:

```php
        $money = $request->user()->hasPermission('jobs.financials');
        abort_if($request->filled('money') && ! $money, Response::HTTP_FORBIDDEN, self::MONEY_HIDDEN);
```

- [ ] **Step 5: Filter.** `$filtered` gains `bool $withMoney = true`, and `$today` joins
its `use` list. Add after the service-area filter:

```php
                // Correlated, not a join: workiz_jobs has its own franchise_id, status and
                // scheduled_for, and the filters above name those columns unqualified.
                ->when($withMoney ? $request->query('money') : null, fn ($q, $state) => $q->whereExists(
                    fn ($w) => $w->selectRaw('1')->from('workiz_jobs')
                        ->whereColumn('workiz_jobs.job_id', 'jobs.id')
                        ->whereRaw(self::MONEY_STATES[$state], $state === 'overdue' ? [$today] : []),
                ))
```

- [ ] **Step 6: Totals, page 1, with the toggle only.** Inside `if ($jobs->currentPage() === 1)`:

```php
            if ($money) {
                $overdue = $today === null ? '0' : 'SUM(CASE WHEN '.self::DUE.' > 0 AND '.self::DUE_ON.' < ? THEN '.self::DUE.' ELSE 0 END)';
                // An OBJECT even when empty: an empty PHP array encodes as [].
                $meta['money_totals'] = (object) DB::table('workiz_jobs')
                    ->joinSub($filtered($status, $when, withMoney: false)->toBase()->select('jobs.id', 'jobs.currency'),
                        'j', 'j.id', '=', 'workiz_jobs.job_id')
                    ->selectRaw('j.currency, ROUND(SUM(GREATEST('.self::TOTAL.', 0)) * 100) AS total, '
                        .'ROUND(SUM(GREATEST('.self::DUE.', 0)) * 100) AS due, ROUND('.$overdue.' * 100) AS overdue',
                        $today === null ? [] : [$today])
                    ->groupBy('j.currency')->get()
                    ->mapWithKeys(fn ($r) => [$r->currency => [
                        'total' => (int) $r->total,
                        'paid' => max((int) $r->total - (int) $r->due, 0),
                        'due' => (int) $r->due,
                        'overdue' => (int) $r->overdue,
                    ]])->all();
            }
```

- [ ] **Step 7: Row money.** The list's `->with([...])` loads `'workizJob'` when
`$money`, as `...($money ? ['workizJob'] : [])`. Without it, each row loads its own and
`JobListQueryCountTest` goes red. In `present()`'s base, next to `amount`:

```php
            ...($money ? ['money' => ($m = WorkizMoney::for($job)) === null ? null
                : ['total' => $m['total'], 'due' => $m['due'], 'state' => $m['state'], 'due_on' => $m['due_on']]] : []),
```

- [ ] **Step 8: Run to verify they pass**

Run: Step 2's command, plus `tests/Feature/Api/JobListQueryCountTest.php tests/Feature/Api/JobListWindowTest.php tests/Feature/Api/JobServiceAreaTest.php`.
Expected: all PASS.

- [ ] **Step 9: Sabotage each rule, then restore.** Each must turn exactly its test red:
- `withMoney: false` dropped from the totals (the facet test);
- `(object)` removed (the empty-object test);
- the `abort_if` removed (the toggle test);
- `'workizJob'` removed from `with()` (`JobListQueryCountTest`);
- `<` changed to `<=` in `overdue` (the overdue case).

- [ ] **Step 10: Commit** `JobsController.php` and the test.

---

### Task 5: Job money leaves the data export without the toggle

The bulk export's `jobs` resource lists `amount_minor` and `currency`
(`app/Support/Data/ResourceMap.php:113`). The Exporter reads each resource's `columns` in
four places: `csv()` for the header and rows, the portability bundle (about line 158),
and the per-customer export (about line 249). `DataController` lists them for the export
picker at line 84. One helper serves all five.

**Files:**
- Modify: `app/Support/Data/ResourceMap.php`: the `jobs` entry, and a new
  `public static function columnsFor(array $definition, User $user): array`
- Modify: `app/Support/Data/Exporter.php`: every `$definition['columns']`,
  `ResourceMap::get('customers')['columns']` and `$jobs['columns']` read goes through
  `ResourceMap::columnsFor(…, $user)`
- Modify: `app/Http/Controllers/Api/DataController.php:84`, the same
- Test: `tests/Feature/Api/JobExportMoneyTest.php` (new)

**Interfaces:**
- Produces:
  - `ResourceMap::columnsFor(array $definition, User $user): array`;
  - an optional per-resource key `'money' => ['permission' => string, 'columns' => list<string>]`.

- [ ] **Step 1: Write the failing test.** It uses the same login helper as `DataTest`
(`asUser`, `tests/Feature/Api/DataTest.php:340`), copied in:

```php
    public function test_the_job_export_has_no_money_without_the_toggle(): void
    {
        $alpha = $this->provisionTenant('Alpha');
        $this->aPricedJob($alpha);   // as in JobFinancialsTest
        $exporter = $this->userWithRole($alpha, 'Exporter', ['jobs.view', 'jobs.export']);

        $header = strtok($this->asUser($exporter)->get('/api/v1/exports/jobs')->assertOk()->streamedContent(), "
");

        $this->assertStringContainsString('number', $header, 'The control: this IS the header row.');
        $this->assertStringNotContainsString('amount_minor', $header);
    }

    public function test_the_toggle_puts_the_money_back(): void
    {
        $alpha = $this->provisionTenant('Alpha');
        $this->aPricedJob($alpha);
        $exporter = $this->userWithRole($alpha, 'Exporter', ['jobs.view', 'jobs.export', 'jobs.financials']);

        $header = strtok($this->asUser($exporter)->get('/api/v1/exports/jobs')->assertOk()->streamedContent(), "
");

        $this->assertStringContainsString('amount_minor', $header);
    }
```

If the jobs resource's export permission is not `jobs.export`, use the key in its
`'export' => ['permission' => …]` entry, the one `DataController:200` checks.

- [ ] **Step 2: Run it to verify it fails**

Run: `DB_DATABASE=ezbizi_testing_jobs php -d memory_limit=1G vendor/bin/phpunit tests/Feature/Api/JobExportMoneyTest.php`
Expected: the first test FAILS, because `amount_minor` is in the header.

- [ ] **Step 3: Declare and filter.** Add to the `jobs` entry in `ResourceMap`:

```php
                // Jose, 2026-09-29: money in Jobs is a financials toggle.
                'money' => ['permission' => 'jobs.financials', 'columns' => ['amount_minor', 'currency']],
```

Then add the helper to `ResourceMap`:

```php
    /** The columns this person may take out: a resource's money only with its toggle. */
    public static function columnsFor(array $definition, User $user): array
    {
        $money = $definition['money'] ?? null;

        return $money === null || $user->hasPermission($money['permission'])
            ? $definition['columns']
            : array_values(array_diff($definition['columns'], $money['columns']));
    }
```

Replace the five reads with `ResourceMap::columnsFor($definition, $user)`: the `csv()`
header and its row mapping, the bundle, the customer export's customer and jobs, and
`DataController:84`.

- [ ] **Step 4: Run to verify it passes,** then `tests/Feature/Api/DataTest.php` whole.
Expected: all PASS.

- [ ] **Step 5: Sabotage, then restore.** Return `$definition['columns']`
unconditionally: exactly the first test goes red.

- [ ] **Step 6: Commit** the three files and the test. The Data export has no
MODULE-OWNERS row; the `jobs` entry is Jobs' own, as registry rows are. Say so in the
message.

---

### Task 6: Gates, hand-over, board

- [ ] **Step 1: Run the full backend gate.** Run every `tests/Feature/Api/Job*Test.php`
plus `RetentionTest`, `tests/Feature/Scheduling`, `tests/Feature/Workiz`,
`tests/Feature/Dashboard` and `Api/DashboardTest`. Also run every other file that creates
or reads jobs: `grep -rlE "api/v1/jobs|Job::create|Job::factory" tests`. Run them as two
parallel batches on `ezbizi_testing_jobs` and `ezbizi_testing_jobs2`, with the variables
set in the parent shell. Expected: all pass. The known machine failures are ErrorAudit and
Imap TLS.
- [ ] **Step 2: Run the client gate.** `cd client && flutter test && flutter analyze && flutter build web`.
- [ ] **Step 3: Send UI/UX the API** for the Money section on the job panel, the money
filter chips and the totals line, with keys and states verbatim. The layout goes to Jose
per the approval flow.
- [ ] **Step 4: Tell Reports and the deploy desk.** Reports: `ReportMap`'s job
`amount_minor` (line 150) should require `jobs.financials`, as the export now does. Desk: after the release only administrators see money in Jobs
until Jose turns Financials on for a role. That is a thing Jose must do, so it goes in the
report to him.
- [ ] **Step 5: Board.** Jobs card, step 15: note the SHAs and what is live.
