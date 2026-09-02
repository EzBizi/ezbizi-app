import 'dart:async';
import 'dart:typed_data';

import 'package:flutter/foundation.dart' show debugPrint, kDebugMode;

import 'api_client.dart';
import 'device_key.dart';
import 'monitoring_capture_channel.dart';
import 'screenshot_capability.dart';

/// Live View — the AGENT half (2026-09-02-monitoring-live-view, Part A). The
/// viewer half lives in desktop_monitoring_tab.dart; see
/// app/Http/Controllers/Api/Monitoring/LiveViewController.php for the
/// contract both sides implement.
///
/// ── The loop, and why it is cheap when nobody is watching ─────────
///
/// Every [_interval] (~2s), this asks the server one question — `GET
/// /monitoring/live-wanted?device_key=...` — "is a manager watching THIS
/// device right now". The server's answer is `{"wanted": bool}`, backed by
/// nothing heavier than a 15-second cache key a viewer's own `watch` POST
/// keeps refreshed (see the controller's class doc comment). When the answer
/// is false — the overwhelming majority of ticks, for the overwhelming
/// majority of a shift — this does NOTHING else: no capture, no upload, one
/// small GET and back to sleep. Only when `wanted` is true does it capture a
/// frame (the same native channel [ScreenshotService] uses — see
/// monitoring_capture_channel.dart) and `POST /monitoring/live-frame`.
///
/// // ponytail: a flat 2s poll per agent, unconditionally, is fine at this
/// // scale — one small GET is not worth pushing a websocket server or a
/// // long-poll into this stack for. If the agent count grows enough that
/// // idle polling shows up as real load, the cheap next step is piggybacking
/// // the "am I wanted" check onto [MonitoringHeartbeat]'s existing 60s beat
/// // (add a field, read it there) rather than a second timer per device;
/// // actual push (websockets) is the step after that, and only worth it past
/// // that point.
///
/// ── Why `device_key`, not a device id ──────────────────────────────
///
/// Same reasoning as [ScreenshotService]: the agent only ever knows itself by
/// the key [DeviceKey.value] resolves, and both `liveWanted` and `liveFrame`
/// on the server resolve it the identical way `HeartbeatController` and
/// `ScreenshotsController::store` do — the caller's own (org, user,
/// device_key) triple, never anyone else's device.
///
/// ── Windows-only, and never on web ──────────────────────────────────
///
/// Same guard as [ScreenshotService], for the same reason: off Windows there
/// is no channel to invoke, so starting this timer anywhere else would just
/// poll `live-wanted` forever for a device that can never answer `wanted:
/// true` with anything. An explicitly injected [capture] bypasses the guard
/// on purpose — always a test.
class LiveViewService {
  LiveViewService(
    this._api, {
    Duration interval = const Duration(seconds: 2),
    Future<Uint8List?> Function()? capture,
  })  : _interval = interval,
        _capture = capture ?? _defaultCapture {
    if (capture == null && !screenshotCaptureSupported) return;

    _timer = Timer.periodic(_interval, (_) => unawaited(_pollAndMaybeUpload()));
  }

  final ApiClient _api;
  final Duration _interval;
  final Future<Uint8List?> Function() _capture;

  Timer? _timer;

  static Future<Uint8List?> _defaultCapture() =>
      captureMonitoringScreen(caller: 'LiveViewService');

  Future<void> _pollAndMaybeUpload() async {
    try {
      final deviceKey = await DeviceKey.value();

      final wantedResponse = await _api.get('/monitoring/live-wanted', {
        'device_key': deviceKey,
      });

      // Not ok covers a 404 (this device has never heartbeat-registered) and
      // any transient failure alike — neither is a reason to capture, and
      // there is nobody waiting on this tick's answer to hear about it.
      if (!wantedResponse.ok) return;
      if (wantedResponse.body['wanted'] != true) return;

      final bytes = await _capture();
      if (bytes == null || bytes.isEmpty) return;

      final upload = await _api.postFile(
        '/monitoring/live-frame',
        'image',
        'live.jpg',
        bytes,
        fields: {'device_key': deviceKey},
      );

      if (!upload.ok && kDebugMode) {
        debugPrint('LiveViewService: frame upload failed (${upload.statusCode}): '
            '${upload.message}');
      }
    } catch (e) {
      if (kDebugMode) {
        debugPrint('LiveViewService: poll/capture/upload failed: $e');
      }
    }
  }

  /// Stops the poll timer. Call this when whatever owns this instance goes
  /// away — same reasoning as [ScreenshotService.dispose].
  void dispose() {
    _timer?.cancel();
    _timer = null;
  }
}
