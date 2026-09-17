import 'dart:convert';

import 'package:ezbizi_client/core/api_client.dart';
import 'package:ezbizi_client/core/session.dart';
import 'package:ezbizi_client/core/theme.dart';
import 'package:ezbizi_client/screens/scheduling_screen.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';

/// Scheduling's tabs are granted one at a time (Jose, 2026-09-17).
///
/// Nothing pumped this screen before today — the capacity tests build
/// `CapacitySettings` directly, so the tab bar itself had no coverage at all.
/// That is the half where a permission filter lives.
void main() {
  testWidgets('a dispatcher with Availability sees it and not Settings', (
    tester,
  ) async {
    await _pump(tester, const [
      'scheduling.view',
      'scheduling.availability.view',
    ]);

    expect(find.text('Availability'), findsOneWidget);
    expect(find.text('Settings'), findsNothing);
    expect(find.text('Schedule'), findsNothing);
  });

  /// The index trap, and the reason `_visible` compares a KEY.
  ///
  /// With Availability hidden, Settings is index 0. A visibility check written
  /// as `_tab == 2` would tell Settings it was off-screen while it was the only
  /// tab drawn — it would render and never load, which looks like an empty
  /// screen rather than a permission.
  testWidgets('a manager with Settings alone gets Settings, loaded, at index 0', (
    tester,
  ) async {
    var askedForAreas = false;

    await _pump(
      tester,
      const ['scheduling.view', 'scheduling.settings.view'],
      onRequest: (request) {
        if (request.url.path.endsWith('/scheduling/areas')) {
          askedForAreas = true;
        }
        return null;
      },
    );

    expect(find.text('Settings'), findsOneWidget);
    expect(find.text('Availability'), findsNothing);

    // The pane it drew is the one it thinks is visible: it loaded.
    expect(
      askedForAreas,
      isTrue,
      reason: 'Settings at index 0 must know it is the visible tab.',
    );
  });

  testWidgets('every tab granted draws all three', (tester) async {
    await _pump(tester, const [
      'scheduling.view',
      'scheduling.availability.view',
      'scheduling.schedule.view',
      'scheduling.settings.view',
    ]);

    expect(find.text('Availability'), findsOneWidget);
    expect(find.text('Schedule'), findsOneWidget);
    expect(find.text('Settings'), findsOneWidget);
  });

  /// Reachable: the rail draws Scheduling for anybody holding the module, and
  /// the tab grants are separate from it.
  testWidgets('the module without any tab says so rather than drawing blank', (
    tester,
  ) async {
    await _pump(tester, const ['scheduling.view']);

    expect(find.text('Availability'), findsNothing);
    expect(find.text('Settings'), findsNothing);
    expect(find.textContaining('do not have access'), findsOneWidget);
  });
}

Future<void> _pump(
  WidgetTester tester,
  List<String> permissions, {
  http.Response? Function(http.Request request)? onRequest,
}) async {
  tester.view.physicalSize = const Size(1280, 900);
  tester.view.devicePixelRatio = 1.0;
  addTearDown(tester.view.reset);

  final api = ApiClient(
    client: MockClient((request) async {
      final override = onRequest?.call(request);
      if (override != null) return override;

      final path = request.url.path;

      if (path.endsWith('/me')) {
        return _json({
          'data': {..._me, 'permissions': permissions},
        });
      }

      if (path.endsWith('/scheduling/areas')) {
        return _json({'data': const [], 'pattern': null});
      }

      return _json({'data': const [], 'pattern': null});
    }),
  );

  final session = Session(api);
  await tester.runAsync(() => session.adopt('tok'));

  await tester.pumpWidget(
    MaterialApp(
      theme: EzBiziTheme.light(),
      home: Scaffold(
        body: SchedulingScreen(api: api, session: session),
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
  'preferences': <String, dynamic>{},
  'organization': {
    'id': 'org-1',
    'name': 'Everdoors',
    'franchise_label': 'Franchise',
    'is_read_only': false,
  },
  'scope': {
    'type': 'organization',
    'id': 'org-1',
    'label': 'Everdoors',
    'path': ['Everdoors'],
  },
  'companies': [
    {'id': 'co-1', 'name': 'Everdoors', 'parent_company_id': null},
  ],
  'franchises': [
    {'id': 'fr-1', 'name': 'Connecticut', 'company_id': 'co-1'},
  ],
};
