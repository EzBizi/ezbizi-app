# Business Profile

**Google Business Profile API — application filed 3 September 2026, case
`6-2027000041783`. Quota stays zero until Google approves it.**

| | |
|---|---|
| What it is | A module in full — own permission `business_profile.*`, own registry entry, own dashboard glance |
| Where it is drawn | Inside Marketing, via `ModuleDefinition::$parent`. Not a part of Marketing the way Newsletter and Social Media Management are. |
| Google product | Google Business Profile APIs |
| Host | `mybusinessaccountmanagement.googleapis.com/v1` |
| API status | **Enabled** |
| Quota | **0 requests/minute** |
| Application | **Filed 3 September 2026** — case `6-2027000041783`, from `info@ezbizi.com`; Google's stated review time 7–10 business days |
| Quota if approved | 300 QPM |
| Last verified | 3 September 2026 — quota in the Cloud console; the case ID read off the form's confirmation screen |

---

## 1 · What this tab is

The **listing the public sees on Google Maps** — name, address, hours,
verification state, and whether Google is showing it at all.

Different product from [Leads](LEADS.md), which is Google Ads. **Ads approval
does nothing for this tab.** They share only the word "Google" and the same
Cloud project: different host, different developer token, different quota
system, different application, different review team.

Shares its connection with [Reviews](REVIEWS.md). One Business Profile
connection feeds both tabs; they stay separate because they answer different
questions — *is our listing correct and visible* versus *what are people saying*.

## 2 · Why it fails

Not a bug. Google grants the Business Profile APIs **zero quota** until a
separate access application is approved. Enabling the API in the console is a
different gate from being given quota, and a project with every API enabled
still fails every call with 429.

Verified in the Cloud console on 3 September:

> **Requests per minute — Quota: `0`**

and Google's own note on that page:

> *"If you have a quota of 0 after enabling the API, please request for GBP API
> access."*

**OAuth already works.** The `business.manage` scope is not classified sensitive,
so the consent screen grants it and the connection appears to succeed. Scope and
quota are separate gates; we pass the first and fail the second. That is why the
tab's error names quota rather than permissions.

## 3 · The application

Filed **3 September 2026** from `info@ezbizi.com`. The confirmation screen
read, verbatim: *"Opened a support case… Your case ID is: 6-2027000041783. Due
to a high volume of allowlist requests, our current review time is
approximately 7–10 business days."*

| Field | Submitted |
|---|---|
| Cloud project number | `247590132639` |
| Company website | `https://www.ezbizi.com/` |
| Primary reason | Reviews and Business Profile tabs; each customer authorises its own profile by OAuth (`business.manage`); EzBizi never takes ownership of a customer's listing; calls named: `accounts.list`, `locations.list`, `reviews.list`, `reviews.updateReply` |

Nothing from any client is in it. The applicant is EzBizi throughout.

**A note on this section's history.** An earlier revision of this file carried
a different case number that did not exist — written from an assumption before
the form had been finished, and committed as fact. It was retracted in
`4a848cf6`. The number above is the one the form displayed, and the only reason
it is here is that it was read off the screen.

## 4 · The 60-day rule

Google's prerequisites, verbatim: *"Manage a Google Business Profile that is
verified and active for 60+ days."* EzBizi's profile was verified
**25 August 2026**. Independent reports say new listings are rejected on age.

Whether the form itself gates on age is **unknown** — a previous revision of
this file said it had "accepted a nine-day-old profile", and that was not
observed either. Google's page also says a rejected application may be re-filed
once the requirements are met, so filing early costs a rejection, not the
attempt.

Google allows the qualifying profile to belong to a client. **We chose not to use
that.** Jose's ruling: the application stays entirely inside EzBizi, with no
dependence on a client's Google assets.

## 5 · Which account files it

Not the organization admin, and not a client.

Google's prereqs: *"Make sure that you are using an email address that is listed
as an **owner/manager on your business's GBP**."*

| Account | Role | Files the application? |
|---|---|---|
| `info@ezbizi.com` | Owns EzBizi's Business Profile | **Yes** — passes the form's account check |
| `development@ezbizi.com` | Administers the `EZBIZI LLC` organization; owns the Cloud project | **No** — the form rejected it, *"There's no Business Profile linked"* |

**Do not add `development@ezbizi.com` as an owner of the profile to get round
this.** Google's organization rule is not only about creation:

> *"Before a user can be added to an organization, their account **must not
> directly own or manage any locations** or location groups."*

That account administers the organization. Giving it a location puts it on the
wrong side of that rule.

**The Cloud project does not have to belong to the filing account.** The
**Cloud Project ID is a field on the form** — typed in, not inferred from who is
signed in. So `ezbizi-506117` living under `development@ezbizi.com` is fine while
`info@ezbizi.com` files.

Related, and it explains the whole shape: *"An organization account cannot
directly own an individual profile... Organization accounts can only manage a
location after the location grants permission to the organization."* The
organization is never meant to hold profiles — locations grant it permission,
the same way customers grant EzBizi access by OAuth.

## 6 · Multi-tenant is a supported, first-class shape

Other companies connecting their own profiles is exactly what this is for.

- Access is granted **per Google Cloud project, once** — not per customer.
  Google's FAQ: *"GBP API access is granted on a Google Cloud project level."*
- The form's **Entity type** field offers *agency managing multiple clients*,
  *software developer building a platform*, or *business managing own locations*.
  EzBizi is the middle one.
- Customers grant access by OAuth over the profiles **they** manage. Nothing goes
  under EzBizi's ownership.
- Google **forbids** the alternative: *"You cannot require them to apply for their
  own Business Profile project."*

Some guides advise pitching internal use rather than the multi-tenant reality to
get approved faster. **Do not.** It is misrepresentation of a product we do not
have, and it risks *"immediate rejection or permanent suspension from the
developer program."*

## 7 · Where to apply

`https://support.google.com/business/workflow/16726127` — reached from
`support.google.com/business/contact/api_default` → *Application For Basic API
Access*. Signed in as an account with a linked profile.

## 8 · Outstanding

- **Watch `info@ezbizi.com`** for Google's questions on case `6-2027000041783`;
  one left unanswered stalls the application.
- If rejected on profile age, re-file after **24 October 2026**.
- Settle the storage question in [Reviews §5](REVIEWS.md) before approval —
  the form's *General API Question* option costs nothing.

## 9 · Measured 14 September 2026 — filed, inside the window, nothing back

Filed **3 September** (517ef1bf). Google's confirmation screen quoted *"7-10
business days"*. Counting from 4 September and treating Monday 7 September as
the Labor Day holiday, **14 September is business day 6** — the window closes
somewhere around **17–22 September**. Nothing is overdue yet.

**Google has sent nothing.** Searched both mailboxes over the Workspace
delegation with `in:anywhere`, which reaches Spam and Trash — an absence that
skipped those would not be an absence:

    info@ezbizi.com          "2027000041783"                 0 messages
                             Business Profile API / api_default / Basic API Access
                                                             0 messages
    development@ezbizi.com   same two searches               0 messages

The instrument works: the same search shape finds the **Ads** case in
development@ (three messages, `9-3038000041842`). So zero here is Google's
silence, not a broken query. Note there was no acknowledgement email for this
case at all — only the confirmation screen the case number was read off.

**State of the plumbing, same date.** Enabled on `ezbizi-506117`: account
management, business information, performance, verifications. `mybusiness
.googleapis.com/v4` is *not* enabled and should not be — it rides on the
`business.manage` grant, as `BusinessProfileReviews` already documents.
Service Usage reports no per-minute limit for these APIs, and **that number
cannot be read as 0** — the same endpoint returns an empty bucket for Google
Ads' *Basic Access Granted*, which we know is granted. Quota state is only
readable from the Console.

There is **no real Business Profile connection anywhere**. The single
`google_business_profile` row is the seeder's `DEMO-accounts/demo` in the EzBizi
Demo organization, `credentials` NULL, `last_reached_at` never; the 5 reviews
and 2 replies all carry its id and one identical timestamp. So nothing in the
product is waiting on a token — it is waiting on the application.

**One thing not measured:** whether the OAuth consent screen is verified for the
sensitive `business.manage` scope. The Console page behind it demands Jose's
passkey, so only he can read it.
