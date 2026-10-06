import 'dart:async';

import 'package:flutter/foundation.dart' show debugPrint, kDebugMode;

import 'api_client.dart';
import 'device_key.dart';
import 'monitoring_capture_channel.dart';
import 'screenshot_capability.dart';

/// A device's own periodic screen captures — POST /monitoring/screenshots,
/// the endpoint ScreenshotsController::store() validates
/// (2026-08-31-monitoring-screenshots, Task 6). See
/// app/Http/Controllers/Api/Monitoring/ScreenshotsController.php for the
/// multipart body this has to match exactly:
///
///   image        required file, jpeg, <= 2048KB
///   device_key   required, string, max 64
///
/// ── Where the bytes come from ──────────────────────────────────────
///
/// [_capture] calls the native `captureScreenshot` method on the
/// `com.ezbizi.app/monitoring_screenshot` channel (Task 5,
/// windows/runner/flutter_window.cpp / monitoring_screenshot.cpp), which
/// returns the primary monitor as JPEG bytes, or null on any failure (no
/// monitor, WIC/COM unavailable, an encode error) or on any platform that
/// isn't Windows, where the channel is never registered at all. Injectable
/// so tests never touch a real platform channel.
///
/// ── Why `device_key` comes from [DeviceKey] and not a field here ──────
///
/// This upload MUST resolve to the exact same `monitored_devices` row
/// [MonitoringHeartbeat]'s beat created — ScreenshotsController::store()
/// 404s outright rather than creating one (see its class doc comment for
/// why). The only way to guarantee that is for both to read the identical
/// cached value, which is what [DeviceKey.value] exists to do.
///
/// ── Why every attempt reports an outcome ───────────────────────────
///
/// A capture that fails is silent by nature: nobody is watching, there is no
/// screen to show an error on, and the debug log does not exist in a shipped
/// build. That silence is what made "this device can capture screenshots, but
/// none have landed yet" unanswerable — it is equally consistent with a
/// machine whose WIC JPEG encoder is missing, one whose user locks their
/// screen, and one that simply has not reached its first tick. So every
/// attempt, success or failure, hands its outcome to [_onOutcome] (the
/// heartbeat, in main.dart), which carries it to the server on the next beat.
/// See MonitoringHeartbeat.reportCapture.
///
/// ── Why this never throws ──────────────────────────────────────────
///
/// A capture or upload failure is not a foreground event — nobody is
/// watching a screenshot go out, and there is no screen to show an error on.
/// [ApiClient.postFile] already swallows its own transport failures into an
/// [ApiResponse]; this wraps the capture call the same way and logs both
/// under [kDebugMode], never lets either propagate to the [Timer.periodic]
/// callback (an uncaught error there would be silently dropped by the zone
/// anyway, but there is no reason to depend on that).
class ScreenshotService {
  ScreenshotService(
    this._api, {
    Duration interval = const Duration(minutes: 5),
    Future<CaptureAttempt> Function()? capture,
    void Function({required bool ok, String? reason, int? captureMs})?
        onOutcome,
  })  : _interval = interval,
        _capture = capture ?? _defaultCapture,
        _onOutcome = onOutcome {
    // Windows-guarded, but only for the DEFAULT (native-channel) capture
    // path: off Windows (including web) there is no channel to invoke at
    // all — windows/runner is the only platform runner that registers
    // `com.ezbizi.app/monitoring_screenshot` — so a real deployment starting
    // this timer there would just capture null every cycle for nothing.
    //
    // An explicitly INJECTED [capture] bypasses the guard on purpose: it is
    // always a test (or a future non-native capture source) supplying its
    // own answer, so whether this timer runs should depend on that caller's
    // choice, not on whatever platform happens to be running the test.
    if (capture == null && !screenshotCaptureSupported) return;

    _timer = Timer.periodic(_interval, (_) => unawaited(_captureAndUpload()));
  }

  final ApiClient _api;
  final Duration _interval;
  final Future<CaptureAttempt> Function() _capture;

  /// Told the result of every attempt. Null in tests that do not care and in
  /// any future caller with nowhere to report — reporting is a courtesy to
  /// whoever is looking at the roster, never a precondition for capturing.
  final void Function({required bool ok, String? reason, int? captureMs})?
      _onOutcome;

  Timer? _timer;

  // The shared channel in monitoring_capture_channel.dart — see this class's
  // doc comment above ("Where the bytes come from") for why this and
  // [LiveViewService] call the same function rather than each invoking their
  // own MethodChannel.
  static Future<CaptureAttempt> _defaultCapture() =>
      captureMonitoringScreen(caller: 'ScreenshotService');

  Future<void> _captureAndUpload() async {
    try {
      // Time the native capture only — the thing that runs on the platform
      // thread and can stall. The upload after it is network, measured nowhere
      // here. capture_ms rides the heartbeat so production shows which machines
      // are slow to grab a frame.
      final sw = Stopwatch()..start();
      final attempt = await _capture();
      final captureMs = sw.elapsedMilliseconds;
      final bytes = attempt.bytes;

      if (bytes == null || bytes.isEmpty) {
        _report(ok: false, reason: attempt.reason ?? 'empty_capture', captureMs: captureMs);
        return;
      }

      final deviceKey = await DeviceKey.value();

      final response = await _api.postFile(
        '/monitoring/screenshots',
        'image',
        'screenshot.jpg',
        bytes,
        fields: {'device_key': deviceKey},
      );

      if (!response.ok) {
        // A capture that succeeded and an upload that was refused are
        // different faults with different fixes, so the status code is kept
        // rather than flattened into a generic failure: `upload_404` means
        // the device row is missing, `upload_413`/`upload_422` mean the
        // frame itself was rejected, `upload_403` is the exemption.
        _report(ok: false, reason: 'upload_${response.statusCode}', captureMs: captureMs);

        if (kDebugMode) {
          debugPrint('ScreenshotService: upload failed (${response.statusCode}): '
              '${response.message}');
        }
        return;
      }

      _report(ok: true, reason: null, captureMs: captureMs);
    } catch (e) {
      _report(ok: false, reason: 'capture_failed');

      if (kDebugMode) {
        debugPrint('ScreenshotService: capture/upload failed: $e');
      }
    }
  }

  /// Never lets a reporting failure become a capture failure — [_onOutcome]
  /// is somebody else's code, and this runs inside a timer callback.
  void _report({required bool ok, String? reason, int? captureMs}) {
    try {
      _onOutcome?.call(ok: ok, reason: reason, captureMs: captureMs);
    } catch (_) {
      // Deliberately swallowed: see above.
    }
  }

  /// Stops the capture timer. Call this when whatever owns this instance
  /// goes away — otherwise the timer keeps this object, and everything it
  /// closes over, alive and capturing for the lifetime of the process.
  void dispose() {
    _timer?.cancel();
    _timer = null;
  }
}
