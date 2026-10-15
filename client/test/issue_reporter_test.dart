import 'dart:async';
import 'dart:convert';

import 'package:ezbizi_client/core/api_client.dart';
import 'package:ezbizi_client/core/issue_reporter.dart';
import 'package:flutter/services.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';

/// The app reporting its own problems (Jose, 2026-10-15: "We should have the
/// app log crashes and issues as well").
void main() {
  TestWidgetsFlutterBinding.ensureInitialized();

  final reporter = IssueReporter.instance;

  setUp(() async {
    // Drain anything a previous test queued.
    reporter.attach(ApiClient(client: MockClient((_) async => http.Response('', 204))));
    await reporter.flush();
    await reporter.flush();
    await reporter.flush();
    reporter.dispose();
    IssueReporter.module = null;
  });

  test('a stall is a late tick between 2 s and a minute, not a sleeping computer', () {
    final t0 = DateTime(2026, 10, 15, 12);
    Duration? stall(int ms) => IssueReporter.uiStall(t0, t0.add(Duration(milliseconds: ms)));

    expect(stall(600), isNull, reason: 'On time.');
    expect(stall(2000), isNull, reason: '1.5 s late is under the line.');
    expect(stall(4700), const Duration(milliseconds: 4200), reason: 'Lenin\'s kind of freeze.');
    expect(stall(5 * 60 * 1000), isNull, reason: 'A laptop lid, not a freeze.');
  });

  test('a failed call is reported once per endpoint per 10 minutes, with ids folded', () {
    reporter.apiFailure('GET', '/scheduling/availability', 503, const Duration(milliseconds: 900));
    reporter.apiFailure('GET', '/scheduling/availability', 503, const Duration(milliseconds: 950));

    expect(reporter.queued, hasLength(1), reason: 'An outage is one problem.');
    expect(reporter.queued.single['kind'], 'api_failure');
    expect(reporter.queued.single['message'], contains('/scheduling/availability answered 503'));
  });

  test('the reporter never reports its own failures', () {
    reporter.apiFailure('POST', IssueReporter.reportPath, 0, Duration.zero);
    expect(reporter.queued, isEmpty);
  });

  test('a report carries the module on screen', () {
    IssueReporter.module = 'scheduling';
    reporter.record('dart_error', 'Bad state: No element');
    expect(reporter.queued.single['module'], 'scheduling');
  });

  test('flush sends a batch, and keeps it when the server cannot be reached', () async {
    final bodies = <Map<String, dynamic>>[];
    var online = false;
    reporter.attach(ApiClient(client: MockClient((request) async {
      if (!online) throw http.ClientException('offline');
      bodies.add(jsonDecode(request.body) as Map<String, dynamic>);
      return http.Response('', 204);
    })));
    reporter.record('dart_error', 'one');
    reporter.record('freeze', 'two');

    await reporter.flush(); // offline
    expect(reporter.queued.where((q) => q['kind'] != 'api_failure'), hasLength(2),
        reason: 'Nothing is lost while offline.');

    online = true;
    await reporter.flush();
    expect(bodies.single['issues'], hasLength(2));
    reporter.dispose();
  });

  test('a server error through ApiClient becomes a report', () async {
    final api = ApiClient(client: MockClient((_) async => http.Response('{"message":"boom"}', 500)));
    await api.get('/jobs/0194c7e2-3f1a-7b2c-9d4e-a1b2c3d4e5f6/notes');

    final report = reporter.queued.single;
    expect(report['kind'], 'api_failure');
    expect(report['message'], contains('GET /jobs/{id}/notes answered 500'),
        reason: 'The record id is folded out of the path.');
  });

  test('a native call is named while it runs, and not after', () async {
    final gate = Completer<void>();
    final call = IssueReporter.trackNative('readSpecs', () => gate.future);
    expect(IssueReporter.inFlight, contains('readSpecs'));
    gate.complete();
    await call;
    expect(IssueReporter.inFlight, isEmpty);
  });

  test('a slow answer from the platform thread is recorded as a freeze', () async {
    const channel = MethodChannel('com.ezbizi.app/watchdog');
    TestDefaultBinaryMessengerBinding.instance.defaultBinaryMessenger
        .setMockMethodCallHandler(channel, (call) async {
      if (call.method == 'ping') {
        await Future<void>.delayed(const Duration(milliseconds: 2300));
      }
      return null;
    });
    addTearDown(() => TestDefaultBinaryMessengerBinding.instance.defaultBinaryMessenger
        .setMockMethodCallHandler(channel, null));

    IssueReporter.module = 'scheduling';
    reporter.startWatchdogs();
    await Future<void>.delayed(const Duration(milliseconds: 7600)); // one ping at 5 s, 2.3 s slow
    reporter.dispose();

    final freezes = reporter.queued.where((q) => q['kind'] == 'freeze').toList();
    expect(freezes, isNotEmpty);
    expect(freezes.first['message'], contains('The window did not respond'));
    expect(freezes.first['detail'], contains('module: scheduling'));
  }, timeout: const Timeout(Duration(seconds: 20)));

  test('a crash marker left by the last run is reported once', () async {
    const channel = MethodChannel('com.ezbizi.app/watchdog');
    var marker = 'exception 0xC0000005 at 0x7FF6 in flutter_windows.dll (offset 0x1A2B)';
    TestDefaultBinaryMessengerBinding.instance.defaultBinaryMessenger
        .setMockMethodCallHandler(channel, (call) async {
      if (call.method != 'takeLastCrash') return null;
      final m = marker;
      marker = '';
      return m;
    });
    addTearDown(() => TestDefaultBinaryMessengerBinding.instance.defaultBinaryMessenger
        .setMockMethodCallHandler(channel, null));

    await reporter.reportLastNativeCrash();
    await reporter.reportLastNativeCrash();

    final crashes = reporter.queued.where((q) => q['kind'] == 'native_crash').toList();
    expect(crashes, hasLength(1));
    expect(crashes.single['detail'], contains('flutter_windows.dll'));
  });
}
