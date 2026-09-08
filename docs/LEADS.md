# Leads

**Google Ads API — approved and working.**

| | |
|---|---|
| What it is | A module in full — own permission `leads.*`, own registry entry, own triggers, own dashboard glance |
| Where it is drawn | Inside Marketing, via `ModuleDefinition::$parent`. It is **not** a part of Marketing the way Newsletter and Social Media Management are — those have no permission of their own. Somebody with `leads.view` and nothing else in Marketing gets this tab and no Newsletter. |
| Google product | Google Ads API |
| Host | `googleads.googleapis.com` |
| Access | **Basic Access — APPROVED 3 September 2026** |
| Quota | 15,000 operations/day |
| Permissible use | Reporting (read-only) |
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
approval — see those files. Connecting Google Ads does nothing for them.

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

Livechat and Form in a1leads are website sources, not Google ones — a later
piece, not this read.

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

## 7 · Compliance

`www/google-ads-api/` is written for Google's reviewers and must stay true to
this file. The rule enforced by `deploy-www.sh`: **the site may not claim EzBizi
handles billing or invoicing.** It does not.

The application declared **Reporting** permissible use and **no write path**.
That remains true: `LeadsController::update` writes lead status to EzBizi's own
database with an audit row and is never written back to Google.

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
