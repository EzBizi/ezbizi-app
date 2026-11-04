# UI/UX: cross-module cohesiveness, 2026-11-02

Opened every module in the sidebar on app.ezbizi.com (v0.0.0513, A-1 Chimney, desktop 1568 wide, dark theme), read only. Nothing was clicked that writes or sends. The other modules' §3 sections from this folder are merged in below (§3). None of those was marked fixed.

## 1. Ranked fix list

| # | What | Where (seen live) | Why it matters | Fix | Needs a vote? |
|---|---|---|---|---|---|
| 1 | **Purple is in the theme.** `Tone.review` is lavender/purple (`theme.dart` 707, 716), against the no-purple rule | "Lead" pill in Communication › Dial's recent calls; "Done, pending approval" in Project Management 2 and Jobs; "Awaiting report" in Reviews; also PTO, Field status, Scoring, Schedule (11 uses) | The rule is broken in 11 places by one token, so a per-screen fix would miss some | Re-tint `Tone.review` once (e.g. the teal family) in light and dark. Every use follows | No (the rule is Jose's) |
| 2 | **Broken: truncated connection** | Customer Experience › Reviews header reads "Google Busi…  Change it in …" | It reads as a bug, and neither half can be read | The same "Google ✓" chip and ⋯ menu as GBP Listings (design 164, built in 8a3077b90) | No (follows 164) |
| 3 | **Filter-bar heights differ** | Search 40 px against chips/segmented 28–32 px against labelled pickers 48 px: Jobs, Customers, PM-1, Reviews, Automations | Jose flagged this exact thing in Leads today ("these 3 fields have different sizes"). Fixed there at 44 px in 6af32366a, but it's the same in every module | One bar height (44) for search, pickers, chips and segmented buttons in any filter row: a shared `FilterBar` or theme sizes for the dense controls | Yes: a short gallery, because the theme change reaches every form |
| 4 | **Three search-bar styles** | Outlined and fixed-width (Jobs, Customers, Leads, PM); filled and full-width (Email, Services, Guidelines, Inspection); small and filled (Reports, Monitoring, Automations, Training) | The same control looks like three different things | One `ListSearch`: outlined, about 280 px at desktop width, full width on a phone | With #3 |
| 5 | **The title-row icons vary** | Three icons on most modules; two on Field, AI Assistance, Reports, Guidelines, Settings; one on PM-1; none on PM-2 | Favourite, help and the walkthrough are missing in some modules for no reason | One `ModuleTitle` used everywhere | No |
| 6 | **Four date vocabularies** | Day/Week/Month/Quarter/Year chips (Dashboard, HR); Week/Month/Quarter/Year segmented (Fleet › Spending); the shared date bar with "Last 7 days" (Reports, eKomi); none at all (Communication Messages/Calls, from phone.md) | The same question ("which dates?") is asked four ways | `ReportDates` everywhere a range is chosen; the overview chips stay only where they pick a summary period, with the same words | Yes |
| 7 | **Quick-filter chips in four styles** | FilterChip with icons (Leads); outlined buttons (Reviews "1–2 stars", PM-1 "Show only"); count pills (Jobs, Customers); ActionChip (GBP) | You can't tell which ones toggle | FilterChip for toggles and ActionChip for "select these", always compact and at #3's height | With #3 |
| 8 | **Picker labels and "Franchise"** | Floating labels ("Where", "Type", "Tags", "Franchise", "Service area") next to an unlabelled search; Customers says "Franchise" where the org label is Branch | The pickers sit higher than the search, and the word differs between modules | Put the label inside as the hint ("Any type", "All branches"); always use the org's `franchise_label` | No |
| 9 | **Second tab level in two styles** | Underlined text tabs (Marketing › Dashboard/Compose…, Automations › Your automations/Built) against pill tabs (Communication › Dial/Team/Messages/Voicemail) | Design 103 set the tab levels, and these two predate it | Move both to design 103's second-level style | No (103 approved) |
| 10 | **Empty states** | Inventory: left-aligned sentence and buttons. Business Profile and AI: centred icon, title and line. Wording varies (dashboard.md) | Not one pattern | `EmptyState(icon, title, line, action)`, centred, with "No X yet" wording | No |
| 11 | **Lists without search** | Field › Dashboard alerts (dozens of rows); Communication Messages/Calls (phone.md) | Breaks the "every list has a search bar" rule | Add `ListSearch` (and paging in Phone) | No |
| 12 | **A table that stops short** | Monitoring › General ends at about 70% of the width | Looks unfinished next to the other tables | Make the columns flex to the full width | No |
| 13 | **Avatar colours include purple** | Reviews list avatars (e.g. "Myron Wells") | Same rule as #1 | Take purple out of the avatar palette | No |

Already fixed today, for the record:
- Leads bar height and chip icons (6af32366a).
- WhatsApp inline on GBP (8a3077b90).
- Payroll type filter (b1ba6b44c).

## 2. Not checked

- Phone width live: the Chrome window can't be resized from here. Widget tests cover phone widths per screen.
- Light theme.
- Inner tabs beyond each module's first screen, except Leads, Jobs, PM, Reviews and Communication, which got a closer look.

## 3. Merged from the other modules (all open)

**Heights**
- Dashboard: in the Small and Medium "N need attention" rows, line heights vary with the copy length. Fix with a copy-length rule (≤ 40 characters, one line, ellipsis).

**Chips and icons**
- Jobs: near-duplicate tags (DEPOSIT RECEIVED/RECIEVED, PRIORITY/PRIORTY, five spellings of "Estimate • Final Follow-Up"). That's a data merge for Jobs. Picker display can stay as is.
- Inventory: the Stock and Items tiles get two payload shapes. Every tab is empty on A-1, so they aren't checked rendered.

**Toolbars and title rows**
- Notifications: bell rows carry an "EzBizi - Company - Branch" caption above the title, which no other list has. Proposal: move it under the title as the muted second line, the way Leads rows do.

**Empty states**
- Dashboard: empty wording varies ("No jobs to show.", "Nobody in this view.", "No items to show."). Goes with #10.
- Customers: the Money tab shows $0 everywhere for a customer with no billing. UI-UX ruling: **hide the Money section when every figure is 0** (client side, so no server change).
- iOS: the demo-tenant Inventory, Fleet and HR are mostly zeros in the store screenshots. Seed demo data before the next shots.

**Dates and search**
- Phone: Messages and Calls have no date bar, search or paging. Goes with #6 and #11.
- Jobs: the search parameter is `search=` in Jobs but `q=` in PM, Logs and Book. Server naming; the UI is unaffected. Align when an endpoint is next touched.

**Wording**
- Jobs: raw Workiz status casing in exports. The UI already says "Done, pending approval".
- Inspection: the Builder uses Title Case ("Meets Industry Standards"). Use sentence case.
- Dashboard: the Email box lists 54 near-identical sentences. Collapse to "54 inboxes could not be asked".
- Customers: "Notes from Workiz" becomes "Notes" once the Jobs notes import lands.
- Settings: hints spell out paths ("Open Settings, then Organization, then…"), and the link lands on the screen rather than the inner tab. Deep-link the tab and say "Open it" instead.
- Phone: Voicemail shows `line_count: 0` although it's every line's default. Say "Every line (default)".

**Dialogs, panels, colours, layout**
- Settings: two dialogs remain (Integrations rename, Organization picker). Move them to SidePanel.
- Settings: six settings use the raw-JSON editor (`invoicing.channels`, four `jobs.pm_*`, `jobs.thumbtack_status_map`). Each needs a real control, and each is its own small design.
- Notifications: the "Alert me" and "Notify me about" settings are in the bell panel. Move them to My Account, with a link from the bell.
- Inspection: the report PDF has its own colours and font. Share the invoice header and footer and the theme tokens.
- Inspection: the automation builder isn't on the shared `StepTimeline` yet. That's UI-UX's.
- Customers: the Workiz parts don't hide yet when the branch has no Workiz.
- Android: there's no "new version" nudge, while desktop has one.
- iOS: store screenshots predate Field, Monitoring, Leads, Reviews and AI.

## 4. Suggested order

1. Do #1, #2, #5, #8, #12 and #13 now: small, no vote, one SHA.
2. Then #9, #10 and #11, plus the merged no-vote items (Money hide, Notifications caption, Settings dialogs).
3. Then one gallery for #3, #4, #6 and #7 (the "one filter bar" design). These four are one decision.
