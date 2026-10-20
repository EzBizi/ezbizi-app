# Camera bridge — Phase 1 (live view) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** A customer pairs an always-on Windows PC running EzBizi to a camera site; that PC finds the site's recorder/cameras and relays live video, on demand, to EzBizi's Camera Monitoring grid and tap-to-live panel.

**Architecture:** Laravel holds sites, cameras, pairing and short-lived tokens. The bridge is a service inside the EzBizi Windows app: it authenticates with a per-site key (no EzBizi user signed in), long-polls for "wanted" cameras, and supervises a bundled go2rtc that pulls RTSP from the recorder and publishes outbound over TLS to a relay on the app box. Viewers get a short-lived signed URL; the relay checks every publish and read against Laravel.

**Tech Stack:** Laravel 13 / PHP 8.4 / MySQL; Flutter (Windows desktop for the bridge; web + all clients for viewing); go2rtc (bridge-side, MIT); MediaMTX or go2rtc on the app box (settled by Task 1); nginx.

**Spec:** `docs/superpowers/specs/2026-10-20-camera-bridge-design.md` (read it; this plan implements its delivery step 1 plus the site/camera model the later phases build on).

## Global Constraints

- Recorder credentials never leave the bridge PC (spec anti-goal); stored with `flutter_secure_storage`.
- No inbound ports at the site; the bridge only makes outbound connections.
- Video crosses the box only while a viewer watches; the bridge stops a camera after a short grace when its last viewer leaves (Ring bridge uses 3 s; use the same).
- Every camera belongs to an organization and inherits its site's company/franchise; reads go through ViewScope + §3 reach exactly like `monitored_devices`.
- Permissions: `monitoring.cameras.view` (watch), `monitoring.cameras.manage` (sites, pairing, camera setup). No new permission keys.
- Cameras without a site keep working unchanged (Ring cameras, public URLs).
- No blocking work on the UI isolate or the Windows platform thread (`flutter-windows-channel-handlers-run-on-the-platform-thread`); the engine is a child process, all LAN I/O async.
- UI: no dialogs (theme_conventions_test), containment (no full-screen push), screens per UI/UX's approved gallery — UI/UX builds them; this plan ships the service layer and plain placeholders only where a test needs a surface.
- Migrations: unique real HHMMSS timestamps; never edit a deployed migration.
- Each new table states retention (RetentionTest) and, if it holds personal data, its inventory entries.
- Commits go to a `session/…` branch; the deploy desk merges and releases.

## Review Focus

1. **A site key or token from one organization used against another's camera** → refused (403/404), never streamed. Pinned in Task 4 and Task 5 tests.
2. **Two viewers watch one camera; one leaves** → stream keeps running for the other; stops only after the last leaves + grace. Pinned in Task 5.
3. **Bridge PC offline or engine crashed while someone taps a camera** → viewer sees "This site's bridge is offline (last seen N min ago)" within the poll budget, not a spinner forever. Pinned in Task 6 (client) and Task 5 (server returns `bridge_offline`).
4. **Recorder password wrong or recorder unreachable** → bridge reports the camera `unreachable` with a reason in its check-in; the grid tile shows it, nothing loops hot. Pinned in Task 7.
5. **Pairing code reused, expired, or guessed** → single use, 15-minute life, rate-limited; a used code cannot pair a second PC. Pinned in Task 3.

---

### Task 1: Relay spike — settle the box-side relay and viewer format

Spike, not product code: decides what Tasks 5 and 8 build against. No tests beyond the measurements recorded.

**Files:**
- Modify: `docs/superpowers/specs/2026-10-20-camera-bridge-design.md` (add "Relay — settled" with the measured choice)

- [ ] **Step 1:** On a Windows PC, run go2rtc against a test RTSP source (`ffmpeg -re -f lavfi -i testsrc=size=1280x720:rate=15 -c:v libx264 -f rtsp rtsp://127.0.0.1:8554/test` into a local go2rtc, or any RTSP camera on the LAN).
- [ ] **Step 2:** Publish it outbound to a relay on the app box, trying in order: (a) MediaMTX with RTSPS ingest + `authMethod: http` pointing at a stub Laravel URL; (b) go2rtc on the box with RTSP ingest behind a TLS-terminating nginx stream block. Record for each: ingest works through the box firewall (state which port), per-path auth via HTTP callback possible (yes/no), viewer outputs available.
- [ ] **Step 3:** Confirm a viewer format that plays on **web and the Windows/Mac/mobile builds without new native players**: MJPEG (existing `CameraImageView` both halves) is the floor; record CPU on the box for one 1080p H.264 stream transcoded to MJPEG at 10 fps, and whether HLS output exists for the web `<video>` path.
- [ ] **Step 4:** Write the decision into the spec: relay software, ingest protocol+port, auth hook, viewer URL shape, measured CPU per stream. Default if both work: MediaMTX for ingest/auth + go2rtc reading from MediaMTX for MJPEG output.
- [ ] **Step 5:** Commit the spec change: `git commit -m "Camera bridge: relay settled by spike"`.

### Task 2: Sites and site cameras — schema and models

**Files:**
- Create: `database/migrations/2026_10_20_HHMMSS_create_camera_sites_table.php`
- Create: `database/migrations/2026_10_20_HHMMSS_add_site_columns_to_monitoring_cameras.php`
- Create: `app/Models/CameraSite.php`
- Modify: `app/Models/MonitoringCamera.php` (relations, casts)
- Modify: `app/Support/Privacy/RetentionPolicy.php` docblock count if the guard requires it
- Test: `tests/Feature/Monitoring/CameraSitesModelTest.php`

**Interfaces:**
- Produces: `camera_sites(id uuid, organization_id, company_id null, franchise_id null, name string(120), device_id null → monitored_devices, site_key_hash char(64) null, paired_at null, last_checkin_at null, status json null, created_by, timestamps)`; `monitoring_cameras` + `site_id null`, `source string(16) default 'url'` (`url|bridge`), `channel string(64) null` (recorder channel or camera address the bridge maps), `capabilities json null` (`["live"]` in phase 1), `bridge_status string(24) null` (`ok|unreachable|auth_failed`), `bridge_reason string(255) null`.
- `CameraSite::online(): bool` — `last_checkin_at` within 120 s.
- `MonitoringCamera::scopeVisibleTo(Builder, User)` — site cameras via the site's company/franchise under ViewScope + reach; site-less cameras keep today's org rule.

- [ ] **Step 1:** Tests: `test_a_site_camera_follows_its_sites_company_for_visibility` (user scoped to company B does not see a camera whose site is in company A; org-level user sees both); `test_a_siteless_camera_is_unchanged` (Ring-style row still visible to org-level viewer); `test_online_is_two_minutes_of_checkins`.
- [ ] **Step 2:** Run: `DB_DATABASE=ezbizi_testing_monitoring php artisan test tests/Feature/Monitoring/CameraSitesModelTest.php` — FAIL (no table).
- [ ] **Step 3:** Migrations + models. `CameraSite` uses `BelongsToTenant`, `HasUuids`; states retention `forever()` with reason "configuration: one row per physical site, removed when the site is deleted". No personal data (device link is technical).
- [ ] **Step 4:** Run the test plus `tests/Feature/RetentionTest.php` (`php -d memory_limit=1G vendor/bin/phpunit`) — PASS.
- [ ] **Step 5:** Commit.

### Task 3: Site management and pairing

**Files:**
- Create: `app/Http/Controllers/Api/Monitoring/CameraSitesController.php`
- Modify: `routes/api.php` (MAIN group, Monitoring block)
- Test: `tests/Feature/Monitoring/CameraSitesTest.php`

**Interfaces:**
- Consumes: Task 2 models.
- Produces (all `auth:sanctum` MAIN group):
  - `GET monitoring/camera-sites` (view) → `{data:[{id,name,company_id,franchise_id,online,online_since|offline_since,last_reason,last_checkin_at,device:{id,label,pc_name,app_version}|null,camera_count}]}` — field names agreed with UI/UX for gallery 117
  - `GET monitoring/camera-sites/{id}` (view) → the site plus `cameras:[{id,label,enabled,supports:["live"],bridge_status,bridge_reason,still_url|null}]`
  - `POST monitoring/camera-sites` (manage) `{name, company_id?, franchise_id?}` → site
  - `PATCH/DELETE monitoring/camera-sites/{id}` (manage)
  - `POST monitoring/camera-sites/{id}/pairing-code` (manage) → `{code: "ABCD-2345", expires_at}` — 8 chars from an unambiguous alphabet, 15 min, single use, stored hashed in cache.
  - `POST monitoring/bridge/pair` (manage, signed in **on the bridge PC**) `{code, device_key}` → `{site_id, site_key}` — 40-char random; only `sha256` stored; links `device_id`; a second pair on the same site revokes the old key.
  - `POST monitoring/camera-sites/{id}/revoke` (manage) → clears `site_key_hash`, `device_id`.
- Audit: `monitoring.camera_site.created|paired|revoked`.

- [ ] **Step 1:** Tests: create/list respecting reach; `test_pairing_code_is_single_use_and_expires_in_15_minutes` (second use 422; `travel(16)->minutes()` 422); `test_pairing_is_rate_limited` (6th bad code in a minute → 429, FactorAttempts-style limiter keyed per user); `test_pair_returns_a_key_stored_only_as_a_hash`; `test_repairing_revokes_the_previous_key`; `test_view_only_cannot_create_or_pair` (403).
- [ ] **Step 2:** Run — FAIL.
- [ ] **Step 3:** Controller + routes.
- [ ] **Step 4:** Run the file + `tests/Feature/Monitoring` — PASS.
- [ ] **Step 5:** Commit.

### Task 4: The bridge's own API (site-key auth)

**Files:**
- Create: `app/Http/Middleware/AuthenticateCameraBridge.php`
- Create: `app/Http/Controllers/Api/Monitoring/CameraBridgeController.php`
- Modify: `routes/api.php` (a new group: `throttle`, `AuthenticateCameraBridge`, **no** sanctum/tenant user, no idle)
- Modify: `bootstrap/app.php` or the middleware alias registry as the project does it
- Test: `tests/Feature/Monitoring/CameraBridgeTest.php`

**Interfaces:**
- `AuthenticateCameraBridge`: `Authorization: Bridge <site_key>` → resolves `CameraSite` by `sha256`, enters its tenant (TenantContext), sets `$request->attributes->get('camera_site')`. Missing/unknown → 401 `{code:'bridge_unpaired'}`.
- Endpoints:
  - `POST monitoring/bridge/still` (multipart `camera_id`, `image` jpeg ≤ 300 KB) → `204`; the bridge sends a low-res sub-stream still per enabled camera every 10 min (UI/UX's camera tiles; spec: no continuous video). Served to viewers via a signed URL like play; retention: overwrite in place, one per camera.
  - `POST monitoring/bridge/checkin` `{engine_version, found:[{key, label, kind:'recorder'|'camera', address, channels:[{channel, label}]}], cameras:[{camera_id, status:'ok'|'unreachable'|'auth_failed', reason?}]}` → `204`; sets `last_checkin_at`, `status`, each camera's `bridge_status/reason`.
  - `GET monitoring/bridge/work` — **long-poll**: holds up to 25 s, returns as soon as the wanted set differs from the `?since=` version: `{version:int, wanted:[{camera_id, channel, quality:'sub'|'main', publish:{url, token, expires_at}}]}`; returns the same shape on timeout.
  - (wanted-set storage: cache key per site; version increments on change.)

- [ ] **Step 1:** Tests: `test_unpaired_or_wrong_key_is_401`; `test_a_key_reads_only_its_own_sites_cameras` (site A key cannot see site B's wanted cameras, even in the same org); `test_checkin_marks_online_and_camera_status`; `test_work_answers_immediately_when_a_camera_is_wanted_and_after_timeout_otherwise` (use a 1 s hold in tests via config `camera_bridge.long_poll_seconds`).
- [ ] **Step 2:** Run — FAIL.
- [ ] **Step 3:** Middleware + controller. Long-poll = loop checking the cache version every 500 ms until the hold elapses (ponytail: a poll loop inside the request; a push channel if PHP-FPM workers become the limit — record the ceiling in a comment).
- [ ] **Step 4:** Run — PASS. Also run `tests/Feature/Automations` (routes touch the shared registry only if a module is added; run it anyway per SESSIONS.md).
- [ ] **Step 5:** Commit.

### Task 5: Watching — wanted set, signed viewer URLs, relay auth hook

**Files:**
- Create: `app/Support/Monitoring/CameraRelay.php` (token minting/verification, wanted-set)
- Modify: `app/Http/Controllers/Api/Monitoring/CamerasController.php` (or the existing cameras controller) — `watch`
- Create: `app/Http/Controllers/Api/Monitoring/CameraRelayAuthController.php`
- Modify: `routes/api.php`
- Test: `tests/Feature/Monitoring/CameraRelayTest.php`

**Interfaces:**
- `POST monitoring/cameras/{id}/watch` (view, reach) `{quality:'sub'|'main'}` → `{play:{url, expires_at}}` or `409 {code:'bridge_offline', last_checkin_at}` when the site is offline. Adds the camera to its site's wanted set.
- `CameraRelay::mintPlay(MonitoringCamera, User, string $quality): array` / `mintPublish(MonitoringCamera): array` — HMAC-SHA256 over `{camera_id, action, exp}` with `config('camera_bridge.relay_secret')`; play 5 min, publish 10 min.
- `POST camera-relay/auth` (relay → Laravel; shared secret header, no user): `{action:'read'|'publish', path, token}` → `200` or `401`. Path is `cam/<camera_id>/<quality>`; the token's camera must equal the path's.
- `POST camera-relay/viewers` (relay → Laravel) `{path, viewers:int}` → when 0, schedule removal from the wanted set after 3 s grace.

- [ ] **Step 1:** Tests: `test_watch_returns_a_play_url_and_wants_the_camera`; `test_watch_on_an_offline_site_is_409_bridge_offline`; `test_a_play_token_for_one_camera_cannot_read_another` (auth hook 401); `test_a_publish_token_cannot_be_used_to_read`; `test_expired_tokens_are_refused` (`travel`); `test_last_viewer_leaving_unwants_after_grace_and_two_viewers_keep_it`; `test_relay_hooks_refuse_without_the_shared_secret`; `test_a_viewer_outside_reach_gets_404`.
- [ ] **Step 2:** Run — FAIL.
- [ ] **Step 3:** Implement.
- [ ] **Step 4:** Run file + `tests/Feature/Monitoring` — PASS.
- [ ] **Step 5:** Commit.

### Task 6: Viewer client — site cameras in the grid and tap-to-live

**Files:**
- Modify: `client/lib/screens/monitoring/camera_monitoring_tab.dart` (site cameras: call `watch`, play the returned URL; offline state)
- Create: `client/lib/core/camera_watch.dart` (`Future<CameraPlay> watchCamera(ApiClient, String cameraId, {String quality})`, re-mints on expiry)
- Test: `client/test/camera_watch_test.dart`, extend `client/test/camera_monitoring_tab_test.dart`

**Interfaces:**
- Consumes: Task 5 `watch`.
- `CameraPlay {String url; DateTime expiresAt}`; throws `CameraWatchException('bridge_offline', lastSeen)`.
- Tiles use `quality:'sub'`; the live panel `'main'`. URL-source cameras unchanged.

- [ ] **Step 1:** Tests: a `source:'bridge'` camera tile requests `watch` and renders `CameraImageView` with the returned URL; `bridge_offline` renders "This site's bridge is offline (last seen N min ago)" and no spinner; a URL-source camera never calls `watch`; the live panel asks `quality:'main'`.
- [ ] **Step 2:** `flutter test test/camera_watch_test.dart test/camera_monitoring_tab_test.dart` — FAIL.
- [ ] **Step 3:** Implement (layout stays UI/UX's; only the data source changes).
- [ ] **Step 4:** Run — PASS; `flutter analyze lib test` clean.
- [ ] **Step 5:** Commit.

### Task 7: The bridge service (Windows app)

**Files:**
- Create: `client/lib/core/camera_bridge/camera_bridge_service.dart` (loop: checkin every 60 s; long-poll `work`; reconcile engine)
- Create: `client/lib/core/camera_bridge/bridge_store.dart` (site key + recorder credentials in `flutter_secure_storage`; `io` only, stub elsewhere)
- Create: `client/lib/core/camera_bridge/engine.dart` (writes go2rtc YAML, starts/stops/restarts the child process, health)
- Create: `client/lib/core/camera_bridge/discovery.dart` (ONVIF WS-Discovery probe on UDP 3702, 3 s; plus `addByAddress(host)`)
- Create: `client/lib/core/camera_bridge/bridge_api.dart` (the Task 4 endpoints with `Authorization: Bridge <key>`)
- Modify: `client/lib/main.dart` (start the service on Windows when a site key exists, independent of sign-in)
- Test: `client/test/camera_bridge_service_test.dart`, `client/test/camera_bridge_engine_test.dart`, `client/test/camera_bridge_discovery_test.dart`

**Interfaces:**
- `CameraBridgeService.start({required BridgeApi api, required BridgeStore store, required Engine engine, required Discovery discovery})`, `stop()`.
- `Engine.apply(List<WantedCamera>)` — idempotent; config maps each wanted camera to `rtsp://<user>:<pass>@<recorder>/cam/realmonitor?channel=<n>&subtype=<0|1>` (Amcrest/Dahua) or the camera's own RTSP URL, publishing to the `publish.url` with its token. Empty list → engine idle (process may stay up, no streams).
- `Discovery.find(): Future<List<FoundDevice>>`.
- Reports per-camera `ok|unreachable|auth_failed` from engine health in the next checkin.

- [ ] **Step 1:** Tests (all with fakes, no real process or network): wanted set from `work` → `engine.apply` called with exactly those cameras and `subtype=1` for `sub`; empty set → `apply([])`; engine reports auth failure → next checkin carries `auth_failed`; `bridge_unpaired` 401 → service stops and clears the stored key; discovery parses a real WS-Discovery `ProbeMatches` XML fixture captured from an Amcrest device (store the fixture under `client/test/fixtures/`); long-poll failures back off (1 s, 2 s … max 30 s) and never spin.
- [ ] **Step 2:** Run — FAIL.
- [ ] **Step 3:** Implement. Process start via `Process.start` (non-blocking); all reads async.
- [ ] **Step 4:** Run — PASS; analyze clean.
- [ ] **Step 5:** Commit.

### Task 8: Ship the engine and the box relay

**Files:**
- Create: `client/windows/engine/go2rtc.exe` + `client/windows/engine/LICENSE-go2rtc` (pinned release, SHA-256 recorded in `client/windows/engine/VERSION`)
- Modify: `client/windows/CMakeLists.txt` (install the engine next to the exe)
- Create: `deploy/camera-relay/` (relay config from Task 1's decision, systemd unit, nginx snippet for the auth hook and play paths)
- Test: `client/test/camera_bridge_engine_path_test.dart` (engine path resolves next to the executable; missing binary → bridge reports `engine_missing` instead of looping)

- [ ] **Step 1:** Test for `engine_missing`.
- [ ] **Step 2:** Run — FAIL.
- [ ] **Step 3:** Add binary + CMake install; deploy files. **Box changes go through the deploy desk** (send them the deploy/ folder and the firewall port from Task 1).
- [ ] **Step 4:** `flutter build windows --release` succeeds and `build/windows/x64/runner/Release/engine/go2rtc.exe` exists; test PASS.
- [ ] **Step 5:** Commit.

### Task 9: End-to-end check on a real camera

- [ ] **Step 1:** On a Windows PC with the release build: pair to a test site (staff org), point at a real RTSP camera or the NV2108E-HS when it arrives, watch from web and from another desktop. Record: tap-to-first-frame time, box CPU per stream, that closing the panel stops the bridge's stream within ~5 s (relay viewer count 0 → wanted set empty → engine idle).
- [ ] **Step 2:** Pull the bridge PC's network cable; the tile shows offline within 2 min; reconnect recovers without touching it.
- [ ] **Step 3:** Record results in the spec ("Phase 1 measured") and hand the branch to the deploy desk with the three conformances.

---

**Screens (separate track, UI/UX builds):** Monitoring › Settings › Cameras (sites list, site detail, pairing code) and the bridge-PC "Camera bridge" panel. Gallery requested 2026-10-20. Tasks 3, 6 and 7 expose everything those screens need; the screens land after the gallery is approved and are not blockers for Tasks 1–5, 7, 8.
