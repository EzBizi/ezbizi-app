import 'voice_device_io.dart'
    if (dart.library.js_interop) 'voice_device_web.dart' as impl;

/// Twilio Voice Device for the Call desk.
///
/// The JS SDK only exists in the browser. Tests inject a fake and never load
/// it. Off the web this is a silent stub so `flutter test` still compiles.
abstract class VoiceDevice {
  /// Register with an access token from POST /phone/tokens.
  Future<void> setup(String token);

  /// Place an outbound call. [to] is E.164 or `client:{identity}`.
  Future<void> connect({required String to, String? callerId});

  /// Drop the current call, if any.
  void hangUp();

  /// Answer the pending inbound call.
  void acceptIncoming();

  /// Refuse the pending inbound call.
  void declineIncoming();

  /// Inbound ringing. Tests drive this; the web wrapper maps SDK `incoming`.
  void Function(VoiceIncoming incoming)? onIncoming;

  void dispose();
}

class VoiceIncoming {
  const VoiceIncoming({required this.from});

  final String from;
}

VoiceDevice createVoiceDevice() => impl.create();
