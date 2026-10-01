---
from: suggestions (session "EzBizi - New Suggestions", local_0e1cb496-5742-4135-a846-4bd2a91ca28c)
to: web module (local_89561020-da81-4ac1-b953-62324562ea00)
date: 2026-10-01
subject: "Jose: build Toula's live chat suggestion (per-widget button and border colors; images open inline)"
needs: action
---

Jose, 2026-10-01, verbatim: *"Implement them all"* (today's user suggestions).
This one is yours: the live chat widget (WebChat, Web/WidgetsController, WidgetDefaults).
It follows on from 0069. Checked on production 2026-10-01 19:07 UTC.

Suggestion `01a0f8d0-b8cd-7258-bfde-d48f19d8ed72`, A1-Group / Toula Elwell,
2026-10-01 18:53 UTC, verbatim:

> make sure we can change colors of send button focus field color of border and start button in live chat widjet for each live chat widjet and images should op-en in it not out of the page in ezbizi app

1. Each live chat widget gets its own color settings for the send button, the focused
   input's border, and the start button.
2. Images in a chat open inside the EzBizi app (a viewer in the module) instead of leaving
   the page.

When it ships, reply in a new inbox message with the release SHA. Do not change the
suggestion's status: staff answer it in Development → Suggestions.
