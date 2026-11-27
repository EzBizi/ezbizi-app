# Website daily 2026-11-21: desk reply (measured 2026-11-22 ~00:00 UTC)

1. **Live on web since 0620 (3f99b84c):** 0621 (43a211f0), 0622 (7dfa316b) and 0623 (194e4c29, live now; Windows too; iOS/macOS/Android built for 0622).
   - Folded: none.
   - Rolled back: none.
2. **Since 3f99b84c5 (`git diff 3f99b84c5..194e4c29c`, 18 files):**
   - New https hosts in app/ and config/: 0.
   - Changes under app/Support/Social: 0.
   - New routes: 0.
   - New processor, OAuth scope, Google/Meta write or public no-login page: none.
   - What changed:
     - Workiz job-file reads: Workiz v2, the existing processor and host. They run every 10 minutes for done jobs scheduled in the last 30 days. Rate-limit waits now retry instead of failing.
     - Report fixes: "Not checked" instead of a miss, and "photos not read".
     - Roles: an Administrator can edit roles from a company view. Organization-level authority is still required; branch users are still refused.
   - Outside the release: plugin 1.26.33/1.26.34, rolled out to the 46 A-1 installs. The rating badge renders `[ezbizi_rating]` from the payload, and any aggregateRating ld+json is rewritten to the live numbers. No new party is involved.
3. **Row counts (prod):**
   - training_attempts 58, training_progress 82, training_assignments 12 (unchanged).
   - phone_ring_groups 6; phone_numbers resolving to a group: 6, of 15 phone_numbers in total.
   - Scheduled Jobs (jobs.scheduled_for set, no workiz_jobs row): 785, all in Triplex Group (0 in any other org). Unchanged.
   - Local Services leads (source=local_services): 55,429, of which 55,429 have a remote_id.
   - New publisher class under app/Support/Social: none.
4. **Site claims:**
   - The A-1 review count and rating on the sites now come live from Google's per-listing totals and update after each 6-hourly sync. Today's figure: 13,592 reviews, 4.9 stars. Any page copy that hard-codes 13,583 is now 9 lower than the badge.
   - The wording "locally operated" and "project manager on every job" is unchanged since 11-20.
   - Nothing else changed.
