import 'dart:convert';

import 'package:ezbizi_client/core/api_client.dart';
import 'package:ezbizi_client/core/device_key.dart';
import 'package:ezbizi_client/core/remote_control_service.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';

/// The agent half of Remote Control — see
/// app/Http/Controllers/Api/Monitoring for the contract this pins: `GET
/// /monitoring/input-pending?device_key=...` answers `{"events":[...],
/// "control_wanted":bool}` and RETURNS-AND-CLEARS the queue, and every event
/// it returns is applied via the native input channel.
void main() {
  ({
    ApiClient api,
    List<http.Request> polled,
    List<List<Map<String, Object?>>> injected,
  }) harness({
    required bool controlWanted,
    required List<Map<String, Object?>> events,
  }) {
    final polled = <http.Request>[];
    final injected = <List<Map<String, Object?>>>[];

    final api = ApiClient(
      client: MockClient((request) async {
        polled.add(request);

        if (request.url.path.endsWith('/monitoring/input-pending')) {
          return http.Response(
            jsonEncode({'events': events, 'control_wanted': controlWanted}),
            200,
            headers: const {'content-type': 'application/json'},
          );
        }

        return http.Response('', 204);
      }),
    );

    return (
      api: api,
      polled: polled,
      // The fake input channel: records every batch the service asks to apply,
      // exactly where RemoteControlService would otherwise call the native
      // `injectInput`.
      injected: injected,
    );
  }

  RemoteControlService build(
    ({
      ApiClient api,
      List<http.Request> polled,
      List<List<Map<String, Object?>>> injected,
    }) it,
  ) =>
      RemoteControlService(
        it.api,
        fast: const Duration(milliseconds: 20),
        slow: const Duration(milliseconds: 20),
        inject: (events) async => it.injected.add(events),
      );

  test(
    'when input-pending answers control_wanted:true with events, the service '
    'polls by the shared device_key and applies exactly those events',
    () async {
      final events = <Map<String, Object?>>[
        {'type': 'mouseMove', 'x': 0.5, 'y': 0.25},
        {'type': 'mouseButton', 'button': 'left', 'down': true},
      ];
      final it = harness(controlWanted: true, events: events);

      final service = build(it);
      await Future<void>.delayed(const Duration(milliseconds: 80));
      service.dispose();

      final polls = it.polled
          .where((r) => r.url.path.endsWith('/monitoring/input-pending'))
          .toList();
      expect(polls, isNotEmpty,
          reason: 'The agent must poll input-pending on every tick.');

      final deviceKey = await DeviceKey.value();
      expect(polls.first.url.queryParameters['device_key'], deviceKey,
          reason: 'input-pending must be asked about THIS device, by the same '
              'shared device_key the rest of monitoring uses.');

      expect(it.injected, isNotEmpty,
          reason: 'Returned events must be applied via the input channel.');
      expect(it.injected.first, equals(events),
          reason: 'The exact events the server returned must be what is '
              'injected — same shapes, in order.');
    },
  );

  test(
    'when input-pending returns no events, nothing is ever injected even '
    'though the poll keeps running',
    () async {
      final it = harness(controlWanted: false, events: const []);

      final service = build(it);
      await Future<void>.delayed(const Duration(milliseconds: 80));
      service.dispose();

      expect(
        it.polled.where((r) => r.url.path.endsWith('/monitoring/input-pending')),
        isNotEmpty,
        reason: 'The idle cost is one small GET per tick — that still happens.',
      );
      expect(
        it.injected,
        isEmpty,
        reason: 'An empty queue means there is nothing to apply — the input '
            'channel must not be called with an empty batch.',
      );
    },
  );

  test(
    'on a platform without capture support, the poll timer never starts and '
    'nothing is ever requested',
    () async {
      // No injected `inject` — this exercises the real constructor guard
      // against `screenshotCaptureSupported`, which `flutter test` forces
      // false regardless of host OS (see screenshot_capability.dart). Mirrors
      // live_view_service_test.dart's reliance on that ambient behaviour.
      final polled = <http.Request>[];
      final api = ApiClient(
        client: MockClient((request) async {
          polled.add(request);
          return http.Response(
            jsonEncode({'events': const [], 'control_wanted': false}),
            200,
            headers: const {'content-type': 'application/json'},
          );
        }),
      );

      final service = RemoteControlService(
        api,
        fast: const Duration(milliseconds: 20),
        slow: const Duration(milliseconds: 20),
      );

      await Future<void>.delayed(const Duration(milliseconds: 80));
      service.dispose();

      expect(
        polled,
        isEmpty,
        reason: 'A platform with no native input channel must never start the '
            'poll timer at all — not even the idle input-pending GET.',
      );
    },
  );
}
