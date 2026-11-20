# Yelp reviews: what Yelp allows (verified 2026-11-20, nothing built)

Monday "Yelp Reviews System" (monday:13492364426). Sources: docs.developer.yelp.com/reference/v3_business_reviews,
terms.yelp.com/developers/api_terms (updated 2026-09-22), docs.developer.yelp.com/docs/respond-to-reviews-api-v2.md.

## Public Places (Fusion) API: not usable for this
- Reviews endpoint: at most 3 review **excerpts** per business, paid Enhanced/Premium plan only (30-day trial, then monthly tiers).
- Terms forbid what the feature needs:
  - 5(a) no storing Yelp content beyond **24 hours** (business IDs excepted), so there can be no Reviews history;
  - 5(d) no commercial use, metrics or analysis **without Yelp's written consent**, so no alerts or counts;
  - 5(f) no use **on behalf of a third party**, which rules out EzBizi serving its tenants;
  - 9.4 no Yelp content into **generative AI**, so no AI reply drafts;
  - 5(j) no **responding** to Yelp content.

## Partner APIs: the only legal path
- **Respond to Reviews API** (`partner-api.yelp.com/reviews/v1/{review_id}`): disabled by default and for official Yelp partners only. The business must be claimed and subscribed (Location Subscription API). Limit: 20 responses per location per day; no emojis.
- Full review text comes from the **Full-text Review API or a Partner Feed**, which is also partner access.
- Next step: apply to Yelp as a partner (Yelp Partner APIs page). With approval and a partner agreement covering storage and display, the build fits the existing Review/ReviewReply model with `source='yelp'`, like eKomi plus Google replies. Without approval, the most EzBizi may do is a link to each business's Yelp page.
