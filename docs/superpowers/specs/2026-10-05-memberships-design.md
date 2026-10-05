# Memberships — yearly maintenance program (Customers › Memberships)

Owner: Membership Program session (`local_9b8c5aa9-9f63-4f38-9ecc-78746c73404b`).

## Jose, 2026-10-05

> a sub tab in Customers, with its own sub tabs of Dashboard - Members - Settings

> they can sign up via an expansion of the review widget whereas they leave a
> good review, it would send the customer an automatic text or whatsapp or email
> (depending on the automation and settings) ... thanking them for the review and
> offering them to become part of the Membership Program

> once they sign up to the program, it sets a timer for 1 year, and 1 month
> before the 1 year mark, the customer gets sent a reminder so he can confirm the
> appointment date for his next visit, basically a yearly maintenance program.

> on the tab i would need to see the customer list, enrollment type, plan type,
> amount due, state, zip, estimate gross for client, amount paid so far

Answers to the follow-up questions, same day:

| Question | Answer |
|---|---|
| How a member pays | Either, per plan: each plan is billed yearly or monthly |
| Enrollment type | New vs renewal |
| Estimate gross for client | Plan price + their average job value |
| After the reminder | All three: the reminder is sent; a confirmed date books a job; no answer leaves staff a follow-up |

## Model

- `membership_plans`: name, `price_minor`, currency, `billing` (yearly|monthly), active.
- `memberships`: ONE ROW PER YEAR. customer, plan, `franchise_id` (customer's
  owning franchise at joining), `enrollment` (new|renewal — renewal when the
  customer has any earlier row), `starts_on`, `ends_on` (= starts + 1 year − 1 day),
  `cancelled_at`, `reminded_at`, `paid_minor`.
- Status is derived: cancelled / expired / upcoming / active.
- Amount due: yearly = price − paid; monthly = price × months begun ÷ 12 − paid. Never negative.
- Estimated gross = plan price + the customer's average job `amount_minor`.
- Every amount needs `jobs.financials` (Jose's money rule); without it the
  amounts are null and the row is still shown.

## Permissions

Module `memberships` (parent `customers`, no sidebar entry), CRUD actions, tabs
`memberships.dashboard.view`, `.members.view`, `.settings.view`. Plans are
changed with `settings.view` + `memberships.edit`.

## Automations

- `membership.created` — "Member joined".
- `membership.renewal_due` — raised once by `memberships:remind-renewals`
  (daily 09:00 New York) when a live year ends within a month.
- The subject carries `customer_id`, so "The customer" works as the recipient
  of SMS, WhatsApp and email steps. The channel is the automation's choice.

## API (phase 1, done)

    GET    /memberships               latest year per customer in view
    POST   /memberships               {customer_id, membership_plan_id, starts_on?, paid_minor?}
    PATCH  /memberships/{id}          {membership_plan_id?, paid_minor?, cancelled?}
    DELETE /memberships/{id}
    GET    /memberships/plans
    POST   /memberships/plans         {name, price_minor, billing, is_active?}
    PATCH  /memberships/plans/{id}

Row shape: id, customer_id, customer_name, state, zip, enrollment, plan_id,
plan_name, billing, starts_on, ends_on, status, reminded_at, currency,
price_minor, paid_minor, due_minor, estimated_gross_minor.

## Review → offer (phase 2, done)

Jose, on how the faces know who smiled: **both** — a personal link, else ask.

- `{{ review_link }}` on a Customer or Job automation = the site's `review_link`
  page + `c=<token>` (HMAC of the customer id, `CustomerLinks`).
- `{{ membership_join_url }}` on a Customer automation = a signed, 60-day join page
  (`GET/POST v1/memberships/join/{customer}`), Blade, lists active plans, one click joins.
- `POST v1/web/review` {key (site public key), rating happy|sad, c?, phone?, email?, comment?}
  → finds the customer by token, else by phone (last 10 digits) or email, and raises
  `customer.reviewed` (payload rating, comment, source, site, membership_join_url).
  Always answers `{"ok":true}`, matched or not.
- The offer itself is an automation: `customer.reviewed` where rating = happy →
  SMS / WhatsApp / email to "The customer" with `{{ membership_join_url }}`.

Web Management owns the widget change: pass `c` from the page URL, and with no `c`
ask for phone or email on the happy face, then POST `v1/web/review`.

## Still to do

1. **Screens** — UI/UX designs and builds Dashboard, Members, Settings.
2. **Confirm the next visit** — the reminder carries a link; a chosen date
   books a job (Jobs); no answer in N days leaves staff a follow-up.
3. Payments arrive only by staff typing `paid_minor` until there is a payments ledger.
