# Inventory General Tab Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.
>
> **Server only.** The General screen itself is UI/UX's to design and build (Jose, 2026-09-09: "ux"). Do not create, edit or register a client screen. Task 5 hands the brief over; that is the whole client-side involvement.
>
> **Do not deploy, `git push production`, or SSH.** The deploy desk owns releases, `client/VERSION` and `version_history.dart`.

**Goal:** Serve everything the Inventory General tab needs from one endpoint — a stock-health snapshot, a warnings list, and a movement series — without computing anything twice.

**Architecture:** The counts already exist inside `InventoryGlance`. They move once into `InventorySummary`, which returns plain facts; the glance and the new controller each shape those facts their own way, so the Dashboard box's payload does not change and the two can never disagree. Warnings and movement are new methods on the same class. Every query passes through `InventoryVisibility::constrain`.

**Tech Stack:** Laravel 13 · PHP 8.4 · MySQL 8 (`ezbizi_testing_inventory`) · `DashboardPeriod` · `ViewScope` · `InventoryVisibility` · bcmath for quantities

**Spec:** `docs/superpowers/specs/2026-09-09-inventory-general-tab-design.md`

## Global Constraints

- **Tests:** `DB_DATABASE=ezbizi_testing_inventory php -d memory_limit=1G vendor/bin/phpunit <path>`. Never the shared `ezbizi_testing`. Run explicit paths, not `--filter` — `--filter` still loads every test class, so another session's half-written test fails your run.
- **Exit code 0 does not mean the suite passed.** Read the JSON summary's `failed` and `errors` counts.
- Never assert on the key order of anything read back from the database.
- Quantities are `decimal(12,3)`. Compare with `bccomp($a, $b, 3)`, never `==` or `<=` on floats.
- Money is USD minor units, integer. No FX. `last_cost_minor` is a last-paid price, not a cost method.
- Calendar days in the **view's timezone**. The movement date columns (`received_on`, `issued_on`, `transferred_on`, `adjusted_on`, `fulfilled_on`) are `date`, not `timestamp` — they are already calendar days. Parse them in the period's timezone; never round-trip them through UTC.
- **No new permissions, no new tables, no new columns.** The chip is `inventory.view`; money is the existing `inventory.financials`.
- Money is omitted **server-side** when the caller lacks `inventory.financials`. Never sent and hidden in the client.
- `InventorySummary` does **not** manage tenancy. Callers enter `TenantContext` — same contract as `InventoryVisibility`.
- Refusal sentences come from `app/Support/Access/Refusal.php`. This feature adds none: its permission answer is an absent figure, not a 403.
- **Break every test on purpose once.** Delete the production line it names, watch that test and only that test go red, restore.
- **Committing:** resolve `BASE=$(git rev-parse HEAD)` and commit in the *same* tool call; private index via `GIT_INDEX_FILE`; check `git diff-tree --stat -r "$BASE" "$TREE"` **unfiltered**; `update-ref` with the old-value guard; verify with `git show <sha>:<path>`, never `git log`. Run `./scripts/index-landmines.sh` before **and** after — a private-index commit arms a landmine against its own new lines for every path it touches, so `git reset -q HEAD -- <path>` each committed path afterwards.
- Tell the deploy desk the SHA after each commit. Flag wire-shape, permission and migration changes explicitly; this plan has one wire-shape addition (a new endpoint) and no permission or migration changes.

---

## File structure

| Path | Responsibility |
|---|---|
| `app/Support/Inventory/InventorySummary.php` | **Create.** The one computation: counts, warnings, movement. Returns facts, not payloads. |
| `app/Support/Dashboard/Glances/InventoryGlance.php` | **Modify.** Calls `InventorySummary::counts()` instead of computing. Output shape unchanged. Shared edge — tell the Dashboard owner before touching. |
| `app/Http/Controllers/Api/InventorySummaryController.php` | **Create.** `GET /inventory/summary`; shapes facts into `health` / `warnings` / `movement`. |
| `routes/api.php` | **Modify.** One route line beside the other `inventory/…` routes (~line 531). Shared file — stage your line only. |
| `tests/Feature/Inventory/InventorySummaryTest.php` | **Create.** The unit-level facts. |
| `tests/Feature/Inventory/InventorySummaryApiTest.php` | **Create.** The endpoint: visibility, financials, empty state. |
| `tests/Feature/Dashboard/InventoryGlanceTest.php` | **Unchanged, and that is the point.** It is the control proving Task 1 moved no behaviour. |

---

## Task 1: Extract `InventorySummary::counts()`

The glance keeps its exact output. Only the arithmetic moves.

**Files:**
- Create: `app/Support/Inventory/InventorySummary.php`
- Modify: `app/Support/Dashboard/Glances/InventoryGlance.php:38-115`
- Test: `tests/Feature/Inventory/InventorySummaryTest.php` (create)
- Control: `tests/Feature/Dashboard/InventoryGlanceTest.php` (do not edit)

**Interfaces:**
- Produces: `InventorySummary::counts(User $user, ViewScope $scope): array` returning
  `['items'=>int, 'locations'=>int, 'open_purchase_orders'=>int, 'in_stock'=>int, 'low'=>int, 'out'=>int, 'stock_value_minor'=>int]`.
  **Always all seven keys**, including `stock_value_minor` — this method does not know about permissions. Stripping is the caller's job, so that there is exactly one place that decides and it is the one nearest the response.

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/Inventory/InventorySummaryTest.php`:

```php
<?php

namespace Tests\Feature\Inventory;

use App\Models\InventoryBalance;
use App\Models\InventoryItem;
use App\Models\InventoryLocation;
use App\Models\User;
use App\Support\Inventory\InventoryItemStatus;
use App\Support\Inventory\InventorySummary;
use App\Support\Scoping\ViewScope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/**
 * The one computation behind both the Dashboard box and the General tab.
 */
class InventorySummaryTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    public function test_an_item_with_no_reorder_point_is_never_low(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $admin = $this->userWithRole($tenant, 'Boss', ['inventory.view', 'inventory.financials']);

        $this->inTenant($tenant['org'], function () use ($tenant) {
            $location = InventoryLocation::factory()->create([
                'organization_id' => $tenant['org']->id,
                'company_id' => $tenant['company']->id,
            ]);

            // No reorder_at: one unit on hand must read as in stock, not low.
            $noPoint = InventoryItem::factory()->create([
                'organization_id' => $tenant['org']->id,
                'company_id' => $tenant['company']->id,
                'status' => InventoryItemStatus::Active,
                'reorder_at' => null,
            ]);

            InventoryBalance::create([
                'organization_id' => $tenant['org']->id,
                'item_id' => $noPoint->id,
                'location_id' => $location->id,
                'qty_on_hand' => '1.000',
            ]);
        });

        $counts = $this->countsFor($admin);

        $this->assertSame(1, $counts['in_stock']);
        $this->assertSame(0, $counts['low']);
        $this->assertSame(0, $counts['out']);
    }

    public function test_on_hand_at_the_reorder_point_is_low_not_in_stock(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $admin = $this->userWithRole($tenant, 'Boss', ['inventory.view', 'inventory.financials']);

        $this->inTenant($tenant['org'], function () use ($tenant) {
            $location = InventoryLocation::factory()->create([
                'organization_id' => $tenant['org']->id,
                'company_id' => $tenant['company']->id,
            ]);

            // At the point, not below it: the boundary is inclusive.
            $atPoint = InventoryItem::factory()->create([
                'organization_id' => $tenant['org']->id,
                'company_id' => $tenant['company']->id,
                'status' => InventoryItemStatus::Active,
                'reorder_at' => '5.000',
            ]);

            InventoryBalance::create([
                'organization_id' => $tenant['org']->id,
                'item_id' => $atPoint->id,
                'location_id' => $location->id,
                'qty_on_hand' => '5.000',
            ]);
        });

        $counts = $this->countsFor($admin);

        $this->assertSame(0, $counts['in_stock']);
        $this->assertSame(1, $counts['low']);
    }

    public function test_a_sibling_companys_items_are_absent_from_every_count(): void
    {
        $tenant = $this->provisionTenant('Alpha');

        $sibling = $this->inTenant($tenant['org'], function () use ($tenant) {
            return \App\Models\Company::create([
                'organization_id' => $tenant['org']->id,
                'name' => 'Beta Co',
                'is_active' => true,
            ]);
        });

        $local = $this->userWithRole(
            $tenant,
            'Local',
            ['inventory.view', 'inventory.financials'],
            onlyAt: $tenant['franchise'],
        );

        $this->inTenant($tenant['org'], function () use ($tenant, $sibling) {
            $theirs = InventoryLocation::factory()->create([
                'organization_id' => $tenant['org']->id,
                'company_id' => $sibling->id,
            ]);

            $theirItem = InventoryItem::factory()->create([
                'organization_id' => $tenant['org']->id,
                'company_id' => $sibling->id,
                'status' => InventoryItemStatus::Active,
                'reorder_at' => null,
            ]);

            InventoryBalance::create([
                'organization_id' => $tenant['org']->id,
                'item_id' => $theirItem->id,
                'location_id' => $theirs->id,
                'qty_on_hand' => '99.000',
            ]);
        });

        $counts = $this->countsFor($local);

        $this->assertSame(0, $counts['items']);
        $this->assertSame(0, $counts['in_stock']);
        $this->assertSame(0, $counts['stock_value_minor']);
    }

    /** @return array<string, int> */
    private function countsFor(User $user): array
    {
        return $this->inTenant(
            $user->organization,
            fn () => InventorySummary::counts($user, ViewScope::forUser($user)),
        );
    }
}
```

- [ ] **Step 2: Run it and watch it fail for the right reason**

```bash
DB_DATABASE=ezbizi_testing_inventory php -d memory_limit=1G vendor/bin/phpunit tests/Feature/Inventory/InventorySummaryTest.php
```

Expected: FAIL — `Class "App\Support\Inventory\InventorySummary" not found`. A failure naming anything else means the fixture is wrong, not the code; fix the fixture before writing the class.

- [ ] **Step 3: Create `InventorySummary` by moving the glance's arithmetic verbatim**

Create `app/Support/Inventory/InventorySummary.php`. The body of `counts()` and `qtyOnHandByItemId()` are lifted from `InventoryGlance::resolve()` and `InventoryGlance::qtyOnHandByItemId()` **without changing a comparison or a rounding rule** — that is what makes the control in Step 6 meaningful.

```php
<?php

namespace App\Support\Inventory;

use App\Models\InventoryBalance;
use App\Models\InventoryItem;
use App\Models\InventoryLocation;
use App\Models\InventoryPurchaseOrder;
use App\Models\User;
use App\Support\Scoping\ViewScope;
use Illuminate\Support\Collection;

/**
 * The facts behind the Dashboard's Inventory box and the General tab.
 *
 * Returns numbers, not payloads: each caller shapes them its own way. That is
 * deliberate — the box wants pie slices and sentences, the tab wants a flat
 * block, and the thing they must agree on is what "low" means, not how it is
 * drawn.
 *
 * Does NOT enter TenantContext and does NOT consult permissions. Callers do
 * both, the same contract as InventoryVisibility, so that the decision to
 * withhold money is made once and next to the response.
 */
final class InventorySummary
{
    /**
     * @return array{items:int,locations:int,open_purchase_orders:int,in_stock:int,low:int,out:int,stock_value_minor:int}
     */
    public static function counts(User $user, ViewScope $scope): array
    {
        $itemQuery = InventoryVisibility::constrain(InventoryItem::query(), $user, $scope);

        $items = (int) (clone $itemQuery)->count();
        $locations = (int) InventoryVisibility::constrain(InventoryLocation::query(), $user, $scope)->count();

        $openPurchaseOrders = (int) InventoryVisibility::constrain(InventoryPurchaseOrder::query(), $user, $scope)
            ->whereIn('status', [
                InventoryPurchaseOrderStatus::Draft,
                InventoryPurchaseOrderStatus::Open,
            ])
            ->count();

        $inStock = 0;
        $low = 0;
        $out = 0;
        $stockValueMinor = 0;

        if ($items > 0) {
            $activeItems = (clone $itemQuery)
                ->where('status', InventoryItemStatus::Active)
                ->get(['id', 'reorder_at', 'last_cost_minor']);

            $qtyByItem = self::qtyOnHandByItemId($activeItems->pluck('id'));

            foreach ($activeItems as $item) {
                $qty = $qtyByItem[$item->id] ?? '0.000';

                if (bccomp($qty, '0', 3) <= 0) {
                    $out++;
                } elseif ($item->reorder_at !== null
                    && bccomp($qty, (string) $item->reorder_at, 3) <= 0) {
                    $low++;
                } else {
                    $inStock++;
                }

                if ($item->last_cost_minor !== null) {
                    // Keep the fractional on-hand; round the product to nearest
                    // minor unit (half-up) rather than truncating qty first.
                    $stockValueMinor += (int) round(((float) $qty) * (int) $item->last_cost_minor);
                }
            }
        }

        return [
            'items' => $items,
            'locations' => $locations,
            'open_purchase_orders' => $openPurchaseOrders,
            'in_stock' => $inStock,
            'low' => $low,
            'out' => $out,
            'stock_value_minor' => $stockValueMinor,
        ];
    }

    /**
     * @param  Collection<int, string>  $itemIds
     * @return array<string, string> item id → qty decimal string (scale 3)
     */
    private static function qtyOnHandByItemId(Collection $itemIds): array
    {
        if ($itemIds->isEmpty()) {
            return [];
        }

        return InventoryBalance::query()
            ->whereIn('item_id', $itemIds->all())
            ->groupBy('item_id')
            ->selectRaw('item_id, SUM(qty_on_hand) as qty_on_hand')
            ->pluck('qty_on_hand', 'item_id')
            ->map(fn ($qty) => number_format((float) $qty, 3, '.', ''))
            ->all();
    }
}
```

- [ ] **Step 4: Run the new test**

```bash
DB_DATABASE=ezbizi_testing_inventory php -d memory_limit=1G vendor/bin/phpunit tests/Feature/Inventory/InventorySummaryTest.php
```

Expected: PASS, 3 tests.

- [ ] **Step 5: Rewrite `InventoryGlance::resolve()` to call it**

In `app/Support/Dashboard/Glances/InventoryGlance.php`, replace the counting block with a call. Delete the now-unused `qtyOnHandByItemId()` from the glance and the imports it alone used (`InventoryBalance`, `InventoryItemStatus`, `InventoryPurchaseOrderStatus`, `Collection`). Keep every emitted string identical.

```php
    public function resolve(User $user, DashboardPeriod $period, array $options): GlancePayload
    {
        return app(TenantContext::class)->run($user->organization, function () use ($user) {
            $scope = ViewScope::forUser($user);
            $counts = InventorySummary::counts($user, $scope);

            if ($counts['items'] === 0 && $counts['locations'] === 0) {
                return new GlancePayload(
                    key: $this->key(),
                    label: $this->label(),
                    presentation: $this->defaultPresentation(),
                    allowedPresentations: $this->allowedPresentations(),
                    snapshot: $this->snapshot(),
                    comingSoon: false,
                    options: [],
                    data: [
                        'empty' => true,
                        'sentence' => 'No items in reach.',
                    ],
                );
            }

            $items = $counts['items'];
            $locations = $counts['locations'];
            $openPos = $counts['open_purchase_orders'];

            $data = [
                'empty' => false,
                'slices' => [
                    ['label' => 'In stock', 'total' => $counts['in_stock']],
                    ['label' => 'Low', 'total' => $counts['low']],
                    ['label' => 'Out of stock', 'total' => $counts['out']],
                ],
                'sentences' => [
                    $items === 1 ? '1 item in reach.' : "{$items} items in reach.",
                    $locations === 1 ? '1 location in reach.' : "{$locations} locations in reach.",
                    $openPos === 1 ? '1 open purchase order.' : "{$openPos} open purchase orders.",
                ],
            ];

            if ($user->hasPermission('inventory.financials')) {
                $data['stock_value'] = [
                    ['currency' => 'USD', 'amount_minor' => $counts['stock_value_minor']],
                ];
            }

            return new GlancePayload(
                key: $this->key(),
                label: $this->label(),
                presentation: $this->defaultPresentation(),
                allowedPresentations: $this->allowedPresentations(),
                snapshot: $this->snapshot(),
                comingSoon: false,
                options: [],
                data: $data,
            );
        });
    }
```

Add `use App\Support\Inventory\InventorySummary;`.

- [ ] **Step 6: Run the control — the glance's own test, unedited**

```bash
DB_DATABASE=ezbizi_testing_inventory php -d memory_limit=1G vendor/bin/phpunit tests/Feature/Dashboard/InventoryGlanceTest.php
```

Expected: PASS, unchanged. **This is the proof the extraction moved no behaviour.** If it goes red, the move was not verbatim — revert and redo it rather than editing the glance test, which would destroy the only control you have.

- [ ] **Step 7: Break it on purpose**

Change `bccomp($qty, (string) $item->reorder_at, 3) <= 0` to `< 0` in `InventorySummary`. Run both files. Expected: `test_on_hand_at_the_reorder_point_is_low_not_in_stock` goes red and the glance test may too. Restore the `<=`, re-run, both green.

- [ ] **Step 8: Tell the Dashboard owner, then commit**

`InventoryGlance.php` is a shared edge. Message the Dashboard module's session (address from `docs/SESSIONS.md`) with the SHA after committing — its payload is unchanged, but its file is not.

```bash
# one tool call: resolve BASE and commit together
./scripts/index-landmines.sh
BASE=$(git rev-parse HEAD)
export GIT_INDEX_FILE="$SCRATCH/idx"; rm -f "$GIT_INDEX_FILE"
git read-tree "$BASE"
git update-index --add -- app/Support/Inventory/InventorySummary.php \
  app/Support/Dashboard/Glances/InventoryGlance.php \
  tests/Feature/Inventory/InventorySummaryTest.php
TREE=$(git write-tree)
git diff-tree --stat -r "$BASE" "$TREE"   # must be exactly those three
COMMIT=$(git commit-tree "$TREE" -p "$BASE" -F msg)
git update-ref refs/heads/main "$COMMIT" "$BASE"
unset GIT_INDEX_FILE
git reset -q HEAD -- app/Support/Inventory/InventorySummary.php app/Support/Dashboard/Glances/InventoryGlance.php tests/Feature/Inventory/InventorySummaryTest.php
./scripts/index-landmines.sh
```

Message: `One definition of low, called by the box and soon by the tab.`

---

## Task 2: The endpoint and its `health` block

**Files:**
- Create: `app/Http/Controllers/Api/InventorySummaryController.php`
- Modify: `routes/api.php` (one line, beside `inventory/stock` at ~531)
- Test: `tests/Feature/Inventory/InventorySummaryApiTest.php` (create)

**Interfaces:**
- Consumes: `InventorySummary::counts(User, ViewScope): array` from Task 1.
- Produces: `GET /api/v1/inventory/summary` returning `{"health": {...}, "warnings": [], "movement": {...}}`. Tasks 3 and 4 fill `warnings` and `movement`; this task ships them as an empty array and an empty object so the wire shape does not change under the client later.

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/Inventory/InventorySummaryApiTest.php`:

```php
<?php

namespace Tests\Feature\Inventory;

use App\Models\InventoryBalance;
use App\Models\InventoryItem;
use App\Models\InventoryLocation;
use App\Support\Inventory\InventoryItemStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/**
 * GET /inventory/summary — the General tab's one request.
 */
class InventorySummaryApiTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    public function test_health_counts_what_is_in_reach(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $boss = $this->userWithRole($tenant, 'Boss', ['inventory.view', 'inventory.financials']);

        $this->seedOneItemOnHand($tenant, qty: '4.000', reorderAt: '10.000', lastCostMinor: 250);

        $health = $this->signedInAs($boss)
            ->getJson('/api/v1/inventory/summary')
            ->assertOk()
            ->json('health');

        $this->assertFalse($health['empty']);
        $this->assertSame(1, $health['items']);
        $this->assertSame(1, $health['low']);
        $this->assertSame(0, $health['out']);
        $this->assertSame(1000, $health['value']['amount_minor']);
        $this->assertSame('USD', $health['value']['currency']);
    }

    public function test_without_financials_there_is_no_value_key_at_all(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $clerk = $this->userWithRole($tenant, 'Clerk', ['inventory.view']);

        $this->seedOneItemOnHand($tenant, qty: '4.000', reorderAt: '10.000', lastCostMinor: 250);

        $health = $this->signedInAs($clerk)
            ->getJson('/api/v1/inventory/summary')
            ->assertOk()
            ->json('health');

        // Absence, not zero: a zero would be a money answer.
        $this->assertArrayNotHasKey('value', $health);
        $this->assertSame(1, $health['low']);
    }

    public function test_no_items_in_reach_returns_the_sentence(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $boss = $this->userWithRole($tenant, 'Boss', ['inventory.view']);

        $health = $this->signedInAs($boss)
            ->getJson('/api/v1/inventory/summary')
            ->assertOk()
            ->json('health');

        $this->assertTrue($health['empty']);
        $this->assertSame('No items in reach.', $health['sentence']);
    }

    public function test_the_low_figure_carries_the_query_that_produced_it(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $boss = $this->userWithRole($tenant, 'Boss', ['inventory.view']);

        $this->seedOneItemOnHand($tenant, qty: '4.000', reorderAt: '10.000', lastCostMinor: null);

        $health = $this->signedInAs($boss)
            ->getJson('/api/v1/inventory/summary')
            ->assertOk()
            ->json('health');

        $this->assertSame('stock?low=1', $health['links']['low']);
    }

    private function seedOneItemOnHand(array $tenant, string $qty, ?string $reorderAt, ?int $lastCostMinor): void
    {
        $this->inTenant($tenant['org'], function () use ($tenant, $qty, $reorderAt, $lastCostMinor) {
            $location = InventoryLocation::factory()->create([
                'organization_id' => $tenant['org']->id,
                'company_id' => $tenant['company']->id,
            ]);

            $item = InventoryItem::factory()->create([
                'organization_id' => $tenant['org']->id,
                'company_id' => $tenant['company']->id,
                'status' => InventoryItemStatus::Active,
                'reorder_at' => $reorderAt,
                'last_cost_minor' => $lastCostMinor,
            ]);

            InventoryBalance::create([
                'organization_id' => $tenant['org']->id,
                'item_id' => $item->id,
                'location_id' => $location->id,
                'qty_on_hand' => $qty,
            ]);
        });
    }
}
```

- [ ] **Step 2: Run it and watch it fail**

```bash
DB_DATABASE=ezbizi_testing_inventory php -d memory_limit=1G vendor/bin/phpunit tests/Feature/Inventory/InventorySummaryApiTest.php
```

Expected: FAIL — 404, the route does not exist.

- [ ] **Step 3: Write the controller**

Create `app/Http/Controllers/Api/InventorySummaryController.php`:

```php
<?php

namespace App\Http\Controllers\Api;

use App\Models\InventoryItem;
use App\Support\Inventory\InventorySummary;
use App\Support\Scoping\ViewScope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The General chip's one request (spec 2026-09-09, §6).
 *
 * One response rather than three, because the screen needs all three blocks
 * and three requests would draw the tab in three stages.
 *
 * Money is omitted here, not in the client: a total over costs answers about
 * rows the caller may not see, so `inventory.financials` has to be spent
 * before the number is computed into the payload, never after it is sent.
 */
class InventorySummaryController extends Controller
{
    /** GET /api/v1/inventory/summary */
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', InventoryItem::class);

        $user = $request->user();
        $scope = ViewScope::forUser($user);
        $counts = InventorySummary::counts($user, $scope);

        return response()->json([
            'health' => $this->health($counts, $user->hasPermission('inventory.financials')),
            'warnings' => [],
            // Tasks 3 and 4 fill these. They ship with their final SHAPE now —
            // an empty list and an object with empty members — so that the
            // client is never written against a shape that later changes type.
            'movement' => ['period' => 'month', 'buckets' => [], 'lines' => []],
        ]);
    }

    /**
     * @param  array<string, int>  $counts
     * @return array<string, mixed>
     */
    private function health(array $counts, bool $withMoney): array
    {
        if ($counts['items'] === 0 && $counts['locations'] === 0) {
            return [
                'empty' => true,
                'sentence' => 'No items in reach.',
            ];
        }

        $health = [
            'empty' => false,
            'in_stock' => $counts['in_stock'],
            'low' => $counts['low'],
            'out' => $counts['out'],
            'items' => $counts['items'],
            'locations' => $counts['locations'],
            'open_purchase_orders' => $counts['open_purchase_orders'],
            // Each figure carries the list that produced it, so the client links
            // rather than guessing. A figure with no entry here is drawn as text.
            'links' => [
                'low' => 'stock?low=1',
                'items' => 'items',
                'locations' => 'locations',
                'open_purchase_orders' => 'orders',
            ],
        ];

        if ($withMoney) {
            $health['value'] = [
                'currency' => 'USD',
                'amount_minor' => $counts['stock_value_minor'],
            ];
        }

        return $health;
    }
}
```

- [ ] **Step 4: Register the route**

In `routes/api.php`, beside the other `inventory/…` routes (~line 531), add:

```php
        Route::get('inventory/summary', [InventorySummaryController::class, 'index']);
```

and the matching `use App\Http\Controllers\Api\InventorySummaryController;` import.

`routes/api.php` is edited by several sessions at once. When you commit, stage **your line only** by rebuilding the blob from `$BASE`:
`git show "$BASE":routes/api.php` → add your two lines to that text → `git hash-object -w --stdin --path routes/api.php` → `git update-index --cacheinfo 100644,<sha>,routes/api.php`. Then `php -l` the staged blob before committing. Never `git add routes/api.php` — that takes whatever another session has on disk.

- [ ] **Step 5: Run the test**

```bash
DB_DATABASE=ezbizi_testing_inventory php -d memory_limit=1G vendor/bin/phpunit tests/Feature/Inventory/InventorySummaryApiTest.php
```

Expected: PASS, 4 tests.

- [ ] **Step 6: Break it on purpose**

Delete the `if ($withMoney)` guard so `value` is always attached. Expected: `test_without_financials_there_is_no_value_key_at_all` goes red and nothing else does. Restore, re-run, green.

- [ ] **Step 7: Commit**

Message: `Inventory answers what you hold in one request.` Tell the deploy desk: **wire-shape addition — a new endpoint, nothing removed or renamed.** No migration, no permission change.

---

## Task 3: `warnings`

**Files:**
- Modify: `app/Support/Inventory/InventorySummary.php` (add `warnings()`)
- Modify: `app/Http/Controllers/Api/InventorySummaryController.php` (call it)
- Test: `tests/Feature/Inventory/InventorySummaryApiTest.php` (add cases)

**Interfaces:**
- Consumes: `InventorySummary::counts()`.
- Produces: `InventorySummary::warnings(User $user, ViewScope $scope, bool $withMoney): array` — a list of `['key'=>string,'sentence'=>string,'count'=>int,'link'=>string]`, in the fixed order below, with zero-count rows omitted.

The order is fixed on the server, out of stock first, because it is the only one where work has already stopped. Do not sort by count.

| `key` | Rule | `link` |
|---|---|---|
| `out_of_stock` | Active item, total on-hand at or below zero | `stock` |
| `below_reorder` | `reorder_at` set, on-hand at or below it | `stock?low=1` |
| `purchase_order_open_a_long_time` | Status draft or open, `ordered_on` more than 30 days before today in the view timezone | `orders` |
| `location_holding_nothing` | Location in reach with no balance row above zero | `locations` |
| `no_cost_recorded` | `last_cost_minor` null. **Only when `$withMoney`** | `items` |

- [ ] **Step 1: Write the failing tests**

Add to `tests/Feature/Inventory/InventorySummaryApiTest.php`:

```php
    public function test_a_purchase_order_with_no_ordered_on_is_never_called_old(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $boss = $this->userWithRole($tenant, 'Boss', ['inventory.view']);

        $this->inTenant($tenant['org'], function () use ($tenant) {
            $vendor = \App\Models\InventoryVendor::factory()->create([
                'organization_id' => $tenant['org']->id,
                'company_id' => $tenant['company']->id,
            ]);

            // ordered_on is nullable. Ageing a null from zero would mark every
            // undated order permanently fine, which is the opposite of a warning.
            \App\Models\InventoryPurchaseOrder::create([
                'organization_id' => $tenant['org']->id,
                'company_id' => $tenant['company']->id,
                'vendor_id' => $vendor->id,
                'number' => 'PO-1',
                'status' => \App\Support\Inventory\InventoryPurchaseOrderStatus::Open,
                'ordered_on' => null,
            ]);
        });

        $warnings = $this->signedInAs($boss)
            ->getJson('/api/v1/inventory/summary')
            ->assertOk()
            ->json('warnings');

        $this->assertNotContains(
            'purchase_order_open_a_long_time',
            array_column($warnings, 'key'),
        );
    }

    public function test_a_warning_with_nothing_in_it_is_not_sent(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $boss = $this->userWithRole($tenant, 'Boss', ['inventory.view']);

        $this->seedOneItemOnHand($tenant, qty: '40.000', reorderAt: '10.000', lastCostMinor: 250);

        $warnings = $this->signedInAs($boss)
            ->getJson('/api/v1/inventory/summary')
            ->assertOk()
            ->json('warnings');

        $this->assertNotContains('below_reorder', array_column($warnings, 'key'));
        $this->assertNotContains('out_of_stock', array_column($warnings, 'key'));
    }

    public function test_out_of_stock_leads_the_list(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $boss = $this->userWithRole($tenant, 'Boss', ['inventory.view']);

        $this->seedOneItemOnHand($tenant, qty: '0.000', reorderAt: '10.000', lastCostMinor: 250);
        $this->seedOneItemOnHand($tenant, qty: '2.000', reorderAt: '10.000', lastCostMinor: 250);

        $keys = array_column(
            $this->signedInAs($boss)->getJson('/api/v1/inventory/summary')->assertOk()->json('warnings'),
            'key',
        );

        $this->assertSame('out_of_stock', $keys[0]);
    }

    public function test_no_cost_recorded_is_withheld_without_financials(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $clerk = $this->userWithRole($tenant, 'Clerk', ['inventory.view']);

        $this->seedOneItemOnHand($tenant, qty: '4.000', reorderAt: null, lastCostMinor: null);

        $keys = array_column(
            $this->signedInAs($clerk)->getJson('/api/v1/inventory/summary')->assertOk()->json('warnings'),
            'key',
        );

        $this->assertNotContains('no_cost_recorded', $keys);
    }
```

- [ ] **Step 2: Run and watch them fail**

```bash
DB_DATABASE=ezbizi_testing_inventory php -d memory_limit=1G vendor/bin/phpunit tests/Feature/Inventory/InventorySummaryApiTest.php
```

Expected: `test_out_of_stock_leads_the_list` fails on an empty `warnings` array. The three "not contains" tests will pass against the empty array from Task 2 — **they are not yet proving anything.** That is expected and is exactly why Step 5 breaks them on purpose.

- [ ] **Step 3: Implement `warnings()`**

Add to `InventorySummary`. Each branch runs its own constrained count; the item classification reuses the same `bccomp` rules as `counts()` by calling it.

```php
    /**
     * @return list<array{key:string,sentence:string,count:int,link:string}>
     */
    public static function warnings(User $user, ViewScope $scope, bool $withMoney): array
    {
        $counts = self::counts($user, $scope);
        $rows = [];

        // Order is fixed here, not sorted by count: out of stock leads because
        // it is the only one where work has already stopped.
        $rows[] = [
            'key' => 'out_of_stock',
            'count' => $counts['out'],
            'sentence' => $counts['out'] === 1
                ? '1 item is out of stock.'
                : "{$counts['out']} items are out of stock.",
            'link' => 'stock',
        ];

        $rows[] = [
            'key' => 'below_reorder',
            'count' => $counts['low'],
            'sentence' => $counts['low'] === 1
                ? '1 item is at or below its reorder point.'
                : "{$counts['low']} items are at or below their reorder point.",
            'link' => 'stock?low=1',
        ];

        $oldOrders = (int) InventoryVisibility::constrain(InventoryPurchaseOrder::query(), $user, $scope)
            ->whereIn('status', [
                InventoryPurchaseOrderStatus::Draft,
                InventoryPurchaseOrderStatus::Open,
            ])
            // A null ordered_on cannot be aged, so it is excluded rather than
            // treated as today — which would make every undated order fine.
            ->whereNotNull('ordered_on')
            ->whereDate('ordered_on', '<', now()->subDays(30)->toDateString())
            ->count();

        $rows[] = [
            'key' => 'purchase_order_open_a_long_time',
            'count' => $oldOrders,
            'sentence' => $oldOrders === 1
                ? '1 purchase order has been open more than 30 days.'
                : "{$oldOrders} purchase orders have been open more than 30 days.",
            'link' => 'orders',
        ];

        $emptyLocations = (int) InventoryVisibility::constrain(InventoryLocation::query(), $user, $scope)
            ->whereDoesntHave('balances', fn ($q) => $q->where('qty_on_hand', '>', 0))
            ->count();

        $rows[] = [
            'key' => 'location_holding_nothing',
            'count' => $emptyLocations,
            'sentence' => $emptyLocations === 1
                ? '1 location is holding nothing.'
                : "{$emptyLocations} locations are holding nothing.",
            'link' => 'locations',
        ];

        if ($withMoney) {
            $noCost = (int) InventoryVisibility::constrain(InventoryItem::query(), $user, $scope)
                ->where('status', InventoryItemStatus::Active)
                ->whereNull('last_cost_minor')
                ->count();

            $rows[] = [
                'key' => 'no_cost_recorded',
                'count' => $noCost,
                'sentence' => $noCost === 1
                    ? '1 item has no cost recorded.'
                    : "{$noCost} items have no cost recorded.",
                'link' => 'items',
            ];
        }

        return array_values(array_filter($rows, fn (array $row) => $row['count'] > 0));
    }
```

In the controller, replace `'warnings' => []` with:

```php
            'warnings' => InventorySummary::warnings($user, $scope, $user->hasPermission('inventory.financials')),
```

Hoist `$withMoney = $user->hasPermission('inventory.financials');` above the array so the permission is read once.

If `InventoryLocation` has no `balances` relation, add it:

```php
    public function balances(): HasMany
    {
        return $this->hasMany(InventoryBalance::class, 'location_id');
    }
```

- [ ] **Step 4: Run the tests**

Expected: PASS, 8 tests in the file.

- [ ] **Step 5: Break each guard on purpose**

Three separate sabotages, restoring after each:

1. Remove `->whereNotNull('ordered_on')`. Expected: `test_a_purchase_order_with_no_ordered_on_is_never_called_old` red, alone.
2. Remove the `array_filter`. Expected: `test_a_warning_with_nothing_in_it_is_not_sent` red, alone.
3. Remove the `if ($withMoney)` around `no_cost_recorded`. Expected: `test_no_cost_recorded_is_withheld_without_financials` red, alone.

A sabotage that turns nothing red means that test asserts nothing — fix the test, not the code.

- [ ] **Step 6: Commit**

Message: `Inventory says what needs attention, and only what it can prove.`

---

## Task 4: `movement`

**Files:**
- Modify: `app/Support/Inventory/InventorySummary.php` (add `movement()`)
- Modify: `app/Http/Controllers/Api/InventorySummaryController.php`
- Test: `tests/Feature/Inventory/InventorySummaryApiTest.php` (add cases)

**Interfaces:**
- Consumes: `DashboardPeriod::for(User $user, string $key = 'month'): self` with readonly `key`, `startsOn`, `endsOn`, `timezone`, and `bucket(Carbon $at): string`.
- Produces: `InventorySummary::movement(User $user, ViewScope $scope, DashboardPeriod $period): array` returning
  `['period'=>string, 'buckets'=>list<string>, 'lines'=>list<array{key:string,label:string,internal:bool,points:list<float>}>]`.
  `points` is index-aligned with `buckets`. Lines: `in`, `out`, `adjusted`, `transferred`.

**`transferred` carries `internal => true`.** It is net zero — moving ten from the warehouse to a van changes where stock sits, not how much is held. It must never be added into `in` or `out`, and the flag is how the client knows not to.

The date columns are `date`, already calendar days. Parse with `Carbon::parse($value, $period->timezone)` and hand that to `bucket()`. Do not convert through UTC.

- [ ] **Step 1: Write the failing tests**

```php
    public function test_a_transfer_is_neither_in_nor_out(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $boss = $this->userWithRole($tenant, 'Boss', ['inventory.view']);

        $this->seedOneTransferToday($tenant, qty: '10.000');

        $lines = collect(
            $this->signedInAs($boss)->getJson('/api/v1/inventory/summary')->assertOk()->json('movement.lines')
        )->keyBy('key');

        // The whole point: ten moved between two of our own shelves is not ten
        // received and ten issued, and a chart that says so makes a quiet week
        // look busy.
        $this->assertSame(0.0, array_sum($lines['in']['points']));
        $this->assertSame(0.0, array_sum($lines['out']['points']));
        $this->assertSame(10.0, array_sum($lines['transferred']['points']));
        $this->assertTrue($lines['transferred']['internal']);
    }

    public function test_an_adjustment_downward_counts_as_out(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $boss = $this->userWithRole($tenant, 'Boss', ['inventory.view']);

        $this->seedOneAdjustmentToday($tenant, before: '10.000', after: '4.000');

        $lines = collect(
            $this->signedInAs($boss)->getJson('/api/v1/inventory/summary')->assertOk()->json('movement.lines')
        )->keyBy('key');

        $this->assertSame(-6.0, array_sum($lines['adjusted']['points']));
    }

    public function test_points_are_index_aligned_with_buckets(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $boss = $this->userWithRole($tenant, 'Boss', ['inventory.view']);

        $movement = $this->signedInAs($boss)
            ->getJson('/api/v1/inventory/summary')
            ->assertOk()
            ->json('movement');

        foreach ($movement['lines'] as $line) {
            $this->assertCount(
                count($movement['buckets']),
                $line['points'],
                "Line {$line['key']} has a different number of points than there are buckets.",
            );
        }
    }
```

The two helpers, with the real columns from `database/migrations/2026_08_24_160000_create_inventory_tables.php:131-190`. Note `reason` on an adjustment is `text` NOT NULL, and a transfer's `number` is unique per organization:

```php
    private function seedOneTransferToday(array $tenant, string $qty): void
    {
        $this->inTenant($tenant['org'], function () use ($tenant, $qty) {
            $from = InventoryLocation::factory()->create([
                'organization_id' => $tenant['org']->id,
                'company_id' => $tenant['company']->id,
            ]);
            $to = InventoryLocation::factory()->create([
                'organization_id' => $tenant['org']->id,
                'company_id' => $tenant['company']->id,
            ]);
            $item = InventoryItem::factory()->create([
                'organization_id' => $tenant['org']->id,
                'company_id' => $tenant['company']->id,
                'status' => InventoryItemStatus::Active,
            ]);

            $transfer = \App\Models\InventoryTransfer::create([
                'organization_id' => $tenant['org']->id,
                'from_location_id' => $from->id,
                'to_location_id' => $to->id,
                'number' => 'TR-'.uniqid(),
                'transferred_on' => now()->toDateString(),
            ]);

            \App\Models\InventoryTransferLine::create([
                'organization_id' => $tenant['org']->id,
                'transfer_id' => $transfer->id,
                'item_id' => $item->id,
                'qty' => $qty,
            ]);
        });
    }

    private function seedOneAdjustmentToday(array $tenant, string $before, string $after): void
    {
        $this->inTenant($tenant['org'], function () use ($tenant, $before, $after) {
            $location = InventoryLocation::factory()->create([
                'organization_id' => $tenant['org']->id,
                'company_id' => $tenant['company']->id,
            ]);
            $item = InventoryItem::factory()->create([
                'organization_id' => $tenant['org']->id,
                'company_id' => $tenant['company']->id,
                'status' => InventoryItemStatus::Active,
            ]);

            \App\Models\InventoryAdjustment::create([
                'organization_id' => $tenant['org']->id,
                'location_id' => $location->id,
                'item_id' => $item->id,
                'qty_before' => $before,
                'qty_after' => $after,
                'reason' => 'Counted.',
                'adjusted_on' => now()->toDateString(),
            ]);
        });
    }
```

- [ ] **Step 2: Run and watch them fail**

Expected: FAIL on `movement.lines` being absent from an empty `movement`.

- [ ] **Step 3: Implement `movement()`**

**A movement row carries no `company_id`.** Issues and adjustments hang off `location_id`; transfers off `from_location_id` and `to_location_id`. So `InventoryVisibility::constrain` is applied to the **location and the item behind the row**, never to the movement table itself — the same `whereHas` pairing `InventoryStockController::index` already uses.

Add to `InventorySummary`:

```php
    /**
     * @return array{period:string,buckets:list<string>,lines:list<array{key:string,label:string,internal:bool,points:list<float>}>}
     */
    public static function movement(User $user, ViewScope $scope, DashboardPeriod $period): array
    {
        $buckets = self::bucketAxis($period);
        $slot = array_flip($buckets);

        $visibleItem = fn ($q) => InventoryVisibility::constrain($q, $user, $scope);
        $visibleLocation = fn ($q) => InventoryVisibility::constrain($q, $user, $scope);

        $received = InventoryReceiveLine::query()
            ->join('inventory_receives', 'inventory_receives.id', '=', 'inventory_receive_lines.receive_id')
            ->whereHas('item', $visibleItem)
            ->whereBetween('inventory_receives.received_on', [
                $period->startsOn->toDateString(), $period->endsOn->toDateString(),
            ])
            ->get(['inventory_receives.received_on as on', 'inventory_receive_lines.qty as qty']);

        $issued = InventoryIssueLine::query()
            ->join('inventory_issues', 'inventory_issues.id', '=', 'inventory_issue_lines.issue_id')
            ->join('inventory_locations', 'inventory_locations.id', '=', 'inventory_issues.location_id')
            ->whereHas('item', $visibleItem)
            ->whereIn('inventory_issues.location_id', $visibleLocation(InventoryLocation::query())->select('id'))
            ->whereBetween('inventory_issues.issued_on', [
                $period->startsOn->toDateString(), $period->endsOn->toDateString(),
            ])
            ->get(['inventory_issues.issued_on as on', 'inventory_issue_lines.qty as qty']);

        $transferred = InventoryTransferLine::query()
            ->join('inventory_transfers', 'inventory_transfers.id', '=', 'inventory_transfer_lines.transfer_id')
            ->whereHas('item', $visibleItem)
            ->whereIn('inventory_transfers.from_location_id', $visibleLocation(InventoryLocation::query())->select('id'))
            ->whereBetween('inventory_transfers.transferred_on', [
                $period->startsOn->toDateString(), $period->endsOn->toDateString(),
            ])
            ->get(['inventory_transfers.transferred_on as on', 'inventory_transfer_lines.qty as qty']);

        // Signed, so a downward count reads negative on ONE line rather than
        // being split across in and out — the sign is the information.
        $adjusted = InventoryAdjustment::query()
            ->whereHas('item', $visibleItem)
            ->whereIn('location_id', $visibleLocation(InventoryLocation::query())->select('id'))
            ->whereBetween('adjusted_on', [
                $period->startsOn->toDateString(), $period->endsOn->toDateString(),
            ])
            ->get()
            ->map(fn ($row) => (object) [
                'on' => $row->adjusted_on,
                'qty' => bcsub((string) $row->qty_after, (string) $row->qty_before, 3),
            ]);

        return [
            'period' => $period->key,
            'buckets' => $buckets,
            'lines' => [
                ['key' => 'in', 'label' => 'Received', 'internal' => false,
                    'points' => self::points($received, $period, $slot, count($buckets))],
                ['key' => 'out', 'label' => 'Issued', 'internal' => false,
                    'points' => self::points($issued, $period, $slot, count($buckets))],
                ['key' => 'adjusted', 'label' => 'Adjusted', 'internal' => false,
                    'points' => self::points($adjusted, $period, $slot, count($buckets))],
                // Net zero: stock moved between our own shelves. Never folded
                // into in or out, and flagged so the client cannot either.
                ['key' => 'transferred', 'label' => 'Transferred', 'internal' => true,
                    'points' => self::points($transferred, $period, $slot, count($buckets))],
            ],
        ];
    }

    /** @return list<string> */
    private static function bucketAxis(DashboardPeriod $period): array
    {
        $axis = [];
        $at = $period->startsOn->copy();

        // Walk the whole period so a quiet week returns a full axis rather than
        // an empty chart the client has to invent labels for.
        while ($at->lessThanOrEqualTo($period->endsOn)) {
            $bucket = $period->bucket($at);
            if (! in_array($bucket, $axis, true)) {
                $axis[] = $bucket;
            }
            $at->addDay();
        }

        return $axis;
    }

    /**
     * @param  iterable<object{on:mixed,qty:mixed}>  $rows
     * @param  array<string,int>  $slot
     * @return list<float>
     */
    private static function points(iterable $rows, DashboardPeriod $period, array $slot, int $width): array
    {
        $points = array_fill(0, $width, 0.0);

        foreach ($rows as $row) {
            // These are `date` columns — already calendar days. Parse them IN
            // the view timezone; never round-trip one through UTC, which can
            // move it a day.
            $bucket = $period->bucket(Carbon::parse((string) $row->on, $period->timezone));

            if (isset($slot[$bucket])) {
                $points[$slot[$bucket]] += (float) $row->qty;
            }
        }

        return $points;
    }
```

Imports to add: `Carbon\Carbon`, `App\Models\InventoryAdjustment`, `InventoryIssueLine`, `InventoryReceiveLine`, `InventoryTransferLine`, `App\Support\Dashboard\DashboardPeriod`.

In the controller, replace the placeholder movement with:

```php
            'movement' => InventorySummary::movement(
                $user,
                $scope,
                DashboardPeriod::for($user, (string) $request->query('period', 'month')),
            ),
```

`DashboardPeriod::for` throws `InvalidArgumentException` on a key outside `['week','month','quarter','year']`. Validate first and return 422 rather than letting it 500:

```php
        $periodKey = (string) $request->query('period', 'month');

        if (! in_array($periodKey, DashboardPeriod::VALUES, true)) {
            return response()->json([
                'message' => 'That is not a period this dashboard offers.',
            ], 422);
        }
```

**Sale fulfilments join the `out` line, and they are shaped differently from the other three.** `inventory_sale_fulfillment_lines` has **no `item_id`** — it carries `sale_line_id`, and the item lives one hop further on `inventory_sale_lines`. Anyone assuming the four line tables are symmetrical will write a join that does not exist:

```php
        $fulfilled = InventorySaleFulfillmentLine::query()
            ->join('inventory_sale_fulfillments', 'inventory_sale_fulfillments.id', '=', 'inventory_sale_fulfillment_lines.fulfillment_id')
            // The extra hop: the item is on the sale LINE, not the fulfilment line.
            ->join('inventory_sale_lines', 'inventory_sale_lines.id', '=', 'inventory_sale_fulfillment_lines.sale_line_id')
            ->whereIn('inventory_sale_lines.item_id', $visibleItem(InventoryItem::query())->select('id'))
            ->whereIn('inventory_sale_fulfillments.location_id', $visibleLocation(InventoryLocation::query())->select('id'))
            ->whereBetween('inventory_sale_fulfillments.fulfilled_on', [
                $period->startsOn->toDateString(), $period->endsOn->toDateString(),
            ])
            ->get([
                'inventory_sale_fulfillments.fulfilled_on as on',
                'inventory_sale_fulfillment_lines.qty as qty',
            ]);
```

The `out` line sums both sources, so build its points from the two collections concatenated:

```php
                ['key' => 'out', 'label' => 'Issued and sold', 'internal' => false,
                    'points' => self::points($issued->concat($fulfilled), $period, $slot, count($buckets))],
```

Add a fourth test proving the second source is actually counted — without it, `out` could silently drop every sale and the transfer test would still pass:

```php
    public function test_a_sale_fulfilment_counts_as_out(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $boss = $this->userWithRole($tenant, 'Boss', ['inventory.view']);

        $this->seedOneFulfilmentToday($tenant, qty: '3.000');

        $lines = collect(
            $this->signedInAs($boss)->getJson('/api/v1/inventory/summary')->assertOk()->json('movement.lines')
        )->keyBy('key');

        $this->assertSame(3.0, array_sum($lines['out']['points']));
    }
```

`seedOneFulfilmentToday()` creates an `InventorySale`, an `InventorySaleLine` (`qty_ordered`, `item_id`), an `InventorySaleFulfillment` (`sale_id`, `location_id`, `fulfilled_on`) and an `InventorySaleFulfillmentLine` (`fulfillment_id`, `sale_line_id`, `qty`). Read `inventory_sales`' own required columns off `database/migrations/2026_08_25_120000_create_inventory_sales_tables.php:18-34` — they were not read while writing this plan, and inventing them would prove only that the fixture agrees with itself.

- [ ] **Step 4: Run the tests**

Expected: PASS, 12 tests in the file (4 from Task 2, 4 from Task 3, 4 here).

- [ ] **Step 5: Break it on purpose, twice**

1. Add the transferred quantities into both `in` and `out`. Expected: `test_a_transfer_is_neither_in_nor_out` red, alone. Restore.
2. Drop `->concat($fulfilled)` from the `out` line. Expected: `test_a_sale_fulfilment_counts_as_out` red, alone. Restore.

The second sabotage is the one that matters: `out` summing two sources means it can silently lose one and still look right in every other test.

- [ ] **Step 6: Run the whole Inventory suite and the glance**

```bash
DB_DATABASE=ezbizi_testing_inventory php -d memory_limit=1G vendor/bin/phpunit tests/Feature/Inventory tests/Feature/Dashboard/InventoryGlanceTest.php
```

Expected: PASS. Report the count **with the paths it covered**, not the number alone.

- [ ] **Step 7: Commit**

Message: `Inventory shows what moved, and does not count a transfer twice.`

---

## Task 5: Hand the brief to UI/UX

The screen is design's to draw and build. This task creates the durable brief and nothing else — **do not create, edit or register a client screen.**

**Files:**
- Modify: `docs/UI-DESIGN-QUEUE.md` (append one brief)

- [ ] **Step 1: Capture a real payload**

Run the endpoint against the demo tenant (`php artisan inventory:seed-demo`, then a signed-in request) and save the actual JSON. Design draws against real data, not a described shape.

- [ ] **Step 2: Append the brief**

Add to `docs/UI-DESIGN-QUEUE.md`, under the queue: the ask in Jose's words; the endpoint and the captured payload; that General is the **first chip and the default landing**, so Stock no longer opens first; the three blocks; and the four choices that are design's — how the blocks stack at 375px versus desktop, whether movement is a bar, a line or stacked areas, whether `transferred` appears on the chart at all given it is net zero, and whether warnings lead the screen or sit under the health figures.

State the constraints that are not design's to choose: no modals, colours from `theme.dart`, 44px touch targets, a figure with no `links` entry is drawn as text rather than a dead control, and the tab must be complete and honest with no money on it for a caller without `inventory.financials`.

- [ ] **Step 3: Commit, then message the UI/UX session**

Address it by desktop session id from `docs/SESSIONS.md`. If that send errors the role is unstaffed — write the brief into `docs/agents/inbox/` instead, per `docs/agents/README.md`, because a file outlives the session that wrote it.

- [ ] **Step 4: Tell the deploy desk**

One message: the SHA range across Tasks 1-5, that the only wire-shape change is the added `GET /inventory/summary`, and that no client change has shipped yet — so `client/VERSION` needs no bump for this work, and will need one when design ships the screen.

---

## Spec coverage

| Spec § | Task |
|---|---|
| 1-3 what it is, settled decisions | 1-5 |
| 4 where it sits (chip, landing) | 5 (brief); the insertion itself ships with design's screen |
| 5 one aggregator, two consumers | 1 |
| 6 the endpoint | 2 |
| 7 `health` | 2 |
| 8 `movement` | 4 |
| 9 `warnings` | 3 |
| 10 money and financials | 2 (`value`), 3 (`no_cost_recorded`), 4 (quantities only) |
| 11 screen | 5 — design's, not this plan's |
| 12 dates | 4 |
| 13 tests | each task |
| 14 out | not built, by construction |
| 15 build order | task order above |
