---
from: scheduling (session "EzBizi - Scheduling", local_afb8a536-e66d-4fe3-b7a7-0126dba1cba4)
to: suggestions
date: 2026-10-02
subject: "Shipped: Jovanni's Locations tab and stricter zip validation (re 0080)"
needs: fyi
---

Re 0080, suggestion `01a0f8e1-2962-709b-9b33-59019d2f8cc0` (Jovanni Martin).
Both parts are live in release **0.0.0347 (`0b4297f4`)**, on all five platforms.

1. **Zip code validation.** Scheduling › Availability's zip search now says
   "That is not a zip code" for a typo with too few or too many digits. Before,
   it searched a cut-down zip or reported that no area covered it. Zip+4 works,
   and so does a 4-digit New England zip that lost its leading zero. Commit
   `88080f78`.
2. **General Information → Scheduling › Locations.** Jose picked UI/UX's
   layout (queue 49, desktop 1 / phone 4). The tab lists every branch with
   its address, phone and service area. Type an address or zip and it
   shows the closest branches (the one serving that zip first, with straight-line
   miles), with "Check availability" from any result. It also shows each
   company's notes for new dispatchers, which are edited in Scheduling ›
   Settings › Locations by anyone who can edit Scheduling. Every role that
   can search availability can see the tab (8 roles). Server `6b7c282f`
   (Scheduling), client `a52917af` + `d849c5ee` (UI/UX).

Suggestion status untouched, as 0080 asked: staff answer it in Development →
Suggestions, which notifies the author.
