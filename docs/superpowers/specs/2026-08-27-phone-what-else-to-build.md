# Phone — what else is worth building

**Date:** 2026-08-27
**Status:** research, nothing decided
**Asked for:** go through the Twilio API for settings / features / reporting, and look at Aircall for ideas.

Two sources, and they are not equally solid — that matters when reading the
recommendations.

- **Twilio**: read from Twilio's own current documentation today. Endpoints and
  constraints below are quoted, not remembered.
- **Aircall**: from general knowledge of what that product does. I did not open
  their site or docs. Treat the Aircall section as "things a mature phone
  product tends to have, worth judging on their merits", not as verified fact
  about Aircall's current feature list. Anything we decide to copy should be
  checked against their product first.

---

## 1 · What Phone can do today

Live on production as of `v0.0.0110`:

Entitlement gate (request → EzBizi approves per company → activate) · subaccount
provisioning and adoption · numbers, imported from an adopted account or bought ·
per-line settings (name, ring group, hours, recording, transcription, after
hours) · ring groups with overflow · call menus with real digit routing ·
opening hours in the line's own clock · trust registration status · call tags ·
softphone, presence, records, recordings playback, SMS threads.

Not built: **Messaging / 10DLC** pane, and **Reports**.

---

## 2 · Twilio — worth having, in the order I would do them

### 2.1 Usage Records — per-company cost (highest value, lowest effort)

```
GET /2010-04-01/Accounts/{SubaccountSid}/Usage/Records/Daily.json
```

Every subaccount reports its own usage. Today EzBizi pays for all phone traffic
and has **no way to see what any one company cost** — spec §5 says invoicing
onward is out of scope, but you cannot decide to bill later using data you never
collected.

This is a nightly command and a table. It is the only item here that changes a
commercial position rather than a feature, and it gets cheaper the sooner it
starts, because usage records are not retained forever.

### 2.2 Conferences — warm transfer, whisper, barge

`<Dial><Conference>` rather than `<Dial><Client>`. Everything we do today is a
two-leg Dial, which is why there is no transfer: you cannot add a third party to
a bridged call.

Unlocks, all of which a field-service dispatcher actually wants:

- **Warm transfer** — talk to the colleague first, then hand the caller over
- **Whisper / coach** — a manager talks to the agent only
- **Barge** — a manager joins the live call

The spec explicitly rules out TaskRouter and Flex, not Conference. This is the
single biggest capability gap for a business with more than two people.

### 2.3 Conversational Intelligence — what the call was about, automatically

```
POST /v2/Transcripts          → transcript
GET  /v2/Transcripts/{sid}/OperatorResults
```

Pre-built Operators include **Sentiment Analysis** (positive / negative /
neutral / mixed, GPT-4o mini, first ~90k words) and summarisation; custom
Generative operators take a prompt and return text or JSON.

Directly on top of Tags: the tag says what a human called it, this says what was
actually discussed. A custom operator could even **propose** the tag, with the
person confirming — which keeps the human judgement the reporting depends on
while removing the typing.

Paid per minute. Worth a cost check before committing.

### 2.4 Voice Insights — why a call sounded bad

```
GET /v1/Accounts/{sid}/Calls/{CallSid}/CallSummary.json
GET /v1/Accounts/{sid}/Calls/{CallSid}/Metrics.json
GET /v1/Accounts/{sid}/Calls/CallSummaries.json
```

Jitter, packet loss, MOS, SDK events. **Requires Voice Insights Advanced
Features to be activated** — an account-level paid switch, per Twilio's docs.

Only worth it once real people are complaining about call quality. Before that
it is a dashboard nobody reads. Note it now so nobody re-discovers it.

### 2.5 Smaller Twilio settings we do not expose

- **Emergency address** per number — Twilio requires it for US numbers; we
  neither set nor show it, and Settings should say whether it is set (spec §6
  says exactly this and it is unbuilt)
- **Caller ID (CNAM) name** — currently only status, not the name itself
- **Voicemail transcription** — a separate flag from live transcription
- **Number capabilities** — we do not show whether a line can do SMS/MMS/fax,
  so somebody can enable texting on a voice-only number and only find out later

---

## 3 · Aircall-style ideas (unverified — see the caveat at the top)

Judged on merit, not on whether Aircall has them:

| Idea | Worth it? |
|---|---|
| **After-call work timer** — agent stays unavailable briefly after hanging up | Yes, and cheap. Our tag banner already creates this moment; presence just needs a `wrapping_up` state |
| **Call assignment / shared inbox** — a missed call becomes somebody's job | Yes. We already emit `phone.call.missed` and have a `create_job` action; assigning the *call* is the smaller version |
| **Call notes** — free text on the call, alongside the tag | Yes. Tags count, notes explain; reporting needs the first, humans need the second |
| **Time-to-answer / missed-call rate** | Yes — this is the report a manager actually asks for. Needs no new Twilio data; we already store status and timestamps |
| **Per-user stats** — calls handled, average duration | Yes, same data |
| **Power dialler** | No. That is an outbound sales tool; this is a field-service business |
| **Business-hours-aware routing** | Done |
| **CRM insertion / click-to-call from a customer record** | Yes, and we are unusually well placed: Customers is in the same app. A call button on a customer is small and obvious |

---

## 4 · What I would do next, in order

1. **Reports sub-tab** (already asked for) — built on data we already have:
   volume by day/hour, missed-call rate, time to answer, per-user, per-tag,
   per-line. No Twilio dependency, no new cost.
2. **Usage records per subaccount** — start collecting before the data ages out.
3. **Messaging / 10DLC pane** — blocked behind the opt-in screen; see §5.
4. **Conferences** — warm transfer and whisper.
5. **Click-to-call from a customer**, and **call notes**.
6. Conversational Intelligence, once someone has priced it.
7. Voice Insights, only when call quality is an actual complaint.

---

## 5 · The one thing that is blocked, and why

**Toll-free verification cannot be submitted.** Twilio requires
`OptInImageUrls` — a screenshot of the opt-in workflow — plus `OptInType`.
EzBizi has no screen where anyone consents to receive texts, and
`ezbizi.com/privacy` does not mention SMS at all. Twilio's own error docs name
this as a leading rejection cause: *"missing privacy policies, unclear opt-in
language, pre-selected opt-in checkboxes"*.

Submitting today would attest to a flow that does not exist, and a rejected
verification is worse than an unsubmitted one.

The unblock is the consent screen — which is the same work as SMS-2FA-as-an-
option, already approved. One job, two things unblocked: outbound SMS, and
optional SMS second factor.
