import 'package:ezbizi_client/core/files.dart';
import 'package:flutter/foundation.dart';
import 'package:flutter_test/flutter_test.dart';

/// Take a photo is offered where a camera opens: the Android and iOS apps
/// (image_picker). The desktop apps would get a file dialog, which is
/// Choose files under another name, so they keep the picker alone.
void main() {
  tearDown(() => debugDefaultTargetPlatformOverride = null);

  for (final platform in [TargetPlatform.android, TargetPlatform.iOS]) {
    test('${platform.name} offers the camera', () {
      debugDefaultTargetPlatformOverride = platform;
      expect(Files.canTakePhoto, isTrue);
    });
  }

  for (final platform in [
    TargetPlatform.windows,
    TargetPlatform.macOS,
    TargetPlatform.linux,
  ]) {
    test('${platform.name} does not', () async {
      debugDefaultTargetPlatformOverride = platform;
      expect(Files.canTakePhoto, isFalse);
      // And never reaches the plugin: there is no camera flow to open.
      expect(await Files.takePhoto(), isNull);
    });
  }
}
