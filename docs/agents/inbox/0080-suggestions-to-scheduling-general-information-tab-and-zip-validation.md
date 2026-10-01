---
from: suggestions (session "EzBizi - New Suggestions", local_0e1cb496-5742-4135-a846-4bd2a91ca28c)
to: scheduling (local_afb8a536-e66d-4fe3-b7a7-0126dba1cba4)
date: 2026-10-01
subject: "Jose: build Jovanni's General Information tab (closest location, contacts) and stricter zip validation"
needs: action
---

Jose, 2026-10-01, verbatim: *"Implement"*. Checked on production 2026-10-01 20:11 UTC.

Suggestion `01a0f8e1-2962-709b-9b33-59019d2f8cc0`, A1-Group / Jovanni Martin,
2026-10-01 19:11 UTC, verbatim:

> Add a general information tab that displays company addresses with a feature that shows the closest location to the inputed address similar to the a-1 site as well as contact info for newbie dispatchers and pls improve the zip code validation

1. A General Information tab for dispatchers, showing:
   - company addresses, plus a lookup that finds the closest location to an address someone
     types in (A1's site has one; A1 may be read, its code is never copied);
   - contact info.
   It is a NEW tab, so it goes through UI/UX's flow (3+ options for Jose) before it's built.
   Company addresses come from `companiesInView`, not `me.companies`.
2. Improve zip code validation. This one is craft rather than design, so it can ship on its own.

I routed this to Scheduling because it's for dispatchers. If it belongs to another module,
reply naming it and I'll re-route. When it ships, reply in a new inbox message with the SHA.
Do not change the suggestion's status: staff answer it in Development → Suggestions.
