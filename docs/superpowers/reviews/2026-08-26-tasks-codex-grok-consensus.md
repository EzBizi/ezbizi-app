# Tasks Module — Codex–Grok Consensus Record

**Date:** 2026-08-26  
**Participants:** Codex and Grok; the owner removed Claude from this review gate while Claude is occupied  
**Status:** Owner accepted the technical consensus and selected **A — Dense Grid** on 2026-08-26; ready for explicit implementation authorization  
**Implementation:** Not started

## Inputs

- Design: `docs/superpowers/specs/2026-08-25-tasks-design.md`
- Phase 1 plan: `docs/superpowers/plans/2026-08-25-tasks-phase-1.md`
- Codex review: `docs/superpowers/reviews/2026-08-26-tasks-codex-review.md`
- Grok review: `docs/superpowers/reviews/2026-08-26-tasks-grok-review.md`
- Interactive layouts: `docs/previews/tasks-layouts.html`
- Layout review: `docs/previews/tasks-layouts-review.md`

Grok returned nine **accept**, three **change**, and zero **block** verdicts against the twelve consensus questions. Codex rechecked the three changes against the live repository before changing the packet. The Grok review remains the immutable statement of the original findings; this file records their final disposition.

## Questions 1–9

Accepted without further change: exact current-view filing; scope-visible/private recovery; structural versus typed fields; stable status category; owner/collaborator/Watch/Mute/People semantics; governed record links; concurrency/bulk/order/audit/outbox timing; Phase 1 boundaries; and spreadsheet transfer safety.

## Reconciled changes

### 10. Automation action identity — accepted and refined

The concern is valid: suffix-only implementedness would let `task.create` make the unrelated existing `job.create` declaration appear implemented. The literal proposed replacement did not preserve EzBizi's existing distinction between declaration key and stored execution type, and the Flutter client currently derives the latter from the former.

The consensus contract is:

- clean declaration keys `task.create`, `task.assign_owner`, `task.set_status`, `task.set_due_date`, `task.move_group`, and `task.add_update`;
- `ActionDefinition` publishes an optional explicit execution type and `subject_bound` value, while existing declarations retain suffix-derived defaults;
- Tasks uses each full `task.*` key as its unique stored execution type, so `job.create` remains unimplemented;
- `task.create` is cross-subject and creates follow-up work on an authorized named board/group; the five mutations require the Task that triggered the rule; and
- module-only execution types appear once in the picker, not again under “Works with anything.”

This preserves intentional generic sharing such as `customer.update_field` and `job.update_field` → `update_field` without using doubled module prefixes.

### 11. Shared notification identity — accepted and refined

The concern is valid: a shared bell table must not gain one foreign-key column per module. Reusing `subject_type/id` for the intent would instead overload the table's existing navigation meaning, while restoring an intent-side delivered-notification ID would recreate two link fields that can diverge.

The consensus contract adds one reusable nullable UUID `notifications.delivery_key` and unique `(organization_id,user_id,source,delivery_key)`. Tasks uses `source='task'` and its intent UUID as the opaque key. Notification subject fields continue to identify the actual Task. The intent stores no delivered-notification ID. The authorization-aware Tasks presenter remains mandatory before data, unread counts, or subject metadata are exposed.

### 12. FileStore gate sequencing — accepted

Task 0 lands the private-file backup/restore-check implementation and passes a disposable local fixture. Non-file board, access, task, view, and automation work may continue without a production backup cycle. A new Task 10A then requires an approved production-sized, off-instance restore/checksum proof before any production-capable path may store Tasks attachment bytes, import staging bytes, or usable File-column files. Failure parks those paths without blocking non-file development.

## Remaining owner gate

This consensus closes the peer-review phase but does not authorize implementation or deployment. The owner accepts or changes the consolidated design/plan and selects one of the three HTML alternatives before production Flutter work begins.
