# Desktop Monitoring — Specs slice — design

Date: 2026-08-31. Status: approved to build. Owner: this session.
Parent: `docs/superpowers/specs/2026-08-28-monitoring-module-design.md` (Sub-project 2).

Desktop Monitoring is Sub-project 2 of the Monitoring module. It gives the
already-installed EzBizi **Windows** desktop app native capability it does not
have today — there is no `MethodChannel` and no native-info code in the client yet,
so this is greenfield native work, not a switch to flip.

This spec covers **only the first slice: Specs**. It reads the machine's hardware,
OS, and network and reports them through the presence heartbeat SP1 already built,
so the PC Specs tab's grey "not available — web session" fields turn real for a
device running the Windows app.

---

## Decisions locked before this spec (owner, 2026-08-31)

1. **Windows first.** The desktop app is already installed on office/shop PCs (Inno
   installer, `scripts/windows-installer.iss`). Native code targets Windows; other
   platforms keep reporting `['activity']` only.
2. **Specs first, then screenshots, then live view.** Each its own spec → plan →
   build. Remote control / real-time streaming stays deferred (parent spec, SP2
   `ponytail` note).
3. **Specs while the app is running** — not always-on. The background/tray +
   start-on-boot "agent runtime" is its own **next** slice (shared by specs and
   screenshots). Until it lands, closing the app stops reporting. Accepted, staged.
4. **Native approach: a hand-written C++ platform channel** — no new pub.dev
   dependencies, deterministic, and it establishes the native bridge the screenshots
   slice will reuse for screen capture.
5. **Four values:** RAM, disk free, OS version, wifi (SSID + signal) — exactly what
   the tab renders. Refresh ~every 15 minutes (specs barely change), not per beat.
6. **"Open apps / active window"** is NOT in this slice — it is continuously-changing
   behavioral data that groups with screenshots/activity, not hardware inventory.

---

## Scope of the whole Sub-project 2 (context, not this build)

| Slice | Delivers | This spec? |
|---|---|---|
| **Specs** | RAM/disk/OS/wifi into the tab, while the app runs | **yes** |
| Agent runtime | background/tray + start-on-boot (always reporting) | no — next |
| Screenshots | 5-min capture + storage + 30-day sweep + viewer + `screenshots.view` | no |
| Live view | on-demand fast screenshots | no |
| Remote control | real-time stream + input injection | deferred (own slice) |

---

## Architecture

Client-only. **No backend change, no migration, no new endpoint** — SP1's
`HeartbeatController` already stores a `specs` blob when `capabilities` contains
`'specs'`, the devices read endpoint already returns it behind `monitoring.specs.view`,
and the PC Specs tab already renders RAM/disk/wifi.

### 1. Native — Windows runner (C++)

A new `MethodChannel` named `com.ezbizi.app/monitoring_specs` with one method,
`readSpecs`, handled in `client/windows/runner/` (a new `monitoring_specs.{h,cpp}`,
registered where the runner sets up channels — `flutter_window.cpp`). Each value is
an independent Win32/WLAN call; a failure yields `null` for that field, never a
whole-read failure, never a crash:

**The keys are a contract with the shipped SP1 tab, not free choice.** `_SpecsLine`
in `pc_specs_tab.dart` reads these exact keys; a mismatch (`ram_total_gb` vs `ram_gb`)
renders nothing and fails silently. Verified against the tab on 2026-08-31:

| Key (exact) | Source | Note |
|---|---|---|
| `ram_gb` | `GlobalMemoryStatusEx` → `ullTotalPhys` | total physical RAM, bytes → GB. Tab renders "N GB RAM". |
| `disk_free_gb` | `GetDiskFreeSpaceExW` on the system drive (`%SystemDrive%`) | free bytes → GB |
| `os_version` | `RtlGetVersion` (ntdll) | e.g. "Windows 11 26100". `GetVersionEx` lies without a manifest — do not use it. |
| `wifi_ssid` | WLAN API: `WlanOpenHandle` → `WlanEnumInterfaces` → `WlanQueryInterface(wlan_intf_opcode_current_connection)` | null when no adapter / not connected |
| `wifi_signal_dbm` | same WLAN query → `wlanAssociationAttributes.wlanSignalQuality` (0–100) | convert to dBm: `dbm = quality/2 − 100` (0 → −100, 100 → −50). The tab renders "$signal dBm", so send dBm, not the raw quality. Null with `wifi_ssid`. |

`open_apps` (a `List<String>`) is ALSO a key the tab already renders ("Open: …"), but
it is **deferred to a later slice** (behavioral, not hardware) — this slice never sends
it, and the tab simply omits that line. Returns a Dart-decodable map; a missing/failed
field is absent or null. Link `wlanapi.lib`; `RtlGetVersion` is resolved from `ntdll`
at runtime.

### 2. Dart — `client/lib/core/`

A thin `SpecsReader`:
- `Future<Map<String, dynamic>?> read()` — invokes the channel **only when
  `Platform.isWindows`**; returns `null` on every other platform (web, macOS, Linux,
  Android, iOS) so those builds never declare `'specs'`.
- Swallows a `MissingPluginException`/`PlatformException` into `null` (an old Windows
  build without the native handler must degrade to activity-only, not crash).

`MonitoringHeartbeat` (SP1, `monitoring_heartbeat.dart`) gains a held `specs` value:
- On construction and every ~15 minutes, calls `SpecsReader.read()`; stores the last
  non-null result.
- When posting a beat, if `specs` is non-null, includes it in the body and adds
  `'specs'` to `capabilities` (which is `['activity']` today). If null, the beat is
  unchanged — `['activity']`, no `specs` key.

### 3. Server + tab — unchanged

Named to be explicit: nothing in `app/`, no migration, no route, no client screen
changes. The wire contract SP1 validated (`specs` stored only with the capability;
returned only with `monitoring.specs.view`) already fits.

---

## Data flow

```
[15-min timer / startup]         [every 60s beat / nav segment]
  SpecsReader.read()  ──────────►  heartbeat holds last specs
  (Windows MethodChannel)               │
                                        ▼
   POST /monitoring/heartbeat  { device_key, platform:'windows',
       capabilities:['activity','specs'],
       specs:{ram_gb, disk_free_gb, os_version, wifi_ssid, wifi_signal_dbm}, … }
                                        │
                                        ▼
   HeartbeatController (SP1) stores specs → DevicesController returns it
   (with monitoring.specs.view) → PC Specs tab renders RAM/disk/wifi
```

## Error handling

- Per-field: a failed native call → that field null; the rest still report.
- Whole-channel failure (unexpected) or non-Windows → `read()` returns null → the
  device stays activity-only. Never crash the app over a specs read; never block the
  beat on the read (hold the last value, refresh async).
- An old Windows build (native handler absent) → `MissingPluginException` → null →
  activity-only. Forward-compatible.

## Testing

- **Dart contract test** (real, non-vacuous): mock the MethodChannel
  (`TestDefaultBinaryMessengerBinding…setMockMethodCallHandler`). Assert: (a) channel
  returns a specs map → the next beat body carries `specs` AND `capabilities` contains
  `'specs'`; (b) channel returns null / throws `PlatformException` → beat has no
  `specs` key and `capabilities` stays `['activity']`. It must fail if the heartbeat
  sent specs unconditionally or dropped the capability.
- **`// ponytail:`** the C++ handler has no unit test — the Flutter test harness cannot
  drive the real Win32 calls. It is covered by the Dart contract (the wire shape) plus
  a manual run of the Windows build confirming real values land in the tab. Upgrade
  path if this ever burns: a tiny native test exe, not a mock that proves nothing.
- `flutter analyze` clean; existing SP1 heartbeat/tab tests stay green (the beat's
  shape only gains an optional field they already tolerate).

## Platform / freeze safety

- The native code lives in `client/windows/runner/` — it never compiles into the iOS
  `.ipa`, so the App Store review freeze is untouched. iOS keeps sending
  `['activity']` with no specs; server behavior is identical.
- `SpecsReader` is `Platform.isWindows`-guarded, so macOS/Linux/web/Android builds are
  unaffected and declare no specs.
- Additive only: a new channel, a new Dart file, an optional field already accepted by
  the server. No response shape changes, no permission changes, no migration.

## Deferred (named, so nothing is silently dropped)

- **Always-on** (background/tray + start-on-boot) — the next slice. Until then, specs
  report only while the app is open.
- **Open apps / active window**, **screenshots**, **live view**, **remote control** —
  later slices per the parent spec.
- **macOS/Linux specs** — Windows-first; the channel pattern ports later, macOS adding
  the Screen Recording permission wrinkle only when screenshots reach it.

## Open questions (none block this slice)

- Whether `os` should also carry the edition ("Pro"/"Home") — default no, build is
  enough; add if an admin asks.
- Disk: system drive only, or all fixed drives — default system drive (what "disk
  free" means to a person); revisit if multi-disk machines matter.
