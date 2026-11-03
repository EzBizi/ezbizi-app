# Android app — review 2026-11-02

Owner: Android · Play session. Measured on production 2026-11-02, box release
20261030222016 (server 0.0.0513), Play Console, and the prod database.

The emulator is PIN-locked after a reboot, so there was no hands-on pass today.
Every "Working" line below cites the date and build it was last driven on the
emulator against production.

## 1. Working

- **Play listing live.** Production track Active, 0.0.0502 (versionCode 502), 178 countries, 8 installs. Last published Oct 29.
- **Stability.** 0 user-perceived crashes and 0 ANRs on 502 (Oct 29 – Nov 1, Play release dashboard).
- **Push registration.**
  - `device_tokens`: 45 android rows for 9 users.
  - 10 refreshed in the last 7 days; latest 2026-11-02 14:51.
  - FCM sender prunes a token on 404/NOT_FOUND (`PushSender.php:247`).
- **Push tap opens the exact record.** Measured 10-12 on 0.0.0411 against prod:
  - warm (app running): ae7bcc8f;
  - cold (app closed): with ba643ca8.
- **Urgent channel.** `ezbizi_urgent` (HIGH) for alerts, leads and missed calls, beside `ezbizi_notifications` (DEFAULT). Compiled and shipped (a1546ab7).
- **Calls (Twilio voice).**
  - `phone_device_registrations`: 9 android, last seen 2026-11-02 14:10.
  - FCM token handed to twilio_voice via MainActivity (66ce7bcf).
- **Field location.**
  - Foreground service plus "EzBizi is sharing your location" notification; the Play FGS declaration was approved.
  - Fallback works when Location Accuracy is off.
  - Asks for permission only in the foreground, once per run (d69a8443).
- **OAuth connects from the app.** Opens the system browser and returns to the app; measured 10-09 to 10-29:
  - Pinterest, YouTube (youtube.upload + readonly), Gmail (gmail.modify).
  - Publish to YouTube and Pinterest (sandbox) succeeded.
- **Monitoring.** 48 android devices in `monitored_devices`; the most recent checked in 2026-11-02 14:52.

## 2. Broken

1. **Play is 11 versions behind the server (502 vs 513).**
   - Evidence: Play Console Production track "Latest release: 502"; Publishing overview shows no pending changes.
   - WHY: `release.sh` (§3 android) builds the AAB into `Distribution/Android/` and stops. There is no Play upload step, while iOS uploads to TestFlight on every release. Jose chose "You drag the file once" (Sep), so each Play release waits on a manual upload that nobody is scheduled to do.
   - Phones installed from Play miss every client fix since 10-29.
   - Not fixed: needs Jose (Q1).
2. **No minimum version is enforced on any platform.**
   - Evidence: `platform_minimum_client_versions` is empty on prod.
   - The 426 "update required" gate exists in the client and server but is inert. A phone on any old build keeps working against new server behaviour.
   - Not fixed: setting a minimum strands users until Play has the build (Q1, Q2).
3. **The app version a device runs is not recorded.**
   - Evidence: `monitored_devices.specs` holds no app version for any of the 12 android rows this week, and the user agent is just `Dart/3.11 (dart:io)`.
   - We cannot tell which build the 8 Play installs or the 9 field users are on. Without that, (2) cannot be set safely.
   - Fix is mine plus Monitoring's: send `X-EzBizi-Client-Version` into specs. Proposed in §4; not done today because it touches Monitoring's writer.
4. **Email connect shows "Could not reach the server" after a successful Google sign-in** (seen 10-29, 0.0.0503).
   - The inbox is connected server-side (`status=connected`).
   - Owner: Email. Reported 10-29.

## 3. Cohesiveness

- Android-native surfaces only:
  - notification channel names ("EzBizi notifications", "EzBizi urgent");
  - the Field FGS notification ("EzBizi is sharing your location");
  - permission rationale.
- These read consistently with the rest of the app.
- Screen layout is the shared Flutter UI; UI-UX owns it. No Android-only divergence seen.
- One difference: the in-app update nudge exists for desktop (download URL) but Android has no "new version on Play" hint. See §4.

## 4. Suggestions (ranked)

1. **Automate the Play upload.**
   - Use a Play Developer API service account, or a Gradle Play Publisher task, to the internal or production track from `release.sh`.
   - Payoff: Android gets every release the same day, like iOS TestFlight. Removes the 11-version gap.
2. **Record the client version per device.** Send it in the existing monitoring/ping payload.
   - Payoff: see who is stale before raising a minimum; makes the 426 gate usable.
3. **Set an Android minimum one release behind current, once (1) and (2) exist.**
   - Payoff: old phones can't silently misbehave against new APIs.
4. **Enable R8 shrinking** (Play's recommended action on 502).
   - Payoff: smaller download and lower memory on field phones.
5. **Prune stale push tokens by age**: delete `device_tokens` not re-registered in 60 days.
   - 35 of 45 android rows are older than 7 days.
   - Payoff: fewer wasted FCM calls; cleaner device counts.
6. **Add a soft "update available" hint on Android** linking to the Play listing.
   - Payoff: users update without a hard block.

## 5. Needs Jose

1. **Play uploads.**
   - Option A: grant a Play Console service account (Release manager) so every release uploads automatically.
   - Option B: keep manual uploads, and agree who drags the AAB and how often.
2. **Minimum Android version.** Once Play is current, should Android below the previous release be blocked with "update required"?
3. **Test video cleanup.**
   - The YouTube review upload is live on @ezbiziapi.
   - Your personal Gmail is still connected as a test inbox in Ace Fireplace.
   - Remove both after Google's review?
