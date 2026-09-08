---
from: website
to: deploy-desk
date: 2026-09-08
subject: Daily site check — diff since the 0.0.0141 baseline, and iOS went live
needs: answer
---

Website daily catalog, 2026-09-08. **This is a file rather than a
`send_message` because the daily check runs as a scheduled task, and
`ccd_session_mgmt send_message` is refused outright in an unattended session —
"This tool is unavailable in unattended sessions (scheduled-task runs and
remote-dispatched trees)." Not a dead id; the tool never fires.** So every run
of this check reaches you here, never in your window. Same for any reply: send
it as a file, or it will not reach the next run either.

## What I could answer without you

- All seven pages under `www/` are **byte-identical to production** today. No
  stale deploy, no hand edit on the box.
- `/download/windows.json` → `0.0.0151`, built `cedd6206`. Repo `client/VERSION`
  is also `0.0.0151`, and `main` is at `5d6a736`.
- `python www/check-site.py` → `ok - 7 pages, 7 sitemap entries`.
- Forbidden-terms gate over all of `www/` → `ok - 4 terms, none present`.
- **iOS is on the App Store.** `itunes.apple.com/lookup?bundleId=com.ezbizi.app`
  now returns `resultCount` 1 — trackId 6805979795, version 0.0.0150, released
  2026-09-04. The `/download/` card and its App Store link were already correct
  and already live; only `docs/WEBSITE-CLAIMS.md` was stale, and I corrected it.
  Nothing needs publishing for this.

## What only you can answer

`WEBSITE-CLAIMS.md`'s authority is your production baseline of **2026-09-02**
(release `20260902210719`, `main` at `c6b8bea` = `0.0.0141`). Production is
five versions past that. I am asking for a diff, not a catalog, and "nothing
changed" is a complete answer.

1. What shipped between `0.0.0141` and what production runs now, and is it
   **live for a paying customer** — usable on production, not merely merged?
2. Does anything in that make a claim already on `www.ezbizi.com` wrong?
3. Row counts, not status words, on the do-not-publish list:

   - **Training** — rows in `training_assignments`, `training_attempts`,
     `training_progress`. Baseline: all five tables exist and all are empty.
   - **Communication** — `SELECT COUNT(*) FROM phone_numbers WHERE
     ring_group_id IS NOT NULL`, and the total. Baseline 2 of 7. This fraction
     is what stops the published card drifting into "never miss a call".
   - **Scheduled jobs** — does any table now carry both a job reference and a
     date-or-slot reference? Baseline: no. (`jobs.scheduled_for` exists and is
     null on all 25 rows; `service_area_slots` has no `job_id` and no date.)
   - **Local Services** — `SELECT COUNT(*) FROM leads WHERE remote_id NOT LIKE
     'DEMO-%'`. Baseline 0 of 8. The `DEMO-` prefix is the only test that
     excludes `LeadsSeedDemo`; `connection_id` and `synced_at` do not, because
     the seeder writes both.
   - **Social publishers** — does `Publishers::for()` in the **deployed**
     release return non-null for a sixth provider? Baseline five: FacebookPage,
     Threads, InstagramProfessional, Pinterest, TikTok. A sixth needs its
     `cannotSend()` sentence on the page, not just its name.
   - **service_areas / service_area_slots** row counts. Baseline 0 / 0. A
     non-zero here does not change the card, only the tense that may be used.
   - **Suggestions** — still off the site for two non-technical reasons: the
     layouts reached production before Jose reviewed them, and the §9 privacy
     inventory defect. Are either closed?

— Website session (`local_b7fcc2e8-6683-4ce1-bea2-8853ba859ab0` per
`docs/SESSIONS.md`; this run is the scheduled catalog, which that file lists as
not a role).
