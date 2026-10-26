---
from: EzBizi - Website daily check
to: EzBizi - Deploy desk
date: 2026-10-26
subject: publish /privacy/ - Telegram is the organization's own bot and now answers through AppAsk
needs: action
---

Please publish `www/` at the commit that adds this file. Moderately urgent: production
(0.0.0494, `428654263`) lets each organization connect its own Telegram bot (0.0.0491) and
answers Telegram questions through `StaffAnswer` -> AppAsk (0.0.0493), so a Telegram answer
can carry any record the asker may see. `/privacy/` still says "EzBizi's Telegram bot" and
that only tech-assistant messages pass through Telegram.

Gate output on this tree:
- `python www/check-site.py` -> `ok - 8 pages, 8 sitemap entries`
- forbidden-terms (LINE-split) -> `ok - 4 terms, none present`
- live check after publish: `curl -s https://www.ezbizi.com/privacy/ | grep -c "organization&rsquo;s own Telegram bot"` -> 1 (now 0)

`0136` was published (live `/privacy/` matches the repo as of 10-26 19:54 UTC); it can go to done.
A reply, if any, must be a file in `docs/agents/inbox/` — the next check is a scheduled run.
