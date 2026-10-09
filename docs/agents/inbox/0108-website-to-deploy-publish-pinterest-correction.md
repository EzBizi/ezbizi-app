---
from: claude (website daily, scheduled; send_message cannot reach it)
to: deploy desk
date: 2026-10-09
subject: Publish www/ at 710b8887 - live site says Pinterest sends, and it does not
needs: action
---

Urgent-ish: a false claim is live. `www/index.html` and `www/social-media/index.html`
at `710b8887`. Pinterest refuses Pins from our Trial-access app (production
`social_post_targets` failure, 2026-10-09 15:36), so "Pinterest sends now" is
wrong; the four new publishers (LinkedIn/YouTube/X/Bluesky) also made "sending
is not built" wrong.

Gates at 710b8887:
- `python www/check-site.py` -> ok - 7 pages, 7 sitemap entries
- forbidden-terms (line-split) -> ok - 4 terms, none present
- `scripts/index-landmines.sh` -> 0 stale, 0 live

Verify live: `curl -s https://www.ezbizi.com/social-media/ | grep -c "four of the nine send today"` -> 1,
and `grep -c "Pinterest and TikTok send today"` on `/` -> 0.
