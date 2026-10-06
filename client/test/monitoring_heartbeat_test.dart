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
  ({MonitoringHeartbeat heartbeat, List<Map<String, dynamic>> posted}) harness({
    SpecsRead? readSpecs,
    bool screenMonitoringExempt = false,
  }) {
    final posted = <Map<String, dynamic>>[];

    final api = ApiClient(
      client: MockClient((request) async {
        posted.add(jsonDecode(request.body) as Map<String, dynamic>);
        return http.Response('', 204);
      }),
    );

    return (
      heartbeat: MonitoringHeartbeat(
        api,
        readSpecs: readSpecs,
        screenMonitoringExempt: screenMonitoringExempt,
      ),
      posted: posted,
    );
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
    'a beat carries specs and the specs capability when the reader returns them',
    () async {
      final it = harness(
        readSpecs: () async => {
          'ram_gb': 16,
          'os_version': 'Windows 11 26100',
        },
      );

      await it.heartbeat.refreshSpecs(); // deterministic: force the read before beating
      await it.heartbeat.enter('jobs'); // triggers a post
      await pumpEventQueue(); // let the async post run

      expect(it.posted.last['specs'], {
        'ram_gb': 16,
        'os_version': 'Windows 11 26100',
      });
      expect(it.posted.last['capabilities'], contains('specs'));

      it.heartbeat.dispose();
    },
  );

  test(
    'a beat omits specs and stays activity-only when the reader returns null',
    () async {
      final it = harness(readSpecs: () async => null);

      await it.heartbeat.refreshSpecs();
      await it.heartbeat.enter('jobs');
      await pumpEventQueue();

      expect(it.posted.last.containsKey('specs'), isFalse);
      expect(it.posted.last['capabilities'], ['activity']);

      it.heartbeat.dispose();
    },
  );

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
      addTearDown(() => debugDefaultTargetPlatformOverride = null);

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

  test(
    "an exempt device omits 'screenshot' even where the build could capture "
    '(the SECURITY exemption)',
    () async {
      // The TRUE-branch platform (a capture-capable Windows build) — the exact
      // state the paired test above declares 'screenshot' under. Here the Lead
      // Developer's exemption must win over it: the capability is dropped even
      // though screenshotCaptureSupported is true. That is the whole point —
      // without the exempt gate this beat would carry 'screenshot'.
      debugDefaultTargetPlatformOverride = TargetPlatform.windows;
      addTearDown(() => debugDefaultTargetPlatformOverride = null);

      final it = harness(screenMonitoringExempt: true);
      await it.heartbeat.enter('jobs');

      expect(
        it.posted.single['capabilities'],
        ['activity'],
        reason: 'An exempt device still reports activity (it stays online) but '
            "must never advertise 'screenshot' — the roster filters to capture-"
            'capable devices, and the Lead Developer must not appear there.',
      );

      it.heartbeat.dispose();
    },
  );

  group('the beat carries the last capture outcome', () {
    test('no capture reported yet means no capture block at all', () async {
      final it = harness();
      await it.heartbeat.enter('jobs');

      expect(
        it.posted.single.containsKey('capture'),
        isFalse,
        reason: 'A device that has not attempted a capture must say nothing '
            'rather than assert a state it has no evidence for — the server '
            'leaves the last known answer alone when the block is absent.',
      );

      it.heartbeat.dispose();
    });

    test('a reported failure rides on the next beat, with its reason', () async {
      final it = harness();

      it.heartbeat.reportCapture(ok: false, reason: 'jpeg_encoder_missing');
      await it.heartbeat.enter('jobs');

      final capture = it.posted.single['capture'] as Map<String, dynamic>;
      expect(capture['ok'], isFalse);
      expect(capture['reason'], 'jpeg_encoder_missing');
      expect(
        DateTime.tryParse(capture['at'] as String),
        isNotNull,
        reason: 'The server validates `capture.at` as a date, so an unparseable '
            'stamp would 422 the whole beat — taking presence down with it.',
      );

      it.heartbeat.dispose();
    });

    test('a success reports ok with no reason key', () async {
      final it = harness();

      it.heartbeat.reportCapture(ok: true);
      await it.heartbeat.enter('jobs');

      final capture = it.posted.single['capture'] as Map<String, dynamic>;
      expect(capture['ok'], isTrue);
      expect(capture.containsKey('reason'), isFalse,
          reason: 'Omitted rather than null — the server reads ok:true as '
              '"clear the stored fault", and a reason alongside it would be '
              'two answers to one question.');

      it.heartbeat.dispose();
    });

    test('capture_ms rides the beat when the service timed the capture', () async {
      // The freeze diagnostic (2026-10-06): production shows how long a capture
      // took per machine. Omitted when not measured, present when it is.
      final it = harness();

      it.heartbeat.reportCapture(ok: true, captureMs: 42);
      await it.heartbeat.enter('jobs');

      final capture = it.posted.single['capture'] as Map<String, dynamic>;
      expect(capture['capture_ms'], 42);

      it.heartbeat.dispose();
    });

    test('capture_ms is omitted, not null, when it was not measured', () async {
      final it = harness();

      it.heartbeat.reportCapture(ok: false, reason: 'screen_locked');
      await it.heartbeat.enter('jobs');

      final capture = it.posted.single['capture'] as Map<String, dynamic>;
      expect(capture.containsKey('capture_ms'), isFalse);

      it.heartbeat.dispose();
    });

    test('the outcome keeps riding on later beats until it is replaced', () async {
      final it = harness();

      it.heartbeat.reportCapture(ok: false, reason: 'screen_locked');
      await it.heartbeat.enter('jobs');
      await it.heartbeat.enter('customers');

      expect(it.posted.length, 2,
          reason: 'The control: two beats really were sent, so the second '
              "reading below is a second beat's and not the first one twice.");
      expect(
        (it.posted.last['capture'] as Map<String, dynamic>)['reason'],
        'screen_locked',
        reason: 'Beats run every 60s and captures every 5 minutes, so most '
            'beats carry no fresh outcome. If the outcome were cleared once '
            'sent, the roster would go blank for four minutes out of every '
            'five — a stale truth beats a fresh "we do not know".',
      );

      it.heartbeat.dispose();
    });
  });

  testWidgets('a listener that throws cannot break the beat or a module switch',
      (tester) async {
    // `onBeat` belongs to Phone (commit 09641747) and is called at the end of
    // `_post`, which `enter()` AWAITS. So an exception from that callback used
    // to propagate out of "the person switched module" — a presence fetch
    // failing would have taken the nav rail down with it, in a module that has
    // nothing to do with either.
    final h = harness();
    h.heartbeat.onBeat = () => throw StateError('the listener is broken');

    // Must not throw. This is the assertion; the rest is control.
    await h.heartbeat.enter('jobs');

    // Control 1: the beat still went out, so surviving the throw is the guard
    // working rather than `_post` having bailed before it posted.
    expect(h.posted, hasLength(1));
    expect(h.posted.single['current_module'], 'jobs');

    // Control 2: a SECOND switch still beats. A guard that swallowed the error
    // but left the object wedged would pass the line above and fail here.
    await h.heartbeat.enter('customers');
    expect(h.posted, hasLength(2));
    expect(h.posted.last['current_module'], 'customers');

    h.heartbeat.dispose();
  });
}
