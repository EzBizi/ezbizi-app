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

  /// Does this platform ring for an incoming call by itself?
  ///
  /// ── ONE RING PER CALL, EVERYWHERE ───────────────────────────────
  ///
  /// Jose, after weeks of this: "1 ring for incoming, 1 for outgoing, it
  /// should be simple, and the same type for all of them."
  ///
  /// Three platforms were producing two sounds each and they were fixed one at
  /// a time, which is why it kept coming back. The rule is now one sentence:
  ///
  ///   INCOMING — exactly one, and EzBizi plays it wherever EzBizi owns the
  ///   audio. On a handset the OS owns it: CallKit and the Android
  ///   ConnectionService ring the phone's own ringtone before a line of our
  ///   code runs, and a phone that rings with an app's tone instead of its
  ///   own is a phone behaving wrongly. So on mobile this is true and
  ///   [CallAlerts] stays quiet.
  ///
  ///   OUTGOING — exactly one, and it is Twilio's `<Dial ringTone>`. That is
  ///   generated on the server, so it is literally identical on every
  ///   platform rather than merely similar. Every client-side outgoing tone
  ///   is silenced.
  ///
  /// False here does not mean silent — it means WE ring, which is the common
  /// case. True means stay out of the way.
  bool get ringsItself;

  /// Register with an access token from POST /phone/tokens.
  /// Build whatever the screen must MOUNT, before [setup] registers.
  ///
  /// Two phases because the Windows device needs its WebView2 widget in the
  /// tree BEFORE registration: the microphone prompt is delivered to the
  /// widget's `permissionRequested` delegate, `getUserMedia` fails without an
  /// answer, and Twilio's register() then rejects. [setup] used to create the
  /// controller and register inside one await, so [platformView] was still
  /// null while the page was asking — nothing was mounted, and every desktop
  /// launch reported a desk that could not connect.
  ///
  /// A no-op everywhere else: the browser needs nothing mounted, and calling
  /// this before [setup] is always safe.
  Future<void> prepare() async {}

  Future<void> setup(String token);

  /// Whether [setup] actually reached Twilio.
  ///
  /// Distinct from "setup returned": it returns on refusal and on timeout too.
  /// Without this the desk cannot tell a registered device from one whose
  /// signalling socket never came up, and it would draw a ready dial pad that
  /// silently never rings.
  bool get registered;

  /// Why registration failed, when the platform was told.
  ///
  /// Null when registered, or when the platform had nothing to say. Twilio
  /// reports a real reason — an expired token, a rejected identity, no audio
  /// device — and the Windows bridge has always forwarded it; nothing read it,
  /// so the desk fell back to guessing "the network is blocking the
  /// connection". That guess sent Jose to check a firewall twice for faults
  /// that were not the network.
  String? get registrationError => null;

  /// Whether the fix for [registrationError] is in the SYSTEM's settings.
  ///
  /// Android only, and it exists because that failure is the one kind the desk
  /// cannot resolve by retrying. Telecom will not place or accept a call until
  /// the user has ENABLED this app under Calling accounts, which is a screen
  /// only Android can show — so "Try again" on its own is a button that can
  /// only fail until somebody has been somewhere else first.
  ///
  /// A typed flag rather than the desk reading [registrationError] for a
  /// phrase. A message is copy, it gets reworded, and a button that appears
  /// because of a substring disappears the day somebody improves the sentence.
  bool get needsCallingAccount => false;

  /// Take the user to the system screen where they can enable it.
  ///
  /// A no-op everywhere [needsCallingAccount] is false, so the desk may call
  /// it without knowing which platform it is holding.
  Future<void> openCallingSettings() async {}

  /// A platform object the screen must MOUNT for this device to work, or null.
  ///
  /// Only Windows returns one, and it is not a display surface: WebView2
  /// delivers the microphone permission prompt to the `Webview` WIDGET's
  /// delegate, so a controller that is never put in the tree is never asked,
  /// and `getUserMedia` fails with the phone looking perfectly healthy. The
  /// Dial tab mounts it at zero size.
  Object? get platformView;

  /// 'ios', 'android', or null for anything that is not a handset.
  ///
  /// ── Why the device answers this and not the app ──────────────────
  ///
  /// A browser hears an incoming call down the signalling socket it is
  /// already holding open. A locked phone holds nothing open, so Twilio has to
  /// send a VoIP push — and it only does that when the ACCESS TOKEN names a
  /// push credential, which means the token request has to say what is asking
  /// before it is minted.
  ///
  /// Nothing said, so every token was a browser token. The TestFlight build on
  /// 2026-09-09 registered, dialled out, showed itself connected, and never
  /// rang once. There was no error anywhere, because outgoing calls need only
  /// the TwiML application and that was set.
  ///
  /// Asked of the DEVICE rather than read from the platform at the call site,
  /// because the honest answer is not "what OS is this" — the Windows build
  /// runs a browser inside a desktop app, and macOS runs the handset plugin on
  /// a machine that never sleeps in a pocket. Only the device knows whether it
  /// can be woken.
  String? get pushPlatform => null;

  /// Place an outbound call. [to] is E.164 or `client:{identity}`.
  Future<void> connect({required String to, String? callerId});

  /// Drop the current call, if any.
  void hangUp();

  /// Answer the pending inbound call.
  void acceptIncoming();

  /// Refuse the pending inbound call.
  void declineIncoming();

  /// Called whenever [registered] changes after [setup] has returned.
  ///
  /// ── Why this exists rather than reading the flag ─────────────────
  ///
  /// The desk used to sample `registered` once, immediately after setup, and
  /// draw its banner from that. On Windows registration finishes AFTER that
  /// point — the page loads, the token goes in, a signalling socket opens —
  /// so the sample was taken while the answer was still false and nothing ever
  /// took another.
  ///
  /// The result was a desk that said "not connected to the phone network"
  /// across the top while a call was connected underneath it, which is worse
  /// than either fact alone: it teaches somebody the banner is noise, and then
  /// they ignore it on the day it is true.
  void Function()? onRegistrationChanged;

  /// Inbound ringing. Tests drive this; the web wrapper maps SDK `incoming`.
  void Function(VoiceIncoming incoming)? onIncoming;

  /// The access token is about to expire, and a desk with a dead token is a
  /// desk that silently stops ringing.
  ///
  /// ── The hour-long phone ──────────────────────────────────────────
  ///
  /// `accessToken()` mints a token with a TTL of **3600 seconds**, and the
  /// client asked for exactly one, at startup. So a desk left open longer than
  /// an hour lost its registration and took no more incoming calls, with
  /// nothing on screen saying so: no error, no banner, a dial pad that still
  /// dials out perfectly.
  ///
  /// Measured on production 2026-09-17: of seven `<Client>` legs on Jose's
  /// busiest line, three went to identities nothing was registered as, and 27
  /// of the 29 inbound calls over two days rang the full 60 seconds and went
  /// to voicemail.
  ///
  /// ── The half that was already built ─────────────────────────────
  ///
  /// The browser bridge has raised this since the day it was written, with a
  /// comment saying "Dart owns the token lifecycle; it refetches and sends
  /// setup again". Dart did not. Nothing listened for the message, and nothing
  /// failed — the painted half shipped and the half nobody sees was never
  /// written.
  ///
  /// So this is the contract that comment was describing. The desk answers by
  /// fetching a new token and calling [setup] again, which re-registers the
  /// same identity and, not incidentally, is also the only thing that refreshes
  /// `last_seen_at` — the column `PhoneDeviceRegistration::liveFor()` uses to
  /// decide whether a device is worth dialling at all.
  void Function()? onTokenExpiring;

  /// The call is over, and NOBODY HERE ASKED FOR THAT.
  ///
  /// ── Why this had to exist ────────────────────────────────────
  ///
  /// Every way a call used to end went through a button: accept, decline, hang
  /// up. So the desk cleared its state on the way out of its own methods and
  /// that looked complete.
  ///
  /// It is not, because the commonest ways a call ends are not local. The
  /// caller gives up while it is ringing. Somebody else in the ring group
  /// answers first and Twilio cancels this leg. The customer hangs up on a
  /// live call. None of those press anything here, so the desk kept believing
  /// a call was in progress: the incoming bar stayed across every screen
  /// offering to answer a call that no longer existed, and `on_call` stayed
  /// set on the server, which quietly takes that person out of the ring group.
  ///
  /// Fired for all three, deliberately without saying which. The desk's answer
  /// is the same in each case — there is no call any more — and a device that
  /// had to name the reason would be three chances to name it wrong.
  void Function()? onEnded;

  /// The native call UI answered this call, and nothing here pressed anything.
  ///
  /// ── Why a phone needs its own signal for this ────────────────────
  ///
  /// On a handset the operating system owns answering: CallKit on iOS, a
  /// ConnectionService on Android. Somebody swipes to answer THERE, so the
  /// desk's own accept button is never pressed and the desk goes on offering
  /// it — Jose answered a call in CallKit on 2026-09-10 and was then asked to
  /// accept the same call again inside the app.
  ///
  /// Distinct from [onEnded], which says there is no call: this says there is
  /// one and it is already connected. Firing onEnded here would clear the bar
  /// correctly and take the desk off `on_call` at the same moment the
  /// conversation started.
  ///
  /// The browser never raises it — there, pressing accept in the app IS the
  /// answering, so the desk already knows.
  void Function()? onAnswered;

  /// A call this device started failed before it reached anybody.
  ///
  /// Jose, 2026-09-29: pressing Call "tries to open up something then nothing
  /// happens". The Windows softphone could not find a microphone, Twilio never
  /// saw the call, and the page's error message was dropped on the Dart side,
  /// so the Dial pad showed nothing at all. The reason is one of
  /// [callFailureSentence]'s.
  void Function(String reason)? onCallFailed;

  void dispose();
}

/// A person-readable sentence for why a call could not start.
///
/// Microphone first, because that is the one somebody can fix from their desk:
/// Twilio's 31401/31208 are a refused permission and 31402/31400 a microphone
/// that could not be opened — most often, none is connected.
String callFailureSentence(String message, {int? code}) {
  final text = message.toLowerCase();

  if (code == 31401 ||
      code == 31208 ||
      text.contains('notallowed') ||
      text.contains('permission')) {
    return 'EzBizi is not allowed to use the microphone. Allow microphone '
        'access for EzBizi, then press Call again.';
  }

  if (code == 31402 ||
      code == 31400 ||
      text.contains('notfound') ||
      text.contains('device not found') ||
      text.contains('acquisition') ||
      text.contains('getusermedia') ||
      text.contains('microphone')) {
    return 'No microphone found. Connect a headset or microphone, then press '
        'Call again.';
  }

  return message.trim().isEmpty
      ? 'The call could not start.'
      : 'The call could not start: ${message.trim()}';
}

/// The platform refused to place an outbound call, and here is why.
///
/// Thrown rather than returned so that nothing already written against
/// `connect()` has to change shape — and so the one caller that must react to
/// it cannot miss it by ignoring a return value.
class CallRefused implements Exception {
  const CallRefused(this.reason);

  /// A sentence to show the person who pressed Call.
  final String reason;

  @override
  String toString() => reason;
}

class VoiceIncoming {
  const VoiceIncoming({required this.from});

  final String from;
}

VoiceDevice createVoiceDevice() => impl.create();

/// A device that can press keys on the live call (Jose, 2026-11-11: Meta's
/// call asked him to press 1 and the popup had no keypad). Separate from
/// [VoiceDevice] so a device without it is still a device.
abstract interface class SendsDigits {
  void sendDigits(String digits);
}
