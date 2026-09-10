import 'dart:async';

import 'package:ezbizi_client/screens/phone/voice_device_mobile.dart';
import 'package:flutter_test/flutter_test.dart';

/// What the desk says when a handset cannot register.
///
/// ── The report this exists for ───────────────────────────────────
///
/// Jose ran the Android app and got "This desk is not connected to the phone
/// network. Reload the page; if it keeps happening the network is blocking the
/// connection." — on a phone, for a fault that is neither a page nor a
/// network. The real reason was thrown by the platform channel and discarded
/// by a bare `catch (_)`, and `registrationError` was a hardcoded `null` with
/// a comment claiming failures surface through the SDK's own callbacks.
///
/// So these are about the REASON surviving, not about registration working.
/// A device that cannot register is a state this app has to be able to
/// describe; one that cannot say why is how three hours go into the wrong
/// question.
void main() {
  // The SDK's event channel is subscribed to after a successful registration,
  // and EventChannel reaches for ServicesBinding.instance the moment it is
  // listened to. Without this the two SUCCESS paths fail on the binding rather
  // than on anything they assert.
  TestWidgetsFlutterBinding.ensureInitialized();

  test('a refused registration keeps the platform\'s own words', () async {
    final device = MobileVoiceDevice(
      register: (_) async => throw Exception(
        "No 'deviceToken' provided or invalid type",
      ),
    );

    await device.setup('a-token');

    expect(device.registered, isFalse);
    expect(
      device.registrationError,
      contains("No 'deviceToken' provided"),
      reason:
          'the diagnosis was thrown away, so the desk can only offer its '
          'generic "reload the page" banner',
    );
  });

  /// The iOS nil-device-token path: the plugin returns without ever calling
  /// `result(...)`, so the future never completes.
  ///
  /// Without a timeout this test would hang rather than fail, which is
  /// precisely what it does to `start()` on a real phone — the desk does not
  /// report a failure, it stops.
  test('a registration that never answers gives up and says so', () async {
    final device = MobileVoiceDevice(
      register: (_) => Completer<bool?>().future,
    );

    await device.setup('a-token').timeout(const Duration(seconds: 40));

    expect(device.registered, isFalse);
    expect(device.registrationError, contains('VoIP push token'));
  }, timeout: const Timeout(Duration(seconds: 60)));

  /// `false` is an answer, and a different one from a thrown error.
  test('a plugin that declines without throwing still explains itself', () async {
    final device = MobileVoiceDevice(register: (_) async => false);

    await device.setup('a-token');

    expect(device.registered, isFalse);
    expect(device.registrationError, contains('gave no reason'));
  });

  /// The control. Without it every assertion above passes for a device that
  /// can never register at all.
  test('a registration that succeeds reports no error', () async {
    final device = MobileVoiceDevice(register: (_) async => true);

    await device.setup('a-token');

    expect(device.registered, isTrue);
    expect(device.registrationError, isNull);
  });
}
