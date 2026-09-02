# Online booking — the public surface

| | |
|---|---|
| Date | 1 September 2026 |
| Status | Design approved, not yet planned |
| Second spec | Google Actions Center channel — depends on this one existing |

---

## 1 · What this is

**A stranger who has never heard of EzBizi books a real job on a franchise's
schedule.** Today every EzBizi surface is behind a login: the only unauthenticated
routes in the whole application are password reset, invitation acceptance and a
Meta data-deletion callback. This adds the first surface intended for the public.

The immediate reason is Reserve with Google, whose Appointments integration is a
*redirect* — Google sends the customer to a page we host, it does not host the
booking itself. But the page is worth more than that one channel, so it is built
as a product rather than as a Google adapter: the same page serves a link in an
email, an embed on the merchant's own site, and Google.

**Google is one channel over this, not the reason for it.** That distinction is
what keeps the second spec from leaking into this one.

## 2 · What this is not

Deliberately absent, and each for a reason:

| Not building | Why |
|---|---|
| A public booking **API** | A company without WordPress usually has no developer either; an API is a project they cannot staff. The embed in §9 covers them. Waits for a customer with a developer to ask. |
| Deposits or payment | Nothing in EzBizi takes money today. Adding a payment surface and a public surface in one change is two risks wearing one commit. |
| Customer self-rescheduling | Every reschedule is a capacity move. Worth doing once bookings exist and are boring. |
| Multi-service baskets | One service per booking. A basket needs duration arithmetic the service model does not carry (§5). |

## 3 · The surface

`book.ezbizi.com`, served by the same Laravel application. **No new box, no new
deploy target** — a route file, a middleware group and Blade views.

**Server-rendered, not Flutter.** The client app is a multi-megabyte SPA behind a
login; shipping it to somebody who wants to pick a Tuesday is wrong three ways.
Google's redirect wants a cheap fast target, the page must render inside an iframe
on a stranger's Wix site, and it must work for a person with no account.

**Its own subdomain, not a path under the app.** The page is embedded on
third-party sites. On a separate origin, `app.ezbizi.com`'s session cookie is not
reachable from that iframe at all. Under a path it would be, and the isolation
would become something to get right with cookie scoping and CSP rather than
something structurally absent.

`routes/booking.php`, no `auth` middleware:

| Route | Does |
|---|---|
| `GET /{merchant}` | The booking page |
| `POST /{merchant}/availability` | zip → service area → open slots |
| `POST /{merchant}/verify` | Send the confirmation code |
| `POST /{merchant}/book` | Check the code, create the job |
| `GET /{merchant}/embed.js` | One line the merchant pastes; injects an iframe of the page |

## 4 · Tenancy, and not saying who exists

`TenantScope` throws with no tenant set, and a stranger has no login, so this
cannot be handwaved. The pattern already exists — `InvitationAcceptanceController`
is a public route into tenant data — and is carried across rather than reinvented:

- The merchant resolves from an **opaque slug**, never an organization id.
- The slug lookup is the one cross-tenant read, and runs through
  `runWithoutTenant()` **inside a dedicated service**, not in the controller.
- Everything after resolution — every read and every write — runs inside
  `TenantContext::run($organization, ...)`.

**Enumeration.** A public URL is probed. An unknown slug, a page switched off and
a lapsed merchant all return **one identical response**; the caller cannot tell
which happened. A zip outside a franchise's coverage answers "no availability for
that area", never "that zip is not ours" — the second sentence maps a
competitor's service areas for them, one request at a time.

## 5 · What a merchant opts in

Nothing is public until somebody ticks it. The internal catalogue was written for
staff: exposing it wholesale would publish internal naming and the entire price
list to anyone, competitors included.

Two columns on `services` rather than a join table — the row already knows its
franchise, so a table keyed (service × franchise) would restate what the foreign
key says:

- `bookable` — offered on the public page at all
- `show_price` — whether `price_minor` is shown, per service

A service has **no duration** and none is added here. Capacity is jobs per window
(§6), not minutes, which suits home services: the customer is promised a window,
not a 45-minute appointment. A booked job takes `scheduled_for` = the slot's start
and `duration_minutes` = the slot's length.

## 6 · Availability

Read-only, and entirely derived from the scheduling module that already exists
(`service_areas`, `service_area_zips`, `service_area_slots`, migrated
2026-08-31):

```
zip → service_area_zips → the owning area → service_area_slots for that weekday
    → minus jobs already scheduled in that window → remaining
```

One zip belongs to exactly one area per franchise, enforced by a unique index, so
the resolution is unambiguous without asking the customer anything.

Only slots with remaining capacity are shown, over **the next 14 days**. A
weekday with no slot rows is a closed day and simply does not appear.

Fourteen rather than an unbounded horizon: slots are a weekday pattern, so a
longer window publishes availability for dates the franchise has not yet thought
about, and a customer booking eleven weeks out is a customer the office will ring
to move. The number is configuration, not a constant, so it can be argued with
later without a release.

## 7 · The booking write

A booking creates a **confirmed job that consumes capacity immediately**. The
alternative — a request somebody confirms later — was rejected because published
availability would then be able to lie: two people can take the same last slot
while both requests sit unreviewed.

All of the following in **one transaction**:

1. Re-check the slot's remaining capacity **with row locking**
2. Match or create the `Customer`, on normalised phone digits (`Lead::digits()`,
   the existing precedent)
3. Create the `Job` — `scheduled_for`, `duration_minutes`, service, franchise
4. Write the audit row
5. Emit the event

**The re-check inside the transaction is the point.** Availability was read
minutes earlier on a page that has been sitting open. Without locking, two people
booking the last 7–11am slot at the same moment both succeed, and a public page
makes that ordinary rather than theoretical.

## 8 · Confirming the person

A public form that creates confirmed jobs can also fill a franchise's week with
fake ones. **An SMS code, with an email link as fallback.**

The customer enters a number, receives a six-digit code, and the job is created
only once it checks out. This reuses `TwilioGateway::sendMessage`, already in the
phone module; it doubles as the booking confirmation rather than adding a step
that only serves us; and it means **every booking carries a phone number somebody
answered**, which is worth more to the office than any anti-spam measure.

Where a franchise has no phone connection, an emailed confirmation link stands in.
Weaker — email is disposable — but it is the difference between that franchise
having online booking and not.

## 9 · Reaching the merchant's own site

The hosted page has to exist for Google regardless. The other two channels are
then nearly free, because **all three are the same page**:

| Channel | What it is |
|---|---|
| Hosted page | `book.ezbizi.com/{slug}` — a link that works with no website at all |
| Embed | One `<script>` tag that injects an iframe of that page. Covers Wix, Squarespace, GoDaddy, hand-rolled HTML — everything |
| WordPress | The Web Management plugin places that same embed natively |

This is why an API is not in scope. It is the only one of the four that is a
genuinely separate build, and it is the one nobody has asked for.

## 10 · Storage

| Table | Columns | Notes |
|---|---|---|
| `booking_pages` | `franchise_id`, `slug`, `headline`, `active` | `slug` unique platform-wide; it is the public address |
| `services` | **+ `bookable`, `+ show_price`** | Both default false — nothing becomes public by a migration |
| `booking_verifications` | `booking_page_id`, `phone_digits`, `code_hash`, `expires_at`, `attempts` | Code hashed, not stored plain; attempts capped |

No change to `service_areas`, `service_area_zips` or `service_area_slots`. This
reads them and writes none of them.

## 11 · Abuse

A named `throttle:booking` limiter following the existing `throttle:signup` and
`throttle:invitation` convention, code expiry, and a cap on verification attempts
per number.

## 12 · Testing

The four that would actually catch a defect, rather than one per method:

1. An unknown slug, a disabled page and a lapsed merchant return **identical**
   responses — the enumeration guarantee in §4.
2. Concurrent bookings of the last slot **cannot oversell it** — the transaction
   and lock in §7. Without this the race is invisible until a customer is
   double-booked.
3. A booking presented **without a verified code is refused**.
4. One merchant's slug **cannot reach another merchant's slots** — the tenancy
   boundary in §4, asserted from the outside rather than trusted.

## 13 · Sequencing

1. **This spec** — the page, availability, the booking write, verification, embed.
2. **The WordPress plugin** — a small addition to Web Management, rides along.
3. **The Google channel** — its own spec: the Actions Center partner interest
   form, the entity/service feeds, and certification. It describes this page, so
   it cannot be written usefully until this page exists.

The partner interest form has a long approval lead time and costs nothing, so it
can be submitted while this is being built rather than after — the same reasoning
that applied to the Google Ads API application.
