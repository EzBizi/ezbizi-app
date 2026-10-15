import 'dart:async';
import 'dart:typed_data';

import 'package:flutter/foundation.dart' show debugPrint, kDebugMode;
import 'package:flutter/services.dart'
    show MethodChannel, MissingPluginException, PlatformException;

import 'issue_reporter.dart';

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

/// The outcome of one capture attempt: the JPEG bytes, or the reason there
/// are none.
///
/// Exactly one side is ever set. [reason] is a short stable slug from the
/// native side's fixed vocabulary (`screen_locked`, `jpeg_encoder_missing`,
/// `blit_failed`, …, see windows/runner/monitoring_screenshot.cpp) or one of
/// the two this layer adds itself: `channel_missing` when there is no native
/// handler at all, and `empty_capture` for a success carrying no bytes.
///
/// It never contains anything from the screen, a path or a person's name —
/// it is uploaded on the next heartbeat and shown to a human.
typedef CaptureAttempt = ({Uint8List? bytes, String? reason});

/// Invokes the channel above and returns either the primary monitor as JPEG
/// bytes or the reason it could not be captured — never throws, so a caller's
/// own `Timer.periodic` callback is never at risk of an uncaught error.
///
/// [caller] is only for the debug log line, so a failure in the log can be
/// told apart between the two things that call this.
Future<CaptureAttempt> captureMonitoringScreen({String caller = 'capture'}) async {
  try {
    final bytes = await IssueReporter.trackNative(
      'captureScreenshot',
      () => monitoringScreenshotChannel.invokeMethod<Uint8List>('captureScreenshot'),
    );

    if (bytes == null || bytes.isEmpty) {
      // The native handler reports every real failure as an ERROR now, so a
      // null success means the channel answered without producing anything —
      // kept as its own slug rather than folded into a failure reason,
      // because the two want different fixes.
      return (bytes: null, reason: 'empty_capture');
    }

    return (bytes: bytes, reason: null);
  } on PlatformException catch (e) {
    // `e.code` IS the reason slug — see the channel handler in
    // windows/runner/flutter_window.cpp.
    if (kDebugMode) {
      debugPrint('$caller: native capture failed: ${e.code}');
    }
    return (bytes: null, reason: e.code);
  } on MissingPluginException {
    // No native handler on this platform at all. Reached only if a caller
    // skipped the `screenshotCaptureSupported` gate.
    return (bytes: null, reason: 'channel_missing');
  } catch (e) {
    if (kDebugMode) {
      debugPrint('$caller: native capture failed: $e');
    }
    return (bytes: null, reason: 'capture_failed');
  }
}
