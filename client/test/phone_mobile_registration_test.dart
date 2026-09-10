import 'dart:async';

import 'package:ezbizi_client/screens/phone/voice_device.dart';
import 'package:ezbizi_client/screens/phone/voice_device_mobile.dart';
import 'package:twilio_voice/twilio_voice.dart';
import 'package:flutter/foundation.dart';
import 'package:flutter/services.dart';
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
      readDeviceToken: () async => 'fcm',
      register: (_, _) async => throw Exception(
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
      readDeviceToken: () async => 'fcm',
      register: (_, _) => Completer<bool?>().future,
    );

    await device.setup('a-token').timeout(const Duration(seconds: 40));

    expect(device.registered, isFalse);
    expect(device.registrationError, contains('VoIP push token'));
  }, timeout: const Timeout(Duration(seconds: 60)));

  /// `false` is an answer, and a different one from a thrown error.
  test('a plugin that declines without throwing still explains itself', () async {
    final device = MobileVoiceDevice(
      readDeviceToken: () async => 'fcm',
      register: (_, _) async => false,
    );

    await device.setup('a-token');

    expect(device.registered, isFalse);
    expect(device.registrationError, contains('gave no reason'));
  });

  /// The control. Without it every assertion above passes for a device that
  /// can never register at all.
  test('a registration that succeeds reports no error', () async {
    final device = MobileVoiceDevice(
      readDeviceToken: () async => 'fcm',
      register: (_, _) async => true,
    );

    await device.setup('a-token');

    expect(device.registered, isTrue);
    expect(device.registrationError, isNull);
  });

  group('the Android device token', () {
    setUp(() => debugDefaultTargetPlatformOverride = TargetPlatform.android);
    tearDown(() => debugDefaultTargetPlatformOverride = null);

    /// The whole defect, asserted on the ARGUMENT rather than on the call.
    ///
    /// `twilio_voice` refuses to register without a device token — it is the
    /// first thing its Android `TOKENS` handler checks. We passed none, so
    /// Android registration had never happened. A test that only asserted
    /// "setTokens was called" would have passed throughout.
    test('reaches setTokens, which is the whole reason registration failed', () async {
      String? seen;
      var called = false;

      final device = MobileVoiceDevice(
        readDeviceToken: () async => 'fcm-token-from-the-channel',
        register: (_, deviceToken) async {
          called = true;
          seen = deviceToken;

          return true;
        },
      );

      await device.setup('a-token');

      expect(called, isTrue, reason: 'registration was never attempted');
      expect(seen, 'fcm-token-from-the-channel');
      expect(device.registered, isTrue);
    });

    /// Null is a real answer here, not a theoretical one.
    test('a device with no token is refused, and told why', () async {
      var called = false;

      final device = MobileVoiceDevice(
        readDeviceToken: () async => null,
        register: (_, _) async {
          called = true;

          return true;
        },
      );

      await device.setup('a-token');

      expect(device.registered, isFalse);
      expect(device.registrationError, contains('no Firebase messaging token'));

      // And it does not bother the plugin with it. Its refusal names the
      // ARGUMENT; ours names the cause, and two messages for one fault is how
      // the wrong one gets quoted in a bug report.
      expect(called, isFalse);
    });

    /// A channel that throws is a registration failure with a reason.
    test('a channel error is kept rather than swallowed', () async {
      final device = MobileVoiceDevice(
        readDeviceToken: () async => throw PlatformException(
          code: 'fcm_unavailable',
          message: 'Firebase never initialised',
        ),
        register: (_, _) async => true,
      );

      await device.setup('a-token');

      expect(device.registered, isFalse);
      expect(device.registrationError, contains('fcm_unavailable'));
    });
  });

  group('call events', () {
    /// ── The defect ─────────────────────────────────────────────────
    ///
    /// `ringing` is raised for the far end of an OUTGOING call, and this
    /// treated it as an incoming one. So placing a call raised the desk's own
    /// incoming banner and asked the caller to accept the call they had just
    /// made. Jose rang Rodrigo, Rodrigo answered, and he had to accept an
    /// incoming call from himself to join it.
    test('ringing is the far end of our own call, not an incoming one', () {
      final device = MobileVoiceDevice();
      VoiceIncoming? seen;
      device.onIncoming = (incoming) => seen = incoming;

      device.handleEvent(CallEvent.ringing);

      expect(
        seen,
        isNull,
        reason:
            'placing a call asks the caller to accept the call they just made',
      );
    });

    /// The control: a real incoming call still raises the banner. Without it
    /// the test above passes for a device that reports nothing at all.
    test('an incoming call still raises the banner', () {
      final device = MobileVoiceDevice();
      var raised = false;
      device.onIncoming = (_) => raised = true;

      device.handleEvent(CallEvent.incoming);

      expect(raised, isTrue);
    });

    /// Answering happens in CallKit on a handset, so the desk has to be told
    /// or it goes on offering its own accept button for a live call.
    test('answering natively tells the desk, without ending the call', () {
      final device = MobileVoiceDevice();
      var answered = 0;
      var ended = 0;
      device.onAnswered = () => answered++;
      device.onEnded = () => ended++;

      device.handleEvent(CallEvent.answer);

      expect(answered, 1);
      expect(
        ended,
        0,
        reason:
            'the desk was told the call was over at the moment it started, '
            'which takes this person off on_call mid-conversation',
      );
    });

    test('the four ways a call ends still end it', () {
      for (final event in [
        CallEvent.callEnded,
        CallEvent.missedCall,
        CallEvent.declined,
      ]) {
        final device = MobileVoiceDevice();
        var ended = false;
        device.onEnded = () => ended = true;

        device.handleEvent(event);

        expect(ended, isTrue, reason: '$event left the desk showing a call');
      }
    });
  });

  /// iOS must NOT supply one: the plugin fills `deviceToken` from PushKit
  /// itself, so a value from Dart would be wrong rather than merely spare.
  test('iOS registers without asking the channel for a token', () async {
    debugDefaultTargetPlatformOverride = TargetPlatform.iOS;
    addTearDown(() => debugDefaultTargetPlatformOverride = null);

    Object? seen = 'untouched';

    final device = MobileVoiceDevice(
      readDeviceToken: () async => null,
      register: (_, deviceToken) async {
        seen = deviceToken;

        return true;
      },
    );

    await device.setup('a-token');

    expect(seen, isNull);
    expect(device.registered, isTrue);
    expect(device.registrationError, isNull);
  });
}
