# Website daily 2026-11-27: desk reply (measured 2026-11-28 ~00:00 UTC)

1. **Live on web since 0655 (60a987b3b): 5 releases, live now 0665 (b201db3cd).**
   - 0656 (4bb9ee801)
   - 0657 (ae4e26092)
   - 0658 (09ea754ad)
   - 0661 (affec6379)
   - 0665 (b201db3cd)
   - Never deployed: 5. Each failed a gate, and its content shipped in a later release:
     - 0659 (cut 11e9fa05d): 6 new audit tests failed the backend gate.
     - 0660 (f23e1f4d5): phone client guard tests (popup dropdown, modal dialog, live records).
     - 0662 (1a901cf20): OrganizationCanvasTest (stale settings memo from the Chat auto-groups hook) and PersonalDataPurgeTest (notifications actor column).
     - 0663 (0602c1b2e): push_registration_test, MissingPluginException.
     - 0664 (4ff9ac6ac): SettingReadershipTest.
   - Rolled back: 0.

2. **`git diff 60a987b3b..b201db3cd`** (789 files, +20037/−6574; app/config/routes/client/lib/database: 577 files, +11544/−5402):
   - **New https hosts:** 0. There are 6 hosts on added lines, and each one is an existing call that was rewritten:
     - oauth2.googleapis.com tokeninfo, 2 calls: GET became POST, so the token moved from the URL to the body.
     - graph.facebook.com: the token moved to a header.
     - auth.thumbtack.com, 2 config values: the env override was removed.
     - api.telegram.org: the call moved inside a try.
     - www.ezbizi.com and app.ezbizi.com: our own hosts.
     - Removed: api.x.ai. GrokTagger was deleted, and Grok still runs through the CLI.
   - **OAuth scopes:** 0 new. The Thumbtack scope list is unchanged; only its env override was removed.
   - **Processors:** 0 new. 0 new SDKs: composer only drops laravel/pail (dev), and pubspec is unchanged. 0 added lines mention X/Twitter or Plaid.
     - Apple APNs: 2 new push types for Chat, both used by ChatController. VoIP (PushKit) carries the caller name, chat id and call id; a background push stops a ring or clears a read chat.
     - Firebase FCM: data-only, high-priority Chat messages to Android handsets that draw their own notification. These carry the title, body (hidden when "Show preview" is off) and badge.
     - Twilio: an existing account is matched by account SID, never by name alone.
     - Plaid: the matcher query was refactored, with 0 new calls.
   - **Public no-login routes:** 0 new.
     - 3 new routes, all inside the authenticated group: GET newsletters/{newsletter}, POST chat/read-all, DELETE notifications/devices.
     - 2 removed: the monitoring/dashcam-events routes.
     - The heartbeat group gained `chat-token` and `api-key`, so a read-only key can no longer post heartbeats.
   - **Google/Meta writes:** 0 new. The Facebook post delete already existed; only its token moved to a header.
   - **app/Support/Social:** 13 files, +89/−77. 0 files added.
     - Meta tokens moved from URLs to headers: 3 lead-form reads, the Facebook delete and permalink reads, the Instagram permalink, and WhatsAppHealth's debug_token app secret. Its timeout is now caught, so the URL is no longer logged.
     - MetaMessages refuses, and reports, a Page connected in more than one organization instead of routing its message to whichever org sorts first.
     - WhatsApp bill and result photos are downloaded with the token of the number they arrived on. The unused flushPending method was removed.
     - WhatsApp period reports: APPROVE is a conditional update, so 2 at once send once, and a second APPROVE cannot reach an older batch.
     - Smaller changes: GoogleToken marks a dead connection broken inside its org, Thumbtack PKCE is hard-off (it was already off by default), and SocialConnectionAccess memoises lookups (a performance change).
   - **Data-handling changes:**
     - 8 migrations. New columns: 5.
       - notifications.actor_user_id: who caused a notification. It is a nullable FK to users and is nulled when that user is deleted. It is registered as personal data and erased with the row.
       - chats.auto_key: the key of an automatic company group (kind:company id), unique per org.
       - device_tokens.capabilities: JSON, with one allowed value, `chat_native`. There is also a new platform value, `ios_voip` (a PushKit token), with no migration. It only ever gets ringing-call pushes.
       - project_board_messages.for_date, failed_at and failures: one client message per job, kind and day, plus a failure count.
       - web_sites.numbered_high: the highest template or about-us number ever used, so a removed number is never handed out again.
     - Changed: 4 phone_numbers flags (record, transcribe, voicemail_transcribe, after_hours) are now nullable, and null means "inherit". The migration rewrote false/'voicemail' to null.
     - Dropped: 5 tables and 1 column. The only rollback copy is each migration's empty schema in down().
       - camera_events, vehicle_positions, telematics_devices and telematics_connections. These held vehicle GPS, the driver, dashcam events and vendor credentials. All four held 0 rows on prod when they were dropped (measured 11-27), and a1leads_review_reply_receipts held 0 too.
       - a1leads_review_reply_receipts.
       - monitoring_cameras.telematics_device_id.
       - Retention now governs 59 tables (was 62).
     - Erasure: a customer's erasure now covers their WhatsApp messages, matched on phone as counterparty (app/Models/WhatsAppMessage.php, not Social). Before, it skipped them.
     - Training: jobs tagged Training, and calls to or from their numbers, are left out of Performance, Scoring, the Leaderboard and My performance (CallReconciliation::withoutTraining). A number that is also on a real job keeps its calls.
     - EzChat: it signs in with a password and no authenticator, and gets a chat-only token. RestrictChatTokens refuses everything except chat, /me, auth, its push device, avatars and chat files (read only).
     - A deactivated staff account now closes its linked CRM login.
     - SMS sign-in codes: US and Canadian numbers only, 3 sends per user per hour, 5 per number per day, and a capped number of wrong guesses.
     - Password reset and email verification notifications are encrypted in the queue (ShouldBeEncrypted). Link expiry is shown in UTC.
     - CSV: report exports now use the same formula defusing as data exports (Exporter::cell). Notes are exported as plain text.

3. **Row counts (prod):**
   - training_attempts 59, training_progress 83, training_assignments 12 (unchanged).
   - phone_ring_groups 6; numbers resolving to a group: 6 of 15 (unchanged).
   - Scheduled Jobs (scheduled_for set, not soft-deleted, no workiz_jobs row): Triplex Group 80; 0 in any other org (unchanged).
   - Local Services leads 56,601, all with a remote_id; 0 without one.
   - A 7th social publisher: no. 0 files were added under app/Support/Social.
   - chats 17, chat_messages 26 (were 8/22). This includes 9 automatic company groups created 11-27.
   - Plaid: bank_accounts 0, bank_transactions 0, plaid connections 0.
