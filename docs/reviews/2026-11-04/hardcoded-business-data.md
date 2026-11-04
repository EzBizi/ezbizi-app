# Hardcoded business data — inventory (2026-11-04)

Read-only sweep of `app/`, `config/`, `routes/console.php`, `client/lib/`. Ruling (Jose): every
business value is editable in the app and through the API (so the ezbizi MCP can edit it),
never a code constant that needs a release.

Excluded: framework/technical constants, vendor API bases, code-coupled enum states (lead
sources tied to integrations, invoice/estimate status machines), seed/demo commands, tests,
docs, `.env` secrets, comments/docblocks that merely quote a number or address.

Frequency: **O** often (monthly or faster), **S** sometimes (a few times a year), **R** rarely.
Pain: **3** blocks work / wrong data until a release, **2** visible but tolerable, **1** cosmetic.

## 1. Summary

### Count by module

| Module | Findings |
|---|---|
| Reports (calls, scoring, daily texts) | 17 |
| Phone | 7 |
| Leads | 8 |
| WhatsApp / Grok / AI | 8 |
| Scheduling (cron times + scheduling rules) | 10 |
| Customers / Customer Experience / Reviews | 7 |
| Jobs / Workiz | 5 |
| HR / Time clock | 4 |
| Email | 2 |
| Marketing / Ads / Web | 5 |
| Fleet / Memberships / Misc | 4 |
| Client (Flutter) | 4 |
| **Total** | **81** |

### Top 15 to move first (frequency x pain)

| # | Where | What | Freq | Pain |
|---|---|---|---|---|
| 1 | `app/Support/Reports/Experimental/CallReconciliation.php:67` `ALIASES` | Phone-system name -> Workiz name, 10 staff pairs; grows with every hire | O | 3 |
| 2 | `config/services.php:432-433` `audit_alert_user_ids` default | 3 hardcoded user UUIDs who get phone-line audit alerts | S | 3 |
| 3 | `app/Support/Reports/Tagging/CallLabels.php:14,32` `EXISTING`, `NOT_BOOKED` | Call-tag taxonomy + AI definitions; changed 3 times in Oct | O | 3 |
| 4 | `app/Models/Customer.php:68,105` `PLACEHOLDER_EMAILS`, `PLACEHOLDER_PHONES` | Junk/office emails and relay phones ignored on customers | O | 2 |
| 5 | `app/Console/Commands/WatchMissedCalls.php:34` `COMPANIES` | RingCentral company label -> EzBizi company name (A-1, Speedy, Ace, TX) | S | 3 |
| 6 | `app/Support/Reports/Experimental/RingCentralCallLog.php:84,413,415` | Org slug `a-1-chimney`, queues `A1 - New Client` / `A1 - Existing Client` (settings `leads.new_client_queue` / `leads.existing_client_queue` already exist — duplicate) | S | 3 |
| 7 | `app/Console/Commands/CallScoringClause.php:30` `CONTRACTS` | Contract names whose staff get the AI-recording clause | S | 3 |
| 8 | `routes/console.php:399-404` | WhatsApp same-day report 21:05/21:35, weekly Wed 08:00/12:00 | S | 3 |
| 9 | `routes/console.php` (23 lines) + ~10 commands | Hardcoded `America/New_York` instead of org `timezone` setting | S | 3 |
| 10 | `app/Models/Lead.php:618,624` | Reply overdue after 10 min; follow-up after 3 h | S | 2 |
| 11 | `app/Support/Reports/Texts/DailyTexts.php:31` `KINDS` | Which daily/weekly WhatsApp report kinds exist and their titles | S | 2 |
| 12 | `app/Support/Workiz/WorkizJobWriter.php:393` `STATUSES` | Workiz statuses incl. tenant-custom `done pending approval` | S | 3 |
| 13 | `app/Support/Scheduling/Openings.php:102,105` | Free statuses; excluded tags `deposit received` (+ typo) | S | 2 |
| 14 | `app/Console/Commands/ScoreReportCalls.php:29,75` `CHECKS`, `PROMPT` | Dispatcher scoring checklist and AI coach prompt | S | 2 |
| 15 | `app/Http/Controllers/Api/WhatsAppGroupsController.php:30` `PURPOSES` | WhatsApp group purposes (financial, dispatch, bookkeeper approval) | S | 2 |

## 2. Full list, by module

Columns: location — value — business meaning — freq — suggested home.
"Setting" means a new `SettingDefinition` in `app/Support/Settings/SettingRegistry.php`
(cascade org > company > franchise, already served by `GET/PATCH/DELETE /api/v1/settings/{key}`).
"Lists" means the new generic list store proposed in section 3.

### Reports

| Location | Value | Business thing | Freq | Home |
|---|---|---|---|---|
| `app/Support/Reports/Experimental/CallReconciliation.php:67` | `ALIASES` 10 name pairs | Staff name aliases phone <-> Workiz | O | Lists `staff_name_aliases` (or a `user_aliases` table keyed to User) |
| `app/Support/Reports/Experimental/RingCentralCallLog.php:84` | `ENV_ORGANIZATION_SLUG = 'a-1-chimney'` | Which tenant owns the env RingCentral account | R | RingCentral connection row (`connections`) |
| `app/Support/Reports/Experimental/RingCentralCallLog.php:413,415` | `A1 - New Client`, `A1 - Existing Client` | Call queue names | S | Existing settings `leads.new_client_queue` / `leads.existing_client_queue`; delete constants |
| `app/Support/Reports/Experimental/RingCentralCallLog.php:81` | `RETENTION_MONTHS = 12` | Call-log retention policy | R | Setting |
| `app/Http/Controllers/Api/ExperimentalCallReportController.php:58` | `LOOKBACK_DAYS = 30` | Call report window | R | Setting |
| `app/Support/Reports/Tagging/CallLabels.php:14` | `EXISTING` 11 labels + definitions | Existing-client call tags | O | Lists `call_labels.existing` (key, label, AI definition) |
| `app/Support/Reports/Tagging/CallLabels.php:32` | `NOT_BOOKED` 10 labels | New-client not-booked reasons | O | Lists `call_labels.not_booked` |
| `app/Support/Reports/Tagging/CallLabels.php:52` | `BOOKING_RULES` text | What counts as "booked" | S | Setting (text) |
| `app/Console/Commands/ScoreReportCalls.php:29` | `CHECKS` 7 items | Dispatcher scoring checklist | S | Lists `scoring.checks` (weights already in `ReportScoringWeight`) |
| `app/Console/Commands/ScoreReportCalls.php:75` | `PROMPT` | AI coach prompt; names chimneys/fireplaces/garage doors | S | Setting (text), interpolate org trades |
| `app/Support/Reports/Scoring/ScoringWeights.php:22` | `DEFAULTS` 50/15/5... | Default score weights (saved set exists) | R | Fine as fallback; seed into `ReportScoringWeight` on first read |
| `app/Http/Controllers/Api/ScoringReportController.php:34`, `app/Console/Commands/AiPersonReviews.php:23` | `MIN_CALLS = 3` (twice) | Min calls before a person is scored | S | One Setting `reports.scoring_min_calls` |
| `app/Support/Reports/Texts/DailyTexts.php:31` | `KINDS` 7 report kinds + titles | Which WhatsApp reports exist | S | Titles to Lists; kinds stay code |
| `app/Support/Reports/Texts/ReportGoals.php:18` | `KEYS` 10 goals + direction | Branch goal metrics (values already stored) | R | OK as code; labels to Lists if renamed |
| `app/Support/Reports/Texts/SameDayReport.php:32,34` | stale 30 / 60 min | When same-day data counts stale | R | Setting |
| `app/Support/Reports/MarketingReport.php:28,31` | `PAID = LS Ads, PPC`; `LEAD_SOURCES` | Which Workiz ad sources are paid | S | Lists `marketing.paid_sources` |
| `app/Support/Reports/Airtable/ReportSources.php:25` | `FIELDS` Airtable column names | Report column mapping to bookkeeper sheets | S | `FinancialSource` field map (exists for financials) |

### Phone

| Location | Value | Business thing | Freq | Home |
|---|---|---|---|---|
| `config/services.php:432` | 3 user UUIDs default | Who gets line-audit alerts | S | Setting `phone.audit_alert_users` (TYPE_ROLES/user list) |
| `config/services.php:434` | `+18559553322` | Audit test-call From number | R | Setting picking a PhoneNumber |
| `config/services.php:435` | `ACbfcc...fe4` | Twilio account SID for audit call | R | PhoneConnection row |
| `app/Console/Commands/PhoneLineAudit.php:32` | `VOICE_ELSEWHERE_EXPECTED` +18559553322 | Lines known to route elsewhere (ticket note) | S | Flag + note on `phone_numbers` |
| `app/Console/Commands/WatchMissedCalls.php:34` | `COMPANIES` 4 names | RC label -> company | S | Column on companies or Lists `ringcentral.company_labels` |
| `app/Console/Commands/WatchMissedCalls.php:31` | `FRESH_MINUTES = 30` | Missed-call alert window | R | Setting |
| `app/Http/Controllers/Api/Phone/QueueWaitWebhookController.php:180` | "Thank you for waiting..." | Hold-queue spoken text | S | Setting on ring group / queue |

### Leads

| Location | Value | Business thing | Freq | Home |
|---|---|---|---|---|
| `app/Models/Lead.php:618` | `REPLY_OVERDUE_AFTER_MINUTES = 10` | Reply SLA | S | Setting `leads.reply_overdue_minutes` |
| `app/Models/Lead.php:624` | `FOLLOW_UP_AFTER_HOURS = 3` | Follow-up SLA | S | Setting |
| `app/Models/Lead.php:898` | `AUTO_ANSWER_WITHIN_MINUTES = 60` | Auto-answer window | R | Setting |
| `app/Models/Lead.php:723`, `app/Http/Controllers/Api/LeadsController.php:577`, `app/Console/Commands/LeadsFollowUpDue.php:77` | "Hi {first}", "Hi there", "Hey" | Greeting words | R | Setting `leads.greeting` |
| `app/Http/Controllers/Api/LeadsController.php:1876` | `SYNC_FAILING_AFTER_HOURS = 6` | When lead sync is flagged | R | Setting |
| `app/Support/Leads/CallerLead.php:21` | `SAME_PERSON_DAYS = 30` | Repeat caller = same lead | R | Setting |
| `app/Support/Leads/EzLeadCalls.php:41`, `RingCentralLeadCalls.php:96` | announce within 60 min | Lead announce window | R | Setting |
| `app/Console/Commands/ImportWorkizLeads.php:67` | `ALREADY_OURS` lsads, localservices, thumbtack | Workiz ad sources ingested elsewhere | S | Lists `workiz.skip_sources` |

### WhatsApp / Grok / AI

| Location | Value | Business thing | Freq | Home |
|---|---|---|---|---|
| `app/Http/Controllers/Api/WhatsAppGroupsController.php:30` | `PURPOSES` 5 | Group purposes | S | Lists `whatsapp.group_purposes` |
| `app/Support/Social/WhatsAppGroups.php:31` | `READY_TEMPLATE = 'automation_alert'` | Meta template name | S | Setting (like `invoicing.whatsapp_template`) |
| `app/Support/Social/WhatsAppGroups.php:36` | `READY_LINE` text | Report-ready text | R | Setting |
| `app/Support/Social/WhatsAppResults.php:24` | caption regex results/resultados/ace | Words that mark an inspection result photo | S | Lists |
| `app/Support/Social/WhatsAppStaffBot.php:80` | `MAX_LOCATIONS = 5` | Staff bot menu size | R | Setting |
| `app/Services/Leads/LeadInsights.php:129` | `READ_PROMPT` names "A1 Chimney" + trades | AI enquiry classifier | S | Setting (text) with `{org}`/`{trades}` |
| `app/Support/Ai/TranscriptSpeakers.php:74` | `PROMPT` trades | AI speaker split | R | Same trades setting |
| `config/services.php:596` | `GROK_AI_SINCE 2026-10-13` | Grok read start date | R | Setting |

### Scheduling (cron times) — `routes/console.php`

| Location | Value | Business thing | Freq | Home |
|---|---|---|---|---|
| `routes/console.php:399-400` | 21:05, 21:35 ET | Same-day WhatsApp reports | S | Setting `reports.same_day_times` (scheduler reads at minute tick) |
| `routes/console.php:403-404` | Wed 08:00, 12:00 | Weekly period reports | S | Setting |
| `routes/console.php:476` | 12:00 | Leads daily digest | S | Setting |
| `routes/console.php:429,427` | 13:00, 15:00 | GBP weekly posts, review backlog | S | Setting |
| `routes/console.php:436,441,445-446,450` | 07:00, Mon 07:00, 06:30/06:45 | GBP digest, AI weekly summary, person reviews, ads review | S | Setting per report |
| `routes/console.php:49,268,271,274,279` | 08:05, 07:00, 08:10, 08:20, 09:00 | Invoice overdue, overbooking warn, estimate aging, paid-not-started, membership reminders | S | Setting per alert |
| `routes/console.php` (23x) + `AiPersonReviews.php:36`, `AiWeeklySummary.php:38`, `MetaAdsSync.php:38`, `WhatsAppSameDayReports.php:33`, `SendCallRecordings.php:52,69`, `SendRequestedReport.php:58`, `InventorySalesController.php:245` | `America/New_York` | Business day boundary | S | Existing `timezone` setting |
| `app/Support/Scheduling/Openings.php:102,105` | free statuses; `deposit received` tags | What frees/holds a slot | S | Lists, or existing `jobs.cancelled_statuses` |
| `app/Support/Scheduling/Overbooking.php:155` | `FULL_DAYS = 10` | Overbooking look-ahead | R | Setting |
| `app/Support/Scheduling/RescheduleOffers.php:43,45` | horizon 21, not-again 30 days | Offer policy (siblings already settings) | R | Setting |

### Customers / Customer Experience / Reviews

| Location | Value | Business thing | Freq | Home |
|---|---|---|---|---|
| `app/Models/Customer.php:68` | ~30 junk emails + `office@/info@/accounting@a-1chimney.com` | Placeholder emails | O | Lists `customers.placeholder_emails` |
| `app/Models/Customer.php:105` | 12 relay/office phones | Placeholder phones | O | Lists `customers.placeholder_phones` |
| `app/Support/Workiz/WorkizTwins.php:20` | `800 se 4th ave` | Placeholder street (office) | S | Lists |
| `app/Support/Reviews/ReviewAutoReplyRule.php:29` | 5 star-reply texts | Review reply defaults ("editable on the rule") | R | OK as seed; confirm editable via Automations |
| `app/Console/Commands/TasksCustomerExperience.php:43` | Complaint/Bad Review + colours | CX task types | R | Task board statuses (exist) |
| `app/Support/Memberships/CustomerLinks.php:24,27` | join 60, review 90 days | Link validity | R | Setting |
| `app/Support/Web/WidgetPresets.php:458`, `WidgetDefaults.php:276` | thank-you texts | Feedback/form texts (preset defaults) | R | OK if widget edits them |

### Jobs / Workiz

| Location | Value | Business thing | Freq | Home |
|---|---|---|---|---|
| `app/Support/Workiz/WorkizJobWriter.php:393` | statuses incl. `done pending approval` | Workiz tenant statuses | S | Read from Workiz lists (`WorkizRecords::LISTS` sub_status) |
| `app/Http/Controllers/Api/ProjectManagement2Controller.php:29` | `STATUS = 'done pending approval'` | PM status | S | Existing `jobs.pm_scope_statuses` |
| `app/Console/Commands/SyncWorkizJobs.php:412` | `CLOSED = Done, Canceled` | Closed statuses | R | Existing `jobs.cancelled_statuses` / `earned_statuses` |
| `app/Support/Jobs/EstimateAging.php:20` | `DAYS = 30` | Estimate aging threshold | S | Setting (statuses already a setting) |
| `app/Support/Workiz/WorkizRecords.php:25` | `SINCE = 2023-01-01` | Import start date | R | Workiz connection field |

### HR / Time clock

| Location | Value | Business thing | Freq | Home |
|---|---|---|---|---|
| `app/Console/Commands/CallScoringClause.php:30` | 3 contract names | Contracts that get the recording clause | S | Flag on contract template |
| `app/Console/Commands/CallScoringClause.php:32` | `CLAUSE` text | Legal clause text | R | Contract template body |
| `app/Models/TimeClockDay.php:41` + `client/lib/screens/hr/work_hours_tab.dart:2048` | office/home/absent/sick/holiday/vacation | Day tags (duplicated in client) | S | Lists `time_clock.day_tags` |
| `app/Support/Hr/HrOverview.php:38,41` | expiring 60, quiet 3 days | HR alert windows | R | Setting |

### Email

| Location | Value | Business thing | Freq | Home |
|---|---|---|---|---|
| `app/Mail/SavedReportMail.php:69`, `app/Support/Mail/PlatformIdentity.php:11` | `noreply@ezbizi.com` | Platform sender (platform, not tenant) | R | config is fine |
| `config/ezbizi.php:301`, `AddressLookupController.php:36`, `CountyLookupController.php:34`, `SchedulingLocationsController.php:201` | `development@ezbizi.com` UA contact | Platform contact | R | one config key |

### Marketing / Ads / Web

| Location | Value | Business thing | Freq | Home |
|---|---|---|---|---|
| `app/Services/Ads/LsaCertifications.php:42` | `WARN_DAYS = 30` | License expiry warning | R | Setting |
| `app/Console/Commands/AdsCampaignWatch.php:38` | `SHOWING` statuses | Google statuses (vendor) | R | keep |
| `app/Http/Controllers/Api/SocialPostsController.php:78` | CTA types | Google CTA (vendor) | R | keep |
| `config/services.php:156` | Meta WhatsApp signup config id | External account id | R | env only, drop default |
| `app/Support/Web/BookingLink.php:23` | `DAYS = 14` | Booking link validity | R | Setting |

### Fleet / Memberships / Misc

| Location | Value | Business thing | Freq | Home |
|---|---|---|---|---|
| `app/Support/Fleet/FleetSummary.php:30` | `SOON_DAYS = 30` | Fleet due-soon window | R | Setting |
| `app/Console/Commands/MembershipsRemindRenewals.php:25` | `FOLLOW_UP_DAYS = 14` | Renewal follow-up | R | Setting |
| `app/Models/PhoneServiceRequest.php:50` | `COOLDOWN_DAYS = 7` | Re-ask cooldown | R | Setting |
| `app/Console/Commands/AssistantImportA1.php:49,167` | bot name/welcome, financing URL | One-off A-1 import (writes settings) | R | OK (one-shot) |

### Client (Flutter)

| Location | Value | Business thing | Freq | Home |
|---|---|---|---|---|
| `client/lib/screens/financials/invoice_panel.dart:1090` | cash/check/card/transfer/other | Payment methods (mirrors `InvoicePayment::METHODS`) | R | Serve from API |
| `client/lib/screens/hr/onboarding_form.dart:63` | Hour/Week/Month/Year | Pay periods (mirrors `PayRate::PERIODS`) | R | Serve from API |
| `client/lib/screens/training/learn_shelves.dart:73` | training statuses | Shelf labels | R | keep |
| `client/lib/screens/fleet/fleet_general.dart:342` | registration/inspection/insurance/service | Fleet date kinds | R | Serve from API if tenant-extensible |

The client is largely clean: business values reach it through the API already. Hint texts
(`a-1chimney.com` in `screens/email/mailbox_panels.dart:1955`) are placeholders, not data.

## 3. Common pattern to use

What exists:

- `settings` table + `App\Models\Setting`, resolved through `SettingsResolver` with an
  org > company > franchise cascade.
- `SettingRegistry` (~295 definitions) with typed `SettingDefinition`s, including
  `TYPE_LIST`, `TYPE_JSON`, `TYPE_SUBSET`, `TYPE_ROLES`, `TYPE_FRANCHISE_MAP`.
- API: `GET /api/v1/settings`, `PATCH /api/v1/settings/{key}`, `DELETE /api/v1/settings/{key}`
  (`SettingsController`), already callable by the ezbizi MCP, and a Settings screen that draws
  from the registry.
- Some lists already live there (`jobs.statuses`, `jobs.cancelled_statuses`,
  `leads.new_client_queue`, `leads.reply_templates`), proving the pattern.

Recommendation:

1. **Scalars, short lists, texts, times**: add a `SettingDefinition` per value, with the current
   constant as its `default:`. No new table. The scheduler reads the time setting inside a
   per-minute `->when()` / `->everyMinute()` gate, or a generic `ezbizi:run-scheduled-reports`
   that reads the times. Timezone: use the existing `timezone` setting, never a literal.
2. **Lists with rows that grow (aliases, placeholders, call labels, Workiz source maps)**: one
   generic `business_lists` table — `organization_id, scope_type, scope_id, list_key, item_key,
   value (json), sort, active` — with `GET/POST/PATCH/DELETE /api/v1/lists/{list_key}[/{item}]`,
   permission `settings.lists.manage`, audit-logged, and one Settings > Lists screen that renders
   any registered list from a `ListDefinition` (label, columns, owner module). Rows are editable
   one at a time, so a new staff alias is a single MCP call, not a JSON-blob rewrite.
3. **Things that belong to a record**: put them on that record (RC label on the company,
   "voice elsewhere" on `phone_numbers`, alert recipients as user ids in a `TYPE_ROLES`-style
   setting, Twilio SID on `PhoneConnection`).
4. **Delete duplicates**: the RingCentral queue constants alongside the existing `leads.*_queue`
   settings, and the two `MIN_CALLS = 3`.
5. A guard test: a source-scan that fails on new `const ... = [` holding personal names or
   `@a-1chimney.com` in `app/` (keep it narrow; see memory "a source-scanning guard reads its
   own prose").
