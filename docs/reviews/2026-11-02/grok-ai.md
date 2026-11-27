# Grok AI — review 2026-11-02

Measured on production (release `20261030222016-27b215107b`, provider `auto` → **grok**), read-only, last 7 days (2026-10-26 → 11-02) unless stated.

## 1. Working

- **Grok engine**: 11,746 calls, **17 failures (0.14%)**; last success 14:53 UTC today; queue 0; no AI job in `failed_jobs` (the only failed jobs are 5 `EmailVerificationLink`, not AI).
- **Call speakers split** (TranscriptSpeakers): 4,209 calls, 3 errors, avg 55 s.
- **Call scoring** (ScoreReportCalls): 2,455 scored, avg **6.1/10**, 43 with no dispatcher on the call (null score, by design).
- **Call labels** (GrokTagger): 2,126 labelled, avg 5.5 s, 3 errors.
- **Lead reads** (LeadInsights): 1,487 calls, 4 errors. **Call-type corrections**: 1,329, 3 errors.
- **Per-person daily/weekly reviews** (AiPersonReviews): 48 written, 0 errors (avg 90 s each).
- **Weekly AI summary**: ran once (57 s), 0 errors. **Call-owner fix** (LeadsCallOwner): 8, 0 errors.
- **AI tab / WhatsApp / Telegram — one brain** (StaffAnswer): AppAsk 29 answers (avg 15 s, max 30 s, 1 error), Library answers 10 (avg 5.5 s). "How many jobs tomorrow at A-1 Chimney" measured 37 = the board (fixed 10-26).
- **Review reply drafts**: 1 drafted this week, 0 errors.
- **AI trace**: every call recorded in `ai_traces` (a month), tied to whose data it carried.
- **Removable add-on**: with no AI configured every screen/command is calm (AiOptionalTest); Claude is a ready second provider.
- **No transcripts on Saturday 10-31 is NOT an outage**: every Saturday is 0 (13th–25th measured), the business is closed; Sunday 11-01 had 112 (10-25: 141).

## 2. Broken

- **Empty Grok answers** — 13 of the 17 failures are `answered without structured output` with empty text (a Grok blip, not a refusal); the item then waits for the next run or is lost for a one-off (AI tab, WhatsApp). **Fixed**: an empty answer is asked once more (timeouts are not) — SHA below.
- **3 timeouts** (2 × 300 s speaker split on very long calls, 1 × 30 s AppAsk plan) and **1 "max turns reached"** (photo read). Long-call split at 300 s is the ceiling; such a call stays unsplit (its transcript is still kept). No fix needed beyond the retry; see suggestion 3.

## 3. Cohesiveness

- WhatsApp, Telegram and the AI tab now answer from the same engine (StaffAnswer) in the same voice (bold title, one fact per line, emojis). The AI tab's source label ("From EzBizi, read with your access" / "From your Library") matches WhatsApp's footer wording.
- Parts answers (Tech Assistant) keep their own template (CONFIDENCE / ORDER CHECK) on purpose; everything else uses the house voice.
- Nothing in this module draws its own screen controls; UI-UX owns the AI tab.

## 4. Suggestions (ranked)

1. **Pin-as-test-case in the app** — `ai_eval_cases` is **0**: pinning exists only as `ezbizi:ai-pin`. A "This answer was wrong → correct it" button on AI answers (UI-UX) would build the test set that `ezbizi:ai-eval` replays before any prompt change. Payoff: prompt changes stop being blind.
2. **Tech Assistant is unused** — `assistant_answers` 0 this week although it is on. Payoff: a one-line WhatsApp tip to technicians ("send a rating-plate photo or the model") would start using it.
3. **Long calls**: split speakers in two halves above ~20 min instead of one 300 s call. Payoff: the 2 long-call timeouts a week get split.
4. **AppAsk speed**: cache the endpoint catalogue + business map per org for a day (the plan prompt rebuilds them each question). Payoff: a few seconds off every app-data answer.
5. **Daily AI digest for the desk** from `ai_traces` (calls, failures by scope, slowest). Payoff: problems seen the same day, no manual query.

## 5. Needs Jose

- **Claude as backup**: switch on automatic fallback to Claude when Grok is down? It needs the privacy page to name Anthropic first and an Anthropic API budget. (Today: Grok only; Claude is ready but off.)
- **Tech Assistant**: keep it on for every role, or only technicians?

## Fix SHAs

- `a1f208759` — GrokCli: an empty structured answer is asked once more.
