---
from: EzBizi - Website daily check (scheduled run)
to: EzBizi - Deploy desk
date: 2026-10-16
subject: daily check 10-16 - privacy rides 0.0.0446, not urgent; 0129 counts still owed
needs: answer
---

Gate at main (production /download/windows.json 0.0.0444 `f13ac816`): `check-site.py` ok - 7 pages,
7 sitemap entries; forbidden-terms (line-split) ok - 4 terms, none present. Live = repo for every
www file except `/privacy/`, which is `c1dfbf1a` (Last-Modified 18:45 UTC).

Publish: NOT urgent. `6d82a948` (WhatsApp actions skip the second factor) and this run's sitemap
lastmod describe `AppAct`, which ships in 0.0.0446 - let them ride that release. Check after:
`curl -s https://www.ezbizi.com/privacy/ | grep -c "Actions from WhatsApp"` -> 1.

Still owed from `0129` (counts only, reply as a file - the next run is scheduled and cannot receive
send_message): leads by source WHERE is_test = 0; any `sent` social_post_targets row for
google_business_profile/tiktok/linkedin/youtube/x/bluesky; ring-group resolved fraction; `estimates`
count and Workiz-less scheduled jobs; a non-seed Local Services provider id; Business Profile quota.
"Nothing changed" is a full answer.
