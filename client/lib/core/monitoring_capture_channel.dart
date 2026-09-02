import 'dart:async';
import 'dart:typed_data';

import 'package:flutter/foundation.dart' show debugPrint, kDebugMode;
import 'package:flutter/services.dart' show MethodChannel;

/// The ONE platform channel every screen-capture path in this client calls —
/// [ScreenshotService]'s periodic 30-day capture (2026-08-31-monitoring-
/// screenshots, Task 6) and [LiveViewService]'s live-view capture
/// (2026-09-02-monitoring-live-view, Part A) both call [captureMonitoringScreen]
/// rather than each invoking their own `MethodChannel`.
///
/// ── Why this is pulled out rather than left inside ScreenshotService ──
///
/// Two callers needing "a JPEG of the primary monitor" is two chances for the
/// channel name or the method name to drift apart — a typo in a second,
/// independently-written `invokeMethod('captureScreenshot')` would fail
/// silently (any error is swallowed below) rather than loudly, and nothing
/// would catch it until a live view sat on "Waiting for the live feed…"
/// forever. One function, called from both places, makes that class of bug
/// impossible rather than merely unlikely.
///
/// windows/runner/flutter_window.cpp / monitoring_screenshot.cpp is the only
/// platform runner that registers this channel — every other platform (web
/// included) has no native code for it at all, which is why every caller of
/// this function gates behind `screenshotCaptureSupported`
/// (screenshot_capability.dart) before ever constructing a timer that would
/// reach it.
const MethodChannel monitoringScreenshotChannel =
    MethodChannel('com.ezbizi.app/monitoring_screenshot');

/// Invokes the channel above and returns the primary monitor as JPEG bytes,
/// or null on any failure (no monitor, WIC/COM unavailable, an encode error)
/// — never throws, so a caller's own `Timer.periodic` callback is never at
/// risk of an uncaught error from this.
///
/// [caller] is only for the debug log line, so a failure in the log can be
/// told apart between the two things that call this.
Future<Uint8List?> captureMonitoringScreen({String caller = 'capture'}) async {
  try {
    return await monitoringScreenshotChannel.invokeMethod<Uint8List>('captureScreenshot');
  } catch (e) {
    if (kDebugMode) {
      debugPrint('$caller: native capture failed: $e');
    }
    return null;
  }
}
