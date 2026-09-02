import 'package:flutter/foundation.dart';
import 'package:flutter_test/flutter_test.dart';

import 'package:ezbizi_client/screens/phone/voice_device_io.dart' as io;
import 'package:ezbizi_client/screens/phone/voice_device_mobile.dart';
import 'package:ezbizi_client/screens/phone/voice_device_windows.dart';

/// Which engine carries call audio, per platform.
///
/// Twilio ships three different Voice SDKs and none of them covers everything
/// EzBizi runs on, so this is a switch rather than one implementation — and
/// picking wrong is the quietest failure in the module. A device that is
/// merely inert draws a perfect dial pad, registers nothing, and never rings;
/// there is no error, no log line, and nobody to complain because the caller
/// hears ringing and assumes nobody is in.
void main() {
  tearDown(() => debugDefaultTargetPlatformOverride = null);

  test('Windows gets the WebView2 device, because Twilio has no SDK for it', () {
    debugDefaultTargetPlatformOverride = TargetPlatform.windows;

    expect(io.create(), isA<WindowsVoiceDevice>());
  });

  test('phones and macOS get the native SDKs', () {
    for (final platform in [
      TargetPlatform.android,
      TargetPlatform.iOS,
      TargetPlatform.macOS,
    ]) {
      debugDefaultTargetPlatformOverride = platform;

      expect(
        io.create(),
        isA<MobileVoiceDevice>(),
        reason: '$platform did not get the native engine',
      );
    }
  });

  /// Linux has neither a Twilio SDK nor WebView2. What matters is that it says
  /// so: `supported` false is what makes the Dial tab ring the person's own
  /// handset instead, and a device that quietly accepted calls it cannot place
  /// would take that fallback away.
  test('a platform with no engine says so rather than pretending', () {
    debugDefaultTargetPlatformOverride = TargetPlatform.linux;

    final device = io.create();

    expect(device.supported, isFalse);
    expect(device.registered, isFalse);
    expect(device.platformView, isNull);
  });

  /// Every engine must answer this, and only Windows has anything to mount.
  test('only Windows asks the screen to mount something', () {
    debugDefaultTargetPlatformOverride = TargetPlatform.android;
    expect(io.create().platformView, isNull);

    debugDefaultTargetPlatformOverride = TargetPlatform.windows;

    // Null until setup() builds the controller — but the getter exists, which
    // is what stops the Dial tab from having to know which device it holds.
    expect(() => io.create().platformView, returnsNormally);
  });
}
