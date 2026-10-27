import 'dart:async';
import 'dart:convert';

import 'package:ezbizi_client/core/api_client.dart';
import 'package:ezbizi_client/core/exit_hooks.dart';
import 'package:ezbizi_client/core/issue_reporter.dart';
import 'package:flutter/services.dart';
import 'package:flutter/widgets.dart';
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

  test('a background tab coming back is not a freeze; a real stall while in front is', () {
    // 2026-10-16: 175 web "freezes", and they were hidden tabs. Chrome holds a
    // hidden tab's timer for up to a minute; the late tick fired once the tab
    // was visible again and read as a 40 s freeze.
    final t0 = DateTime(2026, 10, 16, 18);
    reporter.checkTick(t0, resumed: true); // baseline

    reporter.noteLifecycle(AppLifecycleState.hidden);
    // The throttled tick lands 45 s later, just after the tab is visible again.
    expect(
      reporter.checkTick(t0.add(const Duration(seconds: 45)), resumed: true),
      isNull,
      reason: 'Time spent away restarts the clock instead of counting.',
    );

    // Back in front, the app really stalls for 4 s: that IS a freeze.
    final back = t0.add(const Duration(seconds: 45));
    expect(
      reporter.checkTick(back.add(const Duration(milliseconds: 4500)), resumed: true),
      const Duration(milliseconds: 4000),
    );
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

  // ── 2026-10-27: web freeze false alarms, missing context, Aborted() ──

  test('a tick that finds the tab hidden or unfocused is not a freeze', () {
    final t0 = DateTime(2026, 10, 27, 9);
    reporter.checkTick(t0, resumed: true);
    // Chrome throttles the hidden tab; the lifecycle still says resumed.
    expect(
      reporter.checkTick(t0.add(const Duration(seconds: 30)), resumed: true, visible: false),
      isNull,
    );
    // The first tick back in front measures from the hidden one, not t0.
    expect(
      reporter.checkTick(t0.add(const Duration(milliseconds: 30500)), resumed: true),
      isNull,
    );
  });

  test('hidden at any point during the interval is not a freeze', () {
    final t0 = DateTime(2026, 10, 27, 9);
    reporter.checkTick(t0, resumed: true);
    reporter.notePageHidden(); // visibilitychange between two ticks
    expect(reporter.checkTick(t0.add(const Duration(seconds: 20)), resumed: true), isNull);
  });

  test('a once-a-minute throttle pattern is not a freeze', () {
    var t = DateTime(2026, 10, 27, 23);
    reporter.checkTick(t, resumed: true);
    final stalls = <Duration?>[];
    for (var i = 0; i < 30; i++) {
      t = t.add(const Duration(seconds: 59, milliseconds: 800));
      stalls.add(reporter.checkTick(t, resumed: true));
    }
    expect(stalls.whereType<Duration>(), isEmpty);

    // Shorter late ticks on a one-minute cadence are the same throttle.
    var u = DateTime(2026, 10, 28, 2);
    reporter.checkTick(u, resumed: true);
    final cadence = <Duration?>[];
    for (var i = 0; i < 10; i++) {
      for (var k = 0; k < 114; k++) {
        u = u.add(const Duration(milliseconds: 500)); // on time for 57 s
        reporter.checkTick(u, resumed: true);
      }
      u = u.add(const Duration(milliseconds: 3500)); // then 3 s late
      cadence.add(reporter.checkTick(u, resumed: true));
    }
    expect(cadence.skip(1).whereType<Duration>(), isEmpty,
        reason: 'A stall a minute after the last one is the throttle, not a freeze.');
  });

  test('a real 3 s freeze while visible is reported', () {
    final t0 = DateTime(2026, 10, 27, 10);
    reporter.checkTick(t0, resumed: true);
    expect(
      reporter.checkTick(t0.add(const Duration(milliseconds: 3500)), resumed: true),
      const Duration(seconds: 3),
    );
  });

  test('every report carries the context, after the frames the server fingerprints', () {
    IssueReporter.module = 'scheduling';
    IssueReporter.route = '/scheduling';
    IssueReporter.breadcrumb('tap');
    reporter.record('dart_error', 'Bad state: No element', detail: 'frame 1');

    final detail = reporter.queued.single['detail']! as String;
    final lines = detail.split('\n');
    expect(lines.take(4).join('\n'), isNot(contains('context:')),
        reason: 'Breadcrumbs vary; inside the first four lines they would stop repeats folding.');
    final context = jsonDecode(lines.last.substring('context: '.length)) as Map<String, dynamic>;
    expect(context['route'], '/scheduling');
    expect(context['uptime_s'], isA<int>());
    expect(context.keys, containsAll(['platform', 'os', 'renderer', 'breadcrumbs']));
    expect(context['renderer'], 'native');
    expect(context['breadcrumbs'], containsAll(['screen scheduling', 'tap scheduling']));
  });

  test('breadcrumbs keep the last ten', () {
    for (var i = 0; i < 15; i++) {
      IssueReporter.module = 'm$i';
    }
    expect(IssueReporter.breadcrumbs, hasLength(10));
    expect(IssueReporter.breadcrumbs.last, 'screen m14');
  });

  test('a CanvasKit Aborted() is reported once and asks for a reload', () {
    IssueReporter.pageBroken.value = false;
    for (var i = 0; i < 5; i++) {
      reporter.record('dart_error', 'RuntimeError: Aborted(). Build with -sASSERTIONS for more info.');
    }
    expect(reporter.queued.where((q) => '${q['message']}'.contains('Aborted()')), hasLength(1));
    expect(IssueReporter.pageBroken.value, isTrue);
  });

  testWidgets('the reload banner shows once the page is broken', (tester) async {
    IssueReporter.pageBroken.value = false;
    await tester.pumpWidget(const Directionality(
      textDirection: TextDirection.ltr,
      child: PageProblemBanner(child: SizedBox()),
    ));
    expect(find.textContaining('needs to reload'), findsNothing);

    IssueReporter.pageBroken.value = true;
    await tester.pump();
    expect(find.text('The page hit a problem and needs to reload'), findsOneWidget);
    expect(find.text('Reload'), findsOneWidget);
    IssueReporter.pageBroken.value = false;
  });

  test('a crash after shutdown began is reported as a crash while closing', () async {
    const channel = MethodChannel('com.ezbizi.app/watchdog');
    var marker = 'during shutdown: exception 0xC0000005 at 0x7FF6 in flutter_windows.dll (offset 0x14BBD)';
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

    final crashes = reporter.queued.where((q) => q['kind'] == 'native_crash').toList();
    expect(crashes, hasLength(1));
    expect(crashes.single['message'], 'The app crashed while closing last time');
  });

  test('exit hooks all run, once, even when one throws or never finishes', () async {
    var ran = 0;
    ExitHooks.add(() async => ran++);
    ExitHooks.add(() async => throw StateError('gone'));
    ExitHooks.add(() => Completer<void>().future);
    final removed = ExitHooks.add(() async => ran += 100);
    ExitHooks.remove(removed);

    await ExitHooks.runAll(each: const Duration(milliseconds: 50));
    await ExitHooks.runAll(each: const Duration(milliseconds: 50));

    expect(ran, 1);
  });
}
