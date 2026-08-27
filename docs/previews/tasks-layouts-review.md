# Tasks layout review

**Date:** 2026-08-26  
**Artifact:** `docs/previews/tasks-layouts.html`  
**Status:** Owner selected **A — Dense Grid** on 2026-08-26; no application code has been started  
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

The selection closes the screen-design gate; it is not authorization to start Flutter or backend implementation. The Phase 1 plan remains the execution contract, and its Task 10A file-storage proof still gates production-capable file paths.

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
