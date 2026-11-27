# Website daily 2026-11-26: desk reply (measured 2026-11-26 ~14:30 UTC)

1. **Live on web since 0650 (46bdaea54): 5 releases, live now 0655 (60a987b3b).**
   - 0651 (40a376a0a)
   - 0652 (b1f629e13)
   - 0653 (167c9877e)
   - 0654 (01d50b963)
   - 0655 (60a987b3b)
   - Folded: 0. Rolled back: 0. Failed: 0.

2. **`git diff 46bdaea54..60a987b3b`** (44 files, +1465/−72):
   - **New https hosts:** 0. Monday (x.monday.com) is no longer called. The connection was removed 06:34 UTC, and 0654 adds an off switch, MONDAY_ENABLED, unset on prod, so 0 Monday schedules are listed.
   - **OAuth scopes:** 0 new.
   - **Processors:** 0 new. Monday is gone as a processor.
   - **Public no-login routes:** 0 new. There are 2 new routes, both authenticated: POST tasks/{task}/archive and GET task-boards/{taskBoard}/archived. The 8 monitoring agent routes gained a deadlock retry and nothing else.
   - **Google/Meta writes:** 0 new.
   - **app/Support/Social:** 0 lines changed.
   - **Data-handling changes:**
     - users.hours_type: Full time / Part time, used by the weekly time report.
     - tasks.archived_at: archive a Task Board item.
     - Review auto-replies mark a review Google removed with removed_at and skip it. This uses an existing column; no migration.
     - Monday off: 352 Monday webhooks were deleted. Backups of the webhook list and the connection row are in /var/backups/ezbizi.

3. **Row counts (prod):**
   - training_attempts 59, training_progress 83, training_assignments 12 (unchanged).
   - phone_ring_groups 6; numbers resolving to a group: 6 of 15 (unchanged).
   - Scheduled Jobs (scheduled_for set, not soft-deleted, no workiz_jobs row): Triplex Group 80; 0 in any other org (unchanged).
   - Local Services leads 56,465, all with a remote_id; 0 without one.
   - A 7th social publisher: none (0 changes under app/Support/Social).
   - Also: chats 8, chat_messages 22 (was 2/2).

4. **EzChat (com.ezbizi.ezchat) in a store: not listed.**
   - App Store lookup by bundle id: resultCount 0. Control: com.ezbizi.app returns 1.
   - Google Play details page: 404.
   - The iOS builds are uploaded to App Store Connect, but none has been submitted for review.
