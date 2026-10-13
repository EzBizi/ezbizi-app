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
| **Reports on request over WhatsApp** (`report-tasks.js`) | `ReportAsk` on the staff line, `reports.view` only, text answers (2026-10-13) |
| Call summary / next step | Grok AI session's call summaries |
| Review reply drafts (`review-reply.js`) | Reviews "Draft with AI" |
| Message reply drafts (`message-reply.js`) | Leads "Suggest" (`LeadInsights::suggestReply`) |

## Still to do, in Jose's order when he picks them

1. **Auto-reply to Google LS message leads, second turn** (`pipeline/automations/availability.js`):
   offer one real open Workiz slot and ask for the address. First-turn auto
   replies already exist as Automations rules.
2. **Address from the call** (`lsa-triage-assistant.js`, `address-validator.js`):
   AI extracts the service address so Book fills it. Note: USPS terms forbid
   CRM validation (memory `usps-api-terms-forbid-crm-validation`); Rodrigo's
   DuckDuckGo scrape is not an option either.
3. **Review auto-reply** (`review-auto-reply.js`): send positives, WhatsApp the
   negatives for approval. Blocked while the A1 repair forbids public Google
   replies.
4. **Report PDF on request**: `ReportAsk` answers in text; send the PeriodReport
   PDF when people ask for it.
5. **Credit reasons AI does not suggest yet**: GEO_MISMATCH (needs the service
   area), DUPLICATE (same phone within days, can be done in code).

## Stays with Rodrigo

Anything that needs a signed-in browser: Workiz UI reports, LSA account
discovery, Google review removal, Maps review searches (`ez-leads-project`
memory).
