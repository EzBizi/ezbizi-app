# Thumbtack Help Center article — submitted answers

Filed from **development@ezbizi.com** (the account the partner application was
made with). Form: *Thumbtack Help Article Submission — Integration Partners*.

Every answer below describes what EzBizi **actually does today**, not what it is
planned to do. Thumbtack publishes this as documentation Pros follow, so an
aspirational answer here becomes a support ticket there.

---

## Two of the ten permissions cannot exist

Jose ticked all ten boxes and asked whether we could actually have them. **Two
of them nobody can have**, and that is verifiable from Thumbtack's own OpenAPI
document rather than a judgement call:

| Ticked | Reality |
|---|---|
| Edit / update your business profile | **No endpoint.** The supply API has `POST /v4/businesses` (create) and nothing that updates one. |
| Upload photos | **No endpoint.** `supply::businesses/media.write` is declared in their scope vocabulary and *zero* endpoints reference it. Same for `categories.write` and `preset-jobs.*`. |

Both unticked. The other **eight are built** — see the commit "Eight of the ten
Thumbtack permissions, and why the other two cannot exist".

### Webhooks are lead delivery, not a notification option

Their supply API can fetch a negotiation by id and has **no endpoint that lists
them** — no cursor, no paging. A webhook is the only way EzBizi ever learns a
lead exists, so "view leads" and "webhooks" are one feature, not two.

Two consequences stated in the article because Pros will hit both:

- An account connected without a registered webhook receives **nothing at all**,
  rather than receiving things late.
- **Nothing can backfill.** Enquiries from before registration are permanently
  unreachable, because no endpoint would find them.

## Section 1 — Partner information

| Field | Answer |
|---|---|
| Email | `development@ezbizi.com` |
| Legal Business Name | `EZBIZI LLC` |
| Product Website URL | `https://www.ezbizi.com` |
| Partner Login URL | `https://app.ezbizi.com` |
| Partner Support URL or Email | `contact@ezbizi.com` |

**Brief Product Description**

> EzBizi is field-service business software for home-service companies — one
> place for jobs, scheduling, customers, invoicing, phone, fleet and staff,
> instead of a separate tool for each. It is built for companies that run
> several branches or franchises under one roof, so a franchise's own work stays
> its own while the group can still see across all of it. The Thumbtack
> integration keeps your team's phone numbers registered with Thumbtack, so
> calls between your technicians and your Thumbtack customers are recognised as
> coming from your business.

**Target Industry / Customer Type**

> Home-service and field-service companies — chimney sweeps, HVAC, plumbing,
> garage doors, dryer-vent and air-duct cleaning — including multi-branch and
> franchise groups.

**3 Key Benefits for Thumbtack Pros**

> • Your team is recognised — the phone numbers your technicians actually call
> from are registered with Thumbtack, so a customer sees your business rather
> than an unknown number.
> • Nothing to keep up by hand — when somebody joins or leaves, EzBizi updates
> Thumbtack the same night. No re-entering numbers in two places.
> • One place for the rest of the job — the same system holds the scheduling,
> the customer record, the invoice and the phone line.

---

## Section 2 — Data

**Data sent FROM Thumbtack TO EzBizi**

Business ID, name and profile image URL; the connected Thumbtack user ID;
associate phone number ID, number, name, created/updated timestamps and deleted
flag; negotiation ID and created time; customer ID, first name, last name and
phone; request ID; service category ID and name; proposed appointment times;
message ID, text, sender, sent time and attachment file name/size/MIME/URL;
review ID and content; webhook ID, URL, enabled flag and event types.

**Data sent FROM EzBizi TO Thumbtack**

Associate phone number (E.164) and associate name; message text for a reply a
Pro sends from inside EzBizi; job status (`not_scheduled`, `appt_scheduled`,
`job_complete`, `invoice_paid`, `customer_cancel`, `pro_cancel`) with the time
it actually changed; invoice amount in cents for `invoice_paid`; and the webhook
callback URL, event types and the credential EzBizi generates for Thumbtack to
authenticate itself with.

No customer records, pricing or scheduling data held in EzBizi is sent. The only
job information sent is the status of work that came from a Thumbtack enquiry.

## Section 3 — Setup

**Step-by-step connection instructions**

> 1. Log in to EzBizi at https://app.ezbizi.com.
> 2. Click **Leads** in the left sidebar.
> 3. Open the **Thumbtack Leads** tab.
> 4. Click **Connect Thumbtack**.
> 5. Sign in to Thumbtack in the window that opens and approve the requested
>    access.
> 6. You are returned to EzBizi and the tab shows your connected Thumbtack
>    business by name.
>
> You need the "manage Leads" permission in EzBizi to connect an account. If you
> do not have it, the tab tells you so instead of showing the button — ask
> whoever manages roles in your organisation.

**Configurable Settings**

> Associate phone number sync | Automatic, nightly | EzBizi sends the phone
> numbers of the people assigned to the branch this Thumbtack business is
> connected for, and removes numbers for people who have left. No setting to
> change.
>
> Which team members are included | Determined by the branch you connect for |
> A Thumbtack business connected for one company or franchise only ever sends
> that place's people. Sibling companies never share numbers.
>
> Phone number source | Each team member's profile phone number | Update it in
> EzBizi under the person's profile; the next sync picks it up.

**Common errors and solutions**

> That connection has no usable token — the connection has expired or was
> revoked on Thumbtack | Open Leads → Thumbtack Leads in EzBizi, disconnect, and
> connect again.
>
> A team member's number is not registered with Thumbtack — the number in their
> EzBizi profile is not a valid US phone number | EzBizi skips numbers it cannot
> read and names them in the sync report. Check the person's profile phone
> number: it must be a real 10-digit US number, not an extension.
>
> Numbers are missing for some of the team — those people are not assigned to
> the branch this Thumbtack business was connected for | Check the person's
> assignment in EzBizi. Only people assigned to the connected company or
> franchise are sent.
>
> Thumbtack refused it: that number belongs to another business | The same
> handset is already registered to a different Thumbtack business. Remove it
> there first.

**Step-by-step disconnect instructions**

> 1. Log in to EzBizi at https://app.ezbizi.com.
> 2. Click **Leads** in the left sidebar.
> 3. Open the **Thumbtack Leads** tab.
> 4. Click **Disconnect** on the connected account and confirm.
>
> What happens afterwards:
> • EzBizi deletes its stored Thumbtack access and refresh tokens immediately
>   and stops sending anything to Thumbtack. The disconnection is recorded in
>   your EzBizi audit log.
> • **Phone numbers already registered with Thumbtack are not removed by
>   disconnecting.** They stay on your Thumbtack account until you remove them
>   in Thumbtack, or reconnect EzBizi and let the next sync reconcile them.
> • Nothing else in EzBizi is affected — your jobs, customers and phone system
>   are untouched, because no Thumbtack data is used by them.

---

## Section 4 — Permissions to tick

Eight, each with code behind it:

- [x] View your Thumbtack business profile and account details
- [x] View and manage phone numbers linked to your Thumbtack account
- [x] Read messages you receive from customers on Thumbtack
- [x] Send messages to customers on your behalf via Thumbtack
- [x] View your leads and job requests from Thumbtack
- [x] Update job status
- [x] Receive instant notifications when new leads arrive (webhooks)
- [x] Disconnect / remove the integration from your Thumbtack account

Unticked because **no endpoint exists** for either: edit/update profile, upload
photos.

## Follow-ups this raised

1. ~~Disconnect leaves numbers behind at Thumbtack.~~ **Closed.** Disconnecting
   now removes the numbers, deletes the webhook and revokes the grant, in that
   order — revoking first would make the other two impossible. Every step is
   best-effort and none can block the disconnect, because the account somebody
   is trying to disconnect is exactly the one whose token already stopped
   working. The disconnect answer above must be re-worded before filing.
2. **This article assumes the connect UI is deployed.** The Leads restructure
   and the Thumbtack Connect button are committed but not yet on
   app.ezbizi.com. The instructions above are wrong until that ships.
3. **Update when lead ingestion lands** — the permissions list, the data list
   and the "not fetched yet" wording all change together.
