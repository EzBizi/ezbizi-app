import 'dart:async';

import 'api_client.dart';
import 'client_platform.dart';
import 'device_key.dart';
import 'screenshot_capability.dart';

/// A device reporting its own presence — POST /monitoring/heartbeat, the
/// endpoint HeartbeatController::store() validates (2026-08-28-monitoring-pc-
/// specs). See app/Http/Controllers/Api/Monitoring/HeartbeatController.php
/// for the body this has to match exactly:
///
///   device_key      required, string, max 64
///   platform         required, one of web/android/ios/windows/macos/linux
///   capabilities     required, array of a fixed enum
///   current_module   optional, string
///   segment          optional: {module_key, dwell_seconds (int, >= 0)}
///
/// ── What a "beat" is, and when one goes out ───────────────────────
///
/// [enter] is called every time somebody switches to a different module. It
/// does two things in one request: it reports the module just OPENED as
/// `current_module`, and — if a module was already open — it closes that
/// module's segment, reporting how long it was open as `dwell_seconds`. The
/// segment names the module being LEFT, never the one being entered; get that
/// backwards and every dwell reading in the roster describes the wrong tab
/// while still passing every validation rule on the server, which is why it
/// is asserted explicitly in the test rather than left to fall out of the
/// server accepting the body.
///
/// Separately, a 60-second [Timer.periodic] posts a keep-alive beat carrying
/// whatever `current_module` is current — no segment, because nothing was
/// closed. Its only job is to keep `last_seen_at` fresh for a person who has
/// been sitting on the same screen for minutes, who [enter] alone would never
/// hear from again.
///
/// ── Why a [Stopwatch] and not two [DateTime.now()] calls ──────────
///
/// A stopwatch is the built-in tool for "how long since I started timing",
/// and reads the monotonic clock rather than the wall clock — immune to a
/// system clock adjustment (NTP sync, a manual change, a DST transition)
/// producing a negative or wildly wrong dwell time mid-session.
///
/// ── Why `device_key` comes from [DeviceKey] rather than living here ───────
///
/// [DeviceKey.value] reads it from local storage (or generates it, on the
/// very first call anything in this process makes) and caches it — see that
/// class for why the cache lives there, shared, rather than as a field on
/// this class: [ScreenshotService] (2026-08-31-monitoring-screenshots, Task
/// 6) posts to a DIFFERENT endpoint that must resolve to the exact same
/// `monitored_devices` row this beat creates, which only holds if both read
/// the identical cached value rather than each generating their own.
///
/// ── Why `'screenshot'` is declared conditionally ──────────────────
///
/// [screenshotCaptureSupported] is the same Windows-only, native-channel
/// check [ScreenshotService] gates its own capture loop on (see that file).
/// Declaring it here from anything else — a hardcoded platform list, a
/// separate assumption about what this build can do — would let the two
/// drift: a beat that claims `'screenshot'` while the service that would
/// actually produce one never runs, or the reverse.
class MonitoringHeartbeat {
  MonitoringHeartbeat(this._api, {Duration interval = const Duration(seconds: 60)})
      : _interval = interval {
    // Started immediately, not on the first [enter]. A person who opens the
    // app and never touches the nav (the default screen is exactly where they
    // wanted to be) must still show up as present — the keep-alive is not
    // conditional on ever having changed modules.
    _timer = Timer.periodic(_interval, (_) => unawaited(_post()));
  }

  final ApiClient _api;
  final Duration _interval;

  /// `'activity'` always — every device that beats at all is reporting
  /// activity. `'screenshot'` on top of that only when this build can
  /// actually produce one; see the class doc comment above.
  static List<String> get _capabilities => [
        'activity',
        if (screenshotCaptureSupported) 'screenshot',
      ];

  Timer? _timer;
  String? _currentModule;
  final Stopwatch _dwell = Stopwatch();

  /// Somebody switched to [moduleKey]. Closes the previous module's segment
  /// (if there was one) and posts a beat naming the new module as
  /// `current_module`. The very first call in a session has no previous
  /// module to close, so it posts with no `segment` at all.
  Future<void> enter(String moduleKey) async {
    final segment = _closeSegment();

    _currentModule = moduleKey;
    _dwell
      ..reset()
      ..start();

    await _post(segment: segment);
  }

  /// The segment for the module being left, or null if this is the first
  /// module entered this session. Read the dwell BEFORE [_dwell] is reset for
  /// the module being entered — the caller does that reset itself, after this
  /// returns, precisely so this method never has to guess which stopwatch
  /// reading belongs to which module.
  Map<String, Object?>? _closeSegment() {
    final previous = _currentModule;
    if (previous == null) return null;

    return {
      'module_key': previous,
      'dwell_seconds': _dwell.elapsed.inSeconds,
    };
  }

  Future<void> _post({Map<String, Object?>? segment}) async {
    final body = <String, Object?>{
      'device_key': await DeviceKey.value(),
      'platform': ClientPlatform.name,
      'capabilities': _capabilities,
      if (_currentModule != null) 'current_module': _currentModule,
      'segment': ?segment,
    };

    await _api.post('/monitoring/heartbeat', body);
  }

  /// Stops the keep-alive timer. Call this when whatever owns this instance
  /// goes away — otherwise the timer keeps this object, and everything it
  /// closes over, alive and posting for the lifetime of the process.
  void dispose() {
    _timer?.cancel();
    _timer = null;
  }
}
