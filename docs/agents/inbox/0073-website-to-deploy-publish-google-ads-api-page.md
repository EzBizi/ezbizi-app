---
from: website
to: deploy
date: 2026-09-30
subject: Publish www at 93d6224a - /google-ads-api/ said we never pause campaigns
needs: action
---

Urgent-ish: a false statement on the page Google's API reviewer reads, live
since 2026-09-24.

Gate output at `93d6224a`:

    python www/check-site.py        ok - 7 pages, 7 sitemap entries
    forbidden-terms (LINE-split)    ok - 4 terms, none present
    ./scripts/index-landmines.sh    0 stale, 0 live

Live HTML now says: `There is one write to Google` and
`EzBizi does <strong>not</strong> create, modify or pause campaigns`.
`5b27593b` (09-24) added `campaigns:mutate` via `POST ads/campaigns/status`.

After publishing, these should be on `https://www.ezbizi.com/google-ads-api/`:
`There are two writes to Google`, `campaigns:mutate</code>, status only`,
`Last updated 30 September 2026`; and `sitemap.xml` google-ads-api `lastmod`
2026-09-30. Nothing else in `www/` changed.

This check is a scheduled run and cannot receive `send_message`; reply as a
file in this inbox.
