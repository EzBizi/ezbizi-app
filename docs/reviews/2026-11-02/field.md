# Field — review, 2026-11-02

Measured on production at 2026-11-02 (tinker on the app box, read only). I did not open the screens in a
signed-in browser this pass, because I can't sign in as Jose. Every line below comes from production data or
the client issue log, not from memory.

## 1. Working

- **Tracking:** 8,787 location points in the last 7 days. There are 19 people with a presence row, and 5 of
  them reported in the last 15 minutes.
- **Tracking mode:** the organization is set to `scheduled` (`field.tracking_mode`).
- **Geocoding:** 6,659 addresses are cached, all `ok`. Of the 649 jobs from yesterday to 14 days ahead,
  647 have a pin; 2 don't yet (the sweep geocodes 50 a minute), and 0 have no address.
- **Alerts:** the sweep raises them. In the last 30 days there were 164 lost-signal alerts and 106
  stopped-too-long alerts. No failed queue jobs touch Field in the last 7 days.
- **Map roles:** "Roles shown on the map" is set on one company (`field.map_roles`, one role), so the
  People list is filtered as Jose asked.
- **Safety recordings:** shipped, and off by default. There are 0 recordings because no company has
  turned the feature on.
- **Coverage map:** the closest-office, coverage, geocode-ZIP, tiles and attribution endpoints are live
  for Leads › Stats.

## 2. Broken

- **Alerts nobody closes.** All 270 alerts from the last 30 days are still open; not one was acknowledged.
  - Why: a lost-signal or stopped alert stayed open after the phone reported again or the person moved
    on. "Open alerts" counted things that had already fixed themselves, so the number meant nothing
    and people stopped looking.
  - Fixed in **3db9305d**: recovery now closes the alert itself, with `acknowledged_by` left null. Open
    alerts are now only the ones still true.
  - The 270 already open on production stay open; closing them would be a data rewrite, and I'm asking
    Jose in section 5.
- **Android "startForeground() not allowed"** (1 occurrence, version 0.0.0461). Android 12+ refuses to
  start the location service while the app is in the background. Fixed in **169780b0**: sharing starts
  only while the app is on screen.
- **Phones on old builds.** 11 of 19 phones still run 0.0.0461 (8 iOS, 3 Android), so they don't have
  169780b0, the alert fix, or device details. 2 presences send no platform at all. The cause is
  updates, not code.
- **`/field/me` and `/field/locations` timeouts.** There are 24 "could not reach the server" reports in
  14 days, usually after 43 to 105 seconds and once after 74 minutes. These are phones losing signal in
  the field. The tracker already batches points and resends them, so no data is lost; the reports are
  noise. Suggestion 3 below.

## 3. Cohesiveness (for UI/UX)

- The Field Dashboard alert list has its own Acknowledge button style. Other modules' attention rows
  are on the main Dashboard glance.
- The Map and Route date controls (◀ date ▶ Today) are Field's own. A shared date bar would match the
  Scheduling and Jobs day pickers.
- The Map's status colours (green, red, yellow, blue) come from `EzBiziTheme.field*`, not the app's tone
  colours. Jose asked for them, but no other module uses them.

## 4. Suggestions (ranked)

1. **Show the app version on Field › Map's person bar in red when it's behind.** Managers would see who
   needs to update; 11 of 19 phones are behind today.
2. **Show "Closed by itself" in the alert list** when `acknowledged_by` is null, so a manager can tell
   it apart from "Acknowledged by Ana".
3. **Stop logging lost-network timeouts from the phone tracker to client issues** (keep real errors).
   That removes about 24 noise rows every two weeks, so real failures stand out.
4. **Raise the geocode budget from 50 to 200 a minute for a one-off backfill** when a branch is imported,
   so new jobs get pins in minutes instead of hours.
5. **Recordings:** they stay unused until a company turns them on. A one-line nudge in Field › Settings
   would make the feature findable.

## 5. Needs Jose

- Should I close the 270 alerts from before this fix that already fixed themselves? (Lost signal where the
  phone has reported since, and stopped where the person has moved since.) I'd take a dump first, then
  close them with `acknowledged_by` left empty.
- Do you want to turn safety recordings on for any company? They're off everywhere, which is the default
  you approved.
