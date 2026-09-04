import 'dart:async';

import 'package:flutter/foundation.dart' show debugPrint, kDebugMode;
import 'package:flutter/services.dart' show MethodChannel;

/// The ONE platform channel the remote-control AGENT path calls to apply a
/// manager's mouse/keyboard onto this machine — the mirror image of
/// [monitoringScreenshotChannel] (monitoring_capture_channel.dart), which
/// sends the screen OUT; this one brings input IN.
///
/// windows/runner is the only platform runner that registers this channel and
/// its `injectInput` method (applied via Win32 SendInput). Every other
/// platform — web included — has no native code for it at all, which is why
/// [RemoteControlService] gates behind `screenshotCaptureSupported`
/// (screenshot_capability.dart, the identical Windows-only signal Live View
/// and Screenshots use) before ever constructing a timer that would reach it.
const MethodChannel monitoringInputChannel =
    MethodChannel('com.ezbizi.app/monitoring_input');

/// Applies a batch of input events on the native side, or does nothing when
/// there is nothing to apply. Never throws — a caller's own `Timer` callback
/// must never be at risk of an uncaught error from this, exactly as
/// [captureMonitoringScreen] guarantees for the capture direction.
///
/// The arg is passed straight through as a `List` of event maps in the shapes
/// the native side reads: `mouseMove` / `mouseButton` / `scroll` / `key` /
/// `text` (see the plan's native contract). [caller] only labels the debug
/// log line so a failure can be told apart from the capture channel's.
Future<void> injectInput(
  List<Map<String, Object?>> events, {
  String caller = 'RemoteControlService',
}) async {
  if (events.isEmpty) return;
  try {
    await monitoringInputChannel.invokeMethod<void>('injectInput', events);
  } catch (e) {
    if (kDebugMode) {
      debugPrint('$caller: native input injection failed: $e');
    }
  }
}
