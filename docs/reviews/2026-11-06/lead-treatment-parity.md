# Lead treatment parity by source (2026-11-06)

Jose, 11-06: "recently we started getting a lot of leads from facebook. but im not sure if the existing customer thing is also applied to them", then "All the leads should, like this things need to be basic", then "Can you please check every type of lead to see if they all get the same treatments".

Read-only audit by UI/UX, from code on main. Fixes belong to Leads.

## Sources

From `Lead::SOURCES` (app/Models/Lead.php:286):

- local_services: LS texts, LS calls and LS bookings
- thumbtack
- website_chat, website_form, website_booking
- facebook, instagram
- whatsapp
- workiz
- ringcentral
- ez_calls, ez_texts

Meta lead-ad forms are `facebook`/`instagram` rows written by MetaLeadForms. A form becomes the Messenger chat's lead when the phone matches (MetaMessages.php:241).

## Matrix

✓ means it works. ✗ means a gap. — means it does not apply. "dep" means it depends on whether the source gives a phone number.

| Treatment | LS | Thumbtack | Chat | Form | Booking | FB/IG DM | FB/IG form | WhatsApp | RC / EZ call | EZ text | Workiz |
|---|---|---|---|---|---|---|---|---|---|---|---|
| Phone on the lead (phone_digits) | ✓ | ✓ | dep | dep | ✓ | **✗ never** | ✓ | ✓ | ✓ | ✓ | ✓ |
| Existing-customer banner and list "Customer" pill | ✓ | ✓ | dep | dep | ✓ | **✗** | ✓ | ✓ | ✓ | ✓ | ✓ |
| Match by email | **✗ all** | | | | | | | | | | |
| Phone/email typed in the thread used for the match | **✗ all** | | | | | | | | | | |
| Name/address/ZIP read from the thread (LeadDetails) | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | — (calls excluded) | ✓ | — |
| "Check availability for ZIP" | when a ZIP is known, all sources | | | | | | | | | | |
| Reply from EzBizi | ✓ | ✓ | ✓ | ✓ (SMS) | **✗** | ✓ | ✓ | ✓ | ✓ (SMS) | ✓ | — inherent |
| Reply templates, AI suggest and proofread | follow "Reply from EzBizi", so **✗ on Booking** | | | | | | | | | | |
| `lead.received` (automations "new lead", notifications) | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | **✗** (only `lead.missed_call` on a miss) | **✗** | — inherent |
| `lead.contact_received` | through LeadThread, all inbox sources | | | | | | | | | | |
| Unreplied / Overdue / Follow up / Star / presence | source-agnostic; calls owe no reply by design (Lead.php:668) | | | | | | | | | | |
| Book → job | all inbox sources | | | | | | | | | | |

## Gaps, ranked

1. **The existing-customer match is phone-only.**
   - `LeadsController::customerMatches` (LeadsController.php:733) and the list's `customersByNumber` (LeadsController.php:443) read only `phone_digits`.
   - Facebook and Instagram DMs are created with no phone (MetaMessages.php:281, "No phone or email: Meta has none to give"), so they can never match.
   - A chat or form without a phone has the same problem.
   - **Fixable:**
     - match on email, case-insensitive;
     - extend LeadDetails (LeadDetails.php:36, which returns name, address and ZIP only) to read a phone and email from the customer's messages;
     - write what it finds back to the lead's empty `phone`, `phone_digits` and `email`, so the list pill, the banner, the Customers link and spam rules all see it.
   - No name-only match: it would attach leads to the wrong customer of the same name.
2. **EZ texts, RingCentral calls and EZ calls never emit `lead.received`.**
   - EzLeadCalls.php:163 and RingCentralLeadCalls.php:223/279 emit `lead.missed_call` only.
   - So a "new lead" automation or notification never fires for an answered call or an incoming EZ text.
   - **Fixable:** emit `lead.received` when the lead is created.
3. **Website booking has no reply channel.**
   - It is missing from the binding in AppServiceProvider.php:134, which covers ringcentral and website_form only, and from the tag list at :141.
   - So it gets no reply box, no templates and no AI suggestion, although it always has a phone.
   - **Fixable:** add `website_booking` to the SmsLeadReplies loop and the tag.
4. **Workiz** gets no reply and no `lead.received`. This is **inherent**: it is an imported book, not an inbox (Lead.php:97).

## Production, last 30 days (read-only, non-test leads)

| Source | Leads | With phone | With email | Phone matches a customer |
|---|---|---|---|---|
| ringcentral | 7203 | 7203 | 0 | 2109 |
| local_services | 6456 | 6260 | 314 | 2480 |
| facebook | 321 | 158 | 1 | **1** |
| website_form | 297 | 293 | 292 | 113 |
| website_chat | 240 | 237 | 240 | 107 |
| instagram | 25 | 13 | 0 | 1 |
| whatsapp | 20 | 20 | 0 | 0 |
| website_booking | 18 | 17 | 0 | 12 |
| thumbtack | 3 | 3 | 0 | 1 |
| ez_texts | 1 | 1 | 0 | 1 |
| workiz | 3 | 3 | 3 | 3 |

- Half of Facebook leads (163 of 321) have no phone, so gap 1 is real.
- Of the 158 that do have a phone, only 1 matches a customer. Every other source matches 30–45%.
- Check whether those phones are stored in a shape the RIGHT(…,10) match misses, or whether they really are new people from ads.
