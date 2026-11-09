# monday.com → EzBizi Task Board: capability port map

Task Board owner (Jobs session), 2026-11-09. This covers functionality only; UI-UX is doing look and feel.

## Sources

**Jose's monday account** (a-1group.monday.com, one "Main workspace"). Read through the monday MCP connector; nothing was changed, created or deleted.

Boards read:
- NW - Nationwide (24,572 items, 48 owners)
- Ohio Project Management (149 items)
- Adeline - Customer Relations
- Jose Demasi

Also read: their automations, views and subitem boards.

## Seen in the UI (Chrome, 2026-11-09, read-only)

I walked through Ohio Project Management in Jose's logged-in Chrome. I opened menus and panels and saved nothing. The only thing changed was closing a "what's new" popover. Rows marked 👁 in the table below were seen this way.

- **Views bar.** Main table, Table, Kanban, Calendar, Kanban, Dashboard and Gantt are saved as tabs on the board. (The MCP reported no views, so the API under-reports.) The "+" menu offers Table, Gantt, Chart, Calendar, Kanban, Canvas, Doc, File gallery, Form, Dashboard, Vibe apps and Apps.
- **Gantt.** Baseline, Auto Fit, a Days/Weeks zoom, and a saved filter on the view ("Filter / 1").
- **Dashboard view.** Chart widgets on the board, Add widget, Export, and scheduled PDF export by email.
- **Toolbar.** New item · Search · Person · Filter · Sort · Hide · Group by · more.
- **Quick filters.** One column per board column, each listing its values (All done labels / Working on it / …), plus "Showing all of 149 items" and "Save as new view".
- **Advanced filters.** Where *Column* *Condition* *Value* rows, "+ New filter", "+ New group" (nested AND/OR), and "Filter with AI".
- **Sort.** Column plus Ascending/Descending, and "Save as new view".
- **Group by.** Group items by any column with a sorting option, "Show empty groups", and "Save as new view".
- **Column header menu.** Settings ▸ (customize, add column description, status notifications, **set column as required**, column validation, **restrict column editing**, restrict column view, hide summary, save column as template) · AI auto-assign labels · Filter · Sort · Collapse · Group by · Duplicate column · Add column to the right ▸ (Status, Text, People, Timeline, Date, Tags, Numbers, More columns) · Change column type · Rename · Hide · Delete.
- **Group footers.** A status distribution bar under every status column, and the checkbox count "0/12".
- **Item panel.** Tabs Updates / Files / **Activity Log** / +.
  - Updates: mention, Like, Reply, seen count, "Update via email".
  - Activity Log: one row per change (column, from → to) with a **per-row Undo**, Filter log, Person filter, AI, and a separate "Automation activity" log.
  - "+" adds item views: Item card, Item description, Table, Chart, Gantt, File gallery, Dashboard, Emails & Activities.
- **Automations center.**
  - Manage tab: list with on/off, an importance ("Critical"), owner, description, **Run history**, My connections, Account usage.
  - Create tab: categories (AI-powered, 2-way sync, Productivity, Dates, Communication, Sync, Connected & mirror columns, Created by A1 Group) and Integrations / 44.
  - Recipes seen: "When an item is created assign creator", "When status changes notify", "When date arrives notify", "When status changes move to group", "When an email is received create an item", "When date arrives and only if status is … notify", **"Every time period create an item"**.

Not clicked: board permissions, templates, the inbox, keyboard shortcuts and form view. Those rows come from monday's feature set.

## What A-1 actually uses (this sets the priority)

| Signal | Observed |
|---|---|
| Column types in use | status (very heavy: NW has 4 status columns, "Reason" has 38 labels), people, date, text, link, subitems, mirror (1, subitem link), timeline, numbers ($ unit), checkbox, formula (`IF({Production job}, VALUE({Job Amount})*0.1 + {Job Cost}, {Job Cost})`), file (on subitems) |
| Not used | tags, dropdown, long text, phone, email, dependency, connect-boards, rating, time tracking, button, world clock |
| Deactivated labels | NW Status/Reason keep **deactivated** labels (PENDING, SPAM, Cancel, Parts…). Old items keep them and new picks hide them |
| Status "is done" | Each status label has `is_done`; "Done", "Done & Paid" and "ETA" are marked done |
| Groups as pipeline | Ohio PM groups: Unscheduled – Waiting Review (per area) → Ready To Schedule → Scheduled → In Progress → Need Another Visit → Done – Waiting Payment → Completed |
| Automations in use | (1) When Status → Done, move item to group Done/Completed (3 boards). (2) When an item is created, assign the creator to Person. (3) When an item is created in group X, assign someone (replace). (4) When Area changes to {Connecticut / NJ / Illinois / Texas / Ohio / Georgia / Wisconsin…}, only if the item is in group "Customer Relations - Adeline", **add** a specific person (one rule per area, 8 rules). (5) When Schedule Date arrives (00:00 America/New_York), only if the item is in group Scheduled, move it to In Progress |
| Board permissions | NW is `assignee` (people edit only items assigned to them); others are `everyone` |
| Item terminology | Per board: "Task", "Name & job#", "item" |
| Views | Mostly default Table only; Jose's board has one saved Table view with its own column order |
| Templates | "New Brand / New Franchise / New Location Template" boards, copied to start a location |
| Subitems | Own column set per board (Owner, Status, State, Date, Link / Numbers, Files) |

## Capability table

Has: ✅ yes · 🟡 partial · ❌ no. 👁 = seen in the UI (rows 3, 11, 20, 21, 23, 29–34, 37, 39–43, 46, 48–50, 52). Rows 21 (required column, restrict column editing) and 23 (per-row Undo in the activity log) gained details from the click-through. Priority: P1 = A-1 uses it today, P2 = clear value, P3 = later or never. Effort: S (≤1 day) · M (2–4 days) · L (a week or more).

| # | monday capability | EzBizi Task Board | Gap | Pri | Effort |
|---|---|---|---|---|---|
| **Columns** |
| 1 | Status column: coloured labels, inline add, edit labels | ✅ | — | — | — |
| 2 | Status label **is_done** flag | ✅ board statuses have is_done | Custom status columns (as opposed to the built-in Status) have no done flag | P2 | S |
| 3 | **Deactivate a label** (hidden from pickers, kept on old items) | ❌ deleting clears values | A deactivated state on an option | **P1** | S |
| 4 | Several status columns per board | ✅ status/dropdown (Label) columns | — | — | — |
| 5 | People column (multi-person) | 🟡 Owner is multi; a custom Person column holds one person | Multi-person custom column | P2 | S |
| 6 | Text / Long text / Number with unit / Currency | ✅ (batch 1) | — | — | — |
| 7 | Date / Date+time / Timeline | ✅ | — | — | — |
| 8 | Checkbox | ✅ | — | — | — |
| 9 | Link (URL), Phone, Email | ✅ (batch 1) | — | — | — |
| 10 | Tags / Dropdown (multi) | ✅ (batch 1) | — | — | — |
| 11 | **Formula** with IF / VALUE / functions | 🟡 + − × ÷ ( ) only | IF, VALUE, ROUND, SUM, DAYS, comparisons, booleans (Ohio's Total Cost needs IF + a checkbox) | **P1** | M |
| 12 | Files column | 🟡 files on updates only | A files column | P2 | M |
| 13 | Mirror (show a column of a linked item) | ❌ | Mirror of a linked task or subtask column, or of the customer/job link (phone, address) | P2 | M |
| 14 | Connect boards (relation) | 🟡 task links (twins) plus the customer/job link column | Link to tasks on another board, picked | P2 | M |
| 15 | Dependency column, with Gantt arrows | ❌ | — | P3 | L |
| 16 | Rating, vote, world clock, country, location, color picker, hour, week | ❌ | Location from the customer link covers most of these | P3 | S each |
| 17 | Time tracking (start/stop timer) | ❌ (Work Hours module) | — | P3 | M |
| 18 | Auto number, item ID, creation log, last updated | 🟡 Created/Made by built-ins | Last updated (who/when) and auto-number built-ins | P2 | S |
| 19 | Button column (runs an automation) | ❌ | — | P3 | M |
| 20 | Column footer (sum/avg/count, status distribution bar) | 🟡 number sum/average | A **status distribution bar** in group footers | P2 | S |
| 21 | Column description, required columns, default value | ❌ | — | P3 | S |
| **Items and subitems** |
| 22 | Subitems with their own columns | 🟡 subtasks share the board's columns | Per-board subitem columns (Ohio uses Numbers and Files on subitems) | P2 | L |
| 23 | Item side panel: Updates (rich text, @mention, reply, like), Files, **Activity log** | 🟡 updates and files | **Activity log tab** (who changed which column, from → to); @mention notifications | **P1** | M |
| 24 | Duplicate, move to group/board, archive, delete with undo | ✅ | Archive (vs delete) | P3 | S |
| 25 | Item terminology per board ("Task", "Name & job#") | ❌ | Board setting | P3 | S |
| 26 | Item card email-in (each board has an email address) | ❌ | — | P3 | M |
| **Groups** |
| 27 | Groups: colour, collapse, reorder, rename, move items | ✅ | — | — | — |
| 28 | Group as pipeline stage, moved by automation | ✅ (via automations) | — | — | — |
| **Views** |
| 29 | Table | ✅ | — | — | — |
| 30 | Kanban (by any status column) | 🟡 by board status | Kanban by **any** status/label column (e.g. Area, Reason) | P2 | S |
| 31 | Calendar (by any date column) | 🟡 | By any date column (Schedule Date) | P2 | S |
| 32 | Timeline / Gantt | 🟡 Timeline view | Gantt dependencies, baseline | P3 | L |
| 33 | Chart view (pie/bar by column) | ❌ | — | P2 | M |
| 34 | Form view (public form creates items) | ❌ | Public intake form per board | P2 | M |
| 35 | Workload view (per person capacity) | ❌ | — | P3 | M |
| 36 | Map view (from location) | ❌ | From the customer address | P3 | M |
| 37 | **Saved views** (own filter, sort, column order, hidden columns, per user or shared) | ❌ | Save the current filter, sort and column set as a named tab | **P1** | M |
| **Filter / sort / group** |
| 38 | Search | ✅ | — | — | — |
| 39 | **Filter** by any column value (AND/OR, "is / is not / empty") | ❌ | Filter panel over every column | **P1** | M |
| 40 | **Person filter** (one-click "my items") | 🟡 | Avatar strip filter | **P1** | S |
| 41 | **Sort** by any column (multi-level) | ❌ | — | **P1** | S |
| 42 | **Group by** any column (status, person, date) in place of groups | ❌ | — | P2 | M |
| 43 | Hide columns per view | 🟡 hide per board | Per view (with saved views) | P2 | S |
| **Automations** |
| 44 | When status changes to X, move to group | ✅ | — | — | — |
| 45 | When item created, assign creator | 🟡 | Verify that "assign creator" is an action | P1 | S |
| 46 | **"and only if item is in group X"** condition | 🟡 conditions exist | Group condition in the picker | **P1** | S |
| 47 | Assign: **replace vs add** | ❌ (replace only) | Add-assignee behaviour (NW Area rules add) | **P1** | S |
| 48 | **When date arrives** (column date, time, timezone), and X days before/after | 🟡 | Date-arrives trigger on any date column, with offset | **P1** | M |
| 49 | Notify someone, send email, create item on another board, create update, set date to today, clear column, push date, duplicate item, change another column | 🟡 most | Audit against monday's recipe list; add the missing ones | P2 | M |
| 50 | Recurring item ("every Monday, create item") | ❌ | Recurring tasks (Adeline's "Weekly / Daily Task" labels fake this) | **P1** | M |
| 51 | Subitem automations (all subitems done → parent done) | 🟡 rollup status | As an automation | P2 | S |
| 52 | Automation usage log / run history | 🟡 | Per-board run log | P2 | S |
| **Board level** |
| 53 | Board permissions: everyone / **assignee-only edit** / view-only | 🟡 members, view-only | "Edit only items assigned to me" mode (NW uses it) | **P1** | M |
| 54 | Board owners vs members vs guests | ✅ members | — | — | — |
| 55 | **Templates**: save a board as a template, new board from a template | ❌ | A-1 copies location templates by hand | P2 | M |
| 56 | Duplicate board (structure ± items) | ❌ | — | P2 | S |
| 57 | Folders / workspaces | 🟡 boards by company | Folders | P3 | M |
| 58 | Import from Excel/CSV, export to Excel | 🟡 monday importer | Export the view to CSV/XLSX | P2 | S |
| 59 | Board subscribers and notifications (inbox, bell, @mentions, "assigned to you") | 🟡 notifications exist | Board-level subscribe; @mention | P2 | M |
| 60 | Dashboards (widgets across boards: numbers, chart, battery, workload, timeline) | 🟡 EzBizi Dashboard glances | A Task Board glance (overdue, mine, by status) | P2 | S |
| 61 | Integrations (Gmail, Calendar, Slack, Twilio…) | ✅ EzBizi is the integration | — | — | — |
| 62 | Keyboard shortcuts (Ctrl+K search, Enter new item, arrows) | ❌ | Basic shortcuts | P3 | S |
| 63 | Undo (Ctrl+Z) | 🟡 delete undo | General undo of the last change | P3 | M |
| 64 | AI (summarise, categorise, extract) | 🟡 Grok elsewhere | AI column "categorise Reason from the update" | P3 | M |

## Top 10 to build next

Ordered by what A-1's own boards depend on, then by effort.

1. **Filter, sort and person filter** (#39 #40 #41). On a 24k-item board, "Reason = Complaint, Area = NJ, mine" is the daily workflow, and EzBizi has search only.
2. **Saved views** (#37, then #43). Named tabs that keep filter, sort, columns and order, per person or shared. Jose's own monday board already has one.
3. **Deactivated labels** (#3). NW keeps 8+ retired labels on old items. Deleting would wipe history and keeping them clutters the pickers.
4. **Automation gaps A-1 runs today** (#46 #47 #48). The "only if in group" condition, add-vs-replace assign, and "when date arrives" with time and timezone. All 10 of NW's and Ohio's rules need these to port as-is.
5. **Recurring tasks** (#50). Adeline marks "Daily Task" and "Weekly Task" by label because monday has no clean recurrence on her board. EzBizi can do it properly.
6. **Activity log tab** in the task panel (#23). Who changed which column, from what to what. The audit data already exists.
7. **Assignee-only edit permission** (#53). NW's mode: 48 people, each editing only their own items.
8. **Formula functions** (#11). IF, VALUE, ROUND, comparisons and checkbox values, so Ohio's Total Cost formula ports.
9. **Kanban and Calendar on any column** (#30 #31), plus a **status distribution bar** in group footers (#20).
10. **Board templates and duplicate board** (#55 #56). A-1 starts every location from a template board.

After those: multi-person columns, per-board subitem columns, mirror columns, files column, form view, chart view, CSV export.

## Coordination

This is the split agreed with UI-UX on 2026-11-09.

**UI-UX** owns the app-wide patterns and writes them up in `docs/agents/monday-port-uiux.md`:
- navigation, side panels, empty states, tours, search
- notifications and inbox, dashboards and widgets, forms
- mobile, dark mode, density, keyboard shortcuts, micro-interactions
- generic table mechanics: inline edit, resize and reorder, sticky headers

In this table, rows #34 (form view), #59 (notifications), #60 (dashboards) and #62 (shortcuts) are listed for completeness only; UI-UX's document is the one that counts for them.

**Task Board (this document)** owns the board features: column types, automations, views, groups, subitems, and board-level behaviour.
