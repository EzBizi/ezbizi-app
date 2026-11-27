# A1 Leads cutover (do the day Google releases GBP v4 to project ezbizi-506117)

Precondition, measured, not relayed: `gcloud services enable mybusiness.googleapis.com --project ezbizi-506117`
succeeds, and a v4 `.../reviews` GET with an EzBizi-client token answers 200 (it is 403 as of 2026-10-29).
Then confirm the 6-hourly `ezbizi:gbp-sync --reviews` writes new reviews before removing anything below.

**Measured 2026-11-13:** precondition met — `mybusiness.googleapis.com` is in the project's enabled services; all 44 `google_business_profile` connections are on
EzBizi's own OAuth client (none carry the `interim` A1 Leads credentials), all reached that day, and Google
reviews import daily (56 on 11-13, the last right after the 12:40 `--reviews` run). Box: `a1leads.service` and
`a1leads-refresh.timer` are already **disabled and inactive**. Still there: the 9 `A1LEADS_*` keys in `.env`
(no code reads them). Code: the `config/services.php` `a1leads` block is already gone (`13dd2595c`, 10-30).

Box (Jose's decision to stop; nothing is deleted):
- `a1leads.service`, `a1leads-refresh.timer` / `.service` (user `a1leads`). Stop + disable.
- `.env`: the `A1LEADS_*` keys (9 on 2026-10-29). Remove, then `config:cache` as root (see production-env memory).

Code (one PR, cut fresh from main that day — `grep -rln "a1leads\|A1Leads\|A1LEADS" app routes config client/lib tests`
listed 25 files on 2026-10-29):
- ~~`config/services.php` `a1leads` block~~ (removed `13dd2595c`); `ReviewsImportA1Leads`, `GbpInterimConnect`; the A1Leads* models' callers.
- Keep the `a1leads_*` tables and their models' retention: retire, never drop (history and reply receipts).
- `a1leads_gbp` module: already retired by `2026_10_20_171508`.
