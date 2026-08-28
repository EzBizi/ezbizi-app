import 'dart:io';
import 'package:integration_test/integration_test_driver_extended.dart';

/// Driver half of the App Store screenshot run.
///
/// `flutter drive` runs this on the host while the test runs on the device.
/// Its only job is to catch the bytes the test sends back and write them where
/// a person can find them — the test itself cannot write to the host's disk.
Future<void> main() async {
  await integrationDriver(
    onScreenshot: (String name, List<int> bytes, [Map<String, Object?>? args]) async {
      final file = File('store-screenshots/ios/$name.png');
      await file.parent.create(recursive: true);
      await file.writeAsBytes(bytes);
      stdout.writeln('  wrote ${file.path} (${bytes.length} bytes)');
      return true;
    },
  );
}
