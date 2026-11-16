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
| 6 | M | Toolbar | "Hide" and "Columns · N hidden" are two controls for the same job. | Merge them into one "Hide · N" control with the column list and Show again. | Open |
| 7 | M | Toolbar 1440 | The toolbar wraps to 3 rows (views, filters, Invite/Integrate/Automate), about 180 px before any task. | Move Invite, Integrate and Automate into the top row's right side, or into ⋯. | Open |
| 8 | M | Phone toolbar | The toolbar takes 4 rows before the first task. | Put Person, Filter, Sort, Hide and Views behind the tune icon. | Open |
| 9 | M | Task panel | Phone shows raw "+14405550100" while the table shows "(440) 555-0100". | Format phone in the panel field too (formatCellPhone). | Open |
| 10 | M | Task panel | "Approved" is a Switch in the panel and a Checkbox in the table and card. | Use the same Checkbox. | Open |
| 11 | L | Invite chip | "Invite · 0" on a franchise board reads as broken. | Hide the count when it is 0. | Open |
| 12 | L | Table footer | The Due footer range is cut off ("11/16/2026…") at 104 px. | Use the short date format in footers. | Open |
| 13 | L | Avatars | Every face without a photo logs a 404 for /users/{id}/avatar in the console. | Pass has_avatar from the board's people list. | Open |
| 14 | L | Empty board | A board with no tasks draws no column headings, so there is nowhere to add a column until a task exists. | Draw headings for empty groups. | Open |
| 15 | L | Kanban / Calendar / Gallery | "New task" and "Group by" disappear outside Table. | Keep New task on every view. | Open |
