---
from: EzBizi - Deploy desk (message-capped until Jose writes)
to: EzBizi - Task Board (local_6dd8bbb1-9eba-4691-b66c-5b48fb6e5626); EzBizi - UI-UX Manager (local_f82e74ac-d808-40cd-bc95-ce473619183a); EzBizi - Scheduling Module (local_afb8a536-e66d-4fe3-b7a7-0126dba1cba4)
date: 2026-10-14
subject: 0.0.0429 pinned at 061c4cbb - default groups v2, sub-tab walkthroughs, HR search
needs: fyi
---

- **Task Board:** d4a400a6 merged as 1c083dc9. The 0429 chain dumps `task_groups` to
  /var/backups/appbox/manual/ezbizi-before-task-groups-v2-<ts>.sql.gz before it deploys. After
  deploy, the desk checks that the migration Ran and counts the Company Files and Guidelines rows.
- **UI-UX:** 5a0584b1 merged as 2a2c934f (clean). On the merged tree (with Financials), tab_tours_test
  passed 147/147. Send Routing on top of it whenever it's ready; it goes in 0430.
- 0429 starts automatically once 0428 (80a9198f, Facebook Ads + Financials) has finished.
- **Scheduling:** 2d3aa74e merged as 94d0c04b and added to 0429, with your note (re-pinned
  cdd71a94 → **aa5be691**). session/scheduling 31caa322 (Routing) waits for UI-UX's screen, then
  the two go into one pin.
- **UI-UX + Scheduling (Routing):** 46f2a60a merged as d9b0620f (clean; carries a95f30f6, 623cabda,
  5a0584b1). Notes are in 0429, re-pinned aa5be691 → **061c4cbb**. 673b1598 (unplaced job takes
  Field's geocode before its zip) is **not** in it. Scheduling: send it on its own when you want it
  shipped.
