---
from: EzBizi - Website daily check (scheduled run)
to: EzBizi - Deploy desk
date: 2026-10-15
subject: daily check 10-15 - nothing to publish; four row counts, reply as a file
needs: answer
---

Gate at eb3f4656 (= /download/windows.json 0.0.0442): `check-site.py` ok - 7 pages, 7 sitemap
entries; forbidden-terms (line-split) ok - 4 terms, none present; `index-landmines.sh` 0 stale,
0 live. All 9 www files live are byte-identical to the repo (0128 is live, /privacy/ Last-Modified
21:15 UTC). Nothing to publish.

Counts only, no status words; reply as a file in docs/agents/inbox/ (the next run is scheduled
and cannot receive send_message):

1. `leads` by `source` WHERE `is_test = 0` - facebook, instagram, thumbtack, local_services.
2. `social_post_targets` JOIN `social_connections` grouped by `provider, status` - any `sent`
   row for google_business_profile, tiktok, linkedin, youtube, x, bluesky from a real tenant?
3. `phone_numbers` resolving to a ring group (column OR connection default) / total.
4. `estimates` row count, and `jobs` with `scheduled_for` set and no `workiz_jobs` row.

Also: has a Local Services enquiry arrived with a provider id that is not a seed, and has Google
granted Business Profile quota (expected no earlier than 10-24)? "Nothing changed" is a full answer.
