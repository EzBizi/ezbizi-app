# UI/UX phone deep dive, 2026-11-04

Jose: "do it but on mobile mode as well, DEEP DIVE like before, all possible tabs". This covers app.ezbizi.com v0.0.0526 at 390x844, read only, as Triplex. It runs in four sections; each one has its own Fix first table, patterns, wording and a checklist of the tabs visited. Gaps are listed in each checklist. Development was locked behind TOTP.

Companion to docs/reviews/2026-11-02/ui-ux-deep-dive.md (desktop).


---

# Section 1


app.ezbizi.com v0.0.0526 as A-1 Chimney, dark theme, read only. One lead (Adelene Howell, no unread dot) was opened. Nothing was saved or sent.

**Blocked.** The Browser pane was hidden for the whole run, so the page did not composite frames:
- Scrolls timed out, and drags and PageDown did nothing.
- Drawer and route animations froze mid-way.
- After the Leads filter click, screenshots came back stale at the wrong scale.

Dashboard, the side nav, the Leads list and one lead thread were captured, partly by setting the viewport to 390×3000 so a whole page fits without scrolling. **Customers, Jobs and Scheduling were not reached.** Re-run with the pane displayed.

## 1. Fix first

| # | What | Where |
|---|---|---|
| 1 | **Status chips overflow the row.** "Replied" is cut to "Rep" under "just now", and "Awaiting reply" to "Awaiting rep". Names truncate to 8 characters ("Christop…", "Alice We…", "Sarah So…") because the chips take the width. The assignee "Tiara Marañon" is clipped mid-letter. | Leads list rows |
| 2 | **Words broken mid-word.** Two-column tiles split titles across lines ("Guideli / nes", "Financi / als") and truncate others ("Project Mana…"). The "Coming soon" chip is cut to "Com…" and is red, though it marks a normal state. The "Coming soon" text itself is already with the desk (server side). | Dashboard tiles (bottom) |
| 3 | **The open lead leaves about 40 % of the screen for the conversation.** The header block (chips, Phone, Address, Via ad, Form answers and the AI line) stays expanded, and the "Leads ⓘ ? ▶" title row stays above the thread. On phone, collapse the header by default and drop the module title row inside a record. | Leads › lead thread |
| 4 | **The lead name is squeezed to "Adelen…"** by the Status field and Book. Put the name on its own line, or move Status into the ⋯ menu on phone. | Leads › lead thread header |

## 2. Patterns

- **The side nav has 29 items and no search.** At 844 px tall only about 12 fit; Settings is last and needs a long scroll. The dividers have no group labels. Add group headings (or a search field), and pin the most-used modules first.
- **The top bar is crowded:** branch chip, a red-outlined switch icon, the phone status icon, the device icon, the bell and the avatar. "A-1 Chimney" fits only because it is short, and a longer "Company · Branch" name will truncate. The red outline on the switch icon reads as an error; use a neutral outline.
- **Two-column tiles at 390 px** are too narrow for 13-character module names. Use one column below 420 px, or let titles wrap whole words only.
- **Dashboard › Phone tile** has no headline number. Every other tile leads with a big figure, but this one shows only "146 voicemails are waiting."

## 3. Wording

- "A-1 Chimney" (branch chip) and "A1 Chimney" (ad-account chip) sit side by side on one lead. Both are data, but they read as a typo.
- Jobs legend: "done pending approval" is lower case among Title-case entries ("Submitted", "Pending", "In progress").

## 4. Checklist

- [x] Dashboard: every tile, at full length (tall viewport)
- [x] Side nav: open, every item listed
- [x] Top bar: looked at, not tapped (bell, phone, device and avatar not opened)
- [x] Leads: list (first 19 rows), one thread, header expanded
- [ ] Leads: Filters panel, ⋯ menu, Book panel (blocked)
- [ ] Customers: all tabs (blocked)
- [ ] Jobs: all tabs and Estimate Board (blocked)
- [ ] Scheduling: all tabs (blocked)

---

# Retry, 2026-11-04: Customers, Jobs, Scheduling (390 px, org Everdoors)

The viewport was set to 390×1800, so a whole tab fits without scrolling; horizontal scroll worked on the tab strips. Forms were opened and cancelled, and nothing was saved.

## 1. Fix first

| # | What | Where |
|---|---|---|
| 5 | **The Schedule board is the desktop grid squeezed to 390 px.** A 170 px Technician column leaves room for one time column ("8 AM – 11 AM"), and the job cards are cut on the right ("In prog…"). The board sits in a fixed-height inner scroller, so only 3 of 16 scheduled technicians show and the third card is cut off at the bottom ("9 AM · Milf…"). On phone it needs a per-technician list (or one technician per page). | Scheduling › Schedule |
| 6 | **The route's stop separator draws as a missing-glyph box** ("Valentin Toth ▯ Diane Thomas ▯ Jimmy"). The font has no glyph for the arrow; use "→" from the bundled font, or "·". | Scheduling › Routing, technician cards |
| 7 | **Label overlaps value.** The "Area" floating label sits on top of "All areas". | Scheduling › Routing, Area picker |
| 8 | **Delete is styled like the safe action.** Delete has the same accent-outlined style as Deactivate, and the Job pane's Delete sits alone in accent blue. Destructive buttons should be red (Batch A did this only for Services). | Customers › customer panel; Jobs › job panel |
| 9 | **The first tap after closing a panel is lost.** After closing a customer with ×, the first tap on Memberships did nothing and the second worked. The job panel's × also needed a second tap. (Known desktop issue #1 of the open list, confirmed on phone.) | Customers, Jobs |

## 2. Patterns

- **Controls take 430–600 px before the first row**, more than half the phone screen:
  - Customers: intro line, Possible duplicates, Import/Export, Add customer, search, Show/Franchise/Sort.
  - Jobs: intro, Export/Add job, Money chips, search, Status/Where/Type/Tags, Upcoming/Past/All, totals.

  On phone, keep search plus one Filters button (as Leads does), and put Export, Import and duplicates in a ⋯ menu.
- **Chip rows run off the right edge with no fade or arrow:**
  - Jobs › Money ("● C…");
  - Estimates status (Draft / Sent / Accepted / …);
  - the Scheduling top tabs: 9 tabs, only 2½ visible, and "Re-Scheduling" through "Settings" can't be discovered without a sideways swipe.
- **The Scheduling tab strip recentres on tap,** so the tab next to the one tapped moves under the finger. Two taps meant for Routing → Locations landed on Routing → Helpers.
- **Tabs cut off inside panels:** in the Job pane, "Notes" shows as "Note…" and anything after it is off-screen.
- **Long spinners with no skeleton:**
  - Memberships › Members and Settings: over 5 s;
  - Scheduling › Schedule: about 15 s;
  - Scheduling › Overbooking: still spinning at 10 s;
  - Jobs list: about 15 s.
- **Truncated pickers:**
  - Customers "Show: Everyone (1,…";
  - Helpers "Branch: Everdoors · Co…";
  - Money chip "From Workiz ·…";
  - job row "Garage Door Rep…" and "JOB-2026-53235 · 1…" in the customer panel.
- **Colour on zero values:** Jobs totals "Overdue $0" in red, Job › Money "Paid $0.00" in green. Zero should be neutral.

## 3. Wording

- **"Franchise" / "franchises" is still used:**
  - Customers intro "The Franchise tag shows who is accountable", and the Franchise picker and "All franchises";
  - Jobs "26 upcoming across the franchises you can reach";
  - Estimates "0 estimates across the franchises…";
  - the New estimate "Franchise" field.

  Use the org's branch label.
- **Workiz still sounds in charge:**
  - Customer tab "Notes from Workiz";
  - Job › Money "Workiz has no invoice, payment or estimate on this job yet";
  - Schedule "Changes are saved here and copied to Workiz" (this one is fine).
- Job › Job shows "Total 802.50" with no $, while its tab reads "Money · $802.50".
- The Scheduling tab is labelled "Re-Scheduling"; elsewhere it is "Rescheduling".

## 4. Checklist (retry)

- [x] Customers › Customers: list, header controls, one record (Aaron Rose; Customer tab)
- [ ] Customers › customer record › Notes from Workiz tab (not opened)
- [x] Customers › Memberships: Dashboard (empty state)
- [ ] Customers › Memberships › Members and Settings: still spinning after 5 s
- [x] Jobs › Jobs: list, one job (Job and Money tabs)
- [ ] Jobs › job panel › Photos & files and Notes tabs (not opened)
- [x] Jobs › Estimates: List, Board, the New estimate form (cancelled)
- [x] Scheduling: Availability, Schedule, Routing and Helpers
- [ ] Scheduling: Overbooking (still loading), Re-Scheduling, Locations and Settings (not reached)

---

# Section 2


This covers Communication, Email, Marketing, Customer Experience and Automations, checked on app.ezbizi.com v0.0.0526 as Jose (A-1 Chimney) at 390×844 in the dark theme. It was read only: nothing was saved, sent, dialled, toggled or marked read, and no email message was opened. Forms were opened and then closed.

A caveat on load times: the tab ran in the background of the Browser pane, which only draws frames while it is on screen. The load times below are measured from that tab, so read them as upper bounds. They are still well beyond what a phone user will wait for.

## 1. Fix first (broken, or against a standing rule)

| # | What | Where |
|---|---|---|
| 1 | **Text squeezed to one letter per line.** "Facebook" wraps as F / a / c / eb / o / o / k because the "Instagram comments" link and "Disconnect" take the row's width. | Marketing › Social Media Management › Accounts, Facebook row |
| 2 | **Search box squeezed to "S…".** It sits between the "Mailings in A-1 Chimney" title and "New newsletter" and is about 110 px wide, too narrow to use. | Marketing › Newsletter › Dashboard |
| 3 | **Picker hangs off the right edge.** The "Phone account" field next to the Numbers title is clipped by the screen. | Communication › Settings › Numbers |
| 4 | **The switch contradicts the badge.** "Answer 4 and 5-star reviews automatically" is marked **Auto-disabled** ("Disabled after 5 consecutive failures") while its switch shows ON. The card says "Open Run history", but its ⋮ menu only has "Open and edit" and "Make a copy". | Automations › Your automations, Reviews group |
| 5 | **The complaints queue is full of bookings.** All 451 open "complaints" are New, and the ones shown are booked calls ("Agent books a level-two wood chimney inspection…", "Nelson booked a chimney sweep…"). The queue can't be used. This is a classification problem, not a layout one; send it to the CX owner. | Customer Experience › Complaints |
| 6 | **"Set it now" with nothing to set.** The person's signature panel says "Will be: Nothing yet: write the template first", but the blue "Set it now" button is enabled. At this width "In Gmail now" and "Will be" sit side by side, so the email breaks mid-address ("addie / @a-1chimney.com"). | Email › Google Workspace › a person (e.g. Addie Chimney) |
| 7 | **Only 1.5 rows of the list are visible.** On Numbers, the title, red banner, segmented control, search and 6 tiles stay pinned (about 470 px) and only the list below scrolls. Communication's own header (title, tab strip, Phone sub-chips on 2 rows) is pinned the same way, so Phone › Voicemail shows its first message at about 85 % of the screen height. | Communication › Settings › Numbers; Communication › Phone › Voicemail |
| 8 | **Red for normal states (11-02 #7, still there):** "Do not disturb" pills on Team; "needs a reply" on every unanswered review; "Red · of opened 0 · 0%" and "Complaint · of red 0" in red when the value is zero. | Communication › Phone › Team; Customer Experience › Reviews; Customer Experience › Review links |
| 9 | **Destructive actions in accent blue (11-02 #11, remaining spots).** Marketing › Accounts now has a muted Disconnect, but these are still accent blue: Disconnect on Marketing › Newsletter › Settings and on Email › Settings › an inbox; Delete beside New on Marketing › Newsletter › Composer; Delete on the Automations rule panel; "Block this caller" on a call record. | Listed in the row |
| 10 | **Chip wall (11-02 pattern, not converted):** 13 line chips over 4 rows plus 4 "About" chips fill the first screen, before any number. | Communication › Reports |
| 11 | **Emoji drawn as a box.** The post title "Your bricks may be revealing a moisture problem 👀" first shows "problem ▯" on the Dashboard timeline; the Calendar tab draws it correctly. The Dashboard's ▲ delta chip did the same once, showing "▯ 1%". This is the emoji font loading after the first frame. | Marketing › Social › Dashboard; Dashboard Jobs tile |

## 2. Patterns across modules (one fix each, many screens)

- **A pinned header eats the phone screen.** The module title, top tab strip and second-level chips (Communication's Phone chips wrap to 2 rows, leaving Voicemail alone) stay fixed, and on many screens the intro text, filters and tiles do too. What's left to scroll for the list:
  - Communication › Phone › Team: about 540 px.
  - Phone › Voicemail: the first row at y≈720.
  - Customer Experience › Reviews: the first review at y≈700.
  - Email › Settings: the intro and "Add an inbox" stay pinned.

  On phone, scroll everything below the tab strip with the content, and collapse the title once it has scrolled.
- **Stat-tile grids push the list below the fold**, usually 2×2 or more, plus a lone odd tile (sibling-tiles rule):
  - Phone › Team (5 tiles);
  - Records › Calls (6);
  - Reports (7);
  - Users (4);
  - Numbers (6);
  - Marketing Social Dashboard (5);
  - Newsletter Dashboard (3, a gap, then 2);
  - GBP Performance (5).

  On phone, fold them into one horizontally scrolling row of chips, or a "Summary" row that expands.
- **Search boxes are 2/3 width or less, and their placeholders are cut off:**
  - "Search name, number or t…" (Messages, Records, Voicemail);
  - "Search na…" (Users, beside the title);
  - "Name or what they s…" (Complaints);
  - Alerts (the box sits above the compose form, not over the list).

  `ListSearch` should go full width under 600 px.
- **Tab strips hide tabs with no hint**:
  - Communication: Phone, Users, Records, Rep…, then Alerts and Settings off screen;
  - Email: "Google Workspace" is cut;
  - Customer Experience: 7 tabs;
  - Marketing Social: "QR C…";
  - Web Management SEO: General, Social links, Pages, Cities, Posts, Templates, SEO H… as segmented pills.

  There is no fade or chevron at the edge. Tapping a half-visible tab only scrolls the strip, so it takes a second tap.
- **The first tap after a back arrow or a panel close is lost.** In Communication › Records (back from a call) and in Settings (back from a page), the next tap on a chip or row did nothing and had to be repeated. This may partly be the hidden pane's frame throttling; recheck on a real phone before fixing.
- **Long spinners with no skeleton**, measured from the background tab (see the caveat above):
  - Records (about 12 s);
  - Reports (about 15 s);
  - Alerts (about 12 s);
  - every Communication › Settings page (over 10 s);
  - Email › Inbox (about 35 s);
  - Email › Google Workspace (about 30 s);
  - Web Management › Widgets (over 40 s, never finished).

  Email › Settings already has a skeleton; copy it.
- **Popup DropdownButton fields** (banned pattern) on phone:
  - Reports When / Broken down / Direction;
  - Complaints Any kind / Anyone / All branches / All time;
  - GBP Listings "All branches";
  - GBP Performance;
  - Users' outgoing-number picker;
  - Email inbox "Add a person";
  - Call flow "Company".

  The branch ones should be the SearchPicker reading "Company · Branch".
- **Two-column layouts that don't stack:**
  - the signature preview (Fix first #6);
  - the Review links "Sources" table (Google / eKomi / Thumbtack), where "Not conne…" is cut and the counts wrap;
  - the Reports daily table, which drops its column headers and leaves bare "2 2 100%" rows.
- **Footers that are always visible take up height:**
  - Users' "Everything saved · Discard · Save" (90 px) shows even when nothing has changed;
  - the Build footer stacks a hint, Back / Step / Next and Cancel (about 170 px);
  - the rule panel stacks Delete / Make a copy / Try it / Edit on 2 rows.
- **Two "menu" icons:** Email shows the app's ☰ and, right under it, a second ☰ for mail labels, and the labels drawer adds a second Compose beside the FAB.
- **The Keypad / Compose FAB covers the last row's time and star** (Phone › Dial, Email › Inbox). Add bottom padding to the list equal to the FAB's height.

## 3. Wording and labels

- **Internal or vendor state shown to staff:**
  - "Publishing to Mailchimp is not built yet" and "Publishing to WhatsApp is not built yet" (Compose);
  - "EzBizi has no LinkedIn app set up on this installation yet… the missing piece is at EzBizi's end" (Accounts › Catalogue);
  - "Twilio charges for each message transcribed" (Voicemail).
- **Jargon:** "A connection is created at the view you are in. Organization sees every company below. Siblings do not share." (Accounts).
- **The company is named two ways:**
  - "A1 Chimney" (accounts, inboxes, listings);
  - "A-1 Chimney" (org picker, branches).

  "A1 Chimney · A1 Chimney" is repeated on Social posts.
- **Raw numbers:**
  - the call record title "Inbound · +15615773963" (the list formats it);
  - alert titles "+15139059434 - I paid for an …";
  - ISO dates "2026-10-08" in the Reports table.
- **The same count disagrees:** Call flow says "7 lines reach nobody"; Numbers says "8 lines are not reaching anybody".
- **Duplicated titles:** the back bar and the H2 both say "Call flow" / "Numbers" / "Inbound · +1…".
- **Lowercase place names:** "A1 Chimney · bellevue, WA" (GBP Listings).
- **Unlabelled numbers:**
  - Messages cards float a bare "1" / "2" on the right;
  - Web Management site rows show amber "10", "20", "16" badges;
  - the Users extension field shows "0108" with only "Starts with 0" under it.
- **Stray "—" separators** in Team rows ("Do not disturb — Message") and Records rows ("Ana — Answered — 0:07").
- **Hidden gesture:** "Press and hold a person to select them" (Google Workspace).
- **Placeholder rows:** "No list chosen — —" on Newsletter drafts.
- **Each run's failure is printed twice** (red banner, then the same text after ✕) in the rule panel's Recent runs.

## 4. Per-module checklist

**Communication**
- Phone: Dial ✓, Team ✓ (scrolled), Messages ✓ (no thread opened, since opening marks it read), Voicemail ✓.
- Users ✓ (scrolled; the picker was not opened).
- Records: Calls ✓, one call record ✓, SMS ✓; Voicemail chip not opened.
- Reports ✓ (scrolled to the end).
- Alerts ✓.
- Settings list ✓, Call flow ✓, Numbers ✓ (scrolled), IVR menus (spinner over 10 s, never loaded).
- **Not visited:** Settings Connection, Porting, Hours, Ring groups, Voicemail, Extensions, Blocked callers, Trust & caller identity, Tags, Cost and Messaging.

**Email**
- Inbox: the All list ✓, the labels drawer ✓. Primary, Leads & customers and N… were not opened, and no message was opened.
- Settings ✓, plus one inbox (accounting@) ✓ scrolled to the end.
- Google Workspace ✓, plus one person (Addie Chimney) ✓.

**Marketing**
- Social Media Management:
  - Dashboard ✓, with List and Board both viewed;
  - Compose ✓ (scrolled);
  - Calendar ✓;
  - Accounts ✓ (scrolled to Catalogue);
  - QR Codes ✓, plus the New QR code panel opened and closed.
- Newsletter: Dashboard ✓ (scrolled to the calendar), Composer ✓, Settings ✓.
- Web Management:
  - SEO › General ✓, plus one site (a-1chimney.com) ✓;
  - Social links ✓ (same site list);
  - Posts strip ✓;
  - Widgets (spinner, never loaded).
  - **Not opened:** Pages, Cities, Templates and SEO Helper.

**Customer Experience**
- Reviews ✓, plus one review ✓. The List / table / compact view toggles were not tried.
- GBP Listings ✓, plus one listing (New Jersey) Info ✓. The Media and Services sub-tabs were not opened.
- GBP Performance ✓.
- Posts to approve ✓ (empty).
- Review links ✓.
- Complaints ✓, plus one complaint ✓. The board view was not tried.

**Automations**
- Your automations ✓, with the ⋮ menu opened and the rule panel opened and closed.
- Build step 1 ✓. Steps 2–5 were not reached, because Next needs a name.

---

# Section 3


Group 3 is HR, Work Hours, Field, Fleet, Inspection, Inventory, Training and Guidelines.

- **Where:** app.ezbizi.com v0.0.0526, phone width 390×844, dark theme, A-1 Chimney scope.
- **Read only.** No save, send, approve, toggle, publish or delete was clicked. Forms were opened and closed with Cancel or ×.

## 1. Fix first (broken, or against a standing rule)

| # | What | Where |
|---|---|---|
| 1 | **Crash.** Below the search row the tab is a grey box. The console says "Type 'minified:Class5766' is not a subtype of type 'minified:Class5813<dynamic>?' in type cast". Widening the same tab to 1280 still shows grey, so it is not phone-only. | Inspection › Generated Reports |
| 2 | **Stock and record disagree.** The list says the 8" Oval Top Plate has "8 each · 2 places". Its record says "None on hand at a visible location", and History says "Nothing has moved yet". The record header reads "Triplex Group · General"; the Items list reads "A-1 Chimney · General" for the same item. | Inventory › Stock › item record |
| 3 | **Tabs you can't see.** Module tab strips show about 3 tabs. The rest sit off the right edge with no fade, arrow or "more" hint. HR has 7 tabs (Payroll is only reachable by scrolling); Work Hours, Field, Inspection and Inventory have 5 each. Inspection's Builder and Testing tabs aren't visible at all on load. | All module tab strips |
| 4 | **Tapping a half-visible tab doesn't open it.** The tap only scrolls the strip, so a second tap is needed. | HR record inner tabs, Inventory (Orders, Locations), Inspection (Builder) |
| 5 | **The record loses its header.** In a person's record, scrolling a short section (Pay, about 1 card) scrolls the name, back arrow and the 11 inner tabs out of view. What's left is an empty screen with no way back except the module tab. | HR › Records › person › Pay (any short section) |
| 6 | **One tap approves everyone.** A sticky full-width primary "Approve all 4" sits at the bottom of the scrolling list, right under the thumb. | Work Hours › Daily approval |
| 7 | **The day bar is broken.** The timeline bar under each person's hours draws as a 2 px stub or glyph at phone width. | Work Hours › Daily approval |
| 8 | **Red for normal states** (still there on phone): | |
| | • a red "The employee: 12 missing" pill on every row | HR › Onboarding |
| | • a red "Not signed" pill on every row | HR › Payroll |
| | • red/pink clock-out "17:00" pills | Work Hours › person week |
| 9 | **Centred dialog pickers**, against SidePanel, never dialogs: Role, Person, and the Company person picker. On phone they sit over the form as a dimmed full-height card. | HR › Invite somebody › Role; Field › Route › Person |
| 10 | **Lists without search** (the every-list rule). | Training › Dashboard › a test (43 people) |
| 11 | **Keyboard covers the results.** The search box sits mid-screen (y≈400) and results drop below it, so with the phone keyboard open you can't see them. | Inspection › New Inspection |
| 12 | **Map unreadable on phone.** It opens zoomed to all of North America with about 40 markers in one unclustered blob. Selecting a job pans its marker behind the 265 px People/Jobs panel. | Field › Map |
| 13 | **Destructive links styled as accents, beside the safe action.** "Revoke" on every invitation; "Cancel" next to "Open" on every onboarding. | HR › Invitations, HR › Onboarding |
| 14 | **Wrong status while loading.** A published guide shows "Draft — nobody sees it yet" and a Publish button for about 5 s, then flips to Published/Unpublish. | Training › Settings › guide editor |

## 2. Patterns (one fix each, many screens)

- **Filters and tiles push the list below the fold.** On phone, collapse these into one "Filters" button plus a one-line tile summary.
  - HR Records: the list starts at y≈460.
  - HR Payroll: about 370 px of filters.
  - HR Invitations: 6 tiles, so the list starts at y≈650.
  - HR Onboarding: 6 tiles.
  - Fleet Vehicles: 7 branch chips, the All row, search and 10 status chips put the first vehicle at y≈750.
  - Inventory: every tab has a header, Add button and 4 tiles before its list.
- **Search boxes and dropdowns at fixed desktop widths** (240–320 px), not full width. Placeholders truncate, e.g. "Search person, job or re…", "Job number or custo…", "During their scheduled work …".
  - Field Dashboard, Recordings, Map and Settings.
  - Work Hours Reports and Daily approval.
  - Training Dashboard.
  - HR Onboarding.
  - Inspection Testing (280/200/320, ragged).
- **Chip walls → SearchPicker:**
  - Fleet branch chips (7, two rows).
  - Work Hours › New report Roles (15 chips).
- **Branch pickers aren't "Company · Branch":**
  - Fleet vehicle and New vehicle use separate Company and "Franchise" dropdowns.
  - HR Records uses "Filter by company / All companies".
- **Long loads on every tab change** (5–11 s on Work Hours PTO, Daily approval, Field and Inspection), with only a spinner. A cold module load is about 15 s.
- **Times and dates mixed on one screen:**
  - Work Hours person: schedule "08:00 – 17:00" next to entries "9:00 AM".
  - HR record Work: "08:00 – 20:00".
  - Fleet Insurance: "2026-07-31" next to M/D/Y fields.
  - Money is unformatted: "1150.00 a week" in the HR record (Payroll shows "$1,150 a week"), "$6289.00" in Fleet.
- **Rows squeezed by trailing actions:**
  - Work Hours Reports: text sits in a ~150 px column next to Preview/edit/delete.
  - Field alerts: names wrap next to "Acknowledge".
- **Primary buttons enabled with nothing to save:**
  - Inventory item "Save count" with all fields empty.
  - Guidelines New guideline "Save" with no title.
  - Inspection Builder "Publish" while "Saved".
- **Opens on an empty state with an obvious default:**
  - Field › Route opens on the signed-in person ("No jobs on this day").
  - Inspection › Testing opens on "Choose a form" though only one form exists.
  - Inspection › New is empty until you type.
- **Sibling tiles of unequal height:** Work Hours person ("AVERAGE PER DAY" wraps). Buttons not aligned on Fleet General ("Add insurance" / "Assign keys").
- **Guidelines reader** uses 210 px cards with a big icon band, about 3 per screen. Its own Settings list is the compact form; use that for the reader too.
- **Possible clipboard write** on tab tap: the browser reported a page write to the OS clipboard when tapping Inspection › Generated Reports and Builder. Check nothing copies on a tab tap.

## 3. Wording and labels

- "Franchise" (Fleet vehicle and New vehicle); "a company covers its franchises" (HR record › Employment).
- "A1 Tools record (imported)", "Job title in A1 Tools" with raw value "local_field_manager" (HR record › Onboarding).
- Developer notes shown to users:
  - "This preview cannot read the contract paper size from Settings yet" (Contracts › designer).
  - "Sample guideline. Replace with the company's real procedure." (Guidelines › Upset customer).
- Raw field keys: "Filled from Client address line1" (Inspection Builder).
- Missing-glyph box: "▯ 838 vs 0 last period" (Inventory General › Movement).
- Plurals and duplicates:
  - "1 screens" (Training Settings).
  - "SHOPS 0 shops", "VANS 0 vans" (Inventory Locations).
  - Tile "STORAGE" vs pill "Warehouse".
- Guides counted as "18 passed" (Training Dashboard). A guide is finished, not passed.
- Contracts row "32 asked today" plus a bare unlabelled "Yes".
- Truncation:
  - "Massachuse…" (HR Dashboard).
  - "signed, waiting for app…" (HR Onboarding tile).
  - PTO timeline "5/13/20…", overlapping "Resets 5/13/2027 · 14 would carry over".
  - Guide title field "Dispatcher Introductio".
  - "1 · We ar…".
- Name shown twice: "Nick Frias" in the back bar and again in the header (Work Hours person). Duplicate names in the Person picker (Artem Maliuk ×2) with no branch to tell them apart.
- Emergency contact phone unformatted: "8153289436".
- Large duplicate "Settings" heading inside the Training › Settings tab.
- Unlabelled Chapter/Section boxes in the guide editor.

## 4. Per-module checklist (390 px)

| Module | Tabs visited | Opened |
|---|---|---|
| HR | Dashboard ✓, Records ✓, Contracts ✓, Invitations ✓, Onboarding ✓, Payroll ✓ (all 7 incl. strip end) | Person record: Personal, Emergency contacts, Employment, Pay, Work, Equipment, Onboarding (+ onboarding record Form). Not opened: Time off, Training, Notes, Access. COO contract designer: Set it up, The contract. Invite somebody panel and its Role picker. |
| Work Hours | General ✓, Paid Time Off (Balances, Requests) ✓, Reports ✓, Daily approval ✓, Settings ✓ | Person week (Nick Frias); New report panel |
| Field | Dashboard ✓, Map ✓ (legend, People, Jobs, job card), Route ✓, Recordings ✓, Settings ✓ | Person picker |
| Fleet | General ✓, Vehicles ✓ | Vehicle record (full scroll); New vehicle |
| Inspection | New Inspection ✓, Generated Reports ✗ (crash), Builder ✓, Testing ✓ | Client search results; Builder screen "Client and site" |
| Inventory | General ✓, Stock ✓, Items ✓, Orders ✓, Locations ✓ | Item record (8" Oval Top Plate) |
| Training | Learn ✓, Dashboard ✓, Settings ✓ | Test progress (Technician Training); guide editor (Dispatcher Introduction Video) |
| Guidelines | Guidelines ✓, Settings ✓ | Guideline (Upset customer); New guideline panel |

Not done: the HR record's Time off, Training, Notes and Access sections; Training "Continue" (it records progress); Work Hours "Preview" of a report. All were skipped as possible writes or for time.

---

# Section 4


Checked app.ezbizi.com v0.0.0526 at 390×844 (phone layout), signed in as Triplex › A-1 Chimney, dark theme, read only.

Covers Reports, Financials, PM-1, PM-2, Task Board, Company, Monitoring, AI Assistance, Development, Suggestions, Settings and Account.

Nothing that saves, sends, toggles or deletes was tapped. Forms were opened, then closed or cancelled. The auto-mode classifier blocked one tap ("No goal" on Company › KPIs), so whether goals can be edited from that row is unverified.

## 1. Fix first (broken, or against a standing rule)

| # | What | Where |
|---|---|---|
| 1 | **Settings for a branch can't be reached.** On a phone the pinned header (intro, List/Structure, search, Add company) plus the branch header takes about 820 of the 844 px. The settings list underneath scrolls inside a strip about 20 px tall. | Settings › Organization › open a branch (Connecticut) › Settings |
| 2 | **Label drawn one letter per line.** "Home address" wraps to one character per line in a column about 10 px wide, and the address text runs over the house icon. | Your account › You › Home address |
| 3 | **List rows have no labels.** Each row ends in "11/4/2026 11/4/2026 $0.00 $1,249.00 $1,249.00", the desktop table columns flattened with no headers. Nobody can tell which date or amount is which. The side panel labels them, but the list doesn't. | PM-2 › Pending, Ready for submission, Submitted |
| 4 | **Toolbar takes most of the screen; about 300 px is left for tasks.** From top to bottom: the What's new banner, the board name, My Board/Org Board, the 4 views, search + Statuses, Columns + avatars, People + ⋯. All of it stays pinned. | Task Board (every view) |
| 5 | **Purple is still there.** The "Recurring Tasks" and "Guidelines" group headers are purple. "Customer Experience" uses a red header, but it's a normal group, not a problem. | Task Board › Table |
| 6 | **Dialog on top of a panel.** "Set who they report to" opens a SidePanel with just one field, and tapping that field opens a centred dialog picker. The picker lists "Artem Maliuk" twice with nothing to tell them apart (no branch). | Company › Ownership; the same on Processes › Lead owner |
| 7 | **People list has an unnamed row, and the company appears as a person.** The first row has an empty name, and "A1 Chimney" is listed as a person. Tapping a row does nothing. | Company › KPIs |
| 8 | **Count says 0 while the list has rows.** "Answers this month 0" sits above an answer from Jose. API activity shows "0 changes" with "Changes only" switched on, yet lists changes. | AI Assistance › Answers; Settings › API activity |
| 9 | **Raw IDs shown to users:** "appzD9I7nNkALmw4l / tblhc4CJLOkMC…" source lines; the "WEEKLY_SUMMARY" key above a group name; the WhatsApp "*bold*" asterisks in the daily-text preview. | Financials › Settings; AI › WhatsApp groups; Reports › Workspace › PPC |
| 10 | **Destructive actions look like normal ones:** "Delete" in accent blue beside Save on a Financials record; "Delete…" in accent blue on a task; "Switch off" outlined like "Rename" on a branch. The ✕ on a WhatsApp group member is about 16 px and has no undo. | Financials › Deposits record; Task Board task panel; Settings › Organization branch; AI › WhatsApp groups |
| 11 | **Rows that do nothing when tapped:** Monitoring › General people rows, Company › KPIs rows. The "—  ⊕" desk control on each Monitoring row is about 16 px. | Monitoring › General; Company › KPIs |
| 12 | **Lists without search:** Settings › Integrations (about 20 services in 6 cards), Monitoring › Files device list, Company › Processes. | |
| 13 | **"Save" active with nothing to save.** A role shows "No changes yet." next to a solid blue Save. | Settings › Roles & permissions › Dispatcher |

## 2. Patterns across modules (one fix each, many screens)

- **Pinned headers eat the phone.** The page header, tab strip, intro, filters and search stay fixed and only the body scrolls. Content left:
  - Reports › Performance: about 460 px.
  - Task Board: about 300 px.
  - Settings › Preferences: about 400 px.
  - Settings › Organization › branch: about 20 px.
  - Below 600 px wide, let the whole page scroll, or collapse the filters behind a "Filters" button, as PM-1 already does.
- **Tab strips hide most tabs with no hint.** None has a fade or chevron, so a tab is often cut mid-word: "Lead…", "Fi…", "Camera Monitori…", "Roles & perm…".
  - Financials: 5 tabs. PM-1: 7. Monitoring: 8. AI Assistance: 8. Settings: 6, plus a second chip row of about 12 Preferences sections.
  - Reports does it best: one dropdown that lists all 10 sections.
- **Date ranges clipped.** The Today / Yesterday / Last 7 days / Last 30… chips run off the edge.
  - Reports: Performance, Scoring, Marketing, Meta, Thumbtack; also Workspace › Period/PPC.
  - The date button truncates too ("Last week Mon, Oct …").
- **Search boxes about 240 px wide** with clipped placeholders: "Find a branch or sour…", "Find a category or br…", "Job, customer, addre…", "Find a company or br…".
  - On a phone the search should be full width, like Reports › Workspace and P&L › Categories.
- **Tables kept as tables.** Fixed for Financials › Deposits and Workspace › Period, which still need cards:
  - Financials › Deposits shows "Current Week" ("Previous Weeks" on every row) as the first column.
  - Workspace › Period shows Branch, which is the same on every row when a Place is picked, then Source. The counts are off-screen to the right.
  - PM-1 › Rules: the stage table's middle column wraps to 5 lines.
- **Dates typed as text:**
  - Financials › Bills › Type one in: "From (YYYY-MM-…" and "To (YYYY-MM-…".
  - Financials › Lead source results › Add result: "Date (YYYY-MM-DD)".
  - Financials › Invoices › New sheet already uses date pickers; use the same field.
  - ISO dates are shown in Financials records ("2026-04-14") and Account › Gender and date of birth.
- **Pickers stacked at different widths:**
  - Reports › P&L: Base 260, Table 220, Week 240.
  - Settings › Activity: When / Who / Module.
  - Reports › Meta/Thumbtack: State / Category / Where.
  - Settings › API activity: Person / Key.
  - On a phone make them all full width, or put them behind one Filters panel.
- **Filter dropdowns open the keyboard.** Meta's State filter is an editable DropdownMenu, so a tap opens the phone keyboard for a one-option list ("All").
- **Same thing repeated in a row:**
  - PM-1 › Assignments: the job number 3 times ("Paid: JOB-50913 …", "Job #JOB-50913" link, "Job #JOB-50913").
  - Reports › Performance › Calls: the phone number twice.
  - PM-1 job timeline: "Skipped [Skipped]".
  - Scoring person: "Analysis by AI" as the title and again as the link.
  - Account › Where you are signed in: the app bar and the card both say "Where you are signed in".
  - Task Board: "My board ▾" over "My Board | A-1 Chimney Board".
- **Gutters vary between 8, 12, 16, 20 and 40 px:**
  - Reports › Workspace search sits at 8 px.
  - AI › WhatsApp groups and Financials › Invoices use 12.
  - Company › Processes and Settings use 20–24.
  - Reports › Workspace report and Overbooking warning detail are nested panels at 40 px.
- **Cards fixed at desktop height leave 60–80 px of empty space:** Settings › Integrations cards and service detail, Monitoring › Camera cards (a trash icon alone under the image).
- **Branch shown as bare state names, not "Company · Branch":**
  - PM-2 rows ("New Jersey"), Company › Ownership ("Illinois" next to "A-1 Chimney").
  - Financials › Deposits uses state chips (NJ · 452, CT · 171 …).
  - Settings › branch chip reads "Franchise".
- **Copy written for desktop:**
  - Settings › Organization › Structure: "narrowing or collapsing the sidebar".
  - Roles: "the same tabs … as the sidebar".
  - Account: "Theme and sidebar".
  - There is no sidebar on a phone.
- **Long loads with only a small spinner:** Company › KPIs (about 10 s), Reports › P&L (about 20 s; it has a skeleton, which is better).

## 3. Wording and labels

- "franchise" / "Franchise": Settings › Preferences › Default timezone text, the Organization branch chip, Structure "Franchise in A-1 Chimney".
- **Title Case:**
  - "Profit And Loss" (P&L Base).
  - Financials record fields: "Status Of Payment", "Status Of Job", "Total Job Amount", "Outstanding Balance".
  - "Ready for submission" vs the row chip "Done, pending approval" (PM-2 Ready tab).
- **Activity sentences don't read as English:** "An automation phone sms sent by automation", "Ara Awe marked lead", "An automation replied automation lead" (Settings › Activity).
- Lowercase platform names: "windows" (Monitoring › Files).
- Raw phone format "+18559553322" (PM-1 › Logs) and "+14048243631" (task panel).
- "?·?" placeholder and ✔️ / ✘ emoji on the Correct/Incorrect buttons (AI › Answers).
- Two refresh times on one screen: "refreshed 23 min ago" in the header, "Refreshed 14 min ago" in the body (Financials › Deposits).
- "Done" with an ✕ icon closes the field picker; ✕ reads as cancel (Reports › Report Builder).
- Orphan labels: "Show" floats right of the search with its chips on the next line (Reports › Performance › People); "84" floats beside the search (Monitoring › PC Specs).
- Task panel title is the company ("A-1 Chimney") rather than the task.
- Financials record title is the first field ("Previous Weeks") rather than the job number.
- Suggestions: "Attach a screenshot or file" comes after "Send it"; the intro text runs straight on from the title with no space; the search box sits above the new-suggestion form.

## 4. Checklist: what was opened

- **Reports:**
  - Reports list (All / Work Hours / Scheduling chips, + New report, Overbooking warning detail).
  - Report Builder (Add field picker).
  - Performance › Overview (scrolled to Not booked list), People, Calls.
  - Scoring (list, person detail, Weights panel).
  - Marketing (expanded a row).
  - Meta (State filter), Thumbtack.
  - Workspace (Period table both scroll directions, PPC daily text).
  - Google Ads (account picker).
  - Profit & Loss (scrolled to Categories).
- **Financials:**
  - Home (area tiles, bases); Deposits area (row record panel).
  - Bills (Type one in form).
  - Invoices › Charge sheets (New sheet form), Invoices, Settings.
  - Lead source results (Add result form).
  - Settings (Airtable sources).
- **PM-1:** Overview; Jobs (job timeline); Assignments; Repair team; Rules; Logs; Settings (scrolled).
- **PM-2:** Pending (job panel), Ready for submission, Submitted.
- **Task Board:**
  - My Board and A-1 Chimney Board.
  - Table (scrolled to Customer Experience, subtasks, task panel), Kanban, Calendar (No date list), Timeline (empty state).
  - Not tapped: Show me / Not now on the banner.
- **Company:**
  - Ownership (report-to panel + picker).
  - Processes (Lead owner panel).
  - KPIs (list; the goal tap was blocked).
- **Monitoring:** General; Map; PC Specs; Files; Camera Monitoring; Desktop Monitoring; Lighting. No device was opened in Files, and no Go live / Take control.
- **AI Assistance:** AI; Library; Parts; Manuals; Answers; WhatsApp groups; Staff bot; Settings (scrolled).
- **Development:** blocked at the authenticator-code gate, which needs Jose's code. Not audited past the lock screen.
- **Suggestions:** form and search (no past suggestions listed).
- **Settings:**
  - Organization: List, branch Details + Settings, Structure, Add company form.
  - Preferences: All settings, plus the chip row enumerated: Scheduling, Field, Work Hours, Leads, Communication, Email, Newsletter, Memberships…
  - Integrations (scrolled; Facebook detail).
  - Roles & permissions (Dispatcher role).
  - Activity; API activity.
- **Account:**
  - Your account (all sections; Where you are signed in).
  - Account menu (Your account / Get the apps / Sign out; nothing tapped beyond Your account).

Out of scope here, seen in passing: the drawer lists about 30 modules with no search or jump.
