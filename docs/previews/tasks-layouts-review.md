# Tasks layout review

**Date:** 2026-08-26
**Artifact:** `docs/previews/tasks-layouts.html`
**Status:** Owner selected **A — Dense Grid** on 2026-08-26; production goldens approved on 2026-08-28
**Selected direction:** **A — Dense Grid**
**Prior Codex recommendation:** C — Board + Detail

## What is shared by all three alternatives

The alternatives use EzBizi's existing white, ink, cyan, structural-line, and muted-surface tokens inside the current top bar, scope switcher, desktop sidebar, and mobile bottom navigation. They share the same product contract so the choice is about working rhythm rather than missing functionality:

- exact-view context: `A-1 Chimney › Connecticut`, never a raw `/me.companies` picker;
- Board and My Tasks views;
- To-Do and Completed groups, one-level subtasks, owners, statuses, due state, priorities, linked records, and estimates;
- private-board visibility, board settings, custom columns, and an archive action;
- task description, durable updates, mentions, attachment affordance, and linked EzBizi records;
- import upload, explicit column mapping, preview, and blocking refusals for an unknown owner and missing parent;
- empty, loading, access-refusal, and concurrent-edit conflict states;
- a desktop task-detail panel that is collapsible and resizable by pointer or keyboard;
- a touch-first task list and full-screen task detail in the 375px presentation.

## Alternatives

| Direction | Best at | Main cost |
|---|---|---|
| **A — Dense Grid** | Fast bulk scanning and the shortest transition for Monday-trained office users. It exposes the most columns and keeps spreadsheet rhythm. | With details open, later columns require horizontal movement. Its mobile conversion is usable but feels least native. |
| **B — Calm Operations** | Readability, onboarding, and quickly understanding workload health. The summary makes the exception obvious. | Fewer fields remain visible at once and the extra vertical space slows high-volume scanning. |
| **C — Board + Detail** | Working a task while preserving board position. Description, linked record, owner/status/due facts, and updates remain available without a modal. | It exposes fewer grid columns than A, so secondary fields move into detail or require an alternate board presentation. |

## Owner decision

The owner selected **A — Dense Grid**. Production UI planning therefore uses A as the approved base: the high-density desktop table, grouped rows, visible operational columns, collapsible/resizable detail, and the touch-first card conversion at 375px. The shared product contract above remains mandatory.

Before that decision, Codex recommended C because it keeps description and conversation persistently visible while preserving board position. That recommendation is retained for provenance, but it does not override the owner's A selection and does not authorize merging B or C elements into A without a later decision.

The selection closed the screen-design gate. Full Phase 1 implementation was later authorized and the Task 10A file-storage proof passed before production-capable file paths opened; the Phase 1 plan remains the execution record.

## Verification completed

The HTML was opened through a local web server and checked in a browser at a 1440px desktop viewport and a real 375px viewport, in addition to the built-in exact 375px frame.

- All three board alternatives rendered with five realistic task rows and no console warnings or errors.
- Desktop body and app frame had no page-wide horizontal overflow. A's dense table scrolls within its board pane by design.
- At 375px, all three board alternatives had no page-wide or app-wide horizontal overflow.
- Mobile settings navigation and import steps/table use contained horizontal scrolling where the source information cannot be legibly collapsed.
- My Tasks reduced the sample to Maya's two open tasks.
- Pointer and Enter-key task selection opened the correct detail; the mobile Close control returned to the board.
- The desktop separator resized the detail pane by 24px with an arrow key.
- Board settings exposed the board name, visibility, folder, and column list.
- Import showed four mappings, two refused rows, and a disabled Review action until the refusals are resolved.
- Empty, loading, refusal, and conflict states all rendered and announced the intended state.
- Focus-visible styling, reduced-motion behavior, 40–44px primary controls, truncation, status contrast, and mobile bottom navigation were inspected.

Three issues found during this pass were corrected in the artifact: task rows gained keyboard semantics, a bubbling size-control collision that immediately reclosed mobile detail was isolated, and duplicate/unwired mobile Back controls were replaced with one working control.
## Production golden approval

**Date:** 2026-08-28

**Command:** `cd client && flutter test test/tasks/tasks_golden_test.dart --update-goldens`

**Result:** 11 tests passed; every checked-in PNG was then inspected at its native size against the selected A — Dense Grid direction.

The approved set covers directory, populated board, and task detail at both 1440×900 and 375×812, plus desktop board settings, import reconciliation, concurrent-edit conflict, and empty directory, and the mobile access-refusal state. The desktop board preserves the dense grouped grid, internally scrollable operational columns, side directory, and bounded detail panel. The mobile board converts the same governed rows to touch-first cards and the detail becomes a full-screen route. Settings and import remain bounded right-side work surfaces, and conflict/refusal states keep the governing context without rendering stale content.

Visual inspection found no overflow stripes, clipped primary actions, unintended page-wide scrolling, stale overlays, or mismatched dimensions. Flutter's deterministic test font is intentionally visible in these baselines; layout, color, spacing, grouping, control presence, and responsive behavior—not host font rasterization—are the comparison contract.

**Approved:** all 11 production goldens in `client/test/goldens/tasks/` faithfully represent the accepted A — Dense Grid direction and are approved as the Task 19 responsive baselines.

## Task 15 golden re-approval (Phase 2 security and workflow gate)

**Date:** 2026-08-31

**Command:** `cd client && flutter test test/tasks/tasks_golden_test.dart` (no `--update-goldens` — Phase 2's changes to the backend query surface, dependencies, and recurrence touch no Tasks widget, so no golden was expected to move, and none did).

**Result:** 11/11 passed against the checked-in PNGs with no regeneration. Every PNG was then opened and inspected individually, at native size:

- `directory_desktop.png` / `directory_mobile.png` — top bar, scope switcher, Boards/My Tasks tabs, folder header, three board cards. No overflow; card layout intact at both widths.
- `board_desktop.png` — side directory, board header, filter/tab row, toolbar (search/sort/group/filter/columns), grouped dense grid (To-Do and Completed group bars), five task rows with owner/status/priority/due columns, empty detail-pane placeholder on the right. Matches the approved A — Dense Grid direction; no column collision.
- `board_mobile.png` — filter chips wrap correctly, group header, touch-first cards stacked, toolbar row scrolls horizontally by design (matches the original review's noted contained-scroll behavior for mobile toolbars).
- `detail_desktop.png` — board stays visible with the selected row outlined (edit-cursor indicator present), detail panel on the right: title, linked record, owner/status/due facts, updates, activity, comment box. No clipping.
- `detail_mobile.png` — full-screen detail route with a close control; same field set as desktop, stacked. No overflow.
- `settings_desktop.png` — right-side drawer over a dimmed backdrop: tabs, name/description, folder, groups/statuses/priorities, an archive toggle, Save button, danger zone. Bounded to the drawer width.
- `import_desktop.png` — right-side drawer: chosen-file chip, five field mappings (Task/Owner/Status/Group/Due date), disabled Review action, reconciliation list showing two flagged rows in red. Matches the original "two refused rows" fixture.
- `conflict_desktop.png` — the concurrent-edit banner renders above the grid with its own action pair, and the edited row keeps the caller's in-flight text rather than being silently overwritten by the server's version.
- `empty_desktop.png` — centered empty-state illustration placeholder, heading, body copy, single CTA, and a secondary link; no board chrome underneath.
- `refusal_mobile.png` — access-refusal copy renders below the tabs; no board list, no stray requests reflected in the layout.

Read with the paired-ghosts-vs-solid-blocks technique (Flutter's deterministic test font renders every character as a filled glyph box, so a real word reads as a run of solid blocks): every block run inspected stayed a single solid run at a stable position — no run split into two offset blocks (which would mean a glyph box moved, i.e. a layout shift) and no run relocated between rows. That is consistent with a byte-identical PNG produced by an unrelated backend change, which is what this run's untouched Dart source predicts.

**Approved:** all 11 goldens in `client/test/goldens/tasks/` still faithfully represent the accepted A — Dense Grid direction. Task 15 changed no Flutter source, so this is a re-confirmation of the existing baseline, not a new one — recorded here per the brief's requirement to look at every PNG before signing off, not just trust a green test run.
