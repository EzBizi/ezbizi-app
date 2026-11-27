# Replacing the Rodrigo bot's WhatsApp reports

Jose, 2026-10-06: "Yes, replace Rodrigo" — **everything** it sends, delivered into **new EzBizi WhatsApp groups**.

Rodrigo's system: `H:\Development\Leads\a1leads` (Node, builds reports) + `H:\Development\Leads\a1bot` (Python "Hermes", sends them). Mapped 2026-10-06; file:line references below are into those repos.

## What it sends

| # | Report | Built by (a1leads) | When (America/New_York) | Where |
|---|---|---|---|---|
| A | **Same-Day Report**, one per company book (CT, DMV, GA, IL, NATIONWIDE, NJ, OH, TX, WA) | `report-engine/daily-text.js:176-200` `buildDailyText`, data `lib/digest.js` | refresh 20:15/20:40, send from 21:00 | "A1 {XX} - Daily Performance" group |
| B | **Same-Day Company Summary** (all companies) | `daily-text.js:202-235`, `lib/daily-finance-summary.js` | after all 9 A's are sent | "A1 Financial Reports 📊" |
| C | **Weekly / monthly PDFs** per company + consolidated | `pipeline/weekly-report.js` → `scripts/build-*.js` → `render-daily.js` | weekly Wed `0 8,10,12,14,16 * * 3`; monthly `0 9,12,15,18 2-7 * *` | company groups + Financial; **only after Merav (bookkeeper) replies "Approved"** in a DM |
| D | Daily Account Review (LSA/GBP status) | `lib/packages/account-review.js:130` | 06:30 | "Local Services & GBP Updates" |
| E | LSA inventory changes / account health | `lsa-universe-sync`, `scheduler.js:752` | 06:15, 07:45 | same |
| F | GBP health audit | server 07:30 + Mac cron | 07:30 | same |
| G | Bad-review alerts (≤2★ or removed) | after review sync | 04:00, 16:00 | same |
| H | Real-time: new lead, auto reply, new message, spam filtered | webhook events | live | "A1 Messages 💬", "A1 Chimney - Dispatch" |
| I | "Ask me about any location, technician, source or job" | Claude Sonnet + ~23 tools over the a1leads API | on demand | any chat |
| J | User-made schedules ("send me X every Tuesday") | `a1bot/a1_scheduler.py` | hourly replay | any chat |

**A's format**: header `*{Company} · Same-Day Report*`, date, `_Current through 9:00 PM EDT_`; 1. jobs per ad source + total; 2. jobs per service area + total; 3. Google reviews per mapped metro (new · total · ★) + subaccount line; 4. field performance per tech (completed · canceled · $ sales · $ deposits) + total; footer "Ask me…". A section whose data failed prints `⚠️ *Unavailable:* …`. Max 4096 chars.

## Data — what EzBizi can and cannot read

- Jobs per ad source / per area, tech completed / canceled / sales, deposits: Rodrigo **scrapes the Workiz web app** in a logged-in Chrome (`lib/workiz-area-scraper.js`, `scripts/scrape-area-sources.js`, Payments report). EzBizi is official-APIs-only, so these depend on what **Workiz v2** exposes — Workiz session to answer. *Update: since 2026-10-20 (`f6f224d33`) EzBizi also reads Workiz's own web reports (Payments, Sales, job companies, lists) through each tenant's signed-in Workiz web session — the `ezbizi:workiz-web-*` schedules in `routes/console.php`.*
- Google reviews: GBP API — EzBizi has it (Google session).
- LSA: EzBizi has Local Services (Google session).
- Weekly P&L: Airtable tables + RingCentral — EzBizi has Airtable connections and RingCentral call reports (Reports session).
- "Ask me": Jose declined paid AI (2026-09) — ours answers fixed questions from the report's own figures (the Library assistant), not an LLM. *Superseded 2026-10-26 (`d11eb3f11`): staff questions on WhatsApp, Telegram and the AI tab go through `App\Support\Ai\StaffAnswer` — Tech Assistant, then the Library, then `AppAsk` (AI over the app's data as the asker) — via `GrokCli`.*

## Delivery — Groups API

- Official WhatsApp Cloud API cannot post into the existing groups (Rodrigo uses WhatsApp Web / Baileys). Jose chose new EzBizi groups.
- Groups API: **Official Business Account required**, **max 8 participants**, join by **invite link only**, text/media/templates allowed (no interactive). OBA can be requested from ~**2026-10-15** (30-day clock, memory `whatsapp-oba-30-day-clock`), and needs an approved display name ("EzBizi - A1 Report" pending since 2026-10-01).
- Until OBA is approved: each report is sent **1:1 to the group's members** (a utility template, then the report inside the 24 h window).
- **Update 2026-11-10 (memory `whatsapp-oba-30-day-clock`):** OBA is not a clock any more — Meta (case 29480413778312084) said it is only available through a Meta account rep, and Jose: "I don't believe we do" have one. **Groups are shelved.** `App\Support\Social\WhatsAppGroups` sends each member ONE message: the report's PDF with its text as the caption (Jose, 2026-11-10).
- **Built:** A and B = `ezbizi:whatsapp-same-day-reports` (21:05 and 21:35 ET); C = `ezbizi:whatsapp-period-reports week|month` (Wed 08:00/12:00, the 2nd 09:00/13:00 ET), sent to the approver first (`WhatsAppPeriodReports`).

## Who builds what

- **Meta / WhatsApp session** (see SESSIONS.md): group create / invite links / send (and the 1:1 fallback), a report-delivery target (group ↔ people), scheduling hook, Merav's approval DM, OBA request on 2026-10-15.
- **Reports session**: report content A, B, C (text + PDF) from EzBizi data.
- **Workiz session**: whether Workiz v2 gives ad source, service area, tech, sales, deposits per day.
- **Google API / Leads session**: D, E, F, G from Local Services and Business Profile; H from Leads.
