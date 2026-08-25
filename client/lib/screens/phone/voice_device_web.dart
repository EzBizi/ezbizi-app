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
      await device.register().toDart;
    } catch (_) {
      // No SDK, or a token Twilio refused. The desk still draws; Call then
      // says so by doing nothing.
    }
  }

  @override
  Future<void> connect({required String to, String? callerId}) async {
    final device = _device;
    if (device == null) return;

    final params = JSObject();
    params.setProperty('To'.toJS, to.toJS);
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
