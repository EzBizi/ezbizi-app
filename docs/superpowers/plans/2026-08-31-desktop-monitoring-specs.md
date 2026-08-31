# Desktop Monitoring — Specs slice — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** The Windows EzBizi app reports its own RAM / disk / OS / wifi through the presence heartbeat, so the PC Specs tab's "not available" fields turn real for a device running the desktop app.

**Architecture:** A C++ `MethodChannel` in the Windows runner reads the four values from Win32/WLAN. A thin, `Platform.isWindows`-guarded Dart `SpecsReader` invokes it; `MonitoringHeartbeat` (SP1) holds the last reading, refreshes ~every 15 min, and includes it in the beat (adding the `'specs'` capability) only when present. No backend change — SP1's server + tab already handle a `specs` blob.

**Tech Stack:** Flutter (Dart) client · Windows runner C++ (Win32 + WLAN API) · SP1's `MonitoringHeartbeat` + `HeartbeatController`.

**Spec:** `docs/superpowers/specs/2026-08-31-desktop-monitoring-specs-design.md`

## Global Constraints

- **Windows-first.** Native code lives only in `client/windows/runner/`. `SpecsReader` is guarded by `Platform.isWindows` and returns `null` on every other platform, so web/macOS/Linux/Android/iOS builds are untouched and declare no `'specs'`.
- **Exact `specs` keys — a contract with the shipped SP1 tab** (`_SpecsLine` in `pc_specs_tab.dart`); a wrong key renders nothing: `ram_gb` (total RAM, int GB), `disk_free_gb` (int GB), `os_version` (string, e.g. "Windows 11 26100"), `wifi_ssid` (string), `wifi_signal_dbm` (int dBm). Do NOT send `open_apps` — deferred.
- **Wifi signal is dBm, converted from WLAN quality:** `dbm = quality / 2 - 100` (quality 0–100 → −100…−50).
- **Never crash or block over a specs read.** A failed native field → that field absent; whole-read/non-Windows failure → `null` → the device stays activity-only. The read is async and never blocks a beat.
- **Freeze-safe & additive:** no backend, no migration, no route, no new client screen. The heartbeat only gains an optional `specs` field the server already accepts (SP1 Task 4). iOS keeps sending `['activity']`.
- **Tests:** `cd client && flutter analyze` (clean) and `flutter test` (green, incl. the existing SP1 heartbeat/tab suites). The Dart wire is unit-tested with an injected reader; the C++ has no harness test (see Task 2).
- **Worktree:** cut a fresh worktree at execution time (`scripts/session-worktree.sh monitoring-specs`); `session/monitoring` is mid-merge — do NOT build on it. Commit there; hand SHAs to `ezbizi-a1`.

---

## File Structure

- Create: `client/lib/core/specs_reader.dart` — the `Platform.isWindows`-guarded channel wrapper.
- Modify: `client/lib/core/monitoring_heartbeat.dart` — hold + refresh specs; include in the beat.
- Modify: `client/test/monitoring_heartbeat_test.dart` — inject a fake reader; assert the wire.
- Create: `client/windows/runner/monitoring_specs.h` / `monitoring_specs.cpp` — the native handler.
- Modify: `client/windows/runner/flutter_window.{h,cpp}` — register the channel (store it to outlive OnCreate).
- Modify: `client/windows/runner/CMakeLists.txt` — link `wlanapi`.

---

## Task 1: Dart wire — SpecsReader + heartbeat holds/sends specs

**Files:**
- Create: `client/lib/core/specs_reader.dart`
- Modify: `client/lib/core/monitoring_heartbeat.dart`
- Test: `client/test/monitoring_heartbeat_test.dart`

**Interfaces:**
- Produces: `SpecsReader.read() -> Future<Map<String,dynamic>?>` (null off-Windows / on failure). `MonitoringHeartbeat(this._api, {Duration interval, SpecsRead? readSpecs})` — `typedef SpecsRead = Future<Map<String,dynamic>?> Function()`. A public `Future<void> refreshSpecs()` for deterministic tests. When a specs value is held, a beat's `capabilities` is `['activity','specs']` and the body carries `specs`; otherwise `['activity']` and no `specs` key.
- Consumes: SP1's `MonitoringHeartbeat._post`, `ClientPlatform.name`, the existing fake-ApiClient test pattern in `monitoring_heartbeat_test.dart`.

- [ ] **Step 1: Write the failing tests** (add to `monitoring_heartbeat_test.dart`, reusing its existing `MockClient`/ApiClient fake — match how the current tests capture posted bodies)

```dart
test('a beat carries specs and the specs capability when the reader returns them', () async {
  final posts = <Map<String, dynamic>>[];              // adapt to the file's existing capture helper
  final hb = MonitoringHeartbeat(
    apiThatRecordsInto(posts),
    readSpecs: () async => {'ram_gb': 16, 'os_version': 'Windows 11 26100'},
  );
  await hb.refreshSpecs();          // deterministic: force the read before beating
  hb.enter('jobs');                 // triggers a post
  await pumpEventQueue();           // let the async post run
  expect(posts.last['specs'], {'ram_gb': 16, 'os_version': 'Windows 11 26100'});
  expect(posts.last['capabilities'], contains('specs'));
  hb.dispose();
});

test('a beat omits specs and stays activity-only when the reader returns null', () async {
  final posts = <Map<String, dynamic>>[];
  final hb = MonitoringHeartbeat(apiThatRecordsInto(posts), readSpecs: () async => null);
  await hb.refreshSpecs();
  hb.enter('jobs');
  await pumpEventQueue();
  expect(posts.last.containsKey('specs'), isFalse);
  expect(posts.last['capabilities'], ['activity']);
  hb.dispose();
});
```

Match `apiThatRecordsInto`/`pumpEventQueue` to the file's existing idiom (the SP1 tests already build an ApiClient over a `MockClient` and read `jsonDecode(request.body)` — reuse that exactly).

- [ ] **Step 2: Run, verify they fail**

Run: `cd client && flutter test test/monitoring_heartbeat_test.dart`
Expected: FAIL — `readSpecs`/`refreshSpecs` not defined.

- [ ] **Step 3: Create `specs_reader.dart`**

```dart
import 'dart:io' show Platform;

import 'package:flutter/services.dart';

/// Reads this machine's hardware/OS/network specs over the Windows native
/// channel. Returns null on any non-Windows platform, and null (never throws)
/// if the native handler is absent or a read fails — an old Windows build or a
/// transient failure must degrade to activity-only, not crash.
class SpecsReader {
  const SpecsReader();

  static const MethodChannel _channel = MethodChannel('com.ezbizi.app/monitoring_specs');

  Future<Map<String, dynamic>?> read() async {
    if (!Platform.isWindows) return null;
    try {
      final result = await _channel.invokeMapMethod<String, dynamic>('readSpecs');
      return (result == null || result.isEmpty) ? null : result;
    } on PlatformException {
      return null;
    } on MissingPluginException {
      return null;
    }
  }
}
```

- [ ] **Step 4: Wire into `monitoring_heartbeat.dart`**

Add near the top:

```dart
import 'specs_reader.dart';

typedef SpecsRead = Future<Map<String, dynamic>?> Function();
```

In the class: accept the reader, hold the last value, refresh on a 15-min timer, and include it in the body.

```dart
// constructor — add the named param and default it to the real reader:
MonitoringHeartbeat(this._api,
    {Duration interval = const Duration(seconds: 60), SpecsRead? readSpecs})
    : _interval = interval,
      _readSpecs = readSpecs ?? const SpecsReader().read {
  _timer = Timer.periodic(_interval, (_) => unawaited(_post()));
  unawaited(refreshSpecs());                                   // first read, non-blocking
  _specsTimer = Timer.periodic(const Duration(minutes: 15), (_) => unawaited(refreshSpecs()));
}

final SpecsRead _readSpecs;
Map<String, dynamic>? _lastSpecs;
Timer? _specsTimer;

/// Read specs now and hold the result. Keeps the previous value on a null read
/// (a transient failure should not blank a device that was reporting specs).
Future<void> refreshSpecs() async {
  final specs = await _readSpecs();
  if (specs != null) _lastSpecs = specs;
}
```

In `dispose()`, add `_specsTimer?.cancel();`.

In `_post`, build capabilities/specs from the held value (replaces the `'capabilities': _capabilities` line and adds the specs key):

```dart
Future<void> _post({Map<String, Object?>? segment}) async {
  final specs = _lastSpecs;
  final body = <String, Object?>{
    'device_key': await _deviceKeyValue(),
    'platform': ClientPlatform.name,
    'capabilities': specs != null ? const ['activity', 'specs'] : const ['activity'],
    if (_currentModule != null) 'current_module': _currentModule,
    'segment': ?segment,
    if (specs != null) 'specs': specs,
  };
  await _api.post('/monitoring/heartbeat', body);
}
```

Remove the now-unused `static const List<String> _capabilities = ['activity'];` if nothing else references it (grep first).

- [ ] **Step 5: Run tests, verify pass**

Run: `cd client && flutter analyze && flutter test test/monitoring_heartbeat_test.dart`
Expected: PASS. Then flip the beat to send specs unconditionally (drop the `if (specs != null)` guards) and watch the activity-only test go RED; restore.

- [ ] **Step 6: Commit**

```bash
git add client/lib/core/specs_reader.dart client/lib/core/monitoring_heartbeat.dart \
        client/test/monitoring_heartbeat_test.dart
git commit -m "Monitoring: the client reports specs when the native reader has them"
```

---

## Task 2: Native Windows handler — the readSpecs channel

**Files:**
- Create: `client/windows/runner/monitoring_specs.h`, `client/windows/runner/monitoring_specs.cpp`
- Modify: `client/windows/runner/flutter_window.h` (hold the channel), `flutter_window.cpp` (register it in `OnCreate`)
- Modify: `client/windows/runner/CMakeLists.txt` (link `wlanapi`)

**Interfaces:**
- Consumes: nothing from Task 1 at compile time (separate process boundary); it must SATISFY the contract Task 1's `SpecsReader` calls — channel `com.ezbizi.app/monitoring_specs`, method `readSpecs`, returning an `EncodableMap` with the keys in Global Constraints (a field absent when its source fails).
- Produces: the live native channel handler.

- [ ] **Step 1: Write `monitoring_specs.h`**

```cpp
#ifndef RUNNER_MONITORING_SPECS_H_
#define RUNNER_MONITORING_SPECS_H_

#include <flutter/flutter_engine.h>
#include <flutter/method_channel.h>
#include <flutter/standard_method_codec.h>
#include <memory>

// Registers the com.ezbizi.app/monitoring_specs channel on the engine and
// returns the channel, which the caller MUST keep alive (the handler is only
// live while the channel object exists).
std::unique_ptr<flutter::MethodChannel<flutter::EncodableValue>>
RegisterMonitoringSpecsChannel(flutter::FlutterEngine* engine);

#endif  // RUNNER_MONITORING_SPECS_H_
```

- [ ] **Step 2: Write `monitoring_specs.cpp`** (the four Win32/WLAN reads; each failure just omits its keys)

```cpp
#include "monitoring_specs.h"

#include <windows.h>
#include <wlanapi.h>

#include <string>

namespace {

using flutter::EncodableMap;
using flutter::EncodableValue;

typedef LONG(WINAPI* RtlGetVersionPtr)(PRTL_OSVERSIONINFOW);

EncodableMap ReadSpecs() {
  EncodableMap m;

  MEMORYSTATUSEX mem{};
  mem.dwLength = sizeof(mem);
  if (GlobalMemoryStatusEx(&mem)) {
    m[EncodableValue("ram_gb")] =
        EncodableValue(static_cast<int64_t>(mem.ullTotalPhys / (1024ULL * 1024 * 1024)));
  }

  wchar_t sysdir[MAX_PATH] = {0};
  if (GetWindowsDirectoryW(sysdir, MAX_PATH) >= 3) {
    sysdir[3] = L'\0';  // "C:\"
    ULARGE_INTEGER freeBytes{};
    if (GetDiskFreeSpaceExW(sysdir, &freeBytes, nullptr, nullptr)) {
      m[EncodableValue("disk_free_gb")] =
          EncodableValue(static_cast<int64_t>(freeBytes.QuadPart / (1024ULL * 1024 * 1024)));
    }
  }

  RTL_OSVERSIONINFOW vi{};
  vi.dwOSVersionInfoSize = sizeof(vi);
  if (HMODULE ntdll = GetModuleHandleW(L"ntdll.dll")) {
    if (auto rtl = reinterpret_cast<RtlGetVersionPtr>(GetProcAddress(ntdll, "RtlGetVersion"));
        rtl && rtl(&vi) == 0) {
      std::string name = vi.dwBuildNumber >= 22000 ? "Windows 11" : "Windows 10";
      m[EncodableValue("os_version")] =
          EncodableValue(name + " " + std::to_string(vi.dwBuildNumber));
    }
  }

  DWORD negotiated = 0;
  HANDLE wlan = nullptr;
  if (WlanOpenHandle(2, nullptr, &negotiated, &wlan) == ERROR_SUCCESS) {
    PWLAN_INTERFACE_INFO_LIST list = nullptr;
    if (WlanEnumInterfaces(wlan, nullptr, &list) == ERROR_SUCCESS && list->dwNumberOfItems > 0) {
      GUID guid = list->InterfaceInfo[0].InterfaceGuid;
      PWLAN_CONNECTION_ATTRIBUTES attr = nullptr;
      DWORD size = 0;
      WLAN_OPCODE_VALUE_TYPE opcode;
      if (WlanQueryInterface(wlan, &guid, wlan_intf_opcode_current_connection, nullptr,
                             &size, reinterpret_cast<PVOID*>(&attr), &opcode) == ERROR_SUCCESS &&
          attr && attr->isState == wlan_interface_state_connected) {
        const auto& ssid = attr->wlanAssociationAttributes.dot11Ssid;
        m[EncodableValue("wifi_ssid")] = EncodableValue(
            std::string(reinterpret_cast<const char*>(ssid.ucSSID), ssid.uSSIDLength));
        int quality = static_cast<int>(attr->wlanAssociationAttributes.wlanSignalQuality);
        m[EncodableValue("wifi_signal_dbm")] = EncodableValue(static_cast<int64_t>(quality / 2 - 100));
      }
      if (attr) WlanFreeMemory(attr);
    }
    if (list) WlanFreeMemory(list);
    WlanCloseHandle(wlan, nullptr);
  }

  return m;
}

}  // namespace

std::unique_ptr<flutter::MethodChannel<flutter::EncodableValue>>
RegisterMonitoringSpecsChannel(flutter::FlutterEngine* engine) {
  auto channel = std::make_unique<flutter::MethodChannel<flutter::EncodableValue>>(
      engine->messenger(), "com.ezbizi.app/monitoring_specs",
      &flutter::StandardMethodCodec::GetInstance());
  channel->SetMethodCallHandler(
      [](const flutter::MethodCall<flutter::EncodableValue>& call,
         std::unique_ptr<flutter::MethodResult<flutter::EncodableValue>> result) {
        if (call.method_name() == "readSpecs") {
          result->Success(EncodableValue(ReadSpecs()));
        } else {
          result->NotImplemented();
        }
      });
  return channel;
}
```

- [ ] **Step 3: Register it in the runner, keeping the channel alive**

In `flutter_window.h`, add the include and a member so the channel outlives `OnCreate`:

```cpp
#include <flutter/method_channel.h>
#include "monitoring_specs.h"
// ... in the private section of FlutterWindow:
std::unique_ptr<flutter::MethodChannel<flutter::EncodableValue>> monitoring_specs_channel_;
```

In `flutter_window.cpp`, `OnCreate()`, right after `RegisterPlugins(flutter_controller_->engine());`:

```cpp
monitoring_specs_channel_ =
    RegisterMonitoringSpecsChannel(flutter_controller_->engine());
```

- [ ] **Step 4: Link the WLAN library** — in `client/windows/runner/CMakeLists.txt`, add `monitoring_specs.cpp` to the runner sources (next to the other `.cpp` files) and link `wlanapi`:

```cmake
target_link_libraries(${BINARY_NAME} PRIVATE wlanapi)
```

- [ ] **Step 5: Build and verify manually** (no Flutter-harness test can drive real Win32 — this is the `ponytail:` note in the spec)

Run: `cd client && flutter build windows --release`
Expected: compiles and links (wlanapi resolved). Then run the built app on a real Windows machine signed into EzBizi with `monitoring.specs.view`, open Monitoring → PC Specs, expand your own device, and confirm real RAM / disk free / OS / Wi-Fi values render (not "not reported"). Record what you observed in the report — this is the only verification the native path gets.

- [ ] **Step 6: Commit**

```bash
git add client/windows/runner/monitoring_specs.h client/windows/runner/monitoring_specs.cpp \
        client/windows/runner/flutter_window.h client/windows/runner/flutter_window.cpp \
        client/windows/runner/CMakeLists.txt
git commit -m "Monitoring: Windows native readSpecs channel — RAM, disk, OS, wifi"
```

---

## Self-Review

**Spec coverage:**
- Native RAM/disk/OS/wifi via C++ channel → Task 2. ✓
- Exact tab keys (`ram_gb`/`disk_free_gb`/`os_version`/`wifi_ssid`/`wifi_signal_dbm`), dBm conversion → Global Constraints + Task 2's `ReadSpecs`. ✓
- `Platform.isWindows` guard, null-on-failure, never block/crash → Task 1's `SpecsReader` + `refreshSpecs` (keeps prior value on null). ✓
- Held value + ~15-min refresh, `'specs'` capability only when present → Task 1 heartbeat changes. ✓
- No backend/migration/route/screen change; freeze-safe → true by construction (only client files touched). ✓
- `open_apps` NOT sent → confirmed (not in `ReadSpecs`). ✓
- C++ has no harness test → Task 2 Step 5 states the manual verification + the spec's `ponytail` note. ✓

**Placeholder scan:** Task 1's test references `apiThatRecordsInto`/`pumpEventQueue` as the file's existing idiom to reuse rather than reproduced verbatim — this is a pointer to a named, existing pattern (the SP1 heartbeat tests), not a vague placeholder; the assertions themselves are concrete.

**Type consistency:** `readSpecs`/`SpecsRead`/`refreshSpecs`/`_lastSpecs`/`_readSpecs` are spelled identically across Task 1. The channel name `com.ezbizi.app/monitoring_specs` and method `readSpecs` match between `SpecsReader` (Task 1) and `RegisterMonitoringSpecsChannel` (Task 2). The five spec keys match the spec's contract table and the tab.
