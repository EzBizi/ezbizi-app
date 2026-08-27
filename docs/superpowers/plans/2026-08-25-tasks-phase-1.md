# Feature Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Deliver the permanent core of an EzBizi Tasks module that replaces ordinary Monday.com boards: scoped boards, groups, tasks, one-level subtasks, typed custom columns, private membership, updates, EzBizi record links, saved table views, My Tasks, notifications, automations, import/export, and responsive desktop/mobile UI.

**Architecture:** EzBizi's active organization/company/franchise view is the workspace. Every board has one immutable filing place and every read/write is constrained by both `ViewScope` and board access. Laravel owns authorization, typed values, transactions, audit/outbox work, query/view semantics, imports, and exports. Flutter renders server-owned board state and sends versioned commands. Milestone 0 first lands customer-file backup/restore support with a disposable local proof and produces reviewable HTML layouts. Non-file work may continue while the approved production restore check is arranged, but Task 10A must pass before any production-capable Tasks path stores file bytes; no production widget work begins until one layout is accepted.

**Tech Stack:** PHP 8.4 runtime (Composer floor `^8.3`), Laravel 13, MySQL 8, PHPUnit 12, Flutter/Dart 3.11, Material 3, `flutter_html:^3.0` for the strict rich-text display subset, `url_launcher:^6.3` for validated external HTTPS links, `phpoffice/phpspreadsheet:^5.9` for XLSX, existing EzBizi audit/automation/notification/attachment/privacy infrastructure.

**Spec:** [`docs/superpowers/specs/2026-08-25-tasks-design.md`](../specs/2026-08-25-tasks-design.md)

**Codex review:** [`docs/superpowers/reviews/2026-08-26-tasks-codex-review.md`](../reviews/2026-08-26-tasks-codex-review.md)

**Codex–Grok consensus:** [`docs/superpowers/reviews/2026-08-26-tasks-codex-grok-consensus.md`](../reviews/2026-08-26-tasks-codex-grok-consensus.md)

**Layout review:** [`docs/previews/tasks-layouts-review.md`](../../previews/tasks-layouts-review.md) · [interactive HTML](../../previews/tasks-layouts.html)

## Global Constraints

- [ ] Start execution in an isolated `codex/` worktree using `superpowers:using-git-worktrees`; do not mix this module with any pre-existing feature work in the current tree.
- [ ] Create or resume the `tasks-module` card in `H:\Development\integrations-board` before work. At implementation kickoff, expand it with one checklist step for every numbered task in this plan; tick each completed step in the same turn and record external waits without secrets.
- [ ] Keep board filing on the exact current view. A Tasks endpoint must never choose a place by iterating raw `/me.companies` or `/me.franchises`; it must derive filing IDs from `ViewScope::$type` / `$id` and the board's immutable place. Reads may include descendant boards covered by the current `ViewScope`; creation never defaults into a descendant.
- [ ] Treat organization, company, and franchise filing places as different authority levels. A company view cannot create or mutate an organization board, and a sibling company/franchise merely present in `/me` does not confer visibility.
- [ ] Require both module permission and record access. Private membership narrows access; it never grants a missing `tasks.view` or a missing place permission.
- [ ] Put multi-table writes in one database transaction. Audit rows and automation outbox events are part of that transaction; notifications dispatch after commit.
- [ ] Maintain a mutation matrix for every board/configuration/recovery/membership, group/option/column/view, task/bulk/order, participant/watch, custom value/file, update/revision/mention, record-link, and import mutation. Controllers, importers, commands, and automation actions call writers; they never write these models directly. Each writer inserts its classified audit row and, where declared/applicable, its outbox event, durable notification intent, and human activity row in the same transaction.
- [ ] Use optimistic concurrency (`version`) for task edits and atomic ordering for boards, groups, tasks, subtasks, columns, statuses, and priorities.
- [ ] Archive business records by default. Hard deletion is restricted to the existing privacy/retention workflows.
- [ ] Add no speculative Phase 2/3 schema. Dependencies, Gantt, formula, rollup, connected-board, forms, charts, workload, guest access, and direct Monday API migration remain outside this plan.
- [ ] Follow TDD in every task: write the named failing test, run it and observe the intended failure, add the smallest implementation, rerun the focused test, then run the milestone suite.
- [ ] Do not deploy from a feature branch. When every gate passes, use `superpowers:finishing-a-development-branch`; deployment remains a separate owner-approved step through `scripts/deploy.sh`.

## Contract and File Map

The implementation must converge on these public service contracts. Controllers stay thin and may not reproduce their authorization or transaction rules.

```php
final class TaskBoardAccess
{
    public function constrainVisible(Builder $query, User $user, ViewScope $scope): Builder;
    public function constrainBackgroundVisible(Builder $query, User $user): Builder;
    public function createDecision(User $user, ViewScope $scope): Response;
    public function backgroundViewDecision(User $user, TaskBoard $board): Response;
    public function viewDecision(User $user, TaskBoard $board, ViewScope $scope): Response;
    public function manageDecision(User $user, TaskBoard $board, ViewScope $scope): Response;
}

final class TaskWriter
{
    public function create(User $actor, TaskBoard $board, ViewScope $scope, array $input): Task;
    public function update(User $actor, Task $task, ViewScope $scope, int $expectedVersion, array $input): Task;
    public function archive(User $actor, Task $task, ViewScope $scope, int $expectedVersion): Task;
    public function restore(User $actor, Task $task, ViewScope $scope, int $expectedVersion): Task;
}

interface TaskRecordLinkDefinition
{
    public function key(): string;
    public function label(): string;
    public function search(User $user, TaskBoard $board, ViewScope $scope, string $query, int $limit = 20): array;
    public function resolve(User $user, TaskBoard $board, ViewScope $scope, string $id): ?Model;
    public function snapshot(Model $record): array;
    public function deepLink(Model $record): array;
    public function privacyEntries(): array;
}

final class TaskColumnValueStore
{
    public function replace(User $actor, Task $task, TaskColumnDefinition $column, ViewScope $scope, int $expectedVersion, mixed $value): Task;
}
```

Primary new locations:

| Area | Files |
|---|---|
| Enums/contracts | `app/Support/Tasks/{BoardVisibility,BoardMemberRole,StatusCategory,PriorityRank,ColumnType,TaskRecordLinkDefinition}.php` |
| Domain services | `app/Support/Tasks/{TaskPlace,TaskBoardAccess,TaskBoardWriter,TaskWriter,TaskColumnValueStore,TaskListQuery,TaskViewCodec,TaskRecordLinkRegistry,TaskUpdateWriter,TaskNotificationIntentDispatcher,TaskTransfer}.php` |
| Models | `app/Models/Task*.php` and `app/Models/Task.php` |
| API | `app/Http/Controllers/Api/Tasks/*.php`, `routes/api.php` |
| Policies | `app/Policies/{TaskBoardPolicy,TaskPolicy,TaskUpdatePolicy}.php` |
| Platform | `app/Providers/{ModuleServiceProvider,TaskServiceProvider}.php`, `app/Support/Dashboard/Glances/TasksGlance.php`, `app/Support/Dashboard/GlanceRegistry.php`, `app/Support/Files/Attachables.php`, `routes/console.php` |
| Client | `client/lib/screens/tasks_screen.dart`, `client/lib/screens/tasks/*.dart`, `client/lib/core/{address,module_screens}.dart`, `client/lib/main.dart`, `client/lib/widgets/notification_bell.dart` |
| Tests | `tests/Feature/Tasks/*.php`, `tests/Feature/Dashboard/TasksGlanceTest.php`, `client/test/tasks/*.dart` |
| Review artifacts | `docs/previews/tasks-layouts.html`, `docs/previews/tasks-layouts-review.md` |

## Milestone 0 — Agree on the Interface

### Task 0: Build customer-file backup and restore verification

**Files:**
- Modify: `deploy/backup.sh`
- Modify: `deploy/restore-check.sh`
- Modify: `docs/EZBIZI-STATUS.md`
- Modify: `docs/EZBIZI-DEV-ENVIRONMENT.md`
- Modify: `H:\Development\integrations-board\data.json`

- [ ] Treat the later production proof as a release-blocking prerequisite for every Tasks path that stores real import or attachment bytes. Schema work and fake/disposable-disk tests may proceed, but no production-capable File-column upload/selection, attachment-bearing update, or import staging path is enabled while the configured `local` disk is instance-only.
- [ ] Extend the encrypted nightly backup to include `storage/app/private` with a versioned manifest of relative path, byte length, and SHA-256. Preserve the existing database/git/`.env` backup behavior, permissions, retention, off-instance copy, and failure reporting; never log a filename or customer value.
- [ ] Extend `restore-check.sh` to restore that archive into a new temporary directory, reject traversal/symlink entries, compare every manifest row and checksum, and remove only the verified temporary directory. A missing archive, missing row, extra row, checksum mismatch, decryption failure, or extraction failure makes the nightly health result fail.
- [ ] Run the shell syntax checks and a disposable fixture backup/restore test locally before Task 2. Leave the production-sized restore/checksum run to the explicit Task 10A operations gate; Tasks 2–10 may proceed without persisting Tasks file bytes.
- [ ] Commit the infrastructure change separately; do not deploy it from the Tasks feature branch without the owner's normal deployment approval. Leave FileStore's instance-loss warning intact until Task 10A proves the production restore.
- [ ] Commit: `git add deploy/backup.sh deploy/restore-check.sh docs/EZBIZI-STATUS.md docs/EZBIZI-DEV-ENVIRONMENT.md && git commit -m "ops: restore-check private customer files"`

**Green checkpoint:** backup/restore-check code passes syntax and the disposable fixture; production-capable Tasks file paths remain closed pending Task 10A.

### Task 1: Produce and select three real layouts

**Files:**
- Create: `docs/previews/tasks-layouts.html`
- Create: `docs/previews/tasks-layouts-review.md`
- Verify: `docs/superpowers/specs/2026-08-25-tasks-design.md`

- [x] Build three navigable HTML alternatives using the actual EzBizi sidebar/top-bar chrome: **A Dense Grid**, **B Calm Operations**, and **C Board + Detail**. Each alternative must include a desktop width and a 375px mobile state, To-Do/Completed groups, owner/status/due/custom cells, resizable/collapsible detail, toolbar, My Tasks, private marker, updates panel, board settings, one import-preview/mapping flow, empty state, loading state, refusal state, and conflict banner.
- [x] Use Monday only as an interaction reference. Use EzBizi tokens, typography, spacing, controls, permission language, and current-view switcher behavior.
- [x] Open `docs/previews/tasks-layouts.html` locally at 1440px and 375px. Record overflow, keyboard order, contrast, truncation, and touch-target findings in `docs/previews/tasks-layouts-review.md`.
- [x] Get the owner to select one alternative. The owner selected **A — Dense Grid** on 2026-08-26; the decision and its unmerged boundary are recorded in `tasks-layouts-review.md`.
- [ ] Commit: `git add docs/previews/tasks-layouts.html docs/previews/tasks-layouts-review.md && git commit -m "docs: prototype Tasks layouts"`

**Green checkpoint:** one layout is explicitly accepted, with desktop and mobile behavior recorded.

## Milestone 1 — Domain, Storage, and Access

### Task 2: Add stable enums and the board schema

**Files:**
- Create: `app/Support/Tasks/BoardVisibility.php`
- Create: `app/Support/Tasks/BoardMemberRole.php`
- Create: `app/Support/Tasks/StatusCategory.php`
- Create: `app/Support/Tasks/PriorityRank.php`
- Create: `app/Support/Tasks/ColumnType.php`
- Create: `database/migrations/2026_08_25_170000_create_task_board_tables.php`
- Test: `tests/Feature/Tasks/TaskBoardSchemaTest.php`

- [ ] Write schema tests proving foreign keys, unique ordering within the parent, all three exact place shapes including organization-level null IDs, private membership uniqueness, member roles, board `structure_version`, group `order_version`, core-column settings, board preferences, and status/priority category checks.
- [ ] Run `php -d memory_limit=1G vendor/bin/phpunit tests/Feature/Tasks/TaskBoardSchemaTest.php` and confirm failure because the tables/enums do not exist.
- [ ] Add backed string enums with exactly these values: visibility `scope|private`; member role `owner|member`; status category `not_started|active|blocked|completed|cancelled`; priority rank `low|normal|high|urgent` (`null` means no priority); column type `text|number|checkbox|date|people|label|link|file`.
- [ ] Create `task_folders`, `task_boards`, `task_board_members`, `task_board_preferences`, `task_groups`, `task_core_column_settings`, `task_status_options`, `task_priority_options`, `task_column_definitions`, and `task_column_options`. Folders and boards both carry the immutable nullable company/franchise place shape. Preferences are unique by board/user and store `is_favorite` plus nullable `last_opened_at`; they never grant access. Groups have `order_version` default 1. Core settings are unique by board/stable core key and store only the shared display label. Boards have `name`, optional `description`, `visibility`, `structure_version` default 1, `created_by`, `updated_by`, position/timestamps/archive, and no `owner_user_id` or mutable generic scope pair. Creation later inserts the creator as an owner membership. Membership stores `role`, active/archive state, and unique `(board_id,user_id)` for both visibility modes.
- [ ] Before Tasks FKs, add/reuse indexed candidate keys `companies(organization_id,id)`, `franchises(organization_id,company_id,id)`, and `users(organization_id,id)`. Follow Inventory/Services' nullable-place pattern: store a non-null **stored generated** `place_key` on folder and board; reference the company/franchise tuples for non-null ancestry; expose folder candidate key `(organization_id,id,place_key)`; and make board `(organization_id,folder_id,place_key)` reference it. Add `CHECK (franchise_id IS NULL OR company_id IS NOT NULL)` to both directly place-owned tables. Use `RESTRICT` on place/base-column and generated-key relationship FKs—MySQL forbids `SET NULL`/`CASCADE` actions there. Direct MySQL tests first prove the parent candidate keys exist, then reject organization/company/franchise/folder mismatches without an HTTP endpoint.
- [ ] Constrain option `category`/`rank` to the enum values and keep display labels/colors editable. `NULL` is the only None-priority state. Column definitions store nullable `number_suffix`; type is stored and never updated after values exist. Use the `users(organization_id,id)` candidate key for compound tenant/user foreign keys on creators, updaters, and board memberships.
- [ ] Run the focused PHPUnit test and let `RefreshDatabase` rebuild through `phpunit.xml`, which pins `DB_DATABASE=ezbizi_testing`. Never run `php artisan migrate:fresh --env=testing`; this repository has no `.env.testing`, and that command may resolve the wrong database.
- [ ] Commit: `git add app/Support/Tasks database/migrations tests/Feature/Tasks/TaskBoardSchemaTest.php && git commit -m "feat(tasks): add board schema and stable enums"`

### Task 3A: Add core task and typed-value storage

**Files:**
- Create: `database/migrations/2026_08_25_171000_create_task_core_tables.php`
- Modify: `app/Support/Numbering/RecordNumberGenerator.php`
- Modify: `app/Support/Settings/SettingRegistry.php`
- Test: `tests/Feature/Tasks/TaskRecordSchemaTest.php`
- Test: `tests/Feature/Tasks/TaskNumberingTest.php`

- [ ] Write failing tests for one-level parent shape, top-level-group/subtask-parent exclusivity, same-board inherited group, parent archive markers, organization-wide task-number uniqueness, structural and tenant/user foreign keys, `version`/`detail_version`/`due_revision`, typed scalar/multi-value storage, and link label storage.
- [ ] Create `tasks` with UUIDv7, repeated organization/board identity, nullable `group_id`, nullable `parent_task_id`, organization-wide number, title, nullable safely formatted description plus plain-text projection, owner/status/priority, dates, `due_revision` default 0, completed/cancelled actors and times, creator/updater, position, core `version` default 1, `detail_version` default 1, nullable `archived_with_parent_id`, timestamps, and archive state. Add a CHECK requiring `(parent_task_id IS NULL AND group_id IS NOT NULL) OR (parent_task_id IS NOT NULL AND group_id IS NULL)`; a subtask's effective group is read by joining its parent, never copied into the child.
- [ ] Add compound foreign keys rejecting mismatched tenant, board, top-level group, same-board parent, archive parent, status, priority, owner, creator/updater, and completion/cancellation actor IDs even below services. Index top-level `(organization_id,board_id,group_id,position)` and subtask `(organization_id,board_id,parent_task_id,position)` reads. The writer still enforces that a parent has no parent because MySQL cannot express that cross-row depth rule as an ordinary check. Direct MySQL, writer, and import tests reject a child-supplied group and every same-board/different-effective-group attempt.
- [ ] Create `task_column_values` with nullable `text_value`, `number_value`, `boolean_value`, `date_value`, `url_value`, and `link_label_value`, unique per task/definition. Create People/Label/File join tables with compound task/board/definition/target identity.
- [ ] Add organization/company/franchise setting `numbering.task_prefix` default `T-`, `RecordNumberGenerator::TASKS = 'task'`, and a prefix branch that reads that setting from the board place while the counter remains organization-wide.
- [ ] Run the numbering race outside the usual uncommitted test transaction using committed rows and two database connections/processes; prove both allocations are distinct and sequential.
- [ ] Run `php -d memory_limit=1G vendor/bin/phpunit tests/Feature/Tasks/TaskRecordSchemaTest.php tests/Feature/Tasks/TaskNumberingTest.php`.
- [ ] Commit: `git add database/migrations app/Support/Numbering/RecordNumberGenerator.php app/Support/Settings/SettingRegistry.php tests/Feature/Tasks/TaskRecordSchemaTest.php tests/Feature/Tasks/TaskNumberingTest.php && git commit -m "feat(tasks): add core task storage"`

### Task 3B: Add collaboration, activity, and governed-link storage

**Files:**
- Create: `database/migrations/2026_08_25_172000_create_task_collaboration_tables.php`
- Test: `tests/Feature/Tasks/TaskCollaborationSchemaTest.php`

- [ ] Write failing tests for collaborator uniqueness, tenant/user identity, explicit watch preference, immutable update revisions, mentions, durable activity, governed-link uniqueness, and update-attachment candidate claiming.
- [ ] Create `task_collaborators`, `task_watch_preferences` (`watched|muted`), `task_updates` with independent integer version and idempotency key, `task_update_versions`, `task_update_mentions`, `task_update_attachment_candidates` (repeated organization/board/task, unique attachment, actor, 24-hour expiry), immutable `task_activities`, and `task_record_links`. Add/reuse unique `(organization_id,id)` on `attachments` so candidate and File-value compound foreign keys cannot cross tenants.
- [ ] Repeat organization/board/task identity and use compound foreign keys so no collaboration, People value, mention, activity, or link row can point across a tenant/board/task/user boundary.
- [ ] Keep `task_activities` as durable business history; it does not inherit the three-month `audit_events` window. Updates and revisions retain with their parent task under the explicit policy selected in Task 4.
- [ ] Run `php -d memory_limit=1G vendor/bin/phpunit tests/Feature/Tasks/TaskCollaborationSchemaTest.php`.
- [ ] Commit: `git add database/migrations tests/Feature/Tasks/TaskCollaborationSchemaTest.php && git commit -m "feat(tasks): add collaboration storage"`

### Task 3C: Add views, reminders, notifications, and import-session storage

**Files:**
- Create: `database/migrations/2026_08_25_173000_create_task_view_and_delivery_tables.php`
- Modify: `app/Models/Notification.php`
- Test: `tests/Feature/Tasks/TaskViewDeliverySchemaTest.php`

- [ ] Write failing tests for personal/shared view identity and version, per-user view preferences, one overdue occurrence per task/due-revision, one reminder per task/recipient/due-revision/lead-hours, notification intent lifecycle/lease/dedupe, unique visible notification delivery, and one-use import sessions.
- [ ] Create `task_views` with visibility/lock state, immutable Main marker, configuration, and version; `task_view_preferences`; `task_overdue_occurrences` and `task_due_reminder_occurrences` with due-date snapshots/revisions; content-free `task_notification_intents` with `kind`, required opaque `source_occurrence_key`, `status`, `available_at`, `claim_token`, `claimed_until`, `attempts`, classified `last_error_code`, and timestamps; and `task_import_sessions` with token hash, actor/board/active-view binding, disk/path/bytes/SHA-256, normalized mapping, board structure version, expiry, and consumed timestamp. Ordinary/recovery/membership notifications use the same transaction's audit-event UUID; scheduled notices use their occurrence-row UUID.
- [ ] Add one generic nullable UUID `delivery_key` to existing `notifications` and its fillable contract. Enforce unique `(organization_id,user_id,source,delivery_key)`; existing rows remain unrestricted because `delivery_key` is null. Tasks delivery uses `source='task'` plus the intent UUID as the key, keeps `subject_type/id` pointed at the actual Task, and stores no Tasks-specific notification column or intent-side delivered-notification ID. Repeat organization/board/user identity and add compound foreign keys on Tasks tables. Enforce unique `(organization_id,user_id,kind,source_occurrence_key)` and import nonce/one-use identity in the database, not with read-before-write races.
- [ ] Run `php -d memory_limit=1G vendor/bin/phpunit tests/Feature/Tasks/TaskViewDeliverySchemaTest.php`.
- [ ] Commit: `git add database/migrations tests/Feature/Tasks/TaskViewDeliverySchemaTest.php && git commit -m "feat(tasks): add views and delivery storage"`

### Task 4: Add models, factories, retention, and model-level invariants

**Files:**
- Create: `app/Models/Task.php`
- Create: `app/Models/{TaskFolder,TaskBoard,TaskBoardMember,TaskBoardPreference,TaskGroup,TaskCoreColumnSetting,TaskStatusOption,TaskPriorityOption,TaskColumnDefinition,TaskColumnOption,TaskColumnValue,TaskColumnPerson,TaskColumnLabel,TaskColumnFile,TaskCollaborator,TaskWatchPreference,TaskUpdate,TaskUpdateVersion,TaskUpdateMention,TaskUpdateAttachmentCandidate,TaskActivity,TaskRecordLink,TaskView,TaskViewPreference,TaskOverdueOccurrence,TaskDueReminderOccurrence,TaskNotificationIntent,TaskImportSession}.php`
- Create: `database/factories/TaskBoardFactory.php`
- Create: `database/factories/TaskFactory.php`
- Modify: `tests/Feature/Api/DataInventoryTest.php`
- Modify: `tests/Feature/RetentionTest.php`
- Test: `tests/Feature/Tasks/TaskModelTest.php`

- [ ] Write model tests for tenant scoping, casts, archived scopes, board/group/task relationships, task parent depth, non-mass-assignable core/detail/order/structure-version fields, and immutable history/revision rows. Writer increment/conflict assertions belong to Task 8.
- [ ] Implement explicit `$fillable`/casts/relationships and reuse the repository's tenant-scope trait. Do not use a generic entity-value model.
- [ ] Inventory every Tasks model by actual data content—not by whether it has obvious text. Classify titles/descriptions, custom text/links, owner/People/collaborator/watch/member/mention IDs, update revisions, candidate and committed filenames/metadata, record snapshots, saved search/filter data, notification/outbox/audit payloads, and import staging. Each model explicitly declares `HoldsPersonalData` or `StatesRetention`, parent retention, DSAR/export behavior, redaction/erasure behavior, and orphan cleanup.
- [ ] Run `php -d memory_limit=1G vendor/bin/phpunit tests/Feature/Tasks/TaskModelTest.php tests/Feature/Api/DataInventoryTest.php tests/Feature/RetentionTest.php`.
- [ ] Commit: `git add app/Models database/factories tests/Feature && git commit -m "feat(tasks): add task domain models"`

### Task 4A: Register the Tasks module contract

**Files:**
- Modify: `app/Support/Modules/ActionDefinition.php`
- Modify: `app/Providers/ModuleServiceProvider.php`
- Modify: `app/Http/Controllers/Api/AutomationsController.php`
- Test: `tests/Feature/Tasks/TaskModuleTest.php`
- Modify: `tests/Feature/Api/AutomationsApiTest.php`

- [ ] Write the failing module test before registering any permission so later policy tests use the real catalogue.
- [ ] Extend `ActionDefinition` with optional explicit `executionType` and `subjectBound` constructor arguments. Existing declarations default to the current suffix-derived type and `subjectBound:true`, so `customer.update_field` and `job.update_field` still reduce to `update_field`. Validate an explicit execution type with the same lower-snake dotted-key grammar. Publish `type` and `subject_bound` on every catalogue declaration; do not infer either in Flutter.
- [ ] Register the Tasks contract immediately after Dashboard with `sidebar:false`, actions `view|create|edit|comment|delete|export|manage`, `Task::class` as subject, all nine trigger declarations, and clean action keys/execution types `task.create`, `task.assign_owner`, `task.set_status`, `task.set_due_date`, `task.move_group`, and `task.add_update`. Only `task.create` declares `subjectBound:false`; the other five operate on the Task that triggered the rule. They remain unimplemented until Task 13B extends `ActionRunner`. Keeping the tab hidden here preserves the repository invariant that every visible sidebar module already has a glance and client destination.
- [ ] Prove the catalogue reports the explicit type/applicability, existing generic declarations retain their current types, `task.create` is not rendered with a doubled Task prefix, and registering the new declaration does not mark either `task.create` or existing `job.create` implemented before Task 13B.
- [ ] Prove module order, hidden-sidebar state, all seven permissions, subject, declarations, and PermissionCatalogue implications with `php -d memory_limit=1G vendor/bin/phpunit tests/Feature/Tasks/TaskModuleTest.php tests/Feature/ModuleRegistryTest.php tests/Feature/Dashboard/GlanceRegistryTest.php`.
- [ ] Commit: `git add app/Providers/ModuleServiceProvider.php tests/Feature/Tasks/TaskModuleTest.php && git commit -m "feat(tasks): register module contract"`

### Task 5: Implement exact-place and private-board access

**Files:**
- Create: `app/Support/Tasks/TaskPlace.php`
- Create: `app/Support/Tasks/TaskBoardAccess.php`
- Create: `app/Support/Tasks/TaskParticipantEligibility.php`
- Create: `app/Policies/TaskBoardPolicy.php`
- Create: `app/Policies/TaskPolicy.php`
- Create: `app/Policies/TaskUpdatePolicy.php`
- Test: `tests/Feature/Tasks/TaskAccessTest.php`

- [ ] Write a matrix test with organization, company, franchise, descendant franchise, sibling company, sibling franchise, scope-visible/private, owner/member/non-member, orphaned-private-board recovery, and each Tasks permission. Include `/me` reach that contains both siblings to prove raw reach alone is insufficient.
- [ ] Implement `TaskPlace::fromView(ViewScope $scope): array{organization_id:string,company_id:?string,franchise_id:?string}` for exact filing: organization uses both null, company uses `$scope->id` and null, franchise resolves that franchise's company plus `$scope->id`. Implement `TaskPlace::coveredBy(User $user, TaskBoard $board, ViewScope $scope, ScopeReach $reach): bool` for reads: the active view must cover the filed company/franchise and `ScopeReach` must independently prove assignment authority at the filed level. Organization boards require organization view plus organization-level reach.
- [ ] `TaskBoardAccess` is the single SQL and record-level implementation. `constrainVisible(...)` applies release availability, entitlement, current-view coverage, `tasks.view`, filed-place reach, and private membership before pagination. `createDecision(...)` additionally proves `tasks.manage` and exact-place assignment reach without trusting `ViewScope::forUser()`'s organization fallback. `backgroundViewDecision(...)` applies release/entitlement, `tasks.view`, filed-place assignment reach, and private membership without depending on another user's active UI view. `viewDecision(...)` adds current-view coverage. `manageDecision(...)` additionally requires `tasks.manage` and active owner membership.
- [ ] Policies return the service's `Response`/`Refusal` directly so human refusal sentences survive; direct callers use `->allowed()`. Board directories, glance, My Tasks, quick search, and export begin with `constrainVisible(...)` and never load then filter in PHP.
- [ ] `TaskParticipantEligibility::forMembership(...)` requires active user, `tasks.view`, and filed-place reach; it intentionally does not require membership for the person being added. `forTaskParticipation(...)` adds active private membership and is the only owner/collaborator/mention/People/import-email/Watch predicate. Selecting a task participant never creates membership.
- [ ] Add one audited, content-blind Administrator recovery domain operation, exposed through the dedicated Task 7B API only when no eligible owner remains. It locks board/memberships, installs a named eligible owner, returns minimal confirmation, and never grants the Administrator view/manage access.
- [ ] Run `php -d memory_limit=1G vendor/bin/phpunit tests/Feature/Tasks/TaskAccessTest.php tests/Feature/Api/ViewScopeTest.php`.
- [ ] Commit: `git add app/Support/Tasks app/Policies tests/Feature/Tasks/TaskAccessTest.php && git commit -m "feat(tasks): enforce board place and private access"`

**Green checkpoint:** storage, retention, and the complete sibling-scope/private-access matrix are green before adding endpoints.

## Milestone 2 — Module and Core APIs

### Task 6: Add the dashboard glance and minimal client destination

**Files:**
- Create: `app/Support/Dashboard/Glances/TasksGlance.php`
- Modify: `app/Support/Dashboard/GlanceRegistry.php`
- Create: `client/lib/screens/tasks_screen.dart`
- Modify: `client/lib/core/module_screens.dart`
- Test: `client/test/tasks/tasks_module_screen_test.dart`
- Test: `tests/Feature/Dashboard/TasksGlanceTest.php`
- Modify: `tests/Feature/Dashboard/GlanceRegistryTest.php`

- [ ] Write failing tests for a live Tasks glance, a Flutter destination, and the final sidebar-visible module; the permission/declaration contract is already green but hidden from the sidebar in Task 4A.
- [ ] Add `TasksGlance` with key `tasks`, permission `tasks.view`, default presentation `bar`, and payload counts `open`, `assigned_to_me`, `due_in_period`, `overdue`, `blocked`, and `completed_in_period` plus top owners by open count, constrained by `TaskBoardAccess` and the current `ViewScope`.
- [ ] Add a minimal but real `TasksScreen` using the selected layout's shell, loading state, empty board directory, and refusal state; add `tasks` to `GlanceRegistry::ORDER` and the Flutter module map, then atomically flip the server module to `sidebar:true` immediately after Dashboard. Task 16 expands this same screen. The focused commit is green only when server module, glance, glance order, and client destination all exist together.
- [ ] Run `php -d memory_limit=1G vendor/bin/phpunit tests/Feature/Tasks/TaskModuleTest.php tests/Feature/Dashboard/TasksGlanceTest.php tests/Feature/Dashboard/GlanceRegistryTest.php tests/Feature/ClientModuleScreensTest.php`, then `cd client; flutter test test/tasks/tasks_module_screen_test.dart; flutter analyze`.
- [ ] Commit: `git add app/Support/Dashboard/Glances/TasksGlance.php app/Support/Dashboard/GlanceRegistry.php client/lib/core/module_screens.dart client/lib/screens/tasks_screen.dart client/test/tasks tests/Feature/Dashboard tests/Feature/ClientModuleScreensTest.php && git commit -m "feat(tasks): add Tasks glance and destination"`.

### Task 7A: Implement boards, groups, and configuration APIs

**Files:**
- Create: `app/Support/Tasks/TaskBoardWriter.php`
- Create: `app/Http/Controllers/Api/Tasks/TaskBoardsController.php`
- Create: `app/Http/Controllers/Api/Tasks/TaskGroupsController.php`
- Create: `app/Http/Controllers/Api/Tasks/TaskStatusesController.php`
- Create: `app/Http/Controllers/Api/Tasks/TaskPrioritiesController.php`
- Create: `app/Http/Controllers/Api/Tasks/TaskColumnsController.php`
- Modify: `routes/api.php`
- Test: `tests/Feature/Tasks/TaskBoardConfigurationApiTest.php`

- [ ] Follow the repository's existing controller-validation convention; use `$request->validate(...)` at the HTTP edge and repeat security/business invariants in `TaskBoardWriter`. Do not introduce Form Requests only for this module.
- [ ] Write failing API tests for directory listing, exact-view creation, private creation, rename/archive/restore, structure-only duplication, group CRUD/reorder/archive/restore, active-task archive refusal, archived-group create/move refusal, status/priority/column configuration, immutable filing place, and structure-version conflicts.
- [ ] Make `POST /api/v1/tasks/boards` call `createDecision(...)` and derive its place only from the request's active `ViewScope`. The request body contains `name`, `visibility`, and optional `folder_id`; it must reject all `organization_id`, `company_id`, and `franchise_id` input.
- [ ] Create the board plus creator owner-membership transactionally. Defaults are To do and Completed groups; Not started/Working on it/Blocked/Done/Cancelled statuses; Low/Normal/High/Urgent priorities; and permanent Main table showing Task, Owner, Status, Priority, Start date, and Due date.
- [ ] Enforce that every board retains at least one non-terminal status and one Completed status. A used status/priority/column may be renamed, recolored, reordered, or archived but not hard-deleted.
- [ ] A used status category or priority rank cannot change in place. `NULL` remains the only None priority. Core display-label changes write `task_core_column_settings`; custom Number configuration persists `number_suffix`. Hide/order/width stay in view configuration. Every structure/configuration/reorder request locks the board, compares `expected_structure_version`, writes affected rows, and increments structure version exactly once.
- [ ] Expose REST routes under `/api/v1/tasks/...`; return one documented JSON envelope with `data`, optional `included`, and `meta` rather than per-controller shapes.
- [ ] Structure-only duplication may target the exact current view only when `viewDecision(...)` and source owner-management both cover the source in that same active view and `createDecision(...)` authorizes the target; it copies no members, owners, favorites/preferences, tasks, updates, links, or attachments. It rejects `include_tasks=true` until Task 9 can copy typed values transactionally. Refuse group archive while it has active top-level tasks, reject task create/move into an archived group, and restore a group under `expected_structure_version` without restoring tasks. Archived-group content is owner-authorized history/export only. For a source outside the target view, relocation is blank target creation → reviewed export/import → source archive, never a place update.
- [ ] Position columns are signed integers. Reorder locks the entire sibling set, moves affected rows into a collision-free temporary range, then writes final dense positions before commit; test adjacent swap and competing reorders against immediate MySQL unique indexes.
- [ ] Run the focused suite and `tests/Feature/Tasks/TaskAccessTest.php`.
- [ ] Commit: `git add app/Support/Tasks/TaskBoardWriter.php app/Http/Controllers/Api/Tasks routes/api.php tests/Feature/Tasks/TaskBoardConfigurationApiTest.php && git commit -m "feat(tasks): add board configuration APIs"`

### Task 7B: Implement membership, recovery, folders, and favorites APIs

**Files:**
- Create: `app/Http/Controllers/Api/Tasks/TaskBoardMembersController.php`
- Create: `app/Http/Controllers/Api/Tasks/TaskBoardRecoveryController.php`
- Create: `app/Http/Controllers/Api/Tasks/TaskFoldersController.php`
- Create: `app/Http/Controllers/Api/Tasks/TaskBoardPreferencesController.php`
- Create: `app/Http/Controllers/Api/Tasks/TaskParticipantsController.php`
- Modify: `routes/api.php`
- Test: `tests/Feature/Tasks/TaskBoardMembershipApiTest.php`

- [ ] Write failing tests for owner transfer/demotion/removal, last eligible owner, concurrent transfer, private member add/revoke, deactivation/reach/permission loss, content-blind recovery, same-place folders including organization-null mismatch attacks, favorites/last-opened state, eligible participant search, and non-enumerating inaccessible IDs.
- [ ] `canManage` requires active owner membership plus `tasks.manage`; ordinary members do not configure a board. Added owners/members must pass `TaskParticipantEligibility::forMembership`. Removing/demoting the last eligible owner is refused while external later deactivation/loss may create the recoverable orphan state.
- [ ] Transfer/revocation locks the board and affected memberships. Recovery is organization-Administrator-only, only for an actual orphan, and returns no task/board content beyond opaque board identity and success.
- [ ] Folder create/change derives exact filing place from the active view; a board can move only to a folder with the identical generated `place_key`. `task_board_preferences` stores personal favorite and `last_opened_at` values and never widens visibility.
- [ ] Add capped participant search (maximum 20) with an authorization-owning purpose: `membership` requires `manageDecision(...)` and calls `forMembership(...)`; `task_create` requires `tasks.create`; `owner|people|collaborator` require `tasks.edit`; `mention` requires `tasks.comment`; each task purpose calls `forTaskParticipation(...)`. Import-email resolution stays inside owner-managed preview and Watch is self-only. No UI enumerates raw `/me.companies`/users.
- [ ] Run `php -d memory_limit=1G vendor/bin/phpunit tests/Feature/Tasks/TaskBoardMembershipApiTest.php tests/Feature/Tasks/TaskAccessTest.php`.
- [ ] Commit: `git add app/Http/Controllers/Api/Tasks routes/api.php tests/Feature/Tasks/TaskBoardMembershipApiTest.php && git commit -m "feat(tasks): add board membership and organization"`

### Task 8: Implement task lifecycle, subtasks, bulk changes, and ordering

**Files:**
- Create: `app/Support/Tasks/TaskWriter.php`
- Create: `app/Support/Tasks/TaskRichText.php`
- Create: `app/Http/Controllers/Api/Tasks/TasksController.php`
- Create: `app/Http/Controllers/Api/Tasks/TaskBulkController.php`
- Create: `app/Http/Controllers/Api/Tasks/TaskOrderController.php`
- Modify: `routes/api.php`
- Test: `tests/Feature/Tasks/TasksApiTest.php`
- Test: `tests/Feature/Tasks/TaskConcurrencyTest.php`

- [ ] Write failing tests for create/edit/archive/restore, parent cascade/targeted restore, safe task description/plain-text projection, one accountable owner, collaborators, completion/reopen/cancel without subtask status propagation, due revisions including A → B → A, one-level subtask, cross-group move, dense reorder/group-order conflicts, bulk partial refusal, stale version, and access revocation racing a write.
- [ ] Build `TaskRichText` on the already-installed HTMLPurifier dependency. Allow only `p`, `br`, `strong`, `em`, `ul`, `ol`, `li`, and HTTPS `a`; strip style, images/data URLs, scripts, event attributes, and email-oriented CSS; emit normalized HTML plus a plain-text projection. Use it for durable description and later Updates.
- [ ] `TaskWriter` locks task plus relevant private-membership rows, rechecks access, compares `expected_version`, validates every referenced board-owned/eligible participant ID, performs the mutation, increments version once, and returns the fresh task. The same transaction writes audit, durable task activity, declared outbox events, and content-free notification intents.
- [ ] A stale request returns HTTP 409 with current values/conflicting fields only while access remains; after revocation it returns the generic unavailable shape with no current row.
- [ ] Entering Completed sets `completed_at`/`completed_by` and clears cancellation markers. Leaving Completed clears completion markers; it raises `task.reopened` only when the new category is non-terminal. Entering Cancelled sets `cancelled_at`/`cancelled_by` and clears completion markers; leaving it clears cancellation markers. All semantics follow stable category, never display label text.
- [ ] Increment `due_revision` only when the normalized `due_on` value changes; clearing and later restoring a date is a new revision. Status/owner changes never alter it.
- [ ] A subtask stores the parent's board and `parent_task_id` but no group, cannot itself receive a parent, and cannot be reparented in Phase 1. Moving the parent across groups automatically changes every child's effective joined group without child writes or artificial child-version conflicts. Completing/cancelling a parent does not change subtask status. Parent archive locks active children, marks them with `archived_with_parent_id`, increments every affected core version once, and emits ordinary per-task archived activity/audit/outbox events under one causation ID; parent restore increments/restores only rows carrying that marker.
- [ ] Bulk endpoints authorize each task before mutation; if any target is forbidden or stale, mutate none and return indexed errors. A successful bulk write still creates one audit/activity/outbox source occurrence per affected task under a shared causation ID, so notification dedupe keys cannot collapse different tasks for the same recipient.
- [ ] Task reorder requires expected source/destination group `order_version`, locks those groups and affected siblings, uses the temporary-range then final-dense algorithm, and increments each affected group once. Cross-group move increments the moved task's core version; shifted siblings keep their core versions. Never issue one HTTP write per row.
- [ ] Run `php -d memory_limit=1G vendor/bin/phpunit tests/Feature/Tasks/TasksApiTest.php tests/Feature/Tasks/TaskConcurrencyTest.php`.
- [ ] Commit: `git add app/Support/Tasks app/Http routes/api.php tests/Feature/Tasks && git commit -m "feat(tasks): add versioned task lifecycle"`

### Task 9: Implement typed custom column values

**Files:**
- Create: `app/Support/Tasks/TaskColumnValueStore.php`
- Create: `app/Http/Controllers/Api/Tasks/TaskColumnValuesController.php`
- Create: `app/Http/Controllers/Api/Tasks/TaskDuplicationController.php`
- Modify: `routes/api.php`
- Test: `tests/Feature/Tasks/TaskColumnValuesTest.php`
- Test: `tests/Feature/Tasks/TaskDuplicationTest.php`

- [ ] Write a data-provider test for all eight types, null/clear behavior, invalid shapes, option ownership, ineligible People, same-task attachment ownership, HTTPS/link-label normalization, stale version, and refusal to change a populated definition's type.
- [ ] Accept exactly one typed payload per type: text string; finite decimal number; boolean; ISO `YYYY-MM-DD`; People array of user IDs; Label array of column-option IDs; HTTPS URL plus optional label; attachment IDs already uploaded through the existing attachment endpoint.
- [ ] `replace(...)` locks membership/task, rechecks access, compares `expected_version`, validates compound identity and `TaskParticipantEligibility`, replaces joins/scalar value, increments once, and writes audit/activity. A mismatch makes no dependent write and returns the Task 8 conflict/unavailable shape.
- [ ] After Task 0's restore proof is green, register Task and TaskUpdate in `Attachables`. A File cell can reference only an ordinary Attachment already bound to that same Task and not marked as an update candidate. Generic attachment endpoints never rebind; Task 11 owns the private candidate and controlled Task → TaskUpdate claim. Cross-tenant, same-tenant cross-record, replay/rebind, revoked access, filename/metadata, and download tests must fail closed through the parent policy. FileStore URLs remain authorization-time responses, never stored public URLs.
- [ ] Add task-inclusive board duplication and single-task duplication now that `TaskWriter` and typed values exist. Require identical filing place and explicit compatible mappings for status, priority, and custom definitions; copy top-level/subtasks and eligible collaborators transactionally, report unmapped values, and leave no partial copy. Cross-place relocation remains structure-only duplicate plus reviewed export/import.
- [ ] Run both focused tests plus `tests/Feature/Api/AttachmentsTest.php`.
- [ ] Commit: `git add app/Support/Tasks app/Http app/Support/Files/Attachables.php routes/api.php tests/Feature/Tasks && git commit -m "feat(tasks): store typed custom values"`

**Green checkpoint:** a client can configure a board and safely complete the full task lifecycle through APIs, including custom fields and conflicts.

## Milestone 3 — Querying, Collaboration, and EzBizi Integration

### Task 10: Add board table query, saved views, and My Tasks

**Files:**
- Create: `app/Support/Tasks/TaskListQuery.php`
- Create: `app/Support/Tasks/TaskViewCodec.php`
- Create: `app/Http/Controllers/Api/Tasks/TaskBoardRowsController.php`
- Create: `app/Http/Controllers/Api/Tasks/TaskDetailsController.php`
- Create: `app/Http/Controllers/Api/Tasks/TaskViewsController.php`
- Create: `app/Http/Controllers/Api/Tasks/MyTasksController.php`
- Create: `app/Http/Controllers/Api/Tasks/TaskQuickSearchController.php`
- Modify: `routes/api.php`
- Test: `tests/Feature/Tasks/TaskViewsApiTest.php`
- Test: `tests/Feature/Tasks/MyTasksApiTest.php`
- Test: `tests/Feature/Tasks/TaskDetailsApiTest.php`

- [ ] Write failing tests for search, Person, filter, allowed/rejected sorts, hidden/order/width columns, server-side group, pagination, personal/shared-unlocked/shared-locked lifecycle, shared/personal reorder conflicts, personal presentation preferences, favorite/last-opened behavior, quick search, board/My Tasks/detail ETag/304, and My Tasks across `TaskBoardAccess::constrainVisible(...)` in the active view.
- [ ] Define `{schema:1,presentation:"table",filters:[],sorts:[],hidden_columns:[],column_order:[],column_widths:{},group_by:null|string,search:string}`. `TaskViewCodec` rejects unknown/archived/foreign references and operators and never passes request fields directly to SQL. Saved search/filter values are classified personal data.
- [ ] Support core/custom comparisons appropriate to type: equality/in/not-in and empty/not-empty; text contains; number range; date before/on/after/between; People/Label contains-any/contains-all. Only Text, Number, Checkbox, Date, and Link-by-normalized-URL custom types sort; reject People/Label/File sorts and omit them from the client catalogue.
- [ ] Paginate server-side, return visible subtasks, and compute group counts/status summaries over the complete filtered accessible result before pagination. Cap page size at 200 and enforce a query-count bound on 100 tasks.
- [ ] Personal views are owner-only. Shared unlocked views are visible to all board viewers and editable by a viewer with `tasks.edit`. Shared locked views are editable only by active owners with `tasks.manage`. Only owner-managers share/lock/unlock/delete and reorder shared views under `expected_structure_version`; a caller reorders personal views only under the owned view versions. Any viewer may duplicate shared to personal; permanent Main cannot be deleted.
- [ ] Add current-view quick search by task number/title/board for every accessible task, not only owned tasks. Return board/task identity and deep link only after the same access query.
- [ ] Return ETag/equivalent tokens for board rows and My Tasks. Add `GET /api/v1/tasks/boards/{board}/tasks/{task}` with an ETag derived from core `version` plus `detail_version`; updates, update files, mentions, and activities increment only `detail_version` when they do not own a core change. Conditional refresh returns 304/no body when unchanged and rechecks access on every request.
- [ ] Run the focused tests with a query-count assertion on the 100-task fixture.
- [ ] Commit: `git add app/Support/Tasks app/Http routes/api.php tests/Feature/Tasks && git commit -m "feat(tasks): add table views and My Tasks"`

### Task 10A: Pass the production FileStore restore gate

**Files:**
- Verify: `deploy/backup.sh`
- Verify: `deploy/restore-check.sh`
- Modify: `app/Support/Files/FileStore.php`
- Modify: `docs/EZBIZI-STATUS.md`
- Modify: `H:\Development\integrations-board\data.json`

- [ ] Confirm Task 0's backup/restore-check change is committed and its disposable fixture is green. Deploy it only through the owner's normal `scripts/deploy.sh` path with explicit deployment approval; never edit production as the shipping mechanism.
- [ ] Through the approved operations path, run one production-sized backup/restore verification that includes `storage/app/private`, rejects unsafe archive entries, and proves the versioned relative-path/byte-length/SHA-256 manifest exactly. Do not print or record secrets, customer values, or filenames.
- [ ] Record only timestamp, source (`offsite` or a clearly failed local-only result), and pass/fail in STATUS and the Tasks work-board card. A missing archive/manifest row, extra row, checksum mismatch, decryption/extraction failure, local-only source, or incomplete restore is a failure.
- [ ] If the proof fails or cannot run, set the Tasks card to waiting on restore-tested FileStore durability and leave Task 11 attachment candidates, Task 14 import staging, and every production-capable File-column upload/selection path closed. Non-file work may continue.
- [ ] Only after a passing proof, update FileStore's durability docblock to state exactly what the restore test covers and what remains outside it.
- [ ] Re-run the backup health check and inspect its full output. Commit only the documentation/board evidence after a green proof; the production output and customer filenames never enter git.
- [ ] Commit: `git add app/Support/Files/FileStore.php docs/EZBIZI-STATUS.md && git commit -m "docs: record FileStore restore proof"`

**Green checkpoint:** FileStore bytes, not only their database rows, survive a restore-verified off-instance loss; file-bearing Tasks paths may now proceed.

### Task 11: Add updates, mentions, Watch/Mute, and notifications

**Files:**
- Create: `app/Support/Tasks/TaskUpdateWriter.php`
- Create: `app/Support/Tasks/TaskNotificationPresenter.php`
- Create: `app/Support/Tasks/TaskNotificationIntentDispatcher.php`
- Create: `app/Http/Controllers/Api/Tasks/TaskUpdatesController.php`
- Create: `app/Http/Controllers/Api/Tasks/TaskCollaboratorsController.php`
- Create: `app/Http/Controllers/Api/Tasks/TaskWatchPreferencesController.php`
- Create: `app/Http/Controllers/Api/Tasks/TaskUpdateAttachmentsController.php`
- Create: `app/Jobs/DeliverTaskNotificationIntent.php`
- Create: `app/Console/Commands/DispatchTaskNotificationIntents.php`
- Create: `app/Console/Commands/ExpireTaskUpdateAttachmentCandidates.php`
- Modify: `app/Http/Controllers/Api/NotificationsController.php`
- Modify: `app/Models/Notification.php`
- Modify: `app/Http/Controllers/Api/AttachmentsController.php`
- Modify: `app/Support/Notifications/NotificationDispatcher.php`
- Create: `app/Support/Files/AttachmentUploader.php`
- Modify: `routes/console.php`
- Modify: `routes/api.php`
- Test: `tests/Feature/Tasks/TaskCollaborationTest.php`
- Modify: `tests/Feature/Api/NotificationsTest.php`

- [ ] Write failing tests for idempotent update create, versioned own edit/archive, immutable revisions, `tasks.comment` attachment-only/file-only updates, refusal to edit/archive another author's update, proof that `tasks.edit`/`tasks.manage`/board ownership do not imply comment authority, durable activity merge, participant eligibility, collaborator/watch transitions, self-suppression, controlled attachment claims, commit/enqueue failure, two-worker claims, expired lease, crash after notification insert, retry, access/owner change after intent, filtered notification data/unread counts/mark-read, and deep links.
- [ ] Reuse `TaskRichText` from Task 8. Mentions resolve only from explicit eligible user IDs, never parsed display names.
- [ ] Owner is mandatory watched. Collaborator, update author, and mentioned user default to watched; each may record `muted` without losing participation. Explicit Watch records `watched`. Owner change removes only mandatory-owner behavior and preserves collaboration/explicit preference.
- [ ] Adding/removing another collaborator requires `tasks.edit`. Any accessible viewer may write only their own Watch/Mute preference; no endpoint accepts an arbitrary preference owner. Removing collaboration preserves an explicit preference, and muting never removes collaboration.
- [ ] Enforce `tasks.comment` for update create, attachment claim, own edit, and own archive. `tasks.edit`, `tasks.manage`, and owner membership do not substitute; another author's update is immutable to the caller outside separately authorized retention/privacy machinery.
- [ ] Extract the existing upload mechanics into `AttachmentUploader`, add byte cleanup when database persistence fails, and keep `AttachmentsController` behavior unchanged for existing types. Add a `tasks.comment`-authorized candidate endpoint that attaches the upload to the Task plus a private candidate marker for that actor/24-hour expiry. Generic Task attachment lists, direct downloads by anyone except the candidate uploader, and File-column selection exclude candidate rows.
- [ ] `TaskUpdateWriter::create` locks each candidate row before proving same tenant/task/actor/unexpired Task-bound state, creates the update, rebinds through one domain-only Attachment claim, and removes markers inside the transaction. Generic rebind remains forbidden. Conflict/rollback leaves candidates private and staged; event/intent creation occurs only after final binding. File-only updates count. Schedule `ezbizi:expire-task-update-attachment-candidates` hourly with bounded batches and `withoutOverlapping(10)`: claim expired rows with `FOR UPDATE SKIP LOCKED`, recheck expiry plus current Task-bound ownership under lock, delete candidate/attachment rows first, and delete bytes only after commit. If ownership no longer matches, remove only the stale marker and never the Attachment. Race-test claim/remove versus expiry and prove no committed update loses bytes.
- [ ] Inside each business transaction insert an idempotent `task_notification_intents` row containing recipient/kind/source occurrence/task/board/place IDs only. Queue `DeliverTaskNotificationIntent` after commit with only the intent ID and schedule `ezbizi:dispatch-task-notification-intents` every minute as durable catch-up.
- [ ] Both the single-intent job and minute sweep use one claim path. Claim at most 100 pending/expired-lease rows with `FOR UPDATE SKIP LOCKED`, a random claim token, `processing` status, five-minute lease, and incremented attempts. Immediately before delivery call `backgroundViewDecision(...)`, then recheck participant relationship, archive state, and current ownership when required. Unauthorized/irrelevant intents become `suppressed`; transient errors return to pending with exponential backoff capped at one hour; terminal errors or attempt 10 become `failed` with only a classified code.
- [ ] In one delivery transaction, find or insert the `notifications` row by unique `(organization_id,user_id,source='task',delivery_key=<intent UUID>)`, then mark the intent delivered. `delivery_key` is the sole idempotency identity: there is no Tasks-specific notification column and no reverse delivered-notification ID on the intent. Keep notification `subject_type/id` as the actual Task; a crash after notification insert and every retry finds the same row. Queue payloads never serialize task content/models.
- [ ] Route all Tasks notification listing/count/read operations through `TaskNotificationPresenter`. Compose non-Tasks rows with Tasks rows constrained by `constrainBackgroundVisible(...)` plus the same kind-specific current relationship predicate used for delivery; apply that SQL before limit and unread count so hidden rows neither leak nor starve older accessible rows. After access loss, omit Tasks rows from `data` and unread count, refuse direct mark-read with the generic unavailable shape, and make mark-all-read affect only currently visible IDs. Available rows receive canonical `/tasks/boards/{board}/tasks/{task}` metadata only after reauthorization.
- [ ] Merge Updates with immutable `task_activities`, not generic audit rows. Emit `task.update_added` after persistence. Append-only updates use idempotency keys and do not increment core task version; create/edit/archive/file/mention/activity changes increment `detail_version`, while edit/archive also compare `expected_update_version` and append one revision.
- [ ] Run the focused test and full Notifications test.
- [ ] Commit: `git add app/Support/Tasks app/Support/Files app/Support/Notifications app/Http app/Jobs app/Console app/Models/Notification.php routes/api.php routes/console.php tests/Feature && git commit -m "feat(tasks): add updates and notifications"`

### Task 12: Add governed EzBizi record links

**Files:**
- Create: `app/Support/Tasks/TaskRecordLinkDefinition.php`
- Create: `app/Support/Tasks/TaskRecordLinkRegistry.php`
- Create: `app/Support/Tasks/RecordLinks/{CustomerTaskLink,JobTaskLink,VehicleTaskLink,InventoryItemTaskLink,InspectionReportTaskLink,InspectionRunTaskLink,HrPersonTaskLink,ServiceTaskLink}.php`
- Create: `app/Http/Controllers/Api/Tasks/TaskRecordLinksController.php`
- Create: `app/Providers/TaskServiceProvider.php`
- Modify: `bootstrap/providers.php`
- Modify: `routes/api.php`
- Test: `tests/Feature/Tasks/TaskRecordLinksTest.php`

- [ ] Write contract tests that every definition provides permission-aware `search` and `resolve`, deep-link metadata, an allowlisted/privacy-classified snapshot, intersects active view with board filing subtree, and never uses `modelClass()+find()` as an authorization shortcut.
- [ ] Register the eight Phase 1 types explicitly; do not scan models. Search accepts `type`, `q`, and max 20 results and runs through each definition's permission-aware query.
- [ ] Creating/removing a link locks membership/task, compares `expected_task_version`, resolves through the definition, validates organization/place, stores only the allowlisted snapshot, increments once, and audits/records activity. An organization board may link any target in the caller's active/reachable organization view; a company board only its subtree; a franchise board only that franchise.
- [ ] Every read/open/notification/audit presentation/export re-resolves target authorization. If denied/gone/moved/erased, return only `{link_id,available:false,type:<registry label>}`—no target ID, label, subtitle, route, or snapshot. Audit/outbox payloads keep stable type/link IDs; privacy erasure redacts stored snapshots.
- [ ] Run the focused test plus the sibling-scope access matrix.
- [ ] `TaskServiceProvider` registers the eight definitions explicitly into one singleton `TaskRecordLinkRegistry`; no model scanning or container-tag ordering decides the public type catalog.
- [ ] Commit: `git add app/Support/Tasks app/Http app/Providers/TaskServiceProvider.php bootstrap/providers.php routes/api.php tests/Feature/Tasks && git commit -m "feat(tasks): link tasks to governed records"`

### Task 13A: Add overdue and due-reminder settings and schedulers

**Files:**
- Modify: `app/Support/Settings/SettingRegistry.php`
- Modify: `app/Support/Preferences/PreferenceRegistry.php`
- Create: `app/Console/Commands/DispatchTaskOverdue.php`
- Create: `app/Console/Commands/DispatchTaskDueReminders.php`
- Modify: `routes/console.php`
- Test: `tests/Feature/Tasks/TaskOverdueTest.php`

- [ ] Register organization-only boolean setting `tasks.due_reminders_enabled` default true and user enum preference `tasks.due_reminder_hours` default 24 with allowed values `1|4|24|48|72`, using the existing dotted-key registries and existing enum preference type.
- [ ] Define overdue as `due_on` before the board place's current local date. Define reminder instants relative to the end of the due day in that timezone; the hourly worker claims the occurrence on the first run crossing the selected lead-hour boundary.
- [ ] `ezbizi:dispatch-task-overdue` scans bounded unarchived/nonterminal rows, inserts unique `(task_id,due_revision)` with a due-date snapshot, emits `task.overdue` only for a successful insert, and creates an access-rechecked intent whose source occurrence is that row. `ezbizi:dispatch-task-due-reminders` inserts unique `(task_id,user_id,due_revision,lead_hours)` with the same snapshot only for the current eligible owner.
- [ ] Schedule each hourly with a distinct `withoutOverlapping(10)` lock. Test repeated/racing runs, A → B → A due changes, complete/reopen, owner/access change after claim, preference/tenant disable, timezones, and private boards.
- [ ] Run `php -d memory_limit=1G vendor/bin/phpunit tests/Feature/Tasks/TaskOverdueTest.php tests/Feature/SettingRegistryTest.php tests/Feature/Api/PreferencesTest.php`.
- [ ] Commit: `git add app/Support/Settings/SettingRegistry.php app/Support/Preferences/PreferenceRegistry.php app/Console routes/console.php tests/Feature && git commit -m "feat(tasks): add due workers"`

### Task 13B: Implement Task automation actions and execution identity

**Files:**
- Modify: `app/Models/Task.php`
- Modify: `app/Support/Scoping/ViewScope.php`
- Modify: `app/Support/Automations/ActionRunner.php`
- Modify: `app/Support/Automations/AutomationEngine.php`
- Modify: `app/Http/Controllers/Api/AutomationsController.php`
- Modify: `client/lib/core/automation_catalogue.dart`
- Modify: `client/lib/screens/automation_builder.dart`
- Test: `tests/Feature/Tasks/TaskAutomationsTest.php`
- Modify: `tests/Feature/Api/AutomationEngineTest.php`
- Modify: `tests/Feature/Api/AutomationsApiTest.php`
- Modify: `client/test/automations_screen_test.dart`

- [ ] Write failing tests proving all nine events carry task/board/place IDs and appropriate old/new stable values, insert transactionally, and roll back with the business write. `Task` implements `CarriesAutomationScope` using franchise first, company second, organization otherwise.
- [ ] Add `ViewScope::forStoredAutomationScope(User $user, ?string $type, ?string $id)`. It maps the existing `null/null` Automation representation to organization scope only after validating organization reach; accepts valid `company/id` or `franchise/id`; rejects every half-null, unknown, foreign, or unreachable pair; and never reads or falls back to `navigation.scope`.
- [ ] Pass the current `Automation` from `AutomationEngine` into `ActionRunner`. Execute as its active `created_by` user in its stored scope; missing/inactive creator, missing Tasks permission, lost reach, or board outside rule scope fails the run. Never use system authority or the creator's last top-bar selection.
- [ ] Add six explicit action branches and catalogue parameter schemas: create task on board/group, assign/clear owner, set status, set/clear due date, move group, add system-authored update. Save-time validation and runtime both verify referenced board-owned IDs. Delegate to normal writers and preserve causation, depth, action budget, idempotency, audit, outbox, and test mode.
- [ ] Extend `IMPLEMENTED_ACTIONS`, `action_types`, validator, and Flutter builder with the six explicit `task.*` execution types only when each complete parameter schema is present. Catalogue `action_types` marks module-only types; the builder excludes those from “Works with anything,” uses the declaration's server-provided execution type rather than parsing its key, and shows each Tasks action exactly once. Preserve existing generic action storage and existing Phone execution types.
- [ ] Treat `task.create` as cross-subject and require the named board/group parameters. Require a Task trigger for `task.assign_owner|set_status|set_due_date|move_group|add_update`; never accept an arbitrary task ID that turns a rule into a hidden cross-board writer. Test that `task.create` becoming implemented does not mark `job.create` implemented.
- [ ] Test scope escape, revoked/deactivated creator, private membership, invalid parameters, subject applicability, test mode, causation, loops, action budget, and one-picker-entry presentation, then run the focused server and Flutter suites.
- [ ] Commit: `git add app/Models/Task.php app/Support/Scoping/ViewScope.php app/Support/Automations app/Http/Controllers/Api/AutomationsController.php client/lib/core/automation_catalogue.dart client/lib/screens/automation_builder.dart tests/Feature client/test/automations_screen_test.dart && git commit -m "feat(tasks): implement automation actions"`

### Task 14: Add CSV/XLSX import and CSV/XLSX/JSON export

**Files:**
- Modify: `composer.json`
- Modify: `composer.lock`
- Create: `app/Support/Tasks/TaskTransfer.php`
- Create: `app/Http/Controllers/Api/Tasks/TaskImportsController.php`
- Create: `app/Http/Controllers/Api/Tasks/TaskExportsController.php`
- Create: `app/Console/Commands/ExpireTaskImportSessions.php`
- Modify: `routes/api.php`
- Modify: `routes/console.php`
- Modify: `docs/EZBIZI-DEV-ENVIRONMENT.md`
- Test: `tests/Feature/Tasks/TaskTransferTest.php`

- [ ] Refuse to begin file staging unless Task 0's restore-tested FileStore gate remains green. Then verify `php -m` contains the extensions required by PhpSpreadsheet 5.9 (`ctype`, `dom`, `fileinfo`, `filter`, `gd`, `iconv`, `libxml`, `mbstring`, `simplexml`, `xml`, `xmlreader`, `xmlwriter`, `zip`, `zlib`). If any are absent, mark this board step waiting and install/enable them before changing Composer.
- [ ] Add `phpoffice/phpspreadsheet:^5.9` with `php composer.phar require phpoffice/phpspreadsheet:^5.9`. Do not parse XLSX manually.
- [ ] Write failing tests for source-row and parent-source-row mapping, one-level hierarchy/cycle/missing-parent errors, eligible owners by normalized email, status/category decisions, dates, eight custom types, reconciliation counts, all-or-nothing/idempotent commit, formulas/unsafe workbook features, explicit export permission, and round-trip fidelity.
- [ ] Preview/commit reauthorize exact board and active ViewScope. Both require active owner management plus `tasks.create`; mappings that alter configuration require `tasks.manage`. CSV/XLSX export requires `tasks.export`; archive-manifest inclusion of archived rows/update revisions additionally requires active owner management.
- [ ] Before PhpSpreadsheet loads XLSX, validate signature, ZIP entry count, expanded bytes, compression ratio, workbook/sheet count, dimensions, shared-string size, and per-entry bounds. Reject encrypted/macros/external links or connections/formulas/unsupported workbook features and never evaluate formulas. Enforce byte/row/column/cell/time limits during bounded preflight, not after unrestricted allocation.
- [ ] Preview stores bytes through private FileStore plus a `task_import_sessions` row containing only the random-token hash, organization/user/board/exact-view identity, disk/path/bytes/SHA-256, normalized mapping, board structure version, and 30-minute expiry. Return raw token once. Commit takes `FOR UPDATE` on that session before reauthorizing and verifying unexpired/unused token/hash/file/structure, keeps the lock through reparse/commit, and consumes once; changed structure returns 409/re-preview.
- [ ] Import calls configuration/task/value writers, emitting ordinary per-task declared events plus one import-summary audit/activity. Rollback leaves no business/audit/activity/outbox/intent rows and no consumed session. `ezbizi:expire-task-import-sessions` runs hourly with bounded `FOR UPDATE SKIP LOCKED` batches and `withoutOverlapping(10)`, rechecks expired/unconsumed state under the same row lock used by commit, deletes session rows first, and deletes staging bytes only after commit. Successful import deletes staging bytes only after its database commit. Race-test commit versus expiry and prove no committed import loses its source during parse/commit.
- [ ] Escape CSV cells beginning `=`, `+`, `-`, `@`, tab, or carriage return. Write every user-controlled XLSX string with explicit string cell type. Exports use allowlists, reauthorize links/files, contain no signed URLs/unavailable snapshots, and send private/no-store responses.
- [ ] Name JSON output a versioned archive manifest, not backup. It excludes attachment bytes and states that clearly; for authorized files it includes identifier/name/size/hash only. No JSON restore is promised in Phase 1.
- [ ] Keep direct Monday API tokens and import outside Phase 1. Document that Monday exports enter through CSV/XLSX here.
- [ ] Run the focused test, `php composer.phar audit`, and the PHP unit suite for Tasks.
- [ ] Commit: `git add composer.json composer.lock app/Support/Tasks/TaskTransfer.php app/Http/Controllers/Api/Tasks app/Console/Commands/ExpireTaskImportSessions.php routes/api.php routes/console.php docs/EZBIZI-DEV-ENVIRONMENT.md tests/Feature/Tasks && git commit -m "feat(tasks): add safe board transfer"`

**Green checkpoint:** collaboration, cross-module links, automation, notifications, and data portability are green before client completion.

## Milestone 4 — Flutter Client

### Task 15: Add typed client models and API client

**Files:**
- Create: `client/lib/screens/tasks/task_models.dart`
- Create: `client/lib/screens/tasks/task_api.dart`
- Test: `client/test/tasks/task_models_test.dart`
- Test: `client/test/tasks/task_api_test.dart`

- [ ] Write fixtures for board directory, board rows, typed values, view definition, conflict response, and unavailable record link; test strict parsing and unknown enum fallback.
- [ ] Model stable categories separately from labels/colors. Preserve unknown custom column definitions as read-only cells so an older client does not discard server data.
- [ ] Put `expected_task_version`, `expected_update_version`, `expected_group_order_version`, `expected_view_version`, or `expected_structure_version` on the mutation that owns that state. Track task-detail ETags separately from core edit versions. Parse 409 into typed conflicts; if access disappeared, parse the generic unavailable response without retaining server values.
- [ ] Run `cd client; flutter test test/tasks/task_models_test.dart test/tasks/task_api_test.dart`.
- [ ] Commit: `git add client/lib/screens/tasks client/test/tasks && git commit -m "feat(tasks): add client task contracts"`

### Task 16: Add Tasks shell, board directory, table, and responsive detail

**Files:**
- Modify: `client/lib/screens/tasks_screen.dart`
- Create: `client/lib/screens/tasks/board_directory.dart`
- Create: `client/lib/screens/tasks/task_table.dart`
- Create: `client/lib/screens/tasks/task_mobile_list.dart`
- Create: `client/lib/screens/tasks/task_detail_panel.dart`
- Modify: `client/lib/core/module_screens.dart`
- Modify: `client/lib/core/address.dart`
- Modify: `client/lib/main.dart`
- Modify: `client/test/address_test.dart`
- Test: `client/test/tasks/tasks_screen_test.dart`
- Test: `client/test/tasks/task_table_test.dart`
- Test: `client/test/tasks/task_deep_link_test.dart`

- [ ] Write widget tests for loading/empty/error/refusal, directory/favorites/private badges, add-board in exact current view, group collapse, inline edit, selection, pagination, conflict banner, cold canonical task route, malformed/foreign route, and Back behavior.
- [ ] Implement the accepted Task 1 layout using existing EzBizi controls and `TabBarLike`. Desktop uses a resizable/collapsible grid-detail split that preserves selection, grid scroll, and dirty edits; mobile uses grouped cards and a full-screen task detail route with the durable description above conversation.
- [ ] Preserve table header and primary controls without horizontal page scrolling; the column region may scroll with pinned task name/selection controls. Mobile never renders the desktop grid shrunk down.
- [ ] Add semantic labels, visible focus, keyboard row movement/edit/escape, 44px touch targets, and non-color status/category cues.
- [ ] On an access-revoked 403/404/unavailable response, immediately evict cached board rows, title, detail, filenames, linked-record labels, and dirty snapshots, return to a generic unavailable/directory state, and never render stale private data.
- [ ] Extend `Address` from first-segment-only lookup to a typed parse/build contract for `/tasks/boards/{board}/tasks/{task}`. `HomeShell` selects the Tasks destination and hands it a pending navigation intent; Tasks consumes it after the authorized fetch and clears it only on success or generic unavailability. Do not pass raw route IDs directly into rendered state.
- [ ] Preserve the `tasks` registration added in Task 6 and rerun the server/client registry contract after expanding the screen.
- [ ] Run the focused Flutter tests, `flutter analyze`, and the Task 6 PHP registry suite.
- [ ] Commit: `git add client && git commit -m "feat(tasks): add responsive board table"`

### Task 17A: Add typed cells, inline editing, and bulk actions

**Files:**
- Create: `client/lib/screens/tasks/task_column_cells.dart`
- Test: `client/test/tasks/task_cells_and_bulk_test.dart`

- [ ] Test all eight types, invalid/unknown read-only fallback, owner/status/priority/dates, ineligible People, nonsortable multi-values, selection, atomic bulk errors, optimistic scalar rollback, and structural operations waiting for the server.
- [ ] Send the current expected version with every dependent task mutation and announce save/validation/conflict/selection/status outcomes to assistive technology.
- [ ] Run the focused test and `flutter analyze`; commit `feat(tasks): add typed cells and bulk editing`.

### Task 17B: Add updates, files, collaborators, and Watch/Mute

**Files:**
- Create: `client/lib/screens/tasks/task_updates.dart`
- Create: `client/lib/screens/tasks/task_participants.dart`
- Modify: `client/pubspec.yaml`
- Modify: `client/pubspec.lock`
- Test: `client/test/tasks/task_collaboration_test.dart`

- [ ] Add `flutter_html:^3.0` and `url_launcher:^6.3`. Test durable description, strict formatted updates/edit history, HTTPS-only external launch, unsafe-link refusal, mentions, controlled candidate-file claims, collaborators, explicit Watch/Mute, mandatory owner watching, participant search, access loss, and activity entries.
- [ ] Preserve unsent update text on a transient error but erase task content immediately on access revocation. Display server eligibility/refusal sentences without reconstructing permission logic.
- [ ] Run the focused test and `flutter analyze`; commit `feat(tasks): add task collaboration`.

### Task 17C: Add governed-record links

**Files:**
- Create: `client/lib/screens/tasks/task_record_links.dart`
- Test: `client/test/tasks/task_record_links_test.dart`

- [ ] Test type-specific search, board-scope intersection, link/unlink conflicts, deep links, unavailable generic type state, and removal of target ID/name/subtitle/route from cached/rendered data after access loss.
- [ ] Never construct a record route from raw type/ID; use only authorized server deep-link metadata.
- [ ] Run the focused test and `flutter analyze`; commit `feat(tasks): add governed record links`.

### Task 17D: Add filters, saved views, My Tasks, quick search, and convergence

**Files:**
- Create: `client/lib/screens/tasks/task_filters_panel.dart`
- Create: `client/lib/screens/tasks/task_views_bar.dart`
- Create: `client/lib/screens/tasks/my_tasks_view.dart`
- Create: `client/lib/screens/tasks/task_quick_search.dart`
- Test: `client/test/tasks/task_views_and_search_test.dart`
- Test: `client/test/tasks/task_accessibility_test.dart`

- [ ] Test search/Person/filter/sort/hide/order/width/group, personal/shared unlocked/shared locked lifecycle, view conflicts, full-result summaries, My Tasks, number/title/board quick search, and inaccessible private omissions.
- [ ] Debounce search and ignore stale responses with a monotonically increasing request token. Keep filters in view state, not global session state.
- [ ] Refresh on open, app resume, successful notification navigation, and manual refresh. While visible, conditionally poll board/My Tasks and any open detail every 30 seconds with their separate ETags, then pause in background. HTTP 304 changes nothing; newer core/detail rows never overwrite a dirty editor or update composer and instead show changed/conflict state.
- [ ] On permission/reach/membership loss, clear board/detail/link/file caches and return to a generic unavailable/directory state. Test remote update/archive, dirty preservation, and revocation while polling.
- [ ] Run the focused tests and `flutter analyze`; commit `feat(tasks): add task views and convergence`.

**Green checkpoint:** the accepted desktop and mobile flows work against the real API with keyboard, screen-reader, conflict, loading, empty, and refusal states.

### Task 18: Complete board administration, participation, duplication, and transfer UI

**Files:**
- Create: `client/lib/screens/tasks/task_board_settings.dart`
- Create: `client/lib/screens/tasks/task_structure_editor.dart`
- Create: `client/lib/screens/tasks/task_board_members.dart`
- Create: `client/lib/screens/tasks/task_duplicate_flow.dart`
- Create: `client/lib/screens/tasks/task_transfer_flow.dart`
- Modify: `client/lib/widgets/notification_bell.dart`
- Modify: `client/lib/main.dart`
- Test: `client/test/tasks/task_administration_test.dart`
- Test: `client/test/tasks/task_transfer_test.dart`

- [ ] Test board create/rename/description/visibility/archive/restore, folders/favorites/last-opened, owner/member/recovery capability states, participant pickers, group CRUD/reorder, status/priority configuration, board-level core-label rename versus per-view hide/order/width, custom column suffix/add/rename/order/archive, group-targeted Add task, Add group/Add column, collaborator/watch controls, cross-place structure duplication, same-place task-inclusive duplication, import preview/commit, and every export mode.
- [ ] Render administration only when server capabilities say the actor is an active owner-manager. Hidden/disabled controls never substitute for authorization, and every refusal uses the server sentence.
- [ ] Support every administration/transfer flow at desktop and 375px. Desktop follows the accepted panel; mobile uses full-screen routes with Back and preserves unsaved mappings across pickers.
- [ ] Show import reconciliation with source/created/skipped/error counts, ineligible users, status/category decisions, unsupported fields, cycles/missing parents/grandchildren, and board-structure conflict. Disable Commit for blocking errors.
- [ ] Provide current-view/board CSV/XLSX export under `tasks.export` and owner-only archive manifest with clear attachment/revision limitations. Show bounded progress and allow the client request to cancel without implying an already committed import can roll back.
- [ ] Give `NotificationBell` an explicit navigation callback/intent bus rather than only `ApiClient`. A Task tap closes the notification panel, asks `HomeShell` to navigate through the canonical typed route, and refreshes the destination; an unavailable/filtered notification has no route or cached title. Test cold/foreground taps, Back, access loss between list and tap, and unread counts.
- [ ] Run `cd client; flutter test test/tasks; flutter analyze`.
- [ ] Commit: `git add client/lib/screens/tasks client/lib/widgets/notification_bell.dart client/test/tasks && git commit -m "feat(tasks): add task administration and transfer"`

## Milestone 5 — Integration, Security, and Release Readiness

### Task 19: Add end-to-end security and workflow coverage

**Files:**
- Create: `tests/Feature/Tasks/TaskWorkflowTest.php`
- Create: `tests/Feature/Tasks/TaskSecurityTest.php`
- Create: `client/test/tasks/tasks_golden_test.dart`
- Create: `client/test/goldens/tasks/directory_desktop.png`
- Create: `client/test/goldens/tasks/board_desktop.png`
- Create: `client/test/goldens/tasks/detail_desktop.png`
- Create: `client/test/goldens/tasks/settings_desktop.png`
- Create: `client/test/goldens/tasks/import_desktop.png`
- Create: `client/test/goldens/tasks/conflict_desktop.png`
- Create: `client/test/goldens/tasks/directory_mobile.png`
- Create: `client/test/goldens/tasks/board_mobile.png`
- Create: `client/test/goldens/tasks/detail_mobile.png`
- Create: `client/test/goldens/tasks/refusal_mobile.png`

- [ ] Test the complete workflow: create company board, configure, create/assign/describe/update/link/comment/filter/save/export/complete, then verify glance, notification intent/read, automation outbox/action, activity/audit, archive, and archive manifest.
- [ ] Attack foreign/mismatched compound tenant-board-group-parent/archive-parent/option/column/folder/user IDs through HTTP and direct writer/model paths; nullable organization-place folder bypass; sibling reach; company-to-organization creation/mutation; private non-member; owner recovery; guessed/cross-record attachment and public rebind; link spoof/snapshot revocation; every core/detail/order/view/structure stale version; forged participant; unsafe URL/HTML; spreadsheet ZIP/formula/session attacks; archived board; notification dedupe/lease/unread-count leakage; and membership revocation race. Nothing may leave inconsistent or partial rows.
- [ ] Assert the complete mutation matrix and rollback/idempotency behavior: failure leaves no business row, activity, audit, outbox, notification intent, or consumed import marker. Payloads contain only classified allowlisted content.
- [ ] Add deterministic 1440px/375px goldens for directory, populated board, mobile cards, detail, settings, import mapping, empty, refusal, and conflict. Generate with `flutter test test/tasks/tasks_golden_test.dart --update-goldens`, inspect every PNG against the accepted layout and record approval in `tasks-layouts-review.md`, then rerun without `--update-goldens`; CI only compares checked-in PNGs.
- [ ] Run `php -d memory_limit=1G vendor/bin/phpunit tests/Feature/Tasks` and `cd client; flutter test test/tasks; flutter analyze`.
- [ ] Commit: `git add tests/Feature/Tasks client/test/tasks && git commit -m "test(tasks): cover workflow and isolation"`

### Task 20: Documentation, versioning, board, and final gates

**Files:**
- Modify: `docs/EZBIZI-STATUS.md`
- Modify: `docs/EZBIZI-TODO.md`
- Modify: `client/VERSION`
- Modify: `client/lib/core/version_history.dart`
- Modify: `H:\Development\integrations-board\data.json`

- [ ] Document Phase 1 behavior, permissions, board place/private rules, notification/redaction lifecycle, trigger/action catalog, restore-tested FileStore result, import/export security, the archive-manifest/no-restore boundary, and explicit Phase 2/3 boundary. Remove the Tasks work only from TODO items actually delivered.
- [ ] Bump the client version once and add a user-facing history entry. Do not bump separately for intermediate tasks.
- [ ] Run `git diff --check`, `php composer.phar audit`, `php -d memory_limit=1G vendor/bin/phpunit`, then `cd client; flutter analyze; flutter test`. Inspect the complete branch diff and untracked-file list for credential values/private keys without truncating output; `StartUp.md` requires a scan but names no executable scanner, so do not claim an unnamed automated gate ran.
- [ ] Run `git status --short` and inspect every changed file. Preserve or separately account for all pre-existing feature work; no unrelated file belongs in the Tasks commit series.
- [ ] Ask an independent code reviewer to check the implementation against the spec and this plan. Resolve all P0/P1/P2 findings or record an owner-accepted exception in `docs/EZBIZI-STATUS.md`.
- [ ] Tick the board's implementation steps and set `tasks-module` to done only after all gates are green. If store/server/domain work remains, leave it in progress or waiting and name the exact dependency.
- [ ] Use `superpowers:verification-before-completion`, then `superpowers:finishing-a-development-branch`. Do not deploy or merge without the owner's explicit choice.
- [ ] Final commit: `git add docs/EZBIZI-STATUS.md docs/EZBIZI-TODO.md client/VERSION client/lib/core/version_history.dart && git commit -m "docs: record Tasks phase 1"`

## Consensus Review Record

Codex and Grok completed this checklist with no blocks. Questions 1–9 were accepted unchanged; Grok's changes to 10–12 were technically reconciled and incorporated as recorded in `docs/superpowers/reviews/2026-08-26-tasks-codex-grok-consensus.md`. The questions remain here as the implementation acceptance checklist:

1. Does exact current-view filing prevent every known sibling/company-to-organization authority leak?
2. Is scope-visible/private board access understandable and recoverable without creating an admin bypass?
3. Are structural fields vs typed custom fields drawn at the right boundary for a permanent core?
4. Does stable status category plus editable label/color preserve automation/reporting semantics?
5. Are zero/one accountable owner, separate collaborators and Watch/Mute preferences, and multi-People custom fields clear enough?
6. Are record links governed by target policies and safe after target access is revoked?
7. Are concurrency, atomic bulk behavior, ordering, audit, outbox, and notification timing complete?
8. Are Phase 1 table/My Tasks and Phase 2/3 deferrals sufficient to replace ordinary Monday use without creating dead schema?
9. Is spreadsheet transfer safe against formula injection, oversized files, and partial imports?
10. Is the plan executable in independently green milestones, with no server/client registry gap?
11. Does the intent lease/dedupe/redaction lifecycle prevent both lost and duplicate notifications without leaking revoked work through unread counts?
12. Are restore-tested FileStore bytes and the canonical client deep-link/detail-refresh contracts sufficient release gates for a Monday replacement?

Any later proposed change must include: user-visible benefit, security/data consequence, schema/API consequence, Phase 1 schedule consequence, and exact replacement text. The owner still approves the consolidated package and selected layout before implementation begins.
