import 'dart:convert';

import 'package:ezbizi_client/core/api_client.dart';
import 'package:ezbizi_client/core/monitoring_heartbeat.dart';
import 'package:flutter/foundation.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';

/// The client half of the beat the server validates in
/// HeartbeatController::store() — see app/Http/Controllers/Api/Monitoring/
/// HeartbeatController.php. The body shape is not this file's to invent: a
/// field spelled differently, or a segment that names the wrong module, is
/// silently accepted by a Dart compiler and then rejected — or worse, wrongly
/// accepted — by the real endpoint, so what is pinned here is the literal
/// wire shape rather than an abstraction over it.
void main() {
  ({MonitoringHeartbeat heartbeat, List<Map<String, dynamic>> posted}) harness() {
    final posted = <Map<String, dynamic>>[];

    final api = ApiClient(
      client: MockClient((request) async {
        posted.add(jsonDecode(request.body) as Map<String, dynamic>);
        return http.Response('', 204);
      }),
    );

    return (heartbeat: MonitoringHeartbeat(api), posted: posted);
  }

  // Reset after EVERY test, not just the one that sets it — a test that
  // failed partway through the override would otherwise leak
  // TargetPlatform.windows into every test that runs after it in this file.
  tearDown(() {
    debugDefaultTargetPlatformOverride = null;
  });

  test(
    'leaving a module posts the module just left, not the one just entered',
    () async {
      final it = harness();

      await it.heartbeat.enter('jobs');
      // Real elapsed time, deliberately: dwell_seconds is a measurement of
      // wall-clock time spent on a module, and there is no fake clock wired
      // into this suite to fast-forward it (see the note on _asksLeft in
      // mailbox_panels.dart for why a DEADLINE would be counted rather than
      // clocked — a DURATION being reported is the opposite case, and has to
      // pass real time to mean anything).
      await Future<void>.delayed(const Duration(milliseconds: 1100));
      await it.heartbeat.enter('customers');

      expect(it.posted.length, 2,
          reason: 'One POST per enter() — the first for jobs, the second for '
              'the move to customers.');

      final secondBeat = it.posted[1];
      expect(secondBeat['current_module'], 'customers');

      final segment = secondBeat['segment'] as Map<String, dynamic>?;
      expect(segment, isNotNull,
          reason: 'Moving off a module must close its segment.');
      expect(
        segment!['module_key'],
        'jobs',
        reason:
            'The segment names the module that was just LEFT. Posting '
            '"customers" here would pass the server\'s validation just as '
            'happily and silently attribute every dwell reading to the wrong '
            'tab — this is the assertion that catches that swap.',
      );
      expect(
        segment['dwell_seconds'],
        greaterThan(0),
        reason: 'Time was spent on jobs before leaving it; a 0 here means the '
            'stopwatch was never actually started or read.',
      );

      it.heartbeat.dispose();
    },
  );

  test(
    'the first module entered posts no segment — there is nothing to close',
    () async {
      final it = harness();

      await it.heartbeat.enter('jobs');

      expect(it.posted.length, 1);
      expect(it.posted.single['current_module'], 'jobs');
      expect(it.posted.single.containsKey('segment'), isFalse);

      it.heartbeat.dispose();
    },
  );

  test('device_key is generated once and stays identical across beats', () async {
    final it = harness();

    await it.heartbeat.enter('jobs');
    await it.heartbeat.enter('customers');

    expect(it.posted.length, 2);

    final first = it.posted[0]['device_key'];
    final second = it.posted[1]['device_key'];

    expect(first, isA<String>());
    expect((first as String).isNotEmpty, isTrue);
    expect(second, first,
        reason: 'A device key that changes between beats from the same '
            'install would register as a new device every time it posts.');

    it.heartbeat.dispose();
  });

  test('every beat carries the fixed identity fields the server validates', () async {
    final it = harness();

    await it.heartbeat.enter('jobs');

    final body = it.posted.single;
    expect(body['platform'], isA<String>());
    expect((body['platform'] as String).isNotEmpty, isTrue);
    expect(body['capabilities'], ['activity'],
        reason: 'On the platform `flutter test` reports by default '
            '(TargetPlatform.android, forced by the test binding regardless '
            "of the host OS), screenshotCaptureSupported is false and the "
            "'screenshot' capability must be absent — see the paired test "
            'below for the TRUE branch.');

    it.heartbeat.dispose();
  });

  test(
    "capabilities include 'screenshot' when this device can capture "
    '(Windows + native channel)',
    () async {
      // ScreenshotService's own native-availability check
      // (screenshot_capability.dart) reads defaultTargetPlatform, and the
      // heartbeat's capability list is meant to agree with it exactly —
      // this override is how a plain `test()` (no widget, no real Win32
      // build) can actually put that check into its TRUE state rather than
      // just asserting the FALSE state the suite runs under by default.
      debugDefaultTargetPlatformOverride = TargetPlatform.windows;

      final it = harness();
      await it.heartbeat.enter('jobs');

      final body = it.posted.single;
      expect(
        body['capabilities'],
        ['activity', 'screenshot'],
        reason: "A capture-capable device's beat must declare both "
            "'activity' (every device) and 'screenshot' (this one can "
            'capture) — an inverted condition on screenshotCaptureSupported '
            "would either drop 'screenshot' here or add it to the FALSE-"
            "branch test above; this pins both directions.",
      );

      it.heartbeat.dispose();
    },
  );
}
