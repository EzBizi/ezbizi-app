# Monitoring General Tab (floor-plan map) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** A Monitoring "General" sub-tab showing machines on a floor-plan grid — walls, person-assigned computer tiles coloured by live online/offline, an editor, and a summary sidebar.

**Architecture:** One scoped table (`monitoring_floor_plans`) holds each plan as a JSON `layout` document (grid + walls + nodes), scoped by the `assigned_organization_id/company_id/franchise_id` columns so `ScopeReach` gives Org›Company›Franchise visibility for free. A thin `FloorPlansController` lists/reads/writes plans; reads return a resolved per-node online status. The client renders an interactive canvas (CustomPaint grid+walls + positioned tiles) with view and edit modes and a right sidebar.

**Tech Stack:** Laravel 13 / PHP 8.4 / MySQL 8; Flutter (web + Windows/macOS/Linux desktop).

**Spec:** `docs/superpowers/specs/2026-09-09-monitoring-general-map-design.md`

## Global Constraints

- Scope columns are exactly `assigned_organization_id`, `assigned_company_id` (nullable), `assigned_franchise_id` (nullable); use `ScopeReach::constrain($query,$actor)` to list, `ScopeReach::assertCovers($actor,$type,$id)` to validate a chosen scope on write, `ScopeReach::constrainToVisiblePeople` to validate/resolve node assignees.
- Writes are audited via the module's existing `auditActionPrefixes: ['monitoring']` — `$this->audit->log('monitoring.map.{created,updated,deleted}', $plan, ...)`.
- New grant `monitoring.map.manage` (edit); viewing uses existing `monitoring.view`. Add-only to the ModuleDefinition.
- Client: NO unconditional `dart:io` (gate any client/lib change with `flutter build web`); NO colour literals (theme tokens only — reuse the existing online/offline status colours PC Specs uses; add a `mapUnassigned` grey token if none exists). 375px must not overflow.
- Migrations keyed by filename; a new table gets a new file.
- Backend tests: `DB_DATABASE=ezbizi_testing_monitoring-map php artisan test <path>`.

---

### Task 1: Table + model

**Files:**
- Create: `database/migrations/2026_09_09_170000_create_monitoring_floor_plans_table.php`
- Create: `app/Models/MonitoringFloorPlan.php`
- Test: `tests/Feature/Monitoring/FloorPlanModelTest.php`

**Interfaces:**
- Produces: `MonitoringFloorPlan` (`BelongsToTenant`, `HasUuids`, `StatesRetention` via `forever()`), casts `layout` → array, fillable `['name','assigned_company_id','assigned_franchise_id','layout','created_by']`; `assigned_organization_id` set by `BelongsToTenant`. Relations: `company()`, `franchise()`, `creator()`.

- [ ] Step 1: Write `FloorPlanModelTest::test_layout_round_trips_as_an_array` — create a plan with a `layout` array (`grid`,`walls`,`nodes`), reload, assert `layout['nodes'][0]['x']` is an int.
- [ ] Step 2: Run it, expect fail (no table/model).
- [ ] Step 3: Migration — `assigned_organization_id` (foreignUuid constrained), `assigned_company_id` nullable + FK nullOnDelete, `assigned_franchise_id` nullable + FK nullOnDelete, `name` string, `layout` json, `created_by` nullable FK→users nullOnDelete, timestamps. Model with the casts/fillable/relations above and `StatesRetention` (`retention()` → `RetentionPolicy::forever()`), NOT `HoldsPersonalData`.
- [ ] Step 4: Run test, expect pass.
- [ ] Step 5: Run `PersonalDataPurgeTest` + `RetentionTest` (schema §9/§10 invariants) — expect green (plan is not personal data). Commit.

### Task 2: The `monitoring.map.manage` grant

**Files:**
- Modify: `app/Providers/ModuleServiceProvider.php` (the `monitoring` ModuleDefinition `actions`)
- Test: `tests/Feature/Monitoring/MonitoringModuleRegistrationTest.php`

- [ ] Step 1: Add assertions to the registration test: the `monitoring` module's actions contain `map.manage`, and `PermissionCatalogue::keys()` contains `monitoring.map.manage`.
- [ ] Step 2: Run, expect fail.
- [ ] Step 3: Add `'map.manage'` to the monitoring `actions` array (add-only, next to `cameras.manage`/`control`). The existing `auditActionPrefixes: ['monitoring']` already covers `monitoring.map.*` — no change there.
- [ ] Step 4: Run the registration test + `ModuleRegistryTest` (§2.2 audit gate) — expect green (writesRecords already true; the audit prefix already declared). Commit.

### Task 3: Read endpoints — list + get-one with status

**Files:**
- Create: `app/Http/Controllers/Api/Monitoring/FloorPlansController.php` (index + show only in this task)
- Create: `app/Policies/MonitoringFloorPlanPolicy.php` (viewAny→`monitoring.view`; create/update/delete→`monitoring.map.manage`)
- Modify: `routes/api.php` (GET list + GET {id}, main v1 group)
- Test: `tests/Feature/Monitoring/FloorPlanReadTest.php`

**Interfaces:**
- Produces: `GET /api/v1/monitoring/floor-plans` → `{data:[{id,name,scope:{type,id,label},updated_at}]}` narrowed by `ScopeReach::constrain`. `GET /api/v1/monitoring/floor-plans/{id}` → `{data:{id,name,scope,layout,status:{<node_id>:{assigned:bool,online:bool,name:?string}}}}`, §3-scoped. Online = the assignee has a `monitored_devices` row seen within `PcSpecsTab.onlineWithin` (5 min) — mirror how DevicesController computes online; resolve names/status ONLY for people `constrainToVisiblePeople` returns (others → `assigned:false`).

- [ ] Step 1: `FloorPlanReadTest::test_company_plan_is_visible_to_that_company_and_its_franchises_and_the_org_but_not_a_sibling_company` — two `provisionTenant`/company arms; assert list membership per viewer (load-bearing reach test).
- [ ] Step 2: `test_show_resolves_online_offline_and_hides_an_unseen_assignee` — a node assigned to an online person → `online:true` with name; a node assigned to a person the viewer cannot see → `assigned:false`, no name.
- [ ] Step 3: Run both, expect fail.
- [ ] Step 4: Implement index (constrain + present scope label from company/franchise/org) and show (load plan §3-scoped like ScreenshotsController's manual find+404; resolve status). Policy + routes.
- [ ] Step 5: Run tests, expect pass. Commit.

### Task 4: Write endpoints — create / update / delete (audited)

**Files:**
- Modify: `app/Http/Controllers/Api/Monitoring/FloorPlansController.php` (store/update/destroy)
- Modify: `routes/api.php` (POST / PUT / DELETE)
- Test: `tests/Feature/Monitoring/FloorPlanWriteTest.php`

**Interfaces:**
- Consumes: `AuditLogger` (constructor DI, like CamerasController).
- Produces: `POST /floor-plans` {name, scope:{type,id}, layout?} → 201; `PUT /floor-plans/{id}` {name?, layout?} → 200; `DELETE` → 204. All gated `map.manage`, §3-scoped, `assertCovers` on the chosen scope, every node `user_id` validated against `constrainToVisiblePeople`, audited `monitoring.map.{created,updated,deleted}`.

- [ ] Step 1: Tests — create at company scope validated by `assertCovers` (a scope above the actor's level → 422); a `PUT` whose layout names an unseeable person → 422; create/update/delete each write one `monitoring.map.*` audit row (assert `AuditEvent` action + target, non-vacuous like CamerasTest); no `map.manage` → 403 with a sentence.
- [ ] Step 2: Run, expect fail.
- [ ] Step 3: Implement. Validate `layout` shape (grid ints; walls = list of 4 ints; nodes = list of {id,x,y,label,user_id?}); reject node `user_id`s outside `constrainToVisiblePeople`. Audit after each write (label in metadata).
- [ ] Step 4: Run tests + whole `tests/Feature/Monitoring` — expect green. Commit.

### Task 5: Client — GeneralTab shell, plan picker, view-mode read

**Files:**
- Create: `client/lib/screens/monitoring/general_tab.dart`
- Modify: `client/lib/screens/monitoring/monitoring_screen.dart` (add the "General" sub-tab, first, gated `monitoring.view`)
- Test: `client/test/general_tab_test.dart`

**Interfaces:**
- Consumes: `ApiClient`, `Session`. Produces `GeneralTab({api, session})`.
- Behaviour: on load, `GET /floor-plans`; a picker (grouped by scope) selects one; `GET /floor-plans/{id}` loads layout+status; renders the canvas (Task 6) read-only; polls status every 30s; empty state when no plans (and an "add a plan" affordance only with `.manage`).

- [ ] Step 1: Test — with two plans the picker lists both; selecting one loads its layout (fake ApiClient); no plans → empty state; sub-tab present + selected. Mirror `monitoring_screen_test` style.
- [ ] Step 2: Run, expect fail.
- [ ] Step 3: Implement the shell + picker + load + 30s poll (cancel on dispose; guard post-await setState with a generation counter like `desktop_monitoring_tab`). Canvas is a placeholder widget until Task 6.
- [ ] Step 4: `flutter test general_tab_test.dart` + `flutter build web` green. Commit.

### Task 6: Client — the canvas (grid + walls + tiles, coloured by status)

**Files:**
- Create: `client/lib/screens/monitoring/floor_plan_canvas.dart` (a `CustomPaint` grid+walls layer + positioned tile widgets)
- Test: `client/test/floor_plan_canvas_test.dart`

**Interfaces:**
- Produces `FloorPlanCanvas({layout, status, selectedNodeId, onSelectNode, editable, on... edit callbacks})`. A tile's colour: `status[id].online==true`→online token (green); `assigned && !online`→offline token (red); `!assigned`→unassigned token (grey). Fit-to-canvas (scale grid to the box), no zoom.

- [ ] Step 1: Test — a node with `online:true` renders the online colour; `assigned:false` renders the unassigned colour; `assigned && online:false` renders the offline colour (a control case per colour, asserted via the tile's `Key`/decoration through the theme, NOT a literal); walls draw (paints without error at 375px; no overflow).
- [ ] Step 2: Run, expect fail.
- [ ] Step 3: Implement CustomPaint (grid faint lines + wall segments from `layout['walls']`) + `Positioned` tiles from `layout['nodes']`; tap a tile → `onSelectNode`. Colours from theme tokens.
- [ ] Step 4: `flutter test` + `flutter build web` green. Commit.

### Task 7: Client — edit mode (place/move/delete tiles, draw walls, assign, save)

**Files:**
- Modify: `client/lib/screens/monitoring/general_tab.dart` (edit toggle + mutations + PUT save)
- Modify: `client/lib/screens/monitoring/floor_plan_canvas.dart` (drag-to-move, wall-draw gesture, add/delete affordances when `editable`)
- Test: extend `client/test/general_tab_test.dart`

**Interfaces:**
- Edit toggle shown only when `session.me.can('monitoring.map.manage')`. Add computer (drops a node, snapped), drag to move (snap to grid), delete; draw a wall (tap two grid points → a segment); assign a person from a searchable list of visible people (reuse the people-list widget Desktop Monitoring uses); rename; create/delete plan + pick scope. Save → `PUT` the whole layout.

- [ ] Step 1: Tests — no `map.manage` → no edit toggle (view only, control); with it, adding a computer then saving PUTs a layout whose nodes include the new node; assigning a person sets the node `user_id`; drawing a wall adds a wall segment. Fake ApiClient records the PUT body.
- [ ] Step 2: Run, expect fail.
- [ ] Step 3: Implement. Keep the generation/identity guards; snap coordinates to the grid; validate locally that a wall has two distinct points.
- [ ] Step 4: `flutter test` whole suite + `flutter build web` green; theme ratchet green. Commit.

### Task 8: Client — the summary sidebar

**Files:**
- Create: `client/lib/screens/monitoring/floor_plan_sidebar.dart`
- Modify: `client/lib/screens/monitoring/general_tab.dart` (mount the sidebar; wire selection)
- Test: extend `client/test/general_tab_test.dart`

**Interfaces:**
- Produces `FloorPlanSidebar({plan, status, selectedNodeId, api})`. Nothing selected → general summary: online/total, offline count, unassigned count (computed from `status`). A tile selected → that person's summary: name, online + "seen X ago" (or offline), PC specs if their machine runs the agent, a button that jumps to Desktop Monitoring for them.

- [ ] Step 1: Tests — nothing selected shows the counts (a plan with 2 online / 1 offline / 1 unassigned → "2 online" etc., the numbers asserted); selecting an online node shows that person's name + online; an unassigned node shows an "assign someone" hint (with `.manage`) or a plain "no one assigned".
- [ ] Step 2: Run, expect fail.
- [ ] Step 3: Implement. Honesty: never show a colour/status more confident than `status` provides.
- [ ] Step 4: `flutter test` whole suite + `flutter build web` green; 375px no overflow. Commit.

---

## Self-Review notes

- Spec coverage: table+scope (T1,T3), permissions (T2,T4), read+status (T3), write+audit+validation (T4), tab+picker+poll (T5), canvas+colours (T6), editor+walls+assign (T7), sidebar summaries (T8) — every spec section maps to a task.
- Deferred items (floor-plan image, zoom, websocket push, per-node table) are NOT tasks, by design.
- Type consistency: `layout` keys (`grid`/`walls`/`nodes`; node `{id,x,y,label,user_id}`) and the `/floor-plans/{id}` `status` map shape are used identically in T3, T4, T6, T7, T8.
