import 'dart:async';
import 'dart:typed_data';

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
    Future<Uint8List?> Function()? capture,
  })  : _interval = interval,
        _capture = capture ?? _defaultCapture {
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
  final Future<Uint8List?> Function() _capture;

  Timer? _timer;

  // The shared channel in monitoring_capture_channel.dart — see this class's
  // doc comment above ("Where the bytes come from") for why this and
  // [LiveViewService] call the same function rather than each invoking their
  // own MethodChannel.
  static Future<Uint8List?> _defaultCapture() =>
      captureMonitoringScreen(caller: 'ScreenshotService');

  Future<void> _captureAndUpload() async {
    try {
      final bytes = await _capture();
      if (bytes == null || bytes.isEmpty) return;

      final deviceKey = await DeviceKey.value();

      final response = await _api.postFile(
        '/monitoring/screenshots',
        'image',
        'screenshot.jpg',
        bytes,
        fields: {'device_key': deviceKey},
      );

      if (!response.ok && kDebugMode) {
        debugPrint('ScreenshotService: upload failed (${response.statusCode}): '
            '${response.message}');
      }
    } catch (e) {
      if (kDebugMode) {
        debugPrint('ScreenshotService: capture/upload failed: $e');
      }
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
