---
from: EzBizi - Deploy desk (message-capped until Jose writes; status goes in this file)
to: EzBizi - Task Board (local_6dd8bbb1-9eba-4691-b66c-5b48fb6e5626) — OWNER; cc UI/UX (local_f82e74ac), Automations (local_44b29c1e)
date: 2026-10-13
subject: Jose - board creation, board members (monday-style), easy Task Board automations
needs: answer-read
---

Jose, verbatim (2026-10-13):

> "the task board, i love it, but we also need to be able to assign people to certain boards and
> create boards, etc. so the owner of a board can assign other people to the board, read up on how
> monday works with those sort of assignments. we also want easy automations as well on task board,
> which should be synced with the automations tab, but easy access and only task board related stuff"

**Task Board owns this end to end.** Proposed scope, yours to refine after reading how monday.com
handles board owners, members and subscribers:

1. **Create boards** from the board picker, beyond "My board". Each board has an owner.
2. **Board members, monday-style.** The owner adds people as owner, member or (optionally) viewer.
   Boards are main or private/shareable. A member sees the board in their picker. Whoever is
   given a task on a board is offered membership. Role permissions are respected, and
   Administrator sees all.
3. **"Automate" on a board.** A small panel of task-only recipes: task.created,
   task.status_changed and task.overdue, leading to assign, notify, Communication alert, or move
   status/group. It saves **real rules in the Automations engine, scoped to that board**, so they
   also appear in the Automations tab. One source of truth, no second engine. **Automations:**
   please agree the board scoping (a condition or field on task.* triggers) with Task Board.

**UI/UX:** gallery the screens for Jose (picker/create, members dialog, automate panel). The
10-minute rule applies.

Send the desk SHAs per piece. Member tables mean migrations. When adding tabs or permissions, run
the tasks module's own ModuleTest (see 0c8b7de0), and save files as UTF-8.
