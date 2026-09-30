# Apple — reply to Guideline 2.1 (Information Needed)

> **The live Notes text is `docs/apple-review-notes/ios.txt` and `macos.txt`,**
> byte for byte - not this document. Edit those, then run
> `python scripts/asc-review-notes.py --push`, which writes both platforms to
> App Store Connect and reads each back. Run it without `--push` to see
> whether live and the files still agree. No browser or password needed.
>
> A change that alters what the app asks for (a permission, a background
> mode, an outside service) must change those files **in the same commit**.
> They were false for three weeks because the permission line was written
> once and never re-read against the binary.

Paste the block below into **App Store Connect → App Review Information → Notes**,
and reply on the Resolution Center thread pointing at it.

This is a **reply, not a resubmission**. The binary Apple already has (0.0.0112,
build 112) is unchanged and stays valid. No rebuild, no `client/VERSION` bump.

Every fact below was checked against the code or the running system, not
remembered. Where something is a judgement rather than a fact it says so.

---

## 2. Devices and operating systems tested on

    iPhone 16 Plus (iPhone17,4) — iOS 26.6      physical device
    iPhone 15 Pro Max (iPhone16,2) — iOS 26.6   physical device
    iPad Pro 13-inch (M4) — iPadOS 26.0         simulator

The iPad screenshots in the listing were captured on the iPad Pro 13-inch
simulator. If you have run the build on a physical iPad, add it; if not, leave
this exactly as it is. Do not list a device the app has not actually run on —
Apple checks the recording against this list.

## 3. What the app does, and for whom

EzBizi is field-service business software for companies that run work across
more than one location — chimney sweeps, garage-door installers, and similar
trades with several branches or franchises.

The problem it solves: in these businesses the office and the field usually work
from different systems. The customer record lives in one place, the job schedule
in another, the technician's inspection notes on paper, the van's service history
in a folder. Nobody can answer a customer's question without asking three people.

EzBizi puts customers, jobs, inspections, inventory, vehicles and staff on the
same records, scoped so that each location sees its own work while the group
above sees all of it. A customer served by two locations is still one customer.

Target audience: owners, office staff and field technicians at multi-location
service businesses. It is sold to the business, not to consumers.

## 4. Setting up and reaching the main features

EzBizi is used by invitation. There is no public sign-up: an organization invites
its staff, and each person sets their own password from that invitation. For
review, use the demo organization below. It contains invented data only — no
real customer or business information.

    URL       https://app.ezbizi.com  (the app points here by default)
    Email     apple@ezbizi.com
    Password  see the Sign-In Information field above

That account is an Administrator of the demonstration organization "EzBizi
Demo", so every module is reachable. There is one account type to test; the
same credentials reach everything.

After signing in, the sidebar lists every area. The core flow is:

    Dashboard   one summary per module
    Customers   the people served, and their history
    Jobs        work booked against a customer
    Inspections build a named inspection, submit a report from the field
    Inventory   stock by item and location
    Fleet       vehicles, insurance, service history

No sample files are needed. Nothing must be uploaded to reach any feature.

## 5. External services used

Hosting and infrastructure

    Amazon Web Services   application hosting and database
    Amazon SES            transactional email delivery

Optional per-tenant integrations. NONE are required to use the app, and none
are configured in the demo organization — each is connected by the customer,
with their own account, and can be disconnected at any time.

    Twilio                          business phone numbers and SMS
    Google (Gmail) / Microsoft 365  connecting a company mailbox
    Meta (Facebook, Instagram,
      Threads), TikTok, Pinterest,
      LinkedIn, X, YouTube          publishing the customer's own social posts
    Google Ads, Google Business
      Profile, Thumbtack           lead sources

Not used, stated explicitly because the question asks:

    No payment processor. Nothing is sold inside the app.
    No AI or machine-learning service of any kind.
    No advertising, analytics, attribution or crash-reporting SDK. The app
      contains no third-party SDK that collects data.

Permissions, read out of the shipped binary (0.0.0275, 2026-09-25) rather than
remembered — this line said "the only requested permission is network access"
for three weeks after that stopped being true:

    Microphone     asked the first time somebody makes or takes a call in the app
    Notifications  so a job assigned to somebody reaches their phone
    Location       iPhone only, When In Use only (never Always), and only for an
                   employee whose employer turned Field on and marked their role
                   tracked; or on SOS from a phone. macOS never requests it.
    Nothing else.  No contacts, camera, photos or tracking (ATT).

Location arrived with the Field module (0.0.0278). This block,
apple-review-notes/ios.txt and macos.txt changed in the same commit as the
code. The App Privacy label must also declare Precise Location, linked to
the user, for App Functionality, in the portal, before a build carrying
Field is submitted.

## 6. Regional differences

None. The app behaves identically in every region. It is available in English
(United States) only, has no region-gated features, no regional pricing, and no
content that varies by territory.

## 7. Regulated industry or protected third-party material

Neither. EzBizi is general business software for service companies. It is not a
financial, medical, legal or otherwise regulated service, and it contains no
licensed or protected third-party material. All content in the app is either
EzBizi's own or entered by the customer about their own business.

---

# App Privacy label — what to declare for the Field release

App Store Connect -> App Privacy. Not reachable through the API, so this is a
portal step. Decided 2026-09-30 against Apple's own definitions
(developer.apple.com/app-store/app-privacy-details), not from memory.

| Data type | Linked to user | Tracking | Purpose |
|---|---|---|---|
| Location -> **Precise Location** | Yes | No | App Functionality |
| **Other Data Types** | Yes | No | App Functionality |

"Other Data Types" covers what the iPhone sends while sharing location
(5600d4e5): battery level and charging state, device model name, iOS version,
EzBizi app version. Latest value only, shown to the technician's manager.

Why not "Other Diagnostic Data": Apple defines it by PURPOSE - "collected for
the purposes of measuring technical diagnostics related to the app". This is
collected so a manager can see a technician's phone, which is App
Functionality. "Other Data Types" is Apple's "any other data types not
mentioned".

Why not optional: Apple lets you skip a type only if collection is infrequent,
outside primary functionality, and chosen by the user each time. This is
routine and automatic, so it must be declared.

Why no Device ID: measured, not assumed. The change sends modelName and
systemVersion; it never reads identifierForVendor or any device-level id.
If that ever changes, Identifiers -> Device ID must be added.

---

# Item 1 — the screen recording

Apple asks for a recording **captured on a physical device running the latest
OS**. A simulator recording is visibly different and gets rejected again.

Both paired iPhones run iOS 26.6, which is current, so either works:

    iPhone 16 Plus     (wireless)
    iPhone 15 Pro Max  (cable)

## What Apple asked for, and what applies

| They asked about | EzBizi |
|---|---|
| Account registration | **Does not exist in the app** — say so aloud |
| Login | Yes — show it |
| Account deletion | **In the app** - `DELETE /api/v1/me`, from the account screen. Show it |
| Paid content, purchase, subscription | None. Nothing is sold in the app |
| User-generated content, reporting, blocking | No public content or feed. Users reach only colleagues in their own organization (phone extensions) |
| Permission prompts | **Microphone** (on the first call), **notifications**, and on iPhone **location** When In Use, only for an employee their employer tracks (Field). No contacts, camera or ATT — see "Permissions" above, and re-read the binary before trusting this row |

So the recording is: launch, sign in, walk the core features. Nothing to
demonstrate for the other four categories, but say out loud that they do not
apply — a reviewer marking "did not show purchase flow" is what causes the
second rejection.

## Shot list — roughly two minutes

1. Home screen, tap the EzBizi icon. Start from launch, not from an app already
   running.
2. Sign-in screen. Say: "There is no sign-up. Staff are invited by their
   organization." Sign in as `apple@ezbizi.com`.
3. Dashboard. One glance per module.
4. Customers → open a customer → show their jobs.
5. Jobs → open a job.
6. Inspections → open a named inspection.
7. Inventory → items and stock.
8. Fleet → a vehicle with its service history.
9. Say: "No purchases, no subscriptions, and no user-generated public content.
   The app asks for the microphone only when a call starts, and for
   notifications." Place or take a call on camera so the microphone prompt is
   seen in context — a reviewer who meets a prompt the notes did not mention
   is the rejection this line exists to prevent.

Record with the iPhone's own screen recorder (Control Centre), or QuickTime on
the Mac with the iPhone as the movie source. Either is a genuine device capture.

---

# One risk worth knowing before you reply

Apple's list includes **account deletion flows**, and EzBizi has none in the app
— deletion is by request to `contact@ezbizi.com`, documented at
`https://www.ezbizi.com/delete-account/`.

Guideline 5.1.1(v) requires apps that let people *create* an account to also let
them delete it from within the app. Our position is that EzBizi does not offer
account creation: there is no sign-up, the server returns `signup_open: false`,
and accounts exist only because an organization created them. Under that reading
the rule does not apply, and business apps where an employer owns the account are
a recognised exception.

That is a reasonable reading, not a certainty. If Apple disagrees, the fix is an
in-app "delete my account" path, which is real work and would need a new build.
Better to know now than to be surprised by a second rejection.

Do not raise it unprompted in the reply. Answer what was asked.
