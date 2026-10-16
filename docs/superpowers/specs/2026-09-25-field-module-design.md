# Field module — design

Owner: Field session (`local_b832295d-a4fe-4933-89e7-27ee2803646c`). Asked for by Jose on
2026-09-25: *"GPS track a specific set of users as usual set on roles y permissions … so we
can track them for safety and compliance as well as be able to offer them the best routes
for their scheduled jobs."*

## Decisions (Jose, 2026-09-25)

| # | Question | Answer |
|---|---|---|
| 1 | When does a phone report? | The organization chooses in Field › Settings: **always**, **scheduled hours**, or **clocked in** |
| 2 | Map/route provider | **Google Maps Platform** (Geocoding, Routes, Map Tiles) |
| 3 | Route tab | **Suggest, never change.** Best order + drive times + late warnings + Navigate hand-off. `scheduled_for` is untouched. |
| 4 | History | Live position + **90-day trail** (3-month retention window), then swept |
| 5 | Automation triggers | **All four**: arrived/left a job, stopped too long, lost signal, SOS |
| 6 | Mechanism | **Free `geolocator` plugin, all rules on the server.** Upgrade path: Transistorsoft if iOS drops points in the field. |

## Permissions — module `field`

- Tabs: `field.dashboard.view`, `field.map.view`, `field.route.view`, `field.settings.view`.
- Actions: `view`, `tracked`, `manage`.
  - **`field.tracked` is "this role is GPS-tracked".** It is how "a specific set of users set on
    roles" is expressed: grant it to Technician, not to Office.
  - `field.manage` acknowledges alerts and edits Field settings.
  - **Administrators are tracked like any tracked role** (Jose, 2026-09-28: *"Always, like any
    tracked role."*). Administrator holds every permission implicitly, so it holds `field.tracked`, and
    it follows the organization's window like everyone else. The first version excluded
    administrators; Jose overturned that.
- **Who you can see** is reach AND current view: `ScopeReach::constrainToVisiblePeople` grouped,
  then `MonitoringView::people` (the top-bar view). This is the same pair Monitoring uses, so a company
  view never shows a sibling company's techs. You always see yourself.

## Settings — group `field`, scopes org → company → franchise

The value is resolved at the tracked person's place (first assignment), like Clock in's schedule.

| Key | Type | Default |
|---|---|---|
| `field.tracking_mode` | enum `always` / `scheduled` / `clocked_in` | `clocked_in` |
| `field.arrival_radius_m` | enum 50/100/150/250/500 | 150 |
| `field.stopped_minutes` | enum 10/15/20/30/45/60 | 20 |
| `field.lost_signal_minutes` | enum 5/10/15/30/60 | 15 |

`scheduled` reads the same work schedule Clock in uses (`TimeClock::scheduleFor` →
`WorkSchedule::windowFor`).

## Data

- `field_locations`: org, user, lat/lng `decimal(10,7)`, accuracy_m, speed_mps, heading, `recorded_at`
  (the device's instant, UTC), created_at. Index (user_id, recorded_at). Retention is
  `window(3, 'recorded_at')`.
- `field_presences`: one row per tracked user holding the evaluator's state. Columns: last point,
  `last_seen_at`, the `anchor_*` point where they last stood still plus `anchored_at`, `at_job_id`,
  `stopped_alerted`, `signal_lost_alerted`.
- `field_alerts`: kind `sos` / `stopped` / `signal_lost`, user, job?, lat/lng, `acknowledged_at`/`_by`.
  Retention is the same 3 months.
- `field_geocodes`: org, `address_hash` (unique per org), address, lat/lng, `status` (ok / not_found).
  Customer addresses are geocoded **once**, with no change to the `customers` table.

## Server flow

1. `GET /field/me` returns `{tracked, should_track, mode, reason}`. The phone polls it every 2 min and
   whenever the session changes.
2. `POST /field/locations` takes a batch of at most 100 points. **The server drops points outside the
   person's tracking window**, so the window is enforced on the server, not trusted to the phone.
   Accepted points are stored, then `FieldEvaluator` runs on the newest one:
   - within `arrival_radius_m` of one of **today's** jobs (`performed_by_user_id` = them) →
     `field.arrived_at_job` (subject: the Job) and `at_job_id` is set;
   - beyond 1.5 × radius of `at_job_id` → `field.left_job`;
   - has not moved more than 75 m for `stopped_minutes` while **not** at a job → one
     `field.stopped_too_long` plus an alert, re-armed on the next movement.
   - The subject of stopped, lost signal and SOS is the **FieldAlert**. It implements
     `CarriesAutomationScope` from the person's first assignment. With the User as the subject, a
     "notify" rule would have told every active user in the organization.
3. `ezbizi:field-sweep` runs every minute. For a presence whose `last_seen_at` is older than
   `lost_signal_minutes`, while the person should still be tracked, it raises one
   `field.signal_lost` plus an alert, re-armed on the next point. It also geocodes up to 50 addresses of
   jobs scheduled in the next 2 days.
4. `POST /field/sos` (`field.tracked`, carrying lat/lng) creates an alert, raises `field.sos` and
   **notifies straight away** every active user who holds `field.map.view` and can see that person.
   Audited as `field.sos`.
5. `POST /field/alerts/{id}/acknowledge` (`field.manage`) is audited.

## Read endpoints

- `GET /field/dashboard`: counts (tracked, reporting now, moving, stopped, at a job, silent), open
  alerts, and today's alerts.
- `GET /field/map`: latest position per visible tracked user. `?user_id=&date=` returns that day's trail.
- `GET /field/map/tiles`: a Google Map Tiles session, as a URL template for the client. It uses
  `flutter_map`, which unlike `google_maps_flutter` also runs on the Windows desktop.
- `GET /field/route?user_id=&date=` returns that person's jobs for the day, geocoded. It is ordered by
  Google Routes `computeRoutes` with `optimizeWaypointOrder`, starting from their latest position when it
  is fresh. Each stop carries drive minutes, ETA and a `late` flag against `scheduled_for`. The response
  is cached for 10 min per (user, date, job set). A job whose address will not geocode is listed as
  **unroutable**, not silently dropped.

## Client

A `field` screen shaped like Work Hours: Dashboard / Map / Route / Settings, each tab gated on its own
permission.

- Dashboard: tiles and alerts. A tracked person also gets the **SOS** button, with a confirmation.
- Map: `flutter_map` on Google tiles, one marker per person; tap a marker for that person's trail.
- Route: person and date pickers, an ordered stop list with drive times and late badges, and
  **Navigate**, which opens the Google Maps directions URL.
- Settings: `SettingsScreen(onlyGroup: 'field')`.
- `FieldTracker` runs on Android and iOS only. It holds `field.tracked`, obeys `/field/me`, streams
  `geolocator` with a 50 m distance filter plus a 2-min heartbeat while still, and batches uploads.
  On Android it runs a foreground service with a visible notification.

## Platform (store review)

- Sharing always *starts* while the app is open, so "while using the app" permission is enough
  on both platforms. **No `ACCESS_BACKGROUND_LOCATION`, and no "Always" prompt.**
- Android: `ACCESS_FINE/COARSE_LOCATION`, `FOREGROUND_SERVICE(_LOCATION)`, `WAKE_LOCK`. The stream runs as a
  foreground service with a visible notification. Play Console still needs the
  foreground-service-location declaration; that goes to the **Android** session.
- iOS: `NSLocationWhenInUse…`, plus `NSLocationAlwaysAndWhenInUse…` because the plugin links the API.
  `UIBackgroundModes: location` shows the blue indicator. Review notes go to the **Apple** session.
- Keys: `GOOGLE_MAPS_SERVER_KEY` (Geocoding + Routes, server-only, IP-restricted) and
  `GOOGLE_MAPS_TILES_KEY` (Map Tiles, handed to the client with the session). **Jose must create
  both in Google Cloud and put them in production `.env`.** Until then the Map shows positions on a blank
  grid, and Route orders stops by scheduled time and says why.

## Out of scope (YAGNI)

- Rewriting job times (decision 3).
- Geofences on the phone.
- Vehicle telematics (Fleet ruled GPS out).
- Customer-facing "tech is on the way" links. That is an automation on `field.arrived_at_job` or a
  later trigger.
- Mileage reports.

## Build order

1. Module, settings, migrations, models.
2. Ingest, evaluator and sweep, with tests.
3. SOS and alerts.
4. Read endpoints, geocoder and router, with the Google HTTP calls faked in tests.
5. Client screen and tabs.
6. Tracker, manifest and plist.
7. Gates, then hand-off to the deploy desk.

## Addendum 2026-09-30: dispatch on the map (Jose)

*"I would like to be able to see the jobs for the day … my dispatchers would use this if someone calls
asking for an ETA … a top bar … which would show phone battery, avg speed, app version, etc."*

- **Jobs on the map.** `GET /field/jobs?date=` returns the day's jobs in the current view, placed from
  stored geocodes (one query). It needs `jobs.view` on top of the Map tab, because customer names and
  addresses are Jobs' data. The sweep now geocodes every upcoming job, not only assigned ones.
- **ETA.** `GET /field/eta?job_id=` ranks the five nearest people reporting now by drive time (Routes
  `computeRouteMatrix`). Adding `&user_id=` gives that person's drive (`computeRoutes`) with the road as an
  encoded polyline, the minutes, an arrival time, and a sentence for the caller.
- **Technician bar.** `POST /field/locations` accepts an optional `device` object (battery %, charging,
  platform, OS version, model, app version), kept as the latest value on `field_presences`, with no
  history. `GET /field/map` adds the device plus average and top moving speed, clocked-in time, and next
  job.
- **Client.** The floating list has People and Jobs tabs, and Jobs is searchable by number or customer.
  Choosing a job opens the job card: the nearest people, then the route and a Copy button for the
  sentence. With a job open, tapping a person routes that person to it. Choosing a person opens the
  horizontal technician bar.

## Addendum, 2026-10-16: safety recordings (from A1 Tools)

The A1 takeover (desk, Jose's "go ahead" 2026-10-16) moves A1's technician
audio safety recording here. A1's design and its decisions with Jose
(2026-07-09) carry over: manual start, auto-stop on leaving the job and at
four hours, 90-day retention unless a manager keeps it, managers listen, the
technician hears their own, and a line to say to the customer in the notice
states.

- **Grants:** `field.record` (makes recordings), `field.listen` (hears other
  people's, within the people Field shows you). Keeping one past 90 days
  is `field.manage`.
- **Setting:** `field.recording_notice_states`, default `FL, CA, PA, IL, CT`
  (A1's list). `/field/me` returns `may_record` and `at_job {id, number, notice}`.
- **Server:** `field_recordings` + `field_recording_chunks`; gaps are a JSON
  list on the recording. Audio lives under `field-audio/{org}/{recording}/`
  on the default disk. `ezbizi:field-sweep` deletes expired recordings and
  their audio, and hourly clears audio whose rows were erased.
- **Phone:** `FieldRecorder` (client/lib/core/field_recorder.dart), owned by
  `FieldTracker.current`: one-minute AAC files with a JSON sidecar, sent with
  their sha256 and deleted once stored; anything left is sent on the next
  launch and closed as `recovered`.
- **Not carried over:** A1's `hard` enforcement (blocking clock-in without the
  microphone) — Clock-in is another module's, and nobody asked for it here.
  Android records only while the app is open: the background microphone
  needs a microphone-type foreground service, and declaring one on the
  location service would break tracking for anyone who refuses the
  microphone.
- **Layouts** (the record control, Field › Recordings) are UI/UX's.
