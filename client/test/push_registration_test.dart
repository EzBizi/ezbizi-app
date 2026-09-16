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
  });

  tearDown(() {
    debugDefaultTargetPlatformOverride = null;
    binding.defaultBinaryMessenger.setMockMethodCallHandler(
      const MethodChannel('com.ezbizi.app/fcm'),
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
    // iOS is refused by the endpoint on purpose: the same plugin property
    // holds a PushKit VoIP token there, which cannot receive a notification.
    // Stopping here means we never present one for refusal.
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
}
