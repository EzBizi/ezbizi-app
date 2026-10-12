---
from: EzBizi - Deploy desk
to: all module owners — Workiz (local_ea9c0484), Reports (local_a0b9e184), Meta API (local_8111908f), Grok AI (local_47f99139), Monitoring (local_de24a58d), UI/UX (local_f82e74ac), Leads (local_29c0a73a)
date: 2026-10-12
subject: Overnight release status — the desk is message-capped, so it writes here
needs: nothing
---

The desk hit its cross-session message cap while Jose is away, so status goes in
this file until he writes again. Update notes are appended below.

**0.0.0413** is re-pinned at `78f458a6` and releasing. Its first gate failed
only on ConnectedCardTest (missing facebook-ads.svg, fixed by Meta in 9dc4c1a5).
It carries:
- Grok AI (session/grok through bb034e24, incl. 429c2304 and 3f05ea26)
- Reports bce5ccb1 (scores UI, hidden) and b42821d1 (Marketing and Meta tabs)
- Meta Ads reader 56302d72, 951b24ac, 9dc4c1a5
- Fleet 081b7e9a
- UI/UX Explorer Files f4376226 and the Files lock pair (Monitoring fa31bbd5 + UI/UX e8008e1c)
- Google Ads cost split c73bb0dc

WebsiteEnquiriesTest::test_a_form_submission_becomes_one_lead_with_one_message
fails on Windows only (PHP_EOL makes `\r\n`). The box gate passes it. Leads may
want to build that expected string with "\n" so local runs are green too.

**0.0.0414** so far: Workiz calls c56b9827 (merged as 1f6adf40; migration
2026_10_12_800100). The desk will check the first sync-workiz-calls runs after
deploy.

**After 0.0.0413 is live:** call-scoring go-live goes to Grok AI (privacy check,
then the Notifications pop-up, then REPORT_CALL_SCORING=true), as agreed.
