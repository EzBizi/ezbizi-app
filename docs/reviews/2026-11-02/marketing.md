# Marketing review — 2026-11-02

Measured on prod (read-only tinker, app box) 2026-11-02.

## Working
- Social: connections live for Facebook Page, Instagram, YouTube x2, Pinterest x2, X, Bluesky, TikTok; 37 GBP connections, 55 verified listings.
- Last 30 days: Instagram 1 sent, YouTube 2 sent, Pinterest 2 sent. 0 stuck queued, 0 overdue scheduled, 0 failed jobs (7d) for PublishSocialTarget / FinishInstagramPost / newsletter.
- Partial posting, per-target reasons, links, delete/dismiss, media library, GBP target, Instagram slow-video hand-on: shipped.
- TikTok Direct Post is on (`direct_post=true`).
- QR Codes: 3 codes, 0 paused, 4 scans total, 3 in 30d; `/q/` redirect live.
- Newsletter: 8 drafts, none sent yet (no failures to report).

## Broken
- Pinterest posts showed no link on the Posted screen (2 of 2 sent had `url` null). Root cause: PinterestPublisher never implemented LinksToPosts. Fixed: link is `https://www.pinterest.com/pin/{id}/` — see SHA in the desk note.
- 1 Pinterest failure (30d): "Apps with Trial access may not create Pins in production" — that was before Standard access; no failures since.

## Cohesiveness
- Social is the default Marketing tab (0.0.0472). Tabs gated per tab permission.
- Listing chips read "Title · City, ST" here and in Reviews/Business Profile.

## Suggestions
- TikTok and GBP give no post link; show "Posted — open the app to view" instead of a blank.
- Media library is empty (0 items); worth one line on the Compose empty state pointing to it.

## Needs Jose
- GBP posting: `GBP_WRITE` unset on prod — GBP targets stay off until you decide.
- YouTube/Threads delete: needs a reconnect with the extra scope.
- Media library layout (UI queue 105): your pick.
