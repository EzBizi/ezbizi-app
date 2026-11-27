# Vehicle tracking and dash cams

Jose, 2026-11-16: "Connect FORD Car tracking & Dash Cams to EZBizi... we need to have the system ready for them."
Owners: Field (vans: tracking, map, framework) and Monitoring (dash cam UI next to Ring and Wyze).

## Providers (web research, 11-16; nothing tested with an account yet)

| Vendor | What | Access | Push |
|---|---|---|---|
| Ford Pro Telematics (developer.ford.com) | Location, status, history | Partner application; individual access has been cut off at times. Fleetio's import is nightly, at 15-minute steps | No documented webhook |
| Smartcar / High Mobility | Ford location, odometer, fuel/EV, DTCs | Self-serve; one OAuth consent per vehicle; per-vehicle monthly price | Webhooks |
| Samsara, Motive | GPS, ignition, DTCs **and** AI dash cams | Fleet account; API token | Webhooks (safety events) |
| Verizon Connect Reveal, Lytx Surfsight | Dash cam video events, GPS | Fleet account; developer portal | Webhooks |
| Garmin, Vantrue, Rosco | Consumer cams | No fleet API | n/a |

Recommendation: Samsara or Motive covers both vans and cams with one account. Otherwise use Smartcar for the Ford vans plus a fleet cam vendor.

## Data model (migration 2026_11_16_171204)

- `telematics_connections`: one vendor account per org. `credentials` is encrypted and never serialized. `status`/`status_detail`/`checked_at` record the connect flow's self-check.
- `telematics_devices`: one box per van (`kind` = tracker | dashcam | both). `vehicle_id` links it to its Fleet vehicle. Holds the latest reading (lat/lng, speed, heading, ignition, odometer, fuel %, fault codes, last_seen_at).
- `vehicle_positions`: the trail. `driver_user_id` is taken from the vehicle's open assignment when the reading arrives.
- `camera_events`: type (harsh_brake, harsh_accel, harsh_turn, collision, distracted, speeding, other), severity, place and time. `vendor_media_id`, `url` and `url_expires_at` point to the clip, which stays at the vendor.
- `monitoring_cameras.telematics_device_id`: a dash cam is a MonitoringCamera with protocol 'dashcam'.

## Code

- `App\Support\Telematics\TelematicsProvider`: one class per vendor, with `check()` (the self-check, which lists devices) and `clipUrl()` (signs a link now).
- `TelematicsProviders`: `check()`, `ingestPosition()`, `ingestCameraEvent()` (once per external id; emits `monitoring.camera_event`) and `freshClipUrl()` (re-signs only when the link is stale). A vendor is registered in `$providers` once its account exists.

## Privacy and permissions

- Positions and events are personal data about the driver. Each has HoldsPersonalData, a 3-month retention window, and erasure that drops the row.
- The van map layer is behind `field.map.view` and within the people Field shows you. Events and clips are behind `monitoring.cameras.view` plus reach through the vehicle's franchise and company.
- No video is copied: same rule as call recordings.

## Still to do

1. Field: a van layer on the Field map, and on a job, its tech's van.
2. Field: the connect UI and webhook endpoints, per vendor, once Jose picks one.
3. Monitoring: `GET /monitoring/dashcam-events` and `POST /monitoring/dashcam-events/{id}/clip-url`, built on these tables.
