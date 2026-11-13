# Monday → EzBizi Task Board: parity audit, 2026-11-13

Jose, 11-13: *"start the full migration from monday to ezbizi … I want it as we have it on
Monday … keep the sync parity for a week or two … make sure we aren't missing any features
from Monday specially automations"*. Target: a 1:1 mirror of every Monday board in the Task
Board (board, groups, columns, label sets and colours, views, done items), two-way synced while
both are in use.

Read-only on Monday. Sources, all measured 2026-11-13 as Jose (owner on every task board):
- **Structure**: Monday GraphQL through the prod Monday connection (`boards(state:all)` → columns
  with `settings_str`, groups with colour/position, views; `folders` with colours). 171 board
  records: 47 live/archived boards, 42 live subitem boards, 76 deleted.
- **Automations**: Chrome, the board's own Automate panel API (`/automations-ms/automations`,
  `/automations-ms/recipes/custom`, `/lite-builder/workflows`, `/two-way-sync/workflows`) for all
  47 boards. Monday's public API has no automation read, so this is the only source. Raw dump kept
  out of the repo (person ids, message texts); rendered recipes are below, per board.
- **Values**: a probe of each value type on live items (formula, mirror, dependency, file,
  checkbox, numbers, link, date): formula/mirror/connect/dependency answer only `display_value`.

## 1. Summary

| | Monday | EzBizi today | After this branch |
|---|---|---|---|
| Boards | 45 live + 2 archived (Toula, All Calls) | 10 carry `monday:` (5 personal + 5 from 11-03) | importer maps every board (§5) |
| Items | 85,073 (All Calls 52,684, NW 24,659) | 622 imported | dry run counts per board (§6) |
| Column types used | status, date, people, text, timeline, numbers, link, checkbox, dropdown, file, formula, mirror, board_relation, dependency, long_text, phone, email, creation_log, last_updated, item_id | 17 types; importer wrote only text/number/date/timeline | every type mapped (§2) |
| Label sets with colours | 175 status columns | none carried | carried, colours and done (§2) |
| Views | 31 Table, 3 Kanban (+ 8 app Kanbans), Calendar (1 + 9 app), Gantt 1, Dashboard 1, Timeline 1, 2 "SppBoardView" (Complaints View, Shared Items) | Table, Kanban, Calendar, Timeline, Gallery switch; saved views (filters/sort/hidden) | §3 gaps |
| Automations | 145 recipes (96 active, 12 off, 27 off with archived All Calls/Toula, 2 off by permissions, 4 error, 4 webhooks not connected) + 1 workflow | triggers: task added / status changed / overdue | + column changed, date arrived; + push date, set column, add assignee (§4) |
| Folders | 23 folders, colours (§3.4) | none | GAP (UI-UX building), importer records path + colour |
| Integrations | none live (4 webhook recipes "not connected"; 1 errored) | — | nothing to carry |
| Item reminders / recurring items | done by automations only ("Every week notify", "Date arrives → push date") | — | §4 recipes F, E |

## 2. Columns: Monday type → Task Board (importer, `9ad1a1cf9`)

| Monday | Task Board | Status |
|---|---|---|
| Name | title | exists |
| Main status (`Status`, else the `status` column; Ohio PM "Job Status") | board status, Monday's labels, colours, done (`done_colors`) | exists, colours added |
| other status | `status` column, options = Monday labels (id `monday-<index>`), colours, Monday's order | exists (was text) |
| dropdown / tags | `tags`, options = Monday labels | exists (was text) |
| people: the Person/Owner column | assignees (all of them) | exists |
| people: other columns | `people` (every person this board can name; on a custom board they join it) | exists (UI-UX `people`, badc09303) |
| date (Due Date) | due date | exists |
| date (others) | `date`; "with time" (Toula's Due Date only) → due date | exists |
| timeline | `timeline` (the one called Timeline also sets the due date when there is none, as on 11-12) | exists |
| numbers ($ unit) / numbers / rating | `currency` / `number` (+ unit) / `number` | exists; **rating: GAP, UI-UX building** |
| checkbox | `checkbox` | exists (was NULL) |
| link | `url` (Monday's link text dropped) | exists |
| email / phone | `email` / `phone` | exists |
| long_text | `long_text` | exists |
| text | `text` | exists |
| formula | text snapshot of Monday's `display_value`; formula kept in `settings.monday_formula` | **GAP: formula engine/syntax (UI-UX)**. Ohio PM `IF({Production job}, VALUE({Job Amount})*0.1 + {Job Cost}, {Job Cost})`; All Calls `CONCATENATE(FLOOR(dur/60)…)` |
| mirror (Adeline "Subitems Link") | text snapshot | **GAP: mirror** |
| board_relation (All Calls ×10, Users, Bar's board ×3 via automations) | text snapshot | **GAP: connect boards** |
| dependency (Everdoors Brand/MA, 3 templates) | text snapshot of the names | **GAP: dependency** (+ its automation, §4 J) |
| file (Bar "Item Locations", All Calls, Ohio PM subitems) | files on one update "Files from Monday" | **GAP: files column (UI-UX building)** |
| creation_log / last_updated / item_id | the task's own created / updated (no column) | exists (UI-UX adding the columns) |
| subtasks | subtasks; subitem-only columns become board columns | exists |
| Item description | — | **GAP (UI-UX)**: none of the probed items had one |
| Update replies | flattened "↳" updates | **GAP: threaded replies (UI-UX)** |
| Group footers (sum/avg) | number/formula footers exist | partial: footers per column type (UI-UX) |

## 3. Views, groups, folders, sidebar

1. **Views** (§ per board). Monday's API returns view names and kinds only, never their filters
   (`settings_str` is `{}` for all 54). Table views: the board itself; extra named tables
   ("Complaints View" on NW and Outgoing Calls, 4 "Table" on DMV) → saved views with the same
   name. Kanban (Merav, Ohio PM ×2, Illinois PM ×2, Shoval, Chaim, Bar) and Calendar (Ohio PM,
   Illinois PM, Everdoors ×3, templates ×3, Triplex) exist as Task Board view modes. **GAPs:**
   a saved view cannot carry its layout (kanban/calendar), so "Kanban" is a mode not a named view;
   **Gantt** (Ohio PM), **Dashboard** (Ohio PM, widgets unreadable by API), **Timeline-as-view**
   (Shoval) exists as a mode; "SppBoardView" (NW "Complaints View", Bar "Shared Items") are
   Monday app views — mirrored as saved views by name.
2. **Groups**: order and colour from Monday (`position`, `color` hex). Group colours carried; the
   starter groups a new board gets (Recurring Tasks, Company Files, Guidelines…) are removed when
   empty and Monday lacks them. Archived groups are unreadable (API returns live groups only).
3. **Subitems**: 42 subitem boards; their columns (Owner, Status, Date, Link, Timeline, Numbers,
   Files) land on the parent board, as the 11-12 import did.
4. **Folders** (Main workspace; colour names are Monday's): A1 Chimney (DARK_ORANGE) ›
   Partners [Ohio, Nehorai - CT, Meni - NJ, Rotem - TX, Illinois, Netanel - GA, Liraz - DMV,
   Washingtom, Ron - MA], Office Management [Analorena, Eve - HR, Adeline, Jonathan, Brian],
   Marketing [Jose, Rodrigo], Field Management › Operations Management [NW, IL, OH, NJ, CT, DMV,
   TX, GA, MA, Outgoing Calls], Field Management › Project Management [Ohio PM, Illinois PM],
   Field Managers (empty), + A1 - Operatrion at the folder root. Everdoors (DONE_GREEN) ›
   [Everdoors - Operation], Partners [Yosef]. Triplex Group (WORKING_ORANGE) › [Triplex Group -
   Files], Yosef (CT, MA, RI, ME, NH) [Everdoors - MA Franchise]. New Locations (AQUAMARINE) ›
   [3 templates], A1 Chimney (WORKING_ORANGE), Everdoors (STUCK_RED) [Everdoors - Brand], Speedy
   Garage Doors (STUCK_RED), A1 Garage Doors (DARK_ORANGE), Crestline Home (DARK_RED, 6 empty "New
   Folder"), AngiSweep (SUNSET, 6 empty), Roofirst (BRIGHT_GREEN, 7 empty). Email marketing (empty).
   At workspace root: Shoval, Chaim, Bar, Merav - All Operations, Sharper - Rise-OFFICE, Users,
   Job Roles, All Calls, Toula. **GAP: Task Board has no folders** (UI-UX adding folder/board
   colours and user folders). The reader exposes `FOLDER_COLOURS` (name → hex) so the import can
   place each board once the model exists.
5. **Private-board locks**: every task board is `private` on Monday except Users / Job Roles /
   Analytics Dashboard (public). EzBizi custom boards are private-with-members; personal boards
   are private to their owner. Same.

## 4. Automations: every Monday recipe → Task Board

Pattern counts over 145 recipes (all boards; per-board list in the appendix):

| # | Monday recipe | Count (active) | EzBizi equivalent | Status |
|---|---|---|---|---|
| A | When Status changes to Done → move item to group X [only if in group Y] | 35 (35) | `task.status_changed`, `status changed_to Done` [+ `group_id eq`] → `task.move_group` | exists |
| B | … and notify Bar Ohayon "status changed to …" (Jose, Analorena, Toula) | 3 (2) | + `notify` a named person | exists |
| C | When an item is created → assign X (add) [only if in group] | 18 (16) | `task.created` [+ `group_id eq`] → `task.assign` `add: true` | **built** (`add`) |
| C2 | When an item is created → assign creator as Person (NW, Outgoing) | 2 (2) | `task.created` → `task.assign` `user_field: created_by_user_id` | **built** (snapshot carries creator) |
| D | When Area changes to <state> and only if in group "Customer Relations - Adeline" → add <state manager> (NW 8, Outgoing 7) | 15 (15) | **`task.column_changed`** (`column_id eq Area`, `value changed_to Connecticut`, `group_id eq`) → `task.assign add` | **built** |
| E | Every week, Monday 15:00 ET → notify <manager> "Please make sure to complete your weekly tasks." (manager boards) | 10 (10) | scheduled rule (weekly, monday, 15:00 America/New_York) → `notify` | exists |
| F | When Date arrives 13:00 ET, only if in "Reccuring Tasks", Date not empty, Status is Monthly Task → notify manager "maintain your vehicle…" and push Date by 1 month | 10 (9) | **`task.date_arrived`** (`column eq Date`, `days_until eq 0`, `group_id`, `status eq Monthly Task`), delay 780 min → `notify` + **`task.shift_date`** 1 month | **built** |
| G | Analorena: When Due Date arrives 21:15, in group, Reminder is Done → notify "David" (deleted Monday user) + set Due Date to today + set Reminder | 1 (1) | `task.date_arrived` → `notify` + **`task.set_value`** (date today, label) | **built**; recipient is a deleted Monday user → **question** |
| H | Ohio PM (workflow builder): When Schedule Date arrives, only if in Scheduled → move to In Progress | 1 (1) | `task.date_arrived` (`column eq Schedule Date`, `group_id eq Scheduled`) → `task.move_group` | **built** |
| I | When an item is created in "A1 Chimney"/"Everdoors" group → create item on Bar - All Operations (group A1 Chimney / Everdoors), copy name/status/person, connect boards | 3 (2) | `task.created` → `task.create` on Bar's board with the title, status and person | partial: **GAP connect-boards link** (Task links exist; no "linked item" column) |
| J | Dependency: when status changes to Done, set its dependency's status (Everdoors Brand/MA, 3 templates) | 5 (5) | — | **GAP: dependencies** |
| K | When item created → set Date to today and push due by 7 days; When date arrives 1 day before → notify | 10 (0, all deactivated) | `task.created` → `task.set_value today` + `task.shift_date`; `task.date_arrived days_until eq 1` | built; recreated **off**, as on Monday |
| L | All Calls (archived board): status → group, create item on other boards, copy phone, set status | 27 (0, disabled by Monday when the board was archived) | — | not recreated (board archived) |
| M | Send a webhook when a column changes (All Calls ×3, A1 - Operatrion ×1) | 4 (0: not connected / error) | outbound webhook action | not recreated (never worked) |

Recipients and assignees that have no EzBizi account (rules recreated without them until they do):
Nehorai Maamari (CT), Moshe Hay Zion (IL, Wisconsin), Ron Paz (MA), deleted member 71550800 (OH),
deleted member 76893540 ("David", Analorena), Monday user 78455682 (Liraz - DMV, not in the
people list).

Recreation: `ezbizi:monday-automations --spec=docs/agents/monday-automations-2026-11-13.json
--org=<org>` writes one ordinary Automations rule per recipe (109; 37 not carried: All Calls,
webhooks, dependency recipes on templates), on the board the import made, first condition
`board_id eq <board>` (so it shows in the board's ⚡ Automate and in Automations), on/off as on
Monday. A recipe naming something not here (a person with no account, a group Monday itself no
longer has — Outgoing Calls' 7 Area rules point at NW's group id) is made **off** with what is
missing in its description. Once each (`monday:<id>` in the description).

## 5. Boards → EzBizi target ("as we have it on Monday")

Every board comes over as itself, same name, groups, columns, labels. Personal boards → that
person's board; a board whose owner has no account → a custom board of the same name scoped to its
branch. NW - Nationwide → its own board, as on Monday. Templates (3) skipped. Archived items:
unreadable by the API, skipped. Sensitive items (logins, bank, card) imported and locked.
The per-board appendix lists each board's columns, labels and automations.

## 6. Run order (the desk, after merging and deploying)

1. `php artisan migrate` (monday_outbox) — part of the deploy.
2. `ezbizi:monday-import-boards --map=docs/agents/monday-board-map-2026-11-13.json --dry-run`, then
   without `--dry-run` (NW last if run per board: ~1 h of Monday reads).
3. `ezbizi:monday-automations --spec=docs/agents/monday-automations-2026-11-13.json --org=019fd712-74fd-712c-b179-fd88052cc371`.
4. `ezbizi:monday-webhooks` (registers 9 events per imported board; `--dry-run` lists them).
5. The scheduler then runs `ezbizi:monday-sync` (10 min + nightly full), `ezbizi:monday-push`
   (every minute) and `ezbizi:tasks-dates-arrived` (hourly).

## 7. Open questions for Jose (only what cannot be mirrored as-is)

1. **All Calls** (archived on Monday, 52,684 call rows, 31 disabled automations): mirror as an
   archived board, or leave it (EzBizi Phone already holds the calls)? Default: left out.
2. Rules whose Monday recipient has no EzBizi account (Nehorai, Moshe, Ron, deleted "David",
   deleted Ohio manager): recreate pointed at whom? Default: recreated with no recipient, off.

---

## Appendix: every board (generated from the 11-13 read)

### All Calls (9342236072) — ARCHIVED on Monday

- Items 52684, kind private, workspace Main workspace, folder: (workspace root)
- Groups (order, colour): New Client #c4c4c4, Existing Client #579bfc, New Client History #ffcb00, Existing Client History #a25ddc
- Views: Table (TableBoardView), Main View (TableBoardView)

| Column | Monday type | EzBizi | Labels / settings |
|---|---|---|---|
| Subitems | subtasks | subtasks |  |
| Dispatcher Name | text | text |  |
| Direction | status | status | Working on it #fdab3d; Stuck #df2f4a; Done* #00c875; Inbound #007eb5; Outbound #9d50dd |
| Status | status | status | BOOKED #9cd326; NOT BOOKED #ffadad; CANCELED #9d50dd; RESCHEDULED #fdab3d; COMPLAINT #bb3354; ESTIMATE #579bfc; OTHER #333333; UNCLEAR #ff007f; BLANK #c4c4c4; COLLECTIONS #037f4c; UNCLEAR #007eb5; INSPECTION FEE #ffcb00 |
| Client Status | status | status | UNCLEAR #007eb5; NEW CUSTOMER #00c875; EXISTING CUSTOMER #fdab3d; Unknown #c4c4c4 |
| Work Status | status | status | INSPECTION FEE #fdab3d; Free Estimate* #00c875; Inspection Fee #df2f4a; FREE ESTIMATE #007eb5; ESTIMATE #9d50dd; NONE #c4c4c4; UNCLEAR #037f4c; COMPLAINT #579bfc; FREE ESTIMATE / INSPECTION FEE #cab641 |
| Call Status | status | status | Missed #fdab3d; Accepted* #00c875; Hang Up #df2f4a; Call connected #007eb5; No Answer #9d50dd; Wrong Number #037f4c; Returning #cab641; Call Failed #579bfc; Voicemail #ffcb00; Rejected #333333; Busy #bb3354; IP Phone Offline #ff007f; Stopped #ff5ac4; Not Allowed #784bd1; Call Failure #9cd326 |
| Call  Summary | text | text |  |
| Date & Time | date | date | with time |
| Call Lengh | formula | text snapshot (GAP: formula) | `CONCATENATE(FLOOR({numeric_mks723pf} / 60), "m ", MOD({numeric_mks723pf}, 60), "sec")` |
| Date | date | date |  |
| Files | file | files on a "Files from Monday" update (GAP: files column) |  |
| Area Code | dropdown | tags | 39; 6; 24; 42; 1; 43; 12; 40; 18; 22; 44; 13; 5; 41; 30; 10; 51; 23; 4; 59; 58; 8; 64; 19; 25; 54; 21; 35; 2; 63; 14; 29; 15; 9; 49; 38; 48; 28; 46; 76; 3; 37; 34; 75; 47; 20; 50; 74; 57; 73; 56; 85;  |
| Number Name | text | text |  |
| Company Phone Number | phone | phone |  |
| Customer Phone Number | phone | phone |  |
| Call ID | text | text |  |
| Record ID | text | text |  |
| Session ID | text | text |  |
| Extension ID | text | text |  |
| Last updated | last_updated | built-in Updated |  |
| Item ID | item_id | skipped |  |
| Creation log | creation_log | built-in Created |  |
| duration | numbers | number/currency |  |
| Complaints | board_relation | text snapshot (GAP: connect boards) |  |
| Complaint Status | mirror | text snapshot (GAP: mirror) |  |
| Existing Client | board_relation | text snapshot (GAP: connect boards) |  |
| Match Status | status | status | Match* #00c875; Not Match #c4c4c4 |
| Not Booked | board_relation | text snapshot (GAP: connect boards) |  |
| Not Booked | board_relation | text snapshot (GAP: connect boards) |  |
| Chicago - All Calls | board_relation | text snapshot (GAP: connect boards) |  |
| Atlanta - All Calls | board_relation | text snapshot (GAP: connect boards) |  |
| Connecticut - All Calls | board_relation | text snapshot (GAP: connect boards) |  |
| Nationwide - All Calls | board_relation | text snapshot (GAP: connect boards) |  |
| New Jersey - All Calls | board_relation | text snapshot (GAP: connect boards) |  |
| Ohio - All Calls | board_relation | text snapshot (GAP: connect boards) |  |
| Texas - All Calls | board_relation | text snapshot (GAP: connect boards) |  |

Subitem columns (Subitems of All Calls): Owner [people], Status [status], Date [date]

Automations (31):
- `auto_disabled_by_board` WHEN Date & Time arrives 1 days after at 9:00 ET ; MOVE to group "Existing Client History" ; IF Client Status is EXISTING CUSTOMER — by Bar Ohayon
- `auto_disabled_by_board` WHEN Date & Time arrives 1 days after at 9:00 ET ; MOVE to group "New Client History" ; IF Client Status is NEW CUSTOMER — by Bar Ohayon
- `auto_disabled_by_board` WHEN Client Status changes to NEW CUSTOMER ; MOVE to group "New Client" — by Bar Ohayon
- `auto_disabled_by_board` WHEN Client Status changes to EXISTING CUSTOMER ; MOVE to group "Existing Client" — by Bar Ohayon
- `auto_disabled_by_board` WHEN Status changes to NOT BOOKED ; CREATE item on "9443577106" group "group_mkrz2gp5" copying ['name', 'text_mks7f8ps', 'status', 'color_mkrsn8p9', 'color_mkrsdytp', 'color_mks7bgkg', 'text_mkrsqfc3', 'text_mkrs952s', 'phone_mkrs3m45', 'phone_mkrsj05p', 'date4', 'file_mkrssk3n', 'date_mkrsssz6', 'color_mktbkaa4'], link via connect column ; IF Client Status is NEW CUSTOMER — by Bar Ohayon
- `auto_disabled_by_board` WHEN Status changes to COMPLAINT ; CREATE item on "8312747552" group "topics" copying ['name', 'status', 'phone_mkt2h3tf', 'phone_mkt2qz8x', 'text_mkt2qf4c', 'file_mkt2fzjc', 'date4', 'text_mktc5d5r'], link via connect column — by Bar Ohayon
- `not_connected` When a column changes, send a webhook {"columnId": "color_mkrsdytp"} — by Shoval Ohayon
- `auto_disabled_by_board` (connect boards: copy phone column to linked item) {"sourceBoardId": 9342236072, "targetBoardId": 9447466769, "targetColumnId": "phone_mks84ncs", "columnId": "phone_mkrsj05p", "linkToPulseColumnId": "board_relation_mks8eh4e"} — by Bar Ohayon
- `auto_disabled_by_board` WHEN Match Status changes to Match ; SET Client Status to EXISTING CUSTOMER — by Bar Ohayon
- `not_connected` When a column changes, send a webhook {"columnId": "board_relation_mks8eh4e"} — by Shoval Ohayon
- `not_connected` When a column changes, send a webhook {"columnId": "color_mkrsn8p9"} — by Shoval Ohayon
- `auto_disabled_by_board` WHEN Call Status changes to Call connected ; SET Call Status to Accepted — by Bar Ohayon
- `auto_disabled_by_board` WHEN Call Status changes to Hang Up ; SET Call Status to Call Failed — by Bar Ohayon
- `auto_disabled_by_board` WHEN Call Status changes to No Answer ; SET Call Status to Missed — by Bar Ohayon
- `not_connected` When a column changes, send a webhook {"columnId": "color_mksww062"} — by Shoval Ohayon
- `deactivated` WHEN Call Status changes to Missed ; IF dropdown Area Code contains one of 8 labels ; CREATE item on "9444567803" group "group_mkrz2gp5" copying ['name', 'text_mks7f8ps', 'status', 'color_mkrsn8p9', 'color_mkrsdytp', 'color_mks7bgkg', 'text_mkrsqfc3', 'dropdown_mkrsknb', 'text_mkrs952s', 'phone_mkrs3m45', 'phone_mkrsj05p', 'date4', 'file_mkrssk3n', 'numeric_mks723pf', 'date_mkrsssz6'], link via connect column — by Bar Ohayon
- `auto_disabled_by_board` WHEN Call  Summary changes ; IF dropdown Area Code contains one of 8 labels ; CREATE item on "9444567803" group "group_mkrz2gp5" copying ['name', 'text_mks7f8ps', 'status', 'color_mkrsn8p9', 'color_mkrsdytp', 'color_mks7bgkg', 'text_mkrsqfc3', 'text_mkrs952s', 'phone_mkrs3m45', 'phone_mkrsj05p', 'file_mkrssk3n', 'numeric_mks723pf', 'date_mkrsssz6', 'color_mktcdyac'], link via connect column — by Bar Ohayon
- `auto_disabled_by_board` ?{"boardId": 9342236072, "columnId": {"boardId": 9342236072, "columnId": "text_mkrsqfc3", "columnType": "text", "invalid": false}} ; IF dropdown Area Code contains one of 4 labels ; CREATE item on "9715577771" group "group_mkrz2gp5" copying ['name', 'text_mks7f8ps', 'status', 'color_mkrsn8p9', 'color_mks7bgkg', 'color_mksww062', 'text_mkrsqfc3', 'text_mkrs952s', 'phone_mkrs3m45', 'phone_mkrsj05p', 'date_mkrsssz6', 'file_mkrssk3n', 'text_mktcps28', 'color_mktcxv6p'], link via connect column — by Bar Ohayon
- `auto_disabled_by_board` ?{"boardId": 9342236072, "columnId": {"boardId": 9342236072, "columnId": "text_mkrsqfc3", "columnType": "text", "invalid": false}} ; IF dropdown Area Code contains one of 9 labels ; CREATE item on "9716178811" group "group_mkrz2gp5" copying ['name', 'text_mks7f8ps', 'status', 'color_mkrsn8p9', 'color_mks7bgkg', 'color_mksww062', 'text_mkrsqfc3', 'text_mkrs952s', 'phone_mkrs3m45', 'phone_mkrsj05p', 'date_mkrsssz6', 'file_mkrssk3n', 'text_mktcps28', 'color_mktcxv6p'], link via connect column — by Bar Ohayon
- `auto_disabled_by_board` ?{"boardId": 9342236072, "columnId": {"boardId": 9342236072, "columnId": "text_mkrsqfc3", "columnType": "text", "invalid": false}} ; IF dropdown Area Code contains one of 16 labels ; CREATE item on "9716572347" group "group_mkrz2gp5" copying ['name', 'text_mks7f8ps', 'status', 'color_mkrsn8p9', 'color_mks7bgkg', 'color_mksww062', 'text_mkrsqfc3', 'text_mkrs952s', 'phone_mkrs3m45', 'phone_mkrsj05p', 'date_mkrsssz6', 'file_mkrssk3n', 'text_mktcps28', 'color_mktcxv6p'], link via connect column — by Bar Ohayon
- `auto_disabled_by_board` WHEN Call  Summary changes ; IF dropdown Area Code contains one of 12 labels ; CREATE item on "9716574163" group "group_mkrz2gp5" copying ['name', 'text_mks7f8ps', 'status', 'color_mkrsn8p9', 'color_mks7bgkg', 'text_mkrsqfc3', 'text_mkrs952s', 'phone_mkrs3m45', 'phone_mkrsj05p', 'file_mkrssk3n', 'date_mkrsssz6', 'color_mktcxv6p', 'color_mksww062', 'text_mktcps28'], link via connect column — by Bar Ohayon
- `auto_disabled_by_board` WHEN Call  Summary changes ; IF dropdown Area Code contains one of 13 labels ; CREATE item on "9716577155" group "group_mkrz2gp5" copying ['name', 'text_mks7f8ps', 'status', 'color_mkrsn8p9', 'color_mks7bgkg', 'text_mkrsqfc3', 'text_mkrs952s', 'phone_mkrs3m45', 'phone_mkrsj05p', 'file_mkrssk3n', 'date_mkrsssz6', 'color_mktcxv6p', 'color_mksww062', 'text_mktcps28'], link via connect column — by Bar Ohayon
- `auto_disabled_by_board` WHEN Call  Summary changes ; IF dropdown Area Code contains one of 17 labels ; CREATE item on "9716579436" group "group_mkrz2gp5" copying ['name', 'text_mks7f8ps', 'status', 'color_mkrsn8p9', 'color_mks7bgkg', 'text_mkrsqfc3', 'text_mkrs952s', 'phone_mkrs3m45', 'phone_mkrsj05p', 'file_mkrssk3n', 'date_mkrsssz6', 'color_mktcxv6p', 'color_mksww062', 'text_mktcps28'], link via connect column — by Bar Ohayon
- `auto_disabled_by_board` WHEN Status changes from any to UNCLEAR ; IF Direction is Outbound ; SET Call Status to Voicemail — by Bar Ohayon
- `auto_disabled_by_board` WHEN Status changes from any to UNCLEAR ; IF Direction is Inbound ; SET Call Status to Call Failed ; IF duration < 10 — by Bar Ohayon
- `auto_disabled_by_board` WHEN Status changes to BLANK ; IF Status is BLANK ; IF Direction is Inbound ; IF duration < 1 ; SET Call Status to Missed — by Bar Ohayon
- `auto_disabled_by_board` WHEN Status changes to ESTIMATE ; MOVE to group "Existing Client" ; IF Status is BOOKED — by Bar Ohayon
- `auto_disabled_by_board` WHEN Status changes to RESCHEDULED ; MOVE to group "Existing Client" — by Bar Ohayon
- `auto_disabled_by_board` WHEN Status changes to COMPLAINT ; MOVE to group "Existing Client" — by Bar Ohayon
- `deactivated` WHEN Direction changes from any to Inbound ; IF duration < 1 ; IF Status is empty ; SET Call Status to Missed — by Bar Ohayon
- `auto_disabled_by_board` WHEN Status changes to #any ; IF Status is BLANK ; IF Direction is Outbound ; IF duration < 1 ; SET Call Status to Call Failed — by Bar Ohayon

### NW - Nationwide (10084134034)

- Items 24659, kind private, workspace Main workspace, folder: A1 Chimney (DARK_ORANGE) / Field Management / Operations Management
- Groups (order, colour): Company Info #c4c4c4, Field Manager - Claire #ff642e, Customer Relations - Adeline #df2f4a, Done #00c875
- Views: Complaints View (TableBoardView), Complaints View (SppBoardView), Table (TableBoardView)

| Column | Monday type | EzBizi | Labels / settings |
|---|---|---|---|
| Subitems | subtasks | subtasks |  |
| Person | people | person / assignees |  |
| Status | status | status | Received #007eb5; NO ANSWER #401694; Working on it #fdab3d; Stuck #df2f4a; Done* #00c875; NOT INTERESTED #9d50dd; PENDING #579bfc; COMPANY INFO #ff7575; SPAM #4eccc6; WRONG NUMBER #ff6d3b; Booked #66ccff; Confirmed #ffcb00; FOLLOW-UP REQUIRED #ff5ac4; MISSED #bb3354; URGENT #784bd1; NOT BOOKED #ff7575; Cancel #216edf; Pending #4eccc6 |
| Reason | status | status | Complaint #df2f4a; Vendor Collabrations #9aadbd; Net 30 Request #74afcc; Bid Request #7e3b8a; Deposit Job #9cd326; Inspection Report #00c875; Invoice Request #784bd1; Same Day Job #ff7575; Estimate Inquiry #579bfc; Phone / Email Estimate #037f4c; Cancel #333333; Reschedule #9d50dd; Call To Book #007eb5; Missed Call #bb3354; No Answer #ff5ac4; Requesting Credential #bda8f9; Waiting Payment #66ccff; Issue Refund #563e3e; COI / W9 Request #faa1f1; Other #175a63; ETA* #ffcb00; Parts #fdab3d; DEPOSIT #4eccc6; Pending Jobs #ff6d3b; Email Updated #cab641; Missed Voicemail #225091; PRIORITY #e484bd; DISPUTE #5559df; Not Booked #ff007f; Booked #a1e3f6; Redo Needed #401694; Bad Google Review #563e3e; BBB Claim #216edf; Refund Request #a9bee8; Tech No Show #e484bd; Struck #cd9282; Wrong Cap Installed #a1e3f6; OOA #9d99b9 |
| Area | status | status | DMV #225091; Connecticut #cab641; New Jersey #ff6d3b; Illinois #4eccc6; Nationwide #563e3e; Texas #037f4c; Ohio #74afcc; Georgia #cd9282; Wisonsin #ffcb00; Detroit #9d50dd; New York #bb3354; Massachusettes #ff007f |
| Notes | text | text |  |
| Date Received | date | date |  |
| Resolution Date | date | date |  |
| Resolution | status | status | Working on it #fdab3d; Done* #00c875; Stuck #df2f4a; Job Done - Partiel Refund Made #007eb5; No Resolution #9d50dd; Redo Completed #579bfc; Disputed - Challenged #cab641; Stoped Payment #ffcb00; Client Satisfied #333333; Job Canceled - Full Refund #bb3354; Job Canceled - Partiel Refund Made #ff007f; Disputed - Agreed #ff5ac4; Non Payment - Agreed #784bd1; Sent #9cd326; Redo Request #66ccff; Cx Requested Outreach To Stop #757575; Rescheduling Needed #225091; Still In Review #7f5347; Rescheduled #ff7575; Apologetic Outreach #faa1f1 |
| Last Contact Date | date | date |  |

Subitem columns (Subitems of NW - Nationwide): Owner [people], Status [status], Date [date], Reason [status], Area [status], Link [link], Status [status]

Automations (10):
- `active` WHEN Status changes to Done ; MOVE to group "Done" — by Bar Ohayon
- `active` WHEN item created ; ASSIGN creator to Person — by Bar Ohayon
- `active` WHEN item created ; IF item in group "Customer Relations - Adeline" ; ASSIGN (replace) Adeline Pierrelouis-Aghaji to Person — by Bar Ohayon
- `active` WHEN Area changes to Connecticut ; ASSIGN (add) Nehorai Maamari to Person ; IF item in group "Customer Relations - Adeline" — by Bar Ohayon
- `active` WHEN Area changes to New Jersey ; ASSIGN (add) Menahem Maamari to Person ; IF item in group "Customer Relations - Adeline" — by Bar Ohayon
- `active` WHEN Area changes to Illinois ; ASSIGN (add) Moshe Hay Zion to Person ; IF item in group "Customer Relations - Adeline" — by Bar Ohayon
- `active` WHEN Area changes to Texas ; ASSIGN (add) Rotem Hadad to Person ; IF item in group "Customer Relations - Adeline" — by Bar Ohayon
- `active` WHEN Area changes to Ohio ; ASSIGN (add) user 71550800 to Person ; IF item in group "Customer Relations - Adeline" — by Bar Ohayon
- `active` WHEN Area changes to Georgia ; ASSIGN (add) Netanel mullakand to Person ; IF item in group "Customer Relations - Adeline" — by Bar Ohayon
- `active` WHEN Area changes to Wisonsin ; ASSIGN (add) Moshe Hay Zion to Person ; IF item in group "Customer Relations - Adeline" — by Bar Ohayon

### IL - Illinois (18424929266)

- Items 1434, kind private, workspace Main workspace, folder: A1 Chimney (DARK_ORANGE) / Field Management / Operations Management
- Groups (order, colour): Company Info #bb3354, 📋 Administration - Eve #66ccff, Accounting - Esti #ff5ac4, Field Manager - Eddie #579bfc, Customer Relations - Adeline #df2f4a, Done #00c875
- Views: Table (TableBoardView), Table (TableBoardView)

| Column | Monday type | EzBizi | Labels / settings |
|---|---|---|---|
| Subitems | subtasks | subtasks |  |
| Person | people | person / assignees |  |
| Status | status | status | Received #007eb5; NO ANSWER #401694; Working on it #fdab3d; Stuck #df2f4a; Done* #00c875; NOT INTERESTED #9d50dd; PENDING #579bfc; COMPANY INFO #ff7575; SPAM #4eccc6; WRONG NUMBER #ff6d3b; Booked #66ccff; Confirmed #ffcb00; FOLLOW-UP REQUIRED #ff5ac4; MISSED #bb3354; Files #757575; Urgent #9cd326; Pending #5559df; dispute #784bd1 |
| Reason | status | status | Complaint #df2f4a; Requesting Cancellation #9aadbd; Soot Damage / No Protective Covering #74afcc; Bid Request #7e3b8a; Deposit Job #9cd326; Inspection Report #00c875; Invoice Request #784bd1; Cap Issue #ff7575; Estimate Inquiry #579bfc; Phone / Email Estimate #037f4c; Cancel #333333; Reschedule #9d50dd; Call To Book #007eb5; Missed Call #bb3354; No Answer #ff5ac4; Missed Voicemail #bda8f9; Waiting Payment #66ccff; Issue Refund #563e3e; COI / W9 Request #faa1f1; Other #175a63; ETA* #ffcb00; Remote Issue #c4c4c4; Parts #fdab3d; DEPOSIT #4eccc6; Pending Jobs #ff6d3b; Email Updated #cab641; Missed Voicemail #225091; BBB COMPLAINT #225091; Need Requested Documents #4eccc6; Bad Google Review #563e3e; Tech Conflict #401694; Refund Requested #9d99b9; Damage to Property #bda8f9; Missed Appointment #216edf; Incomplete Work #a9bee8; Awaiting for Start Date #e484bd; Pricing Discrepancy #5559df; Document Request #a1e3f6; Pilot Issue #cd9282; Corrective Work Needed #175a63 |
| Area | status | status | Indiana #c4c4c4; DMV #225091; Connecticut #cab641; New Jersey #ff6d3b; Illinois #4eccc6; Nationwide #563e3e; Texas #037f4c; Ohio #74afcc; Georgia #cd9282; Wisonsin #ffcb00; Detroit #9d50dd; Washington #bb3354 |
| Resolution | status | status | Working on it #fdab3d; Done* #00c875; Stuck #df2f4a; Job Done - Partiel Refund Made #007eb5; No Resolution #9d50dd; Redo Schedule Needed #037f4c; Redo Completed #579bfc; Disputed - Challenged #cab641; Stoped Payment #ffcb00; Client Satisfied #333333; Job Canceled - Full Refund #bb3354; Job Canceled - Partiel Refund Made #ff007f; Disputed - Agreed #ff5ac4; Non Payment - Agreed #784bd1; Sent #9cd326; Redo Request #66ccff; Discount Goodwill Gesture #ff6d3b; Apologetic Outreach #7f5347; Reschedule #175a63; Requested GM Callback #ff7575; Still In Review #e484bd; Request Refund #ffadad; Schedule Repair Team #757575; Installation Date Needed #9aadbd; Requested Date For Completion #216edf; Need to Send Documents #225091; Pending #bca58a; Refund #563e3e; DUPLICATE #5559df; OM Review & Approval Needed #401694; Awaiting Deposit #a1e3f6; Deposit Received #4eccc6; Estimate Declined #7e3b8a |
| Notes | text | text |  |
| Date Received | date | date |  |
| Last Contact Date | date | date |  |
| Date Resolved | date | date |  |

Subitem columns (Subitems of IL - Illinois): Owner [people], Status [status], Date [date], Reason [status], Area [status], Link [link]

Automations (1):
- `active` WHEN Status changes to Done ; MOVE to group "Done" — by Bar Ohayon

### NJ - New Jersey (18426054807)

- Items 932, kind private, workspace Main workspace, folder: A1 Chimney (DARK_ORANGE) / Field Management / Operations Management
- Groups (order, colour): Company Info #bb3354, 📋 Administration Board - Eve #66ccff, Accounting - Esti #579bfc, Field Manager - Lucas #ff5ac4, Customer Relations - Natalie #bb3354, Project Manager - Darren #7f5347, Done #00c875
- Views: Main table only

| Column | Monday type | EzBizi | Labels / settings |
|---|---|---|---|
| Subitems | subtasks | subtasks |  |
| Person | people | person / assignees |  |
| Status | status | status | Received #007eb5; NO ANSWER #401694; Working on it #fdab3d; Stuck #df2f4a; Done* #00c875; Pending #9d50dd; PENDING #579bfc; COMPANY INFO #ff7575; SPAM #4eccc6; WRONG NUMBER #ff6d3b; Booked #66ccff; Confirmed #ffcb00; FOLLOW-UP REQUIRED #ff5ac4; MISSED #bb3354; Files #757575; Waiting  Review #9cd326; Need GM Review #66ccff |
| Area | status | status | New Jersey #c4c4c4; DMV #225091; Connecticut #cab641; New Jersey #ff6d3b; Illinois #4eccc6; Nationwide #563e3e; Texas #037f4c; Ohio #74afcc; Georgia #cd9282; Wisonsin #ffcb00; Detroit #9d50dd; Washington #bb3354 |
| Reason | status | status | Complaint #df2f4a; Vendor Collabrations #9aadbd; Net 30 Request #74afcc; Bid Request #7e3b8a; Deposit Job #9cd326; Inspection Report #00c875; Invoice Request #784bd1; Same Day Job #ff7575; Estimate Inquiry #579bfc; Phone / Email Estimate #037f4c; Cancel #333333; Reschedule #9d50dd; Call To Book #007eb5; Missed Call #bb3354; No Answer #ff5ac4; Missed Voicemail #bda8f9; Waiting Payment #66ccff; Issue Refund #563e3e; COI / W9 Request #faa1f1; Other #175a63; ETA* #ffcb00; Parts #fdab3d; DEPOSIT #4eccc6; Pending Jobs #ff6d3b; Email Updated #cab641; Missed Voicemail #225091 |
| Status 1 | status | status | Working on it #fdab3d; Done* #00c875; Stuck #df2f4a; Non-Payment Agreed #007eb5; DUPLICATE #bca58a; job done - full refund #037f4c; invoice sent #579bfc; Cancel Job #cab641; Deposit Received #ffcb00; Refund Inquiry #333333; Estimate Declined #bb3354; Job Rescheduled #ff007f; Job Done No Refund #ff5ac4; estimate sent #784bd1; Job Done Partiall refund #9cd326; No Resolution #66ccff; waiting payment #4eccc6; REDO SCH #7f5347 |
| Notes | text | text |  |
| Date Received | date | date |  |
| Last Contact Date | date | date |  |
| Resolution Date | date | date |  |

Subitem columns (Subitems of NJ - New Jersey): Owner [people], Status [status], Date [date]

Automations (1):
- `active` WHEN Status changes to Done ; MOVE to group "Done" — by Eve Sarfaty

### OH - Ohio (18426052010)

- Items 784, kind private, workspace Main workspace, folder: A1 Chimney (DARK_ORANGE) / Field Management / Operations Management
- Groups (order, colour): Company Info #bb3354, 📋 Administration Board - Eve #66ccff, Accounting - Esti #007eb5, General Manager - Shneor #df2f4a, 🔧 Technician Field Manager - Jared,   Mar (8-10 AM, 6-10 PM) #579bfc, Project Manager - Chris #007eb5, 🤝 Customer Relations Board - Adeline #df2f4a, Disputes / Stop Payments #7f5347, Done #00c875
- Views: Table (TableBoardView), Table (TableBoardView)

| Column | Monday type | EzBizi | Labels / settings |
|---|---|---|---|
| Subitems | subtasks | subtasks |  |
| Person | people | person / assignees |  |
| Status | status | status | Received #007eb5; NO ANSWER #401694; Working on it #fdab3d; Stuck #df2f4a; Done* #00c875; Pending #9d50dd; PENDING #579bfc; COMPANY INFO #ff7575; SPAM #4eccc6; WRONG NUMBER #ff6d3b; Booked #66ccff; Confirmed #ffcb00; FOLLOW-UP REQUIRED #ff5ac4; MISSED #bb3354; Files #757575; Pending #784bd1; Waiting Review #66ccff; URGENT #faa1f1; Dispute #7f5347; Redo Requested #ff6d3b; Requested Discount #9cd326; Not Interested #ffadad; Waiting Review #a1e3f6 |
| Reason | status | status | Complaint #df2f4a; Soot Residue Left #9aadbd; Net 30 Request #74afcc; Bid Request #7e3b8a; Deposit Job #9cd326; Inspection Report #00c875; Invoice Request #784bd1; Same Day Job #ff7575; Estimate Inquiry #579bfc; Phone / Email Estimate #037f4c; Cancel #333333; Reschedule #9d50dd; Call To Book #007eb5; Missed Call #bb3354; No Answer #ff5ac4; Waiting Payment #66ccff; Issue Refund #563e3e; COI / W9 Request #faa1f1; Other #175a63; ETA* #ffcb00; Parts #fdab3d; Pending Jobs #ff6d3b; Tech No Show #225091; Refund Request #e484bd; Documents Request #4eccc6; Negative Review #5559df; BBB Claim #216edf; Challange #a1e3f6; Finish Unacceptable #bda8f9; Waiting Tech #401694; REDO Needed #a9bee8; Disputed Charges #225091; Wrong Size of Liner #9d99b9; Request Discount #563e3e; Pricing Discrepancy #cd9282; Report Property Damage #175a63; Cap Issue #bda8f9; Awaiting For Start Date #cab641; Need to Schedule #4eccc6 |
| Resolution | status | status | Working on it #fdab3d; Installation Date Needed* #00c875; Stuck #df2f4a; Job Done - Partiel Refund Made #007eb5; No Resolution #9d50dd; Redo Schedule Needed #037f4c; Redo Completed #579bfc; Disputed - Challenged #cab641; Stoped Payment #ffcb00; Client Satisfied #333333; Job Canceled - Full Refund #bb3354; Job Canceled - Partiel Refund Made #ff007f; Disputed - Agreed #ff5ac4; Non Payment - Agreed #784bd1; Sent #9cd326; Redo Request #66ccff; Reschedule Needed #ff7575; Schedule Repair Team #7f5347; FM Review Needed #ff6d3b; Pending #faa1f1; Pending Customer Response #757575; Resolved In Customer Favor #401694; Refund Request In Review #7e3b8a; Still in Review #216edf; GM Oversight  Needed #74afcc |
| Notes | text | text |  |
| Date Received | date | date |  |
| Last Customer Contact | date | date |  |
| Date Resolved | date | date |  |

Subitem columns (Subitems of OH - Ohio): Owner [people], Status [status], Date [date]

Automations (1):
- `active` WHEN Status changes to Done ; MOVE to group "Done" — by Eve Sarfaty

### CT - Connecticut (18426444271)

- Items 611, kind private, workspace Main workspace, folder: A1 Chimney (DARK_ORANGE) / Field Management / Operations Management
- Groups (order, colour): Company Info #bb3354, Accounting - Esti #fdab3d, 📋 Administration Board - Eve #66ccff, 🔧 Tech Field Manager -Melissa #579bfc, Complaints - Natalie #df2f4a, Done #00c875, Customer Relations -Natalie #df2f4a
- Views: Main table only

| Column | Monday type | EzBizi | Labels / settings |
|---|---|---|---|
| Subitems | subtasks | subtasks |  |
| Person | people | person / assignees |  |
| Status | status | status | Received #007eb5; NO ANSWER #401694; Working on it #fdab3d; Stuck #df2f4a; Done* #00c875; NOT INTERESTED #9d50dd; PENDING #579bfc; COMPANY INFO #ff7575; SPAM #4eccc6; WRONG NUMBER #ff6d3b; Booked #66ccff; Confirmed #ffcb00; FOLLOW-UP REQUIRED #ff5ac4; MISSED #bb3354; Files #757575; Paid in Full #9cd326 |
| Area | status | status | DMV #225091; Connecticut #cab641; New Jersey #ff6d3b; Illinois #4eccc6; Nationwide #563e3e; Texas #037f4c; Ohio #74afcc; Georgia #cd9282; Wisonsin #ffcb00; Detroit #9d50dd; Washington #bb3354 |
| Reason | status | status | Complaint #df2f4a; Vendor Collabrations #9aadbd; Net 30 Request #74afcc; Bid Request #7e3b8a; Deposit Job #9cd326; Inspection Report #00c875; Invoice Request #784bd1; Same Day Job #ff7575; Estimate Inquiry #579bfc; Phone / Email Estimate #037f4c; Cancel #333333; Reschedule #9d50dd; Call To Book #007eb5; Missed Call #bb3354; No Answer #ff5ac4; Missed Voicemail #bda8f9; Waiting Payment #66ccff; Issue Refund #563e3e; COI / W9 Request #faa1f1; Other #175a63; ETA* #ffcb00; Parts #fdab3d; DEPOSIT #4eccc6; Pending Jobs #ff6d3b; Email Updated #cab641; Missed Voicemail #225091; requesting refund #225091; BBB COMPLAINT #216edf |
| Resolution | status | status | Working on it #fdab3d; Done* #00c875; Stuck #df2f4a; Job Done- Partial Refund #007eb5; No Resolution #9d50dd; Redo Schedule Needed #037f4c; Redo Completed #579bfc; Disputed Challenge #cab641; Stopped Payment #ffcb00; Client Satisfied #333333; Job Canceled - full refund #bb3354; Job Canceled- Partial Refund #ff007f; Disputed - agreed #ff5ac4; Non payment- agreement #784bd1; Sent #9cd326; Redo Request #66ccff; Schedule repair team #757575; Apologetic outreach #7f5347; Discounted Goodwill #ff6d3b; Requested GM Calibrated #ff7575; Still in review #faa1f1; Request Refund #ffadad; OM Repair & approval #7e3b8a; Installtion date needed #9aadbd; Sent Documents #74afcc; Pending #225091; duplicate #5559df; Pending #175a63; Job done no refund needed #563e3e; cx Rescheduled #bda8f9; Estimate declined #216edf; redo sched #a9bee8; Job Done- Full Refund #e484bd; Job Paid #9d99b9 |
| Notes | text | text |  |
| Date Received | date | date |  |
| Last Contact Date | date | date |  |
| Resolution Date | date | date |  |

Subitem columns (Subitems of CT - Connecticut): Owner [people], Status [status], Date [date]

Automations (1):
- `active` WHEN Status changes to Done ; MOVE to group "Done" — by Eve Sarfaty

### Outgoing Calls (18435173396)

- Items 411, kind private, workspace Main workspace, folder: A1 Chimney (DARK_ORANGE) / Field Management / Operations Management
- Groups (order, colour): Outgoing Calls / Missed Calls #c4c4c4, Done #00c875
- Views: Complaints View (TableBoardView), Table (TableBoardView), Table (TableBoardView)

| Column | Monday type | EzBizi | Labels / settings |
|---|---|---|---|
| Subitems | subtasks | subtasks |  |
| Person | people | person / assignees |  |
| Status | status | status | Received #007eb5; NO ANSWER #401694; Working on it #fdab3d; Stuck #df2f4a; Done* #00c875; NOT INTERESTED #9d50dd; PENDING #579bfc; COMPANY INFO #ff7575; SPAM #4eccc6; WRONG NUMBER #ff6d3b; Booked #66ccff; Confirmed #ffcb00; FOLLOW-UP REQUIRED #ff5ac4; MISSED #bb3354; URGENT #784bd1; NOT BOOKED #ff7575; Cancel #216edf; Pending #4eccc6 |
| Reason | status | status | Complaint #df2f4a; Vendor Collabrations #9aadbd; Net 30 Request #74afcc; Bid Request #7e3b8a; Deposit Job #9cd326; Inspection Report #00c875; Invoice Request #784bd1; Same Day Job #ff7575; Estimate Inquiry #579bfc; Phone / Email Estimate #037f4c; Cancel #333333; Reschedule #9d50dd; Call To Book #007eb5; Missed Call #bb3354; No Answer #ff5ac4; Missed Voicemail #bda8f9; Waiting Payment #66ccff; Issue Refund #563e3e; COI / W9 Request #faa1f1; Other #175a63; ETA* #ffcb00; Parts #fdab3d; DEPOSIT #4eccc6; Pending Jobs #ff6d3b; Email Updated #cab641; Missed Voicemail #225091; PRIORITY #e484bd; DISPUTE #5559df; Not Booked #ff007f; Booked #a1e3f6; Bad Google Review #563e3e; BBB Claim #216edf; Refund Request #a9bee8 |
| Area | status | status | DMV #225091; Connecticut #cab641; New Jersey #ff6d3b; Illinois #4eccc6; Nationwide #563e3e; Texas #037f4c; Ohio #74afcc; Georgia #cd9282; Wisonsin #ffcb00; Detroit #9d50dd; New York #bb3354; Massachusettes #ff007f |
| Notes | text | text |  |
| Date Received | date | date |  |
| Resolution Date | date | date |  |
| Resolution | status | status | Working on it #fdab3d; Done* #00c875; Stuck #df2f4a; Job Done - Partiel Refund Made #007eb5; No Resolution #9d50dd; Redo Scheduled #037f4c; Redo Completed #579bfc; Disputed - Challenged #cab641; Stoped Payment #ffcb00; Client Satisfied #333333; Job Canceled - Full Refund #bb3354; Job Canceled - Partiel Refund Made #ff007f; Disputed - Agreed #ff5ac4; Non Payment - Agreed #784bd1; Sent #9cd326; Redo Request #66ccff |
| Last Contact Date | date | date |  |

Subitem columns (Subitems of Outgoing Calls): Owner [people], Status [status], Date [date], Reason [status], Area [status], Link [link], Status [status]

Automations (10):
- `active` WHEN Status changes to Done ; MOVE to group "Done" — by Eve Sarfaty
- `active` WHEN item created ; ASSIGN creator to Person — by Eve Sarfaty
- `active` WHEN Area changes to Illinois ; ASSIGN (add) Moshe Hay Zion to Person ; IF item in group "group_mm1zfwhw" — by Eve Sarfaty
- `active` WHEN Area changes to Connecticut ; ASSIGN (add) Nehorai Maamari to Person ; IF item in group "group_mm1zfwhw" — by Eve Sarfaty
- `active` WHEN Area changes to Wisonsin ; ASSIGN (add) Moshe Hay Zion to Person ; IF item in group "group_mm1zfwhw" — by Eve Sarfaty
- `active` WHEN Area changes to Ohio ; ASSIGN (add) user 71550800 to Person ; IF item in group "group_mm1zfwhw" — by Eve Sarfaty
- `active` WHEN Area changes to Georgia ; ASSIGN (add) Netanel mullakand to Person ; IF item in group "group_mm1zfwhw" — by Eve Sarfaty
- `active` WHEN Area changes to New Jersey ; ASSIGN (add) Menahem Maamari to Person ; IF item in group "group_mm1zfwhw" — by Eve Sarfaty
- `active` WHEN Area changes to Texas ; ASSIGN (add) Rotem Hadad to Person ; IF item in group "group_mm1zfwhw" — by Eve Sarfaty
- `active` WHEN item created ; IF item in group "group_mm1zfwhw" ; ASSIGN (replace) Adeline Pierrelouis-Aghaji to Person — by Eve Sarfaty

### GA - Georgia (18435168865)

- Items 322, kind private, workspace Main workspace, folder: A1 Chimney (DARK_ORANGE) / Field Management / Operations Management
- Groups (order, colour): Company Info #bb3354, 📋 Administration Board - Eve #66ccff, Accounting - Esti #579bfc, Field Manager - Carlo #ff5ac4, Project Manager - Nate #7f5347, Customer Relations - Natalie #bb3354, Done #00c875
- Views: Table (TableBoardView)

| Column | Monday type | EzBizi | Labels / settings |
|---|---|---|---|
| Subitems | subtasks | subtasks |  |
| Person | people | person / assignees |  |
| Status | status | status | Received #007eb5; NO ANSWER #401694; Working on it #fdab3d; Stuck #df2f4a; Done* #00c875; NOT INTERESTED #9d50dd; PENDING #579bfc; COMPANY INFO #ff7575; SPAM #4eccc6; WRONG NUMBER #ff6d3b; Booked #66ccff; Confirmed #ffcb00; FOLLOW-UP REQUIRED #ff5ac4; MISSED #bb3354; Files #757575 |
| Area | status | status | DMV #225091; Connecticut #cab641; New Jersey #ff6d3b; Illinois #4eccc6; Nationwide #563e3e; Texas #037f4c; Ohio #74afcc; Georgia #cd9282; Wisonsin #ffcb00; Detroit #9d50dd; Washington #bb3354 |
| Reason | status | status | Complaint #df2f4a; Vendor Collabrations #9aadbd; Net 30 Request #74afcc; Bid Request #7e3b8a; Deposit Job #9cd326; Inspection Report #00c875; Invoice Request #784bd1; Same Day Job #ff7575; Estimate Inquiry #579bfc; Phone / Email Estimate #037f4c; Cancel #333333; Reschedule #9d50dd; Call To Book #007eb5; Missed Call #bb3354; No Answer #ff5ac4; Missed Voicemail #bda8f9; Waiting Payment #66ccff; Issue Refund #563e3e; COI / W9 Request #faa1f1; Other #175a63; ETA* #ffcb00; Parts #fdab3d; DEPOSIT #4eccc6; Pending Jobs #ff6d3b; Email Updated #cab641; Missed Voicemail #225091; requesting redo #225091 |
| Resolutions | status | status | Working on it #fdab3d; Done* #00c875; Stuck #df2f4a; redo completed #007eb5; no resolution #9d50dd; redo schedule needed #037f4c; redo completed #579bfc; disputed- challenge #cab641; stopped payment #ffcb00; client satisfied #333333; Job canceled- Full refund #bb3354; Job canceled- partial refund #ff007f; Disputed - agreed #ff5ac4; Non payment- agreed #784bd1; sent #9cd326; Redo request #66ccff; schedule repair team #757575; rescheduling needed #7f5347; Requesting redo #ff6d3b; Duplicate #9d99b9; Job Completed #faa1f1; Job Done full refund #ffadad; Duplicate #7e3b8a; Job Done- Full Refund #9aadbd; Apologetic reachout #74afcc; cancelled #225091 |
| Notes | text | text |  |
| Date Received | date | date |  |
| Last Contact Date | date | date |  |
| Resolution Date | date | date |  |

Subitem columns (Subitems of GA - Georgia): Owner [people], Status [status], Date [date]

Automations (1):
- `active` WHEN Status changes to Done ; MOVE to group "Done" — by Eve Sarfaty

### TX- Texas (18427055247)

- Items 319, kind private, workspace Main workspace, folder: A1 Chimney (DARK_ORANGE) / Field Management / Operations Management
- Groups (order, colour): Company Info #bb3354, 📋 Administration Board - Eve #66ccff, Accounting - Esti #9d50dd, 🔧 Technician Field Manager - Nicholas #00c875, 🤝 Customer Relations Board - Natalie #df2f4a, Done #00c875
- Views: Main table only

| Column | Monday type | EzBizi | Labels / settings |
|---|---|---|---|
| Subitems | subtasks | subtasks |  |
| Person | people | person / assignees |  |
| Status | status | status | Received #007eb5; NO ANSWER #401694; Working on it #fdab3d; Stuck #df2f4a; Done* #00c875; NOT INTERESTED #9d50dd; PENDING #579bfc; COMPANY INFO #ff7575; SPAM #4eccc6; WRONG NUMBER #ff6d3b; Booked #66ccff; Confirmed #ffcb00; FOLLOW-UP REQUIRED #ff5ac4; MISSED #bb3354; Files #757575 |
| Area | status | status | DMV #225091; Connecticut #cab641; New Jersey #ff6d3b; Illinois #4eccc6; Nationwide #563e3e; Texas #037f4c; Ohio #74afcc; Georgia #cd9282; Wisonsin #ffcb00; Detroit #9d50dd; Washington #bb3354 |
| Reason | status | status | Complaint #df2f4a; Vendor Collabrations #9aadbd; Net 30 Request #74afcc; Bid Request #7e3b8a; Deposit Job #9cd326; Inspection Report #00c875; Invoice Request #784bd1; Same Day Job #ff7575; Estimate Inquiry #579bfc; Phone / Email Estimate #037f4c; Cancel #333333; Reschedule #9d50dd; Call To Book #007eb5; Missed Call #bb3354; No Answer #ff5ac4; Missed Voicemail #bda8f9; Waiting Payment #66ccff; Issue Refund #563e3e; COI / W9 Request #faa1f1; Other #175a63; ETA* #ffcb00; Parts #fdab3d; DEPOSIT #4eccc6; Pending Jobs #ff6d3b; Email Updated #cab641; Missed Voicemail #225091; cx cancelled #225091; Requesting refund #4eccc6; Invoice paid #5559df |
| Status 1 | status | status | Working on it #fdab3d; Done* #00c875; Stuck #df2f4a; REDO Request #007eb5; DUPLICATE #9d50dd; goodwill gesture #037f4c; Scheduled #579bfc; Estimate Declined #ff007f; Cx Cancelled #ffcb00; Apologetic message #333333; job done - partial refund #bb3354; redo Sched #cab641; Job Done NO Refund #ff5ac4 |
| Notes | text | text |  |
| Date Received | date | date |  |
| Last Contact Date | date | date |  |
| Resolution Date | date | date |  |

Subitem columns (Subitems of TX- Texas): Owner [people], Status [status], Date [date]

Automations (1):
- `active` WHEN Status changes to Done ; MOVE to group "Done" — by Eve Sarfaty

### DMV (18426446570)

- Items 305, kind private, workspace Main workspace, folder: A1 Chimney (DARK_ORANGE) / Field Management / Operations Management
- Groups (order, colour): Company Info #bb3354, 📋 Administration Board - Eve #66ccff, Accounting- Esti #9d50dd, 🔧 Technician Field Manager - Crizzel #579bfc, 🤝 Customer Relations Board - Natalie #df2f4a, Done #00c875
- Views: Table (TableBoardView), Table (TableBoardView), Table (TableBoardView), Table (TableBoardView)

| Column | Monday type | EzBizi | Labels / settings |
|---|---|---|---|
| Subitems | subtasks | subtasks |  |
| Person | people | person / assignees |  |
| Status | status | status | Received #007eb5; NO ANSWER #401694; Working on it #fdab3d; Stuck #df2f4a; Done* #00c875; NOT INTERESTED #9d50dd; PENDING #579bfc; COMPANY INFO #ff7575; SPAM #4eccc6; WRONG NUMBER #ff6d3b; Booked #66ccff; Confirmed #ffcb00; FOLLOW-UP REQUIRED #ff5ac4; MISSED #bb3354; Files #757575; Deposit Collected #9cd326 |
| Area | status | status | DMV #225091; Connecticut #cab641; New Jersey #ff6d3b; Illinois #4eccc6; Nationwide #563e3e; Texas #037f4c; Ohio #74afcc; Georgia #cd9282; Wisonsin #ffcb00; Detroit #9d50dd; Washington #bb3354 |
| Reason | status | status | Complaint #df2f4a; Vendor Collaboration #9aadbd; Net 30 Request #74afcc; Bid Request #7e3b8a; Deposit Job #9cd326; Inspection Report #00c875; Invoice Request #784bd1; Same Day Job #ff7575; Estimate Inquiry #579bfc; Phone/Email  Estimate #037f4c; Cancel #333333; Reschedule #9d50dd; Call To Book #225091; Missed Call #bb3354; No Answer #ff5ac4; Missed Voicemail #bda8f9; Waiting Payment #66ccff; Issue Refund #563e3e; ETA #ff6d3b; Other #ffcb00 |
| Resolution | status | status | Working on it #fdab3d; Done* #00c875; Stuck #df2f4a; job done- partial refund #007eb5; No Resolution #9d50dd; Redo scheduled need #037f4c; Redo Completed #579bfc; Disputed - challange #cab641; Stopped payment #ffcb00; Client Satisfied #333333; Job Canceled- full refund #bb3354; Job Canceled- partial refund #ff007f; Disputed - agreed #ff5ac4; Non payment- agreed #784bd1; Sent #9cd326; Redo request #66ccff; Schdule repair team #757575; Apologetic Outreach #7f5347; Discounted Goodwill #ff6d3b; Requested GM Calibrated #ff7575; Still in review #faa1f1; Request Refund #ffadad; OM review #7e3b8a; installation date needed #9aadbd; Sent documents #74afcc; Duplicate #225091; Pending #4eccc6; Job Canceled by cx #5559df; Partial Deposit #a1e3f6; Waiting Review #563e3e; Refunded #bda8f9; Job Canceled #216edf |
| Notes | text | text |  |
| Date Received | date | date |  |
| Last Contact Date | date | date |  |
| Resolution Date | date | date |  |

Subitem columns (Subitems of DMV): Owner [people], Status [status], Date [date]

Automations (1):
- `active` WHEN Status changes to Done ; MOVE to group "Done" — by Eve Sarfaty

### Eve Sarfaty - HR (9129262284)

- Items 238, kind private, workspace Main workspace, folder: A1 Chimney (DARK_ORANGE) / Office Management
- Groups (order, colour): Open Tasks #00c875, Consumer Complaint/Lawsuits #ff007f, Lawsuits / Collection Management #df2f4a, Daily & Weekly Tasks #66ccff, Company Database #757575, Company Files #757575, Company Car Insurances #757575, Fleet Management #ff5ac4, Completed Tasks #00c875
- Views: Table (TableBoardView)

| Column | Monday type | EzBizi | Labels / settings |
|---|---|---|---|
| Person | people | person / assignees |  |
| Status | status | status | Received #007eb5; Weekly Task #9d50dd; Working on it #fdab3d; Stuck #df2f4a; Done* #00c875; Pending #ff6d3b; COMPANY  INFO #ff007f; CHIMCARE #579bfc; CITYLINE #ff7575; GENESIS #ffcb00; MAGNETIC #ffadad; Daily Task #a1e3f6; Hold #bb3354; SHOVAL #5559df; Files #333333; URGENT #037f4c; Pending #784bd1 |
| Timeline | timeline | timeline |  |
| Date | date | date |  |
| Reminder | status | status | Done* #00c875; Reminder #df2f4a |
| Link | link | url |  |
| Subitems | subtasks | subtasks |  |

Subitem columns (Subitems of Eve Sarfaty - HR): Timeline [timeline], Owner [people], Status [status], Vehicle [text], Amount [text], VIN [text], Link [link], Date [date]

Automations (2):
- `active` WHEN Status changes to Done ; MOVE to group "Completed Tasks" — by Bar Ohayon
- `error` WHEN item created ; ASSIGN (add) Eve Sarfaty to Person — by Bar Ohayon

### Merav - All Operation (8447409431)

- Items 199, kind private, workspace Main workspace, folder: (workspace root)
- Groups (order, colour): All Oporations - Open Tasks #ff642e, A1 Chimney HQ - Company Flies #9cd326, A1 Chimney - 2026 Business Reports #66ccff, A1 Chimney - 2025 Business Reports #579bfc, A1 Chimney - Franchises Information #037f4c, A1 Chimney - Excels #9d50dd, Triplex Group #cab641, Everdoors #fdab3d, Ace Home Care - Excels #ff5ac4, Completed Tasks #00c875
- Views: Table (TableBoardView), Kanban (KanbanBoardView), Table (TableBoardView)

| Column | Monday type | EzBizi | Labels / settings |
|---|---|---|---|
| Subitems | subtasks | subtasks |  |
| Person | people | person / assignees |  |
| Status | status | status | Received #007eb5; Working on it #fdab3d; Stuck #df2f4a; Done #00c875; Pending #e484bd |
| Due Date | date | date | with time |
| Text | text | text |  |
| Job number | text | text |  |
| Text | text | text |  |

Subitem columns (Subitems of Merav - All Operation): Owner [people], Status [status], Date [date]

Automations (2):
- `active` WHEN item created ; ASSIGN (add) Merav Peretz to Person — by Bar Ohayon
- `active` WHEN Status changes to Done ; MOVE to group "Completed Tasks" — by Bar Ohayon

### Shoval - All Operations (10073337047)

- Items 177, kind private, workspace Main workspace, folder: (workspace root)
- Groups (order, colour): Triplex Group #808080, A1 Chimney #fdab3d, Everdoors #e2445c, Chimco #bb3354, Personal #757575, US Chimney #579bfc, US Chimney Supply - Done #00c875, A1 Investments - Done #00c875, A1 Chimney - Done #00c875, Crestline Home - Done #00c875, Speedy Garage Doors - Done #00c875, Air 75 - Done #00c875, Triplex - Done #00c875
- Views: Kanban (app view: dashboard/gantt/calendar/kanban), Primary View (TableBoardView), Table (TableBoardView), Timeline (app view: dashboard/gantt/calendar/kanban)

| Column | Monday type | EzBizi | Labels / settings |
|---|---|---|---|
| Person | people | person / assignees |  |
| Status | status | status | Received #007eb5; Working on it #fdab3d; Stuck #df2f4a; Done* #00c875; Future Project #9aadbd; Files #333333; HOLD #bb3354; Urgent #ff007f; Pending #9d50dd |
| Text | text | text |  |
| Date | date | date |  |
| Subitems | subtasks | subtasks |  |
| Timeline | timeline | timeline |  |

Subitem columns (Subitems of Shoval - All Operations): Owner [people], Status [status], Status 1 [status], Timeline [timeline], Price | Qy [text]

Automations (5):
- `active` WHEN Status changes to Done ; MOVE to group "Triplex - Done" ; IF item in group "Triplex Group" — by Bar Ohayon
- `active` WHEN Status changes to Done ; MOVE to group "A1 Chimney - Done" ; IF item in group "A1 Chimney" — by Bar Ohayon
- `active` WHEN Status changes to Done ; MOVE to group "Speedy Garage Doors - Done" ; IF item in group "Everdoors" — by Bar Ohayon
- `active` WHEN Status changes to Done ; MOVE to group "Crestline Home - Done" ; IF item in group "group_mkvnvs54" — by Bar Ohayon
- `active` WHEN Status changes to Done ; MOVE to group "US Chimney Supply - Done" ; IF item in group "group_mkvtwd8h" — by Bar Ohayon

### Toula Elwell (18393449992) — ARCHIVED on Monday

- Items 167, kind private, workspace Main workspace, folder: (workspace root)
- Groups (order, colour): Open Tasks #ff642e, Recurring Tasks #579bfc, Completed Tasks #00c875
- Views: Table (TableBoardView)

| Column | Monday type | EzBizi | Labels / settings |
|---|---|---|---|
| Subitems | subtasks | subtasks |  |
| Person | people | person / assignees |  |
| Status | status | status | Received #007eb5; Working on it #fdab3d; Stuck #df2f4a; Done* #00c875; Sent #c4c4c4; ASAP #ffcb00; Guidelines #784bd1; Weekly #579bfc; Hold #757575; Files #9d99b9; Monthly #faa1f1; Permanent #037f4c; Self-Assigned #ff007f |
| Priority | status | status | Critical ⚠️️ #333333; High #401694; Medium #5559df; Low #579bfc; None #c4c4c4; On Hold* #ffcb00 |
| Date | date | date |  |
| Due Date | date | date | with time |

Subitem columns (Subitems of Toula Elwell): Owner [people], Category [status], Status [status], Date [date]

Automations (2):
- `auto_disabled_by_board` WHEN item created ; ASSIGN (add) Jose Demasi to Person — by Jose Demasi
- `auto_disabled_by_board` WHEN Status changes to Done ; MOVE to group "Completed Tasks" ; NOTIFY Bar Ohayon: "Hi the status for "{18393449992.pulse.name}" changed to "{18393449992.pulse.status}". " — by Jose Demasi

### Ohio Project Management (18432743116)

- Items 166, kind private, workspace Main workspace, folder: A1 Chimney (DARK_ORANGE) / Field Management / Project Management
- Groups (order, colour): New Group #007eb5, Unscheduled - Waiting Review - Cleveland #579bfc, Unscheduled - Waiting Review - Columbus / Cincinnati #9cd326, Unscheduled - Ready To Schedule - Cleveland #ff642e, Unscheduled - Ready To Schedule - Columbus / Cincinnati #ffcb00, Scheduled #00c875, In Progress #579bfc, In Progress - Need Another Visit #579bfc, Done - Waiting Payment #ffcb00, PROCESS BY EXTARNEL COMPNEY #df2f4a, Suppliers / Contarctors #cab641, Completed #00c875
- Views: Table (TableBoardView), Kanban (KanbanBoardView), Calendar (CalendarBoardView), Kanban (KanbanBoardView), Dashboard (app view: dashboard/gantt/calendar/kanban), Gantt (app view: dashboard/gantt/calendar/kanban)

| Column | Monday type | EzBizi | Labels / settings |
|---|---|---|---|
| Subitems | subtasks | subtasks |  |
| Area | status | status | Cleveland #401694; Columbus #fdab3d; Cincinnati* #7f5347; Youngstown #df2f4a |
| Job Status | status | status | Working on it #fdab3d; Done & Paid* #00c875; Stuck #df2f4a; Waiting Parts #007eb5; Looking For Contractor #9d50dd; Need Measurments #037f4c; Done - Waiting Payment #579bfc; CANCEL #cab641; Scheduled #ff6d3b; In Progress #333333; no answer #bb3354; Waiting On Down Payment #ff007f; Updated Customer #784bd1 |
| Job Type Label | status | status | Working on it #fdab3d; Done* #00c875; Stuck #df2f4a; Masonry #007eb5; Piping #9d50dd; Chase Cover #037f4c; NO ANSWER IT #579bfc; electric #cab641; Damper / Cap #ffcb00; REDO #333333; PCR #bb3354 |
| Need To Be Assign To: | status | status | hactor #ffcb00; Kenni Repair Team* #00c875; Gas sub #df2f4a; Andrew Repair Team #007eb5; by tech #4eccc6; Brayson #037f4c; RUBEN #579bfc; JOSHE #cab641; KITHE #fdab3d; TRAVIS #333333; kalum #bb3354; paiton #ff007f; Max #ff5ac4; jeff #784bd1; marthin #9cd326 |
| Parts Status | status | status | Ordered #fdab3d; In stuck* #00c875; Not ordered #df2f4a; unclear #9d50dd; DISPUTE #bb3354; CANCEL #579bfc; Stop Payment #cab641; Refund #037f4c; Part Is Here #74afcc |
| Production job | checkbox | checkbox |  |
| Job Amount | text | text |  |
| Amount Due | text | text |  |
| Sale Date | date | date |  |
| Timeline | timeline | timeline |  |
| Schedule Date | date | date |  |
| Alternative Schedule Date | date | date |  |
| Time Slot | text | text |  |
| Items | text | text |  |
| Job Cost | numbers | number/currency | unit $ |
| Total Cost | formula | text snapshot (GAP: formula) | `IF({boolean_mm8rsxyc}, (VALUE({text_mm7xd98r}) * 0.1) + {numeric_mm8an2y8}, {numeric_mm8an2y8})` |

Subitem columns (Subitems of Ohio Project Management): Date [date], Numbers [numbers], Files [file]

Automations: none.
- `active` (workflow builder) When Schedule Date arrives and only if item is in Scheduled, move item to In Progress — by Chaim Perez

### Users (18280738661)

- Items 149, kind public, workspace Main workspace, folder: (workspace root)
- Groups (order, colour): Users #579bfc
- Views: Main table only

| Column | Monday type | EzBizi | Labels / settings |
|---|---|---|---|
| Status | status | status | Pending #fdab3d; Active #00c875; Inactive #df2f4a |
| monday.com user | people | person / assignees |  |
| Teams | people | person / assignees |  |
| Email | email | email |  |
| Job Roles | board_relation | text snapshot (GAP: connect boards) |  |
| Base role | numbers | number/currency |  |
| Skills | dropdown | tags |  |
| Location | dropdown | tags |  |
| Resource manager | people | person / assignees |  |

Automations: none.

### A1 - Operatrion (8312752945)

- Items 137, kind private, workspace Main workspace, folder: A1 Chimney (DARK_ORANGE)
- Groups (order, colour): Company Files #ff5ac4, Ad Sources Companies Files #7f5347, Agreements #fdab3d, Employees & Contracts #808080, Franchise’s Information #784bd1, Company Record #7f5347, Completed #00c875
- Views: Primary View (TableBoardView)

| Column | Monday type | EzBizi | Labels / settings |
|---|---|---|---|
| Subitems | subtasks | subtasks |  |
| Person | people | person / assignees |  |
| Status | status | status | Received #007eb5; Working on it #fdab3d; Stuck #df2f4a; Pending #7e3b8a; Done #00c875 |
| Text | text | text |  |
| Bar - All Operations | board_relation | text snapshot (GAP: connect boards) |  |
| Date | date | date |  |
| Timeline | timeline | timeline |  |

Subitem columns (Subitems of A1 - Operatrion): Status 1 [status], Status [status], Owner [people], Text [text], Text 1 [text], Timeline [timeline]

Automations (8):
- `deactivated` WHEN item created ; SET date_1_mkmfk9a7 to today ; PUSH date_1_mkmfk9a7 by {'days': 7} — by Shoval Ohayon
- `deactivated` WHEN date_1_mkmfk9a7 arrives 1 days before at 15:00 ET ; NOTIFY the item's people: "Hi {Task’s Person}, This is a friendly reminder that your task is due tomorrow. Please ensure the following task is completed by {8312752945.pulse.date_1_mkmfk9" — by Shoval Ohayon
- `active` WHEN Status changes to Done ; MOVE to group "Completed" — by Bar Ohayon
- `active` WHEN item created ; IF item in group "topics" ; ASSIGN (add) Bar Ohayon to Person — by Shoval Ohayon
- `active` WHEN item created ; IF item in group "new_group_mkmf8nr4" ; ASSIGN (add) Chaim Perez to Person — by Shoval Ohayon
- `active` WHEN item created ; IF item in group "new_group_mkmfcet7" ; ASSIGN (add) Shoval Ohayon to Person — by Shoval Ohayon
- `error` When any column changes, send a webhook {} — by Shoval Ohayon
- `active` WHEN item created ; IF item in group "topics" ; CREATE item on "Bar - All Operations" group "A1 Chimney" copying ['name', 'status', 'person'], link via connect column — by Bar Ohayon

### MA - Massachusetts (18435169766)

- Items 133, kind private, workspace Main workspace, folder: A1 Chimney (DARK_ORANGE) / Field Management / Operations Management
- Groups (order, colour): Company Info #bb3354, 📋 Administration Board - Eve #66ccff, Accounting - Esti #579bfc, Field Manager - Claire #ff5ac4, Project Manager - Nate/ Claire #7f5347, Customer Relations - Natalie #bb3354, Done #00c875
- Views: Main table only

| Column | Monday type | EzBizi | Labels / settings |
|---|---|---|---|
| Subitems | subtasks | subtasks |  |
| Person | people | person / assignees |  |
| Status | status | status | Received #007eb5; NO ANSWER #401694; Working on it #fdab3d; Stuck #df2f4a; Done* #00c875; NOT INTERESTED #9d50dd; PENDING #579bfc; COMPANY INFO #ff7575; SPAM #4eccc6; WRONG NUMBER #ff6d3b; Booked #66ccff; Confirmed #ffcb00; FOLLOW-UP REQUIRED #ff5ac4; MISSED #bb3354; Files #757575 |
| Area | status | status | Massachusetts #ff007f; DMV #225091; Connecticut #cab641; New Jersey #ff6d3b; Illinois #4eccc6; Nationwide #563e3e; Texas #037f4c; Ohio #74afcc; Georgia #cd9282; Wisonsin #ffcb00; Detroit #9d50dd; Washington #bb3354 |
| Reason | status | status | Complaint #df2f4a; Vendor Collabrations #9aadbd; Net 30 Request #74afcc; Bid Request #7e3b8a; Deposit Job #9cd326; Inspection Report #00c875; Invoice Request #784bd1; Same Day Job #ff7575; Estimate Inquiry #579bfc; Phone / Email Estimate #037f4c; Cancel #333333; Reschedule #9d50dd; Call To Book #007eb5; Missed Call #bb3354; No Answer #ff5ac4; Missed Voicemail #bda8f9; Waiting Payment #66ccff; Issue Refund #563e3e; COI / W9 Request #faa1f1; Other #175a63; ETA* #ffcb00; Parts #fdab3d; DEPOSIT #4eccc6; Pending Jobs #ff6d3b; Email Updated #cab641; Missed Voicemail #225091; redo requested #e484bd |
| Resolution | status | status | Working on it #fdab3d; Done* #00c875; Stuck #df2f4a; Redo schedule needed #c4c4c4; redo completed #007eb5; non payment -  Agreed #9d50dd; sent #037f4c; still in review #579bfc; stopped payment #cab641; disputed- challenging #ffcb00; sch repair team #333333; job canceled- FULL REFUND #bb3354; Job canceled- partial refund #ff007f; sent documents #ff5ac4; pending #784bd1; client satisfied #9cd326; discount #66ccff; Apologetic outreach #757575; installation date needed #7f5347; requested GM Calibrated #ff6d3b; request refund #ff7575; DUPLICATE #faa1f1; OM Review & agreement #ffadad; Job Canceled #9aadbd |
| Notes | text | text |  |
| Date Received | date | date |  |
| Last Contact Date | date | date |  |
| Resolution Date | date | date |  |

Subitem columns (Subitems of MA - Massachusetts): Owner [people], Status [status], Date [date]

Automations (1):
- `active` WHEN Status changes to Done ; MOVE to group "Done" — by Eve Sarfaty

### Illinois Project Management (18435687196)

- Items 116, kind private, workspace Main workspace, folder: A1 Chimney (DARK_ORANGE) / Field Management / Project Management
- Groups (order, colour): Unscheduled - Waiting Review - Chicago #579bfc, Unscheduled - Ready To Schedule - Chicago #ff642e, Unscheduled - Waiting Review - Indiana/Wisconsin #9cd326, Unscheduled - Ready To Schedule - Indiana/Wisconsin #ffcb00, Masonry Job #bb3354, Saen Scheduled #00c875, Hynk constriction #bb3354, Suppliers / Contarctors #cab641, Team 1 #579bfc, Team 2 #757575, Miguel jobs #757575, REQUESTED REFOUND #cab641, suppliers #df2f4a, PROCESS BY EXTARNEL COMPNEY #df2f4a, Done - Waiting Payment #ffcb00, Completed #00c875
- Views: Table (TableBoardView), Kanban (app view: dashboard/gantt/calendar/kanban), Calendar (app view: dashboard/gantt/calendar/kanban), Kanban (app view: dashboard/gantt/calendar/kanban)

| Column | Monday type | EzBizi | Labels / settings |
|---|---|---|---|
| Subitems | subtasks | subtasks |  |
| Area | status | status | Chicago #fdab3d; Indiana #401694; Wisconsin* #7f5347 |
| Job Type Label | status | status | Working on it #fdab3d; Done* #00c875; GAS #df2f4a; Masonry #007eb5; Piping #9d50dd; Chase Cover #037f4c; NO ANSWER IT #579bfc; flashing #ffcb00; liner #cab641; cap damper #333333; Burner #66ccff; Heatshield #9cd326; Log System #bb3354; Fireplace #784bd1; Smoke Chamber #ffadad |
| Job Status | status | status | NEEDS TO ORDER PARTS #ffcb00; Done & Paid* #00c875; Stuck #df2f4a; Waiting Parts #007eb5; Looking For Contractor #9d50dd; Need Measurments #037f4c; Done - Waiting Payment #579bfc; CANCEL #cab641; Scheduled #ff6d3b; In Progress #333333; no answer #bb3354; Not Worth to do the job #ff007f; Adeline/Refund #401694; Waiting Cx confirmation #bca58a; Need date #faa1f1; MISSING UPDATE/CLOSING #66ccff; Need date - URGENT #7e3b8a |
| Need To Be Assign To: | status | status | Javi Team repair #ffcb00; Tzino Repair Team* #00c875; Gas sub #df2f4a; Andrew Repair Team #007eb5; by tech #4eccc6; Brayson #037f4c; RUBEN #579bfc; JOSHE #cab641; KITHE #fdab3d; TRAVIS #333333; masonry sub #ff007f |
| Parts Status | status | status | Ordered #fdab3d; In stuck* #00c875; Not ordered #df2f4a; NEED LIFT #333333; unclear #9d50dd; DISPUTE #bb3354; CANCEL #579bfc; Stop Payment #cab641; Refund #037f4c |
| Job Amount | text | text |  |
| Amount Due | text | text |  |
| Sale Date | date | date |  |
| Schedule Date | date | date |  |
| Time Slot | text | text |  |
| Alternative Schedule Date | date | date |  |
| Parts Arrivel Date | date | date |  |
| Items | text | text |  |
| Job Cost | text | text |  |

Subitem columns (Subitems of Illinois Project Management): Owner [people], Status [status], Date [date]

Automations: none.

### Bar - All Operations (9709588999)

- Items 116, kind private, workspace Main workspace, folder: (workspace root)
- Groups (order, colour): A1 Chimney #fdab3d, Everdoors #e2445c, Chimco #df2f4a, Done #00c875
- Views: Kanban (app view: dashboard/gantt/calendar/kanban), Primary View (TableBoardView), Shared Items (SppBoardView), Table (TableBoardView)

| Column | Monday type | EzBizi | Labels / settings |
|---|---|---|---|
| Item Locations 🔒 | file | files on a "Files from Monday" update (GAP: files column) |  |
| Person | people | person / assignees |  |
| Status | status | status | Received #007eb5; Working on it #fdab3d; Stuck #df2f4a; Done* #00c875; Urgent #ff7575; Pending #9d50dd; HOLD #bb3354 |
| Priority | status | status | Critical ⚠️️ #df2f4a; High #ff6d3b; Medium #ffcb00; Low #579bfc |
| Text | text | text |  |
| Due Date | date | date |  |
| Subitems | subtasks | subtasks |  |
| Timeline | timeline | timeline |  |

Subitem columns (Subitems of Bar - All Operations): Owner [people], Status [status], Priority [status], Date [date], Text [text]

Automations (1):
- `active` WHEN Status changes to Done ; MOVE to group "Done" — by Bar Ohayon

### Chaim - All Operations (10040982416)

- Items 106, kind private, workspace Main workspace, folder: (workspace root)
- Groups (order, colour): Triplex Group #757575, A1 Chimney #ffcb00, Everdoors #bb3354, Chimco #df2f4a, Locations #ff642e, Done #00c875
- Views: Kanban (app view: dashboard/gantt/calendar/kanban), Table (TableBoardView)

| Column | Monday type | EzBizi | Labels / settings |
|---|---|---|---|
| Person | people | person / assignees |  |
| Status | status | status | Received #007eb5; Working on it #fdab3d; Stuck #df2f4a; Done* #00c875; Waiting cloudia #579bfc; Files #cab641; HOLD #bb3354; Pending #9d50dd |
| Text | text | text |  |
| Subitems | subtasks | subtasks |  |
| Company | status | status | Working on it #fdab3d; Done* #00c875; Stuck #df2f4a; A1 Chimney #007eb5; Crestline Home #9d50dd; Everdoors #037f4c; AngiSweep #ffcb00; Chimney Star #bb3354 |
| Timeline | timeline | timeline |  |

Subitem columns (Subitems of Chaim - All Operations): Owner [people], Status [status], Priority [status], Date [date], Text [text], Locations Amount [text]

Automations (1):
- `active` WHEN Status changes to Done ; MOVE to group "Done" — by Bar Ohayon

### Jose Demasi (8343765247)

- Items 102, kind private, workspace Main workspace, folder: A1 Chimney (DARK_ORANGE) / Marketing
- Groups (order, colour): Open Tasks #ff642e, EzBiZi - Done #579bfc, Company Files #c4c4c4, Completed Tasks #00c875
- Views: Table (TableBoardView)

| Column | Monday type | EzBizi | Labels / settings |
|---|---|---|---|
| Subitems | subtasks | subtasks |  |
| Person | people | person / assignees |  |
| Status | status | status | Received #007eb5; Working on it #fdab3d; Stuck #df2f4a; Done* #00c875; Done - Testing #ffcb00; Guidelines #784bd1; Pending #579bfc; Hold #ff007f; Urgent #bb3354; Done - Pending Review #037f4c |
| Timeline | timeline | timeline |  |
| Text | text | text |  |

Subitem columns (Subitems of Jose Demasi): Owner [people], Timeline [timeline], Status [status], Category [status], Date [date], Text [text]

Automations (4):
- `deactivated` WHEN date_1_mkmfk9a7 arrives 1 days before at 15:00 ET ; NOTIFY the item's people: "Hi {Task’s Person}, This is a friendly reminder that your task is due tomorrow. Please ensure the following task is completed by {8343765247.pulse.date_1_mkmfk9" — by Shoval Ohayon
- `active` WHEN Status changes to Done ; MOVE to group "Completed Tasks" ; NOTIFY Bar Ohayon: "Hi the status for "{8343765247.pulse.name}" changed to "{8343765247.pulse.status}". " — by Shoval Ohayon
- `deactivated` WHEN item created ; SET date_1_mkmfk9a7 to today ; PUSH date_1_mkmfk9a7 by {'days': 7} — by Shoval Ohayon
- `active` WHEN item created ; ASSIGN (add) Jose Demasi to Person — by Bar Ohayon

### Everdoors - Operation (8508945126)

- Items 92, kind private, workspace Main workspace, folder: Everdoors (DONE_GREEN)
- Groups (order, colour): Company Files #7f5347, Agreements #df2f4a, Everdoors IP #66ccff, Everdoors (US Garage Previously) #037f4c, A1 Garage Doors #808080, Speedy Garage Doors #a25ddc, Premier Garage Door Repair #9cd326, Completed #00c875
- Views: Calendar (app view: dashboard/gantt/calendar/kanban)

| Column | Monday type | EzBizi | Labels / settings |
|---|---|---|---|
| Subitems | subtasks | subtasks |  |
| Person | people | person / assignees |  |
| Status | status | status | Received #007eb5; Working on it #fdab3d; Stuck #df2f4a; Pending #9d50dd; Done* #00c875; Daily Task #037f4c; Suspended #bb3354 |
| Priority | status | status | Critical ⚠️️ #333333; High #401694; Medium #5559df; Low #579bfc |
| Text | text | text |  |
| Date | date | date |  |

Subitem columns (Subitems of Everdoors - Operation): Owner [people], Status [status], Priority [status], Text [text], Date [date]

Automations (2):
- `active` WHEN Status changes to Done ; MOVE to group "Completed" — by Bar Ohayon
- `active` WHEN item created ; IF item in group "topics" ; CREATE item on "Bar - All Operations" group "Everdoors" copying ['name', 'status', 'person'], link via connect column — by Bar Ohayon

### Rodrigo Mena (9204581187)

- Items 62, kind private, workspace Main workspace, folder: A1 Chimney (DARK_ORANGE) / Marketing
- Groups (order, colour): Open Tasks #ff642e, Recurring Tasks #579bfc, Completed Tasks #00c875
- Views: Table (TableBoardView)

| Column | Monday type | EzBizi | Labels / settings |
|---|---|---|---|
| Subitems | subtasks | subtasks |  |
| Person | people | person / assignees |  |
| Status | status | status | Received #007eb5; Working on it #fdab3d; Stuck #df2f4a; Done* #00c875; Weekly Task #9d50dd; Monthly Task #037f4c |

Subitem columns (Subitems of Rodrigo Mena): Link [text], Owner [people], Status [status], Date [date]

Automations (2):
- `active` WHEN Status changes to Done ; MOVE to group "Completed Tasks" — by Bar Ohayon
- `active` WHEN item created ; ASSIGN (add) Rodrigo Mena to Person — by Bar Ohayon

### Rotem - Texas (8346436834)

- Items 52, kind private, workspace Main workspace, folder: A1 Chimney (DARK_ORANGE) / Partners
- Groups (order, colour): Open Tasks #ff642e, Company Files #ff5ac4, Reccuring Tasks #007eb5, Completed Tasks #00c875
- Views: Main table only

| Column | Monday type | EzBizi | Labels / settings |
|---|---|---|---|
| Person | people | person / assignees |  |
| Status | status | status | Received #007eb5; Weekly Task #9d50dd; Working on it #fdab3d; Stuck #df2f4a; Done* #00c875; Monthly Task #037f4c; Pending #ffcb00 |
| Subitems | subtasks | subtasks |  |
| Timeline | timeline | timeline |  |
| Text | text | text |  |
| Date | date | date |  |

Subitem columns (Subitems of Rotem - Texas): Owner [people], Status [status], Date [date], Link [text]

Automations (6):
- `active` WHEN Status changes to Done ; MOVE to group "Completed Tasks" — by Bar Ohayon
- `deactivated` WHEN date_1_mkmfk9a7 arrives 1 days before at 15:00 ET ; NOTIFY the item's people: "Hi {Task’s Person}, This is a friendly reminder that your task is due tomorrow. Please ensure the following task is completed by {8346436834.pulse.date_1_mkmfk9" — by Bar Ohayon
- `deactivated` WHEN item created ; SET date_1_mkmfk9a7 to today ; PUSH date_1_mkmfk9a7 by {'days': 7} — by Bar Ohayon
- `active` WHEN item created ; ASSIGN (add) Rotem Hadad to Person — by Shoval Ohayon
- `active` EVERY Weekly days=1 at 15:00 ET ; NOTIFY Rotem Hadad: "Please make sure to complete your weeekly tasks." — by Bar Ohayon
- `active` WHEN Date arrives at 13:00 ET ; IF item in group "Reccuring Tasks" ; NOTIFY Rotem Hadad: "Please make sure to maintain your vehicle: Get an oil change sticker proof, invoice and upload to vehicle list on Monaday.com." ; PUSH Date by {'months': '1'} ; IF Date isnot empty ; IF Status is Monthly Task — by Bar Ohayon

### Sharper - Rise-OFFICE (18427251856)

- Items 48, kind private, workspace Main workspace, folder: (workspace root)
- Groups (order, colour): AGENDA #579bfc, The FOUR #a25ddc, 90 Day F.A.S.T. Goals #037f4c, To-Do's 1-7 Days #66ccff, Long Term To-Do's 7-30 Days #579bfc, STOPLIGHT REVIEW #bb3354, ISSUES / DISCUSSIONS  1-3-1 #fdab3d, One Page Plan #ff642e, Opportunities to Explore #00c875, Helpful Links & Info #037f4c, CSO TOPICS / QUESTIONS #66ccff, Sharper Talent--Hiring #757575
- Views: Main table only

| Column | Monday type | EzBizi | Labels / settings |
|---|---|---|---|
| Subitems | subtasks | subtasks |  |
| Person | people | person / assignees |  |
| Status | status | status | On Target #fdab3d; Stuck #df2f4a; Done* #00c875; Files #333333; In Progress #ffcb00; Hold #bb3354 |
| Date | date | date |  |

Subitem columns (Subitems of Sharper - Rise-OFFICE): Owner [people], Status [status], Date [date]

Automations: none.

### Illinois (8346438973)

- Items 48, kind private, workspace Main workspace, folder: A1 Chimney (DARK_ORANGE) / Partners
- Groups (order, colour): Open Tasks #ff642e, Company Files #808080, Reccuring Tasks #007eb5, Completed Tasks #00c875
- Views: Main table only

| Column | Monday type | EzBizi | Labels / settings |
|---|---|---|---|
| Subitems | subtasks | subtasks |  |
| Person | people | person / assignees |  |
| Status | status | status | Received #007eb5; Weekly Task #9d50dd; Working on it #fdab3d; Stuck #df2f4a; Done* #00c875; Default #c4c4c4; Pending #784bd1; Monthly Task #037f4c; HOLD #bb3354 |
| Timeline | timeline | timeline |  |
| Text | text | text |  |
| Date | date | date |  |

Subitem columns (Subitems of Illinois): Owner [people], Status [status], Date [date]

Automations (6):
- `active` WHEN Status changes to Done ; MOVE to group "Completed Tasks" — by Bar Ohayon
- `deactivated` WHEN date_1_mkmfk9a7 arrives 1 days before at 15:00 ET ; NOTIFY the item's people: "Hi {Task’s Person}, This is a friendly reminder that your task is due tomorrow. Please ensure the following task is completed by {8346438973.pulse.date_1_mkmfk9" — by Bar Ohayon
- `deactivated` WHEN item created ; SET date_1_mkmfk9a7 to today ; PUSH date_1_mkmfk9a7 by {'days': 7} — by Bar Ohayon
- `active` WHEN item created ; ASSIGN (add) Moshe Hay Zion to Person — by Shoval Ohayon
- `active` EVERY Weekly days=1 at 15:00 ET ; NOTIFY Moshe Hay Zion: "Please make sure to complete your weeekly tasks." — by Bar Ohayon
- `active` WHEN Date arrives at 13:00 ET ; IF item in group "Reccuring Tasks" ; NOTIFY Moshe Hay Zion: "Please make sure to maintain your vehicle: Get an oil change sticker proof, invoice and upload to vehicle list on Monaday.com." ; PUSH Date by {'months': '1'} ; IF Date isnot empty ; IF Status is Monthly Task — by Bar Ohayon

### Analorena - Business Relations (8343738207)

- Items 47, kind private, workspace Main workspace, folder: A1 Chimney (DARK_ORANGE) / Office Management
- Groups (order, colour): Dispatch Tracking #037f4c, Open Tasks #ff642e, Reccuring Tasks #579bfc, Company Files #9cd326, Completed Tasks #00c875
- Views: Table (TableBoardView)

| Column | Monday type | EzBizi | Labels / settings |
|---|---|---|---|
| Subitems | subtasks | subtasks |  |
| Person | people | person / assignees |  |
| Task Date | status | status | Received #007eb5; Working on it #fdab3d; Stuck #df2f4a; Done* #00c875; Daily & Weekly Task #faa1f1; Pending #ff007f; Daily Task #9d50dd; Weekly Task #037f4c; Files #333333; Hold #bb3354; Waiting Delivery #ffcb00; IN PRODUCTION #401694 |
| Status | status | status | Working on it #fdab3d; Done* #00c875; Stuck #df2f4a |
| Timeline | timeline | timeline |  |
| Reminder | status | status | Done* #00c875; Reminder #df2f4a; Daily #579bfc |
| Due Date | date | date |  |

Subitem columns (Subitems of Analorena - Business Relations): Owner [people], Status [status], Status [status], Date [date], Late Tally [numbers]

Automations (3):
- `active` WHEN Task Date changes to Done ; MOVE to group "Completed Tasks" ; NOTIFY Bar Ohayon: "Hi the following task is done. {8343738207.pulse.name}" — by Shoval Ohayon
- `active` WHEN item created ; ASSIGN (add) user 76893540 to Person — by Bar Ohayon
- `active` WHEN Due Date arrives at 21.25:00 ET ; IF item in group "group_mkrmq3we" ; NOTIFY user 76893540: "Hi David, Please make sure to check the following tasks: {8343738207.pulse.name} Thank you." ; SET Due Date to today ; SET Reminder to Reminder ; IF Reminder is Done — by Bar Ohayon

### Meni - New Jersey (8346429475)

- Items 46, kind private, workspace Main workspace, folder: A1 Chimney (DARK_ORANGE) / Partners
- Groups (order, colour): Open Tasks #ff642e, Company Files #ff158a, Reccuring Tasks #007eb5, Completed Tasks #00c875
- Views: Main table only

| Column | Monday type | EzBizi | Labels / settings |
|---|---|---|---|
| Person | people | person / assignees |  |
| Status | status | status | Received #007eb5; Weekly Task #9d50dd; Working on it #fdab3d; Stuck #df2f4a; Done* #00c875; Pending #784bd1; Monthly Task #037f4c; HOLD #bb3354 |
| Subitems | subtasks | subtasks |  |
| Timeline | timeline | timeline |  |
| Text | text | text |  |
| Date | date | date |  |

Subitem columns (Subitems of Meni - New Jersey): Owner [people], Status [status], Date [date]

Automations (4):
- `active` WHEN Status changes to Done ; MOVE to group "Completed Tasks" — by Bar Ohayon
- `active` WHEN item created ; ASSIGN (add) Menahem Maamari to Person — by Shoval Ohayon
- `active` EVERY Weekly days=1 at 15:00 ET ; NOTIFY Menahem Maamari: "Please make sure to complete your weeekly tasks." — by Bar Ohayon
- `active` WHEN Date arrives at 13:00 ET ; IF item in group "Reccuring Tasks" ; NOTIFY Menahem Maamari: "Please make sure to maintain your vehicle: Get an oil change sticker proof, invoice and upload to vehicle list on Monaday.com." ; PUSH Date by {'months': 1} ; IF Date isnot empty ; IF Status is Monthly Task — by Bar Ohayon

### Nehorai - Connecticut (8343603747)

- Items 44, kind private, workspace Main workspace, folder: A1 Chimney (DARK_ORANGE) / Partners
- Groups (order, colour): Open Tasks #ff642e, Company Files #66ccff, Reccuring Tasks #007eb5, Completed Tasks #00c875
- Views: Main table only

| Column | Monday type | EzBizi | Labels / settings |
|---|---|---|---|
| Person | people | person / assignees |  |
| Status | status | status | Received #007eb5; Weekly Task #9d50dd; Working on it #fdab3d; Stuck #df2f4a; Done* #00c875; Pending #ff6d3b; Monthly Task #037f4c; HOLD #bb3354 |
| Subitems | subtasks | subtasks |  |
| Timeline | timeline | timeline |  |
| Text | text | text |  |
| Date | date | date |  |

Subitem columns (Subitems of Nehorai - Connecticut): Owner [people], Status [status], Date [date]

Automations (4):
- `active` WHEN Status changes to Done ; MOVE to group "Completed Tasks" — by Shoval Ohayon
- `active` WHEN item created ; ASSIGN (add) Nehorai Maamari to Person — by Shoval Ohayon
- `active` EVERY Weekly days=1 at 15:00 ET ; NOTIFY Nehorai Maamari: "Please make sure to complete your weekly tasks." — by Bar Ohayon
- `active` WHEN Date arrives at 13:00 ET ; IF item in group "Reccuring Tasks" ; NOTIFY Nehorai Maamari: "Please make sure to maintain your vehicle: Get an oil change sticker proof, invoice and upload to vehicle list on Monaday.com." ; PUSH Date by {'months': 1} ; IF Date isnot empty ; IF Status is Monthly Task — by Bar Ohayon

### Ohio (8343560998)

- Items 42, kind private, workspace Main workspace, folder: A1 Chimney (DARK_ORANGE) / Partners
- Groups (order, colour): Open Tasks #ff642e, Company Files #cab641, Reccuring Tasks #007eb5, Completed Tasks #00c875
- Views: Main table only

| Column | Monday type | EzBizi | Labels / settings |
|---|---|---|---|
| Person | people | person / assignees |  |
| Status | status | status | Yahav #007eb5; Weekly Task #9d50dd; Working on it #fdab3d; Stuck #df2f4a; Done* #00c875; Monthly Task #037f4c; Pending #784bd1; HOLD #bb3354 |
| Subitems | subtasks | subtasks |  |
| Timeline | timeline | timeline |  |
| Text | text | text |  |
| Status 3 | status | status | Working on it #fdab3d; Done* #00c875; Stuck #df2f4a |
| Status 1 | status | status | Working on it #fdab3d; Done* #00c875; Stuck #df2f4a |
| Status 2 | status | status | Working on it #fdab3d; Done* #00c875; Stuck #df2f4a |

Subitem columns (Subitems of Ohio): Owner [people], Status [status], Date [date]

Automations (6):
- `deactivated` WHEN date_1_mkmfk9a7 arrives 1 days before at 15:00 ET ; NOTIFY the item's people: "Hi {Task’s Person}, This is a friendly reminder that your task is due tomorrow. Please ensure the following task is completed by {8343560998.pulse.date_1_mkmfk9" — by Shoval Ohayon
- `deactivated` WHEN item created ; SET date_1_mkmfk9a7 to today ; PUSH date_1_mkmfk9a7 by {'days': 7} — by Shoval Ohayon
- `active` WHEN Status changes to Done ; MOVE to group "Completed Tasks" — by Shoval Ohayon
- `active` WHEN item created ; ASSIGN (add) user 71550800 to Person — by Shoval Ohayon
- `active` EVERY Weekly days=1 at 15:00 ET ; NOTIFY user 71550800: "Please make sure to complete your weeekly tasks." — by Bar Ohayon
- `error` WHEN date_mksgvftr arrives at 13:00 ET ; IF item in group "Reccuring Tasks" ; NOTIFY user 71550800: "Please make sure to maintain your vehicle: Get an oil change sticker proof, invoice and upload to vehicle list on Monaday.com." ; PUSH date_mksgvftr by {'months': '1'} ; IF date_mksgvftr isnot empty ; IF Status is Monthly Task — by Bar Ohayon

### Brian Scheets - Recruiting (18432440457)

- Items 40, kind private, workspace Main workspace, folder: A1 Chimney (DARK_ORANGE) / Office Management
- Groups (order, colour): MA Hiring's #df2f4a, MD Hiring's #66ccff, GA Hiring's #9d50dd, CT Hiring's #cab641, NJ Hiring's #00c875, Company Files #757575, OH Hiring's #579bfc, IL Hiring's #ffcb00, TX Hiring's #bb3354, HQ Hiring's #bb3354, Completed Hirings #00c875
- Views: Main table only

| Column | Monday type | EzBizi | Labels / settings |
|---|---|---|---|
| Person | people | person / assignees |  |
| Status | status | status | Received #007eb5; Weekly Task #9d50dd; Working on it #fdab3d; HOLD #df2f4a; Done* #00c875; Pending #ff6d3b; Urgent #ff007f; Files #563e3e; Need to Pause #784bd1; Need To Post #9cd326; HOLD #579bfc |
| Due Date | date | date |  |
| Subitems | subtasks | subtasks |  |

Subitem columns (Subitems of Brian Scheets - Recruiting): Owner [people], Status [status], State [status], Date [date], Link [link]

Automations (1):
- `active` WHEN Status changes to Done ; MOVE to group "Completed Hirings" — by Shoval Ohayon

### Liraz - DMV (9595076383)

- Items 32, kind private, workspace Main workspace, folder: A1 Chimney (DARK_ORANGE) / Partners
- Groups (order, colour): Open Tasks #ff642e, Company Files #ff5ac4, Reccuring Tasks #007eb5, Completed Tasks #00c875
- Views: Main table only

| Column | Monday type | EzBizi | Labels / settings |
|---|---|---|---|
| Subitems | subtasks | subtasks |  |
| Person | people | person / assignees |  |
| Status | status | status | Received #007eb5; Weekly Task #9d50dd; Working on it #fdab3d; Stuck #df2f4a; Done* #00c875; Pending #784bd1; Monthly Task #037f4c; HOLD #bb3354 |
| Timeline | timeline | timeline |  |
| Text | text | text |  |
| Date | date | date |  |

Subitem columns (Subitems of Liraz - DMV): Owner [people], Status [status], Date [date]

Automations (4):
- `active` EVERY Weekly days=1 at 15:00 ET ; NOTIFY user 78455682: "Please make sure to complete your weeekly tasks." — by Bar Ohayon
- `active` WHEN item created ; ASSIGN (add) user 78455682, user 78455682 to Person — by Bar Ohayon
- `active` WHEN Status changes to Done ; MOVE to group "Completed Tasks" — by Bar Ohayon
- `active` WHEN Date arrives at 13:00 ET ; IF item in group "Reccuring Tasks" ; NOTIFY user 78455682: "Please make sure to maintain your vehicle: Get an oil change sticker proof, invoice and upload to vehicle list on Monaday.com." ; PUSH Date by {'months': '1'} ; IF Date isnot empty ; IF Status is Monthly Task — by Bar Ohayon

### Netanel - Georgia (9384010310)

- Items 32, kind private, workspace Main workspace, folder: A1 Chimney (DARK_ORANGE) / Partners
- Groups (order, colour): Open Tasks #ff642e, Company Files #ff5ac4, Reccuring Tasks #007eb5, Completed Tasks #00c875
- Views: Main table only

| Column | Monday type | EzBizi | Labels / settings |
|---|---|---|---|
| Person | people | person / assignees |  |
| Status | status | status | Received #007eb5; Weekly Task #9d50dd; Working on it #fdab3d; Stuck #df2f4a; Done* #00c875; Pending #784bd1; Monthly Task #037f4c; HOLD #bb3354 |
| Timeline | timeline | timeline |  |
| Subitems | subtasks | subtasks |  |
| Text | text | text |  |
| Date | date | date |  |

Subitem columns (Subitems of Netanel - Georgia): Owner [people], Status [status], Date [date]

Automations (4):
- `active` EVERY Weekly days=1 at 15:00 ET ; NOTIFY Netanel mullakand: "Please make sure to complete your weeekly tasks." — by Bar Ohayon
- `active` WHEN Status changes to Done ; MOVE to group "Completed Tasks" — by Bar Ohayon
- `active` WHEN item created ; ASSIGN (add) Netanel mullakand to Person — by Bar Ohayon
- `active` WHEN Date arrives at 13:00 ET ; IF item in group "Reccuring Tasks" ; NOTIFY Netanel mullakand: "Please make sure to maintain your vehicle: Get an oil change sticker proof, invoice and upload to vehicle list on Monaday.com." ; PUSH Date by {'months': '1'} ; IF Date isnot empty ; IF Status is Monthly Task — by Bar Ohayon

### Yosef (18404695787)

- Items 29, kind private, workspace Main workspace, folder: Everdoors (DONE_GREEN) / Partners
- Groups (order, colour): Open Tasks #ff642e, Reccuring Tasks #007eb5, Company Files #037f4c, Completed Tasks #00c875
- Views: Main table only

| Column | Monday type | EzBizi | Labels / settings |
|---|---|---|---|
| Person | people | person / assignees |  |
| Status | status | status | Received #007eb5; Weekly Task #9d50dd; Working on it #fdab3d; Stuck #df2f4a; Done* #00c875; Pending #175a63; Monthly Task #037f4c; Files #333333; HOLD #7e3b8a |
| Subitems | subtasks | subtasks |  |
| Text | text | text |  |
| Timeline | timeline | timeline |  |
| Date | date | date |  |

Subitem columns (Subitems of Yosef): Owner [people], Status [status], Date [date]

Automations (4):
- `active` EVERY Weekly days=1 at 15:00 ET ; NOTIFY Menahem Maamari: "Please make sure to complete your weeekly tasks." — by Bar Ohayon
- `error` WHEN item created ; ASSIGN (add) Menahem Maamari to Person — by Bar Ohayon
- `active` WHEN Status changes to Done ; MOVE to group "Completed Tasks" — by Bar Ohayon
- `active` WHEN Date arrives at 13:00 ET ; IF item in group "Reccuring Tasks" ; NOTIFY Menahem Maamari: "Please make sure to maintain your vehicle: Get an oil change sticker proof, invoice and upload to vehicle list on Monaday.com." ; PUSH Date by {'months': 1} ; IF Date isnot empty ; IF Status is Monthly Task — by Bar Ohayon

### Washingtom (18408491068)

- Items 28, kind private, workspace Main workspace, folder: A1 Chimney (DARK_ORANGE) / Partners
- Groups (order, colour): Open Tasks #00c875, Company Files #ff158a, Reccuring Tasks #007eb5, Completed Tasks #00c875
- Views: Main table only

| Column | Monday type | EzBizi | Labels / settings |
|---|---|---|---|
| Person | people | person / assignees |  |
| Status | status | status | Received #007eb5; Weekly Task #9d50dd; Working on it #fdab3d; Stuck #df2f4a; Done* #00c875; Pending #401694; Monthly Task #037f4c; HOLD #bb3354 |
| Timeline | timeline | timeline |  |
| Subitems | subtasks | subtasks |  |
| Text | text | text |  |
| Date | date | date |  |

Subitem columns (Subitems of Washingtom): Owner [people], Status [status], Date [date]

Automations (3):
- `active` WHEN Status changes to Done ; MOVE to group "Completed Tasks" — by Bar Ohayon
- `active` EVERY Weekly days=1 at 15:00 ET ; NOTIFY Menahem Maamari: "Please make sure to complete your weeekly tasks." — by Bar Ohayon
- `active` WHEN Date arrives at 13:00 ET ; IF item in group "Reccuring Tasks" ; NOTIFY Menahem Maamari: "Please make sure to maintain your vehicle: Get an oil change sticker proof, invoice and upload to vehicle list on Monaday.com." ; PUSH Date by {'months': 1} ; IF Date isnot empty ; IF Status is Monthly Task — by Bar Ohayon

### Triplex Group - Files (9516509549)

- Items 27, kind private, workspace Main workspace, folder: Triplex Group (WORKING_ORANGE)
- Groups (order, colour): Group Files #7f5347, Financials #df2f4a, Agreements #579bfc, Oporating Companies #ff642e, Properties #66ccff, American Friends Of Leshem Shamaim #ff5ac4, Completed #00c875
- Views: Calendar (app view: dashboard/gantt/calendar/kanban)

| Column | Monday type | EzBizi | Labels / settings |
|---|---|---|---|
| Subitems | subtasks | subtasks |  |
| Person | people | person / assignees |  |
| Status | status | status | Received #007eb5; Working on it #fdab3d; Stuck #df2f4a; Pending #9d50dd; Done #00c875 |
| Text | text | text |  |
| Assignment | status | status | Bar #333333; Shoval #9cd326; 🌴Chaim #4eccc6; Jose #225091; Rodrigo #175a63; David #fdab3d; Merav #faa1f1; David & Jose #579bfc; Local Partner #cd9282 |
| Date | date | date |  |

Subitem columns (Subitems of Triplex Group - Files): Person [people], Brand [status], Text [text], Date [date]

Automations (2):
- `auto_disabled_by_permission_settings` WHEN Status changes to Done ; MOVE to group "Completed" — by Bar Ohayon
- `auto_disabled_by_permission_settings` WHEN item created ; IF item in group "topics" ; CREATE item on "Bar - All Operations" group "group_mkvnkqdc" copying ['name', 'status', 'person'], link via connect column — by Bar Ohayon

### New Location Template (18390213000)

- Items 25, kind private, workspace Main workspace, folder: New Locations (AQUAMARINE)
- Groups (order, colour): New Location Checklist #ff642e
- Views: Calendar (app view: dashboard/gantt/calendar/kanban)

| Column | Monday type | EzBizi | Labels / settings |
|---|---|---|---|
| Subitems | subtasks | subtasks |  |
| Person | people | person / assignees |  |
| Status | status | status | Received #007eb5; Working on it #fdab3d; Stuck #df2f4a; Pending #9d50dd; Done #00c875; Hold #c4c4c4; Ready To Start #037f4c |
| Assignment | status | status | Bar #333333; Shoval #9cd326; 🌴Chaim #4eccc6; Jose #225091; Rodrigo #175a63; Ana #fdab3d; Merav #faa1f1; David & Jose #579bfc; Local Partner #cd9282; Chaim, Jose & Local Partner #00c875; Eve #bb3354; Local Partner & Merav #a1e3f6; Merav, Ana & Jose #784bd1; Bar & Local Partner #757575; Bar, Shoval & Chaim #563e3e; Eve & Local Partner #bca58a; Shoval & Eve #007eb5; All Members #cab641; Marketing Department #ff6d3b |
| Date | date | date |  |
| Text | text | text |  |
| Dependent On | dependency | text snapshot (GAP: dependency) |  |
| Timeline | timeline | timeline |  |

Subitem columns (Subitems of New Location Template): Person [people], Assignment [status], Status [status], Date [date]

Automations (1):
- `active` When status changes to something, change the status of its dependency to something {"statusColumn": {"boardId": 18390213000, "columnId": "status", "columnType": "color"}, "columnValue": {"index": 1}, "dependencyColumnId": {"boardId": 18390213000, "columnId": "dependency_mky3p6zr", "columnType": "dependency"}, "dependencyColumnValue": {"index": 6}} — by Bar Ohayon

### Everdoors - MA Franchise (18399940654)

- Items 24, kind private, workspace Main workspace, folder: Triplex Group (WORKING_ORANGE) / Yosef (CT, MA, RI, ME, NH)
- Groups (order, colour): Franchise Information Checklist #ff642e
- Views: Calendar (app view: dashboard/gantt/calendar/kanban)

| Column | Monday type | EzBizi | Labels / settings |
|---|---|---|---|
| Subitems | subtasks | subtasks |  |
| Person | people | person / assignees |  |
| Status | status | status | Received #007eb5; Working on it #fdab3d; Stuck #df2f4a; Pending #9d50dd; Done #00c875; Hold #c4c4c4; Ready To Start #037f4c |
| Assignment | status | status | Bar #333333; Shoval #9cd326; 🌴Chaim #4eccc6; Jose #225091; Rodrigo #175a63; Ana #fdab3d; Merav #faa1f1; David & Jose #579bfc; Local Partner #cd9282; Chaim, Jose & Local Partner #00c875; Eve #bb3354; Local Partner & Merav #a1e3f6; Merav, David & Jose #784bd1; Bar & Local Partner #757575; Bar, Shoval & Chaim #563e3e; Eve & Local Partner #bca58a; Shoval & Eve #007eb5; All Members #cab641; Chaim & Eve #ff6d3b; Owners, Eve & Merav #e484bd |
| Date | date | date |  |
| Text | text | text |  |
| Dependent On | dependency | text snapshot (GAP: dependency) |  |
| Timeline | timeline | timeline |  |

Subitem columns (Subitems of Everdoors - MA Franchise): Person [people], Assignment [status], Status [status], Date [date]

Automations (1):
- `active` When status changes to something, change the status of its dependency to something {"statusColumn": {"boardId": 18399940654, "columnId": "status", "columnType": "color"}, "columnValue": {"index": 1}, "dependencyColumnId": {"boardId": 18399940654, "columnId": "dependency_mky3p6zr", "columnType": "dependency"}, "dependencyColumnValue": {"index": 6}} — by Bar Ohayon

### New Franchise Template (18392356461)

- Items 24, kind private, workspace Main workspace, folder: New Locations (AQUAMARINE)
- Groups (order, colour): Franchise Information Checklist #ff642e
- Views: Calendar (app view: dashboard/gantt/calendar/kanban)

| Column | Monday type | EzBizi | Labels / settings |
|---|---|---|---|
| Subitems | subtasks | subtasks |  |
| Person | people | person / assignees |  |
| Status | status | status | Received #007eb5; Working on it #fdab3d; Stuck #df2f4a; Pending #9d50dd; Done #00c875; Hold #c4c4c4; Ready To Start #037f4c |
| Assignment | status | status | Bar #333333; Shoval #9cd326; 🌴Chaim #4eccc6; Jose #225091; Rodrigo #175a63; Ana #fdab3d; Merav #faa1f1; David & Jose #579bfc; Local Partner #cd9282; Chaim, Jose & Local Partner #00c875; Eve #bb3354; Local Partner & Merav #a1e3f6; Merav, Ana & Jose #784bd1; Bar & Local Partner #757575; Bar, Shoval & Chaim #563e3e; Eve & Local Partner #bca58a; Shoval & Eve #007eb5; All Members #cab641; Chaim & Eve #ff6d3b; Owners, Eve & Merav #e484bd |
| Date | date | date |  |
| Text | text | text |  |
| Dependent On | dependency | text snapshot (GAP: dependency) |  |
| Timeline | timeline | timeline |  |

Subitem columns (Subitems of New Franchise Template): Person [people], Assignment [status], Status [status], Date [date]

Automations (1):
- `active` When status changes to something, change the status of its dependency to something {"statusColumn": {"boardId": 18392356461, "columnId": "status", "columnType": "color"}, "columnValue": {"index": 1}, "dependencyColumnId": {"boardId": 18392356461, "columnId": "dependency_mky3p6zr", "columnType": "dependency"}, "dependencyColumnValue": {"index": 6}} — by Bar Ohayon

### Ron - Massachusetts (18409425845)

- Items 22, kind private, workspace Main workspace, folder: A1 Chimney (DARK_ORANGE) / Partners
- Groups (order, colour): Open Tasks #00c875, Company Files #ff158a, Reccuring Tasks #007eb5, Completed Tasks #00c875
- Views: Main table only

| Column | Monday type | EzBizi | Labels / settings |
|---|---|---|---|
| Person | people | person / assignees |  |
| Status | status | status | Received #007eb5; Weekly Task #9d50dd; Working on it #fdab3d; Stuck #df2f4a; Done* #00c875; Pending #784bd1; Monthly Task #037f4c; HOLD #bb3354; Files #333333 |
| Timeline | timeline | timeline |  |
| Subitems | subtasks | subtasks |  |
| Text | text | text |  |
| Date | date | date |  |

Subitem columns (Subitems of Ron - Massachusetts): Owner [people], Status [status], Date [date]

Automations (3):
- `active` EVERY Weekly days=1 at 15:00 ET ; NOTIFY Ron Paz: "Please make sure to complete your weekly tasks." — by Bar Ohayon
- `active` WHEN Status changes to Done ; MOVE to group "Completed Tasks" — by Bar Ohayon
- `active` WHEN Date arrives at 13:00 ET ; IF item in group "Reccuring Tasks" ; NOTIFY Ron Paz: "Please make sure to maintain your vehicle: Get an oil change sticker proof, invoice and upload to vehicle list on Monaday.com." ; PUSH Date by {'months': 1} ; IF Date isnot empty ; IF Status is Monthly Task — by Bar Ohayon

### Everdoors - Brand (18399940557)

- Items 16, kind private, workspace Main workspace, folder: New Locations (AQUAMARINE) / Everdoors (STUCK_RED)
- Groups (order, colour): Brand Information Checklist #ff642e
- Views: Calendar (app view: dashboard/gantt/calendar/kanban)

| Column | Monday type | EzBizi | Labels / settings |
|---|---|---|---|
| Subitems | subtasks | subtasks |  |
| Person | people | person / assignees |  |
| Status | status | status | Received #007eb5; Working on it #fdab3d; Stuck #df2f4a; Pending #9d50dd; Done #00c875; Hold #c4c4c4; Ready To Start #037f4c |
| Assignment | status | status | Bar #333333; Shoval #9cd326; 🌴Chaim #4eccc6; Jose #225091; Rodrigo #175a63; Ana #fdab3d; Merav #faa1f1; David & Jose #579bfc; Local Partner #cd9282; Chaim, Jose & Local Partner #00c875; Eve #bb3354; Local Partner & Merav #a1e3f6; Merav, David & Jose #784bd1; Bar & Local Partner #757575; Bar, Shoval & Chaim #563e3e; Eve & Local Partner #bca58a; Shoval & Eve #007eb5; All Members #cab641; Bar & Jose #ff6d3b |
| Date | date | date |  |
| Text | text | text |  |
| Dependent On | dependency | text snapshot (GAP: dependency) |  |
| Timeline | timeline | timeline |  |

Subitem columns (Subitems of Everdoors - Brand): Person [people], Assignment [status], Status [status], Date [date]

Automations (1):
- `active` When status changes to something, change the status of its dependency to something {"statusColumn": {"boardId": 18399940557, "columnId": "status", "columnType": "color"}, "columnValue": {"index": 1}, "dependencyColumnId": {"boardId": 18399940557, "columnId": "dependency_mky3p6zr", "columnType": "dependency"}, "dependencyColumnValue": {"index": 6}} — by Bar Ohayon

### New Brand Template (18392349013)

- Items 16, kind private, workspace Main workspace, folder: New Locations (AQUAMARINE)
- Groups (order, colour): Brand Information Checklist #ff642e
- Views: Calendar (app view: dashboard/gantt/calendar/kanban)

| Column | Monday type | EzBizi | Labels / settings |
|---|---|---|---|
| Subitems | subtasks | subtasks |  |
| Person | people | person / assignees |  |
| Status | status | status | Received #007eb5; Working on it #fdab3d; Stuck #df2f4a; Pending #9d50dd; Done #00c875; Hold #c4c4c4; Ready To Start #037f4c |
| Assignment | status | status | Bar #333333; Shoval #9cd326; 🌴Chaim #4eccc6; Jose #225091; Rodrigo #175a63; Ana #fdab3d; Merav #faa1f1; David & Jose #579bfc; Local Partner #cd9282; Chaim, Jose & Local Partner #00c875; Eve #bb3354; Local Partner & Merav #a1e3f6; Merav, Ana & Jose #784bd1; Bar & Local Partner #757575; Bar, Shoval & Chaim #563e3e; Eve & Local Partner #bca58a; Shoval & Eve #007eb5; All Members #cab641; Bar & Jose #ff6d3b |
| Date | date | date |  |
| Text | text | text |  |
| Dependent On | dependency | text snapshot (GAP: dependency) |  |
| Timeline | timeline | timeline |  |

Subitem columns (Subitems of New Brand Template): Person [people], Assignment [status], Status [status], Date [date]

Automations (1):
- `active` When status changes to something, change the status of its dependency to something {"statusColumn": {"boardId": 18392349013, "columnId": "status", "columnType": "color"}, "columnValue": {"index": 1}, "dependencyColumnId": {"boardId": 18392349013, "columnId": "dependency_mky3p6zr", "columnType": "dependency"}, "dependencyColumnValue": {"index": 6}} — by Bar Ohayon

### Analytics Dashboard (18426918714)

- Items 5, kind public, workspace dieuve ticoit Vibes, folder: Dark Theme
- Groups (order, colour): Group Title #037f4c
- Views: Main table only

| Column | Monday type | EzBizi | Labels / settings |
|---|---|---|---|
| Metric | text | text |  |
| Value | numbers | number/currency |  |
| Previous Value | numbers | number/currency |  |
| Change Percent | numbers | number/currency |  |
| Category | status | status | Revenue* #00c875; Users #df2f4a; Performance #007eb5 |
| Trend Data | long_text | long_text |  |

Automations: none.

### Jonathan - Recruiting (18431210057)

- Items 3, kind private, workspace Main workspace, folder: A1 Chimney (DARK_ORANGE) / Office Management
- Groups (order, colour): Company Files #c4c4c4, Completed Hirings #00c875
- Views: Main table only

| Column | Monday type | EzBizi | Labels / settings |
|---|---|---|---|
| Person | people | person / assignees |  |
| Status | status | status | Received #007eb5; Weekly Task #9d50dd; Working on it #fdab3d; Stuck #df2f4a; Done* #00c875; Pending #ff5ac4; Urgent #ff007f; CHIMCARE #579bfc; CITYLINE #ff7575; GENESIS #ffcb00; MAGNETIC #ffadad; Daily Task #7f5347; Files #563e3e; Paused #ff007f; Need To Pause #784bd1; Need To Post #9cd326 |
| Subitems | subtasks | subtasks |  |

Subitem columns (Subitems of Jonathan - Recruiting): Owner [people], Status [status], State [status], Date [date], Link [link]

Automations (1):
- `active` WHEN Status changes to Done ; MOVE to group "Completed Hirings" — by Shoval Ohayon

### Adeline - Customer Relations (18379051971)

- Items 2, kind private, workspace Main workspace, folder: A1 Chimney (DARK_ORANGE) / Office Management
- Groups (order, colour): Company Files #cab641, Reccuring Tasks #66ccff, Open Tasks #ff5ac4, Completed Tasks #00c875
- Views: Main table only

| Column | Monday type | EzBizi | Labels / settings |
|---|---|---|---|
| Person | people | person / assignees |  |
| Status | status | status | Received #007eb5; Weekly Task #9d50dd; Working on it #fdab3d; Stuck #df2f4a; Done* #00c875; Pending #ff6d3b; COMPANY  INFO #ff007f; CHIMCARE #579bfc; CITYLINE #ff7575; GENESIS #ffcb00; MAGNETIC #ffadad; Daily Task #7f5347 |
| Reminder | status | status | Done* #00c875; Reminder #df2f4a |
| Due Date | date | date |  |
| Link | link | url |  |
| Subitems | subtasks | subtasks |  |
| Subitems Link | mirror | text snapshot (GAP: mirror) |  |

Subitem columns (Subitems of Adeline - Customer Relations): Owner [people], Status [status], State [status], Date [date], Link [link]

Automations (1):
- `active` WHEN Status changes to Done ; MOVE to group "Completed Tasks" — by Shoval Ohayon

### Job Roles (18280738648)

- Items 0, kind public, workspace Main workspace, folder: (workspace root)
- Groups (order, colour): Job Roles #579bfc
- Views: Main table only

| Column | Monday type | EzBizi | Labels / settings |
|---|---|---|---|
| Billing rate | numbers | number/currency |  |
| Is active | status | status | Active* #00c875; Inactive #df2f4a |

Automations: none.
