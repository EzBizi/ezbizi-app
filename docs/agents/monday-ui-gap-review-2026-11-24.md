# monday.com vs EzBizi Task Board: UI/capability gap review (2026-11-24)

**Summary:** 178 table rows (Monday features or feature groups) checked. HAVE 50, PARTIAL 45, MISSING 72, IN PROGRESS 10, N/A 1.
By priority, the 117 gaps (PARTIAL + MISSING) split into **P1 8**, **P2 43** and **P3 66**. The build list below merges related rows into 63 numbered entries.

## How this was done

- **Monday:** Monday was opened read-only in Jose's Chrome on 2026-11-24, on the `a-1group.monday.com` account (Enterprise plan). Boards opened:
  - **OH - Ohio** (board 18426052010): Main table plus two Table views.
  - **Ohio Project Management** (board 18432743116): Main table, Table, Kanban x2, Calendar, Dashboard and Gantt views.
  - **My Work, Update feed, Notifications, Search Everything, Automations center, Integrations center, Column Center, Keyboard Shortcuts, User menu.**
- **EzBizi:** `app.ezbizi.com` asked for a sign-in in this Chrome profile, so I stopped there (no password typed). The EzBizi column is therefore taken from the code on `main` (`client/lib/screens/task_board_*.dart`, `client/lib/widgets/list_*.dart`, `TaskBoards*Controller.php`, `TasksController.php`, `routes/api.php`) and from `docs/TaskBoardToDo.md`. None of it was seen on screen.
- **IN PROGRESS** means the work is already being built today: either it is on the caller's list, or it is in TaskBoardToDo rows 33, 46, 52, 53 or 61.
- **Side effects on Monday** (unavoidable when viewing):
  - Opening item *Danae Rodriguez* raised its update's "seen" count from 1 to 2 (Jose's account now counts as having seen it).
  - Dismissing a Kanban tip and the onboarding tour of the automation builder is saved as a UI preference.
  - Nothing else was written: no value, setting or item changed.
- **Not checked:**
  - Monday actions inside the automation builder (the list only appears after a trigger is chosen, which starts a draft). The trigger list was read.
  - Monday number-column footer options (sum/avg/median...) and decimals (the menu was not reached without touching a cell).
  - Subitem columns.
  - The Form and Doc builders.
  - The mobile app.

### Issue found on Monday (worth knowing before Thursday)
The Automations center shows the same limit in three places:
- the Manage tab: *"Your account has reached the maximum integration action limit"*
- the Create tab: *"maximum action limit"*
- the builder: *"maximum automation limit"*

On OH - Ohio, all 10 board automations are EzBizi's sync webhooks:
- "When an item is deleted / archived / moved to any group, send a webhook"
- "When any subitem column changes, send a webhook"
- "When a subitem is created, send a webhook"
- "When a new update posted, send a webhook"
- and 4 more

If Monday enforces the cap by not running those webhooks, Monday→EzBizi pushes may be stopping until the monthly reset, and the sync would rest on the parity poll alone. I only measured the banner text, not whether webhooks are failing. The **Run history** tab in Automate → Manage would show it.

---

## 1. Board views

| Monday feature | Variants / options seen | EzBizi | Evidence | Pri |
|---|---|---|---|---|
| View tabs per board | Main table + N saved views as tabs; each tab's menu: Pin view, Hide main table, Rename, Duplicate, Share, Reorder (for you only), Delete | PARTIAL | Saved views open from a "Views" toolbar menu (`list_toolbar.dart:79`, `TaskBoardViewsController`). There is no tab strip and no pin/duplicate/share/reorder of views | P2 |
| "+" Add view picker | Table, Gantt, Chart, Calendar, Kanban, Canvas, Doc, File gallery, Form, Dashboard; Build with Vibe; Apps (TV view, Canvas, Google Calendar Embedded); Explore more views | PARTIAL | Layouts: Table, Kanban, Calendar, Timeline, Gallery (`task_board_screen.dart:2752`) | P2 |
| Table view | Groups, footers, inline edit | HAVE | `task_board_screen.dart` | P1 |
| Kanban | Group by ANY status/label column (Ohio PM uses **Area**), "Divide by" swimlanes, Show battery, Customize card (which columns show on cards), per-lane counts | PARTIAL | Lanes are statuses only (`task_board_screen.dart:5977-6029`). Missing: pick the lane column, swimlanes, battery, card-field chooser | P2 |
| Calendar | Month / Week / Day, Today, prev/next, "+N more", Add widget, export, full screen, settings | PARTIAL | Month and Week, drag to reschedule (`task_board_plan_views.dart:61-89`). No Day mode | P3 |
| Gantt | Baseline, Auto Fit, Days/Weeks zoom, +/- zoom, group bands, today line, legend, dependency arrows | PARTIAL | Timeline view (week/month/quarter, drag/resize, `task_board_plan_views.dart:406`). No baseline, no dependency arrows | P2 |
| Chart view | Chart over board data | MISSING | grep `chart` in `task_board_*`: no view | P2 |
| Dashboard view (board) | Widgets: Vibe app, Chart, Data over time, Numbers, Battery, Gantt, Files Gallery, Apps, More widgets; Export. Ohio PM has a weekly bar chart | MISSING | No dashboard layout on the Task Board (TaskBoardToDo row 53 mirrors the "Analytics Dashboard" *board*, not the widget view) | P2 |
| File gallery view | All files on the board | HAVE | `task_board_gallery.dart`, `task-boards/{id}/attachments` | P2 |
| Form view | Public form that creates items | MISSING | No Task Board form | P3 |
| Doc / Canvas views | Collaborative doc / whiteboard | MISSING | none | P3 |

## 2. Board header and toolbar

| Monday feature | Variants / options seen | EzBizi | Evidence | Pri |
|---|---|---|---|---|
| New item split button | New item; New group; Import items | HAVE | New-task split with Add group (`task_board_screen.dart:3207`) | P1 |
| Import items | Excel import with column mapping | PARTIAL | Paste titles only (`task_board_screen.dart:3091`) | P3 |
| Search this board | Box plus "Choose columns to search" (per-column checkboxes) | PARTIAL | Searches title + every column server-side (`TaskBoardsController:457`). No column scope; updates not searched | P3 |
| Person filter | Avatar grid of everyone + search, Save as new view | HAVE | `list_toolbar.dart:31` | P1 |
| Quick filters | Per-column value grid with item counts, "Showing all of 1107", Clear all, Save as new view | MISSING | Only the rule builder (`list_filters.dart`). No click-a-value grid with counts | P2 |
| Advanced filters | Where column / condition / value; status conditions: is, is not, text is, text is not, is empty, is not empty; + New filter; **+ New group** (nested and/or groups); Filter with AI toggle | PARTIAL | Rules plus match all/any (`list_filters.dart:200-345`). No nested filter groups, no AI filter | P2 |
| Sort | Multi-level (+ New sort, drag order), Save as new view; column menu: Add subsort (Ctrl+click), **Save order of items** | PARTIAL | Up to 3 levels (`list_filters.dart:456`). No "save order of items" | P3 |
| Hide columns | Search columns, All columns (25 selected), per-column checkboxes, Save as new view | HAVE | `task_board_screen.dart:2014` | P2 |
| Group by | Any column + sort option + "Show empty groups" | HAVE | `task_board_screen.dart:3029-3087` (show-empty-groups not seen) | P2 |
| Toolbar "..." → Pin columns | Freeze any number of leading columns | PARTIAL | First column sticky only (`task_board_pinned.dart`) | P2 |
| Toolbar "..." → Header height | Taller header rows | MISSING | none | P3 |
| Toolbar "..." → Item height | Single / Double / Triple row height | MISSING | no density/row-height setting (grep) | P2 |
| Toolbar "..." → Conditional coloring | Colour a **Cell or Row** when Column / Condition / Value; multiple rules; save as view | MISSING | grep `conditional` in task board: none | P2 |
| Toolbar "..." → Default item values | Pre-filled values for new items | MISSING | none | P2 |
| Collapse header (^) | Hide board header to gain space | MISSING | none | P3 |
| AI suggestions / Agents / Sidekick | AI suggestions panel, Agents/1, "Ask, create, analyze" bar | MISSING | EzBizi has AI label suggestion only (`TasksController:210`) | P3 |
| Board discussion | Board-level chat ("Start a board discussion") | MISSING | none | P3 |
| Invite / members | Search by name/team/email; People / Agents tabs; per-member role dropdown (Owner...); General access (Only people invited / Contributor) | HAVE | `task_board_people.dart:19-25` (Owner/Member/Viewer, Open/Private) | P2 |
| Copy board link | Link icon next to Invite | MISSING | Task links only (`task_board_screen.dart:6641`) | P3 |

## 3. Board "..." menu

| Monday feature | Variants / options seen | EzBizi | Evidence | Pri |
|---|---|---|---|---|
| Activity log (board) | Tabs: Activity, Efficiency, Last viewed, Updates. Other activities vs Automation activity. Filter log by Time (Today/Yesterday/This week/This month/This year, with counts), by Group, by Column (with counts). Search, Person, AI Powered. Each row: who, column, old → new chips | MISSING | Only per-task activity (`tasks/{task}/activity`). No board log | P1 |
| Permissions | Board permission modes | PARTIAL | One mode, "Only assignees edit" (`task_board_screen.dart:3345-3441`) | P2 |
| Notifications (board) | Per-board notification settings | MISSING | Personal on/off only (`notification_bell.dart:55`) | P3 |
| Settings → Rename board, Add to favorites | | HAVE | sidebar menu (`task_board_sidebar.dart:751-830`) | P2 |
| Settings → Change board type | Main / private / shareable | PARTIAL | Open/Private visibility | P3 |
| Settings → Default item values | | MISSING | | P2 |
| Settings → Use card for item creation | Item-creation card | MISSING | | P3 |
| Settings → Create items via email | Board email address | MISSING | grep `email-to-board`: none | P3 |
| Settings → Change item terminology | "Item" renamed per board ("Name & job#") | MISSING | grep `terminology`: none | P3 |
| Settings → Archived history | | PARTIAL | Archived boards list exists; no item archive | P3 |
| More actions → Export board to Excel | | MISSING | grep `xlsx/csv/export` in `task_board_*`: none | P1 |
| More actions → Duplicate board | | MISSING | none | P2 |
| More actions → Save as template / Build report / Manage duplicates / Full screen / Convert to data set | | MISSING | none (duplicate-caller warning exists, `TasksController:329`) | P3 |
| Convert board to project / Power-Ups / Vibe | | MISSING | | P3 |
| Archive board / Delete board / View archive / Trash | | HAVE | archive + restore + 30-day trash (`task_board_screen.dart:349-525`) | P2 |

## 4. Groups

| Monday feature | Variants / options seen | EzBizi | Evidence | Pri |
|---|---|---|---|---|
| Collapse this group | | HAVE | `task_board_screen.dart:3729` | P1 |
| Collapse all groups | Menu item + shortcut | PARTIAL | Only while dragging a group (`task_board_screen.dart:371`) | P2 |
| Select all items in group | Then bulk bar | MISSING | grep `selectAll` in task board: none | P2 |
| Expand / Collapse all subitems | | MISSING | | P3 |
| Show item count only on hover | | MISSING | Count always shown | P3 |
| Add group | | HAVE | `task_board_screen.dart:3207, 3662` | P1 |
| Duplicate group | Items only / Items + updates | MISSING | | P3 |
| Move group | To another board; To top | PARTIAL | Move up/down + drag; not to another board | P3 |
| Rename group | Inline | HAVE | `task_board_screen.dart:1612` | P1 |
| Change group colour | 20-swatch palette | PARTIAL | Shown + API accepts; client `_saveGroup` sends name only (`task_board_screen.dart:1513`) | P2 |
| Group title row tint | | IN PROGRESS | TaskBoardToDo 52 | P1 |
| Export group to Excel | | MISSING | | P2 |
| Delete group / Archive group | | PARTIAL | Delete (with "move tasks to") yes; archive no | P3 |
| Group footer: status battery | Stacked label bar; hover shows "LABEL 4/7 57.1%" | HAVE | `task_board_screen.dart:4298` | P1 |
| Group footer: numbers | Sum (seen "22,386.24"-style values) | HAVE | sum/average (`task_board_screen.dart:4279`) | P2 |
| Group footer: checkbox count | "1/16", "0/0" | MISSING | footer handles status/number/formula/date only | P3 |
| Group footer: date/timeline range | | HAVE | `task_board_screen.dart:4271` | P2 |
| Collapsed group shows summaries inline | | PARTIAL | collapsed header + count; summaries not verified | P3 |
| Item counter in group header | "7 Name & job#s" | HAVE | `task_board_screen.dart:3787` | P1 |

## 5. Columns: header menu and settings

| Monday feature | Variants / options seen | EzBizi | Evidence | Pri |
|---|---|---|---|---|
| Settings → Customize status column (label editor) | Grid picker (4 wide), blank/Default Label, per-label colour, drag reorder, retired labels greyed, + New label, Apply | PARTIAL | add/rename/colour/done/delete-with-reassign (`task_board_columns.dart:2380-2620`). Default label missing; colours/weight/size **IN PROGRESS** | P1 |
| Settings → Add column description | Hover "i" on header | MISSING | Fixed help text on built-ins only (`task_board_screen.dart:1881`) | P2 |
| Settings → Set status notifications | Notify when label set | MISSING | (automation can do it) | P2 |
| Settings → Set column as required | Blocks item creation without it | MISSING | grep `required` in columns: Dart only | P2 |
| Settings → Set column validation | | MISSING | | P3 |
| Settings → Restrict column editing / Restrict column view | Per-column permissions | MISSING | only board-wide "only assignees edit" | P2 |
| Settings → Hide column summary | | PARTIAL | numbers have footer: none; others always | P3 |
| Settings → Save column as a template | Column templates ("Resolution") | MISSING | | P3 |
| AI actions | Auto-assign labels, Set a custom prompt, Autofill this column | MISSING | AI label suggestion per task only | P3 |
| Filter by column | | HAVE | `task_board_screen.dart:2067` | P1 |
| Sort (asc/desc, subsort) | | HAVE | `task_board_screen.dart:2067-2162` | P1 |
| Collapse column | Shrink column to a sliver | MISSING | | P3 |
| Wrap text (text columns) | | MISSING | | P3 |
| Group by this column | | HAVE | | P2 |
| Duplicate column | Column only / Column and cell values | MISSING | | P2 |
| Add column to the right | Quick list (Status, Text, People, Timeline, Date, Tags, Numbers) + More columns | PARTIAL | Add column exists; "to the right of this one" + move left/right after | P3 |
| Change column type | | HAVE | `ColumnConverter.php` | P2 |
| Rename / Hide / Delete | | HAVE | `task_board_screen.dart:1830-1877` | P1 |
| Resize / drag-reorder columns | | HAVE | `task_board_screen.dart:4100-4160` | P1 |

## 6. Column types (Column Center, full list seen)

| Monday type | Monday category | EzBizi | Evidence | Pri |
|---|---|---|---|---|
| Status | Essentials | HAVE | `TaskColumn.php:27` | P1 |
| Priority | Essentials | MISSING (status covers it) | | P3 |
| Label (dropdown) | Essentials | HAVE (`dropdown`) | | P1 |
| People | Essentials | PARTIAL (no Teams) | `person`/`people` | P2 |
| monday Doc | Essentials | MISSING | | P3 |
| Numbers | Featured | PARTIAL (no decimals setting) | `task_board_columns.dart:1933` | P2 |
| Timeline | Featured | HAVE | | P2 |
| Date (+time) | Essentials | HAVE (`date`, `datetime`) | | P1 |
| Text / Long text | Essentials | HAVE | | P1 |
| Connect boards / Mirror | Essentials | HAVE | `TaskWriter.php:1125` | P2 |
| Meeting notes / Creative brief | Essentials | MISSING | | P3 |
| Checkbox | Super useful | HAVE | | P2 |
| Link | Super useful | HAVE (`url`) | | P2 |
| World clock | Super useful | MISSING | | P3 |
| Item ID | Super useful | MISSING | | P3 |
| Phone / Location / Files | Super useful | HAVE | | P2 |
| AI columns: Translate, Summarize, Assign labels, Prioritize with AI, Summarize updates, Assign people, Custom AI prompt, Sentiment detection, Extract from file, Generate docs with AI, Improve text, Write with AI, Extract info | AI-powered | MISSING | | P3 |
| Tags | Team Power-Up | HAVE | | P2 |
| Vote | Team Power-Up | MISSING | | P3 |
| Rating | Team Power-Up | HAVE | | P3 |
| Creation log / Last updated | Team Power-Up | HAVE | | P3 |
| Auto number | Team Power-Up | MISSING | | P3 |
| Progress tracking | Team Power-Up | MISSING | | P3 |
| Button | Board Power-Up (also Featured) | MISSING | | P3 |
| Dependency | Board Power-Up | PARTIAL (same board only) | `TaskWriter.php:1130` | P3 |
| Dropdown | Board Power-Up | HAVE | | P2 |
| Week | Board Power-Up | MISSING | | P3 |
| Formula | Board Power-Up | HAVE | | P2 |
| Country | Board Power-Up | MISSING | | P3 |
| Color picker | Board Power-Up | MISSING | | P3 |
| Time tracking | Board Power-Up | MISSING | | P3 |
| Hour | Board Power-Up | MISSING | | P3 |
| Email | Board Power-Up | HAVE | | P2 |
| Combos: Date + Status, Timeline + Status, Timeline + Numeric | Combos | MISSING | | P3 |
| Column templates (account-made, e.g. "Resolution") | Created in your account | MISSING | | P3 |

## 7. Cells and pickers

| Monday feature | Variants / options seen | EzBizi | Evidence | Pri |
|---|---|---|---|---|
| Status picker | Label grid, Edit Labels, Auto-assign labels (AI) | HAVE (label styling IN PROGRESS) | `task_board_columns.dart` | P1 |
| Date picker | Today, typed date field, clock (add time), month + year selectors, prev/next, Autofill date (AI); "+ calendar" icon on empty cell hover | HAVE (empty-cell calendar icon IN PROGRESS) | `task_board_columns.dart:1655` | P1 |
| Person picker | Search names/roles/teams, Suggested people with role, Add an agent, **"Assignees will be notified" + Mute**, Auto-assign people (AI) | PARTIAL: search IN PROGRESS (row 46); no "mute notify" toggle, no teams | | P2 |
| Popup editor for every cell | | IN PROGRESS | caller list | P1 |
| Clickable links in cells | | IN PROGRESS | caller list | P1 |
| Updates bubble on each row with count | Blue bubble with number; opens Updates | HAVE (updates-only panel IN PROGRESS, row 33) | | P1 |

## 8. Items (rows) and bulk actions

| Monday feature | Variants / options seen | EzBizi | Evidence | Pri |
|---|---|---|---|---|
| Item menu → Open / Open in new tab | | Open HAVE; new tab/window **IN PROGRESS** | `task_board_screen.dart:1141` | P1 |
| Copy item link | | HAVE | | P2 |
| Move to → top / group / board | | PARTIAL (group + board yes; "move to top" no) | `task_board_move.dart` | P2 |
| Duplicate → with updates / without updates | | PARTIAL (one duplicate, without the with/without choice) | `task_board_screen.dart:1141-1165` | P3 |
| Create new item below | | HAVE | | P2 |
| Add subitem | | HAVE | `parent_task_id` | P2 |
| Convert to subitem | | MISSING | | P3 |
| Archive item (restorable from board archive) | | MISSING (delete only) | | P2 |
| Delete item (+ undo) | | HAVE (undo toast on delete) | `task_board_screen.dart:962` | P1 |
| Subitems with their own columns | Expand chevron per item | PARTIAL (subtasks reuse parent's columns) | `task_board_screen.dart:3970` | P3 |
| Bulk bar | Count + Duplicate, Export, Archive, Delete, Convert, Move to, Sidekick, Apps, X | PARTIAL: move/copy/delete/status/assign/duplicate yes; **Export, Archive, Convert** no | `task_board_screen.dart:1236`, `TasksController:266` | P2 |
| Header checkbox selects whole group | | MISSING | | P2 |
| Drag rows between groups / boards | | HAVE | `task_board_screen.dart:866, 888` | P1 |
| Full task title visible | | IN PROGRESS | caller list | P1 |

## 9. Item panel (Updates / Files / Activity)

| Monday feature | Variants / options seen | EzBizi | Evidence | Pri |
|---|---|---|---|---|
| Tabs Updates/N, Files, Activity Log | | IN PROGRESS | TaskBoardToDo 61 | P1 |
| "+" item views | Item Card, Vibe app, Item description, Table, Chart, Gantt, File gallery, Dashboard; tab menu: Add to favorites, Set as board default, Rename, Duplicate, Delete | MISSING | | P3 |
| Update via email | Email address per item | MISSING | | P3 |
| Composer rich text toolbar | Format (headings), Bold, Italic, Underline, Strikethrough, Text colour, Size, Numbered list, Bullets, **Table**, Link, Align, Line, Change direction, **Checklist** | MISSING (plain text + mentions + attachments) | `task_board_screen.dart:7022-7181` | P2 |
| Composer @ / attach / emoji / AI write | | PARTIAL (@ and attach yes; emoji and AI no) | `MentionField` `task_board_screen.dart:7114` | P3 |
| Scheduled update | Update ▾: Tomorrow 09:00, Monday 09:00, Custom time | MISSING | | P3 |
| Update card: likes, replies, Read more, seen count | | IN PROGRESS | TaskBoardToDo 61 | P1 |
| Update card: Edit / Delete | | HAVE | | P1 |
| Update card: **Pin to top** | | MISSING | | P2 |
| Update card: Bookmark to update feed | | MISSING | | P3 |
| Update card: Copy link to update | | MISSING | | P3 |
| Update card: Set reminder | | PARTIAL (task reminders, not per update) | `task_board_reminders.dart` | P3 |
| Update card: Summarize (AI) | | MISSING | | P3 |
| Item header "..." | Manage subscribers, Export updates to Excel, Move to, Copy link, Archive, Delete | PARTIAL: copy link + delete; no subscribers, export, archive | `task_board_screen.dart:6641` | P2 |
| Subscribers / follow item | Avatar stack in header | MISSING | none | P2 |
| Files tab | Add file, Search files, grid/list toggle, download all, drag-and-drop | IN PROGRESS | row 61 | P2 |
| Item Activity Log | Other vs Automation activity, Filter log, Person filter, AI, refresh, export; rows: who, column, old → new | PARTIAL: log exists, no filters (tab IN PROGRESS) | `task_board_screen.dart:7185` | P2 |

## 10. Notifications, Update feed, My Work, Search

| Monday feature | Variants / options seen | EzBizi | Evidence | Pri |
|---|---|---|---|---|
| Notifications tabs | All / Mentioned / Assigned to me | MISSING | `notification_bell.dart`: no tabs | P1 |
| Notifications search + Unread only toggle | "Search notifications by people, boards..." | MISSING | | P1 |
| Per-notification actions | Like, Mark as read, Delete, More (on hover); date grouping "Last 7 days" | PARTIAL: click opens, mark-all-read (`notification_bell.dart:485`); click-to-mention + reply notifications IN PROGRESS | | P1 |
| Notifications "..." | Mark all as read, Delete all, Give feedback; gear = settings | PARTIAL (mark all read + on/off) | | P2 |
| **Update feed (Inbox)** | Tabs All updates / I was mentioned / Bookmarked / All account / Scheduled; Show unread; Mark all as read; Filter by board with counts; inline Like/Reply per card with breadcrumb board > group > item | MISSING | no inbox/feed screen for tasks (grep `inbox`: email only) | P1 |
| Mark updates read | | IN PROGRESS | caller list | P1 |
| **My Work** | Table / Calendar; buckets Past dates (108), Today, This week, Next week, Later, No date; columns Group, Board, People, Date (overdue red), Status, Priority; Customize: People (users/teams), Boards, Status/Date/Priority column per board, Hide done items; New item; Search | PARTIAL | Personal "My board" (`kind == 'user'`, `task_board_screen.dart:573`); no cross-board date buckets | P1 |
| Search Everything | Tabs All / Cross Boards / Updates / Files / People / Tags / Docs; Filter by date; Related to me (I'm assigned to, My files, Archived boards, I was mentioned); Saved searches; Recent searches; Quick search Ctrl+K; People search | PARTIAL | `task-search` used by the Link picker only (`TasksController:356`) | P2 |
| Search bar on narrow widths | | IN PROGRESS | caller list | P2 |

## 11. Workspace sidebar

| Monday feature | Variants / options seen | EzBizi | Evidence | Pri |
|---|---|---|---|---|
| Workspaces switcher + folders/subfolders | | HAVE (folders, subfolders, colour) | `task_board_sidebar.dart:528-561` | P1 |
| Favorites | | HAVE | `task_board_sidebar.dart:297` | P1 |
| Board context menu | Open in overlay, Open in new tab, Rename, Move to, Change type, Add to favorites, Duplicate, Save as template, Delete, Archive | PARTIAL: rename/move/favorite/colour/delete/archive yes; duplicate + open in new tab missing (new window IN PROGRESS) | `task_board_sidebar.dart:751-782` | P2 |
| "+" Add to workspace | Agent, Vibe app, Board, Doc, Dashboard, Workflow, Project management, Folder, Template center, More (Form, Canvas, Installed apps, Import data); New workspace | PARTIAL (board + folder) | | P3 |
| Folder visibility / Franchise Boards rename | | IN PROGRESS | caller list | P2 |
| Drag to reorder boards/folders | | HAVE | TaskBoardToDo 29 | P1 |

## 12. Automations and integrations

| Monday feature | Variants / options seen | EzBizi | Evidence | Pri |
|---|---|---|---|---|
| Automations center layout | Create (categories: Explore, AI-powered, 2-way sync, Recommended, Productivity, Dates, Communication, Sync, Connected & mirror columns, Created by A1 Group) / Manage (Automations list with on/off toggle + menu, **Run history**, My connections, Account usage; search, filter, card/list) | PARTIAL | `task_board_automate.dart` + `automations_screen.dart` (run history exists, line 604) | P2 |
| Recipe templates | "When status changes to X move item to group", "When date arrives notify", "When item created assign creator as person", "Every time period create an item", "When an email is received, create an item in group", Google Calendar sync | PARTIAL | Triggers in `task_board_automate.dart:20-33`: created, status changed, overdue, column changed, date arrived | P2 |
| Triggers seen in builder | status changes to X; status changes from X to Y; subitem status changes; date arrives; item created; every time period; column changes; person assigned; email changes; button clicked; item moves board→board; item moves to group; item name changes; update created; item moves to board; subitem update created; every day if date passed (last 7 days); subitem date arrives / created / column changes; AI Sales Agent events | PARTIAL | Missing: person assigned, status from→to, moved to group/board, name changes, **update created**, recurring "every time period", subitem triggers, button | P2 |
| Integrations center | asana, Basecamp, box, ClickUp, Copper, Dropbox, Eventbrite, Meta, GitLab, Gmail, Google Drive, Google Calendar, Harvest, HubSpot, LinkedIn, Mailchimp, MS Teams, OneDrive, Outlook, Outlook Calendar, PagerDuty, Salesforce, Slack, Stripe, SurveyMonkey, toggl, Trello, Twilio, Typeform, Webhooks, Zendesk, Zoom; installed apps incl. QuickBooks, ZoomInfo, Emails & Activities | N/A | EzBizi has its own Integrations module; Task Board "Integrate" opens Automate (`task_board_screen.dart:3345`) | P3 |

## 13. Keyboard, theme, account

| Monday feature | Variants / options seen | EzBizi | Evidence | Pri |
|---|---|---|---|---|
| Keyboard shortcuts | Cheat sheet panel. Quick Search (Ctrl+K), Close item page/dialogs/search, Open/close navigation. Board: arrow-key cell navigation, Tab/Shift+Tab next/previous cell, Enter edit, Backspace clear, Space open column view, Ctrl+F search board, Ctrl+C/V/X cells, Ctrl+Enter select item, Shift+Enter range, Ctrl+Shift new group / new column / open activity log, Ctrl+click sort another column, collapse/expand all groups, Alt+A enhanced navigation | PARTIAL: Esc, Ctrl+Enter, Ctrl+C/V ranges, shift-click range (`task_board_screen.dart:5071`, `:913`); no arrow-key cell nav, no cheat sheet | | P2 |
| Themes | Light / Dark / Night / System default | PARTIAL (light/dark) | `core/theme.dart:163` | P3 |
| Working status / Do not disturb | | MISSING | | P3 |
| Account Trash / Archive pages | | PARTIAL (board archive + 30-day restore) | | P3 |

---

## Build list (every MISSING / PARTIAL, P1 first)

### P1
1. **Board activity log:** add a board-level Activity panel (board "..." → Activity log). It lists every column change across the board as who / item / column / old → new. Filters: time (Today / Yesterday / This week / This month / This year, with counts), group, column and person, plus a search box. It reuses the per-task activity rows already served at `tasks/{task}/activity`.
2. **Export board to Excel:** board "..." → Export downloads an .xlsx of the current view (groups as sections, visible columns, current filter/sort). Also expose it on the group menu (export one group) and the bulk bar (export selected).
3. **Update feed (Inbox) screen:**
   - every task update the user can see, newest first
   - tabs: All updates / I was mentioned / Bookmarked / Scheduled
   - unread-only toggle and Mark all as read
   - left "Filter by board" list with counts
   - each card shows a board > group > task breadcrumb, with inline Like and Reply
4. **My Work screen:** a cross-board list of tasks assigned to me, bucketed into Past dates / Today / This week / Next week / Later / No date. Columns: Board, Group, People, Date (overdue in red) and Status. Table and Calendar tabs; Customize lets the user pick people, boards and the date/status column per board, and Hide done.
5. **Notification bell tabs and search:** add tabs All / Mentioned / Assigned to me, a search field ("people, boards…"), an Unread-only toggle and "Last 7 days / Older" grouping. On hover, each row gets Like, Mark read and Delete; the "..." menu gets Delete all.
6. **Status label editor parity:** add a "default label" (the blank label's text), drag-to-reorder of labels, and a greyed "retired" section in the picker. Lay the picker out as a 4-wide colour grid with Edit Labels at the bottom; colours, weight and size are already in progress.

### P2
7. **Conditional coloring:** toolbar "..." → Conditional coloring, with rules of Cell|Row + column + condition + value + colour, saved per view.
8. **Item height:** toolbar "..." → Item height (Single / Double / Triple); Double and Triple wrap long text and show taller cells. Saved per user per board.
9. **Pin columns:** let the user freeze N leading columns, not only the title column.
10. **Default item values:** a board setting with a value per column that pre-fills every new task.
11. **Quick filters:** a filter popover showing each column's values as clickable chips with item counts, "Showing X of Y", Clear all and Save as view.
12. **Nested filter groups:** "+ New group" in the filter builder so rules can be AND/OR-grouped. Also add the status conditions "text is" and "text is not".
13. **View tabs:** show saved views as a tab strip under the board title (Main table first). Each tab menu offers Rename, Duplicate, Share, Pin, Reorder and Delete; a "+" opens the view-type picker.
14. **Kanban settings:** let the user choose which status or label column makes the lanes (Ohio PM groups by Area), a "Divide by" swimlane column, a show-battery toggle, and which columns show on cards.
15. **Chart view:** a bar/pie chart of item counts or a number sum, grouped by any column, with the X axis by week for dates.
16. **Board Dashboard view:** a widget canvas (Chart, Numbers, Battery, Data over time, Gantt, Files) over the board, with Export. Ohio PM uses one.
17. **Gantt:** draw dependency arrows from the dependency column on the Timeline view, plus a Baseline toggle and Auto-fit.
18. **Group menu:**
    - add Collapse all groups (and a shortcut) and Select all items in group
    - add Change group colour (20-colour palette); the API already accepts colour, but `_saveGroup` sends only the name
    - add Export group to Excel
19. **Header checkbox:** the group-header checkbox selects every task in that group and opens the bulk bar.
20. **Bulk bar:** add Export, Archive and "Set column value", next to the existing move/copy/delete/status/assign.
21. **Archive item:** archive a task (restorable from the board's Archive) as an alternative to delete, in the row menu, the item header and the bulk bar.
22. **Column description:** column header → Settings → Add description, shown as an "i" tooltip on the header.
23. **Required column:** column Settings → Set column as required; a new task cannot be saved without it.
24. **Restrict column editing / view:** per-column lists of the roles or people who may edit or see it.
25. **Status notifications:** status-column setting "notify assignees when label X is set", as a shortcut to an automation.
26. **Duplicate column:** "Column only" or "Column and cell values".
27. **Board permissions:** add Monday's modes beyond "Only assignees edit": edit everything / edit content only / assigned rows only / view only.
28. **Duplicate board:** structure only, structure + tasks, or structure + tasks + updates, from the board menu and the sidebar context menu.
29. **Person picker:** add Teams to the search results, and an "Assignees will be notified · Mute" toggle so a reassignment can be silent.
30. **Numbers column:** a decimals setting (0–4) and left/right unit placement.
31. **Subscribers:** the item header shows a subscriber avatar stack. Its "..." gets Manage subscribers, and users can Follow/Unfollow a task. Subscribers get every update.
32. **Update composer rich text:** bold, italic, underline, strikethrough, text colour, size, numbered/bullet lists, checklist, table, link and divider (store as sanitized HTML/markdown).
33. **Pin update:** "Pin to top" on an update card, keeping the pinned update above the thread.
34. **Item Activity Log filters:** add Filter log (time / column), a person filter, and an Automation-activity vs Other-activities split.
35. **Search Everything:** global search with tabs Tasks (cross-board) / Updates / Files / People. Filter by date, plus "Related to me" shortcuts (assigned to me, I was mentioned); Ctrl+K opens it.
36. **Keyboard navigation:** arrow keys move the active cell, Enter edits, Backspace clears, Tab/Shift+Tab next/previous cell, Ctrl+F focuses board search. Shift/Ctrl+Shift+? opens a shortcuts cheat sheet.
37. **Item menu "Move to top":** moves the task to the top of its group.
38. **Sidebar board menu:** add "Duplicate" and "Save as template".
39. **Automation triggers:** add person assigned, status changes from X to Y, task moved to group, task moved to board, name changes, update created, and recurring "every [period] create a task". Templates: "when status → X move to group" and "when an email is received create a task".
40. **People column:** support Teams as assignees.

### P3
41. **Import:** an Excel/CSV import with column mapping, not titles only.
42. **Search scope:** "Choose columns to search" checkboxes on the board search box.
43. **Sort:** "Save order of items" (persist the current sort as the manual order).
44. **Header:** a header-height setting, and a collapse-header (^) button.
45. **Calendar:** a Day mode, and "+N more" overflow on month cells.
46. **Group menu:**
    - Expand/collapse all subtasks
    - Show item count only on hover
    - Duplicate group (items, or items + updates)
    - Move group to another board / to top
    - Archive group
47. **Group footer:** a checkbox count ("1/16"), and showing summaries on collapsed groups.
48. **Column menu:** Collapse column, Wrap text, Save column as template, Hide column summary for every type, and add-column positioned to the right of the clicked column.
49. **Column validation:** rules on text, number, email and phone formats.
50. **Column types:**
    - Priority, Vote, Auto number, Item ID, Week, Hour, Country, World clock and Color picker
    - Time tracking (start/stop timer with total), Progress (battery from status columns) and Button (runs an automation)
    - Combos: Date+Status, Timeline+Status, Timeline+Numeric
    - monday Doc; account column templates
51. **AI columns and actions** (Summarize, Translate, Sentiment, Extract info, Improve/Write text, Auto-assign labels/people, Autofill column/date). Only after the core parity items.
52. **Dependency column:** allow links across boards.
53. **Subtasks:** their own column set, and Convert to subtask from the row menu.
54. **Duplicate item:** a choice between "with updates" and "without updates".
55. **Item panel:**
    - "+" item views (Item card, Item description)
    - Update via email (per-task address)
    - Export updates to Excel
    - Scheduled update (Tomorrow 9:00 / Monday 9:00 / custom)
    - emoji picker
    - Bookmark update
    - Copy link to update
    - per-update reminder
    - AI summarize
56. **Board settings:**
    - Create items via email (board address)
    - Change item terminology ("Name & job#")
    - Use card for item creation
    - Change board type
    - Save as template
    - Manage duplicates
    - Full screen
57. **Board-level notifications:** settings, and a board discussion thread.
58. **Copy board link:** a button next to Invite.
59. **Form view:** a public form that creates a task in a chosen group.
60. **Doc / Canvas views.**
61. **Workspace "+" menu:** Dashboard, Doc, Form and Import data; also multiple workspaces.
62. **Themes and status:** a Night theme, and a Do-not-disturb working status.
63. **Item views:** chart/gantt/table inside the item panel.
