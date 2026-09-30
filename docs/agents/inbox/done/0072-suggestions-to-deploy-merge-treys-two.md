---
from: suggestions (session "EzBizi - New Suggestions", local_c7e8ae8e-5b4b-4ee3-bfd4-dea0339a5078)
to: deploy (local_072bee23-4a25-4361-9335-c7a59fcfff11)
date: 2026-09-30
subject: Merge session/suggestions @ a76b51eb (NOT MERGED) and ship it
needs: merge + release
---

Jose asked for this on 2026-09-30: *"implement trey's suggestions"*.

`session/suggestions` @ `a76b51eb` is NOT MERGED. It branches from main `2abb556c`, which already carries 85992de9.

**Work Hours correction requests**

- New migrations:
  - `2026_09_30_200000_create_time_clock_correction_requests_table`, a new table.
  - `2026_09_30_200100_work_hours_correction_request_copy`, which inserts 9 `ui_strings` keys in en and es.
- New endpoints:
  - `GET` and `POST /time-clock/corrections` (self).
  - `GET /hr/time-clock/corrections`, `POST .../{id}/approve` and `POST .../{id}/deny`, all under `work_hours.manage`.
- `GET /hr/time-clock/week` now also returns `correction_requests`, only for holders of `work_hours.manage`.
- Approving calls `TimeClock::correct` as the approver.

**Training video fullscreen on Windows**

- In `client/lib/screens/training/video_embed_native.dart`, the player's Fullscreen now makes the window fullscreen and draws the video over the app.
- This is not verified on a real Windows build. Only analyze and unit tests have run on it.

**Gate on this tree**

- TimeClock suites plus the privacy and inventory suites: 175 passed on a76b51eb's server code.
- The new TimeClockCorrectionRequestTest: 7 passed.
- Full client suite and `flutter analyze` are clean. The theme guard is green.
- Full backend run: 5139 tests, 16 failed. One was mine, ClientCallsRealRoutesTest, and it is fixed in a76b51eb and green. The other 15 are this machine's: ErrorAuditTest file_scope_closure, and 14 IMAP tests that need a local TLS certificate.

Release notes (for you to write, per fbdc1efa):

- Work Hours: ask for a correction to one of your own shifts. Whoever manages Work Hours approves or declines it, and you are told either way.
- Training: Fullscreen on a video fills the screen on Windows.
