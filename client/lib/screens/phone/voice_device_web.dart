import 'dart:js_interop';
import 'dart:js_interop_unsafe';

import 'voice_device.dart';

/// Browser wrapper around `Twilio.Device` from @twilio/voice-sdk.
VoiceDevice create() => TwilioVoiceDevice();

class TwilioVoiceDevice implements VoiceDevice {
  _JsDevice? _device;
  _JsCall? _active;
  _JsCall? _pending;

  @override
  void Function(VoiceIncoming incoming)? onIncoming;

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
          onIncoming?.call(VoiceIncoming(from: _from(jsCall)));
        }.toJS,
      );
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
    } catch (_) {
      // No SDK, a token Twilio refused, or signalling that never came up.
      // The desk still draws; `registered` is how Call knows not to pretend
      // it can take a call.
      _registered = false;
    }
  }

  /// Whether the device actually reached Twilio.
  ///
  /// Kept separate from `_device != null`: the object exists the moment it is
  /// constructed, long before — and whether or not — registration succeeds.
  bool _registered = false;

  @override
  bool get registered => _registered;

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
    } catch (_) {}
  }

  @override
  void hangUp() {
    _active?.disconnect();
    _active = null;
    _device?.disconnectAll();
  }

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

  @override
  void dispose() {
    hangUp();
    declineIncoming();
    _device?.destroy();
    _device = null;
    onIncoming = null;
  }

  String _from(_JsCall call) {
    try {
      final parameters = call.parameters;
      final from = parameters.getProperty('From'.toJS);
      if (from.isA<JSString>()) return (from as JSString).toDart;
    } catch (_) {}
    return '';
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
}

@JS()
extension type _JsCall._(JSObject _) implements JSObject {
  external void accept();
  external void reject();
  external void disconnect();
  external JSObject get parameters;
}
