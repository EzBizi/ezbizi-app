# Monday ↔ EzBizi parity audit, 2026-11-24 (Task Board)

Measured on prod. Covers all 39 mirrored boards and 35,216 items, done items included. Monday shuts down on Thu 11-26.

## Clean (0 drift)
- Groups, items, moves, names and members: 0 drift on every board. DMV and NJ (one member each) were fixed in the 11-23 pass.
- Columns: all 267 of Monday's columns exist here, with no type mismatches. Group order and colours match.
- Values: 2,635 differences were queued and imported (`ezbizi:monday-parity --values --done --fix`). Due dates: 0 missing.
- Updates: a sample of 190 items all match.
- Links in imported updates: 1,583 restored as [name](url). 26 were edited here since, so they were left alone.
- @mentions in imported updates: 10,187 filled. 3,668 Mentioned rows were added for the last 30 days, already read.

## Fixed on 11-24
- **Job Status (Ohio and Illinois PM):** 330 values were empty here. All are filled now, and a re-check finds 0.
  - Cause: these items were imported before the import learned to add missing labels as options ("Not started", "Done", "Cleveland"). Those 5 options are added now.
  - The desk's 9 "Ohio main status" differences were the same Job Status values.
- **Mentions on replies:** Monday's `updates(ids:)` leaves out replies. The backfill now reads replies through their items (commit af2e234da).

## Kept as text, not owners (by design)
9,324 tasks have a Monday person but no owner here. Every one has "Assigned in Monday: <name>" in its Activity, so the history survives the shutdown.

| Who | Tasks | Why there's no owner here |
|---|---|---|
| Deleted member | 8,830 | Their Monday account is gone; no user to map to |
| Jeanette Del Rosario | 351 | No EzBizi user |
| Deleted Betty White | 195 | Their Monday account is gone; no user to map to |
| Shoval 14, Chaim 7, Bar 5 | 26 | Another person's personal board; Jose declined adding them as members (11-12) |
| Danika Kaye 7, AERON SIBUG 1 | 8 | Their EzBizi users are inactive |
| Jason R 4, Tal Menahem 2 | 6 | No EzBizi user |
| Nehorai 2, Liraz 1 | 3 | Board couldn't take them as owners (cause not checked) |

## Monday-only features (no direct mirror)
- Column types: dependency 2, board_relation 1, mirror 1, formula 1.
  - The 5 dependency-status rules that use them are not mirrored (New Location / Brand / Franchise templates, Everdoors Brand, MA Franchise).
- 4 Monday AI agents: their setup can't be read through the API.
- Ohio PM's Dashboard view.
- Column order differs on 5 boards: IL - Illinois, Illinois PM, Merav - All Operation, NW - Nationwide, Ohio PM.
- NW has a text "Status" column holding 2 values, which duplicates the built-in status.

## Automations
- 111 legacy Monday rules: 106 mirrored, plus the Ohio PM workflow (mirrored and ON). The 5 dependency rules above are missing.
- 464 Monday automations exist in total; 351 of them are EzBizi webhooks, which go dead at the shutdown.
- Inventory: docs/agents/monday-inventory-2026-11-23.md.

## For Jose
- Jeanette Del Rosario (351 tasks) and Jason R / Tal Menahem have no EzBizi user. Add them if they should own tasks. A re-sync then assigns them.
