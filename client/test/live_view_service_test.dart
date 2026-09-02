import 'dart:convert';
import 'dart:typed_data';

import 'package:ezbizi_client/core/api_client.dart';
import 'package:ezbizi_client/core/device_key.dart';
import 'package:ezbizi_client/core/live_view_service.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';

/// The agent half of Live View — see
/// app/Http/Controllers/Api/Monitoring/LiveViewController.php for the
/// contract this pins: `GET /monitoring/live-wanted` answers `{"wanted":
/// bool}` with no envelope, and only a `true` answer may ever be followed by
/// a `POST /monitoring/live-frame`.
void main() {
  ({ApiClient api, List<http.Request> posted}) harness({required bool wanted}) {
    final posted = <http.Request>[];

    final api = ApiClient(
      client: MockClient((request) async {
        posted.add(request);

        if (request.url.path.endsWith('/monitoring/live-wanted')) {
          return http.Response(
            jsonEncode({'wanted': wanted}),
            200,
            headers: const {'content-type': 'application/json'},
          );
        }

        return http.Response('', 204);
      }),
    );

    return (api: api, posted: posted);
  }

  /// Multipart bodies are binary — see screenshot_service_test.dart's own
  /// copy of this helper for why latin1 rather than utf8.
  String bodyText(http.Request request) => latin1.decode(request.bodyBytes);

  test(
    'when live-wanted answers true, the service captures and uploads a live '
    'frame carrying the shared device_key',
    () async {
      final it = harness(wanted: true);

      final service = LiveViewService(
        it.api,
        interval: const Duration(milliseconds: 20),
        capture: () async => Uint8List.fromList([1, 2, 3, 4, 5]),
      );

      await Future<void>.delayed(const Duration(milliseconds: 80));
      service.dispose();

      final wantedCalls =
          it.posted.where((r) => r.url.path.endsWith('/monitoring/live-wanted')).toList();
      expect(wantedCalls, isNotEmpty,
          reason: 'The agent must poll live-wanted on every tick.');
      expect(wantedCalls.first.url.queryParameters['device_key'], isNotEmpty,
          reason: 'live-wanted must be asked about THIS device, by key.');

      final uploads =
          it.posted.where((r) => r.url.path.endsWith('/monitoring/live-frame')).toList();
      expect(uploads, isNotEmpty,
          reason: 'A true answer must be followed by a captured frame upload.');

      final body = bodyText(uploads.first);
      expect(body, contains('name="image"'),
          reason: 'The JPEG bytes must be sent under the `image` field the '
              'server validates.');
      expect(body, contains('name="device_key"'));

      final deviceKey = await DeviceKey.value();
      expect(body, contains(deviceKey),
          reason: 'The frame must carry the same shared device_key as the '
              'live-wanted poll, not a second independently generated key.');
    },
  );

  test(
    'when live-wanted answers false, the service polls but never uploads a '
    'frame',
    () async {
      final it = harness(wanted: false);

      final service = LiveViewService(
        it.api,
        interval: const Duration(milliseconds: 20),
        capture: () async => Uint8List.fromList([1, 2, 3, 4, 5]),
      );

      await Future<void>.delayed(const Duration(milliseconds: 80));
      service.dispose();

      expect(
        it.posted.where((r) => r.url.path.endsWith('/monitoring/live-wanted')),
        isNotEmpty,
        reason: 'The idle cost is one small GET per tick — that must still '
            'happen.',
      );
      expect(
        it.posted.where((r) => r.url.path.endsWith('/monitoring/live-frame')),
        isEmpty,
        reason: 'Nobody is watching — no capture, no upload, at all.',
      );
    },
  );

  test(
    'on a platform without capture support, the timer never starts and '
    'nothing is ever requested',
    () async {
      // No injected `capture` — this exercises the real constructor guard
      // against `screenshotCaptureSupported`, which `flutter test` forces
      // false regardless of host OS (see screenshot_capability.dart's doc
      // comment). Mirrors screenshot_service_test.dart's own reliance on
      // that ambient platform behaviour for the same guard.
      final it = harness(wanted: true);

      final service = LiveViewService(it.api, interval: const Duration(milliseconds: 20));

      await Future<void>.delayed(const Duration(milliseconds: 80));
      service.dispose();

      expect(
        it.posted,
        isEmpty,
        reason: 'A platform with no capture channel must never start the '
            'poll timer at all — not even the idle live-wanted GET.',
      );
    },
  );
}
