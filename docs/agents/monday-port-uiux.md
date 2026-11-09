# monday.com → EzBizi: UI/UX patterns worth porting (2026-11-09)

Studied read-only in Jose's monday.com account (a-1group, Main workspace, board "Ohio Project Management"). Nothing was created, changed or sent. Board features are out of scope here: column types, automations, views and subitems belong to Jobs (Task Board owner).

**Not measured:**
- Mobile layout: the Chrome window would not resize below desktop width.
- Density setting: no control was found in the menus opened.

Both entries below rely on known monday behaviour and are marked *(unverified)*.

**EzBizi today:** these were checked by grep in `client/lib`.
- `SidePanel`, `ListTable`, `StatusPill`, `EmptyState`, `SearchPickerField` (133 uses), and a dark theme in `core/theme.dart` all exist.
- These do not exist:
  - a command palette or Ctrl+K;
  - sticky, grouped or collapsible `ListTable` sections;
  - user column resize;
  - a density setting;
  - a notifications inbox screen.
- Keyboard shortcuts exist in only 4 files.

Effort: **S** is one widget or one screen, **M** is a shared widget change rolled across several modules, **L** is a new surface with a backend.

## Navigation

| # | Pattern (what monday does) | EzBizi today | Where to apply | Effort |
|---|---|---|---|---|
| 1 | **Workspace switcher:** a button at the top of the left panel opens a list with its own search box, Recent then Mine, plus "Browse all". | Company/branch picker in the top bar. | The Company · Branch picker: add a "Recent" section above the full list. | S |
| 2 | **Tree navigation with coloured folders:** collapsible folders, one colour per folder (New Locations, Triplex Group, A1 Chimney), and a tooltip for truncated names. | Flat module rail plus tabs. | The Settings › Company tree and the HR "Works at" tree: colour comes from the company's theme accent, never purple. | M |
| 3 | **Favourites (★)** on any board, shown in the rail and in workspace Recents. | None. | Pin any module tab or saved filter to a "Favourites" rail entry. | M |
| 4 | **Collapsible side panel** («) giving full width to the table. | Rail is fixed. | Desktop shell: a collapse toggle, remembered per user. | S |

## Tables (shared ListTable)

| # | Pattern | EzBizi today | Where to apply | Effort |
|---|---|---|---|---|
| 5 | **Groups as coloured sections:** each group has a coloured title and a left colour bar, and collapses with ▸. A collapsed group keeps one summary line: its name, "12 Items", and a mini status bar. | Sub-tabs or flat lists. | Project Management sub-tabs (could become groups on one page), the Leads inbox grouped by status, Jobs by date. Add `ListTable` groups with collapse. | M |
| 6 | **Group footer summary:** under each group, every label column shows a stacked distribution bar of its colours (the "battery"). | None. | Project Management, Leads by status, Estimate Board. Hover shows the counts. | S once groups exist |
| 7 | **Sticky header and first column:** the header row and the Item column stay fixed while scrolling both ways. | Header not sticky. | `ListTable` app-wide. | M |
| 8 | **Full-cell colour labels:** a status fills the whole cell with its colour and white text, so a column reads at a glance. | `StatusPill` (a small pill). | Optional "filled" style for label columns in Project Management and Task Board. Keep the pill elsewhere; red only for problems. | S |
| 9 | **Toolbar row:** New item (split button) · Search · Person · Filter · Sort · Hide · Group by · ⋯, the same order on every board. | Varies per screen. | One `ListToolbar` widget used by every list. It also satisfies "every list has search". | M |
| 10 | **Row checkbox + bulk bar:** a checkbox on every row and per group; selecting rows raises a bottom action bar. | Ad hoc. | Leads, Customers, Jobs: a bulk bar along the bottom, never a dialog. | M |
| 11 | **Per-row update count bubble:** a speech-bubble icon with the count, blue when unread by you. | Note icon (design 187). | Leads, Jobs, Tasks rows: show the unread count in the existing note icon. | S |
| 12 | **Column resize/reorder** by dragging the header *(drag not exercised, read-only)*. | Fixed widths; only 4 files size columns. | `ListTable`: drag to resize, with widths saved per user (see memory a-dragged-width-is-a-preference). | M |

## Panels, search, inbox

| # | Pattern | EzBizi today | Where to apply | Effort |
|---|---|---|---|---|
| 13 | **Item side panel with its own URL** (`/pulses/{id}`): it slides in on the right over the table, with tabs Updates / Files / Activity Log; the table stays visible. | `SidePanel`/`JobDetailPanel`, no URL. | Deep-link each panel (job, lead, customer), so a link or notification opens the board with the panel. | M |
| 14 | **Activity Log tab:** every field change on the item, with who and when. | Audit exists for some modules. | A "History" tab in the job, lead and customer panels, read from audit. | M |
| 15 | **Quick search (Ctrl+K):** a centred palette with chips (Boards, Items, Documents, People, More) and "Add filter". It lists recently visited items with breadcrumbs (workspace › folder) and is keyboard-navigable (↑↓, ↵). | None. | A global command palette that searches customers, jobs, leads, staff and screens. Breadcrumb "Company · Branch › Module". | L |
| 16 | **Full search page:** tabs (All, Cross boards, Updates, Files, People, Tags); "Related to me" (I'm assigned to, My files, I was mentioned); saved and recent searches; "Filter by date". | Per-list search only. | The palette's "See all results" page. | L |
| 17 | **Notifications panel:** a right panel with tabs All / Mentioned / Assigned to me, a search box, an "Unread only" switch, "Last 7 days" grouping, and a blue unread dot per row. Each row shows the actor avatar, a verb link, a quoted snippet and the board name. | Bell popover. | Replace it with an inbox SidePanel using the same tabs and unread switch. | M |
| 18 | **Updates composer:** "Write an update and mention others with @", @ · attach · emoji, plus "Update via email", Like/Reply and a seen-by count (👁 2). | Notes field. | Job and lead notes: @mention staff (notifies them) and a seen-by count. | L |

## Help, onboarding, micro-interactions

| # | Pattern | EzBizi today | Where to apply | Effort |
|---|---|---|---|---|
| 19 | **Contextual coach-mark:** a single small callout pinned to a new control ("Control how you search … Got it"), not a tour. | `tab_help`, videos. | Announce new controls (design-level releases) with a one-time callout, dismissed per user. | S |
| 20 | **Help menu:** Contact support, Hire an expert, Support history, Feedback, Explore & learn ›, What's new. | Suggestions + help videos. | A "?" in the top bar: Help videos, Suggestions, What's new (release notes). | S |
| 21 | **Floating "Ask, create, analyze" AI bar** at the bottom centre of every board. | Grok AppAsk. | An AppAsk entry as a floating pill on list screens. | S |
| 22 | **Unread badges on rail icons** (blue dot on Agents, counts in the header). | Partial. | One badge style for every rail module. | S |
| 23 | **Empty group:** "+ Add item" placeholder row inline, never a blank card. | `EmptyState` widget. | Lists the user can add to: an inline add row at the end. Keep `EmptyState` for read-only lists. | S |

## Theme and density

| # | Pattern | EzBizi today | Where to apply | Effort |
|---|---|---|---|---|
| 24 | **Dark mode:** Jose runs monday dark. The surfaces are near-black with saturated label colours that stay readable. | Dark theme exists in `core/theme.dart`. | Audit the dark theme on Project Management and the Leads inbox, where label tones must keep contrast. | S |
| 25 | **Density** (compact/default/spacious rows) *(unverified)*. | None. | A per-user row-height setting in `ListTable`. | M |
| 26 | **Mobile** *(unverified)*: boards collapse to card lists and the panel opens full screen. | Phone layouts per screen. | Already our rule; no action. | — |

## Top 10 (value ÷ effort)

1. **#17 Notifications inbox SidePanel**: All / Mentioned / Assigned, unread switch, 7-day grouping. (M)
2. **#5 + #6 ListTable groups:** collapsible coloured sections with a summary line and distribution bar. Project Management, Leads, Jobs. (M)
3. **#15 Ctrl+K quick search** across customers, jobs, leads and screens. (L, highest daily value)
4. **#9 One shared list toolbar** (search, person, filter, sort, hide, group by) on every list. (M)
5. **#7 Sticky header + first column** in ListTable. (M)
6. **#13 Deep-linkable side panels** (URL per job/lead/customer). (M)
7. **#11 Unread-update count on rows.** (S)
8. **#10 Row checkboxes + bottom bulk-action bar.** (M)
9. **#12 Drag to resize columns**, saved per user. (M)
10. **#19 One-time coach-mark callouts** for new controls. (S)
