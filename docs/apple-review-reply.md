# Reply to Apple — Guideline 2.1, Information Needed

Build under review: **0.0.0129**. Recording made on an **iPhone 16 Plus, iOS 26.6**,
and it includes the account deletion flow.

Paste **Part A** into the Resolution Center reply, and **Part B** into
App Store Connect → App Review Information → Notes (Apple asked for it there
"for future submissions", so it should live in both places).

---

## Part A — the Resolution Center reply

Thank you for the detailed list. Everything requested is below, and the same
information has been added to the Notes field in App Review Information.

**1. Screen recording**

Attached. It was captured on a physical iPhone 16 Plus running iOS 26.6, and it
begins with launching EzBizi from the Home screen.

The recording shows the typical user flow: signing in, the dashboard, and the
core modules — Customers, Jobs, Inspections, Inventory and Fleet — followed by
the account deletion flow in the account screen.

Taking your four specific categories in turn:

- **Account registration** — EzBizi has no registration. It is business software
  used by invitation: an organization invites its staff, and each person sets a
  password from that invitation. There is no public sign-up, and the server does
  not offer one.
- **Login** — shown, using the demo credentials below.
- **Account deletion** — shown. A person can delete their own account from the
  account screen. It asks for their password and for them to type DELETE, then
  erases their personal details and signs them out everywhere. The one exception
  is visible in the app: the sole administrator of an organization is told they
  must make somebody else an administrator first, so that a business is never
  left with nobody able to run it.
- **Paid content, purchases, subscriptions** — none exist. Nothing is sold inside
  the app, there are no in-app purchases and no subscriptions.
- **User-generated content, reporting and blocking** — none. There is no public
  content, no feed, no profiles and no way for one user to contact another.
  Everything a person writes is a private business record visible only to their
  own organization.
- **Prompts for sensitive data or device capabilities** — none. EzBizi requests
  no device permissions at all. The submitted binary declares one permission,
  network access, and contains no location, contacts, camera, microphone or App
  Tracking Transparency usage.

**2. Devices and operating systems tested on**

- iPhone 16 Plus — iOS 26.6 (physical device; used for the recording)
- iPhone 15 Pro Max — iOS 26.6 (physical device)
- iPad Pro 13-inch (M4) — iPadOS 26.0 (simulator)

**3. What the app does, and for whom**

EzBizi is field-service business software for companies that run work across more
than one location — chimney sweeps, garage-door installers and similar trades
with several branches or franchises.

The problem it solves: in these businesses the office and the field usually work
from different systems. The customer record is in one place, the job schedule in
another, the technician's inspection notes on paper, the van's service history in
a folder. Nobody can answer a customer's question without asking three people.

EzBizi puts customers, jobs, inspections, inventory, vehicles and staff on the
same records, scoped so each location sees its own work while the group above
sees all of it. A customer served by two locations is still one customer.

Target audience: owners, office staff and field technicians at multi-location
service businesses. It is sold to the business, not to consumers.

**4. Setting up and accessing the main features**

No setup is required and no sample files are needed. Sign in with:

    Email     apple@ezbizi.com
    Password  (as provided in the Sign-In Information field)

That account is an Administrator of a demonstration organization containing
invented data only — no real customer or business information. There is one
account type, and these credentials reach every feature.

After signing in, the sidebar lists every area. The core flow is Dashboard for a
summary per module, then Customers, Jobs, Inspections, Inventory and Fleet.
Account deletion is in the account screen, reached from the avatar.

**5. External services used**

Infrastructure:

- Amazon Web Services — application hosting and database
- Amazon SES — transactional email

Optional per-tenant integrations. None are required to use the app and none are
configured in the demo organization. Each is connected by the customer using
their own account, and can be disconnected at any time:

- Twilio — business phone numbers and SMS
- Google (Gmail) and Microsoft 365 — connecting a company mailbox
- Meta (Facebook, Instagram, Threads), TikTok, Pinterest, LinkedIn, X, YouTube —
  publishing the customer's own social posts to their own accounts
- Google Ads, Google Business Profile, Thumbtack — lead sources

Stated explicitly because the question asks: there is **no payment processor**,
**no AI or machine-learning service**, and **no advertising, analytics,
attribution or crash-reporting SDK** of any kind. The app contains no third-party
SDK that collects data.

**6. Regional differences**

None. The app behaves identically in every region. It is offered in English
(United States) only, has no region-gated features, no regional pricing and no
content that varies by territory.

**7. Regulated industry or protected third-party material**

Neither applies. EzBizi is general business software for service companies. It is
not a financial, medical, legal or otherwise regulated service, and it contains no
licensed or protected third-party material. All content is either EzBizi's own or
entered by the customer about their own business.

Please let us know if anything further would help.

---

## Part B — the Notes field

Paste items 2 to 7 above (from "Devices and operating systems tested on" through
to the end of item 7). Apple asked for this to be present for future submissions,
so it belongs in the Notes field permanently, not only in this reply.

Also confirm the Sign-In Information fields still hold:

    Username  apple@ezbizi.com
    Password  the demo password from H:/Development/Credentials/
