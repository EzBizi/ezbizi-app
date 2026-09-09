import 'dart:convert';
import 'dart:typed_data';

import 'package:ezbizi_client/core/api_client.dart';
import 'package:ezbizi_client/core/device_key.dart';
import 'package:ezbizi_client/core/monitoring_heartbeat.dart';
import 'package:ezbizi_client/core/screenshot_service.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';

/// The client half of the upload ScreenshotsController::store() validates —
/// see app/Http/Controllers/Api/Monitoring/ScreenshotsController.php. That
/// endpoint 404s outright unless a `monitored_devices` row already exists
/// for the SAME `device_key` a heartbeat posted (its class doc comment
/// explains why it never creates one itself), so what this suite pins is not
/// just "an upload fires" but that it fires with the identical key
/// [MonitoringHeartbeat] uses — a mismatch here is invisible to the compiler
/// and fatal against the real endpoint.
void main() {
  ({ApiClient api, List<http.Request> posted}) harness() {
    final posted = <http.Request>[];

    final api = ApiClient(
      client: MockClient((request) async {
        posted.add(request);
        return http.Response('', 204);
      }),
    );

    return (api: api, posted: posted);
  }

  /// Multipart bodies are binary (the file part is arbitrary bytes), so this
  /// decodes byte-for-byte with latin1 rather than utf8 — utf8.decode throws
  /// on a byte sequence that is not valid UTF-8, and the whole point is to
  /// find ASCII substrings (field names, the device key) inside a body that
  /// may contain non-UTF-8 bytes elsewhere.
  String bodyText(http.Request request) => latin1.decode(request.bodyBytes);

  test(
    'a capture that returns bytes uploads to monitoring/screenshots carrying the device_key',
    () async {
      final it = harness();

      final service = ScreenshotService(
        it.api,
        interval: const Duration(milliseconds: 20),
        capture: () async => (bytes: Uint8List.fromList([1, 2, 3, 4, 5]), reason: null),
      );

      await Future<void>.delayed(const Duration(milliseconds: 80));
      service.dispose();

      final uploads =
          it.posted.where((r) => r.url.path.endsWith('/monitoring/screenshots')).toList();

      expect(uploads, isNotEmpty,
          reason: 'A capture that returned bytes must upload them.');

      final body = bodyText(uploads.first);
      expect(body, contains('name="image"'),
          reason: 'The JPEG bytes must be sent under the `image` field the '
              'server validates.');
      expect(body, contains('name="device_key"'),
          reason: 'The upload must carry a device_key field at all.');

      final deviceKey = await DeviceKey.value();
      expect(body, contains(deviceKey),
          reason: 'The device_key value sent must be the shared one, not a '
              'second independently generated key.');
    },
  );

  test('a capture that returns null never uploads', () async {
    final it = harness();

    final service = ScreenshotService(
      it.api,
      interval: const Duration(milliseconds: 20),
      capture: () async => (bytes: null, reason: 'screen_locked'),
    );

    await Future<void>.delayed(const Duration(milliseconds: 80));
    service.dispose();

    expect(
      it.posted.where((r) => r.url.path.endsWith('/monitoring/screenshots')),
      isEmpty,
      reason: 'No bytes means nothing to upload — a failed/unavailable '
          'capture must not fire a request at all.',
    );
  });

  test(
    'the screenshot upload carries the exact device_key the heartbeat uses',
    () async {
      final it = harness();

      final heartbeat = MonitoringHeartbeat(it.api);
      await heartbeat.enter('jobs');

      final beat = it.posted.singleWhere((r) => r.url.path.endsWith('/monitoring/heartbeat'));
      final heartbeatDeviceKey =
          (jsonDecode(beat.body) as Map<String, dynamic>)['device_key'] as String;
      heartbeat.dispose();

      final service = ScreenshotService(
        it.api,
        interval: const Duration(milliseconds: 20),
        capture: () async => (bytes: Uint8List.fromList([9, 8, 7]), reason: null),
      );

      await Future<void>.delayed(const Duration(milliseconds: 80));
      service.dispose();

      final upload =
          it.posted.firstWhere((r) => r.url.path.endsWith('/monitoring/screenshots'));

      expect(
        bodyText(upload),
        contains(heartbeatDeviceKey),
        reason: 'A screenshot posted under a different device_key than the '
            "heartbeat's resolves to a different (or nonexistent) "
            'monitored_devices row on the server — see '
            "ScreenshotsController::store()'s 404 when no row matches.",
      );
    },
  );

  group('every attempt reports its outcome', () {
    test('a failed capture reports the native reason, and does not upload', () async {
      final it = harness();
      final outcomes = <({bool ok, String? reason})>[];

      final service = ScreenshotService(
        it.api,
        interval: const Duration(milliseconds: 20),
        capture: () async => (bytes: null, reason: 'jpeg_encoder_missing'),
        onOutcome: ({required bool ok, String? reason}) =>
            outcomes.add((ok: ok, reason: reason)),
      );

      await Future<void>.delayed(const Duration(milliseconds: 80));
      service.dispose();

      expect(outcomes, isNotEmpty,
          reason: 'A capture that produced nothing must still report — the '
              'silence is the bug this exists to fix.');
      expect(outcomes.first.ok, isFalse);
      expect(outcomes.first.reason, 'jpeg_encoder_missing',
          reason: "The native side's reason must reach the report intact; "
              'flattening it to a generic failure is what made this '
              'undiagnosable in the first place.');
      expect(
        it.posted.where((r) => r.url.path.endsWith('/monitoring/screenshots')),
        isEmpty,
        reason: 'Nothing to upload.',
      );
    });

    test('a successful capture reports ok with no reason', () async {
      final it = harness();
      final outcomes = <({bool ok, String? reason})>[];

      final service = ScreenshotService(
        it.api,
        interval: const Duration(milliseconds: 20),
        capture: () async => (bytes: Uint8List.fromList([1, 2, 3]), reason: null),
        onOutcome: ({required bool ok, String? reason}) =>
            outcomes.add((ok: ok, reason: reason)),
      );

      await Future<void>.delayed(const Duration(milliseconds: 80));
      service.dispose();

      expect(outcomes.first.ok, isTrue);
      expect(outcomes.first.reason, isNull,
          reason: 'A success must CLEAR the reason, not leave the last '
              'failure standing — a device that recovers has to stop '
              'reporting a stale fault.');
    });

    test('an upload the server refuses is reported with its status', () async {
      final posted = <http.Request>[];
      final api = ApiClient(
        client: MockClient((request) async {
          posted.add(request);
          return http.Response('{"message":"nope"}', 404);
        }),
      );
      final outcomes = <({bool ok, String? reason})>[];

      final service = ScreenshotService(
        api,
        interval: const Duration(milliseconds: 20),
        capture: () async => (bytes: Uint8List.fromList([1, 2, 3]), reason: null),
        onOutcome: ({required bool ok, String? reason}) =>
            outcomes.add((ok: ok, reason: reason)),
      );

      await Future<void>.delayed(const Duration(milliseconds: 80));
      service.dispose();

      expect(posted, isNotEmpty,
          reason: 'The control for this test: the upload must actually have '
              'been attempted, or a "refused" reading means nothing.');
      expect(outcomes.first.ok, isFalse);
      expect(outcomes.first.reason, 'upload_404',
          reason: 'A capture that worked and an upload that was refused are '
              'different faults with different fixes, so the status is kept '
              'rather than folded into a generic failure.');
    });

    test('a throwing onOutcome never breaks the capture loop', () async {
      final it = harness();
      var attempts = 0;

      final service = ScreenshotService(
        it.api,
        interval: const Duration(milliseconds: 20),
        capture: () async {
          attempts++;
          return (bytes: Uint8List.fromList([1, 2, 3]), reason: null);
        },
        onOutcome: ({required bool ok, String? reason}) =>
            throw StateError('the reporter belongs to somebody else'),
      );

      await Future<void>.delayed(const Duration(milliseconds: 80));
      service.dispose();

      expect(attempts, greaterThan(1),
          reason: 'Reporting is a courtesy to the roster; it must never be '
              'able to stop the thing it is reporting on.');
    });
  });
}
