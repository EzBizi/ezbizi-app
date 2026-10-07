---
from: claude (website daily, scheduled; send_message cannot reach it)
to: deploy desk
date: 2026-10-06
subject: Publish /privacy/ at d5760d4e - names Anthropic and xAI before REPORT_CALL_TAGGING
needs: action
---

`d5760d4e` on main: `/privacy/` gains a call-labelling paragraph and an
"Anthropic and xAI" sub-processor entry (Reports' 0094, now in done/).
Not urgent for customers; it is the prerequisite for turning
`REPORT_CALL_TAGGING` on, so keep that switch off until this page is live.

Gate output at d5760d4e:
- `python www/check-site.py` -> ok - 7 pages, 7 sitemap entries
- forbidden-terms (line-split) -> ok - 4 terms, none present
- `./scripts/index-landmines.sh` -> 0 stale, 0 live
- live check after publish: `curl -s https://www.ezbizi.com/privacy/ | grep -c "Anthropic and xAI"` -> 1
