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
| 2 | Flag tasks ("Address needs fixing") must not sit in Company Info; they go to **Open Tasks** (11-19) | Task Board | WORKING | Move the 33 on 9 branch boards; TaskWriter defaults a missing group to Open Tasks (every writer); the desk's 2-minute watch alarms on "company-info" |
| 3 | Job-id columns right after Name, **EzBizi before Workiz**, on Monday too (11-18) | Task Board | WORKING | New columns created in the right place on 13 boards. Values copying (24k left at 08:15 ET, Monday's daytime limit). Old columns removed only at 0 mismatches. Task taskboard-jobid-columns-finish runs every 2 h |
| 4 | Board order in the sidebar (company board first; reorder boards) | Task Board / UI | WORKING | Shipped in 0597/0599; desk to check the sidebar order on prod |
| 5 | Flag tasks for bookers with no EzBizi account (12) | Bar | WAITING | Bar to say who they are |
| 6 | Unknown-caller title and long titles | Automations | WORKING | Shipped 0598; desk to check today's tasks |
| 7 | 2 "Job #" titles left: one empty "job #" (nothing to name it from); 174 number-only titles (151 are phone numbers) | — | DONE by rule | Left on purpose; Jose can say otherwise |
| 8 | **Cutoff** | Desk | WAITING | Jose says when |
| 9 | Sideways scroll: the scrollbar existed but the "Task deleted · Undo" notice never closed and covered it. Fixed app-wide (notices with Undo time out again; test added; ships in 0603). **Still wanted (Jose 11-19): hold the mouse wheel button and drag to scroll sideways** | Desk (notice) / Task Board (wheel-drag) | WORKING | Notice: committed, goes out in 0603. Wheel-drag: widget test plus a check on the Windows app |
| 10 | Items staff create on Monday as "Job #N - Name" get renamed to the name live, with the number moved to the columns and pushed back to Monday (5 made 11-19, e.g. "Job #800020972 - Kirstin Deimel") | Task Board | WORKING | Fix the 5; fix the import for new ones; read back on both sides |
| 11 | Team boards: right-click → **Move up / Move down does nothing** (11-19) | Task Board | WORKING | Widget test plus a check on prod after reload |
| 12 | Owner of rule-made tasks missing from Monday's Person column (e.g. MA › Natalie: 3 items show Person empty on Monday; EzBizi has Natalie Escobar) | Task Board | WORKING | Fix the push + backfill; read back the 3 on Monday |

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
