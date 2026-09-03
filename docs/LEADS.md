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

## 4 · `login-customer-id` is per connection

Never an installation-wide config value. The manager an account sits under is a
fact about *that connection*, not about the deployment. A global value set to
reach one customer through EzBizi's MCC would be sent on every other tenant's
request, and Google answers `PERMISSION_DENIED` for any account that manager
does not manage — one customer fixed, everyone else broken, silently.

`listAccessibleCustomers` returns accounts the signed-in user reaches
**directly**, and each is stored as its own connection, so the customer's own id
is the right login customer for every row held.

## 5 · Known rough edge

**Connecting stores a row for every account the user can reach.** On
3 September, one connect action by `info@a-1chimney.com` created **22
connections in the same second**, all scoped to the single A-1 Chimney company.
Every *Fetch new* therefore makes 22 API calls against the Basic Access quota.

Not a correctness bug — the sync tolerates it, and a single failing account no
longer turns an honest empty result into a 502 — but it is not what anybody
intended. Fixing it means letting the person pick which account to connect.

## 6 · Compliance

`www/google-ads-api/` is written for Google's reviewers and must stay true to
this file. The rule enforced by `deploy-www.sh`: **the site may not claim EzBizi
handles billing or invoicing.** It does not.

The application declared **Reporting** permissible use and **no write path**.
That remains true: `LeadsController::update` writes lead status to EzBizi's own
database with an audit row and is never written back to Google.

## 7 · Outstanding

- `bbfeb42d` — the `googleAds:search` fix — **is committed but not deployed.**
  Production still calls the dead URL.
- `7945fb0` — connections now respect the company view — also undeployed.
- Connect should let the user choose the account rather than storing all 22.
