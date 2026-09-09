import 'package:flutter/foundation.dart';

import 'voice_device.dart';
import 'voice_device_mobile.dart';
import 'voice_device_windows.dart';

/// Which engine carries call audio on a platform that is not the web.
///
/// ── There is no single answer, and that is Twilio's shape ────────
///
/// The Voice SDK exists three times over and covers four of our six targets:
///
///     web        a browser script — wired directly in voice_device_web
///     android    a native SDK, wrapped by twilio_voice
///     ios        a native SDK, wrapped by twilio_voice
///     macos      twilio_voice again, which runs the web SDK there
///     windows    NOTHING — so the browser SDK is hosted in WebView2
///     linux      nothing, and no WebView2 either
///
/// So this is a switch rather than one implementation. Getting it wrong is
/// silent in the worst way — a phone that draws perfectly and never rings —
/// which is why the unsupported case returns a device that says `supported:
/// false` instead of one that fails when used.
VoiceDevice create() {
  if (defaultTargetPlatform == TargetPlatform.windows) {
    return WindowsVoiceDevice();
  }

  if (defaultTargetPlatform == TargetPlatform.android ||
      defaultTargetPlatform == TargetPlatform.iOS ||
      defaultTargetPlatform == TargetPlatform.macOS) {
    return MobileVoiceDevice();
  }

  return _SilentVoiceDevice();
}

/// Linux, and anything else with no engine.
///
/// Not a failure to implement: there is no Twilio SDK for it and no WebView2
/// either. Calls on such a platform ring the person's own handset instead,
/// which is what `supported: false` tells the Dial tab to do.
class _SilentVoiceDevice implements VoiceDevice {
  /// Nothing to wake: this build has no phone at all.
  @override
  String? get pushPlatform => null;

  /// Never fires. There is no engine here, so there is no call to end — and
  /// the desk's state was never set in the first place.
  @override
  void Function()? onEnded;

  // Nothing to mount, so nothing to prepare.
  @override
  Future<void> prepare() async {}

  @override
  Future<void> setup(String token) async {}

  @override
  Object? get platformView => null;

  @override
  bool get supported => false;

  @override
  bool get registered => false;

  // Nothing to explain: this device never attempts to register.
  @override
  String? get registrationError => null;

  @override
  Future<void> connect({required String to, String? callerId}) async {}

  @override
  void hangUp() {}

  @override
  void acceptIncoming() {}

  @override
  void declineIncoming() {}

  @override
  void Function(VoiceIncoming incoming)? onIncoming;

  @override
  void Function()? onRegistrationChanged;

  @override
  void dispose() {}
}
