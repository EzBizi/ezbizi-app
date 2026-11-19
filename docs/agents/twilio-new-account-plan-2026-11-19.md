# New Twilio account: plan (Phone session, 2026-11-19)

Jose, 11-19: "make a new twilio account instead from scratch and we'll just port the numbers we have there when everything is set up correctly."

Everything under "Today" was read from the Twilio API and the production database on 2026-11-19. Nothing has been changed on Twilio for this plan.

---

## Today (measured)

**Old parent account:** ACbfccaca6da66d0b542a51ab89d306fe4. Its primary profile is BU1cddd… "A1 Chimney HQ LLC", which is the problem being escaped.

| Twilio account | EzBizi connection (Triplex Group org) | Numbers on Twilio | In EzBizi? |
|---|---|---|---|
| Parent | none (holds +18559553322 for A-1's connection) | +18559553322 (A-1 toll-free, SMS webhook to A-1's connection) | yes (A-1) |
| | | +18774508572 "EzBizi - Reports", no webhooks | no |
| | | +18778536971 "EzBizi - WhatsApp Business", no webhooks | no (WhatsApp) |
| A-1 Chimney AC77558c… | 01a044ae… (company A-1 Chimney), app AP267f… | 11 local lines: +15615767239 +17722526270 +12394560829 +19737820956 +15612323929 +12397470324 +14752152046 +13253124023 +18564468303 +14694988349 +17542953986 | yes |
| | | +19545162455 "A-1 Chimney WhatsApp bot", no webhooks | no (WhatsApp) |
| EzBizi App AC8ad313… | 01a044cb-6667… (company EzBizi), app AP5cac… | +17864086862 (General line) | yes |
| | | +18777373929, TF-verified; this is TWILIO_PLATFORM_SMS_FROM (login and OTP codes) | no (platform) |
| EverDoors ACcac641… | 01a044cb-6acd… (company Everdoors), app APe90d… | +18884974794 | yes |
| | | +18888599924, no webhooks | **no**: unknown purpose, ask Jose |
| AngiSweep AC92eae4… | 01a08cff… (company AngiSweep), app APa112… | +19544538774 | yes |

**Totals:** 19 numbers on Twilio, 15 of them EzBizi phone lines.

**Registrations today:**
- **A2P:**
  - A-1: brand BNd3493… on BU1cddd…, campaign C7I84F0 VERIFIED 11-19, on messaging service MG1e175… with the 11 local lines.
  - EverDoors: brand BNd72fb…, campaign CYPCQCJ VERIFIED, on MG7435….
- **Toll-free verifications:**
  - HHec96… for 855-955-3322: approved, on parent profile BU5477f9….
  - HHc191… for 877-737-3929: approved, on EZBIZI LLC profile BU5d1b24….
  - HHe16ad… for 888-497-4794: in review, wrongly on A1's profile BU1cddd….
- **Other trust products:** Voice Integrity, SHAKEN/STIR and CNAM for A1 (all on BU1cddd…) and for EverDoors.

**How EzBizi holds Twilio today (code):**
- **One connection per Twilio subaccount.** `phone_connections` stores account_sid, api_key_sid, twiml_app_sid, an encrypted `credentials` (API secret) and an encrypted `auth_token`.
- **Webhook URLs name only our org and connection ids**, never a Twilio SID:
  - voice: `https://app.ezbizi.com/api/v1/phone/voice/{org}/{connection}`
  - SMS: `https://app.ezbizi.com/api/v1/phone/sms/{org}/{connection}`
  - status: `https://app.ezbizi.com/api/v1/phone/status/{org}/{connection}`

  So a number on the new account can point at the very same URLs.
- **Platform credentials in prod .env** (`TWILIO_PLATFORM_*`) are used by:
  - HttpTwilioGateway: creating subaccounts and acting on the parent
  - ConnectionsController::platformTwilioReady
  - TwilioSignature::heldOnPlatform: webhooks for numbers held on the parent, like 855
  - PlatformSms: OTP and login codes, sent from `TWILIO_PLATFORM_SMS_FROM` = +18777373929 on `TWILIO_PLATFORM_SMS_ACCOUNT_SID` = the EzBizi App subaccount
- **`phone_numbers.account_sid`** records which Twilio account holds each number (855 = parent).

---

## 1. Jose does these himself (only he can)

1. **Create the new Twilio account.** Use an @ezbizi.com email, a password and 2FA on his phone. Then upgrade it with a card. A trial account can't buy, port or register anything.
2. **Create the primary Business Profile** in Trust Hub as **EZBIZI LLC**:
   - Address: 800 SE 4th Ave Ste 107, Hallandale Beach, FL 33009.
   - EIN.
   - Authorized representative: Jose, with an @ezbizi.com email and the website ezbizi.com.

   Twilio's form asks for the EIN and his ID details, and he signs it.
3. **Make one API key** (Standard) for us. Read it by script into F:\Credentials, the same way as today. Never paste it in chat.

After step 3 we do everything else through the API and tell him only if Twilio needs him again.

## 2. Setup on the new account, in order, before any number moves

1. **Wait for the primary profile** to be twilio-approved (Twilio says 24–72 h).
2. **Create subaccounts that mirror today's tree:**
   - "A-1 Chimney"
   - "EverDoors"
   - "EzBizi App"
   - "AngiSweep"

   For each one, make an API key and a TwiML app whose voice URL is `…/phone/voice/{org}/{connection}` for the existing connection.
3. **Create secondary customer profiles,** one per business, on the parent:
   - A1 Chimney HQ LLC
   - EverDoors (its own legal entity, not A1's; today's EverDoors toll-free verification wrongly hangs off A1's profile)
   - AngiSweep, if it will ever text

   Each needs its own legal name, EIN and address. Jose or Bar supplies the EINs.
4. **Re-register A2P** (it does not move with numbers; see §4):
   - A-1: Standard brand on the A1 Chimney HQ profile, then the Customer Care campaign. Copy campaign C7I84F0 exactly, including PrivacyPolicyUrl and TermsAndConditionsUrl; leaving those out cost us one rejection on 11-18.
   - EverDoors: copy CYPCQCJ.
   - Brand plus vetting took about a day each time today. Fees are charged again.
5. **Re-submit toll-free verifications** on the new account:
   - 855-955-3322 for A1
   - 877-737-3929 for EZBIZI LLC (platform OTP)
   - 888-497-4794 for EverDoors, on EverDoors' own profile

   These can only be filed once the numbers sit in the new account, so file each on the day it lands.
6. **Create messaging services,** one per A2P campaign, with "use inbound webhook on number" on, exactly as MG1e175… is set today.
7. **Register E911 addresses.** No action ahead of time: EzBizi's `EmergencyAddress::attach` does it per number, and it runs hourly. It recreates the address on whichever account the number is on.
8. **Optional, after numbers land:** re-apply Voice Integrity, SHAKEN/STIR and CNAM per profile, so calls aren't marked spam.

## 3. EzBizi changes (Phone session builds; one SHA, before the first move)

1. **Platform credentials:** swap the `TWILIO_PLATFORM_*` values in /var/www/ezbizi/.env to the new parent, then `config:cache`.
   - Change this only when 855 has landed, because `TwilioSignature::heldOnPlatform` checks parent-held numbers against the parent token.
   - Until then, the old and new parents both need to validate.
2. **Accept both parents during the move.** Small code change: let TwilioSignature accept a second, "previous platform" pair (`TWILIO_PLATFORM_PREVIOUS_*`), so webhooks from numbers still on the old account keep validating. Delete it at the end.
3. **Per-company connections:** for each connection, update its row in place:
   - `account_sid`, `api_key_sid`, `credentials`, `auth_token` and `twiml_app_sid` → the new subaccount's values
   - each number's `account_sid` and `twilio_sid`
   - `emergency_address_set=false`, so E911 re-attaches

   Same connection id, so webhook URLs, ring groups, extensions, history and permissions don't change. Dump `phone_connections` and `phone_numbers` first. Do it per company, at the moment its numbers land.
4. **Devices:** the desktop and phone apps re-register their voice token per account (`GET phone/registrations`). After a connection's account changes, the next heartbeat re-registers. Nobody re-installs anything.
5. **No code path left on the old account:**
   - grep shows Twilio credentials are read only from `phone_connections` and `config('services.twilio.platform_*')`; no SIDs are hard-coded.
   - After the switch, a check over `phone_numbers.account_sid` and `phone_connections.account_sid` must list zero old SIDs.
6. **PlatformSms (login codes):** `TWILIO_PLATFORM_SMS_FROM` / `_ACCOUNT_SID` move to the new EzBizi App subaccount once 877-737-3929 lands there and its toll-free verification is approved. Until then codes keep coming from the old account.

## 4. Moving the numbers

**It is a transfer, not a carrier port.** All 19 numbers are already Twilio numbers. Moving them between two Twilio accounts we own is Twilio's account-to-account number transfer: you request it from Twilio Support, both accounts must be ours and in good standing, and there's no LOA or carrier port.
- If Support instead insists on a regular port-in, the port data is the **old account's** business name, address and account SID, plus a recent invoice. That's an LOA signed by Jose.
- **Before the first move, confirm which of these applies with Support,** in one ticket that lists every number.

**What moves (16, per new subaccount):**
- **A-1 Chimney:** the 11 local lines, +18559553322, and the WhatsApp bot +19545162455. The bot is WhatsApp only, so check first with the WhatsApp session that the Meta sender survives the move.
- **EverDoors:** +18884974794. Also +18888599924, if Jose wants to keep it.
- **EzBizi App:** +17864086862 and +18777373929.
- **AngiSweep:** +19544538774.
- **Parent extras,** only if Jose wants them: +18774508572 "Reports" and +18778536971 "WhatsApp Business".

**Order:**
1. **Test first:** +19544538774 (AngiSweep), a single line with no texting registration. Move it, switch its connection, then make a test call and text to Jose's +13057242199 and check line health.
2. **EzBizi App:** +17864086862, then +18777373929, then switch PlatformSms.
3. **EverDoors:** +18884974794 (+18888599924).
4. **A-1:** the 11 locals together, so the campaign covers them all at once. Then 855. Then switch the platform credentials (§3.1).

**Avoiding a gap:**
- Before each number moves, set its voice, SMS and status URLs on the new side to the same `…/{org}/{connection}` URLs. They already match on the old side, so calls ring through the moment the transfer completes.
- The only window is texting. A2P is per account, so a local number that moves before its new campaign is VERIFIED can receive texts but its outbound texts get filtered.
- So, for each company: get its campaign VERIFIED on the new account first (attach it to an empty messaging service), then move the numbers and bind them straight away. EzBizi's `sms_outbound_allowed` stays on throughout, because the number's campaign is live on both sides.

**What happens to A2P registrations:** they stay on the old account and don't follow the number. After the move, the old campaigns have no numbers; the new ones take over. Toll-free verification likewise has to be filed again on the new account.

## 5. What stays on the old account, and when it closes

- **Until the last number is gone, it keeps:**
  - the old A2P brands and campaigns (A-1 C7I84F0, EverDoors CYPCQCJ), which have to stay live until each company's numbers are bound on the new side
  - the platform OTP sender, until 877 lands and is verified
  - the parent credentials EzBizi validates 855's webhooks with
- **Close it when** a check shows zero old SIDs in `phone_numbers` and `phone_connections`, every new line shows a clean line health with texting verified, and a full billing cycle shows no traffic on the old account.
- **Then:**
  1. Delete `TWILIO_PLATFORM_PREVIOUS_*`.
  2. Close the subaccounts, then the parent, from Jose's login. Closing is his click.
  3. Cancel the old campaigns so their monthly fees stop.

## Questions for Jose (only these)

1. **+18888599924** (EverDoors, no webhooks, not in EzBizi): keep it or let it go?
2. **The parent's "Reports" (+18774508572) and "WhatsApp Business" (+18778536971):** keep them?
3. **EINs** for A1 Chimney HQ LLC, EverDoors and AngiSweep (for their secondary profiles), or tell us who has them.
