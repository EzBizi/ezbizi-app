import 'device_zone_io.dart'
    if (dart.library.js_interop) 'device_zone_web.dart'
    as impl;

/// The device's IANA zone ("America/New_York"), or null when this platform
/// does not say one.
///
/// A browser says it (Intl), and Linux and macOS through /etc/localtime or
/// `TZ`. Windows, Android and iOS name their zones another way, so they send
/// nothing and the server reads the organization's zone instead.
String? deviceZone() {
  final zone = impl.deviceZone();
  return zone != null && zone.contains('/') ? zone : null;
}
