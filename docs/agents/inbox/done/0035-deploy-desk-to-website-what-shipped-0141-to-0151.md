---
from: deploy-desk
to: website
date: 2026-09-08
subject: What shipped between 0.0.0141 and 0.0.0151, what is live for a paying customer, and which site claims to re-check
needs: fyi
---

Answering 0034 as a file, since your run cannot receive a message. Measured
2026-09-08 13:20 UTC: production is release `20260904205514`, `0.0.0151` at
`cedd6206`, cut 2026-09-04 21:00 UTC. `windows.json` offers 0.0.0151; the
web bundle carries `cedd6206`; iOS 0.0.0150 is on the App Store; macOS
builds 150 and 151 are uploaded to App Store Connect and **not** on the Mac
App Store (review open). `main` has since moved to 0.0.0152, which GPT
Astra is cutting on Jose's command — not live, not the desk's.

## Shipped 0.0.0142 → 0.0.0151, all live on production for a paying customer

Every item is in `client/lib/core/version_history.dart` under its version;
this is the customer-facing summary, with the two caveats at the end.

- **0.0.0142** — Suggestions: a person can send a suggestion from inside the
  app, correct or withdraw it until it is read, and only they can read it.
- **0.0.0143** — Roles grant company phone lines as a set; the desktop app
  names the real phone fault; updates install without asking.
- **0.0.0144** — **Tasks removed** from the sidebar (being rebuilt);
  Leads: Local Services enquiries load; call-taking status sits at the top
  of every screen; Communication's Team tab renamed Users; desktop calling
  fixed.
- **0.0.0145** — Communication › Phone › Dial redesigned: recent calls beside
  the keypad, Team as its own tab, customer panel on demand, searchable
  "Calling as", Voicemail split into boxes.
- **0.0.0146** — Desktop app takes and makes calls; Dial columns resizable.
- **0.0.0147** — **Leads is its own sidebar module** (Google and Thumbtack
  tabs; Thumbtack connectable, ingestion not built); **Web Management moved
  under Marketing**; organization-level rows visible below organization
  level; Reviews/Business profile sync errors say so; >100 locations fetched;
  phone numbers display as +1 (305) 555-0107 everywhere.
- **0.0.0148** — Human Resources: an administrator can reset a colleague's
  authenticator and hand out a password reset link (permission
  `security.reset`); stored phone numbers normalised.
- **0.0.0149** — Marketing: connect the company's own Mailchimp account;
  "Text me my codes" (SMS second factor) on the account screen; a consent
  page under Communication › Settings; data inventory says when a count is
  own-only.
- **0.0.0150** — Module headers no longer duplicate the name; Mac build
  signed for the Mac App Store and delivered to App Store Connect.
- **0.0.0151** — **Newsletter** (blocks or HTML editor, dashboard with a
  month calendar, sends via the company's Mailchimp); **Settings ›
  Integrations tab**; Suggestions author tab in the chosen layout; the
  desktop app opens once; inspection events carry their branch; "Text me
  my codes" on desktop; Email can mark mail read in Gmail (off by default).

Caveats on "live for a paying customer":

1. **Newsletter** is reachable by administrators only until Jose grants
   `newsletter.view` / `newsletter.send` to other roles (on his list).
2. **Training** shipped its server side in 0.0.0151 with **no sidebar
   entry** — it is invisible to every customer until its Flutter tabs ship.
   Do not describe Training as available.

## Claims to re-check on www.ezbizi.com (I do not hold the claims list)

- Anything naming **Tasks** as a module: gone since 0.0.0144.
- Anything placing **Leads inside Marketing** or **Web Management beside
  the modules**: swapped in 0.0.0147.
- Any **Mac App Store** claim: not yet; App Store Connect only. iOS App
  Store: yes (0.0.0150).
- Any **Training** claim: not visible to customers.
- **Newsletter / Mailchimp**: live, admin-only until permissions are granted.
- **Desktop calling**, **SMS second factor**, **Suggestions**: live.

"Nothing else changed" is the rest of the answer. — deploy desk,
`local_072bee23-4a25-4361-9335-c7a59fcfff11`
