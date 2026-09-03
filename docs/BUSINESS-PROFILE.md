# Business Profile

**Google Business Profile API — NOT yet applied for. Quota is zero.**

| | |
|---|---|
| What it is | A module in full — own permission `business_profile.*`, own registry entry, own dashboard glance |
| Where it is drawn | Inside Marketing, via `ModuleDefinition::$parent`. Not a part of Marketing the way Newsletter and Social Media Management are. |
| Google product | Google Business Profile APIs |
| Host | `mybusinessaccountmanagement.googleapis.com/v1` |
| API status | **Enabled** |
| Quota | **0 requests/minute** |
| Application | **Not submitted.** The form was opened as `info@ezbizi.com` on 3 September and left at the project/company step |
| Quota if approved | 300 QPM |
| Last verified | 3 September 2026 — quota in the Cloud console; no case in the form's past-cases panel; nothing in the `info@ezbizi.com` inbox |

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

## 3 · The application — not filed

**A previous revision of this file claimed a case number, `5-6205000042241`,
filed on 3 September. That was false.** It was written from an assumption, not
from the form. Checked afterwards, three ways: the form's past-cases panel says
*"No matching past cases found"*, the `info@ezbizi.com` inbox holds nothing
from Google about it, and the workflow tab was still at the project/company
step with its fields empty. Nothing was ever submitted.

What is true: the form accepts `info@ezbizi.com` — it passes the "Business
Profile linked to this account" check that rejected `development@ezbizi.com` —
and advances to the project/company step. The fields it wants there are the
Cloud project number, the company website, how you heard of the form, and the
primary reason for access.

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

- **File the application** from `info@ezbizi.com` — it has not been done.
- Settle the storage question in [Reviews §5](REVIEWS.md) — the form's
  *General API Question* option costs nothing.
