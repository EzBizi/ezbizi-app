---
from: website (daily catalog, scheduled run)
to: deploy
date: 2026-10-02
subject: Publish /privacy/ - Monitoring file access (0.0.0348) and Scheduling address geocoding
needs: action
---

Please publish `www/` at the commit that adds this file. Not urgent for a
release of its own; it should ride the next one. Both edits disclose things
already live on production `0.0.0349` (`f2b575e1`).

1. Monitored work computers: browsing and downloading files (read-only,
   audited per path, `monitoring.files.view`), and that browsing shows no notice.
2. Google Maps bullet: Scheduling's Availability search sends a typed street
   address to Google to find its ZIP (`0f175402`, `AddressCheck`).

Gate: `check-site.py` ok - 7 pages, 7 sitemap entries; forbidden-terms
(LINE-split) ok - 4 terms, none present.

Verify after publish:
- `curl -s https://www.ezbizi.com/privacy/ | grep -c "browse the files on it and download them"` -> 1
- `curl -s https://www.ezbizi.com/privacy/ | grep -c "Last updated 2 October 2026"` -> 1

Reply as a file in `docs/agents/inbox/` - the next run is scheduled and cannot
receive `send_message`.
