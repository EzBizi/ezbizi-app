import 'dart:convert';

import 'package:ezbizi_client/core/api_client.dart';
import 'package:ezbizi_client/core/camera_watch.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';

/// Asking to watch a bridge camera (POST /monitoring/cameras/{id}/watch).
void main() {
  ApiClient api(http.Response Function(http.Request) reply) =>
      ApiClient(client: MockClient((r) async => reply(r)));

  http.Response json(Object body, [int status = 200]) => http.Response(
    jsonEncode(body),
    status,
    headers: const {'content-type': 'application/json'},
  );

  test('returns the play URL and sends the quality', () async {
    late Map<String, dynamic> sent;
    final play = await watchCamera(
      api((r) {
        expect(r.url.path, endsWith('/monitoring/cameras/c-1/watch'));
        sent = jsonDecode(r.body) as Map<String, dynamic>;
        return json({
          'play': {
            'url': 'https://cams.test/x',
            'expires_at': '2026-10-20T12:05:00Z',
          },
        });
      }),
      'c-1',
      quality: 'main',
    );

    expect(sent['quality'], 'main');
    expect(play.url, 'https://cams.test/x');
    expect(play.expiresAt, DateTime.utc(2026, 10, 20, 12, 5));
  });

  test(
    'a 409 bridge_offline throws with when the site was last seen',
    () async {
      final call = watchCamera(
        api(
          (_) => json({
            'code': 'bridge_offline',
            'message': 'offline',
            'last_checkin_at': '2026-10-20T11:00:00Z',
          }, 409),
        ),
        'c-1',
      );

      await expectLater(
        call,
        throwsA(
          isA<CameraWatchException>()
              .having((e) => e.code, 'code', 'bridge_offline')
              .having(
                (e) => e.lastSeen,
                'lastSeen',
                DateTime.utc(2026, 10, 20, 11),
              ),
        ),
      );
    },
  );
}
