# Business Profile

> **Superseded 2026-11-13 — access works.** Measured on prod: 44
> `google_business_profile` connections (A-1 Chimney's org, connected
> 2026-10-29 → 11-05), all on EzBizi's own OAuth client (no `interim` A1 Leads
> grant left), all reached 2026-11-13; Google reviews import daily and AI
> replies publish. [EZBIZI-TODO](EZBIZI-TODO.md) row 30 records Basic Access
> granted 2026-10-28 on case `3-3702000041562` (300 QPM). Everything below
> about zero quota, case `6-2027000041783` and A1Leads GBP (module retired
> `2026_10_20_171508`) is history.

**Google Business Profile API — application filed 3 September 2026, case
`6-2027000041783`. Quota stays zero until Google approves it.**

| | |
|---|---|
| What it is | A module in full — own permission `business_profile.*`, own registry entry, own dashboard glance |
| Where it is drawn | Inside Customer Experience (moved out of Marketing 2026-09-25, Jose), via `ModuleDefinition::$parent`. Never inside A1Leads GBP: **it briefly moved there on 2026-09-23 and was put back the same day** — that tab is a read-only window onto Rodrigo's system and is temporary; this module is EzBizi's own and is what a Google reviewer is shown when our API access arrives. |
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

- **RE-FILED 2026-10-26 as `info@ezbizi.com`: case `3-3702000041562`**, read off
  the confirmation screen (*"review time is approximately 7–10 business days"*;
  *"submit only one request"*). Workflow 16726127: confirm account → profile
  "EzBizi, 800 SE 4th Ave #107, Verified" → project number `247590132639`,
  website `https://www.ezbizi.com/` (live, 200), reason text in
  `H:\Development\Leads\GBP-API-APPLICATION-2026-10-26.md` → verified 60 days:
  Yes → allowlisted project: No. **The last Continue submits; there is no
  review page.** File nothing else for this project.
- **Watch the quota, not the inbox:** Business Information API "Requests per
  minute" on `ezbizi-506117` — 0 = not yet, 300 = approved. Watch
  `info@ezbizi.com` for Google's questions on the new case.
- Settle the storage question in [Reviews §5](REVIEWS.md) before approval —
  the form's *General API Question* option costs nothing.

## 9a · Measured 18 September 2026 — window closed, quota still zero

**Not approved.** Read off the Console rather than inferred from silence:

    console.cloud.google.com/apis/api/mybusinessbusinessinformation
      .googleapis.com/quotas?project=ezbizi-506117

    Requests per minute                     0      <- the gate
    Create Location requests per day      100
    SearchGoogleLocation requests per day 200
    Update Location requests per day   10,000

**Zero QPM is the refusal**, and the three non-zero rows are why it has to be
read this way: a page showing 100 / 200 / 10,000 looks like a project with
quota. Google's own note at the top of that same page says it plainly — *"If
you have a quota of 0 after enabling the API, please request GBP API access"*.
The per-day rows exist on every project; only the per-minute row moves on
approval, from **0 to 300**.

**Eleven business days elapsed** (4, 7–11, 14–18 September), against the
confirmation screen's *"7-10 business days"*. So the window §9 said would close
around 17–22 September has closed at the early end with nothing granted and
nothing said.

**Approval may never arrive as an email.** §9 records that Google sent no
acknowledgement for this case either — only the confirmation screen. So the
quota page above is the instrument to re-read, not the inbox, and the inbox
search is the second check rather than the first.

## 9b · GOOGLE ANSWERED, 22 September 2026 — REJECTED on the 60-day rule

Two replies, and between them they settle every open question in §9a and
overturn the conclusion I had drawn there.

**The verdict, from the Google Business Profile API Team, verbatim in
substance:** *"we are unable to proceed with your application for Basic API
Access because the eligibility criteria have not been fully met. Specifically,
the email address used to submit the application must belong to an Owner or
Manager of a business listing that has been verified for at least 60 days."*
Two mandatory requirements are then named: a **verified listing over 60 days
old**, and a **working website on its own unique domain whose URL exactly
matches the URL displayed on the Business Profile**. We are invited to submit
a new allowlist request once the listing has been verified for 60 days.

**And a second reply, from GBP API support (Kona), answering the chase:**
*"your allowlist request is currently queued … we cannot provide a specific
estimated completion time … we kindly ask … that you refrain from submitting
additional allowlist inquiries, as duplicate requests can lead to further
delays."*

### I was wrong, and the file said so in writing

§9a concluded that the missing confirmation email meant *"the application is
not in the queue, whatever the confirmation SCREEN said"*, and the
recommendation that followed was to re-file at once. **Both are wrong.** The
application registered, a human reviewed it, and it was refused on its merits.
Google never sent an acknowledgement and still processed it, so the inference
from the forum thread — real for that developer — did not transfer to us.

The reasoning error is worth naming because it is repeatable: an external
report of *"no email, therefore not queued"* was treated as a general rule
after matching on one symptom. The symptom was shared; the cause was not.

**Had we acted on it, we would have filed a duplicate that Google has now
explicitly asked us not to file.**

### What is actually true now

| | |
|---|---|
| The 60-day rule | **Enforced, confirmed in writing.** §4 recorded it as *unknown* whether the form gates on age. It does, and it is the stated reason for this refusal |
| Earliest re-file | **24 October 2026** — profile verified 25 August, plus 60 days |
| Website criterion | **Already satisfied, checked 22 September.** The profile's Website field reads `https://www.ezbizi.com/` and the application submitted `https://www.ezbizi.com/` — exact match, own domain, working site. Nothing to fix here |
| Duplicate requests | **Do not file any.** Google asked directly. That covers both a re-file before the date and any further chase |

**So the only thing standing between EzBizi and the API is the calendar.**
Nothing to build, nothing to correct, nothing to chase. Re-file on or after
**24 October 2026** through the same form, and expect the same 7–10 business
day window from that point.

### Somebody else had exactly this, and re-filing fixed it — 18 September 2026

Searched because our own evidence was circumstantial. It is no longer.

**The documented behaviour**: Google sends a confirmation email carrying a
case ID to confirm the application is in the queue, and a second email once
the request has been reviewed. Review is quoted as 7–10 business days on the
form and "within 14 days" in the developer FAQ.

**The identical case**, on Google's own developer forum, thread 389462:

> applied **28 July 2026**, received **no confirmation email**, quota still 0
> after **10 business days**; re-submitted **12 August** and *"got a proper
> confirmation with case ID 1-8807000041926, citing another 7-10 business day
> review"*.

Same symptom, same duration, and the fix was to file it again — the second
attempt was accepted immediately where the first had silently not been. That
is the nearest thing to a controlled experiment available: one applicant, two
submissions, one acknowledgement.

**So the reading of our silence is:** a missing confirmation email means the
application is not in the queue, whatever the confirmation SCREEN said. The
screen is generated before the case is filed; the email is generated after.

> **WRONG, and §9b above has the answer Google gave four days later.** The
> application WAS in the queue; it was reviewed and refused on the 60-day
> rule, with no acknowledgement email at any point. This paragraph is left
> standing rather than deleted because the mistake it records is the useful
> part: a matching symptom in somebody else's report was promoted to a general
> rule about the cause. Acting on it would have produced exactly the duplicate
> filing Google has since asked us not to make.

**A separate risk the same thread raises, and it is ours.** Several
developers report that Basic Access does **not** unlock the legacy
`mybusiness.googleapis.com/v4` reviews endpoint, and that *"there is currently
no documented path for an approved developer to reach reviews access at all"*.
Secondary write-ups claim the opposite — that approval enables v4 reviews — so
this is contested rather than settled. It matters here because REVIEWS.md's
fourteen call sites are all on v4 with no v1 replacement: approval of this
application may unlock Business Profile and still leave Reviews shut. Watch
for it as a second gate rather than assuming one approval covers both.

Sources: `discuss.google.dev/t/...389462`,
`developers.google.com/my-business/content/prereqs`,
`developers.google.com/my-business/content/limits`.

### A control for the silence — 18 September 2026

**Google acknowledges every registered case by email, with the case ID in the
subject line.** That is the fact the silence on `6-2027000041783` has to be
read against, and it is now measured rather than assumed.

Jose, as Workspace admin, exported the email log for `rodrigo@a-1chimney.com`
— metadata only, senders and subjects, no bodies — covering **22 March to 18
September 2026, 2,749 rows**, which includes over two thousand messages from
`*.google.com`. Rodrigo runs the A1 Leads system this module was rebuilt from,
so his mailbox is the nearest thing to a worked example we have.

Every Google case in it acknowledged itself by email:

    2026-04-01  no-reply@google.com   [0-6030000040862] Your Google Ads API Basic Access Application
    2026-04-16  no-reply@google.com   [1-0391000040805] Your Google Ads API Standard Access application
    2026-05-15  no-reply@google.com   [0-3236000040380] Your Google Ads API Standard Access application
    2026-06-04  no-reply@google.com   [4-8878000041870] Your Google Ads API Standard Access application
    2026-05-06  googlebusinessprofile-support@google.com  [9-2798000040124] Your Business Profile support inquiry
    2026-05-22  googlebusinessprofile-support@google.com  [7-9089000040856] Your Business Profile support inquiry

Five case types, two products, **six for six**. A Business Profile support
case produces `[case-id] Your Business Profile support inquiry` from
`googlebusinessprofile-support@google.com`. We have never received one for
`6-2027000041783`, in any of the four mailboxes searched. **That is no longer
"Google is quiet"; it is the one outcome this pattern does not produce.**

**And a correction to the premise that sent us looking.** There is no Business
Profile API grant anywhere in those six months. What Rodrigo was granted is
**Google Ads API Basic Access, approved 2 April 2026** — *"You have been
approved for Google Ads API Basic Access!"*. The two Business Profile items
above are generic support inquiries, and his profile had a duplicate-listing
problem that June, which is the likelier subject. His code does call
`mybusiness*`, on Cloud project **429753364855**, but nothing in his mail
shows that project being granted access — and access is per Cloud project, so
it would not reach `ezbizi-506117` either way.

### Chased, 18 September 2026 — case `3-1318000041937`

Jose's word, asked and given. Filed through the SAME form as the application,
`support.google.com/business/contact/api_default`, signed in as
`info@ezbizi.com`, option **General API Question** — §8 records that this
option costs nothing, which is why the chase does not risk the application.
The confirmation screen read: *"Your email has been sent … your case ID is
3-1318000041937"*, and that number is here because it was read off the screen.

**There was no thread to reply to, and that is a finding rather than a
detail.** The plan was to reply to Google on the existing case; searching
`info@ezbizi.com` with `in:anywhere` for `2027000041783`, for *Business
Profile API*, for `api_default` and for *API access* returned **nothing**,
while a control search for `google` in the same mailbox returned 43
conversations — so the instrument works and the absence is real. Google has
never written to us about this case at all.

**And the filing account's own support page does not list it.** The form's
"Use past details" panel, signed in as `info@ezbizi.com`, says *"No matching
past cases found"*. That is soft evidence — the panel may simply not surface
API cases — but it points the same way as the silence, which is why the chase
asks Google to confirm the case was REGISTERED and offers to re-file, rather
than only asking for a decision date.

**What to watch, in this order:** the per-minute quota on the Console (§9a),
then `info@ezbizi.com` for a reply to `3-1318000041937`. If an acknowledgement
arrives for this case and none ever arrived for `6-2027000041783`, that is the
answer about the original.

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
