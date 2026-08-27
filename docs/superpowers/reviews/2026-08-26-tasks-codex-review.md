# Tasks Module — Codex Review Record

**Date:** 2026-08-26  
**Reviewed artifacts:** `docs/superpowers/specs/2026-08-25-tasks-design.md` and `docs/superpowers/plans/2026-08-25-tasks-phase-1.md`  
**Status:** Codex review and focused re-review complete; Grok changes adjudicated into two-reviewer consensus; owner accepted the consensus and selected **A — Dense Grid** on 2026-08-26  
**Implementation:** Not started

## Review method

The first draft received independent read-only passes for security, product fit, schema/API/concurrency, and execution feasibility. The primary agent reconciled those findings, then challenged the result against the live repository rather than treating internal consistency as completion. The second pass inspected `StartUp.md`, STATUS, module/glance registration, `ViewScope`, Automation scope storage, notification delivery/read behavior, FileStore backup limits, Attachables, Flutter address/shell/notification navigation, and existing nullable-place migrations. A final focused independent pass then rechecked every correction that remained Important and found no Critical or Important issue.

These are Codex reviews. Grok's completed independent pass is recorded in `docs/superpowers/reviews/2026-08-26-tasks-grok-review.md`; the owner subsequently chose Codex and Grok as the final review group while Claude is occupied.

## First-pass findings already resolved

| Finding | Resolution now specified |
|---|---|
| Board ownership could diverge between a board field and memberships. | `task_board_members.role=owner` is the only source; last-eligible-owner removal is refused and recovery is content-blind/audited. |
| Private membership could become a second assignment system. | Membership eligibility requires existing permission/reach; task participation additionally requires private membership; neither grants reach. |
| Collaboration and watching were conflated. | Collaborators are durable participants; Watch/Mute is a separate self-owned preference with an owner-mandatory state. |
| Audit retention was too short for a user activity feed. | Immutable `task_activities` are independent of compliance `audit_events`. |
| Record-link snapshots could reveal revoked targets. | Every presentation re-resolves target policy; unavailable rows omit target identity, display, route, and snapshot. |
| Saved views, import sessions, rich filters, XLSX limits, export authority, and visual/reorder tests were underspecified. | The spec and plan now give explicit modes, schemas, conflict owners, bounded parsing, `tasks.export`, collision-free position writes, and named desktop/mobile gates. |

## Repository-alignment re-audit findings and resolutions

| Severity | Finding | Resolution applied to the packet |
|---|---|---|
| Blocker | Nullable compound FKs cannot prove an organization-level board and folder have the same place. | Folders/boards now use the existing Inventory/Services stored generated non-null `place_key` pattern plus ordinary ancestry FKs and direct MySQL attack tests. |
| Blocker | Notification intents had no exact source identity, status, lease, retry, or user-visible dedupe lifecycle. | Intents now define source occurrence/dedupe, lifecycle state, claim token/lease, attempts, and classified failure. The shared notifications table gains a generic nullable `delivery_key`, uniquely scoped by organization/user/source; delivery is crash-idempotent and queue payloads contain only intent ID. |
| Blocker | The proposed explicit Automation scope method could not represent existing organization rules stored as `null/null`. | `ViewScope::forStoredAutomationScope(User, ?type, ?id)` maps validated `null/null` to organization, accepts valid company/franchise pairs, and rejects half-null/fallback behavior. |
| Blocker | FileStore's local bytes are explicitly outside restore-tested backup, incompatible with a Monday replacement containing files/import staging. | Milestone 0 extends encrypted backup/restore support to `storage/app/private`; a Task 10A production proof gates every production-capable file path while non-file Tasks development may proceed. |
| High | Registering Tasks as a sidebar module before its glance violates the live `GlanceRegistryTest`. | Task 4A registers the permission/automation contract with `sidebar:false`; Task 6 atomically adds glance/order/client destination and flips `sidebar:true`. |
| High | Server deep-link metadata had no client route contract; `Address.idOf()` discards remaining segments and NotificationBell has no navigation callback. | Canonical `/tasks/boards/{board}/tasks/{task}` parsing/building, typed pending intent, HomeShell handoff, notification callback, reauthorized fetch, cache eviction, and widget tests are explicit. |
| High | `TaskBoardAccess::reachDecision` was too vague for notification rendering and creation could trust an organization fallback view. | The plan separates `createDecision`, `backgroundViewDecision`, `viewDecision`, and `manageDecision`, each including the appropriate release/entitlement/permission/reach/membership predicates. |
| High | Update files were required to already belong to an update that did not yet exist, while generic Task upload authority maps to `update` rather than `tasks.comment`. | A comment-authorized endpoint creates private, expiring Task-bound candidate markers; `TaskUpdateWriter` atomically claims/rebinds them through a domain-only operation, generic lists/File cells exclude them, and cleanup removes abandoned bytes. |
| High | Board/My Tasks polling would not observe remote updates/files/activity because update append does not own core task version. | Tasks now have `detail_version`; the detail endpoint ETag derives from core + detail versions and the client conditionally polls an open detail without overwriting a composer. |
| High | `tasks.comment` did not explicitly protect own-update edit/archive/file behavior. | Task 11 now tests and enforces comment-only create/file/own-edit/own-archive, refuses other-author mutation, and proves edit/manage/owner are not substitutes. |
| High | Revoked Tasks notifications could still leak subject IDs and unread-count changes through the current controller. | A Tasks presenter filters inaccessible rows out of data and unread counts, refuses direct mark-read, and limits mark-all-read to visible rows. |
| High | Due-date occurrence identity failed A → B → A. | `tasks.due_revision` increments only on normalized due-date change; reminder/overdue uniqueness and intent sources use the revision plus due snapshot. |
| High | Reorder concurrency had no conflict owner and could version untouched siblings. | `task_groups.order_version` owns task order; source/destination groups lock and increment once, and only the moved task's core version changes on cross-group move. |
| High | Subtask parent archive/restore semantics were absent. | Parent archive marks active children with `archived_with_parent_id`; restore returns only those auto-archived rows. Status never cascades. |
| High | Cross-place relocation text contradicted same-place duplication in the plan. | Structure-only duplication may target the exact current view after source/target authorization; task-inclusive duplication stays same-place; relocation uses reviewed export/import then source archive. |
| Medium | Structural column rename, per-view layout, Number suffix, favorites/last board, and participant pickers lacked storage/API ownership. | Added `task_core_column_settings`, `number_suffix`, `task_board_preferences`, shared/personal reorder ownership, and a capped eligibility search API. |
| Medium | Tenant-owned user references could accept cross-organization IDs below services. | The schema plan adds/reuses `(organization_id,id)` on users and compound tenant/user FKs for members, owners, People, mentions, collaborators, actors, creators, and updaters. |
| Medium | Rich-text behavior had an allowlist but no repository/client implementation choice. | The server reuses installed HTMLPurifier; Flutter renders the strict subset with `flutter_html:^3.0` and the plan names sanitization, plain-text projection, toolbar, link, and test behavior. |

## Final focused re-review

The final independent pass reported **no Critical or Important findings**. It specifically confirmed:

- required company, franchise, user, and folder candidate keys are named and tested before compound foreign keys;
- only top-level tasks store `group_id`; a subtask derives its effective group from its immutable same-board parent, so child grouping cannot diverge;
- archived-group write, restore, history/export, and active-task refusal behavior is explicit;
- update-attachment candidate claims and import commits share row locks with their cleanup jobs, recheck state while locked, and remove bytes only after database commit;
- `TaskNotificationIntentDispatcher` and applicable-mutation wording are consistent; and
- generic `notifications.delivery_key` is the sole notification-delivery idempotency identity, with no Tasks-specific column or redundant intent-side delivered-notification ID.

## Codex–Grok change adjudication

| Grok change | Consensus disposition |
|---|---|
| Clean automation action keys and prevent `task.create` from implementing `job.create`. | **Accepted and refined.** Tasks uses clean `task.*` declaration keys. `ActionDefinition` gains an explicit execution type and subject-applicability contract, preserving existing generic shared actions while giving Tasks unique stored types. `task.create` is cross-subject; the five Task-mutating actions require a Task trigger. |
| Do not add a Tasks-specific intent foreign key to the shared bell table. | **Accepted and refined.** Add reusable nullable `delivery_key` with unique `(organization_id,user_id,source,delivery_key)`. It uses the intent UUID without overloading notification subject routing and without adding a reverse link on the intent. |
| Do not block board schema on a production backup cycle. | **Accepted.** Task 0 lands backup/restore code and a disposable fixture; Tasks 2–10 may proceed without real Tasks file bytes. Task 10A requires the approved production restore/checksum proof before attachments, import staging, or usable File-column file paths. |

The full two-reviewer disposition is recorded in `docs/superpowers/reviews/2026-08-26-tasks-codex-grok-consensus.md`.

## Deliberate adaptations

- Files continue to use EzBizi Attachables/FileStore and a shared extracted uploader; Tasks adds only a private candidate marker and narrowly controlled Task-to-TaskUpdate claim, not a parallel storage system or public generic rebind.
- Automatic watch state is derived from canonical relationships plus one explicit `watched|muted` preference; no second source table is added.
- SQL `NULL` is the permanent None-priority state, avoiding a fake configurable “none” option.
- A generated place key is used only where nullable exact-place equality needs database proof; it does not create another scope system.

## Remaining review gate

The two-reviewer technical consensus has no remaining block. The owner now accepts or changes the consolidated package, then selects one of the three HTML layout alternatives before production Flutter work.

Implementation remains unauthorized by this document. It starts with backup/restore support and three HTML alternatives; non-file Tasks work may proceed after owner acceptance, production file paths wait for Task 10A, and production Flutter work waits for the selected layout. Phase 2 planning views, Phase 3 guests/advanced fields/direct Monday import, deployment, and merge remain separately gated.
