# Tasks — design

**Date:** 2026-08-25  
**Status:** Codex–Grok consensus accepted and **A — Dense Grid** selected by the owner on 2026-08-26; not built  
**Product:** EzBizi customer app (`app.ezbizi.com`)  
**Reference:** monday.com boards and views, especially the supplied grouped-table screenshots. This is a functional replacement shaped around EzBizi, not a visual or code port.

---

## 1. What it is

Tasks is EzBizi's shared work-management module: configurable boards containing groups, tasks, and one level of subtasks. It replaces the separate monday.com workflow with work that already understands EzBizi's organization, companies, franchises, users, roles, customers, jobs, vehicles, inventory, inspections, services, and future modules.

The destination is a full monday.com replacement, delivered in complete phases. Phase 1 is not a disposable miniature: it establishes the permanent board, task, custom-column, access, collaboration, view, audit, and automation contracts. Later views and column types extend those contracts without replacing them.

Success means a team can stop tracking ordinary operational work in monday.com: create a board in the place where the work belongs, configure its workflow, assign and discuss tasks, link EzBizi records, find overdue work, and see dependable progress from desktop or phone.

---

## 2. What it is not

- Not a second organization hierarchy. EzBizi's current organization/company/franchise view is the workspace.
- Not a generic database whose configuration can redefine tenant isolation, permission identifiers, ownership, or completion.
- Not Jobs renamed. A Job is customer work with scheduling and price; a Task is internal work that may link to a Job.
- Not Automations duplicated inside Tasks. Tasks declares events and actions to the existing automation engine.
- Not a collection of modal dialogs. Desktop uses the existing right-side detail-panel convention; narrow screens use a full-screen detail route with Back.
- Not a literal copy of monday.com's branding, layout, source, marketplace, AI features, or every historical feature.
- Not external guest access in Phase 1. Shareable boards require a separate external-identity and permission design.
- Not unlimited task nesting. The hierarchy is task → subtask, one level.
- Phase 1 has no presence, live cursors, or server-push editing. It does have bounded convergence: refresh on board open, app resume, notification navigation, and manual refresh, plus 30-second conditional foreground polling for the visible board/My Tasks result and any open task detail. Polling pauses in the background and never overwrites a dirty editor or update composer; a newer core/detail version surfaces as changed/conflicted. Server push may replace polling later without changing the records.

---

## 3. Settled decisions

| # | Decision |
|---|---|
| 1 | Architect for a full monday.com replacement; release it in complete phases. |
| 2 | Every board belongs to the organization, a company, or a franchise selected by the current EzBizi view. |
| 3 | Tasks inherit the board's place. A task cannot quietly file itself in a sibling company. |
| 4 | Phase 1 board visibility is **Scope-visible** or **Private internal**. Private membership narrows access; it never grants missing permission or assignment reach. |
| 5 | EzBizi scope replaces monday.com Workspaces. Favorites and folders organize boards without creating another authority tree. |
| 6 | The model is hybrid: structural task fields plus board-defined typed custom columns. |
| 7 | A task has zero or one accountable owner and any number of collaborators. Watching is a separate per-user notification preference; custom People columns may contain several users. |
| 8 | Board-defined status labels map to stable categories: Not started, Active, Blocked, Completed, or Cancelled. |
| 9 | Task nesting stops after one subtask level. |
| 10 | Existing and future modules register records that Tasks may link. Links never grant access to the target record. |
| 11 | Groups organize rows. They do not own access, scope, or completion semantics. |
| 12 | A sidebar Tasks tab must have a dashboard glance, import/export inside the tab, automation declarations, audit coverage, and a 375px design. |

---

## 4. Scope is the workspace

```
Organization view
 ├── organization boards
 ├── company boards in reach
 └── franchise boards in reach

Company view
 ├── that company's boards
 └── boards of its Sub Companies / franchises in view

Franchise view
 └── that franchise's boards
```

A board stores `organization_id` on every row and exactly one filing place:

- organization: `company_id = null`, `franchise_id = null`;
- company: `company_id` set, `franchise_id = null`;
- franchise: both set, with the franchise belonging to that company.

Because nullable compound foreign keys do not compare `null` values, every directly place-owned Tasks table (folders and boards) also stores the same non-null stored generated `place_key` pattern used by existing EzBizi catalogue tables: `CONCAT(IFNULL(company_id,'org'), ':', IFNULL(franchise_id,'*'))`. Task setup first adds/reuses candidate keys `companies(organization_id,id)` and `franchises(organization_id,company_id,id)`; folders and boards reference those tuples when IDs are present. Folders expose `(organization_id,id,place_key)`, and a board with a folder references it through `(organization_id,folder_id,place_key)`, proving exact equality even at organization level. Place/base-column foreign keys use `RESTRICT`, because MySQL does not permit `SET NULL`/`CASCADE` actions on stored-generated-column dependencies. MySQL tests exercise all three place shapes directly.

Creation uses `companiesInView`, `franchisesInView`, `filingCompanyId`, and `filingFranchiseId`. The switcher lists every place a person can reach; Add Board has no place picker and files at the exact current view: organization, company, or franchise. A creation decision separately proves release/entitlement, `tasks.manage`, and assignment reach at that exact filing level; it cannot treat `ViewScope::forUser()`'s organization fallback as organization authority. Authority flows down the assignment subtree and never up.

Changing a board's place after creation is not offered. Moving it would silently reinterpret every task, member, record link, saved view, and historical event under a different authority tree. An owner-manager may duplicate board structure into the exact current view only when the source is also covered by that active view and passes separate source authorization. Otherwise, the person creates a blank board after switching to the target view, migrates rows/configuration through the reviewed CSV/XLSX preview/import flow, then archives the source. Task-inclusive board duplication remains limited to an identical filing place.

The module landing page shows boards in the current view. A person's favorites are a user preference. Optional folders are organizational labels inside one filing place, not access boundaries; a folder cannot contain boards from another place.

---

## 5. Board access and ownership

Board access is the conjunction, in order:

```
release flag → entitlement → tasks permission → assignment reach
             → board visibility/membership
```

`scope` boards are visible to anyone who holds `tasks.view` and can act in the board's place. `private` boards require the same permission and reach **plus** an active `task_board_members` row.

Every board has one or more active owner memberships. Creation inserts the creator into `task_board_members` with role `owner`; `task_boards` has no second authoritative owner field. Membership rows exist for scope-visible and private boards, although ordinary membership narrows read visibility only for private boards.

Board administration requires all of: `tasks.manage`, assignment reach for the board's place, current board access, and an active owner membership. `tasks.manage` is not authority to administer every visible board, and owner is not a permission bypass. Board owners may add co-owners or members only from active users who already hold `tasks.view` and reach the board's place. Membership never expands `/me` reach and cannot become a second assignment system.

Removing or demoting the last currently eligible owner is refused. A board is orphaned only when all recorded owners become ineligible through deactivation, lost permission, or lost reach. An organization Administrator may run a dedicated, audited recovery transfer to a named eligible user. Recovery locks membership, returns only minimal confirmation, never reveals board content, and neither adds the Administrator nor becomes an alternate view/manage path.

Boards may be archived and restored. Normal removal is archive. Permanent erasure exists only through the separately authorized retention/privacy machinery. It preserves an audit event's business meaning, resource identity, time, and action while applying the existing policy's redaction/pseudonymization to direct personal identifiers; it does not promise to keep an identifiable actor forever.

---

## 6. Board, group, task, subtask

```
Board
 ├── Group
 │    ├── Task
 │    │    ├── Subtask
 │    │    └── Subtask
 │    └── Task
 └── Group
```

### Board

Name, optional description, place, visibility, owners/members, status definitions, priority definitions, column definitions, views, and ordered groups.

A new board starts from the EzBizi Tasks template: **To do** and **Completed** groups; status options mapped to Not started, Active, Blocked, and Completed; core Owner, Status, Priority, Start date, and Due date columns. These are editable words and arrangements, not organization-specific constants.

### Group

Name, colour token from the EzBizi palette, position, `order_version`, collapsed preference, and archive state. A group is presentation and workflow organization only. Calling a group “Completed” does not complete its rows; status category does.

Archiving a group is refused while any active top-level task belongs to it; move or archive those tasks first. Task creation/move into an archived group is refused. Restoring an empty/history-only group is a `structure_version` write and does not restore tasks. Archived groups and their already archived rows appear only in owner-authorized history/export. Permanent deletion remains retention/privacy machinery. Reordering groups and tasks is transactional.

### Task

UUIDv7, organization/board, a direct group only for a top-level row, optional parent task, title, optional safely formatted description, position, owner, status option, priority option, start date, due date plus `due_revision`, completed/cancelled metadata, creator, updater, optimistic core `version`, `detail_version`, archive state, optional `archived_with_parent_id`, and timestamps. Description is the durable task brief; Updates remain the conversation/history.

Subtasks use the same table and fields, but store `group_id = null`; top-level rows require a group. A CHECK enforces exactly one of direct group or parent, and the compound parent FK proves same organization/board. The effective subtask group is always joined from its parent, so no importer or direct writer can persist a same-board/different-group child and moving the parent changes the effective group without synchronizing duplicate child fields. A subtask cannot itself be a parent; the writer enforces that cross-row depth rule. Completing or cancelling a parent never silently changes subtask status. Archiving a parent locks and transactionally archives its active subtasks, marks them `archived_with_parent_id`, increments each affected core version, and emits each affected task's ordinary archived activity/audit/outbox event under one causation ID. Restoring that parent restores/increments only those auto-archived rows, not subtasks a person archived earlier.

Moving a task between groups on the same board is Phase 1. Duplicating a task to another board in the same place is Phase 1 with explicit column mapping. Moving between boards or places is later because different boards have different status and column definitions.

Task number is organization-wide through `RecordNumberGenerator` (`task`, default prefix `T-`). The display remains the title; the number is a stable reference for search, updates, and links.

---

## 7. Structural fields and custom columns

The following are structural and cannot be replaced by custom columns:

- task title, number, and description;
- board, group, and parent;
- accountable owner;
- status and its semantic category;
- priority;
- start date and due date;
- creator/updater;
- completed/cancelled actor and time;
- archive, version, and audit metadata.

Board owners can rename structural-column display labels without changing meaning. Those labels live in board-level `task_core_column_settings` keyed by a stable core key. Show/hide, width, and order are saved-view configuration, so a board rename is shared while personal layouts remain personal.

Phase 1 custom column types:

| Type | Value |
|---|---|
| Text | Plain text, length-limited; no executable markup |
| Number | Decimal value with optional display suffix |
| Checkbox | True/false |
| Date | Calendar day in the board place's timezone |
| People | One or several reachable EzBizi users; not the accountable owner |
| Label | One or several board-defined options with palette tokens |
| Link | Valid `https` URL plus optional label |
| File | An existing Attachment already bound to the same task through FileStore |

Advanced types—dependency, duration, timeline, formula, roll-up, connected-board/mirror, location, vote, and AI—belong to later phases.

Column definitions have immutable type after the first value is written. A board owner may rename, reorder, or archive a definition; visibility and width remain view configuration. Number definitions store an optional display suffix, and Label options remain separately ordered palette definitions. Changing Text to Date by reinterpreting existing values is not offered; create the Date column and migrate deliberately. A File value may reference only an attachment whose parent is that same task; supplying an attachment from another task or record never rebinds it.

Queryable custom values are not stored as an unvalidated JSON blob on `tasks`. Definitions validate values server-side, and storage keeps typed projections so filters and sorts do not decode every task in PHP. Multi-value People/Label/File relationships use link rows. The API still returns one normalized value shape per column type.

---

## 8. Status, priority, and completion

Each board defines its own visible status labels and colours. Every status option maps to exactly one structural category:

- `not_started`
- `active`
- `blocked`
- `completed`
- `cancelled`

The board must always have at least one non-terminal option and one Completed option. An option already used by tasks may be renamed, recoloured, or archived but not hard-deleted. Its structural category—and a used priority option's structural rank—cannot change in place; archive it and create a new option, or run a deliberate versioned migration through `TaskWriter`.

Entering a Completed category records `completed_at` and `completed_by`. Leaving it records a reopen event and clears the current completion markers; audit and automation history retain the original completion. Cancelled records `cancelled_at` / `cancelled_by` separately and does not count as completed work.

Priority is board-defined wording over stable ranks: low, normal, high, and urgent. SQL `NULL` is the permanent **None** state, not a priority option or enum value. Rank, not label, drives sorting and notifications.

Group summary bars are derived from the status categories of visible rows. They do not store a second progress number.

---

## 9. Responsibility and collaboration

The core Owner is nullable and singular. Unassigned tasks are valid for inbox/triage boards. “My Tasks,” workload, due notices, and assignment automations use this field only. A new owner must be active, hold `tasks.view`, reach the board's filed place, and—on a private board—already be a member. Later access loss preserves the historical assignment but suppresses content and notifications until access is restored or the task is reassigned.

Collaboration and watching are separate. `task_collaborators` records participation. `task_watch_preferences` records explicit `watched` or `muted` notification preference. Owners are always watched and cannot mute while owner. Collaborators, update authors, and mentioned users default to watched but may mute without losing their other relationship. Explicit Watch records `watched`.

Adding or removing another collaborator requires `tasks.edit`. Any accessible task viewer may set only their own explicit Watch/Mute preference; nobody may choose another person's notification preference. Removing collaboration does not erase that person's explicit preference, and muting does not remove collaboration.

Any new owner, collaborator, mention target, People-column user, or person setting Watch must pass one `TaskParticipantEligibility` check: active user, `tasks.view`, assignment reach at the filed place, and active private membership when applicable. Selecting a person never adds board membership. Watching never grants board/task access, and later access loss immediately stops delivery.

Participant pickers use a dedicated search API capped at 20. Membership purpose requires `manageDecision(...)` and returns active users with `tasks.view` and filed-place assignment reach without requiring existing membership. Task-create purpose requires `tasks.create`; owner/People/collaborator edit purpose requires `tasks.edit`; mention purpose requires `tasks.comment`; import resolution stays inside the owner-managed import preview. Each task purpose applies the same eligibility predicate and, for private boards, requires active membership. Watch is self-only and has no person picker. No flow derives choices from raw `/me` reach.

Each task has an Updates feed:

- plain text with safe formatting;
- `@mentions` of users who already reach the board;
- attachments through FileStore;
- chronological activity entries for meaningful field changes;
- edit history for an update rather than silent replacement;
- archive, not invisible hard-delete, for an update.

Phase 1 authoring/rendering uses one strict HTML subset (`p`, `br`, `strong`, `em`, `ul`, `ol`, `li`, and HTTPS `a`). The server purifies it with the repository's HTMLPurifier dependency and also returns a plain-text projection. The Flutter client uses an explicit formatting toolbar and `flutter_html:^3.0` for display; a parsed URI must still be HTTPS before `url_launcher:^6.3` opens it externally.

Update attachments use a controlled candidate/claim path rather than a public rebind endpoint. A `tasks.comment`-authorized endpoint reuses FileStore to attach each upload to the parent Task and creates a private `task_update_attachment_candidates` marker for that actor with a 24-hour expiry. Generic Task attachment lists/downloads and File-column selection exclude candidates; only the uploader may preview or remove one before claim. `TaskUpdateWriter::create` locks the candidate row before proving same organization/task/actor/unexpired Task-bound state, creates the update, rebinds the Attachment through a domain-only operation, and removes the marker in one transaction. Conflict or rollback leaves the private candidate staged. The hourly cleaner claims expired rows with `FOR UPDATE SKIP LOCKED`, rechecks expiry and Task-bound ownership while locked, deletes database rows first, and deletes bytes only after commit; claim and cleanup therefore cannot both win. Events and notification intents are written only after update/file ownership is final. File-column values continue to reference ordinary Task-bound attachments without rebind.

Updates are collaboration. Immutable `task_activities` are the durable human activity feed for meaningful field changes. `audit_events` remain the separately retained compliance record with exact actor, before/after, place, request, and technical identity; the feed never depends on audit retention.

---

## 10. Links to EzBizi records

Tasks introduces a registry rather than a switch over current module names. A linkable-record declaration supplies:

- stable type key and user-facing singular/plural labels;
- search callable scoped to `ViewScope ∩ assignment reach`;
- permission required to see/name the record;
- resolver for display identity and existence;
- route/deep-link information when the client can open the record;
- privacy/data-inventory classification.

Initial declarations: Customers, Jobs, Vehicles, Inventory Items, Inspection Reports/Runs, HR People, and Services. A module with no meaningful task relationship declares none.

A task may link several records, including several types. Every target must belong to the same organization, sit inside the board's place, be visible to the actor, and pass the target module's view permission. Linking does not modify the target and does not grant future access to it.

The link stores only the declaration's allowlisted display snapshot, classified in the privacy inventory. That snapshot is never an authorization substitute. Every read, open, notification, audit presentation, and export re-fetches the target through its module policy before returning label, subtitle, route, or snapshot fields.

If the target is denied, moved, erased, or unavailable, the API returns only the task-link's opaque identity, `available:false`, and the registry type label. It omits target ID, label, subtitle, route, and snapshot. Audit/outbox payloads store stable type/link IDs, not display snapshots, and privacy erasure redacts the stored snapshot according to the target declaration.

---

## 11. Table and saved views

Phase 1 ships the permanent board-view contract and two renderers:

1. **Main table** — the authoritative grouped board.
2. **My Tasks** — tasks owned by the signed-in person across boards in the current EzBizi view.

The Main table supports inline title/owner/status/priority/date edits, task and group reordering, selection, bulk status/owner/group/archive actions, search, person filter, multi-condition filters, multi-column sort, hidden columns, column widths, and temporary Group by one compatible field. Text, Number, Checkbox, Date, and Link (normalized URL) custom values are sortable. Multi-People, multi-Label, and File values support their declared membership/empty filters but are not sortable in Phase 1.

A current-view quick search finds accessible tasks by task number, title, and board name, including tasks not owned by the caller. Results never include an inaccessible private board and deep-link only after access is rechecked.

The default grouping is the board's stored Groups. Alternate Group by reshapes the view without rewriting a task's actual group. Saved filters/sorts/hidden columns/grouping are view configuration, not task data.

Saved views may be:

- personal: visible, ordered, and editable only by their owner;
- shared unlocked: visible to every board viewer and editable in place by viewers holding `tasks.edit`;
- shared locked: visible to every board viewer but editable only by an active board owner holding `tasks.manage`.

Any board viewer may duplicate a shared view as personal. Only an active owner with `tasks.manage` may share, lock/unlock, reorder, or delete shared views. Shared ordering is a board-structure write; personal view ordering is owner-only and uses the personal view version. The permanent Main table cannot be deleted. Every view write carries the version that owns that state.

The versioned configuration schema is `{schema:1,presentation:"table",filters:[],sorts:[],hidden_columns:[],column_order:[],column_widths:{},group_by:null|string,search:string}`. The server validates every field, operator, direction, and referenced definition; archived/foreign definitions never become SQL identifiers. Collapsed groups, density, last active view, and scroll position are personal preferences. Group counts and status summaries describe the complete filtered accessible result before pagination, never only the current page.

Phase 2 adds Kanban, Calendar, Timeline/Gantt, Dependencies, and Workload using the same saved-view rows. Phase 3 adds forms, chart/dashboard views, templates, and advanced reporting.

---

## 12. Screen behavior

One sidebar destination: **Tasks**.

The module opens the most recently opened accessible board in the current scope, recorded in the caller's `task_board_preferences`, or a board directory when none is valid. The same preference row stores favorite state; neither value affects access. The board header contains board selection/favorite, view tabs, and owner-only board settings. The action row contains Add task, Search, Person, Filter, Sort, Hide, and Group by—the useful behavior visible in the supplied reference, restyled in EzBizi's chrome and palette.

Desktop uses a dense, horizontally scrollable grid. The first task column stays pinned. Opening a task uses a collapsible/resizable board-and-detail split based on the selected prototype rather than a fixed ratio; selection, grid position, and dirty edits survive resizing/collapse. The detail pane holds description, fields, links, subtasks, updates, files, collaborators, personal Watch/Mute controls, and activity. No modal.

At 375px the table does not shrink into illegible cells. It becomes a touch-first task list showing title, owner, status, priority, and due state. Filters and board selection open full-height panels. Opening a task is a full-screen route with Back. Reordering has accessible move controls in addition to drag.

The canonical task route is `/tasks/boards/{board}/tasks/{task}`. Client `Address` parsing produces a typed Tasks navigation intent rather than discarding path segments; `HomeShell` selects the Tasks module and the Tasks screen consumes the pending board/task intent only after authorization. Quick-search, in-app notification, and governed-record links use the same route contract. A notification tap closes the notification surface, requests navigation through the shell, and the destination fetch reauthorizes before rendering.

Before Flutter implementation, produce at least three HTML alternatives in actual EzBizi chrome using the real palette and realistic task data, each at desktop and 375px. Every alternative includes board settings and an import-preview/mapping flow as well as the board/detail interaction. The owner selects one. This specification fixes information and behavior, not an unreviewed pixel arrangement.

---

## 13. Permissions and refusals

Module actions:

| Permission | Allows |
|---|---|
| `tasks.view` | Reach the tab and read accessible boards/tasks |
| `tasks.create` | Create tasks and subtasks on an accessible board |
| `tasks.edit` | Change core/custom task values, move groups, manage collaborators, and edit shared-unlocked views |
| `tasks.comment` | Add/edit/archive own updates and attach files without broader task editing |
| `tasks.delete` | Archive/restore tasks; it never grants ordinary hard deletion |
| `tasks.export` | Export accessible task data from a board/current view |
| `tasks.manage` | Create/configure/archive boards, groups, columns, statuses, owners/private membership, and shared-view governance |

Holding any action implies `tasks.view`, through the existing catalogue rule. Board owner/member labels never replace permissions.

Representative server sentences:

| Situation | Sentence |
|---|---|
| Current view cannot file a board | This view does not name a place where a board can be created. |
| User lacks assignment reach | This board is outside the places you are assigned to work. |
| Inaccessible identifier | This board is not available in your current access. |
| Add unreachable member | This person does not already have access to this board's place. |
| Link inaccessible record | That record is not available in this board's view. |
| Nest a subtask again | A subtask cannot contain another level. |
| Delete used status/column | Archive this option instead so existing tasks keep their history. |
| Stale inline edit | This task changed after you opened it. Review the latest values before saving yours. |

The client hides or disables controls it knows cannot work and shows the server sentence when a request is refused. It never reconstructs a different refusal from a permission key. A path lookup the caller is not already authorized to know exists returns one non-enumerating unavailable/404 shape regardless of whether the cause is tenant, place, private membership, archive state, or a guessed ID.

---

## 14. Writes, concurrency, archive, and audit

Every task row carries an integer core `version`. Core/custom values, collaborators, watch preferences, task-bound files, links, archive, and structural moves submit the version last read, lock task and relevant membership rows, recheck access, compare the version, and increment exactly once. A separate `detail_version` increments whenever updates, update attachments, activity, mentions, or other detail-feed state changes without owning the core edit version. The detail endpoint returns an ETag derived from both versions. A stale request returns current data only while the actor still has access; otherwise it returns the generic unavailable shape. Inline editing must not silently overwrite a colleague's later change.

Append-only updates use idempotency keys and do not conflict with unrelated core task edits. Editing/archiving an update submits its own `version`, appends one immutable revision, and increments task `detail_version`. Board configuration has `structure_version`; groups have `order_version`; views have `version`. Configuration, reorder, view, and import mapping writes compare their corresponding version transactionally. Shared-view ordering is board structure governed; a personal view's ordering is an owner-only versioned write.

Task reorder requests submit the moved ID, neighbors, and expected source/destination group `order_version`. The writer locks the affected groups and sibling rows, updates dense positions in one transaction, and increments each affected group once. A cross-group move also increments the moved task's core version; untouched siblings do not receive artificial task-version conflicts. Group/column/status/priority/shared-view reorder uses board `structure_version`. Position compaction is a server concern, not a client loop of individual writes.

All meaningful writes perform, in one transaction:

1. authorize the actor and current board state;
2. validate scope and typed values;
3. persist the record/change;
4. write the audit event;
5. insert automation outbox events;
6. insert notification intents where applicable; any job dispatch happens after commit.

A maintained mutation matrix covers board/configuration/recovery/membership, groups/options/columns/views, task/bulk/order, owner/collaborator/watch preference, custom value/file, update/revision/mention, link, and import mutations. Controllers, importers, commands, and automation actions call domain writers; they do not write these models directly. Audit/outbox/notification payloads use allowlisted stable IDs/categories and classified content only.

Boards, groups, columns, tasks, and updates archive before removal. Parent-task cascade/restore follows the `archived_with_parent_id` rule in §6. Archived children remain available only to authorized history/export modes. Privacy erasure may redact or pseudonymize personal content through the existing §9 machinery, but it does not rewrite the event into different business history.

---

## 15. Automations (§5.5)

The module subject is `Task`. Custom columns are not automation fields in Phase 1; structural task fields are.

Raised triggers:

| Key | When |
|---|---|
| `task.created` | Task or subtask created |
| `task.owner_changed` | Accountable owner assigned, changed, or cleared |
| `task.status_changed` | Status option changed |
| `task.due_date_changed` | Due date set, changed, or cleared |
| `task.completed` | Status enters Completed |
| `task.reopened` | Status leaves Completed for a non-terminal category |
| `task.update_added` | A person adds an update |
| `task.archived` | Task is archived |
| `task.overdue` | Scheduled sweep first observes an unfinished task past due |

The overdue trigger is idempotent per task due revision. `due_revision` increments only when `due_on` changes, so A → B → A is a new occurrence. The hourly scheduler may discover an occurrence late but never emits that revision repeatedly.

Automation actions:

- `task.create`: create a task on a named board/group. This action is not subject-bound, so a rule reacting to another module may create follow-up work.
- `task.assign_owner`: assign or clear the accountable owner on the Task that triggered the rule.
- `task.set_status`: change the triggering Task to a named option on its board.
- `task.set_due_date`: set or clear the triggering Task's due date.
- `task.move_group`: move the triggering Task to a group on the same board.
- `task.add_update`: add a system-authored update to the triggering Task.

Automation declaration identity and stored execution type are explicit, separate facts. `ActionDefinition` gains an optional execution type (defaulting to today's suffix behavior for existing shared actions) and a `subject_bound` flag (default `true`). Tasks declares the clean keys above and uses each same full key as its execution type; it does not add a doubled module prefix. This prevents implementing `task.create` from marking the existing `job.create` declaration implemented, while existing declarations such as `customer.update_field` and `job.update_field` continue to share the generic `update_field` execution type. The catalogue publishes execution type and applicability; module-only execution types are not duplicated under the builder's “Works with anything” group.

Every action re-enters the normal authorization/validation service as the active automation creator in the automation's stored scope; it never reads that user's last top-bar preference and never falls back to system authority. Existing `scope_type = null, scope_id = null` rules mean organization scope. Company/franchise rules require both stored fields to be valid; a half-null pair is refused. A missing/inactive creator, lost permission/reach, or inaccessible board fails the run in a sentence. Task events expose their board filing scope through `CarriesAutomationScope`. Loop limits, causation, idempotency, test mode, and action budget apply exactly as they do elsewhere.

---

## 16. Notifications

Notification intents:

- assigned as accountable owner;
- mentioned in an update;
- added as a collaborator;
- watched task changed status or due date;
- due soon / overdue, controlled by user preference and tenant setting;
- private board ownership transferred.

Relationship rechecks are deterministic: assignment and due notices require the recipient to remain current owner; collaborator-added requires an active collaborator row; mention requires an active mention on an accessible update; watched-change requires the current derived/explicit watch result to be watched; ownership transfer requires current eligible owner membership. Self-suppression and Mute are evaluated before intent insertion and again before delivery/presentation where relevant.

The business transaction inserts a durable, idempotent intent containing only recipient/kind/source-occurrence/task/board/place IDs—never title, body, filename, linked-record display, or rendered deep link. Every caller supplies one required opaque `source_occurrence_key`: the same transaction's per-task audit-event UUID for an ordinary/recovery/membership mutation, or the reminder/overdue occurrence UUID for scheduled work. Each intent has status (`pending|processing|delivered|suppressed|failed`), availability time, claim token/lease, attempt count, classified error code, and timestamps. The database uniquely constrains recipient + kind + source occurrence. The single-intent job and minute catch-up sweep share one bounded claim path; leases last five minutes, transient backoff caps at one hour, and attempt 10 becomes failed. Queue payloads contain only the intent ID, closing commit/queue and worker-crash windows without duplicate user-visible rows.

Immediately before each channel renders or sends, the dispatcher rechecks release/entitlement, current `tasks.view`, assignment reach at the board's filed place, active private membership, and the event's recipient relationship. Background checks use filed place rather than the recipient's active UI view. The shared `notifications` table gains one reusable nullable `delivery_key`, not a Tasks-specific foreign key. Delivery transactionally finds or creates at most one row by unique `(organization_id,user_id,source,delivery_key)`, using `source = 'task'` and the intent UUID as the opaque delivery key, then marks the intent delivered; a crash retry finds the same row. No intent-side delivered-notification ID is stored. The existing notification subject remains the actual Task for normal routing semantics, while the Tasks presenter reauthorizes it before exposing any subject metadata. In-app reads recheck again: an authorization/relationship SQL constraint is applied before limit and unread count, so inaccessible Tasks rows are omitted from both data and counts without starving older accessible rows. They cannot be marked read by ID and expose no task/board identity. `mark all read` touches only currently visible IDs. Queue payloads never serialize task models or rendered task data. In-app notifications ship in Phase 1; email waits on the existing mail-health gate.

---

## 17. Dashboard glance

Every reachable sidebar tab needs a glance. Tasks supplies, for the current view and caller's board access:

- open tasks;
- overdue tasks;
- due in the selected period;
- completed in the selected period;
- blocked tasks;
- top owners by open count, without exposing private-board rows the caller cannot open.

Default presentation is a status/attention chart with a plain-language empty state. Counts derive from structural categories, not visible status words.

---

## 18. Import, export, and replacing Monday

Import/export lives inside Tasks.

Phase 1:

- CSV/XLSX board import with preview and explicit mapping for group, task, stable source-row key, optional parent source-row key, owner email, status, priority, dates, and supported custom columns. Parents must resolve within the same import; cycles, missing parents, and grandchildren are blocking errors;
- preview reports source/created/skipped/error counts, unresolved or ineligible users, status/category decisions, unsupported columns, and parent errors. Users are never silently created and commit is all-or-nothing;
- preview/commit require active owner management plus `tasks.create`; creating configuration also requires `tasks.manage`. The one-use import session binds user, board, exact active view, file hash, normalized mapping, and board `structure_version` for 30 minutes;
- XLSX preflight rejects encrypted workbooks, macros, external links/connections, formulas, unsupported workbook features, excessive ZIP expansion/ratio/entries, dimensions, shared strings, rows, columns, cells, bytes, or time before an unrestricted workbook load;
- `tasks.export` plus current board access permits board/current-view CSV/XLSX export. User-controlled CSV cells with dangerous formula prefixes are escaped and XLSX text is always written with explicit string type;
- a versioned board archive manifest in JSON includes only authorized metadata and explicitly excludes attachment bytes. It is not called a restorable backup until a matching importer exists. Archived content and update-revision history additionally require active owner management.

Import commit and expiry cleanup lock the same `task_import_sessions` row. The committer validates expiry/unused/file identity while holding that lock. The cleaner claims only expired unconsumed rows with `FOR UPDATE SKIP LOCKED`, rechecks state under lock, deletes the session row first, and removes staging bytes only after commit. A claimant never observes a committed session whose bytes a cleaner may still remove.

File-bearing Tasks work is blocked until FileStore durability is proven, but non-file board/schema/access/task/view/automation development is not. Backup and restore-check support plus a disposable local fixture land first. Before any production-capable Tasks path may persist real file bytes, operations must either move the configured disk to durable object storage or extend the existing restore-tested backup to cover `storage/app/private`, then perform and document a production-sized restore/checksum verification through the normal approved deployment path. The explicit Task 10A operations checkpoint gates update-attachment candidates, import staging, and any usable File-column upload/selection path; a failed or missing proof leaves those paths closed while non-file work may continue.

Phase 3 adds a direct monday.com importer for boards, groups, items/subitems, supported columns, updates, and files where the source API permits. Migration is read-only against monday.com and produces a reconciliation report. Unsupported column types are preserved as text plus a warning rather than dropped.

---

## 19. Data shape

Proposed tables, all UUIDv7 with `organization_id` where tenant-owned:

- `task_folders`
- `task_boards`
- `task_board_members`
- `task_board_preferences`
- `task_groups`
- `task_core_column_settings`
- `task_status_options`
- `task_priority_options`
- `task_column_definitions`
- `task_column_options`
- `tasks`
- `task_column_values`
- `task_column_people`
- `task_column_labels`
- `task_column_files`
- `task_collaborators`
- `task_watch_preferences`
- `task_updates`
- `task_update_versions`
- `task_update_mentions`
- `task_update_attachment_candidates`
- `task_activities`
- `task_record_links`
- `task_views`
- `task_view_preferences`
- `task_overdue_occurrences`
- `task_due_reminder_occurrences`
- `task_notification_intents`
- `task_import_sessions`

Files use the existing attachments tables. Notifications, audit events, automation events/runs, and personal-data declarations use existing infrastructure.

Important constraints/indexes:

- exact board/folder place shape is enforced: `companies(organization_id,id)`, `franchises(organization_id,company_id,id)`, and folder `(organization_id,id,place_key)` candidate keys support the compound references; franchise requires company; and a stored generated non-null `place_key` proves folder/board equality even when filing IDs are null;
- every child repeats organization/board identity where needed and compound foreign keys reject mismatched tenant, board, group, parent, option, column, folder, and task identities below the HTTP layer;
- every tenant-owned actor/participant reference uses `(organization_id,user_id)` compound identity where nullable semantics permit, including board members, owners, collaborators, People values, mentions, creators/updaters, and completion/cancellation actors;
- board membership unique `(board_id, user_id)`, same organization, and is the only ownership source;
- a folder and board may relate only when their immutable organization/company/franchise place is identical;
- group/column/status/priority/view names unique where the screen requires it, case-insensitively;
- subtask parent on same board; parent itself has no parent;
- one custom value per `(task_id, column_definition_id)`;
- typed child values unique per task/column/target;
- record links unique per `(task_id, record_type, record_id)`;
- overdue occurrence unique per `(task_id, due_revision)` and stores the due-date snapshot;
- due-reminder occurrence unique per `(task_id, user_id, due_revision, lead_hours)` and stores the due-date snapshot;
- notification intent unique by recipient/kind/source occurrence, delivered in-app notification unique by intent, and import session nonce unique/one-use;
- indexes for board/group/position, owner/due/status, archived state, current-view scope, and typed filter/sort values.

MySQL is the truth. JSON key order is never asserted.

---

## 20. Phase boundaries

### Phase 1 — replace ordinary task boards

- module registry, permissions, policies, tenant declarations, and Tasks dashboard glance;
- scope-visible/private boards, owners/members, folders/favorites;
- groups, tasks, one-level subtasks, accountable owner, collaborators, and separate watch preferences;
- configurable statuses/priorities and Phase 1 custom column types;
- task links to registered EzBizi records;
- table/My Tasks views, saved filters/sorts/hide/group, inline and bulk edits;
- task detail, updates, mentions, files, activity, notifications;
- automation triggers/actions including overdue sweep;
- archive/restore, import/export, responsive design, tests, docs, and deploy.

### Phase 2 — planning and capacity

- Kanban, Calendar, Timeline/Gantt, Dependencies, and Workload views;
- dependency/duration/timeline columns;
- cross-board summaries that obey scope/private access;
- richer recurrence and due-date automation recipes;
- optional server-push refresh.

### Phase 3 — platform replacement

- forms, reusable board templates, chart/dashboard views;
- formula, roll-up, connected-board/mirror and additional typed columns;
- external guest/shareable boards with a dedicated identity model;
- direct monday.com migration and third-party integrations;
- advanced reporting and controlled public/embed surfaces if the owner still wants them.

Each phase receives its own implementation plan and review gate. Phase 1 does not contain speculative tables for later features beyond the extension points explicitly named here.

---

## 21. Test contract

Backend tests, at minimum:

- company view with a reachable sibling cannot create or move a board/task into that sibling;
- scope-visible board follows permission + assignment reach; private membership only narrows;
- adding a member without prior permission/reach is refused;
- organization Administrator can recover an orphaned private board and the act is audited;
- user who loses access cannot read updates, files, notifications, glance counts, search hits, or exports from that board;
- task/subtask depth and same-board constraints hold in MySQL;
- organization-place folder/board equality cannot bypass the database through nullable foreign-key semantics;
- parent archive/restore affects exactly its auto-archived subtasks and completion never auto-completes children;
- structural status categories drive complete/reopen/cancel metadata and group summaries;
- stale version refuses without overwriting; group order versions make reorder atomic without versioning untouched siblings; detail ETags change for remote Updates/files/activity;
- every Phase 1 custom type validates, supports its declared filter matrix, exports, archives, and round-trips; only the types named sortable in §11 sort;
- a custom column type cannot change after values exist;
- record links validate organization, current board place, reach, and target permission;
- target access loss hides every target identifier/display field but keeps an opaque unavailable link row and generic registry type;
- triggers are registered and every claimed raised trigger has a source assertion;
- automation actions use normal services and cannot escape board access;
- overdue occurrence emits once per due revision, rearms across A → B → A, and ignores completed/cancelled tasks;
- notification intent claiming survives commit/queue and worker-crash races without duplicate visible rows; access loss removes Tasks rows and their unread-count contribution;
- private-board counts do not leak through Dashboard or My Tasks;
- CSV/XLSX preview reports unknown users/statuses and import is transactional;
- privacy inventory, retention, audit, attachment, and export declarations include Tasks.

Client tests, at minimum:

- navigation appears only from server `tasks.view` reach;
- module screen uses only companies/franchises in the current view;
- scope-visible/private board states and refusal sentences render honestly;
- table interactions: add, inline edit, search, person, filter, sort, hide, group, select, bulk act, reorder;
- owner is singular; collaborators/custom People remain distinct;
- completed/reopened/cancelled appearance follows structural category;
- task panel includes links, subtasks, updates, files, collaborators, personal Watch/Mute controls, and activity without a modal;
- canonical task deep links select the Tasks tab and authorized board/task on cold open and notification tap;
- inaccessible linked target is not named;
- stale-edit conflict preserves the user's unsaved value while showing the latest server value;
- 375px board/task flows have no overflow or hover-only control;
- desktop grid supports keyboard focus, selection, and accessible reordering;
- saved personal/shared view behavior and permissions;
- Tasks dashboard glance and empty/error states.

The MySQL gate and Flutter analyze/test gate run before any completion claim. New tests are mutation-checked by removing/breaking the production line they protect and observing failure.

---

## 22. Review and implementation gate

Codex's review is recorded in `docs/superpowers/reviews/2026-08-26-tasks-codex-review.md`; Grok's independent pass is in `docs/superpowers/reviews/2026-08-26-tasks-grok-review.md`. The owner chose Codex and Grok as the review group while Claude is occupied. All three Grok change findings were checked against the live repository and reconciled into this revision, with exact dispositions in `docs/superpowers/reviews/2026-08-26-tasks-codex-grok-consensus.md`; no block remains.

The accompanying draft Phase 1 implementation plan names exact files, tests, task boundaries, review checkpoints, migration/deploy steps, STATUS/TODO updates, and work-board steps. The owner accepted the consensus revision and selected **A — Dense Grid** on 2026-08-26. The alternatives and decision record are in `docs/previews/tasks-layouts.html` and `docs/previews/tasks-layouts-review.md`; application implementation begins only after the owner explicitly starts execution.
