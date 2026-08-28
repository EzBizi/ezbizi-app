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
      // The subdirectory comes from the caller, not from a guess.
      //
      // It was hardcoded to 'ios', so an Android run overwrote the iPhone set
      // with 1080x2400 images under an ios/ path — same filenames, wrong
      // device, no warning. Apple and Play want different sizes from the same
      // test, so the destination has to be part of the request.
      // Platform.environment, NOT String.fromEnvironment.
      //
      // This half runs on the HOST, and `flutter drive` forwards --dart-define
      // to the app on the device, never to the driver. A compile-time define
      // here is silently always the default, which is how the first attempt
      // wrote everything to 'unsorted' while the flag looked correct.
      final dir = Platform.environment['SHOT_DIR'] ?? 'unsorted';
      final file = File('store-screenshots/$dir/$name.png');
      await file.parent.create(recursive: true);
      await file.writeAsBytes(bytes);
      stdout.writeln('  wrote ${file.path} (${bytes.length} bytes)');
      return true;
    },
  );
}
