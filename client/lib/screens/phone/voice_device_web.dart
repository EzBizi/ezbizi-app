import 'dart:js_interop';
import 'dart:js_interop_unsafe';

import 'voice_device.dart';

/// Browser wrapper around `Twilio.Device` from @twilio/voice-sdk.
VoiceDevice create() => TwilioVoiceDevice();

class TwilioVoiceDevice implements VoiceDevice, SendsDigits {
  /// A browser hears the call down the socket it is already holding open,
  /// so there is nothing to push to.
  @override
  String? get pushPlatform => null;

  _JsDevice? _device;
  _JsCall? _active;
  _JsCall? _pending;

  @override
  void Function(VoiceIncoming incoming)? onIncoming;

  @override
  void Function()? onRegistrationChanged;

  @override
  void Function()? onTokenExpiring;

  @override
  void Function()? onEnded;

  /// The browser answers through the desk's own button, so the desk
  /// already knows and nothing raises this.
  @override
  void Function()? onAnswered;

  @override
  void Function(String reason)? onCallFailed;

  // The browser grants the microphone against the page itself, so there is
  // no widget that a permission has to reach.
  @override
  Future<void> prepare() async {}

  @override
  Future<void> setup(String token) async {
    if (_twilio == null) return;

    try {
      _device?.destroy();
      final device = _JsDevice(token.toJS);
      device.on(
        'incoming'.toJS,
        (JSAny call) {
          final jsCall = call as _JsCall;
          _pending = jsCall;
          _watchForEnd(jsCall);
          onIncoming?.call(VoiceIncoming(from: _from(jsCall)));
        }.toJS,
      );

      // A TOKEN LASTS AN HOUR AND WE ASKED FOR ONE.
      //
      // The SDK raises this a few minutes before the token dies. Unanswered,
      // the registration lapses and this desk stops receiving calls — no
      // error, no banner, and outbound still works, which is what made it
      // invisible for so long.
      //
      // ZERO PARAMETERS, deliberately. A `.toJS` closure that declares one
      // throws `NoSuchMethodError: method not found: 'call$0'` when the SDK
      // calls it with none, and this event carries none. Measured in a real
      // browser after the same mistake cost a cancel handler.
      device.on(
        'tokenWillExpire'.toJS,
        () {
          onTokenExpiring?.call();
        }.toJS,
      );
      // ── ONE RINGTONE, and it is ours ────────────────────────────
      //
      // The Voice SDK plays its own incoming ring by default, and EzBizi
      // plays one too — see CallAlerts, which every platform uses. On the web
      // both sound at once, which is what Jose reported: the EzBizi tone, and
      // underneath it what he described as the sound of calling somebody.
      //
      // Ours is the one that survives, because it is the same alert on
      // Windows, Android and iOS, and a browser tab that rings differently
      // from the desktop app is a tab people learn to ignore.
      //
      // Only `incoming` is turned off. The OUTGOING tone is left alone
      // deliberately: outbound ringback on a browser leg was silent until
      // <Dial ringTone> was added for it, so this SDK sound is either
      // inaudible on that path or it is the thing making it work, and I have
      // measured neither. Turning it off to be tidy is how that fix gets
      // quietly undone.
      _silenceOwnRing(device);

      _device = device;

      // ── Why this needs a timeout and not just a catch ────────────
      //
      // register() opens a signalling socket. If that socket never connects —
      // a proxy swallowing WebSockets, a captive portal, a network that
      // accepts and then drops — the promise NEVER SETTLES. A promise that
      // never settles is not a rejection, so `catch` below cannot fire and
      // this await simply hangs forever.
      //
      // The desk then draws perfectly, shows no error, and never rings. That
      // is the worst failure this module has: indistinguishable from a quiet
      // day. Ten seconds is generous for a socket handshake, and timing out
      // lands us in the same state as a refused token, which is at least a
      // state the rest of the code already understands.
      await device.register().toDart.timeout(const Duration(seconds: 10));
      _registered = true;
      onRegistrationChanged?.call();
    } catch (_) {
      // No SDK, a token Twilio refused, or signalling that never came up.
      // The desk still draws; `registered` is how Call knows not to pretend
      // it can take a call.
      _registered = false;
      onRegistrationChanged?.call();
    }
  }

  /// Whether the device actually reached Twilio.
  ///
  /// Kept separate from `_device != null`: the object exists the moment it is
  /// constructed, long before — and whether or not — registration succeeds.
  bool _registered = false;

  @override
  Object? get platformView => null;

  @override
  bool get supported => true;

  /// A browser has no call UI of its own, so EzBizi rings.
  @override
  bool get ringsItself => false;

  @override
  bool get registered => _registered;

  // The browser device awaits register() directly, so a failure is a thrown
  // error rather than a message carrying a reason.
  @override
  String? get registrationError => null;
  // Android's Telecom framework is the only place this can be true; a browser,
  // a WebView and a platform with no SDK all have nothing to enable.
  @override
  bool get needsCallingAccount => false;

  @override
  Future<void> openCallingSettings() async {}

  @override
  Future<void> connect({required String to, String? callerId}) async {
    final device = _device;
    if (device == null) return;

    final params = JSObject();
    params.setProperty('To'.toJS, to.toJS);

    // Say it outright rather than let the server infer it.
    //
    // Outbound was being detected from Twilio's own Direction and From —
    // Direction arrives as "inbound" for a browser-originated call, because
    // from Twilio's side the client IS calling in, so detection rested
    // entirely on From starting with "client:". When that inference failed the
    // call was treated as INBOUND and answered by the line's own voicemail:
    // somebody pressed Call and heard "please leave a message".
    //
    // A namespaced parameter cannot collide with a Twilio field and does not
    // depend on how Twilio chooses to describe the call.
    params.setProperty('EzBiziDirection'.toJS, 'outbound'.toJS);
    if (callerId != null && callerId.isNotEmpty) {
      params.setProperty('CallerId'.toJS, callerId.toJS);
    }
    final options = JSObject();
    options.setProperty('params'.toJS, params);

    try {
      final result = await device.connect(options).toDart;
      _active = result as _JsCall;
      _watchForEnd(_active!);
    } catch (error) {
      // Said, not swallowed: this was `catch (_) {}`, and a Call that failed
      // here looked exactly like a Call that was never pressed.
      throw CallRefused(callFailureSentence(error.toString()));
    }
  }

  @override
  void hangUp() {
    _active?.disconnect();
    _active = null;
    _device?.disconnectAll();
  }

  @override
  void sendDigits(String digits) => _active?.sendDigits(digits.toJS);

  @override
  void acceptIncoming() {
    final pending = _pending;
    if (pending == null) return;
    pending.accept();
    _active = pending;
    _pending = null;
  }

  @override
  void declineIncoming() {
    _pending?.reject();
    _pending = null;
  }

  /// Hear the three ways the SDK says a call is over.
  ///
  /// `disconnect` is the customer hanging up on a live call. `cancel` is the
  /// caller giving up while it rings, AND the one that arrives when somebody
  /// else answered first — another of your own devices, or a colleague in the
  /// ring group. `reject` is a decline.
  ///
  /// All three collapse to one answer here on purpose: there is no call any
  /// more. Only the pending/active reference is cleared per event, so a stale
  /// one cannot be accepted after the fact.
  ///
  /// ── THE HANDLER TAKES NO ARGUMENT, AND THAT IS THE FIX ───────
  ///
  /// It used to declare one, and two of these three events carry none:
  /// `emit("disconnect", call)` passes the call, `emit("cancel")` and
  /// `emit("reject")` pass nothing. A Dart closure converted with `.toJS` does
  /// not receive null for an argument JavaScript never sent — it THROWS,
  /// `NoSuchMethodError: method not found: 'call$0'`, before a line of this
  /// body runs. Measured, not reasoned: a one-parameter handler called with
  /// no arguments throws, a zero-parameter one runs whether it is called with
  /// none or with several.
  ///
  /// So exactly one of the three worked, and it was the one nobody reports:
  /// the customer hanging up. The other two are how a call ends when SOMEBODY
  /// ELSE took it, and they were silently dead — Jose answered on the desktop
  /// and the browser kept ringing a call that Twilio had already torn down
  /// seven seconds earlier, and a ring group rang every member who had not
  /// answered for as long as they let it.
  ///
  /// JavaScript ignores arguments a function does not declare, so taking none
  /// is the shape that is safe for every event, present and future.
  void _watchForEnd(_JsCall call) {
    for (final event in const ['disconnect', 'cancel', 'reject']) {
      call.on(
        event.toJS,
        () {
          if (_pending == call) _pending = null;
          if (_active == call) _active = null;
          onEnded?.call();
        }.toJS,
      );
    }
  }

  @override
  void dispose() {
    hangUp();
    declineIncoming();
    _device?.destroy();
    _device = null;
    onIncoming = null;
    onEnded = null;
  }

  String _from(_JsCall call) {
    try {
      final parameters = call.parameters;
      final from = parameters.getProperty('From'.toJS);
      if (from.isA<JSString>()) return (from as JSString).toDart;
    } catch (_) {}
    return '';
  }

  /// Turn off the SDK's own incoming ring, if this build of it has one.
  ///
  /// Guarded rather than called straight: `audio` is absent when the browser
  /// gives the SDK no audio context to work with, and an exception here would
  /// land in setup()'s catch and report the device as unregistered — a phone
  /// that cannot take calls at all, in the name of a duplicate tone.
  void _silenceOwnRing(_JsDevice device) {
    try {
      device.audio?.incoming(false.toJS);

      // AND THE OUTGOING TONE, which used to be left alone.
      //
      // The note here said the SDK's outgoing sound was "either inaudible on
      // that path or the thing making it work, and I have measured neither".
      // It is the first: outbound ringback on a browser leg comes from
      // `<Dial ringTone="us">`, which Twilio generates on the server and
      // plays into the caller's leg. That is why adding it fixed a silent
      // outbound call — the SDK tone was already there and was not enough.
      //
      // So this is the duplicate, and silencing it leaves exactly one
      // outbound ring: the server's, which is identical on every platform
      // because no client produces it.
      device.audio?.outgoing(false.toJS);
    } catch (_) {
      // A tone too many is survivable. Not registering is not.
    }
  }
}

@JS('Twilio')
external JSObject? get _twilio;

@JS('Twilio.Device')
extension type _JsDevice._(JSObject _) implements JSObject {
  external factory _JsDevice(JSString token);

  external JSPromise register();
  external JSPromise connect(JSObject options);
  external void destroy();
  external void disconnectAll();
  external void on(JSString event, JSFunction handler);

  /// Null when the browser handed the SDK no audio helper — see
  /// [TwilioVoiceDevice._silenceOwnRing].
  external _JsAudio? get audio;
}

/// `Device.audio`, which owns the sounds the SDK plays for itself.
@JS()
extension type _JsAudio._(JSObject _) implements JSObject {
  /// Passing false stops the SDK ringing for an incoming call. Called with no
  /// argument it reports the current setting, which is why the bool is
  /// explicit here rather than defaulted.
  external void incoming(JSBoolean on);

  /// The tone played while an outgoing call is ringing. Off, because
  /// `<Dial ringTone>` is the one outbound ring — see [setup].
  external void outgoing(JSBoolean on);
}

@JS()
extension type _JsCall._(JSObject _) implements JSObject {
  external void on(JSString event, JSFunction handler);
  external void accept();
  external void reject();
  external void disconnect();
  external void sendDigits(JSString digits);
  external JSObject get parameters;
}
