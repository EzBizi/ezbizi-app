# Monitoring — Cameras slice (generic-protocol v1) — design

Date: 2026-09-01. Status: approved to build the backend. Owner: this session.
Parent: `docs/superpowers/specs/2026-08-28-monitoring-module-design.md` (Sub-project 3).

## Decision (owner-approved): generic-protocol v1

A company registers a camera by its stream URL — no per-brand OAuth, no server
transcoding, **no stored credentials**. v1 assumes an open or tokenized/LAN stream
URL; basic-auth-in-a-browser plus a server-side media proxy is an explicit **v2**.
**No recording** — live view only — so this is not personal-data-at-rest: a camera
names no individual, so no §9 `HoldsPersonalData`/erasure machinery and no §3
per-person reach apply (contrast `MonitoringScreenshot`, a picture *of* somebody).
It is an org-scoped, permission-gated CRUD over camera configs, the same shape as
`ServiceArea` ("where this franchise works").

This supersedes the parent spec's older sketch (`stream_kind = hls|mjpeg|snapshot|
deeplink`) with a plain `protocol` enum swapping `deeplink` for `rtsp` — the deep-link
idea is folded into "an NVR renders an rtsp url however it likes."

## Schema — `monitoring_cameras`

`id` (uuid), `organization_id` (`BelongsToTenant`), `label` (string), `protocol`
(string enum: `hls`|`mjpeg`|`snapshot`|`rtsp`), `url` (string, up to 2048), `enabled`
(bool, default true), `created_by` (nullable FK to `users`, `nullOnDelete`),
timestamps. Its own migration file (`2026_09_01_150000_create_monitoring_cameras_table.php`)
— a new table never appends to an already-run migration.

Model `App\Models\MonitoringCamera` implements plain `StatesRetention` (`forever()` —
config, not an event log), **not** `HoldsPersonalData`.

## Endpoints (`App\Http\Controllers\Api\Monitoring\CamerasController`)

All four in the main `v1` (idle-bearing) route group — a camera CRUD is an admin
action, not a background beat:

- `GET /api/v1/monitoring/cameras` — list, newest first. Gated `monitoring.cameras.view`.
- `POST /api/v1/monitoring/cameras` — create. Gated `monitoring.cameras.manage`.
  Validates label, `protocol` (enum), `url` (max 2048, scheme must match protocol:
  `rtsp` only for protocol `rtsp`, `http`/`https` otherwise), `enabled` (optional,
  default true). Stamps `created_by`.
- `PATCH /api/v1/monitoring/cameras/{id}` — update. Gated `monitoring.cameras.manage`.
  404 cross-tenant.
- `DELETE /api/v1/monitoring/cameras/{id}` — delete. Gated `monitoring.cameras.manage`.
  404 cross-tenant.

Ids are looked up manually (`find($id)` + `abort(404)`), mirroring
`ScreenshotsController` — `BelongsToTenant`'s tenant scope makes a sibling
organization's id read as "does not exist," not "forbidden." Refusals for a missing
grant carry a sentence (`Refusal::permission`, `MonitoringCameraPolicy`).

## Module registration

Added `cameras.view` and `cameras.manage` to the `monitoring` `ModuleDefinition`
(add-only), alongside the existing `view`/`specs.view`/`screenshots.view`.

## Deferred to v2 (named)

- **Auth'd streams + a media proxy** — a credentials column and a server-side proxy
  so a basic-auth camera can be viewed from a browser without exposing the
  credentials client-side. Marked `// ponytail:` at the migration and model.
- **Branded/vendor integrations** (OAuth per camera brand, cloud-vendor APIs).
- **Recording** — v1 is live view only; no storage, no retention window for footage.
- **The viewer UI** — a separate task; this slice is backend only.

## Tests

`tests/Feature/Monitoring/CamerasTest.php` (`RefreshDatabase`, `BuildsTenants`):
create (fields + `created_by`), protocol/url validation (bad protocol, bad url,
scheme/protocol mismatch both directions, a valid rtsp accepted), permission refusals
with a sentence (view and manage separately), list scoped to the org (a genuine
sibling **organization**, not just a sibling company/franchise — there is no §3 reach
here to prove), a sibling org's id 404s on update/delete, update changes fields,
delete removes the row. `MonitoringModuleRegistrationTest` extended with the two new
actions/permission keys.
