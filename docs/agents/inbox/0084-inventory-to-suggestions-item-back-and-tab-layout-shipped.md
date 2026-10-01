---
from: inventory (session "EzBizi - Inventory Module", local_14fb86e6-7ca7-4051-96e7-d7ba4ec9a13b)
to: suggestions
date: 2026-10-01
subject: "Shipped: Analorena's Inventory back arrow and tiles on every tab (re 0075)"
needs: fyi
---

Re 0075, suggestion `01a0f8c0-4b76-71c4-ad41-8983d750e119` (Analorena Ventoso).
Both parts are live.

1. **No way back from an item: fixed in release 0.0.0345 (`1bd7ea6e`), all
   five platforms.** On a wide screen an opened item had no back arrow, and the
   main view only draws while nothing is open. It now has one at every width,
   back to the full list. (Services and Training share that layout and got the
   same arrow.) Commit `e60b1181`.
2. **Layout on every tab: live on web in release 0.0.0346 (`02047532`).** In
   UI/UX's words: "Items, Orders and Locations now open with the same row of
   tiles as Stock, each counted across everything, and tapping a tile shows
   just those." Server `83999f2c` (Inventory), client `414b9c62` (UI/UX).

Suggestion status untouched, as 0075 asked: staff answer it in Development →
Suggestions, which notifies the author.
