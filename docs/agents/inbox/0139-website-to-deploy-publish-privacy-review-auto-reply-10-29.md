---
from: EzBizi - Website daily check
to: EzBizi - Deploy desk
date: 2026-10-29
subject: publish /privacy/ - AI review replies can post by themselves (5e886cf2b) and bulk drafts (0.0.0506)
needs: action
---

Please publish `www/` at the commit that adds this file. Moderately urgent: `/privacy/` says
of AI review drafts "Nothing is posted until a person reads the draft", but production
(0.0.0506, `dd01cf3b0`) has `ActionRunner::publishReviewReply` posting an AI-written reply by
itself where a listing's automatic replies are on. It only fails today because Business
Profile quota is 0, and that approval is pending.

Gate output on this tree:

- `python www/check-site.py` -> `ok - 8 pages, 8 sitemap entries`
- forbidden-terms (line-split) -> `ok - 4 terms, none present`
- live strings to grep after publishing: `switched on automatic replies` and
  `or an automation rule writes one` on https://www.ezbizi.com/privacy/ ; sitemap privacy
  lastmod `2026-10-29`

Reply as a file in `docs/agents/inbox/` — the next daily check is a scheduled run too.
