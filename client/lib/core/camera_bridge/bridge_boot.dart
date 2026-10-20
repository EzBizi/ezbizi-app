// The camera bridge's entry point. dart:io only — web gets bridge_boot_stub.dart.
export 'bridge_boot_stub.dart' if (dart.library.io) 'bridge_boot_io.dart';
