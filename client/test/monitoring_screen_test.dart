import 'dart:convert';

import 'package:ezbizi_client/core/api_client.dart';
import 'package:ezbizi_client/core/module_screens.dart';
import 'package:ezbizi_client/core/session.dart';
import 'package:ezbizi_client/core/theme.dart';
import 'package:ezbizi_client/screens/monitoring/camera_monitoring_tab.dart';
import 'package:ezbizi_client/screens/monitoring/general_tab.dart';
import 'package:ezbizi_client/screens/monitoring/monitoring_screen.dart';
import 'package:ezbizi_client/screens/monitoring/pc_specs_tab.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';

/// PC Specs — the roster (people, each expandable inline to their devices)
/// plus the at-a-glance panel. The load-bearing assertion is the second test:
/// a device that declared no `specs` capability must say so in an explicit
/// sentence, never render a blank row and never render specs it does not
/// have — see PcSpecsTab.webOnlyNote and DevicesController::present, which
/// withholds the `specs` KEY entirely rather than sending it null.
///
/// §3 (sibling-company leak): NOT re-tested here. That guard is server-side —
/// DevicesController::index scopes by ScopeReach::constrainToVisiblePeople(),
/// already proven in tests/Feature/Monitoring/DevicesReadTest.php. This tab
/// has no client-side scope control of its own (no company/franchise picker
/// iterating `me.companies`, unlike Inventory's "New location" form) — it
/// only renders whatever list the server already scoped. A client §3 test
/// here would just be re-asserting "the fake server returned what the fake
/// server returned", which is the vacuous test the brief says to skip rather
/// than write.
void main() {
  testWidgets(
    'the roster renders a person from the devices response, and the glance '
    'panel shows an online count',
    (tester) async {
      await _pumpScreen(tester, devices: [_agentDevice, _webDevice]);

      expect(find.text('Marco Reyes'), findsOneWidget);
      expect(find.text('Priya Nair'), findsOneWidget);
      expect(find.textContaining('people online'), findsOneWidget);
      expect(find.text('2 / 2 people online'), findsOneWidget);
    },
  );

  testWidgets(
    'a web-only device renders the exact no-specs sentence, never specs and '
    'never a blank row',
    (tester) async {
      await _pumpScreen(tester, devices: [_agentDevice, _webDevice]);

      final webRow = find.byKey(const ValueKey('monitoring-device-dev-web'));
      expect(webRow, findsOneWidget);

      expect(
        find.descendant(of: webRow, matching: find.textContaining('no specs')),
        findsOneWidget,
        reason:
            'A device that declared no specs capability rendered no '
            'explicit sentence — the row would read as blank, which is the '
            'bug this tab exists to not have.',
      );

      // Non-vacuous: this is exactly what would go red if the tab rendered
      // specs (or nothing) for a device that cannot report them.
      expect(
        find.descendant(of: webRow, matching: find.textContaining('RAM')),
        findsNothing,
        reason:
            'A web session has no specs capability, so it must not show '
            'a hardware value that only an agent device can report.',
      );

      expect(
        find.text(PcSpecsTab.webOnlyNote),
        findsOneWidget,
        reason: 'The sentence shown is not the exact one the layout chose.',
      );
    },
  );

  testWidgets('an agent device with specs renders a hardware value', (
    tester,
  ) async {
    await _pumpScreen(tester, devices: [_agentDevice, _webDevice]);

    final agentRow = find.byKey(const ValueKey('monitoring-device-dev-agent'));
    expect(agentRow, findsOneWidget);

    expect(
      find.descendant(of: agentRow, matching: find.textContaining('16 GB RAM')),
      findsOneWidget,
    );
  });

  testWidgets(
    'a capable device with the specs key withheld says so by permission, '
    'not by capability',
    (tester) async {
      // capabilities include `specs`, but the `specs` KEY itself is absent —
      // exactly what DevicesController::present sends when the caller lacks
      // `monitoring.specs.view`. `device['specs']` and a genuinely-null value
      // are indistinguishable through plain map access, which is why the
      // withheld branch has to test `containsKey` rather than nullness.
      final withheld = Map<String, dynamic>.from(_agentDevice)..remove('specs');

      await _pumpScreen(tester, devices: [withheld]);

      final row = find.byKey(const ValueKey('monitoring-device-dev-agent'));
      expect(
        find.descendant(
          of: row,
          matching: find.text(PcSpecsTab.specsWithheldNote),
        ),
        findsOneWidget,
      );
      expect(
        find.descendant(of: row, matching: find.textContaining('RAM')),
        findsNothing,
      );
    },
  );

  testWidgets('a failed load is the request failed, not an empty roster', (
    tester,
  ) async {
    await _pumpScreen(
      tester,
      onRequest: (request) {
        if (request.url.path.endsWith('/monitoring/devices')) {
          return _json({'message': 'the request failed'}, 500);
        }
        return null;
      },
    );

    expect(find.text('the request failed'), findsOneWidget);
    expect(find.text('Try again'), findsOneWidget);
    expect(find.text('Marco Reyes'), findsNothing);
  });

  testWidgets(
    'the roster shows a relative seen-time, never the raw ISO stamp',
    (tester) async {
      final threeMinutesAgo = DateTime.now()
          .toUtc()
          .subtract(const Duration(minutes: 3))
          .toIso8601String();
      final device = {..._agentDevice, 'last_seen_at': threeMinutesAgo};

      await _pumpScreen(tester, devices: [device]);

      final row = find.byKey(const ValueKey('monitoring-device-dev-agent'));

      // Non-vacuous: this is exactly what the old code rendered — the raw
      // ISO-8601 string handed straight to Text('seen $seen').
      expect(
        find.descendant(
          of: row,
          matching: find.textContaining(threeMinutesAgo),
        ),
        findsNothing,
        reason:
            'The roster rendered the raw ISO-8601 timestamp instead of '
            'a human relative time.',
      );

      expect(
        find.descendant(of: row, matching: find.text('seen 3m ago')),
        findsOneWidget,
        reason:
            'A device seen 3 minutes ago should read "seen 3m ago", not '
            'the raw stamp and not a different bucket.',
      );
    },
  );

  testWidgets(
    'the roster shows the human module label, never the raw module key',
    (tester) async {
      await _pumpScreen(tester, devices: [_agentDevice, _webDevice]);

      // _agentDevice's current_module is 'jobs', which the fixture's /me
      // does not declare — the fallback path (title-cased key) exercises
      // the same guarantee: no raw snake_case key ever reaches the screen.
      expect(
        find.text('on jobs'),
        findsNothing,
        reason:
            'The roster rendered the raw module key instead of a human '
            'label.',
      );
      expect(find.textContaining('on Jobs'), findsOneWidget);
    },
  );

  testWidgets(
    'a module key present in the session catalogue renders its server label',
    (tester) async {
      await _pumpScreen(
        tester,
        devices: [_agentDevice, _webDevice],
        meModules: [
          {
            'key': 'monitoring',
            'label': 'Monitoring',
            'available': true,
            'reachable': true,
          },
          {
            'key': 'jobs',
            'label': 'Work Orders',
            'available': true,
            'reachable': true,
          },
        ],
      );

      expect(find.text('on jobs'), findsNothing);
      expect(find.textContaining('on Work Orders'), findsOneWidget);
    },
  );

  testWidgets(
    'the sub-tab strip shows PC Specs active, and Camera Monitoring plus '
    'Desktop Monitoring locked as soon — neither grant is in the default '
    'fixture',
    (tester) async {
      await _pumpScreen(tester, devices: [_agentDevice, _webDevice]);

      expect(find.text('PC Specs'), findsOneWidget);
      expect(find.text('Camera Monitoring'), findsOneWidget);
      expect(find.text('Desktop Monitoring'), findsOneWidget);
      expect(find.text('Soon'), findsNWidgets(2));
    },
  );

  testWidgets(
    'the General sub-tab is present, first in the strip, and selectable on '
    'the module grant — a viewer with monitoring.view can open it',
    (tester) async {
      // The default fixture holds monitoring.view (the module grant), so the
      // General chip is live rather than the locked "Soon" fallback.
      await _pumpScreen(tester, devices: [_agentDevice, _webDevice]);

      expect(find.text('General'), findsOneWidget);

      // First in the strip: leftmost chip on the (single) row at 1280px.
      final generalX = tester.getCenter(find.text('General')).dx;
      final pcSpecsX = tester.getCenter(find.text('PC Specs')).dx;
      expect(
        generalX,
        lessThan(pcSpecsX),
        reason: 'General must be the FIRST sub-tab, left of PC Specs.',
      );

      // Selectable, not a locked "Soon" chip — switching to it mounts the real
      // GeneralTab, which lands on its own empty state (floor-plans stubbed
      // empty), not a no-permission note.
      await tester.tap(find.text('General'));
      await tester.pumpAndSettle();

      expect(
        find.text(GeneralTab.noPlansNote),
        findsOneWidget,
        reason: 'A viewer with monitoring.view switched to General and should '
            'see the real tab (its empty state), proving the chip is gated on '
            'the module grant they hold, not locked behind "Soon".',
      );
      expect(find.text(GeneralTab.noPermissionNote), findsNothing);
    },
  );

  testWidgets(
    'with monitoring.cameras.view granted, the Camera Monitoring sub-tab is '
    'selectable and no longer locked behind "Soon"',
    (tester) async {
      await _pumpScreen(
        tester,
        devices: [_agentDevice, _webDevice],
        permissions: [
          'monitoring.view',
          'monitoring.specs.view',
          'monitoring.cameras.view',
        ],
      );

      expect(find.text('Camera Monitoring'), findsOneWidget);
      // Only Desktop Monitoring remains locked — its own grant is still
      // absent from this fixture.
      expect(find.text('Soon'), findsOneWidget);

      await tester.tap(find.text('Camera Monitoring'));
      await tester.pumpAndSettle();

      expect(
        find.text(CameraMonitoringTab.noPermissionNote),
        findsNothing,
        reason: 'A viewer holding monitoring.cameras.view switched to the '
            'tab and should see the real tab, not the locked/no-permission '
            'note the chip used to fall back to for everyone.',
      );
      expect(
        find.text(CameraMonitoringTab.noCamerasNote),
        findsOneWidget,
        reason: 'Non-vacuous check that the real CameraMonitoringTab '
            'actually mounted and fetched — this is its own empty-state '
            'sentence, not just the absence of the no-permission one.',
      );
    },
  );

  testWidgets(
    'at 375px the glance panel stacks below the roster, no overflow',
    (tester) async {
      await _pumpScreen(
        tester,
        devices: [_agentDevice, _webDevice],
        size: const Size(375, 800),
      );

      expect(tester.takeException(), isNull);
      expect(find.text('Marco Reyes'), findsOneWidget);
      expect(find.textContaining('people online'), findsOneWidget);

      final rosterCenter = tester.getCenter(find.text('Marco Reyes'));
      final panelCenter = tester.getCenter(
        find.textContaining('people online'),
      );
      expect(
        panelCenter.dy,
        greaterThan(rosterCenter.dy),
        reason:
            'Below 720px the panel is meant to drop under the roster, '
            'not sit beside it.',
      );
    },
  );

  test('monitoring is registered in module_screens.dart', () {
    expect(moduleScreens['monitoring'], isNotNull);
  });

  group('friendlyDeviceName rule', () {
    testWidgets(
      'a device with a null label on the web platform renders "Browser", '
      'never "web web"',
      (tester) async {
        final noLabel = Map<String, dynamic>.from(_webDevice)
          ..['id'] = 'dev-web-nolabel'
          ..['label'] = null;

        await _pumpScreen(tester, devices: [noLabel]);

        final row = find.byKey(const ValueKey('monitoring-device-dev-web-nolabel'));
        expect(row, findsOneWidget);

        expect(
          find.descendant(of: row, matching: find.text('Browser')),
          findsOneWidget,
          reason: 'A web device with no real label should read "Browser", '
              'not the raw platform string.',
        );
        expect(
          find.descendant(of: row, matching: find.text('web')),
          findsNothing,
          reason: 'The old fallback (label ?? platform) rendered the bare '
              'platform string as the name — that bug is exactly what '
              '"Browser" replaces.',
        );
      },
    );
  });

  group('switcher defaults to the desktop agent', () {
    testWidgets(
      'an agent device is selected by default in the switcher, even when a '
      'browser session for the same person arrives first from the server',
      (tester) async {
        final browserFirst = {
          ..._webDevice,
          'id': 'dev-web-active',
          'user': {'id': 'u-marco', 'name': 'Marco Reyes'},
        };
        final agentSecond = {..._agentDevice, 'id': 'dev-agent-active'};

        await _pumpScreen(tester, devices: [browserFirst, agentSecond]);

        expect(
          find.byKey(const ValueKey('monitoring-device-dev-agent-active')),
          findsOneWidget,
          reason: 'The agent device should be the default selection in the '
              'switcher, not whichever session happened to arrive first '
              'from the server.',
        );
        expect(
          find.byKey(const ValueKey('monitoring-device-dev-web-active')),
          findsNothing,
        );
      },
    );
  });

  group('the glance "Desktop app" panel', () {
    testWidgets(
      'collapses to one line per person and drops an agent last seen more '
      'than 24h ago',
      (tester) async {
        final recentAgent = {
          ..._agentDevice,
          'id': 'dev-agent-recent',
          'last_seen_at': DateTime.now()
              .toUtc()
              .subtract(const Duration(hours: 1))
              .toIso8601String(),
        };
        final olderAgentSamePerson = {
          ..._agentDevice,
          'id': 'dev-agent-second',
          'label': 'Second desktop',
          'last_seen_at': DateTime.now()
              .toUtc()
              .subtract(const Duration(hours: 5))
              .toIso8601String(),
        };
        final staleAgentOtherPerson = {
          ..._agentDevice,
          'id': 'dev-agent-stale',
          'user': {'id': 'u-dana', 'name': 'Dana Cole'},
          'last_seen_at': DateTime.now()
              .toUtc()
              .subtract(const Duration(hours: 30))
              .toIso8601String(),
        };

        await _pumpScreen(
          tester,
          devices: [recentAgent, olderAgentSamePerson, staleAgentOtherPerson],
        );

        expect(find.text('Devices with agent'), findsNothing);
        expect(find.text('Desktop app'), findsOneWidget);

        final panel = find.ancestor(
          of: find.text('Desktop app'),
          matching: find.byType(Card),
        );
        expect(panel, findsOneWidget);

        expect(
          find.descendant(of: panel, matching: find.textContaining('Marco Reyes')),
          findsOneWidget,
          reason: 'Marco has two agent devices seen within the last 24h; '
              'the panel should collapse them into a single line, not one '
              'row per device.',
        );
        expect(
          find.descendant(of: panel, matching: find.textContaining('Shop desktop')),
          findsOneWidget,
          reason: 'Marco\'s line should name his most-recently-seen agent.',
        );
        expect(
          find.descendant(of: panel, matching: find.textContaining('Second desktop')),
          findsNothing,
          reason: 'The older of Marco\'s two agents should not get its own '
              'line.',
        );
        expect(
          find.descendant(of: panel, matching: find.textContaining('Dana Cole')),
          findsNothing,
          reason: "Dana's only agent device was last seen 30 hours ago and "
              'should have dropped out of this panel entirely.',
        );
      },
    );
  });

  group('active/stale device grouping', () {
    // Owner's own words: 6 devices listed as equals — one web, three
    // windows, two ios browser sessions, most of them stale — "it should
    // only show the active one, or if there are multiple active ones add
    // icons that switch between them". These cases cover the three shapes
    // that report produced: exactly one active device, more than one active
    // device, and nobody active at all.

    testWidgets(
      'one active device shows, stale devices stay hidden until the toggle '
      'is tapped, and the subtitle reflects the split',
      (tester) async {
        final staleDevice = {
          ..._agentDevice,
          'id': 'dev-agent-old',
          'label': 'Old laptop',
          'last_seen_at': DateTime.now()
              .toUtc()
              .subtract(const Duration(minutes: 30))
              .toIso8601String(),
        };

        await _pumpScreen(tester, devices: [_agentDevice, staleDevice]);

        // The active device renders immediately.
        expect(
          find.byKey(const ValueKey('monitoring-device-dev-agent')),
          findsOneWidget,
        );

        // The stale one does not — it is folded behind the toggle, never
        // shown as an equal row on first render.
        expect(
          find.byKey(const ValueKey('monitoring-device-dev-agent-old')),
          findsNothing,
          reason:
              'A stale session rendered as an equal row instead of staying '
              'collapsed behind the toggle.',
        );

        expect(find.text('1 active · 1 earlier'), findsOneWidget);

        final toggle = find.text('Show 1 earlier session');
        expect(toggle, findsOneWidget);

        await tester.tap(toggle);
        await tester.pumpAndSettle();

        expect(
          find.byKey(const ValueKey('monitoring-device-dev-agent-old')),
          findsOneWidget,
          reason: 'Tapping the toggle should reveal the stale device row.',
        );
      },
    );

    testWidgets(
      'more than one active device shows a chip switcher; selecting a chip '
      'swaps which device row is shown',
      (tester) async {
        // Both share _agentDevice's sentinel last_seen_at, which _pumpScreen
        // rewrites to "now" — so both land in the active group at once,
        // exactly the ">1 active" shape the switcher exists for.
        final secondActive = {
          ..._agentDevice,
          'id': 'dev-agent-phone',
          'label': 'iPhone Safari',
          'platform': 'ios',
          'capabilities': ['activity'],
        };

        await _pumpScreen(tester, devices: [_agentDevice, secondActive]);

        // Only the first (selected-by-default) device row shows at once —
        // never both active rows stacked together.
        expect(
          find.byKey(const ValueKey('monitoring-device-dev-agent')),
          findsOneWidget,
        );
        expect(
          find.byKey(const ValueKey('monitoring-device-dev-agent-phone')),
          findsNothing,
        );

        final secondChip = find.text('IOS iPhone Safari');
        expect(secondChip, findsOneWidget);

        await tester.tap(secondChip);
        await tester.pumpAndSettle();

        // Selecting the other chip swaps which row is shown — never both at
        // once.
        expect(
          find.byKey(const ValueKey('monitoring-device-dev-agent-phone')),
          findsOneWidget,
        );
        expect(
          find.byKey(const ValueKey('monitoring-device-dev-agent')),
          findsNothing,
          reason:
              'Selecting a different chip should swap the shown device row, '
              'not add to it.',
        );
      },
    );

    testWidgets(
      'a fully-offline person shows their most-recently-seen device, never '
      'an empty tile',
      (tester) async {
        final recent = {
          ..._agentDevice,
          'id': 'dev-recent',
          'last_seen_at': DateTime.now()
              .toUtc()
              .subtract(const Duration(minutes: 10))
              .toIso8601String(),
        };
        final older = {
          ..._agentDevice,
          'id': 'dev-older',
          'label': 'Old laptop',
          'last_seen_at': DateTime.now()
              .toUtc()
              .subtract(const Duration(hours: 4))
              .toIso8601String(),
        };

        await _pumpScreen(tester, devices: [recent, older]);

        expect(
          find.byKey(const ValueKey('monitoring-device-dev-recent')),
          findsOneWidget,
          reason:
              'Nobody active should still show the most-recently-seen '
              'device, not an empty tile.',
        );
        expect(
          find.byKey(const ValueKey('monitoring-device-dev-older')),
          findsNothing,
        );
        expect(find.text('offline · 1 earlier'), findsOneWidget);
      },
    );
  });
}

const _agentDevice = {
  'id': 'dev-agent',
  'user': {'id': 'u-marco', 'name': 'Marco Reyes'},
  'device_key': 'key-agent',
  'label': 'Shop desktop',
  'platform': 'windows',
  'capabilities': ['activity', 'specs'],
  'user_agent': 'EzBiziAgent/1.0',
  'ip_address': '10.0.0.4',
  'last_seen_at': '2026-08-28T20:00:00Z',
  'current_module': 'jobs',
  'specs': {
    'ram_gb': 16,
    'disk_free_gb': 119,
    'os_version': 'Win 11 26100',
    'wifi_ssid': 'A1-SHOP',
    'wifi_signal_dbm': -48,
    'open_apps': ['EzBizi', 'Chrome', 'Outlook'],
  },
};

const _webDevice = {
  'id': 'dev-web',
  'user': {'id': 'u-priya', 'name': 'Priya Nair'},
  'device_key': 'key-web',
  'label': 'Chrome · Windows',
  'platform': 'web',
  'capabilities': ['activity'],
  'user_agent': 'Mozilla/5.0',
  'ip_address': '10.0.0.9',
  'last_seen_at': '2026-08-28T20:00:00Z',
  'current_module': 'customers',
};

Future<void> _pumpScreen(
  WidgetTester tester, {
  List<Map<String, dynamic>> devices = const [],
  Size size = const Size(1280, 900),
  http.Response? Function(http.Request request)? onRequest,
  List<Map<String, Object?>>? meModules,
  List<String>? permissions,
}) async {
  tester.view.physicalSize = size;
  tester.view.devicePixelRatio = 1.0;
  addTearDown(tester.view.reset);

  final now = DateTime.now().toUtc().toIso8601String();
  final live = [
    for (final device in devices)
      if (device['last_seen_at'] == '2026-08-28T20:00:00Z')
        {...device, 'last_seen_at': now}
      else
        device,
  ];

  final me = {
    ..._me,
    'modules': ?meModules,
    'permissions': ?permissions,
  };

  final api = ApiClient(
    client: MockClient((request) async {
      final override = onRequest?.call(request);
      if (override != null) return override;

      final path = request.url.path;

      if (path.endsWith('/me')) {
        return _json({'data': me});
      }

      if (path.endsWith('/monitoring/devices')) {
        return _json({'data': live});
      }

      // The General sub-tab lists floor plans on open — stubbed empty so the
      // one test that switches to it lands on the honest empty state rather
      // than an error.
      if (path.endsWith('/monitoring/floor-plans')) {
        return _json({'data': <Map<String, dynamic>>[]});
      }

      // Only ever reached by the Camera Monitoring grant test below — no
      // other test here switches to that sub-tab.
      if (path.endsWith('/monitoring/cameras')) {
        return _json({'data': <Map<String, dynamic>>[]});
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
        body: MonitoringScreen(api: api, session: session),
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
  'permissions': <String>['monitoring.view', 'monitoring.specs.view'],
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
