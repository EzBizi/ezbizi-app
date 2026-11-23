# Google Ads API filing amendment: draft for Jose (2026-11-18)

Nothing here has been submitted or sent.

**Corrected 2026-11-23 by Leads:** the first version said EzBizi was on Basic access and addressed case 3-8972000040872. Per docs/LEADS.md and docs/EZBIZI-TODO.md #31, that case was **denied 2026-09-09**, and **Standard Access was granted 2026-09-26** on case **9-3038000041842**. So RMF applies today, and the reply goes on 9-3038000041842. Sections 5–7 below are corrected.

Each section is ready to paste, in
Jose's name, signed in as `development@ezbizi.com` (passkey). One sitting:
sections 2 → 3 → 4 → 5, in that order.

## 1. What changed, and why it must be filed

What Google holds today says EzBizi's only Google Ads write is a Local Services
reply:

- **Standard Access application, case 3-8972000040872**, filed 2026-09-08 (denied 09-09; the re-application on case 9-3038000041842 was granted 09-26, and what it says about writes was not re-read for this draft)
  (`H:/Development/Leads/GOOGLE-ADS-API-STANDARD-ACCESS-ANSWERS.md`).
  - Q7: "One write: a staff-typed reply via appendLeadConversation … No
    campaign, bid, budget, account or billing operations."
  - Q11: campaign types supported, "None".
  - Q12: capabilities, Reporting plus replies.
- The API Center **Intended use** and the 5-page **design document**
  (`EzBizi-GoogleAdsAPI-DesignDocument.pdf`) say the same.

Since 2026-11-09 (dc99f2c33, Marketing › Ads › Google), the app also writes, read
from the code on 2026-11-18:

| Write | Google API call | Who / when | Guards |
|---|---|---|---|
| Pause / enable a campaign | `campaigns:mutate` (status ENABLED/PAUSED only; never REMOVED) | A person with `ads_manager.manage`, after a confirm step. Also an on/off schedule that person sets, applied each minute by `ezbizi:ad-schedules`. The performance screen can pause/enable up to 50 chosen campaigns at once. | Audited (`google_ads.campaign_status_changed`, `ads_performance.campaign_status_changed`) |
| Campaign daily budget | `campaignBudgets:mutate` (amount_micros) | A person with `ads_manager.manage`, before → after review, then Google's value read back | Shared budgets refused; $1–$100,000; audited with from/to (`google_ads.budget_changed`) |
| Bid target | `campaigns:mutate` (target_cpa / target_roas, also within maximize_conversions / maximize_conversion_value) | Same as budget | Portfolio (shared) strategies and manual bidding refused; CPA up to $10,000, ROAS up to 10,000%; audited with from/to (`google_ads.target_changed`) |

Unchanged, and still true:

- every write is at **campaign level**; nothing is created, removed, or touched
  below campaign (no ad groups, ads, keywords);
- display stays Account + Campaign level (case 9-3038000041842);
- no cross-customer aggregation (privacy policy);
- each customer connects its own account by OAuth.

The public page https://www.ezbizi.com/google-ads-api/ was corrected on
2026-11-18 by the Website session (backup on the websites box,
`~/ezb-wp-bak-20261118`).

## 2. API Center: "Intended use" (paste over the current text)

> EzBizi is a field-service CRM sold by subscription to service businesses and
> franchises. Each customer connects its own Google Ads account (OAuth 2.0,
> per-tenant tokens). Reads: Local Services leads and their calls and messages,
> and account- and campaign-level performance (cost, clicks, impressions,
> conversions) for the customer's own reports. Writes, all at campaign level and
> all made by a person in that customer's business with the "Manage Ads"
> permission, confirmed on screen and audited with the before and after values:
> (1) a reply to a Local Services text enquiry (appendLeadConversation);
> (2) pause or enable a campaign, now or on an on/off schedule that person sets;
> (3) change a campaign's own daily budget (shared budgets are refused);
> (4) change a campaign's own target CPA or target ROAS (portfolio and manual
> bidding are refused). EzBizi does not create or remove campaigns, ad groups,
> ads or keywords, does not change billing or account settings, and does not
> show data below campaign level.

## 3. Standard Access answers (Q7, Q11, Q12) as amended

**Q7: business model / tool / audience** (≤ 1,000 characters; 922 below)

> EZBIZI LLC sells EzBizi, a field-service CRM, as a monthly subscription to service businesses and multi-location franchises. Revenue is subscriptions only: no ad resale, no share of spend. Each customer connects its own Google Ads account (OAuth 2.0, per-tenant tokens). Reads: Local Services leads, their calls and messages, and account- and campaign-level performance. Writes, campaign level only, each by a person with the Manage Ads permission, confirmed and audited before/after: a reply to a text enquiry (appendLeadConversation); pause/enable a campaign, now or on a schedule they set; a campaign's own daily budget (shared budgets refused); its target CPA or target ROAS (portfolio and manual bidding refused). No creation or removal of campaigns, ad groups, ads or keywords; no billing or account changes; nothing shown below campaign level. Audience: owners, managers and dispatchers, each in their own business.

**Q11: campaign types supported**

> None created. Existing campaigns of any type can be paused or enabled; Search, Performance Max and Local Services campaigns that have their own budget can have it changed; campaigns on Target CPA, Target ROAS, Maximize Conversions or Maximize Conversion Value can have their own target changed.

**Q12: capabilities**

> Reporting (account and campaign level). Campaign Management, limited to: pause/enable campaign (M.110, without remove), edit campaign budget, edit Target CPA / Target ROAS / Maximize Conversions target (M.96, M.97, M.98). Other: replying to a Local Services text enquiry (appendLeadConversation), sent only by a person pressing Send, audited, never automatic.

## 4. Design document: pages to add (section "Campaign-level changes")

**4.1 Scope.** EzBizi changes three things on a campaign that already exists:
its status (ENABLED ⇄ PAUSED), its own daily budget, and its own bid target
(target CPA or target ROAS). It never creates or removes campaigns, and never
touches ad groups, ads, keywords, assets, billing or account settings.

**4.2 Who can change it.**
- Only signed-in users of the customer's own EzBizi business whose role holds
  "Manage Ads" (`ads_manager.manage`).
- "Ads" (`ads_manager.view`) can only read.
- Each request names the connection and the campaign id. The connection must
  be one of that business's own connected accounts, inside the user's current
  company/branch view.

**4.3 The flow (screenshots to add: Marketing › Ads › Google table, budget panel, confirm panel, read-back message).**
1. The table lists the account's campaigns live from Google (GAQL on `campaign`,
   date range chosen by the user).
2. Status: the user flips the switch → a confirm panel says what will happen
   ("Google stops showing this campaign until it is turned on") → Confirm sends
   `campaigns:mutate` (`update_mask: status`).
3. Budget or target: the user opens the panel, types the new value, presses Save →
   a confirm panel shows **before → after** (e.g. "$45.00 / day → $65.00 / day") →
   Confirm sends `campaignBudgets:mutate` or `campaigns:mutate`. The table is read
   again from Google and the user is told whether Google shows the new value
   ("Google confirms …") or not yet.
4. Every change Google accepts is written to EzBizi's audit log with the user,
   account, campaign, and the from/to values.
5. Schedules: a person with "Manage Ads" can set a campaign to switch on and off at
   chosen times; a job applies it each minute through the same status call, and
   each switch is audited.

**4.4 What is refused, and said on screen.**
- A budget shared with other campaigns ("change it in Google Ads").
- A campaign on a portfolio (shared) bid strategy, or on bidding with no target
  (manual CPC and the like).
- Values outside: budget $1–$100,000 / day; target CPA up to $10,000; target
  ROAS up to 10,000%.
- Google's own refusals are shown to the user as Google gives them; nothing is
  retried.

**4.5 Volume.** Writes are rare and made by people: a few per account per day.
Schedules add at most two status calls per scheduled campaign per day. Reads are
unchanged from the September document.

## 5. Reply on case 9-3038000041842 (Standard Access, granted 2026-09-26)

> Hello,
>
> An update to what EzBizi does with its Standard Access (case 9-3038000041842), so that what you hold matches it.
>
> Since November 9, 2026, EzBizi also lets a person in a customer's own business, with the "Manage Ads" permission, make three campaign-level changes on campaigns that already exist in that customer's own Google Ads account:
> 1. pause or enable a campaign, now or on an on/off schedule they set;
> 2. change the campaign's own daily budget (shared budgets are refused);
> 3. change its own target CPA or target ROAS (portfolio and manual bidding are refused).
>
> Each change is confirmed on screen with the before and after value, read back from Google, and audited. EzBizi still does not create or remove campaigns, ad groups, ads or keywords, does not change billing or account settings, and shows data at account and campaign level only.
>
> We have updated the Intended use in the API Center and attached the revised design document, which adds a "Campaign-level changes" section.
>
> We believe this is limited, specialized functionality rather than a full-service campaign management tool: it cannot create or structure campaigns, ad groups or ads. We would ask the Compliance team to confirm whether the creation and management Required Minimum Functionality applies to it.
>
> Thank you,
> Jose Luis Demasi, EZBIZI LLC

## 6. Required Minimum Functionality: what applies and where we stand

Source: Google's RMF page, https://developers.google.com/google-ads/api/docs/api-policy/rmf,
read 2026-11-18. Its label is "v. 2022-10-06", and nothing on it says RMF was withdrawn.

- **RMF applies only to Standard access, which EzBizi holds since 2026-09-26.**
  So it applies today.
- **Which list depends on the tool type.**
  - A *Full-Service Tool* ("fully manage their Google Ads accounts") owes the
    Creation, Management and Reporting lists.
  - A *Reporting Only* tool owes Reporting only.
  - A tool with "very limited and specialized functionality" that "could not be
    used for creating and managing campaigns, ad groups, and ads" may be
    exempted from Creation and Management. Google's Compliance team decides.
  - The page has no exemption written for status/budget/bid-only tools; the
    limited-functionality exemption is the closest. That is why section 5 asks
    Google to rule.

If Google classes EzBizi as **limited functionality** (what we ask for):

| RMF item | Applies? | EzBizi today |
|---|---|---|
| R.10 Customer | yes (reporting) | Met: account-level figures |
| R.20 Campaign (status shown when paused/active/removed are listed) | yes | Met: campaign table with status; removed campaigns are not listed |
| R.40 Ad Group Ad, R.50 Keyword View, R.70 Search Term View, R.130 Bidding Strategy | Reporting list, "Required" | **Not met, and must not be.** Showing them would break our written statement on case 9-3038000041842 (account and campaign level only). If Google insists, it is a decision for Jose, with two weeks' notice to Google first. |

If Google classes it as **full-service**, it would additionally owe:

- C.10–C.300: create campaign, geo and language targeting, conversion
  snippet, callouts, bidding options, set budget, create ad group, keywords,
  negatives, match type;
- M.10, M.130, M.140: edit campaign settings, pause/remove ads and keywords;
- the remove half of M.110.

**None of those are met.** EzBizi meets only M.96, M.97 and M.98 (the edit-target
items for Target CPA, Target ROAS and Maximize Conversions), plus budget editing
and pause/enable (M.110 without remove). Full-service compliance would be a large
build and would contradict the campaign-level statement, so the realistic options
are:
- (a) the limited-functionality ruling asked for in section 5;
- (b) dropping the campaign writes;
- (c) asking Google to move the project back to Basic access.

## 7. Jose's sitting, in order (≈ 15 minutes)

1. Cloud Console → Google Ads API (project ezbizi-506117) → paste section 2 into
   Intended use.
2. Regenerate the design document with section 4 added (Leads session can
   produce the PDF on request), attach it.
3. Reply on case 9-3038000041842 with section 5 (from development@ezbizi.com),
   attaching the PDF.
4. If the application form must be re-submitted rather than replied to, use
   section 3 for Q7/Q11/Q12; every other answer stays as filed.

Afterwards Leads updates `H:/Development/Leads/GOOGLE-ADS-API-STANDARD-ACCESS-ANSWERS.md`
and the memory note, recording what was sent and when, from the screen.
