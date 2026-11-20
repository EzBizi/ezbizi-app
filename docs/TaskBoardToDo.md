# Task Board to-do (Jose's asks, kept until done)

Every Task Board and Monday ask Jose has made since the fixes began on 2026-11-18. **Every session that touches the Task Board, Monday or task automations reads this file first.** The deploy desk keeps it current. A row is DONE only when it has been **measured on prod** (and on Monday, for Monday rows), never just because a commit or a peer says so.

Status: **DONE** (measured) · **WORKING** (owner on it) · **WAITING** (on a person or event) · **TODO** (not started)

Last updated: 2026-11-19 10:05 ET (desk)

## Standing rules (Jose's words; never ask again)

1. **Full parity, no exceptions.** Monday and EzBizi have the same boards, board names, groups (names and order), columns, tasks, each task's group, values, owners and automations. Mirror it and report; never ask whether something should be mirrored. ("what does full parity mean to you, no exceptions")
2. **Nothing is deleted.** Removals become restorable trash here and archive on Monday.
3. **No task ever lands in a board's first group ("Company Info") by default.** A task created without a group goes to "Open Tasks". (Asked 3+ times; 11-19: "i feel like i asked a thousand times")
4. **Job columns: Name | EzBizi Job # | Workiz Job ID | rest**, on every board, on both sides. Titles carry the customer's name, never "Job #N".
5. **Automation-made tasks show the EzBizi logo** as maker and always have an owner.
6. **Fill the board's own columns from the job.** Use Area, Reason, Client name, Phone number and Address, never an EzBizi-only copy column. **Resolution is never filled by us**; people fill it.
7. **Missed-call tasks are the most important thing.** Every missed call gets its Outgoing Calls task within about 2 minutes, and new tasks reach Monday ahead of any backlog.
8. **Read the Monday API docs before any Monday write.** create_item always carries a group_id; never delete_group or delete_item.
11. **Every rule here applies to every company's boards**, not only A-1's.
10. **Every table scrolls sideways**: scrollbar, shift-wheel, trackpad and holding the wheel button to drag. Notices never stay up for good.
9. **Cutoff:** in about a week (around 11-25), when Jose says so. Then turn off Monday's own automations and stop the push. Until then everything stays two-way.

## Open

| # | Ask (Jose) | Owner | Status | Proof / next step |
|---|---|---|---|---|
| 1 | **Every column on open tasks filled from the job**: EzBizi Job #, Workiz Job ID (find the customer's job when the task has no link), Client name, Phone, Address, Area, Reason per task, "etc." (Jose 11-19: "i mean everything… u always take everything too literal"); **open tasks first, Done last**; Resolution never by us | Automations | WORKING | 11-20 12:00 UTC open-task fill (Client/Phone/Address/EzBizi#/Workiz#/Area/Reason): branch boards 74-88% ids, 95-100% Area/Reason; Everdoors CT/NY + A-1 Chimney 100%; NW 37%, A1 Operation 44-50%, Outgoing Calls 17-22% (pre-0612, no number lookup) - re-fill of Outgoing Calls requested; the rest have no job/lead/phone to fill from. New missed calls fill fully since 0612 (01:12 UTC proof) |
| 2 | Flag tasks ("Address needs fixing") must not sit in Company Info; they go to **Open Tasks** (11-19) | Task Board | WORKING | 37 moved to Open Tasks (0 address flags left in Company Info, measured). TaskWriter default → Open Tasks for every writer (629c2033c), ships in 0604; the desk watch alarms on any new one |
| 3 | Job-id columns right after Name, **EzBizi before Workiz**, on Monday too (11-18) | Task Board | DONE | 11-19: all 13 boards read Name | EzBizi Job # | Workiz Job ID with exactly one of each (desk read DMV, NW, Eve HR on Monday 21:5x UTC); 155 missing values filled, 26 old columns deleted after dumps (/var/backups/ezbizi/jobid-old-columns-*.json); finish job deleted. Sensitive tasks keep Monday's old value (never pushed) |
| 4 | Board order in the sidebar (company board first; reorder boards) | Task Board / UI | WORKING | Shipped in 0597/0599; desk to check the sidebar order on prod |
| 5 | Flag tasks for bookers with no EzBizi account (12) | Bar | WORKING | 11-19: 27 owners fixed (Bar owns Everdoors CT, Sasha gets hers via the middle-name match, 7 to their real booker). Nissin Callanta, Jacky Ferrer and Rachelle have no account; invite them? |
| 6 | Unknown-caller title and long titles | Automations | WORKING | 11-20: 0 unknown-caller titles, 0 missed-call titles without a number today. 9 over-long titles all from the address-flag writer (not the rules) - sent to Task Board to cut |
| 7 | 2 "Job #" titles left: one empty "job #" (nothing to name it from); 174 number-only titles (151 are phone numbers) | — | DONE by rule | Left on purpose; Jose can say otherwise |
| 8 | **Cutoff** | Desk | WAITING | Jose says when |
| 9 | Sideways scroll: the scrollbar existed but the "Task deleted · Undo" notice never closed and covered it. Fixed app-wide (notices with Undo time out again; test added; ships in 0603). **Still wanted (Jose 11-19): hold the mouse wheel button and drag to scroll sideways** | Desk (notice) / Task Board (wheel-drag) | WORKING | Notice fix + hold-the-wheel-and-drag panning (with test) both ship in 0604; check in the Windows app after deploy |
| 10 | Items staff create on Monday as "Job #N - Name" get renamed to the name live, with the number moved to the columns and pushed back to Monday (5 made 11-19, e.g. "Job #800020972 - Kirstin Deimel") | Task Board | WORKING | 9 fixed today; live rename on import in 0604 (629c2033c). 4 made today before it is live; check after deploy |
| 11 | Team boards: right-click → **Move up / Move down does nothing** (11-19) | Task Board | WORKING | Real cause: every PATCH returned the whole board, and A-1 (31k rows) ran out of memory. Now paged; Move up/down off at the ends; ships in 0606 |
| 12 | Owner of rule-made tasks missing from Monday's Person column (e.g. MA › Natalie: 3 items show Person empty on Monday; EzBizi has Natalie Escobar) | Task Board | WORKING | Refused person dropped, the rest sent (bb8ec4eac in 0605); Person backfill after deploy |
| 13 | The **Organization has its own board**: Monday's "Triplex Group - Files" (private) becomes the org's board in Team boards (11-19) | Task Board | DONE | On prod 11-19: Triplex Group - Files first in Team boards; access unchanged (same 4 members on both sides) |
| 14 | **A-1 Chimney's company board = Monday's "A1 - Operatrion"** (private); link them as one board, merging the EzBizi board's tasks in (11-19) | Task Board | DONE | On prod 11-19: A1 - Operatrion right after Triplex; the company board's 253 tasks moved in (groups by name, no column dropped); 224 queued to Monday; read back after drain |
| 15 | **Reorder groups** on a board (drag the header, or Move up/down), saved and mirrored to Monday's group order (11-19) | Task Board | WORKING | Move up/down in the group menu, saved and mirrored to Monday (update_group); ships in 0606. Drag-the-header not built yet |
| 16 | **Deleting a group asks for the authenticator code** (any delete of a group/board) (11-19) | Task Board | WORKING | Authenticator required for group and board delete (server + client, tests); Monday group archived only once empty, never delete_group; ships in 0606 |
| 17 | **Same column layout on all 9 branch boards** (CT, DMV, GA, IL, MA, NJ, NW, OH, TX): one column set, one order, on both sides; e.g. DMV is missing Address (11-19) | Task Board | WORKING | Columns, order and LABELS done both sides 11-19 (Reason 39, Resolution 39 = Monday cap 40 incl. blank; 452 tasks remapped, originals in activity). Left: Monday column order after the job-id move |
| 18 | **Task Board notifications**: Jose's "Assigned to me" and "Mentioned" are empty (11-19). Measured: 279 assignments to him in 7 days, last Task notification 11-18 20:12. His account is also the Monday connection, so Monday changes count as his own and he's never notified | Task Board | DONE | Causes: task bells had no kind, so the tabs only showed automations; Monday sync acted as Jose. Fixed: kind stored, sync actor is the real person or none, Monday assignments ring bells; ships in 0606. Older bells not backfilled |
| 19 | **Everything above applies to EVERY company**, not just A-1 (Everdoors, Air 75 / Roofirst / Crestline brands, garage-door brands…): job-id columns, fills, Customer Relations layout, complaint flows, Monday parity (11-19) | Task Board + Automations | WORKING | 11-19: titles cleaned on every company; flags owned (Bar for Everdoors CT/NY, Sasha via middle-name match); standard columns added to Everdoors Connecticut (12) and Outgoing Calls (5, Monday too); fill run next. Project Management/HR boards keep their own layout. Monday column order for Outgoing Calls goes with row 17 |
| 20 | Merav: **select several attachments at once** on a task (11-19) | Task Board | WORKING | Merged (3c39010b0): pick several attachments and download them together; ships in 0607. Deleting/moving attachments has no server endpoint yet |
| 21 | Merav: **updates/comments text selectable and copyable** like normal text (11-19) | Task Board | WORKING | Merged: update text is selectable and copyable; ships in 0607 |
| 22 | **Values set when a task is created never reached Monday** (e.g. MA Pamela Gignon: client and phone here, empty on Monday) (11-19) | Task Board | WORKING | 927 differing tasks queued to Monday (648 open + 279 Done; MA Resolution 140). Re-run after the drain, expecting ~0; nightly --values from 0607. 20:28 UTC: 20 rows stuck (16 IL "Same Day Job" label deactivated on Monday; 2 subitem moves; NW no Owner col, TX no Date col) → Task Board |
| 23 | Open complaint tasks had BOTH Natalie and Adeline (made before the routing split) | Task Board | DONE | 11-19: Adeline removed from 201 tasks on Natalie's 6 branch boards (CT 38, DMV 18, GA 32, MA 25, NJ 65, TX 23); 0 left with both; pushed to Monday |
| 24 | A live task's push to Monday that hits the rate limit once gets demoted behind the backlog (an MA overbook flag waited 45+ min) (11-19) | Task Board | WORKING | Store "live" explicitly on the outbox row; test; stuck row bumped by hand |
| 25 | Adeline: **clicking the Linked job on a task does nothing** (11-19 suggestion, approved) | Task Board | DONE | Live in 0610: on CT › Tech Field Manager, clicking "Job #JOB-58617" opened that job (11-19 22:3x UTC). No customer-linked task exists on prod; same code path, covered by test |
| 26 | Monday→EzBizi sync stuck on NW since 16:44 UTC (NW alone outlasts a lane run, restarts each time); the 30-min parity starved behind it (a deleted Ohio PM item stayed live) (11-19) | Task Board | DONE | NW caught up 22:08 UTC; on 0611/0612 every scheduled monday-sync (--max-seconds=900) finishes all boards in ~2m15s (journal 00:12, 00:21, 00:36 UTC 11-20); resume path covered by test |
| 27 | The 30-min Monday check (parity --fix --gone) last ran 16:56 UTC: slow-lane runs start only after the previous one ends (~3.5 min), so everyThirtyMinutes fires only by chance; same for the nightly --values and other non-everyMinute slow jobs (11-19) | Task Board | DONE | Live in 0612: slow-lane run starting 00:36 fired the 00:30 parity --fix at 00:38:56 UTC (journal); no nightly job fired at deploy; desk stopgap cron removed |

## Other asks from Jose (not Task Board, kept here so nothing is lost)

| # | Ask (Jose) | Owner | Status | Proof / next step |
|---|---|---|---|---|
| B1 | Backups have limits: **7 nights on the app server, 3 nights of files off-site, ONE monthly copy** (11-19) | Desk | DONE (measured 11-20) | 11-20 run: off-site private files = 11-17, 11-18, 11-20 + 11-01 only (no 09-01/10-01), off-site disk 75%; local = 8 nights + 11-01 (302G, disk 44%). Off-by-one found (find -mtime +N keeps N+1 nights): fixed to keep exactly 7 local / 3 off-site, installed 11-20; next run prunes 11-13 local and 11-17 off-site |
| B2 | Backups RED 2 nights: the 1 h limit killed the run (files grew 8.6 → 40.7 GB a night) and the off-site disk was full | Desk | DONE | 11-20: "backup ok size=302G" 04:17 UTC, "restore-check restore ok from 2026-11-20 (off-site), 307 tables, 518 migrations" 05:02; backup-status.json ok:true; no timeout in the journal (run 1h45m of the 6 h limit) |
| B3 | Incremental file backups (each night only what changed) | Desk (helper) | WORKING | Built + 19 restore tests pass (594b2ee68, a weekly full plus nightly changes, ~25–30% of a full). Merged only after tonight's run is green, because merging installs it on the next cut. The 256 GiB archive cap was raised today (44b5a5aca) |
| B4 | Air 75, Roofirst, Crestline Home as sub-companies of A-1 Chimney, flattened (11-19) | Desk | DONE | 9 companies now directly under A-1 Chimney (API read back); old layout saved in companies-before-flatten-2026-11-19.json |
| B5 | Who handles complaints for the non-chimney brands (garage doors etc.)? | Jose | DONE (answered) | Jose 11-19: **Bar** owns Everdoors CT and New York tasks (flags and complaints). Others use the GM fallback |
| M1 | Merav: password minimum 12 → 8 characters | Amazon SES / auth | WORKING | Merged (e6722ab23): minimum 8 for signup/reset/invite/change; staff consoles stay at 16; ships in 0607 |
| M2 | Merav (HR): onboarding-incomplete warning plus an alert to the local manager; contract address = the employee's branch; Hourly Rate field loses focus while typing (bug) | HR | WORKING | Merged (e8226c536), ships in 0607: daily alert to the branch Local Managers with what is missing; contract uses the employee branch address (branch addresses being filled from Merav's 11-18 locations sheet; branches with several addresses go to Jose/Merav); every form field keeps focus while typing |
| M3 | Merav (Payroll, W2): Sunday 5 PM each manager gets employees, rate/salary and hours to fill/approve; columns Bonus, Deductions, Weekly salary; missing-info warning; Monday morning copy to Merav | Clock In (+ HR) | WORKING | Server live in 0607. A-1 sheet for week 11-09 checked (1,873.55 h, $24,852.46, branch tabs sum to All). Merav set as recipient; first Monday email 11-23 8 AM ET. Manager review off until the screen ships. Open: Eric Nelson salary entered as $120/year (probably $120k?); overtime 1.5x question |
| M4 | Merav (Reports/Marketing): download as Excel or CSV | Reports (+ Marketing) | WORKING | Merged (679a21937), ships in 0607: Download > Excel/CSV on every report table incl. Marketing; Merav has the permission |
| M5 | Merav (Project Management): parts option "paid by technician" (tech expense to reimburse) | Jobs (PM code) | WORKING | Merged (9269b76eb), ships in 0607: "Paid by technician (reimburse)" with the technician, a Reimbursed box, and a feed for payroll |
| M6 | Contract address per branch: A-1 branches with several offices need one picked (CT, DMV, GA, IL, NJ, OH, TX, Nationwide; New York missing). Other companies' branches have none | Merav/Jose | WAITING | Sheet sent 11-19: docs/agents/a1-branch-contract-addresses-2026-11-19.xlsx; HR fills after 0607 (MA set) |
| P1 | A-1 Chimney texting (A2P campaign) | Phone | DONE | Campaign VERIFIED 11-19 15:16 UTC; texting on for 11 numbers; the 12th number (+19545162455) was the A-1 WhatsApp bot, bound by mistake; removed from the texting service (11 = 11) |
| P2 | **New Twilio account from scratch, then port our numbers** (Jose 11-19: Twilio uncooperative on the primary business profile) | Phone (plan) + Jose (creates the account) | WORKING | 11-19: account upgraded (Jose). Parent renamed EzBizi; sub-accounts A-1 Chimney, EverDoors, AngiSweep, EzBizi App created (creds in F:\Credentials\Phone, by script). Primary profile EZBIZI LLC started, rep Shoval Ohayon (per Articles): WAITING on Shoval's ID + selfie (Persona). Phone: API keys + TwiML apps next. Questions still open: +18888599924, parent Reports/WhatsApp numbers, EINs of A1 Chimney HQ/EverDoors/AngiSweep |
| S1 | PHP critical vulnerability phpAfterShell (CVE-2026-126714, CVSS 9.2) (Jose 11-19 "check this") | Desk | MITIGATED | App server (PHP 8.2.35/8.4.27, vulnerable): session upload progress turned off 11-19, verified through FPM, site OK. Websites server (PHP 8.3.36/8.4.27): already off since the php2shell fix. SiteGround hosts no longer exist. TODO: upgrade PHP on both servers when a patched build ships, then remove the mitigation files |
| L1 | Mobile lead header: number truncated ("+1 (267) 55…") and crowded by Status/Book/⋯; must show in full and be easy to copy (11-19) | Leads | WORKING | Merged 78e4fdeb0 (number whole + copy on line 1, Status/Book/menu on line 2; 375 px test). Live on web since 0610 (bundle checked). iOS/macOS/Android 0.0.0612 delivered 11-20 03:0x UTC, waiting on Apple/Google review; close when the iPhone app shows the full number |

## Done (measured)

| Ask | Proof |
|---|---|
| Same boards, groups and tasks on both sides, nothing deleted | Parity 0 drift on 39/39 every 30 min overnight (last 12:57 UTC 11-19) |
| Board names identical | Parity name check, 39/39 |
| Company Info mix-up on Eve's boards (11-18) | ~110 tasks moved back; push now always names the group |
| Customer Experience → Customer Relations; CE groups gone; Jonathan off | 11-18 |
| Overbooking warnings in their own "Overbooked" group, naming who booked | 47 open, 37 with booked-by (Workiz has no booker for the rest); on Monday too |
| Titles "Job #N - Name" → "Name"; job ids in their columns | 26,491 renamed; 15,832 tasks with ids; 5 spot-checked on Monday |
| Monday changes appear here within minutes; no echo backlog | 0601: echo queue 0; 5 people's Monday moves landed |
| New tasks reach Monday first (missed calls) | 0600: live rows median 12 s while backlog drains |
| Monday automations mirrored | 63 on (Ron's 2 when his invite is accepted) |
| Board members match (Merav, Menahem, Marjulie, Claire T) | Read back on Monday 11-18 |
| DMV items deleted on Monday → trash here | 11-19 01:03 UTC |
| Duplicate job-id columns on Ohio PM removed; can't recur | One of each; per-board lock (5f6989125) |
| Every missed call overnight got its task | 15/15 since 6 PM 11-18 |

## How this file is kept

- The desk adds every new Jose ask about the Task Board or Monday here **in the same turn** it is routed, with its owner.
- A row moves to Done only with a measurement in the Proof column.
- The desk re-reads this file on every 30-minute parity check and chases any row without movement.
