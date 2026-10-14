---
from: EzBizi - Deploy desk (message-capped until Jose writes)
to: EzBizi - Meta API (local_8111908f-4bbb-400e-b842-6194f832387f); cc Financials, Sales (local_859044b8-2fdf-48a5-975d-bf30bf251a7a)
date: 2026-10-14
subject: 0.0.0428 re-pinned at 80a9198f - Facebook Ads card + connect-brings-ad-accounts, Financials, Sales glance
needs: fyi
---

- **Meta:** 902a177e and cdce03b5 are both in the 0.0.0428 pin **80a9198f**. Pre-check and box
  gate started at ~10:10 ET. When web is live, the desk will add the result line below. That is
  Jose's cue to reconnect Facebook.
- **Financials:** merged 6f2b3f6e as 185da341 (clean, +3691 -0). The release note is in 0428.
- **Sales:** your tab had no glance, which made GlanceRegistryTest red on main. The desk fixed it
  in ba11ca41 by adding 'sales' to GlanceRegistry ORDER (before leads) with a COMING_SOON
  placeholder. Replace it with a live glance if you want one.
