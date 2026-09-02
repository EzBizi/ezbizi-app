import 'dart:async';
import 'dart:convert';

import 'package:ezbizi_client/core/api_client.dart';
import 'package:ezbizi_client/core/session.dart';
import 'package:ezbizi_client/core/theme.dart';
import 'package:ezbizi_client/screens/monitoring/desktop_monitoring_tab.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';

/// Desktop Monitoring — the hero + filmstrip viewer (docs/previews/
/// monitoring-screenshots-layouts.html, "Layout 2").
///
/// The load-bearing assertion is the honesty one (test 3): a device whose
/// capture stream has gone quiet must say "capture stopped N ago" rather
/// than showing the last frame it has as though it were current — same
/// guarantee the reachability column and PcSpecsTab.webOnlyNote already
/// hold elsewhere. It is proven non-vacuous by pumping the FRESH fixture
/// first and asserting the phrase is absent there, then pumping the stale
/// one and asserting it appears — so a build that always said "capture
/// stopped" (or never did) would fail one half or the other.
void main() {
  testWidgets(
    'the hero renders the latest capture with its own timestamp and age',
    (tester) async {
      await _pumpTab(tester, devices: [_capableDevice], shots: _freshShots);

      final hero = find.byKey(const Key('desktop-monitoring-hero'));
      expect(hero, findsOneWidget);

      expect(
        find.descendant(of: hero, matching: find.textContaining('2m ago')),
        findsOneWidget,
        reason: 'The hero should carry the newest capture (2 minutes old) '
            'and its age, not a bare frame with nothing said about when it '
            'was taken.',
      );
    },
  );

  testWidgets('the filmstrip shows the recent captures below the hero', (
    tester,
  ) async {
    await _pumpTab(tester, devices: [_capableDevice], shots: _freshShots);

    expect(find.byKey(const Key('desktop-monitoring-filmstrip')), findsOneWidget);

    for (final shot in _freshShots) {
      expect(
        find.byKey(ValueKey('desktop-monitoring-thumb-${shot['id']}')),
        findsOneWidget,
        reason: 'Every capture the server returned should have a thumbnail '
            'in the filmstrip.',
      );
    }
  });

  testWidgets('tapping a filmstrip thumbnail makes it the hero', (
    tester,
  ) async {
    await _pumpTab(tester, devices: [_capableDevice], shots: _freshShots);

    final hero = find.byKey(const Key('desktop-monitoring-hero'));
    expect(
      find.descendant(of: hero, matching: find.textContaining('2m ago')),
      findsOneWidget,
      reason: 'Before tapping anything, the newest capture is the hero.',
    );

    await tester.tap(find.byKey(const Key('desktop-monitoring-thumb-shot-3')));
    await tester.pumpAndSettle();

    expect(
      find.descendant(of: hero, matching: find.textContaining('22m ago')),
      findsOneWidget,
      reason: 'Tapping an older thumbnail should swap the hero to that '
          'frame — its own, older age should now be what the hero shows.',
    );
  });

  testWidgets(
    'switching devices while the first one is still loading does not let '
    'its late response overwrite the newer selection',
    (tester) async {
      tester.view.physicalSize = const Size(1280, 900);
      tester.view.devicePixelRatio = 1.0;
      addTearDown(tester.view.reset);

      // Two GETs in flight have no ordering guarantee — this is what proves
      // the tab does not assume one. Device A's `/monitoring/screenshots`
      // response is held open on a Completer; device B's is completed
      // first; A's is resolved LAST, after B's data is already on screen.
      // Without the generation guard in `_loadShots`, A's late answer would
      // land last and silently replace B's shots/hero — one device's
      // screenshots shown under the other device's name in the picker.
      final pending = <String, Completer<http.Response>>{
        'dev-a': Completer<http.Response>(),
        'dev-b': Completer<http.Response>(),
      };

      final api = ApiClient(
        client: MockClient((request) async {
          final path = request.url.path;

          if (path.endsWith('/me')) {
            return _json({
              'data': {
                ..._me,
                'permissions': ['monitoring.view', 'monitoring.screenshots.view'],
              },
            });
          }

          if (path.endsWith('/monitoring/devices')) {
            return _json({'data': [_raceDeviceA, _raceDeviceB]});
          }

          if (path.endsWith('/monitoring/screenshots')) {
            final deviceId = request.url.queryParameters['device_id'];
            return pending[deviceId]!.future;
          }

          if (path.contains('/monitoring/screenshots/')) {
            return http.Response.bytes(
              _onePixelPng,
              200,
              headers: const {'content-type': 'image/jpeg'},
            );
          }

          return _json({'message': 'Nothing stubbed for $path.'}, 500);
        }),
      );

      final session = Session(api);
      await tester.runAsync(() => session.adopt('tok'));
      expect(session.signedIn, isTrue);

      await tester.pumpWidget(
        MaterialApp(
          theme: EzBiziTheme.light(),
          home: Scaffold(body: DesktopMonitoringTab(api: api, session: session)),
        ),
      );
      // Drives initState's _loadDevices -> _loadShots('dev-a') up to its
      // now-pending GET. Deliberately bounded `pump()` calls rather than
      // `pumpAndSettle()`: the loading state renders an INDETERMINATE
      // CircularProgressIndicator, whose animation never stops on its own
      // while A's Completer is unresolved — `pumpAndSettle` would spin
      // until it times out rather than recognising "nothing more to do".
      await _advance(tester);

      // Switch to device B (person Bob Race) before A has answered — a
      // direct tap on the person's row in the picker, never a dropdown.
      // Same reasoning as above: B's own fetch starts (and shows the same
      // indeterminate spinner) while ITS Completer is unresolved, so this
      // stays on bounded pumps too.
      await tester.tap(find.text('Bob Race'));
      await _advance(tester);

      // B answers first.
      pending['dev-b']!.complete(_json({'data': _raceShotsB}));
      await tester.pumpAndSettle();

      expect(
        find.byKey(const ValueKey('desktop-monitoring-thumb-shot-b1')),
        findsOneWidget,
        reason: "B's own capture should be showing once B's response lands.",
      );

      // A's late response finally arrives — it must be ignored.
      pending['dev-a']!.complete(_json({'data': _raceShotsA}));
      await tester.pumpAndSettle();

      expect(
        find.byKey(const ValueKey('desktop-monitoring-thumb-shot-b1')),
        findsOneWidget,
        reason: "A's late answer must not displace what B already applied.",
      );
      expect(
        find.byKey(const ValueKey('desktop-monitoring-thumb-shot-a1')),
        findsNothing,
        reason: 'A stale response landing after a newer selection must be '
            "dropped, not shown under B's name in the picker — that is "
            'exactly the wrong-attribution the honesty rule exists to '
            'prevent, triggered by a race instead of elapsed time.',
      );
    },
  );

  testWidgets(
    "selecting a different person loads that person's own captures",
    (tester) async {
      tester.view.physicalSize = const Size(1280, 900);
      tester.view.devicePixelRatio = 1.0;
      addTearDown(tester.view.reset);

      final api = ApiClient(
        client: MockClient((request) async {
          final path = request.url.path;

          if (path.endsWith('/me')) {
            return _json({
              'data': {
                ..._me,
                'permissions': ['monitoring.view', 'monitoring.screenshots.view'],
              },
            });
          }

          if (path.endsWith('/monitoring/devices')) {
            return _json({'data': [_raceDeviceA, _raceDeviceB]});
          }

          if (path.endsWith('/monitoring/screenshots')) {
            final deviceId = request.url.queryParameters['device_id'];
            final shots = deviceId == 'dev-b' ? _raceShotsB : _raceShotsA;
            return _json({'data': shots});
          }

          if (path.contains('/monitoring/screenshots/')) {
            return http.Response.bytes(
              _onePixelPng,
              200,
              headers: const {'content-type': 'image/jpeg'},
            );
          }

          return _json({'message': 'Nothing stubbed for $path.'}, 500);
        }),
      );

      final session = Session(api);
      await tester.runAsync(() => session.adopt('tok'));
      expect(session.signedIn, isTrue);

      await tester.pumpWidget(
        MaterialApp(
          theme: EzBiziTheme.light(),
          home: Scaffold(body: DesktopMonitoringTab(api: api, session: session)),
        ),
      );
      await tester.pumpAndSettle();

      expect(
        find.byKey(const ValueKey('desktop-monitoring-thumb-shot-a1')),
        findsOneWidget,
        reason: 'Alice is the default selection (her device arrived first).',
      );

      await tester.tap(find.text('Bob Race'));
      await tester.pumpAndSettle();

      expect(
        find.byKey(const ValueKey('desktop-monitoring-thumb-shot-b1')),
        findsOneWidget,
        reason: "Selecting Bob should load Bob's own captures.",
      );
      expect(
        find.byKey(const ValueKey('desktop-monitoring-thumb-shot-a1')),
        findsNothing,
      );
    },
  );

  // Non-vacuousness of the honesty label is proven across this PAIR of
  // tests, deliberately kept as two separate `testWidgets` blocks rather
  // than one test with two `_pumpTab` calls: `tester.pumpWidget` reuses the
  // same State when the widget tree shape is unchanged (no `initState`
  // re-run without an explicit `Key`), so a second `_pumpTab` in the same
  // test would silently keep reading the FIRST fixture's already-loaded
  // data — exactly the kind of false pass "non-vacuous" is meant to rule
  // out. Two tests sidestep that pitfall entirely: a build that always
  // showed the phrase fails the first; a build that never showed it fails
  // the second.
  testWidgets(
    'a device capturing normally never shows the "capture stopped" wording',
    (tester) async {
      await _pumpTab(tester, devices: [_capableDevice], shots: _freshShots);

      expect(
        find.textContaining('capture stopped'),
        findsNothing,
        reason: 'A device capturing normally must never show the '
            '"capture stopped" wording.',
      );
    },
  );

  testWidgets(
    'a device whose capture has stopped shows "capture stopped N ago" on '
    'the hero itself, never a frame passed off as live',
    (tester) async {
      // Newest capture and last heartbeat are both 40 minutes old — well
      // past 2x the 5 minute capture interval, and past the 5 minute
      // online window.
      await _pumpTab(tester, devices: [_staleDevice], shots: _staleShots);

      expect(
        find.textContaining('capture stopped 40m ago'),
        findsAtLeastNWidgets(1),
        reason: 'A device whose last capture is 40 minutes old (and whose '
            'heartbeat is just as stale) must say the stream has stopped, '
            'labelled with how long ago — never render the old frame as if '
            'it were current.',
      );

      final hero = find.byKey(const Key('desktop-monitoring-hero'));
      expect(
        find.descendant(
          of: hero,
          matching: find.textContaining('capture stopped 40m ago'),
        ),
        findsOneWidget,
        reason: 'The hero itself — not just some header off to the side — '
            'must carry the honesty label, since the hero is the thing '
            'somebody could mistake for a live view.',
      );
    },
  );

  testWidgets(
    'without monitoring.screenshots.view, the tab shows the no-permission '
    'note rather than fetching or crashing',
    (tester) async {
      var deviceFetchAttempted = false;

      await _pumpTab(
        tester,
        devices: [_capableDevice],
        shots: _freshShots,
        grantScreenshotsView: false,
        onDevicesFetch: () => deviceFetchAttempted = true,
      );

      expect(tester.takeException(), isNull);
      expect(find.text(DesktopMonitoringTab.noPermissionNote), findsOneWidget);
      expect(find.byKey(const Key('desktop-monitoring-hero')), findsNothing);
      expect(find.byKey(const Key('desktop-monitoring-filmstrip')), findsNothing);
      expect(
        deviceFetchAttempted,
        isFalse,
        reason: 'A caller who cannot view screenshots at all should never '
            'need a round trip to learn that — the tab already knows from '
            'its own session.',
      );
    },
  );

  testWidgets(
    'when nobody has a capture-capable device, the tab shows the honest '
    'empty state instead of picking a browser session',
    (tester) async {
      await _pumpTab(tester, devices: [_webDevice], shots: const []);

      expect(tester.takeException(), isNull);
      expect(find.text(DesktopMonitoringTab.noDevicesNote), findsOneWidget);
      expect(find.byKey(const Key('desktop-monitoring-hero')), findsNothing);
      expect(find.byKey(const Key('desktop-monitoring-filmstrip')), findsNothing);
      expect(find.byKey(const Key('desktop-monitoring-picker')), findsNothing);
    },
  );

  testWidgets(
    'a browser-only device (no screenshot capability) never appears in the '
    'picker, even mixed in with a real capture device',
    (tester) async {
      await _pumpTab(
        tester,
        devices: [_capableDevice, _webDevice],
        shots: _freshShots,
      );

      expect(find.text('Marco Reyes'), findsOneWidget);
      expect(
        find.text('Priya Nair'),
        findsNothing,
        reason: "Priya's only device is a browser session — it cannot be "
            'screen-captured and must not appear in this picker at all.',
      );
    },
  );

  testWidgets(
    'two people each with a capture device show two entries in the picker',
    (tester) async {
      final second = {
        ..._capableDevice,
        'id': 'dev-2',
        'user': {'id': 'u-2', 'name': 'Priya Nair'},
        'label': 'Priya PC',
      };

      await _pumpTab(
        tester,
        devices: [_capableDevice, second],
        shots: _freshShots,
      );

      expect(find.text('Marco Reyes'), findsOneWidget);
      expect(find.text('Priya Nair'), findsOneWidget);
    },
  );

  testWidgets('the search box filters the person list by name', (tester) async {
    final second = {
      ..._capableDevice,
      'id': 'dev-2',
      'user': {'id': 'u-2', 'name': 'Priya Nair'},
      'label': 'Priya PC',
    };

    await _pumpTab(
      tester,
      devices: [_capableDevice, second],
      shots: _freshShots,
    );

    expect(find.text('Marco Reyes'), findsOneWidget);
    expect(find.text('Priya Nair'), findsOneWidget);

    await tester.enterText(find.byType(TextField), 'Priya');
    await tester.pumpAndSettle();

    expect(find.text('Priya Nair'), findsOneWidget);
    expect(find.text('Marco Reyes'), findsNothing);
  });

  testWidgets(
    'no DropdownButton or any modal picker appears anywhere in this tab',
    (tester) async {
      await _pumpTab(tester, devices: [_capableDevice], shots: _freshShots);

      expect(find.byType(DropdownButtonFormField<String>), findsNothing);
      expect(find.byType(DropdownButton<String>), findsNothing);
    },
  );

  testWidgets(
    'a capable device with zero captures yet shows its own empty state',
    (tester) async {
      await _pumpTab(tester, devices: [_capableDevice], shots: const []);

      expect(tester.takeException(), isNull);
      expect(find.text(DesktopMonitoringTab.noCapturesYetNote), findsOneWidget);
      expect(find.byKey(const Key('desktop-monitoring-hero')), findsNothing);
    },
  );

  testWidgets('at 375px the hero, picker and filmstrip render with no overflow', (
    tester,
  ) async {
    await _pumpTab(
      tester,
      devices: [_capableDevice],
      shots: _freshShots,
      size: const Size(375, 800),
    );

    expect(tester.takeException(), isNull);
    expect(find.byKey(const Key('desktop-monitoring-hero')), findsOneWidget);
    expect(find.byKey(const Key('desktop-monitoring-filmstrip')), findsOneWidget);
    expect(find.byKey(const Key('desktop-monitoring-picker')), findsOneWidget);
  });
}

const _capableDevice = {
  'id': 'dev-1',
  'user': {'id': 'u-1', 'name': 'Marco Reyes'},
  'device_key': 'key-1',
  'label': 'Shop desktop',
  'platform': 'windows',
  'capabilities': ['activity', 'screenshot'],
  'last_seen_at': '__NOW__',
};

const _staleDevice = {
  'id': 'dev-1',
  'user': {'id': 'u-1', 'name': 'Marco Reyes'},
  'device_key': 'key-1',
  'label': 'Shop desktop',
  'platform': 'windows',
  'capabilities': ['activity', 'screenshot'],
  'last_seen_at': '__40M_AGO__',
};

const _webDevice = {
  'id': 'dev-2',
  'user': {'id': 'u-2', 'name': 'Priya Nair'},
  'device_key': 'key-2',
  'label': 'Chrome · Windows',
  'platform': 'web',
  'capabilities': ['activity'],
  'last_seen_at': '__NOW__',
};

// Newest first, matching ScreenshotsController::index's ordering.
const _freshShots = [
  {
    'id': 'shot-1',
    'device_id': 'dev-1',
    'captured_at': '__2M_AGO__',
    'bytes': 98304,
    'width': 1920,
    'height': 1080,
  },
  {
    'id': 'shot-2',
    'device_id': 'dev-1',
    'captured_at': '__7M_AGO__',
    'bytes': 96000,
    'width': 1920,
    'height': 1080,
  },
  {
    'id': 'shot-3',
    'device_id': 'dev-1',
    'captured_at': '__22M_AGO__',
    'bytes': 94000,
    'width': 1920,
    'height': 1080,
  },
];

const _staleShots = [
  {
    'id': 'shot-1',
    'device_id': 'dev-1',
    'captured_at': '__40M_AGO__',
    'bytes': 98304,
    'width': 1920,
    'height': 1080,
  },
];

// Fixtures for the device-switch race test only — already-resolved
// timestamps, since that test is about which device's data wins, not about
// staleness.
final _raceNow = DateTime.now().toUtc().toIso8601String();

final _raceDeviceA = {
  'id': 'dev-a',
  'user': {'id': 'u-a', 'name': 'Alice Race'},
  'device_key': 'key-a',
  'label': 'Desk A',
  'platform': 'windows',
  'capabilities': ['activity', 'screenshot'],
  'last_seen_at': _raceNow,
};

final _raceDeviceB = {
  'id': 'dev-b',
  'user': {'id': 'u-b', 'name': 'Bob Race'},
  'device_key': 'key-b',
  'label': 'Desk B',
  'platform': 'windows',
  'capabilities': ['activity', 'screenshot'],
  'last_seen_at': _raceNow,
};

List<Map<String, dynamic>> get _raceShotsA => [
  {
    'id': 'shot-a1',
    'device_id': 'dev-a',
    'captured_at': _raceNow,
    'bytes': 90000,
    'width': 1920,
    'height': 1080,
  },
];

List<Map<String, dynamic>> get _raceShotsB => [
  {
    'id': 'shot-b1',
    'device_id': 'dev-b',
    'captured_at': _raceNow,
    'bytes': 90000,
    'width': 1920,
    'height': 1080,
  },
];

/// A genuine 1x1 PNG. `Image.memory` decodes bytes for real, so a handful of
/// arbitrary numbers would fail to decode rather than exercise the hero and
/// filmstrip rendering this suite is actually testing.
final _onePixelPng = base64Decode(
  'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmM'
  'IQAAAABJRU5ErkJggg==',
);

Future<void> _pumpTab(
  WidgetTester tester, {
  required List<Map<String, dynamic>> devices,
  required List<Map<String, dynamic>> shots,
  bool grantScreenshotsView = true,
  VoidCallback? onDevicesFetch,
  Size size = const Size(1280, 900),
}) async {
  tester.view.physicalSize = size;
  tester.view.devicePixelRatio = 1.0;
  addTearDown(tester.view.reset);

  final now = DateTime.now().toUtc();
  String stamp(String template) => switch (template) {
    '__NOW__' => now.toIso8601String(),
    '__2M_AGO__' => now.subtract(const Duration(minutes: 2)).toIso8601String(),
    '__7M_AGO__' => now.subtract(const Duration(minutes: 7)).toIso8601String(),
    '__22M_AGO__' =>
      now.subtract(const Duration(minutes: 22)).toIso8601String(),
    '__40M_AGO__' =>
      now.subtract(const Duration(minutes: 40)).toIso8601String(),
    _ => template,
  };

  final liveDevices = [
    for (final d in devices) {...d, 'last_seen_at': stamp(d['last_seen_at'] as String)},
  ];
  final liveShots = [
    for (final s in shots) {...s, 'captured_at': stamp(s['captured_at'] as String)},
  ];

  final permissions = <String>[
    'monitoring.view',
    if (grantScreenshotsView) 'monitoring.screenshots.view',
  ];

  final api = ApiClient(
    client: MockClient((request) async {
      final path = request.url.path;

      if (path.endsWith('/me')) {
        return _json({'data': {..._me, 'permissions': permissions}});
      }

      if (path.endsWith('/monitoring/devices')) {
        onDevicesFetch?.call();
        return _json({'data': liveDevices});
      }

      if (path.endsWith('/monitoring/screenshots')) {
        return _json({'data': liveShots});
      }

      if (path.contains('/monitoring/screenshots/')) {
        return http.Response.bytes(
          _onePixelPng,
          200,
          headers: const {'content-type': 'image/jpeg'},
        );
      }

      return _json({'message': 'Nothing stubbed for $path.'}, 500);
    }),
  );

  final session = Session(api);
  await tester.runAsync(() => session.adopt('tok'));
  expect(session.signedIn, isTrue);

  await tester.pumpWidget(
    MaterialApp(
      theme: EzBiziTheme.light(),
      home: Scaffold(
        body: DesktopMonitoringTab(api: api, session: session),
      ),
    ),
  );
  await tester.pumpAndSettle();
}

http.Response _json(Object body, [int status = 200]) => http.Response(
  jsonEncode(body),
  status,
  headers: const {'content-type': 'application/json'},
);

const _me = {
  'id': 'u-1',
  'name': 'Jose',
  'email': 'jose@example.com',
  'role': {'name': 'Manager'},
  'has_avatar': false,
  'permissions': <String>['monitoring.view'],
  'preferences': <String, dynamic>{},
  'organization': {
    'id': 'org-1',
    'name': 'A-1 Chimney',
    'franchise_label': 'Franchise',
    'is_read_only': false,
  },
  'scope': {
    'type': 'organization',
    'id': 'org-1',
    'label': 'A-1 Chimney',
    'path': ['A-1 Chimney'],
  },
  'companies': [
    {'id': 'co-1', 'name': 'A-1 Chimney', 'parent_company_id': null},
  ],
  'franchises': <Map<String, Object?>>[],
  'modules': [
    {
      'key': 'monitoring',
      'label': 'Monitoring',
      'available': true,
      'reachable': true,
    },
  ],
};

/// Advance a bounded amount of real time without waiting for the tree to
/// fully settle — `pumpAndSettle()` cannot be used anywhere an indeterminate
/// `CircularProgressIndicator` is on screen (its animation never stops on
/// its own, so `pumpAndSettle` spins until it times out rather than
/// recognising "nothing more to do"). Used only by the device-switch race
/// test, which deliberately leaves a fetch's response unresolved.
Future<void> _advance(WidgetTester tester, [int steps = 10]) async {
  for (var i = 0; i < steps; i++) {
    await tester.pump(const Duration(milliseconds: 50));
  }
}
