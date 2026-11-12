# Monday → EzBizi Task Board: migration plan (read-only survey), 2026-11-12

Read-only. Monday was read with the monday MCP connector (GraphQL queries,
board_insights, activity logs) as **Jose Demasi (monday id 75953979, jose@a-1chimney.com,
account kind "member", not admin)** on the enterprise account a-1group.monday.com.
EzBizi was read from the repo and from prod with SELECT-only tinker
(org Triplex Group 019fd712-74fd-712c-b179-fd88052cc371). Nothing was written anywhere.

Earlier work this builds on: `docs/reviews/2026-11-03/monday-survey.md` (open items only),
`app/Console/Commands/TasksImportMonday.php` (ezbizi:tasks-import-monday), `docs/agents/monday-port-taskboard.md` (UI port).

## 0. What I could NOT see (read this first)

1. **Private boards Jose is not on.** The API returns only boards Jose owns/subscribes to plus public ones. Jose is a monday *member*, not admin. Main workspace has 20+ folders that look empty to him (Connecticut, New Jersey, Dallas-Fort Worth, Atlanta, Chicago, DMV, Field Management, Field Managers, Partners x2, Email marketing, Triplex Group, Speedy Garage Doors, A1 Garage Doors, Crestline Home, AngiSweep, Roofirst, 12 "New Folder"s). Workspace "A1 Chimney" (9609748, owner Bar Ohayon) also shows 0 boards. Anything private in them is invisible. Other people's personal boards (e.g. Eve, Esti, Natalie, Eric, Shoval, the Sharper Process agency users) are invisible unless Jose was added. **Only a monday admin (Shoval, Chaim, Bar, Merav, Eve are admins) can list everything.**
2. **Items hidden by item-level permissions ("assignee" boards where Jose is not owner).** Jose sees only items assigned to him: Everdoors - Brand 2 of 16, Everdoors - MA Franchise 1 of 24, New Brand Template 2 of 16, New Franchise Template 1 of 24, New Location Template 11 of 25. **88 items hidden.** Activity logs are refused on these 5 boards, so their archived items are unknown too.
3. **Archived items and archived groups: content not readable at all.** `groups` returns only live groups; `items(ids:[archived id])` returns nothing (tested with item 10807636318). Only the activity log tells that they exist. Counted from `archive_group_pulse` / `archive_pulse` events:
   Toula 24 (5 archived groups: Open Tasks, Recurring Tasks, Guidelines, Company Files and Access x2), Analorena 4, Rodrigo 2, Jose 1, Jonathan 1 (+6 empty groups), Adeline 1, NJ 3, Brian 0 (6 empty groups), DMV 0 (1 empty group), **NW ≥ 223** (groups "Jobs Not Booked - Jared" ≥200 — log page cap hit, not walked further — "Sales Executive Manager - Kai" 23, "Mateo - Outgoing Calls" not counted). 0 on MA, GA, TX, CT, OH, IL, Outgoing Calls, both Project Management boards.
   To carry them, someone with rights must **unarchive them in monday first** (a write, not done here).
4. **Deleted boards** (78 entries incl. subitem boards and docs, state=deleted: AngiSweep x9, Crestline x3, Roofirst x2, Reviews Tracking, Campaign boards, Employees, Field Managers, projects DMV, Project Management, welcome boards...). Metadata only; not proposed for import.
5. **Deleted monday users**: ~37 distinct "Deleted member" ids own ~10,000 NW items (identity gone: email is `<id>@deleted.user`).
6. **Exact update/file counts on the big boards.** The API has no count; paging probes are de-duplicated by the connector. Personal-board numbers below are from a full listing; branch boards are sampled estimates. The importer's dry run gives exact numbers.

## 1. Boards Jose can see

Workspaces visible: Main workspace (9607979), "dieuve ticoit Vibes" (17047697), "A1 Chimney" (9609748, empty to Jose), CRM (10177468, deleted).
**29 boards** (27 active + 2 archived) + 25 subitem boards. Items = live items (monday `items_count`; done = in a Done/Completed group or Done label). Subitems = live subitems.

"Type" key: P = personal, B = branch (franchise), C = company-wide, PR = project pipeline, T = template/checklist, X = not task data.

| # | Board (id) | Kind | About | Type | Items (open / done) | Subitems | Archived (unreadable) | Updates / files | Last activity | EzBizi target |
|---|---|---|---|---|---|---|---|---|---|---|
| 1 | Jose Demasi (8343765247) | private | Jose | P | 102 (32 / 70) | 124 | 1 | ~170 + ~60 replies / ~75 files | 11-12 | personal board of **Jose Luis Demasi** |
| 2 | Rodrigo Mena (9204581187) | private | Rodrigo | P | 62 (10 / 52) | 75 | 2 | ~125 / ~250 files | 10-09 | personal board of **Rodrigo Mena** |
| 3 | Analorena - Business Relations (8343738207) | private | Analorena | P | 47 (19 / 28) | 70 | 4 | ~80 / ~20 | 11-10 | personal board of **Analorena Ventoso** (41 items' Person = deleted member 76893540 → assign Analorena) |
| 4 | Adeline - Customer Relations (18379051971) | private | Adeline | P | 2 (2 / 0) | 0 | 1 | 3 / 0 | 11-06 | personal board of **Adeline Pierrelouis-Aghaji** |
| 5 | Toula Elwell (18393449992) **archived board** | private | Toula | P | 167 (10 / 157) | 23 | 24 | ~250 / ~55 | 11-09 | personal board of **Toula Elwell** (inactive in EzBizi → board shows as archived, admins only) |
| 6 | Brian Scheets - Recruiting (18432440457) | private | Brian (recruiting, groups per state) | P | 40 (23 / 17) | 3 | 0 (6 empty groups) | ~92 / ~86 (résumés) | 11-10 | personal board of **Brian Scheets** (decision D3) |
| 7 | Jonathan - Recruiting (18431210057) | private | Jonathan | P | 3 (3 "Company Files" / 0) | 3 | 1 | 2 / 0 | 11-10 | personal board of **Jonathan T** |
| 8 | IL - Illinois (18424929266) | private | IL branch | B | 1,416 (38 / 1,378) | 41 | 0 | est. ~2.4/item + ~1 reply/item; files rare | 11-12 | **A-1 Chimney › Illinois** franchise board |
| 9 | OH - Ohio (18426052010) | private | OH branch | B | 771 (65 / 706) | 12 | 0 | est. ~1.5/item | 11-12 | **A-1 Chimney › Ohio** franchise board |
| 10 | NJ - New Jersey (18426054807) | private | NJ branch | B | 906 (19 / 887) | 5 | 3 | est. ~1.5/item | 11-12 | **A-1 Chimney › New Jersey** |
| 11 | CT - Connecticut (18426444271) | private | CT branch | B | 606 (24 / 582) | 10 | 0 | est. ~1.5/item | 11-12 | **A-1 Chimney › Connecticut** |
| 12 | DMV (18426446570) | private | DMV branch | B | 292 (13 / 279) | 1 | 0 | est. ~1.5/item | 11-12 | **A-1 Chimney › DMV** |
| 13 | TX- Texas (18427055247) | private | TX branch | B | 310 (9 / 301) | 0 | 0 | est. ~1.5/item | 11-12 | **A-1 Chimney › Texas** |
| 14 | GA - Georgia (18435168865) | private | GA branch | B | 311 (12-14 / 299) | 1 | 0 | est. ~1.5/item | 11-12 | **A-1 Chimney › Georgia** |
| 15 | MA - Massachusetts (18435169766) | private | MA branch | B | 130 (8 / 122) | 0 | 0 | est. ~1.5/item | 11-12 | **A-1 Chimney › Massachusetts** |
| 16 | NW - Nationwide (10084134034) | private, assignee perms | all branches' call log | B | **24,645 (17 / 24,628)** | 22 | **≥223** | est. 1.5-2/item + ~2 replies/item; ~0.1 file/item → **~40-90k updates, ~2k files** | 11-12 | **A-1 Chimney › Nationwide** franchise board (decision D1) |
| 17 | Outgoing Calls (18435173396) | private, assignee perms | outbound/missed calls, all branches | C | 403 (12 / 391) | 0 | 0 | ~400 / 0 | 11-12 | **A-1 Chimney company board**, group "Outgoing Calls" (or split by Area → branch; D2) |
| 18 | Ohio Project Management (18432743116) | private | OH job pipeline | PR | 159 (72 / 87) | 0 | 0 | ~1.1/item / 0 | 11-12 | custom main board "Ohio Project Management" under **A-1 Chimney › Ohio** |
| 19 | Illinois Project Management (18435687196) | private | IL job pipeline | PR | 115 (70 / 45) | 0 | 0 | not sampled | 11-12 | custom main board "Illinois Project Management" under **A-1 Chimney › Illinois** |
| 20 | Everdoors - Brand (18399940557) | private, assignee | Everdoors brand checklist | T | 16 (Jose sees 2) | 0 | ? | 3 | 03-09 | **Everdoors company board** (needs admin export for the 14 hidden) |
| 21 | Everdoors - MA Franchise (18399940654) | private, assignee | Everdoors MA (Yosef) | T | 24 (Jose sees 1) | 0 | ? | 1 | 03-09 | **Everdoors company board**, group "MA Franchise" (no MA franchise under Everdoors in EzBizi) |
| 22 | New Location Template (18390213000) | private, assignee | template | T | 25 (sees 11) | 0 | ? | 9 | 03-09 | skip, or custom private board "Templates" (D5) |
| 23 | New Brand Template (18392349013) | private, assignee | template | T | 16 (sees 2) | 0 | ? | 1 | 03-09 | same as 22 |
| 24 | New Franchise Template (18392356461) | private, assignee | template | T | 24 (sees 1) | 0 | ? | 1 | 03-09 | same as 22 |
| 25 | All Calls (9342236072) **archived board** | private | phone call log (Direction, Call ID, Session ID, phones) | X | 52,684 (99 in New/Existing Client, rest History) | 0 | — | — | 10-05 | **do not import as tasks** (D4) |
| 26 | Users (18280738661) | public | monday's resource directory | X | 149 | 0 | — | 0 | 11-09 | skip (EzBizi HR holds people) |
| 27 | Job Roles (18280738648) | public | monday's role list | X | 0 | 0 | — | 0 | 2025-10-27 | skip |
| 28 | Analytics Dashboard (18426918714) | public, Vibes ws | dieuve's vibe app data | X | 5 | 0 | — | 0 | 08-17 | skip |
| 29 | Dark Theme (18426919447) | custom_object, Vibes ws | vibe app object | X | 0 | 0 | — | 0 | 08-17 | skip |

Owners/subscribers (who each board is shared with):
- Personal boards 1-3, 5: owners Shoval, Chaim, Bar (+ Jose on 2, 3, 5; + Analorena on 3); subscribers add the named person (Rodrigo, Analorena + Adeline + Jennine, Toula). 4: owners Shoval, Jose, Eve, Eric; + Adeline. 6-7: owners Shoval, Jose, Eve, Jonathan T, Eric (+ Brian on 6).
- Branch boards 8-16 and Outgoing Calls: 41-47 owners each = the whole dispatch floor (all remote dispatchers, branch managers, admins) + the monday AI agents (Daily WhatsApp Summary Agent, Aurora, Dora, Elena) as subscribers.
- Ohio PM: Shoval, Chaim, Bar, Merav, Jose, Eve, Adeline, Jared, Esti, Chris Restivo, Shneor, James Forsythe. Illinois PM: Shoval, Chaim, Jose, Eve, Adeline, Jared, Eddie Alvi.
- Everdoors/templates: owners Shoval, Chaim, Bar; subscribers + Merav, Jose, Eve (+ Rodrigo on Location Template).

### Columns → EzBizi fields

EzBizi built-ins: title, group, status (per-board list with is_done), assignees (many), due date, creator ("Owner"), created, updates, about (job/lead/customer/vehicle). Custom column types: text, number, status, date, person, dropdown, checkbox, timeline, formula, long_text, currency, phone, email, url, datetime, tags, link (customer/job).

| Monday column (where) | EzBizi |
|---|---|
| Name | title |
| Group | group (same name, colour, order) |
| Main Status (`status`) on personal + branch boards | board **status**; label → status of same name; **done = label index in monday `done_colors`** (only "Done" on every board measured — "Done - Pending Review", "Done - Testing", "Done - Waiting Payment" are *not* done in monday) **or item sits in a Done/Completed group** |
| Analorena "Task Date" (status col named oddly) + "Status" (color_mm72ba0k) | Task Date → status column "Task Date"; Status → board status |
| Ohio/Illinois PM "Job Status" | board status ("Done & Paid" is the done label); "Area", "Job Type Label", "Need To Be Assign To:", "Parts Status" → status columns with the same labels |
| Person (people) | **assignees** (all people, not just first); on branch boards it is 91-96% empty → see D1 group→person rule |
| Reason, Area, Resolution(s), "Status 1", Reminder, Priority, Assignment, Category | custom **status** column, same labels and colours |
| Due Date / Date (date) | due date when the board has one date called Due Date; otherwise custom date column |
| Date Received, Last Contact Date, Resolution Date / Date Resolved, Sale Date, Schedule Date, Alternative Schedule Date, Parts Arrivel Date | custom **date** columns |
| Timeline (timerange) | custom **timeline** column (exists since 11-03; the current importer still writes it as text — fix) |
| Notes, Text, Items, Time Slot, Job Amount/Amount Due/Job Cost (text on IL PM) | custom text (Job Amount etc. → **currency** if they parse as numbers) |
| Job Cost (numbers), Late Tally (numbers) | number / currency |
| Total Cost (formula, Ohio PM) | **formula** column re-created as Job Cost-based expression; or frozen number (D6) |
| Production job (checkbox) | checkbox |
| Link (link) | url |
| Subitems Link (mirror, Adeline) | text snapshot (2 items) |
| Dependent On (dependency, Everdoors/templates) | **no equivalent** → task links (task_links) "related", or drop (templates only) |
| Files (file column: All Calls; Ohio PM subitems) | attachment on a synthetic first update ("Files from monday") — EzBizi has no file column |
| Board relations (All Calls) | none; All Calls not imported |
| Item name "Job #500034398 - Customer" (branch boards) | also set **about = job** when `jobs.number` matches the number (match rate unmeasured) |
| Subitem columns (Owner, Status/Category/State, Date, Link, Reason, Area, Timeline, Text) | subtask fields; subitem-only columns become board columns (they share the parent board in EzBizi) |

## 2. People map (Monday → EzBizi, prod users read 11-12)

Matched by email first, then name; "?" = needs a yes from Jose/peer before the import trusts it.

| Monday user (id, email) | EzBizi user | How | Note |
|---|---|---|---|
| Shoval Ohayon 71278475 info@ | Shoval Ohayon info@a-1chimney.com | email | Administrator |
| Chaim Perez 71282049 chaim@ | Chaim Perez | email | |
| Bar Ohayon 71282063 baro@ | Bar Ohayon bar@ezbizi.com | name | |
| Jose Demasi 75953979 jose@a-1chimney.com | Jose Luis Demasi jose@ezbizi.com | name | |
| Eve Sarfaty 75968508 dismanager@ | Eve Sarfaty eve@a-1chimney.com | name | |
| Merav Peretz 72081101 | Merav Perez | email | |
| Menahem Maamari 71550546 | Menahem Maamari | email | |
| Rotem Hadad 71550313 ben@ | Rotem Hay Hadad rotemhadadrop@gmail.com | name | |
| Rodrigo Mena 76335844 | Rodrigo Mena rodrigo@ezbizi.com | name | |
| Netanel mullakand 77278007 | Netanel Mullakand netanel14@gmail.com | name | probably "Nate" in "Project Manager - Nate" |
| Adeline Pierrelouis-Aghaji 85643017 dispatcher1@ | Adeline adeline@a-1chimney.com | name | |
| Sasha Solano 85643548 dispatcher2@ | Sasha marie Solano | name | |
| Jared Prescott 85643565 dispatcher3@ | Jared Prescott prescottj41@yahoo.com | name | |
| Trey Brannan 95492386 | Trey Brannan | email | |
| Toula Elwell 97762119 | Toula Elwell toula@ezbizi.com | name | **inactive** in EzBizi |
| Analorena Ventoso 98357372 ana@a-1chimney.com | Analorena ana@ezbizi.com | name | |
| Chevel Richards 102787048 | Chevel Richards | email | |
| Jonathan T 103866240 | Jonathan T | email | |
| Marjulie Quino 106590015 | Marjulie Quino (other gmail) | name | |
| Jason R 110533901 | Jason Roman | email | **inactive** |
| Esti Platner 111544959 | Esti Platner | email | |
| Christina Paul 112408822 dispatcher5@ **and** 112700238 christina@ | Christina Paul christina@ | name/email | two monday accounts → one EzBizi user |
| Natalie Escobar 112488957 | Natalie Escobar | email | |
| Eddie Alvi 113014480 | Adnan Alvi eddie@ | email | |
| dieuve ticoit 113596659 | Dieuve Ticoit | email | |
| Tiara Maranon 114363462 | Tiara Marañon | email | |
| Ranya Lara 114508955 | Ranya Lara | email | |
| Nelly escobar 115062467 | Nelly escobar | email | |
| Lunique Mazard 115188114 | Lunique Mazard lmazard@yahoo.com | name | |
| Stephen Deets 115203904 | Stephen Deets (gmail) | name | **inactive** |
| Jovanni Martin 115297534 | Jovanni Martin | email | |
| 27.10ayala@gmail.com 115835962 | Ayala Kenig | email | |
| Lenin Corrales 115879789 | Lenin Corrales (icloud) | name | |
| Jennine Lizer 117383205 | Jennine Lizer (gmail) | name | |
| Brian Scheets 117391389 | Brian Scheets | email | Recruiter |
| Danika Kaye 117468844 | Danika Kaye | email | **inactive** |
| Annalyn Mombay, Mildred Fredas, Sheryl Del Rosario, Aeron Sibug, Hyanne Faelnar, Frederick Delijero, Rolando De Lara, Angela Herrera, Marydem Lucenara, Bryan Lagazon, Cristine Hicale, Claire T (Claire Tiwo), Carlo Surigao, Cgrey Lumacad, Ara Awe | same names | email | remote dispatchers |
| ruzel Jake Yanson 119500544 | RJ Yanson | email | **inactive** |
| adolf flores 119500556 | Adolf Flores | email | **inactive** |
| Lucas Snyder 118572642 | Lucas Snyder | email | NJ field manager |
| Nicholas Frias 119275557 | Nick Frias | email | TX field manager |
| Chris Restivo 119416853 | Kristopher Restivo | email | |
| Eric Nelson Valdes 121241524 | Eric Nelson Valdes | email | COO |
| James Forsythe 121515946 james@ | James Forsythe (gmail) | name | |
| Liraz Sapir 102475856 liraz@ | **? Leroy Sapir** lirsap992@gmail.com | guess | confirm |
| BRI 113111180 briana@ | **? Briana montano** | guess | confirm |
| gino@a-1chimney.com 119382561 | **? Gino Russo-Folco** grussofolco@gmail.com | guess | 66 Outgoing Calls items |
| Shneor@a-1chimney.com 119418504 | **? shneor Cohen** ksn770@gmail.com | guess | OH "General Manager - Shneor" |
| Melissa Oca 102074414 phoeberudd19@ | **? Melissa Lav** melissalav19@gmail.com | guess | CT "Tech Field Manager - Melissa"; 779 NW items |
| **No EzBizi account:** Moshe Hay Zion 71549513 (moses@), Nehorai Maamari 71550764 (mike@), Yosef Abergel 101217584 (office@ma.everdoors.com), Tal Menahem 102151709, Ron Paz 102475875, Jeanette Del Rosario 102798762 (1,085 NW items), Criz Samson 121481405 (DMV "Field Manager - Crizzel"), Sharper Process agency x5 (michelle@, amandam@, austin@, aj@, charissa.a@; amsterdam@ pending), 2 pending invites | — | — | import unassigned, name kept in a note line ("Assigned in monday: X") |
| monday AI agents: Elena, Dora, Daily WhatsApp Summary Agent, Aurora | — | — | not people; skip as subscribers |
| ~37 "Deleted member" ids (≈10k NW items, 41 Analorena items, 15 Outgoing Calls items) | — | — | unassigned + note "former monday user #id"; Analorena's 41 → Analorena (her board) |

Group-is-the-assignee (branch boards; Person is empty on 91-96% of items): Administration - Eve → Eve; Accounting - Esti → Esti; Customer Relations / Complaints - Natalie → Natalie; Customer Relations - Adeline → Adeline; Field Manager - Lucas/Claire/Carlo/Eddie → Lucas Snyder / Claire Tiwo / Carlo Surigao / Adnan Alvi; Technician Field Manager - Nicholas → Nick Frias; - Jared, Mar → Jared Prescott (+ Marjulie?); - Crizzel → none (Criz Samson has no account); Tech Field Manager - Melissa → ? Melissa; Project Manager - Chris → Kristopher Restivo; - Nate/Claire → ? Netanel (+ Claire); - Darren → **? Derren Almeida** (no monday user named Darren); General Manager - Shneor → ? shneor Cohen.

## 3. Gaps and the decisions they need

Real gaps (EzBizi has no equivalent):
- **G1 Archived monday items/groups are unreadable by API** (≥ 259 known, ≥223 on NW). Only fix: unarchive in monday, then import. → **D7**.
- **G2 Hidden items** (88 on Everdoors/templates) and **hidden private boards**: need an admin export (Shoval/Chaim/Bar/Merav/Eve) or Jose added as owner. → **D7**.
- **G3 Replies are flat.** `task_updates` has no parent/thread; replies become separate updates prefixed "↳" in time order (current importer does this).
- **G4 No item description field** on tasks. Descriptions were empty on the 30 Jose items checked; if any exist, they become the first update.
- **G5 No file column.** Files live only on updates (25 MB, 10 per update, fixed extension list). Monday file-column values and update assets → re-uploaded as Attachments on the update (or on a synthetic "Files from monday" update). Files over 25 MB or of other types: kept as a link note. Monday asset URLs expire in 1 h, so download in the same run.
- **G6 No dependency column** (templates/Everdoors only) → task_links or drop.
- **G7 No mirror/board-relation columns** → text snapshot (Adeline, 2 items).
- **G8 No recurring tasks** ("Recurring Tasks" groups on 4 personal boards are just groups — no engine on either side).
- **G9 No per-board rule "column = X → assign Y"** and no "date arrives → move group" automation (Ohio PM's one automation). Already in the 11-03 survey; not import blockers.
- **G10 Assignee must be reachable on the target board.** TaskWriter refuses assignees the board cannot take (importer then drops them: "owners dropped"). Remote dispatchers are owners of every monday branch board but may not be members of each EzBizi franchise board → add them as board members before import, or accept the drop.
- **G11 The previous import collides.** 11-03 run created 8 custom boards, 240 tasks, 445 updates with `external_ref monday:<id>`; all 8 boards and their tasks were soft-deleted together on 2026-11-09 20:56:37. The unique index (organization_id, external_ref) still holds those rows, but the importer looks them up *without trashed*, so re-importing the same items will fail on duplicate key. → **D8**.
- **G12 Importer reads a JSON file, not monday.** It sets every new status `is_done=false`, writes timelines as text, ignores files, ignores group→person, ignores done/completed_at. Needs the changes in section 4.

Decisions (only the real ones):
- **D1 NW - Nationwide (24.6k done items, ~40-90k updates).** (a) import everything onto the Nationwide franchise board as done tasks; (b) import open (17) + last N months, archive the rest as a CSV/JSON export kept on the server; (c) split by Area to each state's franchise board. Recommendation: (a) on a *separate* custom board "Nationwide call log (monday)" scoped to A-1 Chimney › Nationwide so live CX work is not buried — it is history, not work. Volume is the one thing Jose should choose.
- **D2 Outgoing Calls**: company board group vs split by Area (state) into branch boards. Recommend company board (it is run centrally by Analorena/Tiara/Gino).
- **D3 Recruiting boards (Brian, Jonathan)**: personal boards vs one custom "Recruiting" board (groups = state hirings, members Brian, Jonathan, Eve, Eric, Shoval). Recommend custom board; résumé files are personal data — private visibility.
- **D4 All Calls (52,684 archived call rows)**: not tasks; EzBizi phone history already has calls. Recommend: do not import; optional raw JSON archive.
- **D5 Templates (3) and Everdoors (2)**: skip, or import as a private "Templates" board. Recommend import Everdoors to the Everdoors company board, skip templates (only 14 of 65 items visible anyway).
- **D6 Ohio PM "Total Cost" formula**: re-create as EzBizi formula or freeze values.
- **D7 Archived + hidden content**: does Jose want it badly enough to (i) have an admin unarchive groups/items on NW, Toula, Analorena, Rodrigo, Jose, Jonathan, Adeline, NJ and (ii) run the read as an admin (or add Jose as owner on Everdoors/templates/private boards)? Without it, the migration is "everything Jose can see", stated as such.
- **D8 Previous import rows**: restore-and-reuse the 240 soft-deleted tasks (keeps their EzBizi ids/notes, if anyone touched them) vs force-delete them first. Recommend: importer matches `withTrashed()`, force-deletes trashed monday-ref rows on the target before re-creating, in the same transaction; nobody worked on them (all updated 11-03..11-07, deleted 11-09).

## 4. Import approach

Command: extend `ezbizi:tasks-import-monday` (do not fork it):

1. **Two stages, same keys.**
   - `ezbizi:monday-export --board=<id>... --out=storage/app/monday/<date>/` reads monday GraphQL with a token, pages `items_page(limit:500)` with column values, subitems, `updates(limit:100){ id text_body body created_at creator{email} replies{...} assets{id name url file_size} }`, writes one JSON per board (resumable by cursor; NW alone is ~50 pages). Downloads assets right away (1 h URL life) into the same folder, keyed by asset id.
   - `ezbizi:tasks-import-monday <dir> --org= --as= --map=people.json --dry-run` imports from those files. Never reads monday during the write.
2. **Idempotent, keyed on monday ids.** `external_ref = monday:<board id>` (boards), `monday:<item id>` (tasks and subtasks), `monday:<update id>` / `monday:<update id>.<reply id>` (updates; use the reply's own id, not its index), `monday:asset:<id>` (attachments). Look-ups use `withTrashed()` (G11). A re-run updates title/status/group/assignees/values of an existing task when monday's `updated_at` is newer (so a final delta run on cut-over day catches last edits); it never duplicates.
3. **Dry run** (`--dry-run`, default on first run): whole run in one transaction per board, rolled back; prints per board: tasks new/updated/skipped, subtasks, updates, files (count + MB), statuses/groups/columns to be made, people unmatched, assignees dropped (G10), items whose job number matched a job.
4. **Targets** from a mapping file (board id → `personal:<email>` | `franchise:<company>/<franchise>` | `company:<company>` | `custom:<name>@<scope>`), per section 1.
5. **Done items**: status from the label; `is_done` from monday `done_colors`; items in Done/Completed/Completed Tasks/Completed Hirings/EzBiZi - Done groups are done even if the label is blank. `completed_at` = the status value's `changed_at` (in the column value JSON) or the item's `updated_at`. Groups kept as they are (Done stays a group).
6. **People**: `people.json` from section 2 (email/name matches + confirmed guesses); group→person rule for branch boards when Person is empty; unmatched → unassigned + one line "Assigned in monday: <name>". Creator from monday `creator.email`. Pre-step: add the needed users as board members (G10), reported in dry run.
7. **Updates**: `text_body` (not HTML), monday `created_at` kept, author by email, mentions converted to EzBizi mentions *without* notifications; replies flattened "↳" (G3). No automation events (importer already swaps EventEmitter).
8. **Files**: each update asset → Attachment on that update (respecting 25 MB / extension list; others logged as "file not carried: name, size"). File-column values → one synthetic update "Files from monday" dated the item's created_at.
9. **Archived**: only what is unarchived in monday before the export (D7). Imported archived items land done, in their original group name.
10. **Order**: personal boards → PM boards → branch boards (small first) → Outgoing → NW last (dry run first, then real). Final delta run on cut-over day.

## 5. Rough counts

- Boards visible: **29** (7 personal, 9 branch, 1 company, 2 project, 2 Everdoors, 3 templates, 5 not-task) + 25 subitem boards; 78 deleted entries.
- Task-relevant items (boards 1-24): **30,592** live — **~455 open, ~30,030 done**, 105 template/Everdoors items of which Jose sees 17. NW is 24,645 of them (80%).
- Subitems: **390** (Jose 124, Rodrigo 75, Analorena 70, IL 41, Toula 23, NW 22, OH 12, CT 10, NJ 5, Brian 3, Jonathan 3, DMV 1, GA 1).
- Archived (not readable): **≥ 259** known.
- Hidden by permissions: **88** items + unknown private boards.
- Updates: personal boards ~720 + replies; branch/company boards estimated **~45-100k incl. replies** (mostly NW). Files: personal boards ~490 (Rodrigo ~250, Brian ~86 résumés, Jose ~75, Toula ~55, Analorena ~20); branch boards ~0.1 per NW item sampled (~2-3k), 0 in the IL and Ohio PM samples.
- People: 77 monday member accounts (+3 pending invites, 4 AI agents, ~37 deleted members). **59 matched** (41 by email, 18 by name; 58 EzBizi people, Christina Paul has two monday accounts), **5 probable** (need a yes), **12 without an EzBizi account** (5 Sharper Process agency users, Moshe Hay Zion, Nehorai Maamari, Yosef Abergel, Tal Menahem, Ron Paz, Criz Samson, Jeanette Del Rosario with 1,085 NW items), 6 matched users are **inactive** in EzBizi (Toula, Jason R, Stephen Deets, Danika Kaye, RJ Yanson, Adolf Flores).
- EzBizi today: 158 users, 33 company + 43 franchise + 158 personal boards, 0 live custom boards, 454 live tasks; the 11-03 import's 8 boards / 240 tasks / 445 updates are soft-deleted (G11).
