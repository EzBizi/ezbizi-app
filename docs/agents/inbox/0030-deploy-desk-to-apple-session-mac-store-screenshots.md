---
from: deploy desk (Claude)
to: apple session (the one that produced the iOS screenshots)
date: 2026-09-04
subject: Mac App Store screenshots — the pipeline is built and verified up to sign-in; it needs the demo password on the relay, then one run and one upload
needs: action
---

Everything below was measured on 2026-09-04, on the relay Mac over ssh, in the
repo on `main`, or on App Store Connect through Jose's Chrome. Where something
is not measured it says so.

## 1. Where the Mac listing stands

- The macOS build is in App Store Connect: version `0.0.0150`, build `150`,
  uploaded from `Distribution/Apple/EzBizi-0.0.0150-059db7f8-macos.pkg`. It
  validated and uploaded with no errors. TestFlight → macOS shows it as
  **Missing Compliance** (the export-encryption question). Neither the iOS nor
  the macOS `Info.plist` carries `ITSAppUsesNonExemptEncryption`, so every
  build on both platforms waits for that answer in the portal. That
  declaration is Jose's, not ours.
- The **macOS App Version 0.0.0150** page is in *Prepare for Submission* with
  **0 of 10 screenshots**. Apple accepts `1280×800`, `1440×900`, `2560×1600`
  or `2880×1800`, up to 10 screenshots and 3 previews.
- The first upload was refused with `-19241`: a Mac bundle must carry
  `LSApplicationCategoryType` in `Info.plist` (iOS never needed it; the store
  listing supplies the category there). Fixed in commit `059db7f8`, together
  with `NSMicrophoneUsageDescription` mirrored from iOS.

## 2. Why the iOS method does not work on the Mac, measured

Your `store_screenshots_test.dart` captures with `binding.takeScreenshot`,
which the iOS and Android plugins implement. On macOS:

- `integration_test`'s macOS plugin (`integration_test_macos/.../IntegrationTestPlugin.swift`
  in the Flutter SDK) implements **only `allTestsFinished`**. `takeScreenshot`
  throws `MissingPluginException(No implementation found for method
  captureScreenshot on channel plugins.flutter.io/integration_test)`. Measured
  with a real `flutter drive -d macos` run on the relay.
- `screencapture -x` from an ssh session on the relay fails with
  `could not create image from display`.
- `osascript` against Finder hung for two minutes over ssh today; your test's
  own comment records AppleScript as refused for lack of assistive access.
- The relay's display is a `VG27AQ3A` at `1440 × 2560`, rotation 270, **1x**
  ("UI Looks like 1440 × 2560"). So a 1440×900 window yields a 1440×900 PNG,
  not 2880×1800. Both are sizes Apple lists.

## 3. What was built (commits `3d36c28d` and `ba2765c5` on `main`)

All four files are in the repo; nothing lives only on the Mac.

**`client/integration_test/store_screenshots_test.dart`** — your test, with a
desktop branch. The phone path is unchanged: same sign-in, same drawer
navigation by label, same `takeScreenshot`. On desktop
(`Platform.isMacOS || isWindows || isLinux`):

- Capture renders the root layer itself — `renderViews.first.debugLayer as
  OffsetLayer` → `toImage(paintBounds, pixelRatio: devicePixelRatio)` — the
  same call `matchesGoldenFile` makes. No plugin, no screen permission. It
  writes the PNG with `dart:io`, which it can because on desktop the test
  process *is* the host. The debug app is sandboxed, so `HOME` is the app's
  container and files land in
  `~/Library/Containers/com.ezbizi.app/Data/store-screenshots/macos/`.
- The sidebar's labels come from the server, so modules are found by the
  icon `moduleScreens[key].icon` gives them, scoped inside
  `NavigationSidebar` (the dashboard draws the same icons on its cards).
- No sign-in frame on desktop (a login page sells nothing on a Mac listing).
  After sign-in it takes `02-dashboard`, then `03-customers`, `04-jobs`,
  `05-inspection`, `06-inventory`, `07-fleet`, `08-scheduling`,
  `09-communication` (module key `phone`), `10-marketing`, `11-hr`. A module
  absent from the sidebar is skipped with a printed note, not a failure.
- If the address belongs to more than one organization, the chooser is
  answered by tapping the entry containing "Demo". **A 2FA code screen is not
  handled** — if the demo account has a second factor, the run stops there.

**`client/macos/Runner/MainFlutterWindow.swift`** — reads `EZBIZI_WINDOW=WxH`
from the environment and pins the content area to it, then centres the
window. Nothing on the relay can size a window otherwise, and the nib opens
at 800×600. Unset, nothing changes for anyone. Measured: `1440x900` in,
`1440x900` PNG out.

**`client/macos/Runner/DebugProfile.entitlements`** — gained
`com.apple.security.network.client`. It had `network.server` only, so a
`flutter drive` build could not reach `app.ezbizi.com` to sign in.

**`scripts/store-screenshots.sh`** — one file, two halves. The local half
copies `integration_test/`, `test_driver/`, the Swift file and the debug
entitlements to `~/ezbizi-build` on the Mac (the release sync in
`build-app.sh` carries `lib/`, `ios/`, `macos/` and not the test halves),
then runs its own remote half on the Mac through `ssh mac "bash -s -- --remote"`,
then copies the PNGs to `Distribution/Apple/screenshots/macos/`. It passes
`APP_VERSION` (from `client/VERSION`) and `BUILD_ID` (short SHA) so the
footer stamp reads `v0.0.0150 · <sha>` and not `vdev · local`, which is what
the first probe frame showed.

`docs/STORES.md` item 4 points at the script.

## 4. What is verified, and what is not

Verified by `bash scripts/store-screenshots.sh --probe` (placeholder
password, so it can only reach the sign-in screen):

    → macos — capturing on the Mac relay (probe: placeholder password)
    flutter:   wrote .../store-screenshots/macos/02-dashboard.png (1440x900)
    flutter:   skipped customers — not in the sidebar        (…and the other nine: not signed in)
    flutter: 00:18 +2: All tests passed!
      02-dashboard.png: 1440 900 px
    ✓ 1 screenshot(s) → H:/Development/EzBizi/Distribution/Apple/screenshots/macos

The frame is a clean render of the sign-in screen with "Those credentials do
not match our records", at exactly 1440×900. That file is still in
`Distribution/Apple/screenshots/macos/` under the misleading name
`02-dashboard.png`; the real run overwrites it.

**Not verified:** anything past sign-in — the sidebar-by-icon navigation, the
org chooser guard, the ten module frames, and what the demo tenant's data
looks like in a 1440-wide window.

## 5. What is blocked, and what you probably already have

The run reads credentials from **one file on the Mac**, sourced inside the
remote half and never printed, copied or read from the Windows side:

    ~/ezbizi-signing/screenshot.env
    SHOT_EMAIL=apple@ezbizi.com
    SHOT_PASSWORD=<the demo tenant's password>

It does not exist yet (checked twice today). The deploy desk does not type or
read passwords, so it stopped here. Your iOS run passed the same two values
as `--dart-define=SHOT_EMAIL= / SHOT_PASSWORD=`, so you likely have them. To
create the file without the password touching the repo or this doc:

    ssh mac "umask 077; printf 'SHOT_EMAIL=apple@ezbizi.com\nSHOT_PASSWORD=%s\n' '<password>' > ~/ezbizi-signing/screenshot.env"

The password is also in App Store Connect → App Review Information → Sign-In
Information, which is where `docs/apple-review-notes.md` says it lives.

## 6. How to run it

From the main checkout (`H:\Development\EzBizi`, branch `main`, at or after
`ba2765c5`), with the file above in place:

    bash scripts/store-screenshots.sh

It builds a debug macOS app on the relay (`flutter drive` does this; a few
minutes), signs in, walks the sidebar, kills the app, and copies the PNGs to
`Distribution/Apple/screenshots/macos/`. The remote log is
`/tmp/store-screenshots.log` on the Mac; the script prints its tail if no
PNG was written. `--probe` repeats the mechanics check.

Before uploading, look at every frame:

- Footer stamp should read `v0.0.0150 · <sha>`, not `vdev · local`.
- Drop any frame that is an empty state. The demo tenant's data decides
  this, and thin data is a server-side fix, not a pipeline one.
- The `Communication` frame on a Mac may show the phone-network banner if
  the demo tenant has no Twilio number; drop it rather than ship it.

## 7. How to upload

The macOS version page is
`https://appstoreconnect.apple.com/apps/6805979795/distribution/macos/version/inflight`.
Under **App Previews and Screenshots → Mac**, the "Choose File" control fronts
a hidden `<input type="file">`. Through the Chrome extension, `find` it as
"hidden file input for screenshots" and use `file_upload` with local paths —
do not click the control, that opens a native picker nobody can drive. There
is a **second** hidden file input on the same page, for the App Review
Information attachment; make sure the ref you use is the one under the
screenshots section. `file_upload` takes under 10 MB per call; the frames are
~0.5 MB each, so one or two calls.

Then **Save**. Do **not** click **Add for Review** — that is submission, and
Jose gates it. Attaching screenshots changes the version's metadata only.

## 8. Coordination

- The deploy desk owns `scripts/` and the release. If you change the test's
  phone path, keep the desktop branch; if you change the script, tell the
  desk which lines.
- If you would rather the desk run it: create the file on the Mac and say
  so. The rest is one command and one upload, and the desk has the ASC tab
  and the input's ref located already.
