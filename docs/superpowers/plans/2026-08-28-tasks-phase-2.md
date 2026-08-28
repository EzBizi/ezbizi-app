# Tasks Phase 2 Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add five presentations of the tasks Phase 1 already stores — table, Kanban, Calendar, Timeline/Gantt, Workload — plus display-only dependencies and per-task recurrence, without adding a second access path, a second writer, or a second audit path.

**Architecture:** Phase 1 left one deliberate extension point: `TaskViewCodec` refuses every `presentation` but `"table"`. Phase 2 widens that to a `schema:2` configuration carrying `presentation` and a per-presentation `window`, and `GET tasks/boards/{board}/rows` remains the single query surface for all five. Four endpoints would be four places to get exact-view and private-board access wrong; Phase 1's security rests on there being one path, and this plan keeps it. Every drag on every new screen performs the field's **existing** Phase 1 write, with its version, audit row and trigger. The only genuinely new domain objects are a same-board dependency edge and a recurrence declaration served by the hourly worker pattern Phase 1 already built for overdue and due reminders.

**Tech Stack:** PHP 8.4 runtime (Composer floor `^8.3`), Laravel 13, MySQL 8, PHPUnit 12, Flutter/Dart 3.11, Material 3, existing EzBizi audit/automation/notification/settings infrastructure. No new dependency in either half.

**Spec:** [`docs/superpowers/specs/2026-08-28-tasks-phase-2-design.md`](../specs/2026-08-28-tasks-phase-2-design.md) (commit `f03ee7e`)

**Phase 1 design:** [`docs/superpowers/specs/2026-08-25-tasks-design.md`](../specs/2026-08-25-tasks-design.md) · **Phase 1 plan:** [`docs/superpowers/plans/2026-08-25-tasks-phase-1.md`](2026-08-25-tasks-phase-1.md)

## Global Constraints

- [ ] Read `StartUp.md`, `docs/ACTIVE-CONSTRAINTS.md`, and `docs/MODULE-OWNERS.md` before the first edit. `ACTIVE-CONSTRAINTS.md` is read-order 0 and its presence is the signal; it is deleted when App Store review clears.
- [ ] **While `docs/ACTIVE-CONSTRAINTS.md` exists, every change must be additive.** App Store review runs a frozen `v0.0.0112` binary against live production. Do not rename or remove a response field, do not narrow a permission answer, do not reword a `Refusal.php` sentence the frozen client renders. New fields, new tables, new endpoints and new presentations are all permitted.
- [ ] **Never a bare `git commit`.** `.git/index` is shared by every session on this tree, and `git add <path>` adds to whatever someone else has already staged. Use the `StartUp.md` §4 private-index recipe: resolve `BASE=$(git rev-parse HEAD)` once, `export GIT_INDEX_FILE` to a path **outside** `.git/`, `git read-tree HEAD`, `git update-index --add` your own files, check `git diff-index --cached --stat "$BASE"` as its own step, `git commit-tree -p "$BASE"`, then `git update-ref refs/heads/<branch> <new> <old>` with the old-value guard. If `update-ref` refuses, re-read HEAD and rebuild — never force.
- [ ] **Verify every commit by line count, not filename.** `git show --stat <sha>` must match what `git diff-index --cached --stat` showed. A stale blob from the shared index changes the count, not the list of files. On 2026-08-28 a commit whose message said "docs" silently carried half of another session's rename.
- [ ] **Never test against the shared `ezbizi_testing` schema.** Every command in this plan uses `DB_DATABASE=ezbizi_testing_tasks`. Concurrent `RefreshDatabase` runs from other sessions destroy each other and the symptom is impossible SQL — `migrations` missing in one test and `users` already existing in the next. Create it once: `php -r '(new PDO("mysql:host=127.0.0.1","root",""))->exec("CREATE DATABASE IF NOT EXISTS ezbizi_testing_tasks CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");'`
- [ ] Keep every read on the exact current view. No Phase 2 query may pick a place by iterating `/me.companies` or `/me.franchises`; filing comes from `ViewScope::$type`/`$id` and the board's immutable place. A sibling company present in `/me` confers nothing.
- [ ] Reuse Phase 1's writers. Controllers and workers call `TaskWriter`, `TaskBoardWriter`, `TaskViewWriter` and the new `TaskDependencyWriter`; nothing writes these models directly. Each writer inserts its classified audit row and its outbox event inside the same transaction. No write path dispatches an automation directly.
- [ ] Every window is bounded **by the server**, never by the caller's honesty. An unbounded date range or bucket set is how a board view becomes a table scan.
- [ ] Every presentation states what it omitted — undated tasks, tasks outside the window, tasks with no owner. A view that silently drops rows is the defect these rules exist to prevent.
- [ ] **No pop-ups, ever.** `StartUp.md` says it twice and Phase 1 shipped two `AlertDialog`s anyway, caught only by an audit. Presentation pickers, window pickers, the dependency picker and the recurrence editor are panels or screens. Colours come from `client/lib/core/theme.dart`; a Gantt bar and a Kanban column header are exactly where a literal hex creeps in. 375px is first-class.
- [ ] Follow TDD in every task: write the named failing test, run it and watch it fail for the stated reason, add the smallest implementation, rerun the focused test, then run the milestone suite.
- [ ] **Break at least one new test on purpose per task.** This repository has shipped tests that passed against the exact bug they claimed to catch. Confirm the assertion fails when the behaviour is removed, then restore.
- [ ] Do not deploy. The `ezbizi-a1` session owns `scripts/deploy.sh`, the sandbox and the production symlink. Hand it the SHAs, one line of what changed, and what to watch — flagging wire-shape, permission and migration changes explicitly.
- [ ] Update the `tasks-module-phase-2` card on the integrations board in the same turn as the work, one checklist step per numbered task. No secrets on the board.

## Contract and File Map

**Server — new**

| File | Responsibility |
|---|---|
| `app/Support/Tasks/TaskPresentation.php` | Backed enum: `table\|kanban\|calendar\|timeline\|workload` |
| `app/Support/Tasks/TaskWindow.php` | Parses and bounds one presentation's window; refuses foreign shapes |
| `app/Support/Tasks/TaskDependencyWriter.php` | Adds/removes edges, rejects cycles and cross-board pairs, audits, emits outbox |
| `app/Support/Tasks/TaskDependencyCycle.php` | Bounded graph walk used by the writer inside its transaction |
| `app/Support/Tasks/TaskRecurrenceCodec.php` | Validates and reads the `recurrence` JSON by key, never by order |
| `app/Support/Tasks/TaskRecurrenceWorker.php` | Hourly, overlap-locked, both modes, occurrence-idempotent |
| `app/Support/Tasks/TaskWorkloadQuery.php` | Per-owner counts per unit over a bounded window |
| `app/Http/Controllers/Api/Tasks/TaskDependenciesController.php` | `POST`/`DELETE` edges |
| `app/Models/TaskDependency.php`, `app/Models/TaskRecurrenceOccurrence.php` | Tenant-scoped models |

**Server — modified**

| File | Change |
|---|---|
| `app/Support/Tasks/TaskViewCodec.php` | `schema:2`; accept `presentation` and `window`; upgrade `schema:1` on read |
| `app/Support/Tasks/TaskListQuery.php` | Window-aware querying and complete-result summaries |
| `app/Http/Controllers/Api/Tasks/TaskBoardRowsController.php` | Accept and validate `window`; window-sensitive ETag |
| `app/Support/Tasks/TaskWriter.php` | `start_date` write, recurrence declaration, recurrence-aware completion |
| `app/Providers/TaskServiceProvider.php` | Register three triggers and the recurrence command |
| `app/Models/Task.php` | `recurrence` cast and relations |

**Client — new**

`task_kanban_view.dart`, `task_calendar_view.dart`, `task_timeline_view.dart`, `task_workload_view.dart`, `task_presentation_bar.dart`, `task_dependency_panel.dart`, `task_recurrence_panel.dart` — all under `client/lib/screens/tasks/`.

**Client — modified**

`task_models.dart` (presentation, window, `startDate`, dependencies, recurrence), `task_api.dart` (window parameter, dependency and recurrence commands), `tasks_screen.dart` (presentation routing inside the existing `_content` chain), `task_detail_panel.dart` (Blocked-by chips, recurrence summary).

---

## Milestone 1 — Storage and the presentation contract

### Task 1: Add Phase 2 storage

**Files:**
- Create: `database/migrations/2026_08_29_100000_add_task_phase_two_storage.php`
- Create: `app/Models/TaskDependency.php`
- Create: `app/Models/TaskRecurrenceOccurrence.php`
- Modify: `app/Models/Task.php`
- Test: `tests/Feature/Tasks/TaskPhaseTwoSchemaTest.php`

- [ ] Write failing schema tests proving: `tasks.start_on` and `tasks.recurrence` exist and are nullable; `task_dependencies` rejects a cross-tenant, cross-board, or duplicated pair by named MySQL constraint; a dependency row disappears when either task is deleted; `task_recurrence_occurrences` rejects a duplicate `(task_id, occurrence_date)`; and both new models declare their retention and personal-data purpose.
- [ ] Run `DB_DATABASE=ezbizi_testing_tasks php -d memory_limit=1G vendor/bin/phpunit tests/Feature/Tasks/TaskPhaseTwoSchemaTest.php` and confirm it fails because the tables and column do not exist.
- [ ] Add `recurrence` (nullable `json`) to `tasks`. `start_on` already exists — Phase 1 shipped it beside `due_on` — so no start-date column is added. Additive only; do not touch an existing column's name, type or nullability while `ACTIVE-CONSTRAINTS.md` exists.
- [ ] Create `task_dependencies`: UUIDv7 id, `organization_id`, `board_id`, `blocker_task_id`, `blocked_task_id`, `created_by`, timestamps. Unique `(blocker_task_id, blocked_task_id)`. Compound foreign keys carrying `(organization_id, board_id, id)` for both task references so a mismatched tenant or board is rejected below the HTTP layer — the same pattern Phase 1 used for every board child. `CHECK (blocker_task_id <> blocked_task_id)`. Cascade on task removal. Index `(blocked_task_id)` and `(blocker_task_id)` separately; the graph is walked in both directions.
- [ ] Create `task_recurrence_occurrences`: UUIDv7 id, `organization_id`, `task_id` (the series task), `occurrence_date`, `created_task_id` nullable, `created_at`. Unique `(task_id, occurrence_date)` — this uniqueness is the entire idempotency story for a retried or overlapping worker run, exactly as `task_overdue_occurrences` is for overdue.
- [ ] Add indexes `(board_id, start_on, due_on)` for timeline and calendar windows and `(organization_id, owner_id, due_on)` for workload.
- [ ] Declare `retention()` and `personalDataPurpose()` on both new models. `TaskDependency` is `RetentionPolicy::forever(...)` — it is board structure. `TaskRecurrenceOccurrence` is also `RetentionPolicy::forever(...)`, mirroring `TaskOverdueOccurrence`: the occurrence key stays as durable idempotency evidence. Phase 2 builds no cleaner for this table, so `RetentionPolicy::elsewhere(...)` would name a mechanism that does not exist — a false declaration.
- [ ] Rerun the focused test, then break one assertion on purpose (drop the `CHECK`) and confirm it fails before restoring.
- [ ] Commit via the §4 private-index recipe: `database/migrations/2026_08_29_100000_add_task_phase_two_storage.php`, both new models, `app/Models/Task.php`, `tests/Feature/Tasks/TaskPhaseTwoSchemaTest.php`. Message: `feat(tasks): add phase 2 storage`.

### Task 2: Widen the view codec to schema 2

**Files:**
- Create: `app/Support/Tasks/TaskPresentation.php`
- Create: `app/Support/Tasks/TaskWindow.php`
- Modify: `app/Support/Tasks/TaskViewCodec.php`
- Test: `tests/Feature/Tasks/TaskPresentationCodecTest.php`

**Interfaces:**
- Produces: `TaskPresentation` backed enum with cases `Table`, `Kanban`, `Calendar`, `Timeline`, `Workload` (values `table`, `kanban`, `calendar`, `timeline`, `workload`). `TaskWindow::parse(TaskPresentation $p, ?array $raw): TaskWindow` throwing `TaskViewConflict` on a foreign or over-wide shape; readers `->page()`, `->perPage()`, `->from()`, `->to()`, `->unit()`, `->bucketField()`, `->buckets()`.
- Consumed by: Tasks 3, 4, 7, and every client task.

- [ ] Write failing tests: a `schema:1` row reads back as `schema:2` with `presentation:"table"` and `window:null` **without being rewritten in storage**; an unknown presentation is refused; a calendar window offered to a kanban view is refused; a range wider than 400 days is refused; more than 50 buckets is refused; the permanent Main view cannot be changed to another presentation; and a `schema:2` payload still carries every `schema:1` field at its `schema:1` meaning.
- [ ] Run `DB_DATABASE=ezbizi_testing_tasks php -d memory_limit=1G vendor/bin/phpunit tests/Feature/Tasks/TaskPresentationCodecTest.php` and confirm failure at `TaskViewCodec.php:69`, which currently rejects any presentation but `table`.
- [ ] Add `TaskPresentation` and `TaskWindow`. `TaskWindow` validates per presentation and nothing else: `table` takes `{page, per_page}`; `calendar` and `timeline` take `{from, to}` as dates with `to >= from` and a span of at most 400 days; `workload` takes `{from, to, unit}` with `unit` in `day|week`; `kanban` takes `{bucket_field, buckets:[...]}` with at most 50 buckets and `bucket_field` restricted to the same set Phase 1 permits for `group_by`.
- [ ] Widen `TaskViewCodec`: write `schema:2`, accept the five presentations, validate `window` through `TaskWindow`, and upgrade a stored `schema:1` row on read rather than migrating it in place. **Nothing is rewritten in storage** — a rollback must not strand rows in a schema the previous release cannot read.
- [ ] Refuse before any identifier reaches SQL, the rule Phase 1 applied to filters and sorts. An archived or foreign `bucket_field` never becomes a SQL identifier.
- [ ] Break the 400-day bound on purpose, confirm the test fails, restore.
- [ ] Commit via §4: the two new files, `TaskViewCodec.php`, the test. Message: `feat(tasks): widen the view codec to schema 2`.

### Task 3: Serve windowed rows from the one query surface

**Files:**
- Modify: `app/Support/Tasks/TaskListQuery.php`
- Modify: `app/Http/Controllers/Api/Tasks/TaskBoardRowsController.php`
- Test: `tests/Feature/Tasks/TaskWindowedRowsTest.php`

**Interfaces:**
- Consumes: `TaskPresentation`, `TaskWindow` from Task 2.
- Produces: `GET tasks/boards/{board}/rows?presentation=<p>&window=<json>` returning `{data:[...], meta:{summary:{...}, omitted:{...}, window:{...}}}`. `omitted` carries `undated`, `outside_window` and `unowned` counts. Later tasks read `meta.omitted`.

- [ ] Write failing tests: a calendar window returns every accessible task whose due date falls inside it and no task outside; a timeline window returns every task whose **span intersects** the range, including one starting before the window and ending inside it; a kanban window returns the complete accessible set per named bucket rather than a page; `meta.summary` describes the complete filtered accessible result and not the returned slice; `meta.omitted.undated` counts tasks excluded for having no date; two requests differing only in `from`/`to` produce **different ETags**; and a revoked private membership stops returning rows mid-session.
- [ ] Run `DB_DATABASE=ezbizi_testing_tasks php -d memory_limit=1G vendor/bin/phpunit tests/Feature/Tasks/TaskWindowedRowsTest.php` and confirm failure because `window` is not accepted.
- [ ] Add window-aware querying to `TaskListQuery`. Timeline intersection is `(start_on IS NULL OR start_on <= :to) AND (due_on IS NULL OR due_on >= :from)` with tasks having neither date excluded and counted as omitted. Calendar filters on `due_on` alone.
- [ ] Keep the summary computed over the complete filtered accessible result before windowing. A Kanban column header reading 12 when the board holds 40 matching tasks is the defect this rule exists to prevent.
- [ ] Fold the window into the conditional ETag alongside Phase 1's existing dependency-complete cache key. A window change must miss the cache.
- [ ] Reuse the Phase 1 access path unchanged. Do not add a second scope or private-board filter; if the existing prefilter needs a new argument, pass one — do not copy it.
- [ ] Break the summary rule on purpose (compute it after windowing), confirm the test fails, restore.
- [ ] Commit via §4: both modified files and the test. Message: `feat(tasks): serve windowed rows`.

---

## Milestone 2 — Dependencies, recurrence, workload

### Task 4: Add display-only dependencies

**Files:**
- Create: `app/Support/Tasks/TaskDependencyWriter.php`
- Create: `app/Support/Tasks/TaskDependencyCycle.php`
- Create: `app/Http/Controllers/Api/Tasks/TaskDependenciesController.php`
- Modify: `routes/api.php`
- Modify: `app/Support/Access/Refusal.php`
- Test: `tests/Feature/Tasks/TaskDependenciesTest.php`

**Interfaces:**
- Produces: `TaskDependencyWriter::add(User $actor, Task $blocked, Task $blocker): TaskDependency` and `::remove(User $actor, TaskDependency $edge): void`. Routes `POST tasks/{task}/dependencies` and `DELETE tasks/dependencies/{dependency}`. Task detail responses gain `blocked_by:[{id, task_id, number, title}]`.

- [ ] Write failing tests: a direct cycle (A blocks B, B blocks A) is refused with a sentence naming it; an indirect cycle (A→B→C→A) is refused; a cross-board pair is refused; a duplicate edge is refused; a task the actor cannot see is refused **without disclosing whether it exists**; adding and removing each write an audit row and emit `task.dependency_added` / `task.dependency_removed` to the outbox inside the transaction; deleting either task removes the edge; and **completing a blocked task succeeds** — dependencies display, they do not enforce.
- [ ] Run `DB_DATABASE=ezbizi_testing_tasks php -d memory_limit=1G vendor/bin/phpunit tests/Feature/Tasks/TaskDependenciesTest.php` and confirm failure because the writer does not exist.
- [ ] Implement `TaskDependencyCycle` as a bounded walk from the proposed blocked task, run **inside the writer's transaction** so a concurrent insert cannot slip a cycle past it. Bound the depth; a board exceeding it refuses rather than walking forever.
- [ ] Implement `TaskDependencyWriter` with `tasks.edit` on the board, the same-board rule, cycle rejection, a classified audit row, and the outbox event — all in one transaction.
- [ ] Add exactly two refusal sentences to `Refusal.php` and render **those** on the client. Do not write a second copy of a reason. Adding new sentences is additive; do not reword an existing one while `ACTIVE-CONSTRAINTS.md` exists.
- [ ] Add `blocked_by` as a **new field** on both the task detail response and the rows returned for `presentation=timeline` — Task 12 draws lines between bars and cannot do it from detail responses it has not fetched. Shape is identical in both places: `[{id, task_id, number, title}]`. Omit it from the other presentations; a Kanban card has nowhere to draw a line and the payload would be dead weight. Existing fields keep their names and meanings so the frozen client is unaffected.
- [ ] Break cycle rejection on purpose, confirm the indirect-cycle test fails, restore.
- [ ] Commit via §4. Message: `feat(tasks): add display-only dependencies`.

### Task 5: Declare recurrence on a task

**Files:**
- Create: `app/Support/Tasks/TaskRecurrenceCodec.php`
- Modify: `app/Support/Tasks/TaskWriter.php`
- Test: `tests/Feature/Tasks/TaskRecurrenceDeclarationTest.php`

**Interfaces:**
- Produces: `TaskRecurrenceCodec::parse(?array $raw): ?TaskRecurrence` and `::encode(TaskRecurrence $r): array`, over `{mode:"after_completion"|"on_schedule", every:int>=1, unit:"day"|"week"|"month", until:?date, count:?int>=1}`. `TaskWriter::setRecurrence(User $actor, Task $task, ?array $raw, int $version): Task`.

- [ ] Write failing tests: both modes round-trip; `every:0`, a negative `count`, an unknown `unit` and an unknown `mode` are each refused; `until` and `count` may not both be set; the declaration is a versioned write that conflicts on a stale version; it writes an audit row; clearing it to `null` is allowed and audited; and the JSON is read **by key** so a differently-ordered payload parses identically.
- [ ] Run `DB_DATABASE=ezbizi_testing_tasks php -d memory_limit=1G vendor/bin/phpunit tests/Feature/Tasks/TaskRecurrenceDeclarationTest.php` and confirm failure because the codec does not exist.
- [ ] Implement the codec. Never assert on JSON key order — MySQL does not preserve it and Phase 1 was explicit about this.
- [ ] Add `setRecurrence` to `TaskWriter` using Phase 1's optimistic-concurrency and audit path. Do not add a second write path.
- [ ] Break the `until`/`count` exclusivity on purpose, confirm the test fails, restore.
- [ ] Commit via §4. Message: `feat(tasks): declare recurrence on a task`.

### Task 6: Create recurring instances

**Files:**
- Create: `app/Support/Tasks/TaskRecurrenceWorker.php`
- Create: `app/Console/Commands/CreateRecurringTasks.php`
- Modify: `app/Support/Tasks/TaskWriter.php`
- Modify: `routes/console.php`
- Test: `tests/Feature/Tasks/TaskRecurrenceWorkerTest.php`

**Interfaces:**
- Consumes: `TaskRecurrenceCodec` from Task 5, `TaskRecurrenceOccurrence` from Task 1.
- Produces: command `ezbizi:create-recurring-tasks`, scheduled hourly with an overlap lock.

- [ ] Write failing tests: `after_completion` creates exactly one instance dated from the completion, and creates nothing while the task stays open; `on_schedule` creates instances on the calendar whether or not the previous was finished; a **retried run creates no duplicate** and an **overlapping run creates no duplicate**, both proven through the `(task_id, occurrence_date)` uniqueness; a day-boundary case resolves in the board's place timezone rather than UTC; `until` and `count` exhaustion stop the series and emit `task.recurrence_completed`; and a created instance copies title, description, owner, collaborators, group, priority and custom values but **not** updates, files, activity, completion state, or dependencies.
- [ ] Run `DB_DATABASE=ezbizi_testing_tasks php -d memory_limit=1G vendor/bin/phpunit tests/Feature/Tasks/TaskRecurrenceWorkerTest.php` and confirm failure because the worker does not exist.
- [ ] Implement the worker on the Phase 1 pattern in `TaskDueWorker.php` — bounded batch, its own overlap lock distinct from the overdue and reminder locks, board-place timezone through `SettingsResolver`, occurrence row written in the same transaction as the created task.
- [ ] Hook `after_completion` into `TaskWriter`'s completion path so completing a recurring task enqueues its next occurrence date; the worker still does the creating, so a failed rule can never fail the completion.
- [ ] Do **not** copy dependencies to the new instance. By the time it exists its blocker is finished, and the line would mean nothing.
- [ ] Break the occurrence uniqueness on purpose, confirm the overlapping-run test fails, restore.
- [ ] Commit via §4. Message: `feat(tasks): create recurring instances`.

### Task 7: Aggregate workload

**Files:**
- Create: `app/Support/Tasks/TaskWorkloadQuery.php`
- Modify: `app/Http/Controllers/Api/Tasks/TaskBoardRowsController.php`
- Test: `tests/Feature/Tasks/TaskWorkloadTest.php`

**Interfaces:**
- Consumes: `TaskWindow` from Task 2, the access path from Task 3.
- Produces: for `presentation=workload`, `{data:[{owner_id|null, owner_name, units:[{date, count}]}], meta:{summary, omitted}}`. `owner_id: null` is the Unassigned row.

- [ ] Write failing tests: a task with both dates counts on **every unit its span covers**; a task with only a due date counts on that date alone; archived and completed tasks are excluded; unowned tasks appear in an explicit `owner_id: null` row rather than being dropped; the `week` unit buckets by the organization's week start; and a person with no tasks in the window does not appear as a phantom zero row.
- [ ] Run `DB_DATABASE=ezbizi_testing_tasks php -d memory_limit=1G vendor/bin/phpunit tests/Feature/Tasks/TaskWorkloadTest.php` and confirm failure.
- [ ] Implement `TaskWorkloadQuery` as SQL aggregation over the bounded window; do not load rows into PHP and count them there.
- [ ] Emit **no** capacity, threshold or over-capacity field. EzBizi does not know how many hours anyone has, and a number invented here would be presented by the screen as fact.
- [ ] Break the span-covering rule on purpose (count only on the due date), confirm the test fails, restore.
- [ ] Commit via §4. Message: `feat(tasks): aggregate workload by owner`.

### Task 8: Register the Phase 2 automation triggers

**Files:**
- Modify: `app/Providers/TaskServiceProvider.php`
- Test: `tests/Feature/Tasks/TaskAutomationsTest.php`

- [ ] Extend the existing test to assert the module declares `task.dependency_added`, `task.dependency_removed` and `task.recurrence_completed`; that each is applicable to the Task subject; that the existing nine triggers and six actions are **unchanged**; and that `ModuleRegistryTest` still passes.
- [ ] Run `DB_DATABASE=ezbizi_testing_tasks php -d memory_limit=1G vendor/bin/phpunit tests/Feature/Tasks/TaskAutomationsTest.php tests/Feature/ModuleRegistryTest.php` and confirm the three new triggers are missing.
- [ ] Register the three triggers. Add **no** new actions: creating a recurring instance is what the worker does, not something a rule invokes.
- [ ] Assert the module still declares exactly the seven Phase 1 permissions — `tasks.view`, `create`, `edit`, `comment`, `delete`, `export`, `manage` — and no more. Spec §14 is explicit that Phase 2 adds no capability those do not already describe, and a permission added by accident is a data migration across every role in every organization later.
- [ ] Confirm the catalogue serialization stays backward compatible — a frozen client reading the module catalogue must not break on the new entries.
- [ ] Commit via §4. Message: `feat(tasks): register phase 2 triggers`.

---

## Milestone 3 — The five presentations

### Task 9: Extend the client contract

**Files:**
- Modify: `client/lib/screens/tasks/task_models.dart`
- Modify: `client/lib/screens/tasks/task_api.dart`
- Test: `client/test/tasks/task_models_test.dart`
- Test: `client/test/tasks/task_api_test.dart`

**Interfaces:**
- Produces: `TaskPresentation` enum, `TaskWindow` sealed classes, `TaskRow.startDate`, `TaskRow.blockedBy`, `TaskDetail.recurrence`, `TaskViewConfiguration.presentation` and `.window`, `TaskOmitted {undated, outsideWindow, unowned}`. `TaskApi.rows({presentation, window})`, `.addDependency()`, `.removeDependency()`, `.setRecurrence()`.

- [ ] Write failing Dart tests: a `schema:1` configuration decodes with `presentation: table` and a null window; each window type round-trips; an unknown presentation from the server decodes to `table` rather than throwing, so a server ahead of the client degrades instead of crashing; `meta.omitted` parses; and each new command sends the version the server expects.
- [ ] Run `cd client; flutter test test/tasks/task_models_test.dart test/tasks/task_api_test.dart` and confirm failure.
- [ ] Implement the models and commands. Keep every Phase 1 field name and meaning; these are additive.
- [ ] Break the unknown-presentation fallback on purpose, confirm the test fails, restore.
- [ ] Commit via §4. Message: `feat(tasks): extend the client contract for phase 2`.

### Task 10: Kanban

**Files:**
- Create: `client/lib/screens/tasks/task_kanban_view.dart`
- Create: `client/lib/screens/tasks/task_presentation_bar.dart`
- Modify: `client/lib/screens/tasks_screen.dart`
- Test: `client/test/tasks/task_kanban_test.dart`

- [ ] Write failing widget tests: columns render for the chosen `bucket_field`; **empty buckets still render** — hiding them hides that nothing is in review; dragging a card issues the field's existing write with its version; a read-only or archived bucket field renders columns but refuses the drag with the server's sentence; the column header count is the complete accessible total, not the number of cards drawn; at 375px the view becomes one column with a bucket selector and the body does not scroll sideways; and **no `AlertDialog` or `showDialog` appears anywhere in the flow**.
- [ ] Run `cd client; flutter test test/tasks/task_kanban_test.dart` and confirm failure.
- [ ] Implement the Kanban renderer and the presentation bar. Route it through the existing `_content` chain in `tasks_screen.dart` beside `_NewBoardPane` and `NameEntryPanel`; do not invent a second routing pattern.
- [ ] Colours from `theme.dart`. No literal hex on a column header.
- [ ] Break the empty-bucket rule on purpose, confirm the test fails, restore.
- [ ] Commit via §4. Message: `feat(tasks): add the kanban presentation`.

### Task 11: Calendar

**Files:**
- Create: `client/lib/screens/tasks/task_calendar_view.dart`
- Test: `client/test/tasks/task_calendar_test.dart`

- [ ] Write failing widget tests: a month grid renders tasks on their due dates; **the count of undated tasks is stated on screen** — silence would read as "nothing is undated"; dragging to another day issues the Phase 1 due-date write with its version; a task due at 23:00 in the board's place timezone renders on the correct day for a viewer in another timezone; at 375px the month becomes a week list; and no dialog appears.
- [ ] Run `cd client; flutter test test/tasks/task_calendar_test.dart` and confirm failure.
- [ ] Implement the Calendar renderer. Resolve the timezone from the board's place as the server sends it; do not compute it from the device clock.
- [ ] Break the undated-count display on purpose, confirm the test fails, restore.
- [ ] Commit via §4. Message: `feat(tasks): add the calendar presentation`.

### Task 12: Timeline and dependency lines

**Files:**
- Create: `client/lib/screens/tasks/task_timeline_view.dart`
- Create: `client/lib/screens/tasks/task_dependency_panel.dart`
- Modify: `client/lib/screens/tasks/task_detail_panel.dart`
- Test: `client/test/tasks/task_timeline_test.dart`

- [ ] Write failing widget tests covering all four render states: both dates → a bar; due only → a marker; start only → a marker; neither → not drawn and counted in the omitted total. Then: dragging an edge writes that one date; dragging the whole bar writes both in one command at one version; `start_on` after `due_on` is refused with the server's sentence and **not silently swapped**; dependency lines are drawn between bars and a "Blocked by" chip appears on the detail; adding a dependency happens in a **panel**, not a dialog; and the timeline scrolls horizontally inside its own container at 375px while the page body does not.
- [ ] Run `cd client; flutter test test/tasks/task_timeline_test.dart` and confirm failure.
- [ ] Implement the Timeline renderer and the dependency panel. A dependency line is a line: no drag-to-reschedule, no propagation.
- [ ] Break the start-after-due refusal on purpose, confirm the test fails, restore.
- [ ] Commit via §4. Message: `feat(tasks): add the timeline presentation`.

### Task 13: Workload and recurrence UI

**Files:**
- Create: `client/lib/screens/tasks/task_workload_view.dart`
- Create: `client/lib/screens/tasks/task_recurrence_panel.dart`
- Modify: `client/lib/screens/tasks/task_detail_panel.dart`
- Test: `client/test/tasks/task_workload_test.dart`

- [ ] Write failing widget tests: per-owner rows render counts per unit; the label says **tasks**, never hours; the Unassigned row renders and is not dropped; **no capacity line, threshold, or over-capacity colour exists anywhere in the widget tree**; the recurrence panel offers both modes with plain wording ("when it is completed" / "on a fixed schedule"); it is a panel, not a dialog; and 375px works.
- [ ] Run `cd client; flutter test test/tasks/task_workload_test.dart` and confirm failure.
- [ ] Implement both. The recurrence panel writes through `TaskApi.setRecurrence` with the task version.
- [ ] Break the tasks-not-hours label on purpose, confirm the test fails, restore.
- [ ] Commit via §4. Message: `feat(tasks): add workload and recurrence UI`.

### Task 14: Cross-board summaries

**Files:**
- Modify: `client/lib/screens/tasks/my_tasks_view.dart`
- Modify: `app/Http/Controllers/Api/Tasks/MyTasksController.php`
- Test: `tests/Feature/Tasks/MyTasksApiTest.php`
- Test: `client/test/tasks/task_views_and_search_test.dart`

- [ ] Write failing tests: Timeline, Calendar and Workload may be opened across the current EzBizi view; they return only boards the viewer may already open, proven with a sibling company present in `/me`, a company-to-organization reach, and a private non-member; a membership revoked mid-session stops returning that board's rows; and cross-board views are **read-only** — no drag target exists in the widget tree.
- [ ] Run both suites and confirm failure.
- [ ] Reuse My Tasks' access path **verbatim**. Phase 1 proved it against a two-process membership revocation race; do not write a second one. If it needs a presentation argument, pass one.
- [ ] Break the read-only rule on purpose (enable a drag target), confirm the test fails, restore.
- [ ] Commit via §4. Message: `feat(tasks): add cross-board summaries`.

---

## Milestone 4 — Gates

### Task 15: End-to-end security and workflow coverage

**Files:**
- Create: `tests/Feature/Tasks/TaskPhaseTwoSecurityTest.php`
- Modify: `tests/Feature/Tasks/TaskWorkflowTest.php`
- Modify: `client/test/tasks/tasks_golden_test.dart`
- Create: goldens under `client/test/goldens/tasks/` for each presentation at 1440×900 and 375×812

- [ ] Attack every presentation with foreign board, group, owner, column, bucket and task identities; a sibling company in `/me`; a company-to-organization reach; a private non-member; and a membership revoked mid-read. **A presentation must never widen access by one row.**
- [ ] Prove over-wide windows and oversized bucket sets are refused rather than silently truncated, and that failure leaves no business row, activity, audit row, outbox event or occurrence marker behind.
- [ ] Extend the workflow test end to end: create a board, add a dependency, declare recurrence, complete the series task, watch the worker create the next instance, open each presentation, and verify the audit and outbox trail.
- [ ] Generate goldens with `cd client; flutter test test/tasks/tasks_golden_test.dart --update-goldens`, **look at every PNG** against the accepted layout, record approval in `docs/previews/tasks-layouts-review.md`, then rerun without the flag. Phase 1's goldens caught real regressions only because somebody looked at the images.
- [ ] Run `DB_DATABASE=ezbizi_testing_tasks php -d memory_limit=1G vendor/bin/phpunit tests/Feature/Tasks` and `cd client; flutter test test/tasks; flutter analyze`.
- [ ] Commit via §4. Message: `test(tasks): cover phase 2 isolation and workflow`.

### Task 16: Documentation, versioning, board, and final gates

**Files:**
- Modify: `docs/EZBIZI-STATUS.md`, `docs/EZBIZI-TODO.md`, `docs/EZBIZI-BUILD-LOG.md`
- Modify: `client/VERSION`, `client/lib/core/version_history.dart`
- Modify: `H:\Development\integrations-board\data.json`

- [ ] Document Phase 2 behaviour: the presentation contract and the `schema:1`→`schema:2` upgrade-on-read, windows and their bounds, display-only dependencies and why they do not enforce, workload counting tasks rather than hours, both recurrence modes, cross-board read-only summaries, and the explicit Phase 3 boundary. Record that server-push was dropped and why, so the decision is not silently revisited.
- [ ] Bump `client/VERSION` **once** with a user-facing history entry. Never per task. Expect the store train to have moved it independently; rebase onto what is there rather than assuming your number.
- [ ] Run `git diff --check`, `php composer.phar audit`, the full `DB_DATABASE=ezbizi_testing_tasks php -d memory_limit=1G vendor/bin/phpunit`, then `cd client; flutter analyze; flutter test`. Read the complete branch diff and untracked-file list for credential values and private keys without truncating.
- [ ] **State which commit every result belongs to.** `flutter analyze` and `flutter test` read the working tree, always — in a tree many sessions share, their output is a claim about other people's unsaved edits unless run in a worktree at that SHA. Three sessions reported a phantom red on 2026-08-28 this way.
- [ ] Ask an independent reviewer to check the implementation against the spec and this plan. Resolve every P0/P1/P2 or record an owner-accepted exception in `docs/EZBIZI-STATUS.md`.
- [ ] Tick the board card and set `tasks-module-phase-2` to done **only after a gate has actually gone green and you have seen it**. Phase 1's Task 20 sat blocked for two days on a gate that could never pass; a green nobody watched is not a green.
- [ ] Hand the SHAs to the `ezbizi-a1` session with one line of what changed and what to watch, flagging that Timeline reuses the existing `start_on` column rather than adding one, the `schema:2` view configuration and the three new triggers explicitly. **Do not deploy.**
- [ ] Use `superpowers:verification-before-completion`, then `superpowers:finishing-a-development-branch`.
- [ ] Final commit via §4. Message: `docs: record Tasks phase 2`.

---

## Phase 3 boundary

Unchanged from the Phase 1 design §20 and restated in the Phase 2 spec §19: forms, reusable board templates, chart/dashboard views, formula/roll-up/connected-board columns, external guest boards, direct monday.com migration, advanced reporting, and controlled public/embed surfaces.

Two carry notes from Phase 2. **Connected-board columns** are the natural home for cross-board dependencies, which Task 4 deliberately refuses. **External guests** need an identity model that exists nowhere in EzBizi today and should be scoped as its own project rather than a Phase 3 bullet.

Phase 3 receives its own design, plan and review gate.
