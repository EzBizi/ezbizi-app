# Website (www.ezbizi.com) — review 2026-11-02

Measured live with curl against production; no production data touched.

## 1. Working

- All 8 pages return 200: `/`, `/privacy/`, `/terms/`, `/download/`, `/delete-account/`, `/social-media/`, `/google-ads-api/`, `/support/`.
- Every live page is byte-identical to `www/` on main (CR stripped), so nothing is stale and nothing was hand-edited on the box.
- `check-site.py`: ok, 8 pages and 8 sitemap entries (tags, JSON-LD, internal links, favicon frames).
- Forbidden-terms gate (`WEBSITE-CLAIMS.md`): no hits.
- Downloads:
  - `/download/windows.json` reports 0.0.0513 at 27b215107, and the installer returns 200.
  - Google Play listing returns 200.
  - The App Store lookup reports 0.0.0502.
- The daily check (19:00) ran on every day 10-29 to 11-01. Its log is in `docs/WEBSITE-CLAIMS.md`.
- The privacy page is paired with each release that changes a claim, and the desk checks it by sha256 after deploy (0422, 0424, 0426, 0431 and 0446 so far).
- `/support/` (67d6a48c) is live with mailto, postal address, Suggestions and links to privacy and terms. It was built for the Workspace Marketplace resubmission.

## 2. Broken

None found. One known gap: a `www/`-only commit has no release to carry it. It waits for the next `deploy.sh`, or needs a manual `deploy-www` from the desk, as `/support/` did.

## 3. Cohesiveness

- `/support/` is linked only from the homepage footer. The privacy, terms, delete-account and download footers do not link it.
- The AI story on the homepage is one card, "AI & the API" (MCP keys). The many Grok features (lead sorting, drafts, summaries, coaching, the WhatsApp staff line) are described only on `/privacy/`, never sold on the homepage.
- `/privacy/` has grown to many per-feature xAI paragraphs. Each is accurate, but together they read as a list, not a structure.

## 4. Suggestions (ranked)

1. Restructure `/privacy/`'s AI section into one table: feature, what is sent, retention. Same facts, far easier for a reviewer (Google, Meta, Apple) to read.
2. Add a homepage card for EzBizi AI (Grok): lead sorting, suggested replies, call coaching, the WhatsApp staff line. These features are live and unsold.
3. Add a Support link to every page footer. This is cheap, and every Marketplace or store reviewer looks for one.
4. Have `deploy-www` run on any `www/` commit, not only at the end of `deploy.sh`, so site-only changes don't wait.

## 5. Needs Jose

- Should the homepage sell the Grok AI features (suggestion 2)? Some of them read staff calls and the business's Google data, so selling them invites the question "what does it see?". The privacy page already answers that.
