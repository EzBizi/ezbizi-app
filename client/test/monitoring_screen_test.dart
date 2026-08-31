import 'dart:convert';

import 'package:ezbizi_client/core/api_client.dart';
import 'package:ezbizi_client/core/module_screens.dart';
import 'package:ezbizi_client/core/session.dart';
import 'package:ezbizi_client/core/theme.dart';
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
    'the sub-tab strip shows PC Specs active and the other two as soon',
    (tester) async {
      await _pumpScreen(tester, devices: [_agentDevice, _webDevice]);

      expect(find.text('PC Specs'), findsOneWidget);
      expect(find.text('Camera Monitoring'), findsOneWidget);
      expect(find.text('Desktop Monitoring'), findsOneWidget);
      expect(find.text('Soon'), findsNWidgets(2));
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

  final me = meModules == null ? _me : {..._me, 'modules': meModules};

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
