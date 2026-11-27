# Monday.com survey vs. EzBizi Task Board, 2026-11-03

Read-only through the monday API (GraphQL queries only). Workspace "Main workspace" (9607979). "30 days" = created/updated on or after 2026-10-04. Task Board code read from wt/task-board (TaskBoards, TaskWriter, TaskLinks, Task* controllers, TaskColumn::TYPES, task_board_screen.dart) and docs/reviews/2026-11-02/task-board.md.

## 1. What A-1 uses Monday for

| Board | Items | Open | New 30d | Updated 30d | With subitems | With updates (total) | Files | Views |
|---|---|---|---|---|---|---|---|---|
| NW - Nationwide 10084134034 | 24,520 | **18** (6 Claire, 12 Adeline) + 6 Company Info reference rows | >500 (500 newest reach back only to 10-06) | >500 | 2 of newest 500 | 491 of newest 500 | 0 | Table, Complaints View (table + SPP) |
| Outgoing Calls 18435173396 | 299 | 15 | 299 (board new) | 299 | 0 | 290 (391) | 0 | Complaints View, 2 Tables |
| Ohio Project Management 18432743116 | 137 | 62 non-Completed (incl. 6 Suppliers rows, 4 external, 6 waiting payment) | 134 | 137 | 0 | 128 (145) | 0 | Table, 2 Kanban, Calendar, Dashboard, Gantt |
| Toula Elwell 18393449992 | 167 | 7 + 3 recurring | 71 | 78 | 4 (23 subitems) | 52 (122) | 0 | Table |
| Jose Demasi 8343765247 | 92 | 16 (+3 Guidelines, 3 Company Files) | 6 | 27 | 12 (126) | 65 (145) | 0 | Table |
| Rodrigo Mena 9204581187 | 62 | 6 + 4 recurring | 2 | 2 | 9 (75) | 51 (110) | 0 | Table |
| Analorena - Business Relations 8343738207 | 45 | 6 + 6 recurring + 4 Dispatch Tracking (+3 Company Files) | 1 | 9 | 10 (70) | 23 (42) | 0 | Table |
| Adeline - Customer Relations 18379051971 | 2 | 2 | 0 | 0 | 0 | 2 | 0 | none |

Files = item assets; none found on any board (file columns/attachments unused). No Form views on any board. The only dashboard-type view is Ohio's.

**NW pattern.** It is the call/complaint log for all branches. Newest 500 items were created by ~10 VAs (Analorena Ventoso 84, Hyanne Kylla Faelnar 51, Annalyn Mombay 39, Sheryl Del Rosario 32, Marjulie Quino 32, "Deleted member" 29, ...). Person column = creator (automation), plus Adeline on her group. Of those 500: Done 409, NO ANSWER 42, Working on it 20, blank 15. Almost every item (491/500) has an update: the call note lives in updates, not columns. Daily volume: 60-90/day 10-07..10-12, then **8-15/day from 10-13** — the day Outgoing Calls was copied off and when EzBizi's CX tasks started (Task Board review: 347 CX tasks since 10-14). NW is being replaced, not growing.

**Ohio** is a real job pipeline (groups = stages, by region), dates heavily used (Date Received ~all, Schedule Date ~half), Timeline on ~14 of 137, Gantt/Calendar/Kanban views exist. No dependency column. One automation: Schedule Date arrives → Scheduled to In Progress.

**Personal boards** are to-do lists: Open/Recurring/Completed groups, subitems as checklists, updates as notes. Dates almost never filled (Jose: 2 of 92). "Recurring Tasks" is just a group; no recurrence automation measured.

**Templates** (New Location / Brand / Franchise, Everdoors, MA Franchise) are the only users of Dependent On + Timeline; not measured as live work.

## 2. What Monday does that Task Board doesn't (ranked by real use)

| # | Gap | Evidence |
|---|---|---|
| 1 | **Date-driven automation (when date arrives → move/status)** | Ohio's only automation; ~60 open Ohio items carry Schedule Date. Task Board has an overdue reminder but no "date arrives → move group/status". |
| 2 | **Calendar / Gantt / Timeline views** | Ohio has Calendar + Gantt + Kanban; Timeline filled on ~10% of Ohio items. Task Board: table/kanban/cards only, no timeline column type (TaskColumn::TYPES = text, number, status, date, person, dropdown, checkbox). |
| 3 | **Formula / currency columns** | Ohio Job Amount, Job Cost, Total Cost formula. No number formatting/formula on ours. |
| 4 | **Rule: column value → assign person** (Area=state → person; create in group → person) | Active on NW and Outgoing Calls, fires on every call row. Ours has automation hooks but not a per-board "when column = X assign Y" rule. |
| 5 | **Board dashboard (charts over a board)** | 1 (Ohio). Low use. |
| 6 | **Link / mirror columns between boards** | Adeline board only (2 items). Negligible. |
| 7 | **Dependencies** | Templates only, 0 live use. |
| 8 | **Recurring tasks** | Groups named Recurring on 4 boards (16 open rows) but no engine on either side; ours also only has a group name. |

Not gaps: files (0 use in Monday; ours has attachments), forms (none), subitems/updates/groups/statuses/people (ours has them).

## 3. Import mapping

General:
- Monday board → custom Task Board (members = people on the board's subscribers). Personal boards → that person's own board.
- Group → group (keep colour/order). Status column → board statuses (each label one status; "Done"/"Completed" → done type). Other status columns (Reason, Resolution, Area, Job Status, Parts Status, Job Type, Priority, Task Date, Reminder, Category) → `status` or `dropdown` custom column with the same labels.
- Text/long text → text; numbers → number; date → date; timeline → two date columns (start/end) until a timeline type exists; formula → drop (recompute later) or freeze value into number; link/mirror → text snapshot; dependency → skip (templates only).
- People → owner (first) + assignees; match Monday user email to EzBizi user email; unmatched (incl. "Deleted member") → leave unassigned and put the name in a note.
- Subitems → subtasks (name + status + people; subitem Timeline/Category/Late Tally → text in the subtask note).
- Updates → task updates in chronological order, author matched by email, original timestamp kept in body if created_at can't be set; replies flattened.
- Files: none to move.

| Monday board | Target | Import which items | Notes / traps |
|---|---|---|---|
| NW - Nationwide | A-1 Chimney company board, Customer Experience group (or a "Nationwide calls" custom board) | **Open only: 18** (Claire 6, Adeline 12). Company Info 6 → a Guidelines group or skip. Done 24.5k: don't import; export CSV archive. | Overlaps EzBizi CX tasks made since 10-14 (same calls) — dedupe by phone/customer before import. Reason labels (~35) vs ours (Complaint / Bad Review): map into Description status, add missing labels. Area = state → branch board where one exists. |
| Outgoing Calls | same CX group or own board | Open 15 | Same structure as NW; 284 Done skip. |
| Ohio Project Management | Ohio branch board | 62 non-Completed (decide whether Suppliers/Contractors 6 are tasks or contacts) | Stage groups map 1:1. Jobs exist in EzBizi/Workiz: link task → job by job number if present instead of duplicating amounts. Needs date-arrives automation (gap 1) or the Scheduled→In Progress move stops. |
| Jose / Toula / Rodrigo / Analorena / Adeline | their personal boards | Open + Recurring groups (16, 10, 10, 16, 2) | Guidelines / Company Files groups are reference docs, not tasks — move as pinned tasks or skip. Analorena "Dispatch Tracking" 4 → group. |
| Templates | skip (or board templates later) | 0 | Only users of dependencies. |
| Users, Job Roles | skip | — | Directory; EzBizi HR already holds people/roles. |

"Open" = not in a Done/Completed group and status ≠ Done. Traps: NW size (never page all 24.5k through the MCP; >100 kB per 500 rows), deleted members, the creator-assign automation means Person often = creator not worker, and NW/Outgoing duplicates of calls EzBizi already turned into CX tasks.
