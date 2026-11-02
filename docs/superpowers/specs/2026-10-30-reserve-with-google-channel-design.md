# Reserve with Google: the "Book online" channel

| | |
|---|---|
| Date | 30 October 2026 |
| Status | Draft. Waiting for Google to accept the partner application |
| Asked by | Jose, 2026-10-30, via the desk: "EzBizi as a Book online provider on Google profiles and ads, like Precision Garage Door with schedule.street2fleet.com" |
| Depends on | `2026-09-01-online-booking-design.md` (the booking page, live as the website plugin's `/booking/` page) |

---

## 1 · What this is

A **Book** button on a merchant's Google Business Profile, in Maps and in Google Ads, that opens **our** booking page for that merchant. Google calls this the Actions Center **Appointments Redirect** integration. Google hosts nothing. It sends the customer to a URL we give it, and we report the booking back so Google can measure conversions.

Today A-1's Reserve with Google bookings arrive through Workiz (1d201c37e, 615ebb96e). That keeps working until each merchant is switched over (§7).

## 2 · Where it stands (2026-10-30)

- **Partner interest form submitted.** Jose submitted it on 10-30 for EzBizi LLC: platform/aggregator, Home Services, 43 locations, contact info@ezbizi.com. Google's reply: "A member of the team will be in touch shortly." Nothing below can go live until Google opens a **Partner Portal** account for us.
- **The booking page exists:** `https://<merchant site>/booking/` (WordPress plugin), backed by `GET v1/web/booking/days`, `GET v1/web/booking/lead` and `POST v1/web/booking`. The setting `leads.booking_url` holds each branch's page. 14 branches have one.
- **The listings exist:** `business_locations`, synced from GBP v1. That covers every connected, published listing.

## 3 · What we build

### 3.1 Feeds (daily, SFTP)

Google wants three feeds, uploaded every 24 hours as JSON plus a fileset descriptor to `sftp://partnerupload.google.com:19321`, using the "Generic" account from the Partner Portal. The sandbox comes first; production follows once Google signs off on the sandbox feeds.

| Feed | Descriptor | One row per | From |
|---|---|---|---|
| Entity | `reservewithgoogle.entity` | merchant location | `business_locations`: name, phone, address, plus a stable `entity_id` (the listing's uuid) |
| Action | `reservewithgoogle.action.v2` | merchant (and service) | the branch's `leads.booking_url` + `?gl=<entity_id>` |
| Services | `glam.service.v0` | bookable service per merchant | the booking page's service list, the same one `booking/days` offers |

The feeds only include listings that (a) are published and verified, (b) belong to a branch whose `leads.booking_url` is set, and (c) whose branch `bookableOnline()` holds. A page that can offer no day is worse than no button (Bar, 2026-10-21).

**Google's review compares our merchant data with Maps.** Our data comes from the GBP API itself, so name, address and phone match by construction.

Code:
- `ezbizi:rwg-feeds {--sandbox}`: builds the three files and uploads them, scheduled daily.
- The SSH key goes on the box as a credential (`RWG_SFTP_*` in `.env`, never in git). Its public half goes to the Partner Portal.

### 3.2 The landing (rwg_token)

Google appends `rwg_token=<opaque>` to our action link. The booking page:
1. reads `rwg_token`, and stores it with the merchant (`entity_id`) for **30 days** in a first-party cookie (`_rwg`) on the merchant's site. A new referral replaces it.
2. passes it to `POST v1/web/booking` along with the booking.

Server side, the booking stores `rwg_token`, `rwg_entity_id` and `rwg_at` on the lead/job it creates. The lead's source shows as **Google · Book online**.

### 3.3 Conversion tracking

When a booking that carries an unexpired token is **created**, send one request:

```
POST https://www.google.com/maps/conversion/collect        (sandbox: /maps/conversion/debug/collect)
Content-Type: text/plain
{"conversion_partner_id": <ours>, "rwg_token": "<decoded>", "merchant_changed": "2"}
```

Notes:
- `merchant_changed` is always `"2"`: the page books only the merchant it was opened for.
- No personal data goes in the request.
- Sent from the server (queued job, retried) rather than the browser. The plugin page is on the merchant's domain, and the server is the one place that knows the booking really happened.

### 3.4 Admin

There's no new screen in v1. The Business Profile listing row shows "Book on Google: on / off / why not" (from the feed's rules above). That surface goes through UI-UX before it's built.

## 4 · Certification (Google's steps)

1. Setup: Partner Portal access, SFTP key, contact info.
2. Feeds in sandbox, reviewed by Google.
3. Conversion tracking in sandbox (`debug/collect`) with test tokens.
4. Integration review: Google tests real action links end to end.
5. Production feeds, then launch.

Google quotes 4–8 weeks to launch merchants once integration starts.

## 5 · What this is not

| Not building | Why |
|---|---|
| End-to-end booking (Google hosts the form) | Redirect needs no availability API from us. End-to-end means real-time slot APIs and a much longer certification. Revisit if redirect performs. |
| Payment or deposits | Same as the booking spec: nothing in EzBizi takes money. |
| Our own Google Ads "Book" extension | Ads gets the button from the same Actions Center link once the profile has it (the merchant's Ads account must be linked to the profile). |

## 6 · Risks

- **Approval is Google's call** and can be slow or refused. Nothing in §3 is wasted if it's refused: the token capture is a few lines and the feed command is the only sizeable piece. So build only after the Partner Portal invitation arrives.
- **One Book button per profile.** If Workiz (or anyone else) is also a Reserve with Google partner for a listing, Google picks one. Each merchant's switch-over is Jose's decision (§7).

## 7 · Switch-over per merchant

Once we're in production, each branch moves only when Jose says so:
1. Put the listing in our feed.
2. Confirm the Book button on Maps opens our page.
3. Turn off the Workiz booking link for that profile.

Workiz's Reserve with Google path stays in place for branches that haven't moved.
