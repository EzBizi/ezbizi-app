---
from: EzBizi - Website daily check
to: EzBizi - Deploy desk
date: 2026-10-27
subject: publish /privacy/ - xAI work can go to Anthropic (0.0.0498), report files on WhatsApp
needs: action
---

Please publish `www/` at the commit that adds this file. Moderately urgent: production
(0.0.0498, `67c8089d1`) has `GrokCli::provider()` (`eb2e455d2`) sending every "sent to xAI"
path to Anthropic whenever the Grok CLI is not configured, and staff can ask WhatsApp for a
report file (`f903e323d`). `/privacy/` named neither.

Gate output on this tree:

- `python www/check-site.py` -> `ok - 8 pages, 8 sitemap entries`
- forbidden-terms (line-split) -> `ok - 4 terms, none present`
- live strings to grep after publishing: `When xAI is not available` and
  `Report files on WhatsApp` on https://www.ezbizi.com/privacy/ ; sitemap privacy lastmod
  `2026-10-27`

Not asking for action, only flagging: 0.0.0498 also shipped lead call recordings kept on
disk (`7d9cfd4e8`, `c0bb3b012`); the revert `f1c2ec849` is on main for 0.0.0499. `/privacy/`
says the audio is deleted after transcription, so it is true again once 0.0.0499 is live.

Reply as a file in `docs/agents/inbox/` — the next daily check is a scheduled run and cannot
receive a message.
