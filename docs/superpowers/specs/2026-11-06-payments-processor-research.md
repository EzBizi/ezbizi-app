# Payments — processor research and plan (2026-11-06)

Owner: **EzBizi - Money** (`local_92bd89ae-d31c-42af-9c5f-c66794afc829`). Jose,
2026-11-06: *"You will be in charge of Payment Processing and everything that has
to do with payments."*

## What EzBizi needs

A business on EzBizi (A-1 Chimney, each franchise) bills **its own** customers and
gets paid into **its own** bank. EzBizi is the software in between, not the
merchant. That is a *platform* integration (Stripe calls it Connect, others call it
payfac-as-a-service), not a plain merchant account. Needs, in order:

1. Pay an EzBizi invoice online — card and ACH — from the link already sent by
   email / text / WhatsApp (`4752c2269`).
2. Webhook writes the `invoice_payments` row (`provider`, `provider_reference`,
   `fee_cents`, `payout_id` already exist, migration `2026_10_21_201530`) and flips
   the invoice to `paid`. Refunds as `kind = refund`.
3. Technician takes a card on site — Tap to Pay on the phone, no reader.
4. Later: deposits on estimates, saved cards for memberships (recurring), payouts
   reconciliation in Financials, an optional EzBizi fee per payment.

## What already exists

| Thing | State |
|---|---|
| Invoices, lines, payments, PDFs, send, overdue (`InvoicesController`, `Invoice*`) | **On main.** Payments are recorded by hand (`cash/check/card/transfer/other`). |
| Stripe sandbox *EzBizi LLC* `acct_1UQCOZRmexT3zkiV`, `development@ezbizi.com` | Works with the test keys in `Credentials\Stripe`. **Live account not activated** (`details_submitted: false`). |
| Sandbox webhook → `https://app.ezbizi.com/api/v1/payments/stripe/webhook` | Enabled; checkout.session.*, account.updated, charge.refunded. Route does not exist on main. |
| Branch `session/payments` (2026-10-13, 4 commits, +2797) | **Not merged, 3+ weeks stale.** Stripe Connect via Accounts v2, one connected account per company, Checkout as a direct charge with application fee, verified webhook, ACH async events, Flutter screen, "Request payment" on a job, 2 test files with real sandbox event fixtures. Built **before** Invoices, so it has its own `payments` table instead of writing `invoice_payments`. |
| Workiz | A-1's current collection runs through Workiz Pay (Stripe + Adyen underneath); `SyncWorkizWebPayments` reads it. Stays the backup per *EzBizi primary, Workiz backup*. |

## The candidates

US rates, measured from the vendors' pages on 2026-11-06 where marked ✓; others
are published list rates from secondary sources and need a quote.

| | Online card | In person | ACH | Platform model | Fit for EzBizi |
|---|---|---|---|---|---|
| **Stripe Connect** ✓ | 2.9% + 30¢ (keyed +0.5%) | 2.7% + 5¢; Tap to Pay +10¢ | 0.8%, cap $5 | Mature: hosted onboarding/KYC, Checkout, Tap to Pay SDK, application fees, 1099s | **Best now.** Housecall Pro and Workiz run on it. Sandbox and code already exist. |
| Square | 2.9% + 30¢ | 2.6% + 15¢ | 1%, min $1 | OAuth "apps" into the merchant's own Square account | Good POS and hardware; weaker as an embedded platform (we'd be an add-on to *their* Square, not the owner of the flow). |
| Adyen for Platforms | Interchange++ | Interchange++ | yes | Enterprise; minimums and sales-led onboarding | Too early. Workiz uses it at their scale. |
| PayPal / Braintree | ~2.59% + 49¢ | limited | yes | Braintree Marketplace is legacy; PayPal Complete Payments | Worse per-ticket on $200–$2,000 jobs; weaker field tooling. Keep "pay with PayPal/Venmo" as a Checkout method if Stripe adds it, not as the processor. |
| Finix / Rainforest / Payrix (payfac-as-a-service) | Interchange + markup you set | same | yes | **EzBizi becomes the payfac-lite**: sets merchant pricing, keeps the margin, carries the losses | The revenue play. Typical guidance: worth it at hundreds of merchants / tens of millions in volume. Revisit then; the `provider` column makes it a second gateway, not a rewrite. |
| Helcim | Interchange + ~0.4–0.5% | same | yes | No real multi-tenant platform product | Cheap for one merchant, wrong shape for a SaaS. |
| QuickBooks Payments / Authorize.net | ~2.9–3.5% | | | Gateway / single merchant | No. |

## Recommendation

**Stripe Connect, direct charges, one connected account per company, using our own
invoices (not Stripe Invoicing — that adds 0.4% for something we already built).**

Per invoice: a Stripe Checkout Session on the company's account (card + ACH,
`application_fee_amount` = 0 to start), its URL is the "Pay now" button in the
invoice email/text/PDF. `checkout.session.completed` /
`checkout.session.async_payment_succeeded` → `invoice_payments` row with the
`pi_`, the Stripe fee from the balance transaction, and the payout id when
`payout.paid` arrives. Same idempotency key per invoice, so a resent link never
double-charges.

### The one decision that is Jose's: who carries the losses

The branch was built on Jose's 2026-10-13 pick, **Express** (platform collects fees
and losses). Measured against Stripe's Connect page today:

| | Stripe handles pricing ("full"/Standard) | Express — EzBizi handles pricing |
|---|---|---|
| Fees to EzBizi | **$0** | $2 per active account/month + 0.25% + 25¢ per payout + 0.25% routing |
| Chargebacks / negative balances | Stripe and the business | **EzBizi** |
| Business's dashboard | Full Stripe Dashboard | Light Express dashboard, branded EzBizi |
| Can EzBizi take a fee per payment | Yes (application fee) | Yes, and set the business's rate |

With no paying tenants yet, **"full" carries no cost and no liability**, and it is
one config value on the branch (`services.stripe.connect_dashboard`). Recommend
"full" now and Express (or a payfac) once EzBizi wants to price payments itself.

## Build order

1. **Port `session/payments` onto Invoices** — keep `StripeGateway`, `PaymentAccount`,
   onboarding, webhook verification, fixtures; drop the separate `payments` table
   and write `invoice_payments`. Pay link on the invoice. Gate: Payments +
   Invoices + Automations suites (it touches `ModuleServiceProvider`).
2. Refunds from the invoice; `payout.paid` fills `payout_id`; fee shown in Financials.
3. Tap to Pay in the Flutter app (Stripe Terminal; no first-party Flutter SDK —
   platform channels to the iOS/Android SDKs). Apple Tap to Pay entitlement needed.
4. Deposits on estimates; saved cards for memberships.

## Blocked on Jose (cannot be done by a session)

- **Activate the live Stripe platform account** (EzBizi LLC): Stripe asks for the
  owner's SSN/DOB and the payout bank account. Every other field (EIN, address,
  website, MCC) can be pre-filled by a session.
- Each business's own onboarding (A-1 Chimney's owner, bank, tax id) happens in
  Stripe's hosted form from inside EzBizi, by that business.

## Sources

- [Stripe pricing](https://stripe.com/pricing), [Stripe Connect pricing](https://stripe.com/connect/pricing) — read 2026-11-06
- [Workiz Pay uses Stripe and Adyen](https://help.workiz.com/hc/en-us/articles/18055821794961)
- [Housecall Pro on Stripe](https://stripe.com/customers/housecall-pro)
- [Square payments pricing (developer)](https://developer.squareup.com/docs/payments-pricing), [NerdWallet: Square fees 2026](https://www.nerdwallet.com/business/software/learn/square-fees)
- [Rainforest: Stripe Connect alternatives for vertical SaaS](https://www.rainforestpay.com/blog/stripe-connect-alternatives-for-vertical-software-platforms-compare-embedded-payments-software), [Swipesum: payfac-as-a-service 2026](https://swipesum.com/insights/best-embedded-payments-payfac-as-a-service-companies)
