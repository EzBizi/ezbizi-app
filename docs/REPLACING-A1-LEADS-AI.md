# Replacing A1 Leads' AI

Jose, 2026-10-13: "we'll have to replace everything eventually, so plan ahead,
but for now only do those 3". This page tracks Rodrigo's A1 Leads AI features
(`H:\Development\Leads\a1leads`, pinned at 0b822fb) and where EzBizi stands on
each. All EzBizi AI runs through `App\Support\Ai\GrokCli`.

## In EzBizi

| A1 Leads | EzBizi |
|---|---|
| Name from the call, correction learning (`name-extraction.js`, `learning.js`) | `LeadsRead` + `NameDecision` (Rodrigo §3) |
| Speaker labels (`speaker-label.js`) | `TranscriptSpeakers`, `ezbizi:label-call-transcripts` |
| Dispatcher from Workiz (`workiz-dispatcher-lookup.js`) | `FillWorkizCreators` |
| Not a lead / lost / applicant (`classification/*`) | `LeadsRead` |
| **Booked from the call + booking gate** (`booking-gate.js`, `deferral.js`) | `LeadsRead`, `LeadInsights::gate` (2026-10-13) |
| **LSA lead triage → Google credit** (`lsa-call-sentiment.js`, `lsa-lead-triage` extension) | `LeadsRead` suggests `credit_reason`; a person presses Request credit → `LocalServicesLeads::requestCredit` (Google lead feedback) (2026-10-13) |
| **Reports on request over WhatsApp** (`report-tasks.js`) | `ReportAsk` on the staff line, `reports.view` only, text answers (2026-10-13). `ReportAsk` was deleted 2026-10-27 (`42c4169c8`): staff questions now go through `App\Support\Ai\StaffAnswer` (AI tab, WhatsApp, Telegram); a report file asked for goes through `ReportFileRequest` → `App\Jobs\SendRequestedReport` (`f903e323d`) |
| Call summary / next step | Grok AI session's call summaries |
| Review reply drafts (`review-reply.js`) | Reviews "Draft with AI" |
| Message reply drafts (`message-reply.js`) | Leads "Suggest" (`LeadInsights::suggestReply`) |

## Still to do, in Jose's order when he picks them

1. **Auto-reply to Google LS message leads, second turn** (`pipeline/automations/availability.js`):
   offer one real open Workiz slot and ask for the address. First-turn auto
   replies already exist as Automations rules.
   *Done 2026-10-19 (`f1233f8d0`): lead message field `{{offered_slot}}` and the
   automation template "Offer an open time when a customer replies" (template
   only, nothing switched on).*
2. **Address from the call** (`lsa-triage-assistant.js`, `address-validator.js`):
   AI extracts the service address so Book fills it. Note: USPS terms forbid
   CRM validation (memory `usps-api-terms-forbid-crm-validation`); Rodrigo's
   DuckDuckGo scrape is not an option either.
3. **Review auto-reply** (`review-auto-reply.js`): send positives, WhatsApp the
   negatives for approval. Blocked while the A1 repair forbids public Google
   replies.
   *Live since 2026-11-02: `ezbizi:review-backlog` (daily 15:00, 50 per org)
   and the `review.publish_reply` step publish AI replies to 4–5★ on listings
   with `auto_reply` on; ≤3★ get a draft for a person. Measured 2026-11-13:
   61 listings on, 150 automation replies published in 5 days.*
4. **Report PDF on request**: `ReportAsk` answers in text; send the PeriodReport
   PDF when people ask for it. *Done 2026-10-27 (`f903e323d`,
   `SendRequestedReport`).*
5. **Credit reasons AI does not suggest yet**: GEO_MISMATCH (needs the service
   area), DUPLICATE (same phone within days, can be done in code).

## Stays with Rodrigo

Anything that needs a signed-in browser: Workiz UI reports, LSA account
discovery, Google review removal, Maps review searches (`ez-leads-project`
memory).
