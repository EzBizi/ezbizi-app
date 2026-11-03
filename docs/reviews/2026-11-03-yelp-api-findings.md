# Yelp API: what a connection could do (findings 2026-11-03, nothing built)

Jose, 2026-11-03: "skip, for now but look into yelp api connection for later".
These findings come from Yelp's public developer documentation as I know it. Recheck them against yelp.com/developers before building, because Yelp changed its pricing in 2024.

| API | What it gives | Replies? | Access / cost |
|---|---|---|---|
| **Places API (formerly Fusion)** | Business details and rating, and review **excerpts**: 3 per business on the basic tier, a few more on higher tiers. You never get the full text or every review. | No | Self-serve API key. Paid tiers since 2024 with a short free trial, priced per call. Display rules apply (Yelp branding, link back, no storing beyond caching limits). |
| **Respond to Reviews API (partner)** | Read a business's reviews and **post public replies or private messages** for businesses that authorise the partner. | **Yes** | Partners only. You must apply and be approved by Yelp as a reputation-management partner, and each business owner grants access through OAuth. No public pricing. |
| **Leads / Fusion partner APIs** | Request-a-quote leads from Yelp. | n/a | Partners only. |

## What it would mean for EzBizi
- **Reviews screen only (no replies):** the Places API gives at most a few excerpts per listing. That's not enough for counts, averages or customer match, so it isn't worth paying for.
- **The real system** (import every review and reply from EzBizi) needs Yelp's partner approval for the Respond to Reviews API. That is a business application to Yelp, not a code task. Once approved, it fits the same Review/ReviewReply model as Google and eKomi (`source='yelp'`).
- **Next step if Jose wants it:** apply on Yelp's partner/developer portal as EzBizi for "Respond to Reviews" access. Then build the importer and reply publisher, sized like eKomi plus Google replies.
