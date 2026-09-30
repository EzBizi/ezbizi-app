import 'field_os_io.dart'
    if (dart.library.js_interop) 'field_os_web.dart'
    as impl;

/// The phone's operating system and version, as Dart reports it, for the
/// Field technician bar. Its own conditional import because `dart:io` does
/// not exist on the web (see client_platform.dart).
///
/// Not device_info_plus: 13.x calls `isiOSAppOnVision`, which the release
/// Mac's Xcode 26.0.1 SDK does not declare (0.0.0325's iOS build failed on
/// it), and 12.x cannot be resolved beside flutter_secure_storage's win32.
/// So the model name is not sent until one of those changes.
String? fieldOsVersion() => impl.osVersion();
