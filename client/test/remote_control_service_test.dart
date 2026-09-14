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
    List<bool> indicated,
  }) harness({
    required bool controlWanted,
    required List<Map<String, Object?>> events,
    bool indicator = false,
  }) {
    final polled = <http.Request>[];
    final injected = <List<Map<String, Object?>>>[];
    final indicated = <bool>[];

    final api = ApiClient(
      client: MockClient((request) async {
        polled.add(request);

        if (request.url.path.endsWith('/monitoring/input-pending')) {
          return http.Response(
            jsonEncode({
              'events': events,
              'control_wanted': controlWanted,
              'indicator': indicator,
            }),
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
      // The fake indicator channel: records every setControlled(active) the
      // service asks for, where it would otherwise call the native tray.
      indicated: indicated,
    );
  }

  RemoteControlService build(
    ({
      ApiClient api,
      List<http.Request> polled,
      List<List<Map<String, Object?>>> injected,
      List<bool> indicated,
    }) it,
  ) =>
      RemoteControlService(
        it.api,
        fast: const Duration(milliseconds: 20),
        slow: const Duration(milliseconds: 20),
        inject: (events) async => it.injected.add(events),
        indicate: (active) async => it.indicated.add(active),
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
    'when the org enables the indicator AND control is wanted, the service '
    'raises the notice once via setControlled(true)',
    () async {
      final it = harness(
        controlWanted: true,
        indicator: true,
        events: const [],
      );

      final service = build(it);
      await Future<void>.delayed(const Duration(milliseconds: 80));
      service.dispose();

      expect(it.indicated.contains(true), isTrue,
          reason: 'control_wanted && indicator must raise the tray notice.');
      // Raised on the transition only — several polls saw the same state, but
      // the channel is called once for the rise (dispose then adds a false).
      expect(it.indicated.where((a) => a).length, 1,
          reason: 'The notice must be pushed once, not once per poll.');
    },
  );

  test(
    'with the indicator off, the notice is never raised even while control is '
    'wanted',
    () async {
      // indicator defaults to false — the silent default. This is the path the
      // constraints demand changes NOTHING on the machine.
      final it = harness(controlWanted: true, indicator: false, events: const []);

      final service = build(it);
      await Future<void>.delayed(const Duration(milliseconds: 80));
      service.dispose();

      expect(it.indicated.contains(true), isFalse,
          reason: 'A silent org (indicator false) must never raise the notice, '
              'however long control is wanted.');
    },
  );

  test(
    'the indicator alone, with no control wanted, never raises the notice',
    () async {
      // Only BOTH — being driven AND opted in — shows it. Viewing stays silent.
      final it = harness(controlWanted: false, indicator: true, events: const []);

      final service = build(it);
      await Future<void>.delayed(const Duration(milliseconds: 80));
      service.dispose();

      expect(it.indicated.contains(true), isFalse,
          reason: 'indicator true but control_wanted false is watching, not '
              'controlling — it must stay silent.');
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

  test(
    'the published flag follows the notice, so the app can say it too',
    () async {
      // The tray notice is a balloon the OS may swallow — Focus Assist, a
      // full-screen window, a notification policy — and Shell_NotifyIcon
      // reports success either way. So the app draws its own banner, and this
      // is the flag it watches. It must agree with the native call exactly:
      // a banner that appeared when the tray notice did not, or the reverse,
      // would be two different answers to "is somebody driving this machine".
      final it = harness(controlWanted: true, indicator: true, events: const []);

      final service = build(it);
      expect(service.controlled.value, isFalse,
          reason: 'Nothing is claimed before the first poll answers.');

      await Future<void>.delayed(const Duration(milliseconds: 80));

      expect(service.controlled.value, isTrue);
      expect(it.indicated.contains(true), isTrue,
          reason: 'Control: the native notice went up too, so the flag is '
              'tracking the same fact rather than a second opinion.');

      service.dispose();

      // Cleared on the way out, for the same reason dispose already clears the
      // native notice: a banner must not outlive the loop that raised it.
      expect(service.controlled.value, isFalse);
    },
  );

  test(
    'a silent organization raises no banner either',
    () async {
      // The constraint that matters most: an org that did not opt in gets
      // NOTHING added to its screen. The banner is a second surface for the
      // same opt-in, never a way around it.
      final it = harness(controlWanted: true, indicator: false, events: const []);

      final service = build(it);
      await Future<void>.delayed(const Duration(milliseconds: 80));

      expect(service.controlled.value, isFalse,
          reason: 'indicator false means silent, on every surface.');

      service.dispose();
    },
  );
}
