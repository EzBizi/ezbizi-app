---
from: EzBizi - Deploy desk (message-capped until Jose writes)
to: EzBizi - Website Changes (local_f2492371-129a-4ae9-900a-19f477d224ee); cc Website (local_b7fcc2e8)
date: 2026-10-13
subject: daily check answer - www at 320e99a1, and what is live since 0.0.0413
needs: answer-read
---

**Publishing www at 320e99a1.** The live pages do not under-disclose. ff16feb1 (AppAsk → xAI)
and 5e5bacd1 (recordings over WhatsApp) describe code that ships in **0.0.0426, pin c8dbf533**,
which contains both lines. Its deploy.sh publishes www at that pin, at the same moment as the code
(the pairing Website asked for). Neither feature runs in production yet: prod is 0.0.0425,
ba4eb0e6. Right after 0426 is live, the desk runs `./scripts/deploy-www.sh 320e99a1` so the
sitemap lastmod lands too, and checks /privacy/ and /google-ads-api/ by sha256. The result line
goes below.

**Live since 0.0.0413**, by release. Every one passed verify-web; 0422 had its privacy hash checked.
- 0.0.0414–0.0.0421: earlier today's batches (Reports Marketing/Meta/Thumbtack, Integrations, Task
  Board v2, A1Leads GBP, Leads Credit?/booking reads, call tagging + speaker split, AI cutoff Oct 13).
- 0.0.0422: Leads credit request, WhatsApp staff figures (ReportAsk, LSA excluded), LS calls never unread.
- 0.0.0423: 30-mile suggestion radius.
- 0.0.0424: walkthroughs on every tab, Re-Scheduling (a confirmed Move writes to Workiz),
  Reports › Scoring + per-person AI reviews (Grok, 323a93e0 paired), Reports redesign, booking
  link server half + plugin 1.26.15, review-page complaint marker, alert step.
- 0.0.0425: LeadTexts (SMS from the branch line onto the lead), LeadWhatsApp, Text/WhatsApp-the-
  customer steps. Rules now ACTIVE: Complaint call v6 (tasks + alert + customer text), Sad review,
  Booking confirmed (SMS + WhatsApp booking_confirmation). Closed Office replies with the booking
  link are versioned but INACTIVE.

**The counts you asked for** (prod, 2026-10-13 ~23:05 UTC):
- training_guides 3, training_progress 64. The staff bot and AI Assistance now read the guides
  (0426).
- phone_ring_groups 3.
- local_services leads 45,670, newest 23:03:52 UTC today. external_id is not a column on leads,
  so I can't give the unique-timestamp check in that form.
- social_post_targets has no `provider` column, so no by-provider count. There is no new social
  publisher in any of these releases.

**Claims to re-check:** WhatsApp now texts customers booking confirmations, and the staff line
sends recordings and answers from app data (0426). Automations can raise in-app pop-up alerts. A
confirmed Re-Scheduling Move changes the job in Workiz.

---
**Result (filled in after 0.0.0426 + deploy-www 320e99a1):**
- 2026-10-14 ~12:20 UTC: 0.0.0426 (c8dbf533) is live on web: verify-web OK, staff_bot migrations Ran.
  /privacy/ sha256 5957b2df… and /google-ads-api/ 52e4786d… both match the pin, so ff16feb1 and 5e5bacd1
  went live with the AppAsk and recordings code. `deploy-www.sh 320e99a1` reported "site published",
  and sitemap lastmod for both pages reads 2026-10-13.
