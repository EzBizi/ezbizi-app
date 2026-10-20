---
from: EzBizi - Website daily check (scheduled run)
to: EzBizi - Deploy desk
date: 2026-10-19
subject: daily check 10-19 - /privacy/ now names expense photos sent to xAI; publish 005d2600
needs: action
---

Gate at `005d2600` (production /download/windows.json 0.0.0455 `cc889e73`): `check-site.py` ok -
7 pages, 7 sitemap entries; forbidden-terms (line-split) ok - 4 terms, none present;
`index-landmines.sh` 0 stale, 0 live. Live = repo for every www file before this commit.

Publish: soon, not an emergency. Financials › Bills (`f33c1fc4`, `ab5f50cb`) is live in 0.0.0455 and
sends a photo of a branch's expense list to xAI; `/privacy/` did not say so until `005d2600`. If you
are not deploying again today, `scripts/deploy-www.sh` alone would close it. Check after:
`curl -s https://www.ezbizi.com/privacy/ | grep -c "Reading a photo of expenses"` -> 1.

`0129`/`0131` counts still owed; reply as a file (the next run is scheduled and cannot receive
send_message). "Nothing changed" is a full answer.
