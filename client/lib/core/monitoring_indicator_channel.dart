import 'package:flutter/foundation.dart' show debugPrint, kDebugMode;
import 'package:flutter/services.dart' show MethodChannel;

/// The ONE platform channel the remote-control AGENT calls to raise or clear
/// the "your screen is being controlled" notice on this machine — the sibling
/// of [monitoringInputChannel] (monitoring_input_channel.dart), which applies
/// the manager's input; this one only tells the person it is happening.
///
/// windows/runner is the only runner that registers this channel and its
/// `setControlled` method (which updates the existing tray icon via
/// Shell_NotifyIcon NIM_MODIFY — a tooltip swap plus a one-off balloon). Every
/// other platform — web included — has no native code for it at all, which is
/// why [RemoteControlService] only ever reaches it from behind the same
/// Windows-only `screenshotCaptureSupported` gate the rest of monitoring uses.
const MethodChannel monitoringIndicatorChannel =
    MethodChannel('com.ezbizi.app/monitoring_indicator');

/// Shows (active=true) or clears (active=false) the tray notice on the native
/// side, or does nothing when there is no native handler. Never throws — a
/// caller's own `Timer` callback must never be at risk of an uncaught error
/// from this, exactly as [injectInput] guarantees for the input direction.
///
/// [caller] only labels the debug log line so a failure here can be told apart
/// from the input channel's.
Future<void> setControlled(
  bool active, {
  String caller = 'RemoteControlService',
}) async {
  try {
    await monitoringIndicatorChannel.invokeMethod<void>(
      'setControlled',
      active,
    );
  } catch (e) {
    if (kDebugMode) {
      debugPrint('$caller: native control indicator failed: $e');
    }
  }
}
