# Website daily 2026-11-24: desk reply (measured 2026-11-24 ~23:20 UTC)

1. **Live on web since 0627 (593d1a696):**
   - 0628 (3da910c9e).
   - 0629 (6f18d1a73).
   - 0630 (eb9742ad8): iOS 27 phone build.
   - 0631 (c361b1ef8).
   - 0632 (dd46a14dc).
   - 0635 (b723830e0), live now; Windows too.
   - Never deployed:
     - 0633, folded into 0634.
     - 0634: failed the authorization sweep (task-board search needs ?q=); folded into 0635.
     - 0636: failed a flaky EstimatesApiTest.
     - 0637: failed AutomationsApiTest's arms guard.
     - 0636 and 0637 are folded into 0638, which is cutting now.
   - Nothing rolled back.

2. **Since 593d1a696 (`git diff 593d1a696..b723830e0`, 131 files):**
   - New https hosts in app/ and config/: 0.
   - New OAuth scope: none.
   - Changes under app/Support/Social: 0.
   - New processor: none live. Plaid is on main and in 0638, but **not on prod yet**.
   - New Google/Meta write: none.
   - New public no-login routes: none. The new routes are all inside the authenticated group:
     - GET task-boards/{board}/search;
     - the scheduling/zip-reports routes (store, index, move, dismiss);
     - GET roles/{role}/mobile-bar/options;
     - PATCH project-board/jobs/{job}/project-costs.
   - Data-handling changes worth knowing:
     - Administrator accounts' work-hours (time clock) events are no longer written to the audit log. Past ones were deleted on Jose's instruction.
     - Monday sync writes are now attributed to "Monday sync", not to the connecting user. 297,377 past rows were relabelled.
   - **Plaid Bank tab on prod: no.** bank_accounts/bank_transactions don't exist on prod yet, and **0** plaid connections. Both arrive with 0638. A real bank connect needs a person signing in to their own bank.

3. **Row counts (prod):**
   - training_attempts 59, training_progress 83, training_assignments 12 (unchanged).
   - phone_ring_groups 6; phone_numbers resolving to a group: 6, of 15 in total (unchanged).
   - Scheduled Jobs (scheduled_for set, not soft-deleted, no workiz_jobs row): Triplex Group 80; 0 in any other org (unchanged).
   - Local Services leads (source=local_services): 56,215, all 56,215 with a remote_id.
   - New publisher class under app/Support/Social: none.

4. **Site claims:** nothing on ezbizi.com is made wrong by the live releases. The Plaid privacy text went up ahead of 0638; it describes the feature that ships in 0638 (read-only, no bank passwords, no money moved).
