# Getting EzBizi into the app stores

Console work, in the order it has to happen. Same spirit as MAIL-SETUP.md: the
steps that cannot be done from this repo, written down before they are needed so
nobody rediscovers them under pressure.

Decided: EzBizi ships through the **public** stores, and buys **no code-signing
certificate**. That is not a compromise — a self-hosted MSIX would need one, but
a Store-published build is signed by Microsoft; Play App Signing means Google
holds the Android key; Apple issues its own through the Developer Program. The
A1 Chimney YubiKey is not used here and should not be proposed.

The cost is accounts and review latency, not certificates.

---

## Do this first, before any submission

### Fix the bundle identifiers

They are still `flutter create` defaults and they do not match each other:

| Platform | Current | Where |
|---|---|---|
| Android | `com.ezbizi.ezbizi_client` | `client/android/app/build.gradle.kts` |
| iOS/macOS | `com.ezbizi.ezbiziClient` | `client/ios/Runner.xcodeproj/project.pbxproj` |

**A bundle identifier is permanent.** Once a build carrying one is accepted, it
is the app's identity forever — you cannot rename it, and shipping under a new
one means a new listing that existing installs will never update to. So this is
a five-minute edit now and an unfixable annoyance later.

Pick one and use it everywhere. `com.ezbizi.app` reads correctly in a store
listing and does not contain the word "client", which is an implementation
detail nobody outside this repo should see.

Display names need the same pass: Android's label is `ezbizi_client` and iOS's
`CFBundleDisplayName` is `Ezbizi Client`. Both should be **EzBizi**, spelled with
the capital B, which is the product's name.

### Have a demo account ready, and keep it alive

Apple and Google both reject an app a reviewer cannot get inside, and EzBizi is
sign-in-only. Submit working credentials with representative data, and make sure
that account does not lapse — a reviewer landing on an expired subscription is a
rejection, and the appeal costs more days than the fix.

Give it a real organization with a couple of companies, some customers and some
jobs. An empty account looks broken to somebody who has never seen the product.

### Have the public URLs ready

Every store asks for these and will not accept a placeholder:

- a privacy policy at a stable public URL
- a support URL or support email
- a marketing URL (the site is enough)

---

## Apple — iOS and macOS

One Developer Program membership covers both. Currently **$99/year**.

**Enrol as an organization, not an individual.** Individual enrolment is faster
but the seller name on the listing becomes a person's legal name, which is wrong
for a product sold to businesses. Organization enrolment requires a **D-U-N-S
number** for the legal entity — free from Dun & Bradstreet, but it can take days
to a couple of weeks to issue or correct. **Start this first**; it is the longest
lead time in this document and everything Apple-shaped waits behind it.

Then, in App Store Connect:

1. Register the bundle ID (the fixed one, from above).
2. Create the app records — iOS and macOS are separate records sharing one ID.
3. Fill in the privacy questionnaire. EzBizi handles customer contact details and
   staff personal data, so answer it against `HoldsPersonalData` and the data
   inventory rather than from memory.
4. Screenshots at the required sizes, for each device class.
5. Upload a build. Builds come from the Mac relay — see `scripts/build-app.sh`,
   which currently builds `--no-codesign`. Signed builds need the team and a
   provisioning profile installed on the relay first.

**TestFlight** is the way to get it onto a real phone before any public listing,
and is worth using well before submitting. Builds expire after 90 days.

**Apple Business Manager custom apps** were considered and set aside when the
answer was "public". Worth remembering it exists if a customer ever wants a
private build.

## Google — Android

Play Console, currently **$25 one-time**.

**Register as an organization.** Beyond the same seller-name reason as Apple,
Google applies extra requirements to *personal* developer accounts created in
recent years — a closed test with a minimum number of testers running for a
minimum period before production access is granted. Organization accounts are not
subject to it. Confirm the current rules at signup; this policy has changed more
than once.

1. Create the app, choose the package name (permanent, as above).
2. Turn on **Play App Signing** — Google holds the upload key and re-signs. This
   is what removes the certificate requirement.
3. Complete the **Data safety** form. Same source as Apple's privacy answers;
   they must agree with each other and with what the app actually does.
4. Content rating questionnaire, target audience, ads declaration (none).
5. Internal testing track first, then closed, then production.

Once shipping, add the **In-App Updates API** so an outdated app can prompt the
user without leaving the app. That is the Android complement to the server-side
version gate — the gate says *you are too old*, the API is how they fix it.

## Microsoft — Windows

Partner Center, one-time fee (individual and company tiers differ; company
requires verification). Verification can take a few days.

1. Reserve the app name.
2. Package as **MSIX**. Flutter does not do this itself — the `msix` pub package
   is the usual route, configured in `client/pubspec.yaml`.
3. Submit unsigned; Microsoft signs on ingestion.

To test an MSIX locally before submitting you need a self-signed certificate and
to trust it on the machine. That is a development convenience, not a purchase,
and it never leaves the build machine.

---

## What the server already does about all this

`scripts/build-app.sh` builds every non-web target and stamps each with the
version from `client/VERSION`, the commit, and a `+` if the tree was dirty. The
Apple targets go through the Mac relay in one session.

The client sends its platform and version on every request, and the server holds
a **minimum version per platform**, set from the control plane. An outdated app
is answered with HTTP 426 and a plain sentence rather than failing strangely.

The minimum is per platform on purpose: Windows can ship a fix in minutes while
iOS sits in review for days, so one global number would lock out every iOS user
the moment Windows moved. The gate also **fails open** — no version header, an
unparseable version, an unconfigured platform all pass — because the blast radius
of failing closed is every customer at once.
