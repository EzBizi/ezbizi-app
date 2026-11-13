# Reviews

> **Superseded 2026-11-13 — not blocked.** Measured on prod: reviews import
> daily through EzBizi's own Business Profile connections (v4 `--reviews` sync,
> every 6 h at :40) and `ezbizi:review-backlog` publishes AI replies (150 in the
> 5 days to 11-13). State and history of the access: [BUSINESS-PROFILE.md](BUSINESS-PROFILE.md).
> The zero-quota rows and §2 below are history; §4, §5 and §7's policy items
> were not re-checked.

**Blocked by the same zero quota as [Business Profile](BUSINESS-PROFILE.md), and
carries policy obligations the other two do not.**

| | |
|---|---|
| What it is | A module in full — own permission `reviews.*`, own registry entry, own triggers, own dashboard glance |
| Where it is drawn | Inside Customer Experience (moved out of Marketing 2026-09-25, Jose), via `ModuleDefinition::$parent`. Never inside A1Leads GBP: **it briefly moved there on 2026-09-23 and was put back the same day** — that tab is a read-only window onto Rodrigo's system and is temporary; this module is EzBizi's own and is what a Google reviewer is shown when our API access arrives. |
| Google product | Google Business Profile APIs |
| Host | **`mybusiness.googleapis.com/v4`** — legacy, no v1 replacement |
| API status | Enabled |
| Quota | **0 requests/minute** |
| Application | **Filed 3 September 2026**, case `6-2027000041783` — the same one as Business Profile |
| Last verified | 3 September 2026 |

---

## 1 · What this tab is

**What customers said after the job**, on Google, and the replies to them.

[Leads](LEADS.md) is before the job. Reviews is after it.
[Business Profile](BUSINESS-PROFILE.md) is the listing that makes both possible.

Reviews and Business Profile **share one connection**. They stay separate tabs
because they answer different questions — *what are people saying* versus *is our
listing correct and visible*.

## 2 · Why it fails

Identical cause to Business Profile: the Google Business Profile APIs ship at
**zero quota** and stay there until a separate access application is approved.
Enabling the API is not the same gate as being granted quota.

**One application unblocks both tabs.** See
[BUSINESS-PROFILE.md](BUSINESS-PROFILE.md) for the full state, the blocker, and
where to apply — none of it is repeated here.

Nothing about the Google Ads approval touches this. Different product.

## 3 · It rides on a deprecated host

Reviews, replies, posts and media are served **only** by the legacy v4 host,
`mybusiness.googleapis.com/v4`. There is no v1 replacement and v4 has a
long-announced deprecation.

Two consequences worth holding on to:

- v4 is **not a separately enabled API** in the Cloud console. Access rides on
  the `business.manage` scope, so its absence from the enabled list is correct
  rather than a missing step. *Wrong now: `gcloud services list --enabled
  --project ezbizi-506117` lists `mybusiness.googleapis.com` (measured
  2026-11-13); EZBIZI-TODO row 30 records enabling it was refused on 10-29
  until Google released it. It is a separately enabled API.*
- v4 wants an **account-qualified path** — `accounts/X/locations/Y` — where v1
  does not. Code that works against v1 will not simply be repointed.

When v4 goes, this module needs a plan. `BusinessProfileOAuth::LEGACY_V4` is the
one place to start looking.

## 4 · Policy obligations that land only here

Replying to a review is acting **on a customer's behalf, in public, under their
name**. Google's [API policies](https://developers.google.com/my-business/content/policies)
attach duties to that which do not apply to reading leads:

| Obligation | State |
|---|---|
| Obtain the client's **explicit authorization** before replying to reviews or answering Q&A | Not implemented — connecting is treated as blanket consent |
| **Seven business days** to let a departing client disassociate, and *"relinquish and remove those permissions"* | Met — Disconnect deletes the stored grant |
| Notify the merchant **within 48 hours** of account changes made on their behalf | Not implemented |

The first and third are gaps. They are not urgent while quota is zero, but they
are wrong to leave until after an application says we comply.

## 5 · Unresolved: how long we may keep review content

**This is the one to settle before applying.** Google's policy:

> *"You cannot pre-fetch, cache, index, or store any content provided through the
> Business Profile APIs ("Content") **for use outside of your Business Profile
> project** except for limited amounts of Content. You can store limited amounts
> of Content only to improve the performance of your project.*
>
> *Stored Content must meet the following requirements:*
> * *It must be stored temporarily for no more than 30 calendar days.*
> * *It must be stored securely.*
> * *It cannot be manipulated or aggregated in any way."*

EzBizi's `Review::retention()` returns **`RetentionPolicy::forever(...)`** —
*"Held while the profile is connected."* We store `comment`, `reviewer_name`,
`reviewer_photo_url` and `location_name` indefinitely.

**The clause is genuinely ambiguous** and the two readings differ:

- *Lenient* — the 30 days binds only Content stored "for use outside your
  project". Showing a merchant their own reviews inside the product they
  authorised is inside the project, and is permitted.
- *Strict* — the bullets govern all Stored Content, so `forever` is a violation.

What is **not** ambiguous is the consequence: *"Business Profile APIs policies
are strictly enforced, and failure to comply may lead Google to disable your
Business Profile APIs project."*

Two further clauses point at the rule's intent — Content *"cannot be manipulated
or aggregated in any way"*, and cannot be stored *"in order to prevent Google
from tracking usage of your project."* The target is derivative datasets and
quota evasion, not a merchant reading their own reviews. That favours the lenient
reading, but it is inference, not a ruling.

**Settle it in writing before applying.** The same support form has a *General
API Question* option, which costs nothing and does not consume the one Basic
Access attempt. Getting approved and then disabled is worse than a delay.

## 6 · What already works

The connect flow is finished and correct — the only thing that fails is quota:

- OAuth grants `business.manage`; the scope is not classified sensitive, so no
  verification or demo video is required, unlike `adwords`.
- The failure message names **quota** and distinguishes it from a per-minute
  **rate limit**, so a 429 does not read as "quota is zero forever" and send
  somebody back through Continue, which is what keeps a rate limit on.
- Connections respect the company view (`7945fb0`) — a sibling company's profile
  no longer appears here.
- `ReviewsController` writes the reply, the audit row and the outbox event in one
  transaction, so a failed reply cannot leave a row saying it succeeded.

## 7 · Outstanding

- **Settle §5** with Google before case `6-2027000041783` is approved.
- Implement explicit per-client authorization before replying (§4).
- Implement 48-hour change notification (§4).
- Plan for v4's deprecation (§3).
- ~~`7945fb0` undeployed~~ — shipped in `0.0.0150` (`3f7f222b`), verified against production 2026-09-04.
