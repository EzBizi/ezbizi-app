# Repo personal-data and secret inventory, 2026-11-25

Scope: the **current tree** (`git ls-files`, 5,547 files) on `session/repo-hygiene`,
before and after the scrub. No values appear in this file, only where and how
many. History is not covered here; see `history-rewrite-plan.md`.

## Method

- Phone numbers: US formats (`(305) 555-0100`, `305-555-0100`, `305.555.0100`,
  `+13055550100`, bare 10/11 digits next to phone words), with each hit read in
  context to sort business lines from people.
- Emails: every address on a consumer domain (gmail, yahoo, icloud, hotmail,
  outlook, aol, msn, me, ymail, …) and every address on `a-1chimney.com`,
  `ezbizi.com`, `everdoors.com`.
- Street addresses: house number + capitalised street + suffix, plus
  `Address:` / `address` fields; each read in context.
- Names: every `name`/`contact_name`/table-cell "First Last" in tests, docs and
  code (732 distinct), sorted by hand into customer / staff / fiction.
- Secrets: `password|secret|token|api_key|client_secret = "literal"`, tracked
  `.env*`, `*.jks|*.keystore|*.p8|*.p12|*.pem|*.key|key.properties`, Twilio
  `AC…` SIDs, private-key headers, common token prefixes.

Control: the same personal-data rules that `.gitleaks.toml` enforces report
**401 hits in 126 file/kind pairs on the tree before the scrub and 0 after**.

## Scrubbed (189 files)

| Kind | Replaced with | Count |
|---|---|---|
| Phone numbers of customers and staff (106 distinct) | same area code, `555-01NN` (reserved for fiction), one fixed mapping so every format of a number in a test still agrees | 529 |
| Customer names next to those numbers (45 people) | same first name, fictional surname of the same initial | 139 |
| Customer street names (19 addresses) | house number and town kept, street name `Sample…` | 73 |
| Personal emails (customers; staff personal gmail/yahoo in the Monday notes) | `…sample…@` same consumer domain in tests (domain logic kept), `staffNN.sample@example.com` in docs | 35 |
| Old Twilio parent Account SID (docs) | `AC` + 32 `x` | 1 |

By area: `app/` 24 files and `client/lib/` 11 (comments and example strings
only: doc blocks quoting a real incident, one Grok help sentence, one settings
help text, release-note strings in `version_history.dart`; no behaviour change),
`database/migrations/` 1 (doc block only), `tests/` 93, `client/test/` 28,
`docs/previews/` 18, other `docs/` 14.

## Deliberately left

| What | Where | Why |
|---|---|---|
| Business phone lines (16: Twilio lines of A-1 Chimney / EzBizi, WhatsApp WABA numbers, Everdoors website lines) | `docs/agents/twilio-new-account-plan-2026-11-19.md`, WhatsApp migration, phone tests | Company lines, published; the porting plan needs them. Listed in `scripts/hooks/pii-allowlist.txt`. |
| Toll-free numbers (800/833/844/855/866/877/888) | config, docs, tests | EzBizi / A-1 business lines. |
| `Customer::PLACEHOLDER_PHONES` (12) and `PLACEHOLDER_EMAILS` (`no@gmail.com`, `nomail@yahoo.con`, …) | `app/Models/Customer.php`, its tests, `ProjectWorkizRecords.php` | Product constants: the junk values dispatchers typed for "none", filtered by the app. Changing them changes behaviour. |
| Generic first-name fixtures (`nora@`, `bob@`, `person@` on gmail) | three tests | Not identifying; `nora@` is the deliberate near-miss of the `no@` placeholder. |
| Business emails on company domains (`info@`, `office@`, `support@`, `jose@ezbizi.com`, …) and named staff work addresses (`firstname@a-1chimney.com`) | docs, tests, `MondayAutomations.php` | Business contact; staff work addresses are guessable from first names that appear throughout the docs. **Human decision**: scrub named staff work addresses before going public? |
| Staff and contractor names (Jose, Bar, Shoval, Chaim, Rodrigo, Toula, Trey, Adeline, …) | docs, code comments ("Trey Brannan's suggestion"), tests | Hundreds of attributions across the repo. **Human decision** (see below). |
| Fictional fixtures already in place (`Jane Doe`, `Dana Reyes`, `555-…`, `*@example.com/.test`) | tests, previews | Already fiction. |
| Business addresses (A-1 office lines in scheduling tests, the HQ address in the Twilio plan, public buildings) | tests, docs | Business premises, not homes. |
| Ads/Monday/Workiz numeric ids that look like phones | docs/agents Monday notes | Board and account ids, not phone numbers. |

## Secrets check

- No tracked `.env` except `.env.example` (all secret values `null`/empty).
- No tracked `*.jks`, `*.keystore`, `*.p8`, `*.p12`, `*.pem`, `*.key`,
  `key.properties`, credential JSON.
- `client/android/app/google-services.json` holds the public Firebase Android
  key: expected in the tree, allowlisted.
- `password|token|secret = '…'` literals: ~700 hits, all in tests (fakes such as
  `'secret-token'`, factory passwords) and model `$hidden` lists. None is a live
  value.
- Twilio SIDs in tests are `ACaaaa…`/`ACbbbb…` fakes. The one real parent SID
  (docs) is scrubbed; it remains in history.
- Earlier full-history scan (10,496 commits): no AWS/Stripe/Anthropic/GitHub/
  Slack/Google OAuth/Facebook tokens; `BEGIN PRIVATE KEY` hits are placeholders.

## Guards added

- `.gitleaks.toml`: default rules + `pii-us-phone-number`, `pii-personal-email`
  (both scoped to `docs/`, `tests/`, `client/test/`) + `twilio-account-sid`.
- `scripts/hooks/pre-commit` + `scripts/install-hooks.sh` (per clone:
  `bash scripts/install-hooks.sh`).
- `.github/workflows/secret-scan.yml`: gitleaks on the commits of every push/PR.
- `.gitignore`: signing keys, `key.properties`, credential folders, spreadsheet
  and CSV exports under `docs/` (the canonical labels CSV is kept by name).

## Per-file detail (current tree, before -> after)

| File | Kind: count |
|---|---|
| `app/Console/Commands/LeadsRead.php` | phone: 1, customer name: 3 |
| `app/Console/Commands/MatchLeadsToJobs.php` | phone: 1, customer name: 1, street address: 1 |
| `app/Http/Controllers/Api/InspectionRunsController.php` | phone: 2 |
| `app/Http/Controllers/Api/LeadsController.php` | phone: 6, customer name: 1 |
| `app/Http/Controllers/Api/Phone/CallsController.php` | phone: 1 |
| `app/Http/Controllers/Api/SchedulingAvailabilityController.php` | street address: 1 |
| `app/Http/Controllers/Api/SmsCodesController.php` | phone: 1 |
| `app/Http/Controllers/Api/WhatsAppWebhookController.php` | phone: 1 |
| `app/Jobs/SendCallRecordings.php` | phone: 2 |
| `app/Models/Customer.php` | phone: 4 |
| `app/Models/Lead.php` | phone: 4, customer name: 1, street address: 2 |
| `app/Services/Leads/LeadInsights.php` | customer name: 1 |
| `app/Support/Ai/CallRecordingsRequest.php` | phone: 1 |
| `app/Support/Automations/ActionRunner.php` | phone: 4 |
| `app/Support/Leads/LeadDetails.php` | phone: 1, customer name: 2, street address: 3 |
| `app/Support/Leads/LeadSearch.php` | customer name: 1 |
| `app/Support/Leads/RingCentralLeadCalls.php` | phone: 1 |
| `app/Support/Notifications/LeadNotificationText.php` | phone: 4 |
| `app/Support/Phone/NumberNames.php` | phone: 1 |
| `app/Support/Reports/Experimental/RingCentralCallLog.php` | phone: 1 |
| `app/Support/Settings/SettingRegistry.php` | phone: 1 |
| `app/Support/Social/MetaMessages.php` | phone: 1 |
| `app/Support/Social/WhatsAppStaffBot.php` | phone: 3 |
| `app/Support/Web/WebsiteEnquiries.php` | customer name: 1 |
| `client/lib/core/phone_numbers.dart` | phone: 3 |
| `client/lib/core/version_history.dart` | phone: 7 |
| `client/lib/screens/hr/records_tab.dart` | phone: 1 |
| `client/lib/screens/phone/call_tab.dart` | phone: 6 |
| `client/lib/screens/phone/voice_device_mobile.dart` | phone: 1 |
| `client/lib/screens/profile_screen.dart` | phone: 1 |
| `client/lib/screens/reports/jobs_report.dart` | phone: 1 |
| `client/lib/screens/reports/report_run_view.dart` | phone: 2 |
| `client/lib/screens/scheduling/availability_pane.dart` | street address: 1 |
| `client/lib/widgets/lead_thread.dart` | phone: 2, customer name: 1 |
| `client/lib/widgets/quick_search.dart` | phone: 1 |
| `client/test/ads_performance_screen_test.dart` | phone: 1 |
| `client/test/automations_screen_test.dart` | phone: 3 |
| `client/test/availability_button_test.dart` | phone: 3 |
| `client/test/book_job_panel_test.dart` | phone: 5, street address: 2 |
| `client/test/complaints_test.dart` | phone: 2 |
| `client/test/email_settings_test.dart` | personal email: 3 |
| `client/test/experimental_call_report_test.dart` | phone: 2 |
| `client/test/jobs_report_test.dart` | phone: 9 |
| `client/test/lead_thread_reply_window_test.dart` | customer name: 1 |
| `client/test/lead_thread_test.dart` | phone: 6, customer name: 3, street address: 7 |
| `client/test/leads_phone_lines_test.dart` | phone: 4 |
| `client/test/leads_screen_test.dart` | phone: 2, customer name: 1 |
| `client/test/live_modules_test.dart` | customer name: 2, street address: 2 |
| `client/test/phone_call_service_test.dart` | phone: 3 |
| `client/test/phone_call_tab_test.dart` | phone: 18 |
| `client/test/phone_numbers_test.dart` | phone: 13 |
| `client/test/phone_records_tab_test.dart` | phone: 1 |
| `client/test/phone_screen_test.dart` | phone: 3 |
| `client/test/phone_voicemail_names_test.dart` | phone: 3 |
| `client/test/profile_screen_test.dart` | phone: 3 |
| `client/test/quick_search_and_callout_test.dart` | phone: 5, customer name: 3 |
| `client/test/rescheduling_pane_test.dart` | street address: 1 |
| `client/test/scheduling_availability_test.dart` | street address: 5 |
| `client/test/scheduling_board_test.dart` | customer name: 8, street address: 2 |
| `client/test/scheduling_overbooking_test.dart` | street address: 2 |
| `client/test/search_report_run_view_test.dart` | phone: 4 |
| `client/test/search_rescheduling_test.dart` | street address: 1 |
| `client/test/task_board_column_types_test.dart` | phone: 3 |
| `database/migrations/2026_09_03_180000_store_phone_numbers_as_e164.php` | phone: 3 |
| `docs/LEADS.md` | phone: 2 |
| `docs/UI-DESIGN-QUEUE.md` | phone: 1 |
| `docs/WEBSITE-CLAIMS.md` | phone: 1 |
| `docs/agents/inbox/0140-desk-to-all-modules-full-app-review.md` | phone: 1 |
| `docs/agents/inbox/done/0035-deploy-desk-to-website-what-shipped-0141-to-0151.md` | phone: 1 |
| `docs/agents/inbox/done/0042-deploy-desk-to-website-three-row-counts-answered.md` | phone: 1 |
| `docs/agents/inbox/done/0043-deploy-desk-to-website-a-customer-enquiry-has-arrived.md` | phone: 2, customer name: 1 |
| `docs/agents/monday-board-map-2026-11-13.json` | personal email: 2 |
| `docs/agents/monday-migration-plan-2026-11-12.md` | personal email: 9 |
| `docs/agents/twilio-new-account-plan-2026-11-19.md` | phone: 1, Twilio SID: 1 |
| `docs/previews/account-profile-layouts.html` | phone: 2 |
| `docs/previews/account-profile-phone-layouts.html` | phone: 3 |
| `docs/previews/call-flow-modern-layouts.html` | phone: 2 |
| `docs/previews/leads-accounts-layouts.html` | phone: 9 |
| `docs/previews/leads-booking-layouts.html` | phone: 3 |
| `docs/previews/leads-compact-layouts.html` | street address: 1 |
| `docs/previews/leads-connections-layouts.html` | phone: 16 |
| `docs/previews/leads-conversations-layouts.html` | phone: 2 |
| `docs/previews/leads-triage-layouts.html` | phone: 1 |
| `docs/previews/phone-dial-layouts.html` | phone: 1 |
| `docs/previews/reports-operations-layouts.html` | customer name: 2 |
| `docs/previews/rescheduling-spots-layouts.html` | street address: 1 |
| `docs/previews/reviews-complaints-layouts.html` | customer name: 6 |
| `docs/previews/reviews-inbox-layouts.html` | customer name: 10 |
| `docs/previews/sales-pipeline-layouts.html` | phone: 6, customer name: 18 |
| `docs/previews/scheduling-locations-look-layouts.html` | street address: 3 |
| `docs/previews/scheduling-rescheduling-layouts.html` | street address: 2 |
| `docs/previews/scheduling-routing-layouts.html` | street address: 1 |
| `docs/reviews/2026-11-02/customers.md` | phone: 3 |
| `docs/reviews/2026-11-02/email.md` | personal email: 2 |
| `docs/reviews/2026-11-04/ui-ux-mobile.md` | phone: 4 |
| `docs/reviews/ui-pass-2026-11-17.md` | street address: 1 |
| `tests/Feature/Api/AutomationEngineTest.php` | phone: 3 |
| `tests/Feature/Api/CustomerRealContactTest.php` | phone: 2 |
| `tests/Feature/Api/CustomerSearchLeadsTest.php` | phone: 3, customer name: 2 |
| `tests/Feature/Api/InspectionRunsTest.php` | phone: 2 |
| `tests/Feature/Api/JobAddressFlagsTest.php` | customer name: 2 |
| `tests/Feature/Api/MicrosoftMailOAuthTest.php` | personal email: 8 |
| `tests/Feature/Api/SmsCodesConsentTest.php` | phone: 1 |
| `tests/Feature/Automations/CustomerTextLimitsTest.php` | phone: 2 |
| `tests/Feature/Automations/TaskStepsTest.php` | phone: 7 |
| `tests/Feature/CustomerExperience/ComplaintsTest.php` | phone: 3 |
| `tests/Feature/Dashboard/PhoneGlanceTest.php` | phone: 1 |
| `tests/Feature/Jobs/SourceFromDialedTest.php` | phone: 14 |
| `tests/Feature/Leads/BookingBotTest.php` | phone: 1 |
| `tests/Feature/Leads/CallTypeCorrectionsTest.php` | phone: 8 |
| `tests/Feature/Leads/FollowUpDueTest.php` | phone: 1, customer name: 1 |
| `tests/Feature/Leads/LeadConversationsTest.php` | phone: 5 |
| `tests/Feature/Leads/LeadNotificationFieldsTest.php` | phone: 3 |
| `tests/Feature/Leads/LeadSearchTest.php` | phone: 5, customer name: 5 |
| `tests/Feature/Leads/LeadSourceDetailsTest.php` | phone: 3 |
| `tests/Feature/Leads/LeadsCallOwnerTest.php` | customer name: 2, street address: 1 |
| `tests/Feature/Leads/LeadsReadTest.php` | phone: 14, personal email: 5, customer name: 12, street address: 6 |
| `tests/Feature/Leads/LocalServicesLeadsTest.php` | phone: 3, customer name: 2 |
| `tests/Feature/Leads/LsaQuoteTest.php` | phone: 1, customer name: 1, street address: 1 |
| `tests/Feature/Leads/MatchLeadsToJobsTest.php` | phone: 6, personal email: 4, customer name: 4, street address: 2 |
| `tests/Feature/Leads/RecoveryInboxTest.php` | phone: 1, customer name: 2, street address: 11 |
| `tests/Feature/Leads/ReplyOverdueTest.php` | phone: 2 |
| `tests/Feature/Leads/ReplyTemplatesTest.php` | customer name: 2, street address: 1 |
| `tests/Feature/Leads/ReplyWindowTest.php` | customer name: 1 |
| `tests/Feature/Leads/RingCentralLeadCallsTest.php` | phone: 12, customer name: 2 |
| `tests/Feature/Leads/ThumbtackRepliesTest.php` | customer name: 2 |
| `tests/Feature/Leads/ThumbtackTestLeadTest.php` | phone: 1 |
| `tests/Feature/Monitoring/ClientIssuesTest.php` | phone: 2 |
| `tests/Feature/Phone/PhoneAnswerWithTest.php` | phone: 2 |
| `tests/Feature/Phone/PhoneAnsweredByTest.php` | phone: 1 |
| `tests/Feature/Phone/PhoneAutomationsTest.php` | phone: 3 |
| `tests/Feature/Phone/PhoneBlockedCallerTest.php` | phone: 2 |
| `tests/Feature/Phone/PhoneBridgeCallTest.php` | phone: 5 |
| `tests/Feature/Phone/PhoneCallFlowEditTest.php` | phone: 2 |
| `tests/Feature/Phone/PhoneCallFlowLiveTest.php` | phone: 1 |
| `tests/Feature/Phone/PhoneCallParkTest.php` | phone: 1 |
| `tests/Feature/Phone/PhoneCallPeerNameTest.php` | phone: 15 |
| `tests/Feature/Phone/PhoneCallPickupTest.php` | phone: 1 |
| `tests/Feature/Phone/PhoneCallSyncTest.php` | phone: 1 |
| `tests/Feature/Phone/PhoneCallTransferTest.php` | phone: 1 |
| `tests/Feature/Phone/PhoneCallerLookupTest.php` | phone: 1 |
| `tests/Feature/Phone/PhoneColleagueCallTest.php` | phone: 1 |
| `tests/Feature/Phone/PhoneDeviceRingingTest.php` | phone: 1 |
| `tests/Feature/Phone/PhoneDialAnExtensionTest.php` | phone: 1 |
| `tests/Feature/Phone/PhoneJoinRingingCallTest.php` | phone: 1 |
| `tests/Feature/Phone/PhoneLineInUseTest.php` | phone: 2 |
| `tests/Feature/Phone/PhoneNumbersInUseTest.php` | phone: 2 |
| `tests/Feature/Phone/PhonePagingTest.php` | phone: 1 |
| `tests/Feature/Phone/PhonePortTest.php` | phone: 1 |
| `tests/Feature/Phone/PhoneQueueCallbackTest.php` | phone: 1 |
| `tests/Feature/Phone/PhoneSmsTest.php` | phone: 3 |
| `tests/Feature/Phone/PhoneTeamExtensionTest.php` | phone: 1 |
| `tests/Feature/Phone/PhoneVoicemailBoxReachTest.php` | phone: 1 |
| `tests/Feature/Phone/PhoneVoicemailOwnerTest.php` | phone: 1 |
| `tests/Feature/Phone/PhoneVoicemailTilesTest.php` | phone: 1, customer name: 1 |
| `tests/Feature/Phone/PhoneVoicemailTranscriptTest.php` | phone: 1 |
| `tests/Feature/Phone/PhoneWrapUpTest.php` | phone: 1 |
| `tests/Feature/Phone/SmsConsentTest.php` | phone: 4 |
| `tests/Feature/Phone/TwimlRendererTest.php` | phone: 7 |
| `tests/Feature/Phone/VoiceWebhookTest.php` | phone: 10 |
| `tests/Feature/PhoneNumberNormalisationTest.php` | phone: 6 |
| `tests/Feature/Reports/AiBookedNoJobTest.php` | phone: 4 |
| `tests/Feature/Reports/ExperimentalCallReportApiTest.php` | phone: 3, customer name: 2 |
| `tests/Feature/Reports/ExperimentalCallReportTest.php` | phone: 3, customer name: 1 |
| `tests/Feature/Reports/JobsReportTest.php` | phone: 1, customer name: 1 |
| `tests/Feature/Reviews/ReviewCasesTest.php` | phone: 2, customer name: 1 |
| `tests/Feature/Scheduling/SchedulingAvailabilityTest.php` | street address: 3 |
| `tests/Feature/Scheduling/SchedulingLocationsTest.php` | phone: 4 |
| `tests/Feature/Social/MetaLeadFormsTest.php` | phone: 3 |
| `tests/Feature/Social/MetaMessagesWebhookTest.php` | customer name: 2 |
| `tests/Feature/Social/ThumbtackLeadIngestionTest.php` | customer name: 1 |
| `tests/Feature/Tasks/TaskBoardCustomizationTest.php` | phone: 2 |
| `tests/Feature/Tasks/TaskRecordFillTest.php` | phone: 3, customer name: 11 |
| `tests/Feature/Tasks/TasksJobTitlesTest.php` | customer name: 3 |
| `tests/Feature/Telegram/TelegramBotTest.php` | phone: 3 |
| `tests/Feature/Web/WebChatPeopleTest.php` | phone: 2 |
| `tests/Feature/Web/WebsiteEnquiriesTest.php` | phone: 8, customer name: 1 |
| `tests/Feature/WhatsApp/TechAssistantWhatsAppTest.php` | phone: 5 |
| `tests/Feature/WhatsApp/WhatsAppConnectTest.php` | phone: 2 |
| `tests/Feature/WhatsApp/WhatsAppIntakeTest.php` | customer name: 2 |
| `tests/Feature/WhatsApp/WhatsAppNumberPurposeTest.php` | phone: 3 |
| `tests/Feature/WhatsApp/WhatsAppSendTest.php` | phone: 16 |
| `tests/Feature/WhatsApp/WhatsAppStaffBotTest.php` | phone: 16 |
| `tests/Feature/WhatsApp/WhatsAppWebhookTest.php` | phone: 10 |
| `tests/Unit/FormSpamTest.php` | personal email: 1, customer name: 1 |
| `tests/Unit/LeadDetailsTest.php` | phone: 3, personal email: 1, customer name: 5, street address: 9 |
| `tests/Unit/LeadNotificationTextTest.php` | phone: 10 |
| `tests/Unit/LeadPhoneTest.php` | phone: 8 |
| `tests/Unit/TidyNoticeTest.php` | phone: 5 |
