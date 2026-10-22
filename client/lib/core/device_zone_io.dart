import 'dart:io';

/// See device_zone.dart.
String? deviceZone() {
  try {
    final tz = Platform.environment['TZ'];
    if (tz != null && tz.contains('/')) {
      return tz.startsWith(':') ? tz.substring(1) : tz;
    }
    if (Platform.isLinux || Platform.isMacOS) {
      final target = Link('/etc/localtime').targetSync();
      final at = target.indexOf('zoneinfo/');
      if (at >= 0) return target.substring(at + 'zoneinfo/'.length);
    }
  } catch (_) {
    // No link, or not allowed to read it: the server's fallback applies.
  }
  return null;
}
