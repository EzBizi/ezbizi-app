# Business Profile

**Google Business Profile API — never applied for. Quota is zero. Can be applied
for now.**

| | |
|---|---|
| What it is | A module in full — own permission `business_profile.*`, own registry entry, own dashboard glance |
| Where it is drawn | Inside Marketing, via `ModuleDefinition::$parent`. Not a part of Marketing the way Newsletter and Social Media Management are. |
| Google product | Google Business Profile APIs |
| Host | `mybusinessaccountmanagement.googleapis.com/v1` |
| API status | **Enabled** |
| Quota | **0 requests/minute** |
| Application | **Never submitted** |
| Quota if approved | 300 QPM |
| Last verified | 3 September 2026, Cloud console + Gmail + the form itself |

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

## 3 · It was never applied for

Established three independent ways on 3 September, because a note is not
evidence:

1. Quota is 0 and no increase is pending.
2. `development@ezbizi.com`'s mailbox holds no GBP correspondence at all.
3. The application form itself reported **"No matching past cases found."**

## 4 · The 60-day rule does not block us

Earlier belief was that no application was possible until **24 October 2026**,
60 days after EzBizi's own profile was verified on 25 August. **That was wrong.**
Google's prerequisites say the qualifying profile:

> *"can be the applicant's own office or headquarters **or it could belong to one
> of the clients they manage**."*

A-1 Chimney's profile is years old and satisfies the age requirement. The
applicant stays **EZBIZI LLC** — pointing at a client's profile does not make the
client the applicant.

## 5 · The actual blocker

The application must be filed by an account with a Business Profile linked to it.

| Account | Result |
|---|---|
| `development@ezbizi.com` | **Rejected** — *"There's no Business Profile linked"* |
| `jose@a-1chimney.com` | Passes the check, but **wrong applicant** — this would file in A-1 Chimney's name |

The GBP **Organization account `EZBIZI LLC` already exists** — that is the
one-attempt-per-company gate, and it is through. It currently manages **zero
businesses**, which is the gap. Google's own overview page offers the remedy:
*"Want to transfer a business?"*

**So: get a qualifying profile managed under the EzBizi organization, then apply
as EzBizi.**

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

- Get a qualifying profile under the **EZBIZI LLC** organization.
- File the application **as EzBizi**, entity type *software platform*.
- Settle the storage question in [Reviews §5](REVIEWS.md) first — approval
  followed by suspension is worse than a delay. The same form's *General API
  Question* option costs nothing and does not consume the Basic Access attempt.
