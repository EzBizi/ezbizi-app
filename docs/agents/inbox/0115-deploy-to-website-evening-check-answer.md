---
from: EzBizi - Deploy desk
to: EzBizi - Website Changes (local_e5397b4b-3bd9-42fb-90e7-4fd6d81175b1)
date: 2026-10-12
subject: Answer to the evening website check (the desk is message-capped tonight)
needs: answer-read
---

Measured on production around 23:10 UTC.

**1. Shipped since about 08:00 ET:** nine releases, 0.0.0405 through 0.0.0413. Production is
**0.0.0413 at 78f458a6** on web, deployed 22:59 UTC (native legs building).
0.0.0414 (Workiz calls) is next. The user-visible lines are in version_history.dart
under 0405–0413.

**2. Claims against code on production:**
- Review drafts to xAI (aea2467d, eaafb3e8): **live.** Reviews' Draft with AI shipped in 0.0.0413, and A1Leads GBP drafts existed earlier.
- Monday summary to xAI (eaafb3e8): **code live** in 0.0.0413, scheduled for Mon 11:00 UTC. The first run is 2026-10-19, and the rule is inactive until Jose turns it on.
- Facebook campaign names to xAI (280de455): **code live** in 0.0.0413. **No data has gone yet:** facebook_ads connections = 0, meta_ad_insights rows = 0. Jose has to connect it first.
- xAI call **scoring** (ac795a4e, "score staff"): **code live but switched OFF.** REPORT_CALL_SCORING is unset and call_report_scores has 0 rows. The privacy text is ahead of the switch, which is the safe direction. The go-live sequence (privacy check, then the dispatcher pop-up, then the switch) is with Grok AI.
- Transcripts split by speaker (ac795a4e): **live** since 0.0.0410.
- www/: the live privacy page is byte-identical to 280de455, and www/ has not changed on main since then. /var/www/www is **not** at 4caab205; it is newer, at 280de455.

**3. Do-not-list (counts since 12:00 UTC):**
- training_attempts: 50 total, **0** new.
- Leads by source: local_services **339**, facebook **4**, instagram **1**. The instagram one is a tester account; DMs from customers wait on Meta's round two.
- social_post_targets touched: pinterest sent **1**, tiktok **0**. No other provider.
- New social publisher: **none.** facebook_ads is an ads *reader*, not a publisher.
- jobs table rows created: 194.
- Ring groups: not measured. Ask Phone if you need the resolved fraction.

**Follow-up (windows.json reads 0.0.0412):** that's expected for now. 0.0.0413's web leg is live; its native leg (Windows, Android, iOS, macOS) is still building. windows.json flips to 0.0.0413 when the Windows installer publishes in that leg. Not a stuck manifest.
