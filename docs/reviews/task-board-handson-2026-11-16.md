# Task Board hands-on QA, 2026-11-16

Run locally: `php artisan serve` on DB `ezbizi_testing_uiux_tbqa` (migrated and seeded with
one franchise board: 22 columns of every type, 10 tasks, 9 subitems, 1 update), and the
Flutter client on `flutter run -d web-server` driven by Playwright at 1440x900 and 390x844.
Branch `session/uiux-tbqa`.

Coverage: I drove the table, all five views (Table, Kanban, Calendar, Timeline, Gallery), the
task side panel and the phone layout in the browser. I did not click every menu, editor and
bulk action in the browser. Header menus (Hide and Delete) and owner faces are covered by
widget tests. The rest of the list below is still open for a second pass.

Severity: H = broken or blocks a job, M = confusing or ugly, L = polish.

| # | Sev | Where | Finding | Fix | State |
|---|-----|-------|---------|-----|-------|
| 1 | H | Table headings | Hide and Delete were only reachable by clicking the heading name. Nothing showed a menu was there (Jose: "i dont see any option"). | A ⋯ on every heading. Hide column, plus Delete column… for board columns, confirmed inside the menu. | Fixed b841fdf30 |
| 2 | H | Owner / Assigned / People | Names took 130 px per column. | Faces only, +N, a hover card, a person-plus when empty, 80 px wide. | Fixed 72166b238 |
| 3 | M | Headings at 80 px | The ⋯ and sort arrow squeezed names to "OWNE" and "ASS". | The ⋯ shows on hover only, laid over the name, so it takes no width. | Fixed (fix commit) |
| 4 | H | Kanban and phone cards | A card listed every filled column (20+ lines) and was taller than the screen. On phone, one card filled the whole viewport. | Show the first 4 filled columns, then "+N more". | Fixed (fix commit) |
| 5 | M | Timeline | Short bars showed a clipped "Nov" instead of their dates. | Bars under 96 px show their dates beside the bar. | Fixed (fix commit) |
| 6 | M | Toolbar | "Hide" and "Columns · N hidden" are two controls for the same job. | Hide is one control: the toolbar's Hide lists every column with a tick and a search, unticked = hidden for everyone, and counts them ("Hide · 3"). "Columns · N hidden" is gone. | Fixed (pass 2 commit) |
| 7 | M | Toolbar 1440 | The toolbar wraps to 3 rows (views, filters, Invite/Integrate/Automate), about 180 px before any task. | One line that never wraps (PriorityRow): view, search, New task, Person, Filter... while they fit; Invite pinned right; the rest and the board's tools in ⋯. Below 1200 px the views are a dropdown menu. | Fixed (pass 2 commit) |
| 8 | M | Phone toolbar | The toolbar takes 4 rows before the first task. | Phone line: view, search, + (New task) and ⋯. Person, Filter, Sort, Hide, Views, Group by and Invite are in ⋯. | Fixed (pass 2 commit) |
| 9 | M | Task panel | Phone shows raw "+14405550100" while the table shows "(440) 555-0100". | The panel shows "(440) 555-0100"; left as shown it saves nothing. | Fixed (pass 2 commit) |
| 10 | M | Task panel | "Approved" is a Switch in the panel and a Checkbox in the table and card. | Checkbox in the panel too. | Fixed (pass 2 commit) |
| 11 | L | Invite chip | "Invite · 0" on a franchise board reads as broken. | "Invite" when nobody is listed, "Invite · N" otherwise. | Fixed (pass 2 commit) |
| 12 | L | Table footer | The Due footer range is cut off ("11/16/2026…") at 104 px. | Footers drop the year: "11/16–21", "11/30–12/2"; the full range on hover. | Fixed (pass 2 commit) |
| 13 | L | Avatars | Every face without a photo logs a 404 for /users/{id}/avatar in the console. | The board's assignable list carries has_avatar; a face without a photo is never asked for (no 404s in the console at 1440). | Fixed (pass 2 commit) |
| 14 | L | Empty board | A board with no tasks draws no column headings, so there is nowhere to add a column until a task exists. | A board with no tasks draws every group's headings and "No tasks yet. Add the first one below." (phone cards too). | Fixed (pass 2 commit) |
| 15 | L | Kanban / Calendar / Gallery | "New task" and "Group by" disappear outside Table. | New task on every view; outside Table it makes "New task" and opens it to be named. Group by stays Table-only. | Fixed (pass 2 commit) |

## Second pass, 2026-11-16 afternoon

Same local setup (`php artisan serve` from this worktree on `ezbizi_testing_uiux_tbqa`, env vars
only), the client built to scratch and driven by Playwright at 1220 px (the board's width inside
the app at 1440: 220 px sidebar, 264 px boards tree) and 390 px. Clicked this time: the row ⋯
menu, the task name's right-click menu, a cell's right-click menu, the status menu, every cell
editor type (person, people, label, tags, timeline, location, files, checkbox, rating, text),
row picks and the bulk bar (Status), row drag within a group and into another group, Kanban
card drag, the toolbar ⋯, Hide, the view menu, archiving and unarchiving a board, the archived
list, an empty board in Table and on a phone, and the phone ⋯.

The first pass's backend was the main tree's code on 8791 (a stale `php -S` from H:\Development\EzBizi,
stopped); this pass ran this worktree's.

| # | Sev | Where | Finding | Fix | State |
|---|-----|-------|---------|-----|-------|
| 16 | H | Bulk bar · Status | Every task came back "2 tasks were not changed": the client sent the board status id as a status key. | Bulk Status sends status_id (as one task's change does); the server takes status_id on tasks/bulk. | Fixed (pass 2 commit) |
| 17 | M | Status, owner, label menus | Esc did nothing: a menu opened from a cell (lazy menu) never had focus. A menu whose row was rebuilt while open could leave the board dimmed. | Esc closes any open lazy menu; a lazy menu disposed while open reports its close. | Fixed (pass 2 commit) |
| 18 | M | Kanban | Cards could not be dragged between columns (Monday's main move). | Drag a card onto a column to give it that status; long-press on a phone. | Fixed (pass 2 commit) |
| 19 | M | Person / People cells | The hover card opened on top of the picker the same click opened. | A hover card steps aside while any cell menu is open. | Fixed (pass 2 commit) |
| 20 | M | Group header | The group ⋯ sat at the far right of the table, off screen on a wide board. | Beside the group's name and count. | Fixed (pass 2 commit) |
| 21 | M | Archived boards | An archived board looked like any other; "Back to boards" was only in a menu. | "Archived · Back" button on the toolbar line. | Fixed (pass 2 commit) |
| 22 | M | View switch at 1440 | After item 7 the view picker opened a side panel for five choices. | A dropdown menu on desktop; the phone keeps its picker. | Fixed (pass 2 commit) |
| 23 | L | Kanban / phone cards | A number sat 100 px right of its label ("Est. hours: ........0.5"). | Numbers shrink-wrap in a card, still right-aligned in a cell. | Fixed (pass 2 commit) |
| 24 | L | Timeline cell | "11/16/2026 –…" cut off in a 120 px cell. | Short range in the pill, full range on hover. | Fixed (pass 2 commit) |
| 25 | L | Label column menu | A label column's add box said "+ Add tag" over "Edit labels…". | "+ Add label" there, "+ Add tag" under Tags. | Fixed (pass 2 commit) |
| 26 | M | Table, sideways scroll | The Task column scrolls away with the rest, so a row's cells cannot be told apart once scrolled right (Monday pins the item column). | Pin the Task column. Needs a split table (fixed left pane + scrolling right pane); not started. | Fixed dcd58b074 |
| 27 | L | Headings at 80 px | Person columns' names still cut: "REVIE", "ASSIG". | Tooltip with the full name, or 96 px for named person columns. | Fixed 70c86a40c |
| 28 | L | Bulk bar | Picking the first row inserts the bar above the table and pushes every row 54 px down under the pointer. | Float the bar at the bottom, Monday's way. | Fixed e1348d957 |
