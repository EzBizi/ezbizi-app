# Website daily 2026-11-25: desk reply (measured 2026-11-25 ~23:45 UTC)

1. **Live on web since 0635 (b723830e0):**
   - 0638 (3d930fce1), carrying 0636 and 0637.
   - 0640 (e93a7fb85), carrying 0639.
   - 0641 (7f6e8f8fb).
   - 0642 (0ac8fa8e2).
   - 0643 (18fc94395).
   - 0644 (51279055d).
   - 0646 (28467fde2), carrying 0645.
   - 0649 (9b6a0fc24), carrying 0647 and 0648.
   - 0650 (46bdaea54), live now; Windows too.
   - Never deployed:
     - 0636: flaky EstimatesApiTest.
     - 0637: AutomationsApiTest arms guard.
     - 0639: PlaidBankTest clock near midnight.
     - 0645: PhoneModuleTest didn't know Chat's tab; then stopped for Jose's ASAP work-schedule lock.
     - 0647: AutomationEngineTest bell grouping. Its hotfix was refused because the SHA wasn't on main.
     - 0648: WebManagementTest still expected "Tested up to 7.1".
   - Nothing rolled back.

2. **`git diff b723830e0..46bdaea54`:**
   - **New https hosts:** production.plaid.com and sandbox.plaid.com (Plaid), sdk.twilio.com (Twilio Video JS on the public call page, pinned with SRI), x.monday.com (Monday sync only; Monday is disconnected tonight).
   - **OAuth scopes:** no new ones. Plaid Link is not OAuth to a Google/Meta account.
   - **Processors:**
     - Plaid (read-only bank data: balances and transactions) is new and live. The Link customization was fixed in Plaid's dashboard today.
     - Twilio Video, under the existing Twilio account, is used for Chat voice and video calls. Nothing is recorded.
   - **app/Support/Social:** 8 files. Plaid OAuth/relink; WhatsAppCloud stamps the report key and template text on report sends; staff-bot tweaks.
   - **Google/Meta writes:** none new.
   - **Public no-login routes:**
     - GET v1/social/plaid/relinked (Plaid's return page).
     - POST v1/integrations/plaid/webhook (Plaid webhook, verified).
     - GET v1/chat/call: a static call page with no data. The token travels in the URL fragment. no-store, camera and mic Permissions-Policy self, X-Frame-Options SAMEORIGIN.
     - Every other new route is inside the authenticated group (task-board, financials/bank, chat, leaderboard, notifications kinds, and so on).
   - **Data-handling changes:**
     - **Chat** (new): staff-to-staff messages, photos, files, voice notes and call lines. Tables chats / chat_members / chat_messages, 24-month retention, members only. Calls run on Twilio Video, not recorded.
     - **Sent reports:** WhatsApp report template text is now kept in whatsapp_messages.body, and a new report_emails table keeps emailed report bodies.
     - **Notification settings:** per-kind in-app and email switches. Email notices go through the existing mail path.
     - **Customer Experience:** complaints link to branch tasks; 295 links.
     - users.also_known_as (Monday nicknames) and device_tokens.app (ezbizi/ezchat).
     - Privacy page updated today for the camera and mic in Chat calls (6479e4937).
   - **Plaid live:** yes. bank_accounts 0, bank_transactions 0, plaid connections 0. Nobody has connected a bank yet; the first connect needs a person signing in to their bank.

3. **Row counts (prod):**
   - training_attempts 59, training_progress 83, training_assignments 12 (unchanged).
   - phone_ring_groups 6; phone_numbers resolving to a group: 6, of 15 in total (unchanged).
   - Scheduled Jobs (scheduled_for set, not soft-deleted, no workiz_jobs row): Triplex Group 80; 0 in any other org (unchanged).
   - Local Services leads: 56,434, all 56,434 with a remote_id.
   - New publisher class under app/Support/Social: none.
   - Also: chats 2, chat_messages 2 (test accounts only).
