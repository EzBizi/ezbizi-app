# Leads

**Google Ads API — approved and working.**

| | |
|---|---|
| What it is | A module in full — own permission `leads.*`, own registry entry, own triggers, own dashboard glance |
| Where it is drawn | **A row of its own in the sidebar**, since 2026-09-03 — it left Marketing when Thumbtack became a second source. This line said "inside Marketing" until 2026-09-23 and was wrong for three weeks; the registry comment recorded the move and this table did not. |
| Google product | Google Ads API |
| Host | `googleads.googleapis.com` |
| Access | **Basic Access — APPROVED 3 September 2026.** Standard Access **applied twice**: case 3-8972000040872 on 2026-09-08, **DENIED 2026-09-09** (reserved for integrations regularly exceeding 15,000/day with RESOURCE_EXHAUSTED errors — which the box then did, for four hours on 2026-09-16); reapplied, **case 9-3038000041842 OPEN**. *Superseded: **Standard Access approved 2026-09-26** (`routes/console.php`, the `ezbizi:sync-leads` comment) — no daily ceiling; sync runs every minute. The quota history below is history.* Our RMF correction of 14 September stood as the last word for eight days; **followed up 2026-09-22 on Jose's word** with the usage evidence their first refusal asked for — 11 Sept: 14,889 requests in a day on Google's counter at ~60 accounts, 99.3% of the ceiling, no refusals; 16 Sept: 2,033 quota refusals (HTTP 429 / RESOURCE_EXHAUSTED) 09:25–12:35, unbroken, ~690/hour at ~10.2 calls a minute across 51 accounts. **Two qualifications were stated in the message rather than left for a reviewer to find in the metrics**: it is one day of exceedance and not yet a pattern (the cadence was reverted the same day), and the connected account count is temporarily far below 51 because 49 were disconnected on 16 Sept while filming the OAuth demo video — so today's metrics understate the load. A reviewer who finds a gap we did not declare reads it as a misrepresentation; one we declare is a fact about our roadmap. The thread lives in `development@ezbizi.com`, not Jose's inbox — it is why this looked unapplied-for once |
| OAuth scope verification | **`.../auth/adwords` APPROVED 18 September 2026** by Google's Third Party Data Safety team, on project `ezbizi-506117`. Filed 2026-09-16 with the second demo video. Read off the Console the same day: OAuth Overview says *"Your app has been verified by Google"*, Audience says **In production / External**, and the 100-user cap no longer applies to this scope (it is still displayed, 4/100, which is what makes it look live — it binds only a NEW unapproved sensitive scope). Two conditions ride with it: a change to the OAuth consent screen configuration requires a NEW request, and the verification is not inheritable by a new sensitive scope. Separate from the Standard Access track below — this one is about the consent screen, that one about the developer token's quota |
| API contact email | google-ads-api@ezbizi.com, an alias of development@ezbizi.com, set in the API Center 2026-09-09 at Google's request for a role-based address |
| Quota | 15,000 operations/day under Basic Access; **none daily since Standard Access (2026-09-26)**, per-second limits only |
| Permissible use | Reporting; one write, a person's reply to a text enquiry (see §7) |
| Manager account | 706-109-4046 |
| Cloud project | `ezbizi-506117` (247590132639) |
| Last verified | 3 September 2026, live call against production |

---

## 1 · What this tab is

Enquiries from Google Local Services Ads — **people who contacted the business
through advertising and are not customers yet.** The tab exists to close the gap
between an advertising enquiry and the work it becomes.

Leads is **before** the job. [Reviews](REVIEWS.md) is after it.
[Business Profile](BUSINESS-PROFILE.md) is the listing that makes both possible.

Leads is the only one of the three on the **Google Ads** connection. The other
two share a **Business Profile** connection and are blocked on a different
approval — see those files (*no longer blocked: Business Profile works since
2026-10-29, see BUSINESS-PROFILE.md*). Connecting Google Ads does nothing for them.

## 2 · State: working

Approved same day as application, against a stated five-day review. Verified by
calling the API from production rather than by reading the approval mail:

```
token: obtained
HTTP 200
customers: customers/7061094046
```

An empty Leads tab is now the honest answer — the connected account has no
Local Services enquiries — rather than a symptom.

## 3 · How it reads

```
POST googleads.googleapis.com/v24/customers/{customerId}/googleAds:search
     developer-token: <token>
     login-customer-id: {customerId}
     GAQL: SELECT ... FROM local_services_lead WHERE ...
```

Three things that are easy to get wrong, each having actually gone wrong:

- **The version is configuration, not a constant.** `GOOGLE_ADS_API_VERSION`,
  currently `v24`. Google retires versions and a retired one answers **404 to
  everything**. Production sat on `v21` after retirement; every call 404'd and
  the Cloud console showed *zero traffic*, because a dead version path never
  reaches the service. It read as an access problem for weeks and was a URL.
  **Probe without credentials — 401 means alive, 404 means gone:**

  ```bash
  for v in v22 v23 v24 v25; do printf "%-4s " $v; curl -s -o /dev/null \
    -w "%{http_code}\n" "https://googleads.googleapis.com/$v/customers:listAccessibleCustomers"; done
  ```

- **There is no `localServices:searchDetailedLeadReports` REST method.** It is
  not on `googleads.googleapis.com`. The read is `googleAds:search` with GAQL
  over `local_services_lead`.

- **`googleAds:search` refuses a `pageSize`** — `PAGE_SIZE_NOT_SUPPORTED`. With
  one the call is 400, without it 200. `pageToken` still works; the response
  sizes its own pages.

## 4 · What was said: conversations

A lead row is a header — who, which number, when, what category. The substance
is a second resource, **`local_services_lead_conversation`**: one row per call
or message, which side spoke, when, the call's duration and recording URL, the
message's text and attachments. This platform never read it; Rodrigo's a1leads
did from the start, which is why its list showed *LS Text* / *LS Ads* with the
last message under each name and ours showed "unnamed enquiry" with nothing.

Since 2026-09-08 every sync reads it after the leads:

```
SELECT local_services_lead.id, local_services_lead_conversation.id,
       .lead, .conversation_channel, .participant_type, .event_date_time,
       .phone_call_details.call_duration_millis, .phone_call_details.call_recording_url,
       .message_details.text, .message_details.attachment_urls
FROM local_services_lead_conversation
WHERE local_services_lead_conversation.event_date_time >= '<newest held − 1 day>'
```

Measured live against `4941850746` ("A1 Chimney - Cleveland"): eleven rows for
three days, with durations and recording URLs. The lead SELECT also grew
`service_id` and `locale`. Not `note`: GAQL answers **400
`PROHIBITED_FIELD_IN_SELECT_CLAUSE`** for it (measured 2026-09-08), and one
refused field fails the whole request. `service_id` is an identifier, not a
label — `fireplace_chimney` — and `category_id` is `xcat:…`; neither is a
sentence for a screen.

- **Table `lead_conversations`**, unique on `(organization_id, remote_id)`,
  cascades with its lead. The lead carries four `last_contact_*` columns as a
  cache of its newest row so the list shows a preview line without a query per
  row; `last_contact_from = CONSUMER` is what "unreplied" means.
- **Only accounts that have produced a lead are asked.** Measured 2026-09-08
  across all twenty-two A-1 Chimney accounts, lifetime, no date filter:
  **thirteen hold leads, 23,875 between them** (the largest 5,043), nine hold
  none. A tick is therefore 22 lead reads + 13 conversation reads = 35 calls;
  **10,080 operations a day against the 15,000 quota**, the rest for manual
  Fetch and connects. The same probe found one account answering 403 (an
  account the grant does not reach; it fails alone, the others sync) and only
  one `service` value on the whole tenant, `fireplace_chimney`.
- **The 30-day first-run window holds ~400 of those 23,875.** Whether the
  history behind it should be pulled is Jose's call; it is one flag away
  (`FIRST_RUN_DAYS`) and a one-off cost of a few pages per account, not a
  standing one.
- **The window is on the conversation's time, not the lead's.** A message this
  week on a lead from two months ago is inside it; that lead is then fetched by
  id (`WHERE local_services_lead.id IN (…)`) so a live enquiry is never dropped
  for being old.
- **Recordings never leave the server as URLs.** Google's URL answers only to
  the account's bearer token. `GET leads/{lead}/conversations/{id}/recording`
  fetches it with the connection's credentials, streams it once uncached, and
  writes an audit row (`lead.recording_played`).
- **Trigger `lead.contact_received`** fires for a consumer's call or message,
  never for the business's own reply.
- **Triage marks (2026-09-08, item 3 of the a1tools list).** `starred_at` and
  `note` are columns on the lead, shared. Read is per PERSON: `lead_reads`
  (lead_id, user_id, read_at), no model; unread = no row, or a message newer
  than the row. `GET leads` joins the reader's mark and carries `is_unread`,
  `starred`, `note`; filters `unread=1`, `starred=1`, `unreplied=1`.
  `POST leads/{id}/read`, `POST leads/read-all`, `PATCH leads/{id}` with
  `starred` / `note`. The screen draws none of it until UI/UX has.
- **Google's timestamps are in the account's time zone, with no offset.**
  `creation_date_time` and `event_date_time` are bare strings meaning local
  time in `customer.time_zone` (America/New_York for A-1 Chimney). Read as
  UTC, every lead and message was four hours old - Jose saw "4h ago" beside
  a1leads showing the same person at "45m ago" on 2026-09-08, and "Nothing
  new" read as a fetch that had stopped. Both SELECTs now carry
  `customer.time_zone` and `LocalServicesLeads::whenGoogleSaid` parses with
  it. `ezbizi:sync-leads --days=N` re-reads a window and refreshes the rows
  already held, which is how the stored times were corrected after deploy.

Livechat and Form in a1leads are website sources, not Google ones — a later
piece, not this read.

## 4a · One list across every source (decided 2026-09-08, reversible)

Jose asked for "a new sub tab in Leads" for website chat and forms. UI/UX drew
both shapes (docs/previews/leads-sources-layouts.html, 80646605) and, under
his instruction to decide in his absence, recommended **one list** with the
source as a badge on the row and a chip beside Unread / Unreplied / Starred;
built at d1b2e77b. The reason is measured, not taste: the two tabs that
existed were never a source split (the Thumbtack tab drew a connect flow and
no leads), and the triage marks answer "who is waiting on me" only when there
is one list to ask. The row, badges and band are identical in the tab layout,
so **if Jose says "tabs", only the strip changes.**

- `GET leads?source=local_services|thumbtack|website_chat|website_form`.
- The Google accounts band is now **Connected sources**, one row at any number
  of providers, opening a panel with a section per provider (Google's
  accounts list, Thumbtack's list or connect door, a line for the website
  until Web Management sends its widget). Placement counts moved into the
  panel with it.

## 4b · What a model can say: classification and a draft reply

> **Superseded on production:** `BEDROCK_MODEL` is not set on the box
> (2026-11-13); `LeadInsights::enabled()` is true through Grok (`LEADS_AI_GROK`
> + `GrokCli`, Jose 2026-10-09), and the sorting/name/ZIP read runs as
> `ezbizi:leads-read` every two minutes. The Bedrock path below still exists in
> code but is unused. First-turn auto-replies now exist as Automations rules
> (see REPLACING-A1-LEADS-AI.md), so "Not built: auto-reply" is history.

Item 5 of the a1tools list, Jose's "as you recommend" (2026-09-08). Amazon
Bedrock, Claude, through the AWS SDK the app already carries for SES.
`LeadInsights` (app/Services/Leads) is **off until** `AWS_ACCESS_KEY_ID`,
`AWS_SECRET_ACCESS_KEY` and `BEDROCK_MODEL` are set on the box; `enabled()` is
false otherwise and nothing on screen depends on it. The model id is the one
the Bedrock console shows for Claude in `us-east-1`; it is not guessed here.

- **Classification** BOOK / SUPPORT / ARCHIVE plus a one-line `summary`, once,
  the first time the enquirer has said something in words, from
  `LeadThread::refresh()` so every source (Google, website chat) is sorted the
  same way. Stored in `classification` / `classified_at`; never re-run;
  `status` untouched. Raises `lead.classified` (declared on day one, raised
  now). On screen: "Looks like work to book · <summary>" under the header.
- **Suggested reply** `POST leads/{id}/suggest-reply` returns text for the
  composer; a person reads, edits and presses Send. It stores nothing. Offered
  only where a reply can go (`can_suggest`).
- **Not built, on purpose:** auto-reply (a1leads sends without a person; the
  Lead model forbids it until Jose lifts that by name) and call transcription
  (needs an S3 bucket and Amazon Transcribe, both his to provision).
- Cost at A-1 Chimney's volume, measured: single digits a month.

## 4c · "Unnamed enquiry" is two different absences

Measured against production on 2026-09-10, after Jose asked what they were.

**Bookings (451).** `lead_type = BOOKING` is somebody pressing Book on the ad.
Google answers `"contactDetails": []` — an empty list, not a missing key — and
there is nowhere else to look: `googleAdsFields:search` lists SIXTEEN selectable
fields on `local_services_lead` and not one describes a booking, `note.description`
is empty on them, and a catalogue search for any field named `*booking*` returns
hotel and video-campaign settings only. The customer is in Google's Local
Services inbox and not on the wire. The row now says "Booking through Google",
badges "Booking" rather than falling through the channel switch to "Text", and
the thread draws a line saying where the details are instead of an empty message
bubble attributed to the customer.

**Google's retention (about 14,000).** Contact details age out of the API:

| Year | Leads held | With no name and no number |
|---|---|---|
| 2024 | 6,040 | 6,040 (100%) |
| 2025 | 22,629 | 8,007 (35%) |
| 2026 | 12,085 | 392 (3%) |

Nothing is wrong with those rows and nothing can be recovered — they arrived in
the ten-year re-read, and Google no longer holds what it once sent. Worth knowing
before somebody reports the backfill as broken.

**Two fields we do NOT select and could.** `local_services_lead.note.description`
(the business's own note on the lead, written in Google's UI — a bare
`local_services_lead.note` is refused, the sub-field is not) and
`lead_feedback_submitted`. Neither helps a booking; both are there if wanted.

## 5 · `login-customer-id` is per connection

Never an installation-wide config value. The manager an account sits under is a
fact about *that connection*, not about the deployment. A global value set to
reach one customer through EzBizi's MCC would be sent on every other tenant's
request, and Google answers `PERMISSION_DENIED` for any account that manager
does not manage — one customer fixed, everyone else broken, silently.

`listAccessibleCustomers` returns accounts the signed-in user reaches
**directly**, and each is stored as its own connection, so the customer's own id
is the right login customer for every row held.

## 6 · Known rough edge

**Connecting stores a row for every account the user can reach.** On
3 September, one connect action by `info@a-1chimney.com` created **22
connections in the same second**, all scoped to the single A-1 Chimney company.
Every *Fetch new* therefore makes 22 API calls against the Basic Access quota.

Not a correctness bug — the sync tolerates it, and a single failing account no
longer turns an honest empty result into a 502 — but it is not what anybody
intended. Fixing it means letting the person pick which account to connect.

Softened twice on 2026-09-08: each row is now stored under Google's own
descriptive name (`leads:name-accounts` backfills the ones connected before),
and the conversation read skips any account that has never produced a lead.

**And inverted on 2026-09-09.** The twenty-two were not too many; they were
too few. `listAccessibleCustomers` names the accounts the signed-in user
reaches DIRECTLY. Google's own client tree under the Ads Manager
(`customer_client` with `login-customer-id` = 4051729356) lists **thirty-six
enabled accounts**; the other twenty-three (Stamford, Hamden, Providence,
Seattle, St Louis, Austin, Baltimore, Boston, Charlotte, Florida, ...) answer
PERMISSION_DENIED to any read that does not carry the manager. They were never
connected, so their leads never arrived. `social_connections.login_customer_id`
is the manager a row is read through (null = direct); `identify()` walks every
manager's enabled non-manager clients at connect time; every Google read sends
the manager when set; `leads:adopt-manager-clients` does it for grants that
predate the column. 146-421-7121 is in no manager's tree and 403s to everybody;
it is a dead row.

**Crossing the ceiling on purpose, 2026-09-11 — and stepping back, 2026-09-16.**
One full day at sixty accounts read **14,889** requests on Google's own counter
— 99.3% of the 15,000 ceiling, with no refusal. The fleet hovered just UNDER the
line, which is the one outcome that earns nothing: Standard Access was refused
on 2026-09-09 for not exceeding it. Quiet accounts moved from a half hour to a
quarter (+~1,150/day, about 107% of the ceiling) — the smallest lever that
crosses.

It crossed, and the evidence now exists in Google's own logs: 2026-09-16
produced **2,033 refusals between 09:25 and 12:35**, unbroken, ~690 an hour
against a call rate of ~10.2 a minute at a fleet of **51** accounts. So the
cadence went back to the half hour the same day. Jose: *"Reverse this so we have some leway, we already went over
the threshhold so we are good with stepping back on it now."* The ~1,150/day it
returns is the headroom the Ads report and a person looking at it come out of —
at 107% every operation the day had was already promised to the sync, which is
how an afternoon was lost to filming a demo video for Google.

**THE FLEET ON THIS PAGE NO LONGER EXISTS, 2026-09-16.** Every figure above is
about a fleet of roughly fifty to sixty connected Google Ads accounts. That
afternoon Jose disconnected **49** of them between 13:11 and 14:02 while testing
the OAuth consent flow for Google's demo video, leaving **3** rows (two real and
one demo placeholder). So the quota arithmetic here is history rather than a
description of today, and it will be history again the moment those accounts are
reconnected.

Two numbers measured either side of that hour looked like a contradiction and
were not: 51 before the first disconnect, 3 after the last, and 51 - 49 = 2 plus
the demo row. Both were true; what was missing was what happened in between.
Before retracting a measurement that disagrees with a later one, ask what
changed between them - see `a-stale-measurement-looks-like-a-wrong-one`.

Read the daily figure from Google rather than modelling it. Arithmetic from the
tiering rules said 16,704/day on 2026-09-10 while the counter said 12,361: runs
that overrun their five-minute slot are skipped and some accounts fail before
making a call, and only the counter knows that.

```bash
gcloud auth print-access-token   # then the monitoring API:
# metric.type="serviceruntime.googleapis.com/api/request_count"
# resource.label."service"="googleads.googleapis.com"
```

**The quota does not reset — it ROLLS, measured 2026-09-11.** Google does not
document this and it changes what exceeding means. Refusals began at 12:20 UTC
when no calendar day could have held 15,000 (from midnight UTC we had spent
~8,000; from midnight Pacific ~3,300), while the ROLLING 24-hour total crossed
15,000 that morning and read 16,103 at the first refusal. The same clock hour
carried 530 successes and 576 refusals, which a fixed daily reset cannot
produce.

So sitting over the line is **partial service, not a blackout**: capacity frees
up continuously as old requests age out, throughput is capped near 15,000/day,
and ticks fail intermittently rather than everything stopping until some hour.
That is what makes running over sustainable — and it is why the ORDER of the
fleet matters (see the tick's longest-unread-first ordering, same day).

**A correction on that ordering's evidence.** It was first justified with "0 of
59 reached in the last tick, 16 in the last half hour, and the same sixteen".
The counts were measured; **"the same sixteen" was not** — identities were never
compared, and a wider window contradicts it: 56 of 59 accounts had been reached
within the hour, the three exceptions being the demo row and two the grant
cannot see. What was really happening at 13:24 was a total stall affecting all
59 equally. Longest-unread-first still belongs there, as a fix for a hazard the
ordering creates by construction — nothing makes a loser eventually a winner —
rather than for observed starvation.

**What going over costs, and what it does not.** When the day's quota is spent
every request fails until it resets, at an hour Google does not document. Nothing
is LOST in that window: the lead read asks for everything since the newest lead
already held, not since a cursor, so the first successful run afterwards collects
what arrived meanwhile. The cost is delay. That, plus the two-pass order below,
is why exceeding deliberately is an acceptable thing to do.

**Quota tiering, same day.** Forty-five accounts every five minutes exceeds
Basic Access (Standard was refused for not yet exceeding it). The tick reads a
producing account (a lead in 30 days) every 5 min and its conversations every
10; a quiet account every 30 min, conversations never; a connection under 30
minutes old at once. About 11,800 operations a day at 25 producing + 20 quiet.

**Adopted on the box 2026-09-09 13:50 UTC**, once 0.0.0173 (e04ee987) was live:
`leads:adopt-manager-clients` connected 23 (Bucks County via 3747134697, the
other 22 via 4051729356); 46 connected Google Ads rows, 23 read through a
manager. The full re-read (`ezbizi:sync-leads --days=3650`) followed, 13:48
to 14:20 UTC: leads 23,918 -> 40,469 (16,550 of the new ones on 19 of the 23
adopted accounts), conversations 65,002 after the read; both numbers Jose
had found missing against a1tools (646-547-6829, 610-406-7510) present. One
connection failed: the seeded demo row DEMO-555-0100-01, which has no grant.
146-421-7121 is marked broken by the same run.

**2026-09-10, the first real Thumbtack enquiry, and two faults it exposed.**
(1) **No reply channel was tagged for `thumbtack`**, so the composer was not
drawn — and in its place the thread said "Google only accepts replies to text
enquiries. This one came in as a call", about a Thumbtack message thread. The
sentence was hard-coded in the client AND in the controller, both as Google's.
`ThumbtackLeads` now implements `LeadReplyChannel` (a negotiation IS a message
thread, so there is no call/message split to respect) and the reason for any
refusal comes from the channel that refuses, through the optional
`ExplainsRefusal`. A test enquiry says there is nobody to reply to rather than
relaying a 404. (2) **The list ignored the scope switcher.** `index()` narrowed
by REACH, which stops at the organization, so an owner saw every company's
enquiries in every view: A-1 Chimney's leads under A1 Garage Doors (Jose,
2026-09-10). It now narrows by `ViewScope` as well, using
`SocialConnectionAccess::inView`'s rule one level down — a lead filed at
organization level (both columns null) shows in every view, which is why
Thumbtack's twelve organization-scoped businesses still appear inside a
company. `ConnectionsRespectTheViewTest` claimed in prose that the rows were
filtered and only ever asserted the connections band.

**Thumbtack, same day, two faults found from the box.** (1) A specimen from
`ezbizi:thumbtack-test-lead` now authenticates and reaches the lead code, then
`GET /v4/negotiations/{id}` 404s: Thumbtack's own text says specimen ids
"will not refer to any real entities". `d25c935d` files the lead from the
event body and fetches only when the body is thin, which also removes a
lost-lead path for real enquiries. (2) Jose's connect at 12:16 made TWELVE
rows (one per business) all carrying one token pair; Thumbtack rotates the
refresh token per use, one row rotated it at 13:32, the other eleven replayed
the spent one and the family was revoked by 13:37. `12870b19` writes the
rotated pair to every row holding the spent one, under a lock. Nothing repairs
the dead tokens: **Jose reconnects Thumbtack once, after 12870b19 is live**,
never before. Webhook delivery does not need the token, so new enquiries still
store meanwhile; threads and replies do not.

**A specimen says so on the row, from 2026-09-10.** `ezbizi:thumbtack-test-lead`
fires Thumbtack's own webhook, which is what makes it a proof of the pipe — and
leaves a row with a genuine negotiation id that reads exactly like a customer.
The daily catalog read `thumbtack = 1` and was one comparison away from
announcing the first Thumbtack customer on the marketing site; the deploy desk
settled it by hand against a control phone number. So `leads.is_test` exists
(same name and meaning as `automation_runs.is_test`), the command marks the row
it caused, the dashboard box leaves test rows out of every count, and the list
draws a **Test** badge. The question is now SQL anybody can run:

```sql
SELECT source, COUNT(*) FROM leads WHERE is_test = 0 GROUP BY source;
```

The marking is deliberately one-sided. It marks only a row that arrived in the
command's own window AND carries a phone nobody could ring — Thumbtack's
specimen is "statically populated" and a real payload carries E.164 — because
missing a mark leaves a visible test row, while marking a real enquiry would
drop a person waiting for a call back out of the count that exists to catch
exactly that.

## 7 · Compliance

`www/google-ads-api/` is written for Google's reviewers and must stay true to
this file. *Since 2026-11-02 www.ezbizi.com is WordPress on the websites box:
editing `www/` publishes nothing — change the WordPress page `/google-ads-api/`
via wp-cli and fetch the live URL (memory `ezbizi-site-is-wordpress`).* The rule enforced by `deploy-www.sh`: **the site may not claim EzBizi
handles billing or invoicing.** It does not.

The application declared **Reporting** permissible use and **no write path**.
Since 2026-09-08 there is exactly one write, on Jose's decision: **a reply to a
text enquiry**, sent through `localServices:appendLeadConversation` into the
Google chat the person wrote in, from the customer's own account. It goes only
because a person pressed Send - nothing composes or sends automatically - and
every send is an audit row (`lead.replied`). It is never retried: an ambiguous
answer after Google has accepted the message must not become the same sentence
arriving twice at a stranger's phone. Google accepts it for MESSAGE leads only;
a call lead is refused before anything is sent. The public page says the same.

`LeadsController::update` still writes lead status to EzBizi's own database and
is never written back to Google.

## 8 · Outstanding

Everything below the line is shipped. **`0.0.0150` (`3f7f222b`) is live**, and
carries `bbfeb42d`, `7945fb0`, `2531b601` and `517ef1bf`. Verified 2026-09-04
three ways rather than from the release note: all four are ancestors of the cut;
the deployed source at `/var/www/ezbizi-current` has `googleAds:search` and the
only remaining mention of the retired method is the comment explaining it; and
the live manifest reads `{"version":"0.0.0150","built":"3f7f222b"}`.

So production no longer calls the dead URL, connections respect the company
view, and organisation-level leads are visible below organisation level.

Still open:

- Connect stores a row for every account the user can reach (§5). It should let
  the person choose.
- **Re-measure before repeating any of this.** An earlier revision of this
  section said production was still calling the dead URL; it was true when
  written and false by the time it was read, because a release had landed in
  between. A deployment fact has a shelf life.

## The quota run cost three connections, 10-14 September 2026

Proving to Google that Basic Access was too small had a price nobody was
watching for. `GoogleToken` marked a connection `broken` on any failed response
from the OAuth token endpoint, and during the deliberate exhaustion run the
token endpoint rate-limited us. The log shows HTTP 429 immediately before each:

    A1 Chimney - Baltimore   7636036698   silent from 2026-09-10 19:34
    A1 Chimney - Chicago     7251056558   silent from 2026-09-11 15:09
    AngiSweep - MD           1994904698   silent from 2026-09-10 20:39

Silent is the operative word. `broken` is not a label, it is a removal: the
sweep polls `connected` rows, so none of the three was ever asked again.

**None of them was actually broken.** All four distinct refresh tokens across
the 60 google_ads rows renew, and three of the four accounts read normally. The
readable part of that probe is the control - rows sharing the *same* refresh
token that the sweep had reached minutes earlier. A first attempt refused every
token including those, which was the probe using the wrong OAuth client, not
Google refusing.

Fixed in a963615f: only `invalid_grant` marks a row. Rows restored by hand, and
because `since()` derives its watermark from the newest stored lead rather than
a cursor, the gap back-filled itself - **20 leads recovered**, 13 Baltimore and
7 Chicago, both now current. A stored cursor would have lost them.

**146-421-7121 (1464217121) is deliberately left broken.** Google answers HTTP
403 `The customer account can't be accessed because it is not yet enabled or has
been deactivated.` It has never produced a lead. That row is honest.

**Still open:** a `google_ads` row in the EzBizi Demo organization
(`01a0582a-f58a-70bf-90d3-36f07cd4cdc4`) has no refresh token and is `connected`,
so the scheduler retries it every five minutes forever and writes a WARNING each
time. Seeded demo data, harmless, and it is most of the noise in the log.
