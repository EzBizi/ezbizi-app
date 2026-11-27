import 'package:ezbizi_client/core/api_client.dart';
import 'package:ezbizi_client/core/push_registration.dart';
import 'package:flutter/foundation.dart';
import 'package:flutter/services.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';

/// What the server was told, in order.
final _sent = <Map<String, String>>[];

ApiClient _api() {
  _sent.clear();

  return ApiClient(
    client: MockClient((request) async {
      _sent.add({'path': request.url.path, 'body': request.body});
      return http.Response('{"data":{"registered":true}}', 200);
    }),
  );
}

void main() {
  late TestWidgetsFlutterBinding binding;

  setUp(() {
    binding = TestWidgetsFlutterBinding.ensureInitialized();
    // Static listener state outlives a test; without this the second test
    // of a pair measures the first one's handler.
    PushRegistration.resetForTest();
  });

  tearDown(() {
    debugDefaultTargetPlatformOverride = null;
    binding.defaultBinaryMessenger.setMockMethodCallHandler(
      const MethodChannel('com.ezbizi.app/fcm'),
      null,
    );
    binding.defaultBinaryMessenger.setMockMethodCallHandler(
      const MethodChannel('com.ezbizi.app/apns'),
      null,
    );
  });

  /// Answers getToken with [token], and counts how often it was asked.
  List<int> mockChannel(String? token, {PlatformException? fails}) {
    final asked = [0];

    binding.defaultBinaryMessenger.setMockMethodCallHandler(
      const MethodChannel('com.ezbizi.app/fcm'),
      (call) async {
        expect(call.method, 'getToken');
        asked[0]++;
        if (fails != null) throw fails;
        return token;
      },
    );

    return asked;
  }

  test('sends the token and the platform to the devices endpoint', () async {
    debugDefaultTargetPlatformOverride = TargetPlatform.android;
    mockChannel('a-real-looking-token');

    await PushRegistration.start(_api());

    expect(_sent, hasLength(1));
    expect(_sent.single['path'], endsWith('/api/v1/notifications/devices'));
    // The literal body, because the server validates BOTH fields and a
    // missing platform is a 422 rather than a default.
    expect(_sent.single['body'], contains('"token":"a-real-looking-token"'));
    expect(_sent.single['body'], contains('"platform":"android"'));
  });

  test('registers nothing off Android, without asking for a token', () async {
    // Not because iOS has no push — it does, there is an APNs sender. Because
    // the token THIS class can reach is a PushKit VoIP token on iOS, which can
    // never receive a notification. An iOS token comes from the Swift side.
    // Asserting the channel is never called is the point: a token we never
    // produce cannot be sent by a later refactor that trusts the endpoint to
    // refuse it.
    debugDefaultTargetPlatformOverride = TargetPlatform.iOS;
    final asked = mockChannel('a-voip-token-that-must-not-be-sent');

    await PushRegistration.start(_api());

    expect(asked[0], 0, reason: 'the channel should not even be called');
    expect(_sent, isEmpty);
  });

  test('a device with no token registers nothing and does not throw', () async {
    // A handset without Play Services. It must not buzz, and must not stop
    // the app it is inside from loading.
    debugDefaultTargetPlatformOverride = TargetPlatform.android;
    mockChannel(
      null,
      fails: PlatformException(code: 'fcm_token_unavailable'),
    );

    await PushRegistration.start(_api());

    expect(_sent, isEmpty);
  });

  test('an empty token is not sent', () async {
    // Distinct from the case above: the channel SUCCEEDED and handed back
    // nothing useful. An empty token posted would be a row addressing no
    // device, which reads as registered forever.
    debugDefaultTargetPlatformOverride = TargetPlatform.android;
    mockChannel('');

    await PushRegistration.start(_api());

    expect(_sent, isEmpty);
  });

  group('iOS', () {
    /// Answers the apns channel's getToken, and counts the asks.
    List<int> mockApns(String? token, {PlatformException? fails, String? voip}) {
      final asked = [0];

      binding.defaultBinaryMessenger.setMockMethodCallHandler(
        const MethodChannel('com.ezbizi.app/apns'),
        (call) async {
          // PushKit's token for CallKit (Apple 11-27), asked after the APNs one.
          if (call.method == 'getVoipToken') return voip;
          expect(call.method, 'getToken');
          asked[0]++;
          if (fails != null) throw fails;
          return token;
        },
      );

      return asked;
    }

    /// Delivers what the Swift side would push into the handler.
    Future<void> deliver(String method, Object? arguments) {
      return binding.defaultBinaryMessenger.handlePlatformMessage(
        'com.ezbizi.app/apns',
        const StandardMethodCodec().encodeMethodCall(
          MethodCall(method, arguments),
        ),
        (_) {},
      );
    }

    test('a token from getToken is registered as ios, never as android', () async {
      debugDefaultTargetPlatformOverride = TargetPlatform.iOS;
      mockApns('a1b2c3deadbeef');

      await PushRegistration.start(_api());

      expect(_sent.length, 1);
      expect(_sent.single['path'], contains('/notifications/devices'));
      expect(_sent.single['body'], contains('"platform":"ios"'));
      // The whole point of two channels. An android label on an APNs token
      // would be accepted by the server and could never be delivered to.
      expect(_sent.single['body'], isNot(contains('android')));
    });

    test('a VoIP token registers as ios_voip, beside the ios one', () async {
      debugDefaultTargetPlatformOverride = TargetPlatform.iOS;
      mockApns('a1b2c3deadbeef', voip: 'f00dvoip');

      await PushRegistration.start(_api());

      expect(_sent.length, 2);
      expect(_sent.last['body'], contains('"platform":"ios_voip"'));
      expect(_sent.last['body'], contains('f00dvoip'));
    });

    test('a null token posts nothing, and the later onToken registers it', () async {
      // `null` is the ORDINARY answer during launch — registration is
      // asynchronous. There is no retry loop because there does not need to
      // be: the handler is attached before the ask, so the token arrives
      // through onToken whenever it is ready.
      debugDefaultTargetPlatformOverride = TargetPlatform.iOS;
      mockApns(null);

      await PushRegistration.start(_api());
      expect(_sent, isEmpty, reason: 'nothing to register yet');

      await deliver('onToken', 'arrived-a-moment-later');

      expect(_sent.length, 1);
      expect(_sent.single['body'], contains('arrived-a-moment-later'));
      expect(_sent.single['body'], contains('"platform":"ios"'));
    });

    test('apns_unavailable is permanent and registers nothing', () async {
      // A simulator has no APNs token and never will. This is the answer that
      // must NOT be treated like `null` — retrying it is a loop that can only
      // fail, and the distinction is why getToken has three answers.
      debugDefaultTargetPlatformOverride = TargetPlatform.iOS;
      final asked = mockApns(
        null,
        fails: PlatformException(code: 'apns_unavailable'),
      );

      await PushRegistration.start(_api());

      expect(_sent, isEmpty);
      expect(asked[0], 1, reason: 'asked once and did not loop');
    });

    test('an empty token is never posted', () async {
      // A row addressing no device is worse than no row: it reads as
      // registered and can never deliver.
      debugDefaultTargetPlatformOverride = TargetPlatform.iOS;
      mockApns('');

      await PushRegistration.start(_api());

      expect(_sent, isEmpty);
    });

    test('isRegistered stays false when the server refuses the token', () async {
      // The flag the bell reads to stop drawing its own notification. It must
      // mean "the server accepted our token", not "we have one" — a token the
      // server never received is a device the server cannot reach, and
      // suppressing on the strength of one trades a visible duplicate for
      // silence.
      debugDefaultTargetPlatformOverride = TargetPlatform.iOS;
      mockApns('a-real-token');

      final api = ApiClient(
        client: MockClient((request) async {
          _sent.add({'path': request.url.path, 'body': request.body});
          return http.Response('{"message":"nope"}', 500);
        }),
      );

      await PushRegistration.start(api);

      expect(_sent, hasLength(1), reason: 'it tried');
      expect(PushRegistration.isRegistered, isFalse);
    });

    test('isRegistered becomes true only after an accepted POST', () async {
      // The control. Without it the test above passes against a flag that is
      // never set at all, which would suppress nothing and leave the duplicate
      // in place.
      debugDefaultTargetPlatformOverride = TargetPlatform.iOS;
      mockApns('a-real-token');

      expect(PushRegistration.isRegistered, isFalse, reason: 'before');

      await PushRegistration.start(_api());

      expect(PushRegistration.isRegistered, isTrue, reason: 'after');
    });

    test('the apns channel is not touched on android', () async {
      // The control that keeps the two halves apart. Android must never reach
      // the Apple channel, and the reverse is already covered above.
      debugDefaultTargetPlatformOverride = TargetPlatform.android;
      final apns = mockApns('should-not-be-asked');
      binding.defaultBinaryMessenger.setMockMethodCallHandler(
        const MethodChannel('com.ezbizi.app/fcm'),
        (call) async => null,
      );

      await PushRegistration.start(_api());

      expect(apns[0], 0, reason: 'never asked the Apple channel');
    });
  });
}
