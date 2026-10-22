import 'dart:js_interop';

@JS('Intl.DateTimeFormat')
external _DateTimeFormat _format();

extension type _DateTimeFormat._(JSObject _) implements JSObject {
  external _Options resolvedOptions();
}

extension type _Options._(JSObject _) implements JSObject {
  external String? get timeZone;
}

/// See device_zone.dart.
String? deviceZone() {
  try {
    return _format().resolvedOptions().timeZone;
  } catch (_) {
    return null;
  }
}
