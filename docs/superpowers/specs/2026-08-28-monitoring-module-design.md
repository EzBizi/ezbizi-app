# Monitoring module — design

Date: 2026-08-28. Status: approved to build sub-project 1 (PC Specs). Owner: this session.

Monitoring lets an organization watch three things: its **cameras**, its **people's
devices** (what they're logged in from, and — where a desktop agent is installed —
that machine's RAM/disk/OS/wifi/open apps), and its **people's desktop activity**
(periodic screenshots, and later a live view). It is one module, one tab, three
sub-tabs, on one shared spine.

Feature availability is a property of the *device*, not the plan: a web session can
report which tab and for how long; only the installed desktop agent can report
hardware or capture a screen. The module surfaces that per-device so the
organization sees what is and is not possible for each person — it does not pretend a
web-only user can be screen-captured.

---

## Decisions locked before this spec (owner, 2026-08-28)

1. **Build order:** PC Specs → Desktop Monitoring → Cameras. None urgent.
2. **Cameras carry no server-side video.** One AWS box; a media relay on it is a
   single-box killer (build plan Phase 0 #7 already flags disk). v1 stores each
   camera's stream URL and the browser renders it; v2 reuses the desktop agent as an
   on-site RTSP→WebRTC bridge. See Sub-project 3.
3. **Live view before remote control.** v1 "live view" is fast on-demand screenshots
   reusing the capture pipeline. Real-time streaming + input injection is its own
   later slice (WebRTC, STUN-first). See Sub-project 2.
4. **Retention** rides the existing nightly `ezbizi:expire-data` sweep. Defaults
   below; all org-configurable through `SettingsResolver` (§6).
5. **Disclosure is silent + stated in the customer terms.** Owner's explicit call.
   The visible-notice machinery exists (`Support/Privacy/ConsentLedger`,
   `Support/Phone/RecordingNotice` is the precedent) and stays available to flip
   per-org later; nothing here forces it on. Recorded, not re-argued.
6. **Access is tab-permission-driven, no hardcoded roles** (§4). A franchise manager
   sees their subtree, an org admin sees all — decided by the grant, per §3.

---

## Decomposition

Each sub-project gets its own implementation plan and build cycle. This spec fully
specs the spine and Sub-project 1; it sketches 2 and 3 so their later specs can
reference the shared pieces rather than reinvent them.

| # | Sub-project | Needs the agent? | Server load |
|---|---|---|---|
| 1 | **PC Specs** | No for the in-app half; yes for hardware fields | negligible |
| 2 | **Desktop Monitoring** | Yes | screenshots = disk (bounded) |
| 3 | **Cameras** | No (v1); reuses agent (v2) | none (no relay) |

---

## The shared spine (built once, in Sub-project 1)

### 1. Device identity + capability report

Every session already has a `personal_access_tokens` row carrying `user_agent`,
`ip_address`, `last_used_at` (migration `2026_08_06_160000`). PC Specs' in-app half
reads from there — no new identity for web/mobile.

The desktop agent needs a richer, stable record, so:

- **`monitored_devices`** — one row per (organization, user, device). A device is
  identified by a client-generated stable id the agent persists locally. Web/mobile
  sessions map to a device row too (keyed off the token) so the list is uniform.
- Each device reports a **capability set** it declares itself:
  `['activity']` (web/mobile: tab + dwell + device + last seen),
  `['activity','specs']` (desktop agent, hardware),
  `['activity','specs','screenshot']`, `['...','control']` (later).
  The server never assumes capability; it stores what the client claims and shows it.
  This is the answer to "the organization must be informed of what each device can
  do" — the capability set is rendered on the row.

### 2. Telemetry ingest

One authenticated, tenant-scoped endpoint family. Clients POST heartbeats / specs /
screenshots; writes go through `TenantContext`. A monitoring write **must never fail
the user's request** — where a monitoring event should trigger an automation
(`device_offline`, `disk_low`), it inserts an outbox row (`ezbizi:dispatch-
automation-events`, StartUp §10), never dispatches inline.

Heartbeat carries: current module key, dwell since last beat, capability set, and
(agent only) a specs blob. Cadence configurable; default 60s for presence, specs on
change + every 15 min, screenshots every 5 min (agent only).

### 3. Tenancy, gates, automations — module registration

Register in `ModuleServiceProvider` like every module (`ModuleDefinition`):

```
key: 'monitoring', label: 'Monitoring', sidebar: true,
actions: ['view', 'manage', 'specs.view', 'screenshots.view', 'control', 'cameras.manage'],
```

Each action is a grantable tab permission (§4 four gates: release flag → entitlement
→ permission → assignment). No role is hardcoded; `control` and `screenshots.view`
are separate grants so an org can let a manager see who's online without granting
screen capture.

Triggers (raised): `monitoring.device_offline`, `monitoring.new_device_seen`,
`monitoring.disk_low`. Actions: none in v1 — the module *observes*; it does not act
on other modules. Declared with `automationExemptBecause` only if the triggers above
turn out not to ship in slice 1; otherwise the triggers satisfy the §5.5 rule.
Subject: `MonitoredDevice`.

### 4. Retention (all via `RetentionPolicy`, swept by `ezbizi:expire-data`)

| Data | Model shape | Policy | Note |
|---|---|---|---|
| Device specs / presence | one row per device, **overwritten** | `forever` | current state, not a log — same as `PhonePresence` |
| Activity log (tab, dwell) | append, timed by `occurred_at` | `window(3, 'occurred_at', …)` | 90 days |
| Screenshots | file on disk + reference row | `window(1, 'captured_at', …)` | 30 days. The sweep floors at 1 whole month (`RetentionPolicy::window` throws below that), so 30d is the shortest the generic cron honours. |

Screenshots write to `storage/app/monitoring/<org>/<device>/…`, a path a later change
can repoint at object storage with no schema change. The reference row's retention
deletes the file with the row (model `deleting` hook).

`// ponytail: 30-day whole-month sweep is the floor the nightly cron enforces. If disk
pressure demands a shorter or day-precise window, add a dedicated
ezbizi:prune-screenshots command (declare RetentionPolicy::elsewhere) — do not lower
window() below 1.`

**Migration constraints (from the deployment session, learned the hard way today):**

- **Every new table declares a `RetentionPolicy` or is added to `RetentionTest`'s
  exception list _with a reason_.** This rule refused two deploys in one day; the
  second blocked thirty commits. All three tables here have a policy (above), so this
  is satisfied — but the model must actually implement `StatesRetention`, or the test
  fails the build. `monitoring_activity` holds a person's tab history, so its window
  is not optional.
- **No `DB::unprepared` / `CREATE TRIGGER` in a migration.** MySQL refuses those
  without `SUPER` while binary logging is on (error 1419), and the sandbox gate runs
  on production's MySQL, so the gate itself hits it. `device_offline` detection is a
  scheduled command scanning `last_seen_at` (below), **not** a DB trigger — keep it
  that way.

Disk is the real constraint, not a design flourish: 5-min shots ≈ 23 MB/employee/day
compressed; 50 employees × 30 d ≈ 34 GB. Three knobs stay tunable per org — capture
interval, JPEG quality/resolution, and retention — so the default can be dialled down
without a deploy. This is the calibration knob the physical box needs.

---

## Sub-project 1 — PC Specs (build this first)

Delivers value with **zero native code**: the in-app half needs nothing installed.

### What it shows

Layout chosen by the owner 2026-08-28 (preview: `docs/previews/monitoring-layouts.html`):
**a people-first roster as the working surface, with an at-a-glance panel docked on
the right.**

- **Roster (main pane):** the people in view (§3 subtree), each row showing status,
  role/franchise, and current module + dwell; expandable to that person's devices.
  Per device: label, capability set, OS/browser (from `user_agent`), IP at last beat,
  last seen. Hardware fields (RAM, disk free, wifi SSID/signal, open apps) render as
  "not available — web session" until an agent device reports them. Expansion is
  inline (no modal).
- **Glance panel (right, ~320px):** people-online count, a disk-low alert (which is
  the surfacing of the `monitoring.disk_low` trigger), and the list of agent devices
  with their status. It is a **read-out of the same data**, not a second source —
  every number is derived from the device rows, so the two cannot disagree.
- Filter chips over the roster: All / Online / Has agent / Web only.

The panel stacks **below** the roster at 375px (primary content first). Works at
375px, no modal, palette from `theme.dart`, refuses with a sentence (§4).

### Schema (migrations)

- `monitored_devices` — uuid pk, org id, user id, `device_key` (client stable id,
  unique per org+user), `label`, `platform` (web|android|ios|windows|macos|linux),
  `capabilities` json, `user_agent`, `ip_address`, `last_seen_at`, `current_module`,
  `specs` json (nullable — agent only), timestamps. `BelongsToTenant`, `HasUuids`,
  `StatesRetention` → `forever`.
- `monitoring_activity` — append log: uuid pk, org id, device id, user id,
  `module_key`, `dwell_seconds`, `occurred_at`. `CREATED_AT = null`, timed by
  `occurred_at`. `StatesRetention` → `window(3,'occurred_at',…)`.

(Screenshot table lands with Sub-project 2, not here.)

### Endpoints (`routes/api.php`, Monitoring block)

- `POST /api/v1/monitoring/heartbeat` — any authenticated client. Upserts the caller's
  `monitored_devices` row (device_key from body), appends a `monitoring_activity` row
  when the module changed or dwell accrued. Idempotent per beat. Gated by
  authentication + tenancy, not by `monitoring.view` (a user reports their own
  presence regardless of who may read it).
- `GET /api/v1/monitoring/devices` — list devices in the viewer's subtree. Gated
  `monitoring.view` / `specs.view` (specs blob only returned with `specs.view`).
- Both scoped through `TenantContext`; §3 `filingCompanyId` / subtree rules decide
  what a company-view vs org-view sees (copy the Inventory/Fleet/Phone worked example
  — no sibling leak; a company-view test with a sibling in `/me` is the proof).

### Client (`client/lib/screens/monitoring/`)

- `monitoring_screen.dart` + `pc_specs_tab.dart`. Registered in `module_screens.dart`.
- A small `MonitoringHeartbeat` service in `core/`: on route change and every 60s,
  POSTs the current module key + dwell + the device's declared capability set. Web
  declares `['activity']`; the desktop/mobile builds declare more when the agent
  half ships. Device key persisted in local storage (web) / secure storage (native).

### Automations

Emit `monitoring.new_device_seen` (first beat from an unseen device_key) and
`monitoring.device_offline` (a scheduled check: no beat within N× the cadence).
`device_offline` needs a scheduled command (`routes/console.php`) that scans
`last_seen_at` and inserts outbox rows — the same pattern every scheduled
sweep in the app uses.

### Tests (risk-proportional, MySQL gate)

- Heartbeat upserts one device row, not many; second beat updates in place.
- Activity appends only on module change / dwell; retention window is 3 months.
- `GET /devices` in a **company view does not list a sibling company's devices**
  (the §3 leak test — copy `inventory_screen_test.dart`'s shape).
- `specs.view` withheld → specs blob absent; granted → present.
- `new_device_seen` inserts exactly one outbox row on first sighting, none on repeat.
- Each: delete the guarded line, watch it go red, restore (StartUp §8).

---

## Sub-project 2 — Desktop Monitoring (later, own spec)

Rides the spine. Adds to the existing Flutter desktop app (windows/macos/linux
already build via `scripts/build-app.sh`) — **not a separate binary**; the app that's
already installed and signed gains native capability via platform channels.

- Specs: native readers for RAM/disk/OS/wifi/open-app titles → the `specs` blob the
  PC Specs tab already renders.
- Screenshots: capture every 5 min (configurable), hard-compressed JPEG to the
  storage path; reference row with the 30-day sweep. Gated `screenshots.view`.
- "Live view" v1: on-demand fast (1–2s) screenshots reusing the same capture path. No
  new transport.
- Background/autostart so it reports when the window is closed. **macOS caveat:**
  screen capture requires the user-granted Screen Recording permission, which is
  visible on that OS regardless of our silent default — note in the sub-project spec.

`// ponytail: real-time streaming + remote control is a TeamViewer-class build
(screen stream + per-OS input injection + NAT traversal). Deferred to its own slice;
WebRTC STUN-first, TURN only if P2P actually fails in practice. Do not build TURN
speculatively.`

## Sub-project 3 — Cameras (later, own spec, independent)

- v1: `monitoring_cameras` — config rows (label, org/site, `stream_url`,
  `stream_kind` = hls|mjpeg|snapshot|deeplink). Browser renders HLS/MJPEG; RTSP-only
  cameras store a snapshot URL + NVR deep-link. No server transcoding.
- v2: reuse the desktop agent as an on-site RTSP→WebRTC bridge for cameras on its LAN
  — video never crosses our box, no port-forwarding.
- Gated `cameras.manage` / `monitoring.view`.

---

## Four-invariant compliance

- **§3 hierarchy:** devices belong to a user who belongs to the subtree; the list
  filters by `filingCompanyId` / subtree, no sibling leak, no re-parent.
- **§6 settings cascade:** capture interval, screenshot quality, retention read
  through `SettingsResolver` with `allowedScopes` so a franchise can't weaken an
  org policy.
- **§4 gates:** every action a separate grantable permission; an offered control is a
  working control; refusals carry a server sentence.
- **§2.1 config categories:** capture interval / retention are **tenant settings**
  (the org's, everyone in it sees the same); nothing here is a user preference.

## Open questions (none block Sub-project 1)

- `device_offline` threshold — fixed multiple of cadence, or a tenant setting?
  Default: tenant setting, 3× cadence.
- Whether mobile builds report specs (Android battery/OS, iOS version) in slice 1 or
  with Sub-project 2. Default: OS version + last seen in slice 1 (cheap, no native),
  richer specs with the agent.
