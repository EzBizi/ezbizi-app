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
10. **Every table scrolls sideways**: scrollbar, shift-wheel, trackpad and holding the wheel button to drag. Notices never stay up for good.
9. **Cutoff:** in about a week (around 11-25), when Jose says so. Then turn off Monday's own automations and stop the push. Until then everything stays two-way.

## Open

| # | Ask (Jose) | Owner | Status | Proof / next step |
|---|---|---|---|---|
| 1 | Complaint / review tasks use the board's own tags: **Area** and **Reason** from the job; **Address**, **Client name**, **Phone** from the job/customer; Reason worked out per task (Missed Voicemail, Estimate Inquiry, …), not "Complaint" for everything; Resolution left empty (11-19) | Automations | WORKING | Code merged 45ba73799 (TaskRecordFill: Area from the customer's state, Reason worked out per task, Address/Client/Phone from the job; never Resolution); ships in 0603, then rules + backfill --redo-reason. Done 09:20 ET: "Description" removed (309 values → Reason, "Bad Review" label added, Area set on 256) and queued to Monday as live rows. STILL OPEN: Area from the job's location (not the branch); Reason per task, not Complaint for everything; Address/Client/Phone from the job; read back on MA and NJ › Customer Relations - Natalie on both sides. Task Board: stop its one-off commands from recreating "Description" |
| 2 | Flag tasks ("Address needs fixing") must not sit in Company Info; they go to **Open Tasks** (11-19) | Task Board | WORKING | 37 moved to Open Tasks (0 address flags left in Company Info, measured). TaskWriter default → Open Tasks for every writer (629c2033c), ships in 0604; the desk watch alarms on any new one |
| 3 | Job-id columns right after Name, **EzBizi before Workiz**, on Monday too (11-18) | Task Board | WORKING | New columns created in the right place on 13 boards. Values copying (24k left at 08:15 ET, Monday's daytime limit). Old columns removed only at 0 mismatches. Task taskboard-jobid-columns-finish runs every 2 h |
| 4 | Board order in the sidebar (company board first; reorder boards) | Task Board / UI | WORKING | Shipped in 0597/0599; desk to check the sidebar order on prod |
| 5 | Flag tasks for bookers with no EzBizi account (12) | Bar | WAITING | Bar to say who they are |
| 6 | Unknown-caller title and long titles | Automations | WORKING | Shipped 0598; desk to check today's tasks |
| 7 | 2 "Job #" titles left: one empty "job #" (nothing to name it from); 174 number-only titles (151 are phone numbers) | — | DONE by rule | Left on purpose; Jose can say otherwise |
| 8 | **Cutoff** | Desk | WAITING | Jose says when |
| 9 | Sideways scroll: the scrollbar existed but the "Task deleted · Undo" notice never closed and covered it. Fixed app-wide (notices with Undo time out again; test added; ships in 0603). **Still wanted (Jose 11-19): hold the mouse wheel button and drag to scroll sideways** | Desk (notice) / Task Board (wheel-drag) | WORKING | Notice fix + hold-the-wheel-and-drag panning (with test) both ship in 0604; check in the Windows app after deploy |
| 10 | Items staff create on Monday as "Job #N - Name" get renamed to the name live, with the number moved to the columns and pushed back to Monday (5 made 11-19, e.g. "Job #800020972 - Kirstin Deimel") | Task Board | WORKING | 9 fixed today; live rename on import in 0604 (629c2033c). 4 made today before it is live; check after deploy |
| 11 | Team boards: right-click → **Move up / Move down does nothing** (11-19) | Task Board | WORKING | His click saved, but A-1 was already first so nothing could move. Move up is now off on the first board and Move down on the last; test; SHA pending |
| 12 | Owner of rule-made tasks missing from Monday's Person column (e.g. MA › Natalie: 3 items show Person empty on Monday; EzBizi has Natalie Escobar) | Task Board | WORKING | Cause: Monday refuses the whole Person write if one owner (Adeline) isn't on the private board. Fix bb8ec4eac sends the rest and notes who was refused; backfill after deploy |
| 13 | The **Organization has its own board**: Monday's "Triplex Group - Files" (private) becomes the org's board in Team boards (11-19) | Task Board | WORKING | Same board linked, not a copy; keeps Monday's members (private); read back access and parity |
| 14 | **A-1 Chimney's company board = Monday's "A1 - Operatrion"** (private); link them as one board, merging the EzBizi board's tasks in (11-19) | Task Board | WORKING | Jose 11-19 chose: keep it PRIVATE. A1 - Operatrion is THE A-1 board (its 4 members); the company board's 224 tasks move into it; shown after Triplex |
| 15 | **Reorder groups** on a board (drag the header, or Move up/down), saved and mirrored to Monday's group order (11-19) | Task Board | WORKING | Widget test plus a read-back on prod and Monday |
| 16 | **Deleting a group asks for the authenticator code** (any delete of a group/board) (11-19) | Task Board | WORKING | Server refuses without a fresh code; tests; never delete_group on Monday |

## Other asks from Jose (not Task Board, kept here so nothing is lost)

| # | Ask (Jose) | Owner | Status | Proof / next step |
|---|---|---|---|---|
| B1 | Backups have limits: **7 nights on the app server, 3 nights of files off-site, ONE monthly copy** (11-19) | Desk | DONE (applied) | Script 96dd2ca89 installed. Websites server went from 97% to 76% full. Old copies deleted now; the rules dry-run on both servers. Tonight's run must show "backup ok" and "restore ok" |
| B2 | Backups RED 2 nights: the 1 h limit killed the run (files grew 8.6 → 40.7 GB a night) and the off-site disk was full | Desk | WORKING | Limit raised to 6 h (4b0df0038, installed); check tonight's 03:18 UTC run |
| B3 | Incremental file backups (each night only what changed) | Desk (helper) | WORKING | Being built with restore tests; install after review |
| B4 | Air 75, Roofirst, Crestline Home as sub-companies of A-1 Chimney, flattened (11-19) | Desk | DONE | 9 companies now directly under A-1 Chimney (API read back); old layout saved in companies-before-flatten-2026-11-19.json |
| B5 | Who handles complaints for the non-chimney brands (garage doors etc.)? | Jose | WAITING | Question below |

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
