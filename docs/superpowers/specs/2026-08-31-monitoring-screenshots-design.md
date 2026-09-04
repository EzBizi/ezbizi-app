# Monitoring — Screenshots slice — design

Date: 2026-08-31. Status: approved to build (owner decisions below). Owner: this session.
Parent: `docs/superpowers/specs/2026-08-28-monitoring-module-design.md` (Sub-project 2).
Sibling: `docs/superpowers/specs/2026-08-31-desktop-monitoring-specs-design.md` (specs slice).

The screenshots slice lets an organization see periodic captures of a monitored
Windows machine's screen, on the Monitoring tab. The desktop app captures the
**primary monitor** every few minutes, hard-compresses it, and uploads it; the
server stores it under a retention window and serves it — behind its own grant — to
a new viewer in the PC-side of the Monitoring tab. This is the surveillance heart of
the module, so its two honesty problems (disk, and disclosure) are designed in, not
bolted on.

---

## Decisions locked (owner, 2026-08-31)

1. **Windows first**, native capture in the desktop-app runner. Other platforms
   never capture (they declare no `screenshot` capability).
2. **Primary monitor only** — one image of the main display per capture. Not
   all-monitors, not active-window. (Org-configurable multi-monitor can come later
   if asked.)
3. **Every 5 minutes**, org-configurable interval, hard-compressed JPEG.
4. **30-day retention on the box**, swept by the nightly `ezbizi:expire-data` job
   (the sweep floors at one whole month, so 30d = `window(1,'captured_at',…)`). The
   storage path is written so it can move to object storage later without a schema
   change. Disk is the real constraint — see below.
5. **Gated by `monitoring.screenshots.view`** — a NEW grant, separate from
   `specs.view`, so an org can let a manager see who's online and their specs without
   granting screen images.
6. **Silent + stated in the customer terms** (owner's standing call for the module).
   The §9 machinery treats a screenshot as personal data (it is a picture of a
   person's screen).
7. **Remote control is IN scope** (owner 2026-08-31) but is its **own later slice** —
   this slice is capture + store + view only. Live-view (on-demand fast capture)
   rides this pipeline and is the slice after.

---

## The disk reality (this decides the defaults, not the other way round)

5-minute captures ≈ 288/day. Hard-compressed primary-monitor JPEG ≈ 60–120 KB.
Call it ~25 MB/employee/day → ~750 MB/employee over 30 days → **~37 GB for 50
employees**. On one AWS box that is real.

Three knobs stay org-tunable so the default can be dialled down without a deploy:
**interval**, **JPEG quality/scale**, **retention**. The stored path is
`storage/app/monitoring/screenshots/<org>/<device>/<captured_at>.jpg` — abstracted
behind a small `ScreenshotStore` so a later move to S3/object storage is a store
swap, not a schema change.

`// ponytail: 30-day whole-month sweep is the cron floor. If disk bites first, dial
the interval/quality settings down per-org, or add a day-precise pruner
(RetentionPolicy::elsewhere) — do not lower window() below 1, and do not reach for
object storage until the box actually hurts.`

---

## Architecture

Builds on SP1 (module, tab, heartbeat, capability report) and the specs slice's
native-channel pattern. Windows-runner native + a thin backend (table, ingest,
serve) + a new viewer screen.

### 1. Native — Windows runner (C++)

Extend the monitoring native surface with a `captureScreenshot` method (its own
channel `com.ezbizi.app/monitoring_screenshot`, or a second method on the specs
channel — the plan decides):
- Grab the **primary** monitor: `GetDC(NULL)` → `BitBlt` into a memory DC sized to
  the primary display (`GetSystemMetrics(SM_CXSCREEN/SM_CYSCREEN)`), `GetDIBits`.
- Encode JPEG via **WIC** (Windows Imaging Component, built in — no new dependency),
  at the configured quality, optionally downscaled.
- Return the JPEG **bytes** to Dart (as a `Uint8List`), or write to a temp file and
  return the path — the plan picks the lower-copy path.
- Failure (locked session, no desktop, RDP quirk) → return null; never crash, never
  block.

### 2. Client — capture + upload

A `ScreenshotService` (Dart, `Platform`-guarded to Windows, reuses the
`kIsWeb`/`defaultTargetPlatform` guard the specs slice established — **never import
`dart:io` unconditionally**, it breaks `flutter build web`). Driven off the same
agent loop as the heartbeat: every `screenshot_interval` (default 5 min) it invokes
the native capture and, if bytes come back, uploads them. It declares the
`'screenshot'` capability on the beat (so the tab and server know this device can be
captured) only while capture is available.

### 3. Server — ingest, store, serve (no App Store freeze concern; freeze lifted)

- **Table `monitoring_screenshots`** — its OWN migration file (new table, new
  filename; never add to an already-run migration — see
  [[never-edit-a-deployed-migration-file]] in prior work). Columns: uuid id, org id,
  device id, user id, `captured_at`, `path` (relative, under the store root),
  `bytes` (size), `width`/`height`. `BelongsToTenant`, `HasUuids`,
  `StatesRetention` → `window(1,'captured_at',…)`, `HoldsPersonalData` (a screenshot
  is a person's screen — purpose + an Erasure that deletes the file with the row).
  Add `monitoring_screenshots` to the `RetentionPolicy::window()` docblock list (the
  swept-tables sentence `RetentionTest` parses).
- **`ScreenshotStore`** (`Support/Monitoring/`) — writes/reads/deletes the JPEG under
  `storage/app/monitoring/screenshots/…`; the one place the path lives, so object
  storage is a later swap. The model's `deleting` hook (or the retention sweep)
  deletes the file with the row.
- **Ingest** `POST /api/v1/monitoring/screenshots` — the agent uploads a JPEG
  (multipart) with `device_key`; validated (image, max size), stored, one row
  written, tenant-scoped through `TenantContext`. In the heartbeat's idle-less group
  (a background upload, like the beat, must not slide the session idle window).
  Rate/size limited.
- **List + serve** — `GET /api/v1/monitoring/screenshots?device_id=&…` (metadata,
  paged, newest first) and `GET /api/v1/monitoring/screenshots/{id}` (streams the
  JPEG). Both gated `monitoring.screenshots.view`; refusals carry a sentence.
- **Registration** — add `screenshots.view` to the `monitoring` `ModuleDefinition`
  actions (add-only). Declare the `monitoring.disk_low` trigger now too if wired —
  but that belongs to the specs data, so leave it unless an emitter exists.

### 4. Viewer — a NEW screen (UI/UX gate)

A screenshots view inside the Monitoring tab (or a sub-tab): a grid/timeline of a
device's recent captures, click to enlarge, scrub by time. **New arrangement → goes
through the UI/UX owner:** 3+ HTML layout options to Jose, he picks by number, then
build. The backend above ships first and independently; the viewer is built against
it once the layout is chosen. Honesty rule (from the reachability pattern): a device
that has not captured recently must SAY the last frame is stale ("last capture 22
min ago"), never present an old frame as current.

---

## Testing

- Backend (MySQL gate, `DB_DATABASE=ezbizi_testing_monitoring-screenshots`): ingest
  writes one row + one file; retention window is 1 month from `captured_at`; the file
  is deleted with the row; list/serve gated by `screenshots.view` (absent → 403 with
  a sentence; present → bytes); §3 subtree scoping (a company view cannot list a
  sibling company's device's shots — reuse the specs/devices reach). `RetentionTest`
  and `PersonalDataPurgeTest` stay green with the new table named in the docblock.
- Client (`flutter analyze` + `flutter test`, and **`flutter build web`** as a gate —
  a `dart:io` slip breaks web): a contract test that the service uploads bytes when
  the native capture returns them and declares `'screenshot'`; nothing when it
  returns null.
- Native C++: no harness test (`ponytail`: covered by the Dart contract + a manual
  Windows run confirming a real capture appears in the viewer). Owner verifies
  on-device.
- **Client `client/VERSION` bump + `version_history.dart` entry** in the release, or
  desktop users are never offered the capture agent (see [[release-notes-go-stale-silently]]
  and the deploy desk's VERSION rule).

## Deferred (named)

- **Live-view** (on-demand fast capture) — next slice, reuses this pipeline.
- **Remote control** — its own later slice (in scope per owner).
- **Multi-monitor / active-window capture**, **macOS/Linux capture** (macOS adds the
  user-visible Screen Recording permission), **object-storage backend** — later.
- **Always-on polish** (start-to-tray on autostart, org-forced autostart) — small,
  tracked separately; the tray + close-to-tray + installer autostart already exist.

## Open questions (none block the backend)

- Screenshot on a **locked** workstation: capture a lock screen, or skip until
  unlocked? Default: skip when the capture returns black/locked (less noise, less
  disk); revisit if "was the machine locked" matters.
- Viewer placement: a sub-tab of Monitoring vs. an expander on the device row.
  Decide with the layout options.
