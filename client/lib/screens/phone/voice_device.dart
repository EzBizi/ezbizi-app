import 'voice_device_io.dart'
    if (dart.library.js_interop) 'voice_device_web.dart'
    as impl;

/// Twilio Voice Device for the Call desk.
///
/// The JS SDK only exists in the browser. Tests inject a fake and never load
/// it. Off the web this is a silent stub so `flutter test` still compiles.
abstract class VoiceDevice {
  /// Whether this build can EVER place a call.
  ///
  /// Distinct from [registered], and the distinction is the whole point. A
  /// device that failed to register might succeed on a retry; a build with no
  /// Voice SDK in it never will, because the SDK is a browser script and the
  /// desktop app is not a browser.
  ///
  /// Collapsing the two is what put "reload the page; if it keeps happening
  /// the network is blocking the connection" in front of desktop users — a
  /// transient-sounding diagnosis, with a Retry button, for a permanent
  /// platform fact. Somebody reads that and goes to look at their firewall.
  bool get supported;

  /// Register with an access token from POST /phone/tokens.
  Future<void> setup(String token);

  /// Whether [setup] actually reached Twilio.
  ///
  /// Distinct from "setup returned": it returns on refusal and on timeout too.
  /// Without this the desk cannot tell a registered device from one whose
  /// signalling socket never came up, and it would draw a ready dial pad that
  /// silently never rings.
  bool get registered;

  /// A platform object the screen must MOUNT for this device to work, or null.
  ///
  /// Only Windows returns one, and it is not a display surface: WebView2
  /// delivers the microphone permission prompt to the `Webview` WIDGET's
  /// delegate, so a controller that is never put in the tree is never asked,
  /// and `getUserMedia` fails with the phone looking perfectly healthy. The
  /// Dial tab mounts it at zero size.
  Object? get platformView;

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
