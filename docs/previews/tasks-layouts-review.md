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

**CORRECTION (2026-08-31, same day): the claim above is false, and the approval above it is void.** "Phase 2's changes ... touch no Tasks widget" is contradicted by `git log`: `7aa13ea`, `b66a020`, `7b247dd`, and `3d4503f` each added one of Phase 2's four new presentation widgets — `task_kanban_view.dart`, `task_calendar_view.dart`, `task_timeline_view.dart`, `task_workload_view.dart` — roughly 2,000 lines with no golden coverage before or after that pass. `grep -rl matchesGoldenFile client/test/tasks/` returned exactly one file, the pre-Phase-2 baseline, and this section approved it without checking whether it covered what Phase 2 actually shipped. It did not. The gate certified a baseline that did not contain the surfaces it exists to protect. See the section below for the corrected pass: eight new goldens for the four missing presentations, a real clip the UI owner found in the existing baseline (which this pass's "no overflow stripes, clipped primary actions" line also missed), and its fix.

## Task 15 golden re-approval, corrected (Phase 2 presentation coverage and a real clip)

**Date:** 2026-08-31

**Commands:** `cd client && flutter test test/tasks/tasks_golden_test.dart --update-goldens`, then the same command without the flag to confirm reproducibility; `flutter test test/tasks` (181 tests, full suite) and `flutter analyze lib/screens/tasks` both clean; `DB_DATABASE=ezbizi_testing_tasks phpunit tests/Feature/Tasks` (250/250, unaffected — this pass touched no PHP).

**What changed and why, before the results:**

1. **Eight new goldens** — `kanban_desktop.png`, `kanban_mobile.png`, `calendar_desktop.png`, `calendar_mobile.png`, `timeline_desktop.png`, `timeline_mobile.png`, `workload_desktop.png`, `workload_mobile.png` — added to `tasks_golden_test.dart` the same way every existing golden is produced: `_pumpTasks(..., openBoard: true)`, plus this file's own presentation-tab tap. Calendar/Timeline/Workload additionally navigate to a fixed month (August 2026, matching this file's other fixture dates) computed from whatever `DateTime.now()` the suite actually sees, because those three presentations default to the real current month and there is no clock-injection seam in `TasksScreen` — left alone, the header text (and which fixture tasks fall inside frame) would drift a little more every month the suite runs.

2. **The real clip, found by inspection, not by a golden:** in `board_desktop.png` and `conflict_desktop.png` (both 1440×900), the totals/status-chips/actions row inside `TasksScreen._boardControls()` clipped mid-element at the boundary between the content pane and the detail panel (~x=1041, pane ≈823px against a 1440px window). That row had no narrow-width branch at all — it always scrolled horizontally, chrome behaving like data. Confirmed by cropping the two goldens at 4× and reading the shapes directly (this format renders text as solid blocks, but a clipped rounded-rect chip and a stray arc fragment from the next element are visible either way) — row three of the chrome stack, not the presentation tabs above it, which fit.

   Fixed in `lib/screens/tasks_screen.dart`/`task_presentation_bar.dart`/`task_views_bar.dart`:
   - `_actionsRow` now always wraps (`Wrap`, no scroll fallback, no breakpoint to calibrate — `Wrap` already renders as one line whenever its content fits the space it is actually given).
   - `TaskPresentationBar`/`TaskViewsBar` take an optional `width` the caller measures, instead of reading `MediaQuery`'s window width — the original bug: a sibling detail panel can leave this column's real pane well under 700px on a screen nowhere near narrow, and `MediaQuery` never saw that.
   - Board-administration actions (Admin/Duplicate/Transfer — up to three wide `OutlinedButton`s) collapsed into one `PopupMenuButton` overflow menu, reducing this row's typical wrapped footprint.
   - `_workspace()`'s existing `LayoutBuilder` computes the real content-pane width once and threads it down — deliberately not a *new* `LayoutBuilder`; one was tried first and a widget test relying on a fixed, short `pump()` count to land inside a debounce/poll race window started failing, because one more build boundary silently changes how much of that fixed pump budget goes to layout instead of elapsed time.
   - `_workspace()`'s `showDetail` threshold moved from 800px to 1100px (matching `showDirectory`'s existing bar): reserving the detail panel's default 390px below ~1100px content-pane width left under 700px for the chrome above it — the exact width `TaskPresentationBar`/`TaskViewsBar` treat as narrow. At 394px (an 800px window with the detail panel open on nothing selected, `_detailOpen`'s default), the resulting wrapped chrome didn't just clip anymore — it silently dropped an in-progress table edit out of two existing widget tests, the same failure mode the ORIGINAL clip fix's own doc comments warned this exact change could reintroduce ("squeezed the bars to ~394px... starved TaskTable... silently dropped rows"). That regression is closed by this threshold change, not by the `ConstrainedBox` floor alone — a floor can guarantee `TaskTable` a minimum, but nothing can promise it a floor the sibling chrome above it has already spent. `TaskTable` also keeps a 280px `ConstrainedBox` floor as a second-layer guard for whatever pane width remains after this.
   - **Residual, narrower case, disclosed rather than silently accepted:** a detail panel manually resized toward its 640px maximum (`TaskDetailDivider`, clamped 300–640) can still push a wide-but-not-huge window's content pane back under 700px even above the new 1100px threshold. Not exercised by any golden or existing test; flagged here for whoever next touches this area.
   - `test/tasks/task_chrome_bars_test.dart` updated to expect zero horizontal scrollers in the chrome container at 375px (was one, exempting the actions row as "data" — the exact assumption this pass found wrong).

**Result:** 19/19 passed, both with `--update-goldens` and, immediately after, without it (confirming reproducibility, not just a first-run artifact). Every PNG was then opened and inspected individually, at native size:

- The original 11 (`directory_desktop/mobile`, `board_mobile`, `detail_desktop/mobile`, `settings_desktop`, `import_desktop`, `empty_desktop`, `refusal_mobile`) — unchanged pixel-for-pixel from the prior (validly-approved-for-these-11) baseline; re-inspected and still correct.
- `board_desktop.png` / `conflict_desktop.png` — the actions row now wraps onto a second line (task count, Filters, the new overflow-menu icon fit on line one; the icon alone was pushed to line two in `board_desktop`) instead of clipping. Nothing is cut off at the pane boundary in either file.
- `kanban_desktop.png` — presentation bar (five tabs + "Group by" picker) fits on one line within the ~823px pane; actions row below it fits on one line too (no chips — status summary chips are chrome-only below the 700px breakpoint, board width here is above it so they show). Three status columns with color dot + label + count headers, one to two task cards each. No clipping, no overflow.
- `kanban_mobile.png` — chrome wraps across several lines (all three bars, all correctly at 375px), a compact bucket-selector chip row, and a card visible at the bottom edge of the frame. Renders — the scenario that overflowed before the `PopupMenuButton` consolidation and status-chip hiding now fits.
- `calendar_desktop.png` — full August 2026 month grid, weekday header row, task chips positioned on their due dates (22nd, 23rd, 26th, 30th visible). No overflow.
- `calendar_mobile.png` — chrome wrapped, calendar header/nav, weekday abbreviations, a highlighted day visible at the frame's bottom edge (mobile's own week-list reflow, unaffected by this pass).
- `timeline_desktop.png` — August 2026 day scale (1–31), five task bars at their correct offsets/widths, row labels on the left. No overflow.
- `timeline_mobile.png` — chrome wrapped, timeline header/nav, day scale, task rows beginning at the frame's bottom edge.
- `workload_desktop.png` — three owner rows (Maya, Luis, Unassigned) by day-of-August grid, non-zero counts on the fixtured dates. This one needed a second pass: the first render showed the widget's own "No dated tasks fall in this window." empty-state text — the mock's `meta` for the workload branch was missing the `group_counts`/`status_summaries` keys `TaskRowsPage.fromJson` requires unconditionally regardless of presentation, so the response threw during parsing and the actual cause was an error banner, caught, on a re-check before this line was written; fixed by adding both as empty arrays.
- `workload_mobile.png` — chrome wrapped, owner rows and day columns visible at the frame's bottom edge, real counts (not the empty state).

Read with the paired-ghosts-vs-solid-blocks technique throughout (a real word renders as a run of solid blocks in this deterministic test font; a split run means a glyph box moved, i.e. a real layout shift — color-only differences keep one solid run in the same position): no split or relocated runs found in any of the 19, and the two fixed files' before/after crops (`board_desktop.png` specifically) showed the clipped chip's run become whole and move to its own line, exactly the fix's intended effect, not an accidental one.

**Break-one-on-purpose:** `_actionsRow`'s `Wrap` was reverted to the original unconditional `SingleChildScrollView`/`Row` (the exact clip bug) and `board_desktop`/`conflict_desktop` were re-run without `--update-goldens` — both failed (`LocalFileComparator` pixel mismatch against the checked-in, fixed baseline), confirming the goldens actually encode the fix rather than merely existing. The revert was then undone and both re-confirmed passing.

**Approved:** all 19 goldens in `client/test/goldens/tasks/` — the original 11 plus eight new — faithfully represent the accepted A — Dense Grid direction, extended correctly to Kanban/Calendar/Timeline/Workload, with the `board_desktop.png`/`conflict_desktop.png` clip fixed and proven fixed by a failing counter-test. The prior "Task 15 golden re-approval" section above this correction is superseded and should not be relied on.
