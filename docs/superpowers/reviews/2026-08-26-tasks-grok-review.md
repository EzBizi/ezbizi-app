# Tasks Module — Grok Review Record

**Date:** 2026-08-26
**Reviewed artifacts:** `docs/superpowers/specs/2026-08-25-tasks-design.md`, `docs/superpowers/plans/2026-08-25-tasks-phase-1.md`, `docs/superpowers/reviews/2026-08-26-tasks-codex-review.md`
**Status:** Independent Grok pass. Three **change** findings, no **block**. Implementation remains unauthorized.
**Checked against the live repo, not only the packet:** `User` (one organization per account), `notifications` schema, `ActionDefinition::type()`, Inventory/Services `catalogue_key` stored generated columns, `GlanceRegistryTest` sequencing implied by `sidebar:false` then `sidebar:true`.

This is not a substitute for Claude's review or for the remaining Codex re-audit checkbox. The Codex replacements named in that review **are present** in the spec and plan; the findings below are additional.

## Verdict on the twelve consensus questions

| # | Question | Verdict |
|---|---|---|
| 1 | Exact current-view filing vs sibling/company-to-organization leaks | **accept** |
| 2 | Scope-visible / private access, recoverable without admin bypass | **accept** |
| 3 | Structural vs typed custom fields as a permanent core | **accept** |
| 4 | Stable status category plus editable label/color | **accept** |
| 5 | Owner, collaborators, Watch/Mute, multi-People | **accept** |
| 6 | Record links governed after target access is revoked | **accept** |
| 7 | Concurrency, atomic bulk, ordering, audit, outbox, notification timing | **accept** |
| 8 | Phase 1 table/My Tasks and Phase 2/3 deferrals | **accept** |
| 9 | Spreadsheet transfer safety | **accept** |
| 10 | Independently green milestones, no server/client registry gap | **change** |
| 11 | Intent lease/dedupe/redaction vs lost/duplicate/leaked notifications | **change** |
| 12 | Restore-tested FileStore bytes and canonical deep-link/detail-refresh gates | **change** |

Consensus, per the plan, does not require matching aesthetics. It requires every **block** gone and every **change** either applied or explicitly declined by the owner.

---

### 1. Current-view filing — **accept**

Spec §§4–5 and plan Global Constraints / Task 5 / Task 8.

Creation has no place picker. Filing IDs come from `ViewScope::$type` / `$id`, not a walk of `/me.companies`. A company view cannot create or mutate an organization board. Structure-only duplication is allowed into the exact current view only when that view already covers the source; task-inclusive duplication stays same-place. The stored generated `place_key` plus compound ancestry FKs close the organization-level `NULL = NULL` hole that ordinary nullable compound FKs cannot.

The live Inventory/Services pattern is named `catalogue_key` and proves uniqueness inside a place, not folder/board equality. Calling the Tasks column `place_key` is the right name for this job. Do not pretend the Inventory column already does folder/board FKs — it does not; franchise-on-item there is still a model rule.

`users(organization_id, id)` as a candidate key is valid here. `User` belongs to exactly one organization (`app/Models/User.php`). Compound tenant/user FKs therefore reject a foreign-org participant below HTTP, which current single-column `user_id` FKs cannot.

### 2. Scope-visible / private access — **accept**

Spec §5, plan Task 5.

Permission and assignment reach are never granted by membership. Private membership only narrows. Board administration needs `tasks.manage` **and** an active owner membership **and** reach; neither is a bypass of the other. Last-eligible-owner removal is refused. Organization Administrator recovery is a dedicated, content-blind, audited transfer that neither adds the Administrator nor becomes a view path.

Keep the recovery response as specified: minimal confirmation, no board title, no task counts.

### 3. Structural vs custom fields — **accept**

Spec §7, plan Tasks 3A / 9.

The structural list is the automation, glance, My Tasks, and overdue surface. Typed projections plus child link tables for People/Label/File keep filters in SQL. Immutable type after the first value, File values bound only to the same task, and `number_suffix` on the definition are the right permanent core. Advanced column types stay out of Phase 1 schema.

### 4. Status category plus editable label — **accept**

Spec §8.

Category is the reporting/automation fact; label/color are words. A used option cannot change category in place. Completing records `completed_at` / `completed_by`; leaving it clears the current markers and keeps history. Cancelled is not completed. SQL `NULL` as the only None-priority state matches how EzBizi already treats optional ranks.

### 5. Owner, collaborators, Watch/Mute, People — **accept**

Spec §9, plan Task 11.

Zero-or-one accountable owner is the assignment/My Tasks/due-notice field. Collaborators are durable participants. Watch/Mute is a self-owned preference. Owners cannot mute while owner. Participant pickers are purpose-capped searches, never a raw `/me` loop. Custom People is not a second owner.

### 6. Record links — **accept**

Spec §10, plan Task 12.

A registry of explicit definitions, not a model scan. Every presentation re-resolves target policy. Unavailable returns `{link_id, available:false, type:<registry label>}` and omits target identity, display, route, and snapshot. Audit/outbox keep stable type/link IDs. Linking does not grant access to the target.

### 7. Concurrency, bulk, ordering, audit, outbox, notification timing — **accept**

Spec §14, plan Task 8 (bulk: mutate none if any target is forbidden or stale; one source occurrence per affected task under a shared causation ID) and Task 11 (intents in the business transaction, dispatch after commit).

Core `version` vs `detail_version`, group `order_version`, and board `structure_version` have distinct owners. Untouched siblings are not versioned by a reorder. Parent archive uses `archived_with_parent_id`; restore returns only those rows; status never cascades.

### 8. Phase 1 vs later — **accept**

Spec §§11, 20.

Phase 1 table + My Tasks, with saved-view rows carrying `presentation:"table"`, is enough to leave ordinary Monday boards. Kanban/Calendar/Gantt/dependencies/guests/formula are named extension points without speculative tables. One-level subtasks and eight column types are a complete release, not a toy.

### 9. Spreadsheet transfer — **accept**

Spec §18, plan Task 14.

Preview/mapping, all-or-nothing commit, no silent user creation, XLSX preflight (macros, formulas, zip bombs, dimensions), formula-prefix escaping on CSV export, explicit XLSX string type, one-use session bound to actor/board/view/hash/`structure_version`, and `FOR UPDATE SKIP LOCKED` expiry vs commit locking the same row.

### 10. Executable milestones / registry gap — **change**

The `sidebar:false` (Task 4A) then atomic glance + destination + `sidebar:true` (Task 6) split is the right answer to `GlanceRegistryTest`. Client destination and glance must still land in the same commit as the visible tab.

The defect is the automation action keys.

`ActionDefinition::type()` is `substr($key, strpos($key, '.') + 1)` (`app/Support/Modules/ActionDefinition.php`). `job.create` therefore types as `create`. Declaring `task.create` would type as `create` too, and shipping Task creation would mark the existing unimplemented `job.create` as implemented. The packet works around this with nested keys `task.task.create` so `type()` becomes `task.create`.

That workaround will be copied. It also makes the Tasks catalogue read as if there were a `task.task` subject.

**User-visible benefit:** Automations show `Create a task`, not a doubled prefix, and a later Jobs create-action cannot be silently marked done.
**Security/data:** Catalogue truth. No row-shape change.
**Schema/API:** None. Declaration keys become `task.create`, `task.assign_owner`, `task.set_status`, `task.set_due_date`, `task.move_group`, `task.add_update`.
**Schedule:** A small extra assertion inside Task 4A / 13B, not a new milestone.

**Replacement text** for plan Task 4A, replacing the sentence that starts “The deliberate nested prefix…”:

> Register action keys `task.create`, `task.assign_owner`, `task.set_status`, `task.set_due_date`, `task.move_group`, and `task.add_update`. Implemented-ness is keyed by the **full declaration key**, not by `ActionDefinition::type()`. `type()` remains the generic engine verb (`create`, `assign_owner`, …) so two modules may share a verb without one shipping the other. Task 4A adds a regression test: registering these Tasks actions does not mark `job.create` implemented. Task 13B extends `IMPLEMENTED_ACTIONS` / `action_types` by full key.

Same keys in spec §15.

### 11. Intent lifecycle vs leaked unread counts — **change**

Spec §16 and plan Task 11 are otherwise the right notification design: content-free intents, required `source_occurrence_key`, claim token/lease, attempt 10 → failed, crash-idempotent delivery, `TaskNotificationPresenter` filtering SQL **before** limit and unread count, refuse mark-read by ID, mark-all-read only visible IDs, queue payload = intent ID only.

The defect is a Tasks-specific column on the shared `notifications` table.

That table already has `source`, `subject_type`, and `subject_id`, and its own comment says `source` grows with every module (`database/migrations/2026_08_06_080000_create_notifications_table.php`). `task_intent_id` plus unique `task_intent_id` is a second identity, and the next module will add `leads_intent_id`.

`task_notification_intents.delivered_notification_id` already points at the visible row. Delivery does not need the reverse FK to be Tasks-named.

**User-visible benefit:** None directly. Prevents the in-app bell from growing a column per module, which is how unread-count filters eventually fork.
**Security/data:** Same crash-idempotent guarantee, same redaction path. Unique `(source, subject_id)` is the find-or-insert key.
**Schema/API:** No `notifications.task_intent_id`. `source = 'task'`, `subject_type` = intent, `subject_id` = intent id. Unique index on `(source, subject_id)`. Intent still stores `delivered_notification_id`.
**Schedule:** Same Task 3C / 11 work, fewer platform migrations.

**Replacement text** for spec §16, replacing “unique `task_intent_id`”:

> Delivery transactionally find-or-inserts one `notifications` row keyed by `source = 'task'` and `subject_id = <intent id>` under a unique `(source, subject_id)` index, writes that id onto the intent as `delivered_notification_id`, and marks the intent delivered. A crash retry finds the same row. The `notifications` table gains no Tasks-specific column.

**Replacement text** for plan Task 3C / Task 11: delete “Add a nullable unique `task_intent_id` to existing `notifications`.” Substitute the unique `(source, subject_id)` rule above. Presenter tests stay as written.

Keep the presenter. That is the leak-stop, not the column name.

### 12. FileStore restore and deep-link gates — **change**

The gates themselves are correct and I accept them as release gates:

- encrypted backup + restore-check of `storage/app/private` with a path/length/SHA-256 manifest, no filenames in logs;
- canonical `/tasks/boards/{board}/tasks/{task}` parsed into a typed intent (`Address` today discards remaining segments; `NotificationBell` has no navigation callback);
- detail ETag from core `version` + `detail_version`; polling must not overwrite a dirty composer.

The defect is sequencing. Spec §18 blocks **file-bearing** Tasks work until the restore proof. Plan Task 0 then says “Before Task 2 begins, run one production-sized restore check.” Task 2 is board schema. That couples weeks of non-file work to one production backup cycle and to whoever can run the ops path on the box.

**User-visible benefit:** Boards, tasks, views, and automations can be built while the nightly restore proof is recorded, without storing customer files yet.
**Security/data:** No File cell, no update attachment, no import staging bytes until the proof is in STATUS and on the work board. Same blast-radius statement as today: local disk is instance-only until that proof.
**Schema/API:** None.
**Schedule:** Task 0 still lands backup/restore-check **code** early and can deploy on the owner’s ordinary path. The production-sized proof is a hard gate for Task 11 (candidates/files) and Task 14 (import staging), not Task 2.

**Replacement text** for plan Task 0, replacing “Before Task 2 begins, run one production-sized restore check…”:

> Land the backup/restore-check code in Task 0 and run the disposable fixture locally before any Tasks file bytes exist. Run one production-sized restore check through the normal operations path and record only timestamp/result in STATUS and the work board **before Task 11 or Task 14 starts**. Tasks 2–10 may proceed without storing attachments or import files. If Task 0’s code is not yet on the box, Tasks 11 and 14 stay closed.

Deep-link/detail-refresh contracts in Task 6 / Task 16 remain as written.

---

## Packet vs Codex re-audit

The four Codex blockers have replacement text in the packet:

| Codex blocker | Present? |
|---|---|
| `place_key` + ancestry FKs + MySQL attack tests | Yes — spec §4, plan Task 2 |
| Intent source/lease/retry/dedupe + crash-idempotent delivery | Yes — spec §16, plan Task 3C / 11 (see change #11 for the column) |
| `ViewScope::forStoredAutomationScope` including stored `null/null` = organization | Yes — spec §15, plan Task 13B |
| Backup/restore of `storage/app/private` | Yes — spec §18, plan Task 0 (see change #12 for when the production proof gates work) |

High findings (glance sequencing, deep links, access decision split, candidate/claim uploads, `detail_version`, `tasks.comment` own-only, presenter redaction, `due_revision`, group `order_version`, parent archive, duplication vs relocation) are also in the spec/plan.

I am not ticking the Codex re-audit box. That was requested as an independent Codex pass.

## Not raised as changes

- `flutter_html:^3.0` plus server HTMLPurifier plus HTTPS-only `url_launcher` is enough defense in depth if the client wrapper still refuses a non-HTTPS URI after parse.
- Extracting `AttachmentUploader` without changing existing attachment behavior is the right reuse.
- JSON board archive that is **not** called a restorable backup until an importer exists is the right honesty.
- In-app notifications in Phase 1, email waiting on the mail-health gate, matches standing hazard 1.

## What this review does not authorize

No application code, no Flutter widgets, no deploy. Next: Claude’s independent answers, Codex’s re-audit checkbox if still required, reconcile the three changes above, owner approval, then the three HTML alternatives (`docs/previews/tasks-layouts.html`) before any production widget work.
