import 'dart:convert';

import 'package:ezbizi_client/core/api_client.dart';
import 'package:ezbizi_client/core/session.dart';
import 'package:ezbizi_client/core/theme.dart';
import 'package:ezbizi_client/screens/monitoring/floor_plan_summary.dart';
import 'package:ezbizi_client/screens/monitoring/general_tab.dart';
import 'package:ezbizi_client/screens/monitoring/monitoring_screen.dart';
import 'package:ezbizi_client/screens/monitoring/pc_specs_tab.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';

/// The Monitoring General tab's summary pane — the sidebar that says in words
/// what the map says in colour (2026-09-09-monitoring-general-map, Task 8).
///
/// The load-bearing fixture is node `n5`: the server reports it
/// `{assigned:false, online:true}` — a shape a real response reaches whenever a
/// tile names somebody the viewer may not see, because the assignee is hidden
/// while nothing else about the node is. Counting it as ONLINE would be a claim
/// about nobody, so it must land in "unassigned", and every count assertion
/// below is written so that the naive online-first implementation gets a
/// different number (3 online / 1 unassigned instead of 2 and 2). Tapping it is
/// the same test at the level of one tile: no name, no status word.
void main() {
  testWidgets(
    'nothing selected summarises the plan, counting assigned before online',
    (tester) async {
      await _pumpTab(tester);
      await _choosePlan(tester);

      expect(find.byKey(const Key('floor-plan-summary')), findsOneWidget);

      // The plan itself, named the way the picker named it.
      expect(find.text('Main office'), findsWidgets);
      expect(find.text('Company · A-1 Chimney'), findsWidgets);

      // Five tiles: two people online, one assigned but offline, and two with
      // nobody on them — n4 (no assignee) and n5 (an assignee this viewer may
      // not see, whose entry still says online:true).
      expect(find.text('5 in total'), findsOneWidget);
      expect(find.text('2 online'), findsOneWidget);
      expect(find.text('1 offline'), findsOneWidget);
      expect(
        find.text('2 unassigned'),
        findsOneWidget,
        reason: 'n5 reports online:true with assigned:false. Online about a '
            'person nobody can see is a claim about no one — it counts as '
            'unassigned, exactly as the canvas draws it grey.',
      );
      expect(
        find.text('3 online'),
        findsNothing,
        reason: 'The number an online-first count would have produced.',
      );
    },
  );

  testWidgets(
    'an online assignee and an offline one read differently',
    (tester) async {
      await _pumpTab(tester);
      await _choosePlan(tester);

      await _tapNode(tester, 'n1');

      expect(find.text('Ana Ruiz'), findsOneWidget);
      expect(find.text('Online · seen 2m ago'), findsOneWidget);

      // Re-tapping clears the selection, so the plan summary comes back with
      // no close button to hunt for.
      await _tapNode(tester, 'n1');
      expect(find.text('Ana Ruiz'), findsNothing);
      expect(find.text('5 in total'), findsOneWidget);

      // The control: the same pane, a person whose machine has gone quiet.
      await _tapNode(tester, 'n3');

      expect(find.text('Cara Diaz'), findsOneWidget);
      expect(find.text('Offline · seen 3h ago'), findsOneWidget);
      expect(
        find.textContaining('Online ·'),
        findsNothing,
        reason: 'Online and offline must not render the same sentence — that '
            'is the whole difference the pane exists to state.',
      );
    },
  );

  testWidgets(
    'an unassigned tile says so, and names nobody',
    (tester) async {
      await _pumpTab(tester);
      await _choosePlan(tester);

      // n4 has no assignee at all.
      await _tapNode(tester, 'n4');

      expect(find.text(FloorPlanSummary.unassignedNote), findsOneWidget);
      for (final name in const ['Ana Ruiz', 'Ben Ortiz', 'Cara Diaz']) {
        expect(
          find.text(name),
          findsNothing,
          reason: 'A tile with no assignee has no person to name.',
        );
      }
      expect(find.byKey(const Key('floor-plan-summary-name')), findsNothing);
      expect(find.textContaining('seen '), findsNothing);

      // n5 DOES name a user_id, but the server could not resolve the person —
      // and still said online:true. It must read exactly like n4.
      await _tapNode(tester, 'n5');

      expect(find.text(FloorPlanSummary.unassignedNote), findsOneWidget);
      expect(find.byKey(const Key('floor-plan-summary-name')), findsNothing);
      expect(
        find.textContaining('Online'),
        findsNothing,
        reason: 'assigned:false is asked first — an unresolvable assignee has '
            'no status to report, whatever `online` came back as.',
      );

      // The positive control: the pane CAN show a name, one tile over.
      await _tapNode(tester, 'n1');
      expect(find.byKey(const Key('floor-plan-summary-name')), findsOneWidget);
    },
  );

  testWidgets(
    'an assignee with no agent is told so, rather than shown blank specs',
    (tester) async {
      await _pumpTab(tester);
      await _choosePlan(tester);

      // Cara is resolved, online-state known, and `specs` came back null: she
      // has no machine running the agent.
      await _tapNode(tester, 'n3');

      expect(find.text(FloorPlanSummary.noAgentNote), findsOneWidget);

      // The control: the same pane one tile over, where specs exist — and it
      // words them the way PC Specs does, because it mounts the same widget.
      await _tapNode(tester, 'n3');
      await _tapNode(tester, 'n1');

      expect(find.text(FloorPlanSummary.noAgentNote), findsNothing);
      expect(
        find.text('32 GB RAM · 128 GB free · Windows 11'),
        findsOneWidget,
      );
    },
  );

  testWidgets(
    'without monitoring.specs.view the withheld sentence names the permission',
    (tester) async {
      // The server sends no `specs` KEY at all to a caller without the grant
      // (FloorPlansController::status). Present-and-null would be indistinct
      // from "this person has no agent", which is a different fact.
      await _pumpTab(tester, withSpecsKey: false);
      await _choosePlan(tester);

      await _tapNode(tester, 'n1');

      expect(find.text(PcSpecsTab.specsWithheldNote), findsOneWidget);
      expect(
        find.text(FloorPlanSummary.noAgentNote),
        findsNothing,
        reason: 'A withheld key is not an absent agent — the pane must not '
            'tell a permitted-looking story about a permission it lacks.',
      );
    },
  );

  testWidgets(
    'the Desktop Monitoring jump needs monitoring.screenshots.view',
    (tester) async {
      var jumped = 0;

      await _pumpTab(
        tester,
        grantScreenshots: true,
        onOpenDesktopMonitoring: () => jumped++,
        tag: 'with-grant',
      );
      await _choosePlan(tester);
      await _tapNode(tester, 'n1');

      final jump = find.byKey(const Key('general-open-desktop-monitoring'));
      expect(jump, findsOneWidget);

      await tester.tap(jump);
      await tester.pump();

      expect(jumped, 1);

      // The control: the same person, the same tap, without the grant.
      await _pumpTab(
        tester,
        grantScreenshots: false,
        onOpenDesktopMonitoring: () => jumped++,
        tag: 'without-grant',
      );
      await _choosePlan(tester);
      await _tapNode(tester, 'n1');

      expect(find.byKey(const Key('floor-plan-summary-name')), findsOneWidget);
      expect(
        find.byKey(const Key('general-open-desktop-monitoring')),
        findsNothing,
        reason: 'Desktop Monitoring is locked without the grant, so a jump to '
            'it would be a button that can only ever fail.',
      );
      expect(jumped, 1);
    },
  );

  testWidgets(
    'the jump really switches the Monitoring screen to Desktop Monitoring',
    (tester) async {
      // MonitoringScreen owns the sub-tab, so the callback is the only thing
      // that can move it. Tested through the screen, because a callback the
      // screen forgot to pass would look identical from inside the tab.
      await _pumpScreen(tester);

      // MAP, not General: the floor plan moved to its own tab on 2026-09-22
      // and General became the ownership chart, which is also what the screen
      // now opens on.
      await tester.tap(find.widgetWithText(ChoiceChip, 'Map'));
      await tester.pumpAndSettle();

      await _choosePlan(tester);
      await _tapNode(tester, 'n1');

      await tester.tap(find.byKey(const Key('general-open-desktop-monitoring')));
      await tester.pumpAndSettle();

      expect(find.byKey(const Key('floor-plan-canvas')), findsNothing);
      expect(
        tester
            .widget<ChoiceChip>(
              find.widgetWithText(ChoiceChip, 'Desktop Monitoring'),
            )
            .selected,
        isTrue,
      );
    },
  );

  testWidgets(
    'the summary is a rail beside the map when wide, and under it at 375px',
    (tester) async {
      await _pumpTab(tester, size: const Size(1280, 900), tag: 'wide');
      await _choosePlan(tester);

      final canvas = find.byKey(const Key('floor-plan-canvas'));
      final summary = find.byKey(const Key('floor-plan-summary'));

      expect(
        tester.getTopLeft(summary).dx >= tester.getTopRight(canvas).dx,
        isTrue,
        reason: 'Above GeneralTab.summaryBeside the summary is a right rail.',
      );
      expect(tester.takeException(), isNull);

      await _pumpTab(tester, size: const Size(375, 900), tag: 'narrow');
      await _choosePlan(tester);
      await _tapNode(tester, 'n1');

      expect(
        tester.getTopLeft(summary).dy >= tester.getBottomLeft(canvas).dy,
        isTrue,
        reason: 'At 375px the rail would leave the map ~60px wide, so the '
            'summary takes a turn underneath instead.',
      );
      expect(tester.takeException(), isNull);
    },
  );
}

Future<void> _choosePlan(WidgetTester tester) async {
  await tester.tap(find.byKey(const ValueKey('floor-plan-fp-1')));
  await tester.pumpAndSettle();
}

Future<void> _tapNode(WidgetTester tester, String id) async {
  await tester.tap(find.byKey(ValueKey('floor-plan-node-$id')));
  await tester.pumpAndSettle();
}

const _plan = {
  'id': 'fp-1',
  'name': 'Main office',
  'scope': {'type': 'company', 'id': 'co-1', 'label': 'A-1 Chimney'},
  'updated_at': '2026-09-09T10:00:00Z',
};

/// Five tiles and the status map the server would send for them. `withSpecs`
/// switches between a caller who holds `monitoring.specs.view` (the key is
/// present, null for the person with no agent) and one who does not (no key at
/// all, on any node).
Map<String, dynamic> _planBody({required bool withSpecs}) {
  final now = DateTime.now().toUtc();
  final recent = now.subtract(const Duration(minutes: 2)).toIso8601String();
  final quiet = now
      .subtract(const Duration(hours: 3, minutes: 1))
      .toIso8601String();

  Map<String, dynamic> entry(
    Map<String, dynamic> base, {
    Object? specs = _absent,
  }) => {
    ...base,
    if (withSpecs) 'specs': specs == _absent ? null : specs,
  };

  return {
    ..._plan,
    'layout': {
      'grid': {'cols': 40, 'rows': 28},
      'walls': <Map<String, dynamic>>[],
      'nodes': [
        {'id': 'n1', 'x': 2, 'y': 2, 'label': 'Desk 1', 'user_id': 'u-a'},
        {'id': 'n2', 'x': 6, 'y': 2, 'label': 'Desk 2', 'user_id': 'u-b'},
        {'id': 'n3', 'x': 10, 'y': 2, 'label': 'Desk 3', 'user_id': 'u-c'},
        {'id': 'n4', 'x': 14, 'y': 2, 'label': 'Desk 4', 'user_id': null},
        {'id': 'n5', 'x': 18, 'y': 2, 'label': 'Desk 5', 'user_id': 'u-hidden'},
      ],
    },
    'status': {
      'n1': entry(
        {
          'assigned': true,
          'online': true,
          'name': 'Ana Ruiz',
          'last_seen_at': recent,
        },
        specs: const {
          'ram_gb': 32,
          'disk_free_gb': 128,
          'os_version': 'Windows 11',
        },
      ),
      'n2': entry(
        {
          'assigned': true,
          'online': true,
          'name': 'Ben Ortiz',
          'last_seen_at': recent,
        },
        specs: const {'ram_gb': 16},
      ),
      // Resolved, offline, and no machine has ever reported specs.
      'n3': entry({
        'assigned': true,
        'online': false,
        'name': 'Cara Diaz',
        'last_seen_at': quiet,
      }),
      'n4': entry({
        'assigned': false,
        'online': false,
        'name': null,
        'last_seen_at': null,
      }),
      // The trap: an assignee the viewer may not see. Hidden, yet `online`
      // still came back true beside the hiding.
      'n5': entry({
        'assigned': false,
        'online': true,
        'name': null,
        'last_seen_at': null,
      }),
    },
  };
}

/// Sentinel for "this entry names no specs", so `null` can be passed through as
/// a real value.
const Object _absent = Object();

Future<void> _pumpTab(
  WidgetTester tester, {
  bool withSpecsKey = true,
  bool grantScreenshots = false,
  VoidCallback? onOpenDesktopMonitoring,
  Size size = const Size(1280, 900),
  String tag = 'default',
}) async {
  tester.view.physicalSize = size;
  tester.view.devicePixelRatio = 1.0;
  addTearDown(tester.view.reset);

  final api = ApiClient(
    client: MockClient((request) async {
      final path = request.url.path;

      if (path.endsWith('/me')) {
        return _json({
          'data': {
            ..._me,
            'permissions': <String>[
              'monitoring.view',
              'monitoring.general.view',
              'monitoring.map.view',
              if (grantScreenshots) 'monitoring.screenshots.view',
            ],
          },
        });
      }

      if (path.endsWith('/monitoring/floor-plans')) {
        return _json({
          'data': [_plan],
        });
      }

      if (path.contains('/monitoring/floor-plans/')) {
        return _json({'data': _planBody(withSpecs: withSpecsKey)});
      }

      return _json({'message': 'Nothing stubbed for $path.'}, 500);
    }),
  );

  final session = Session(api);
  await tester.runAsync(() => session.adopt('tok'));
  expect(session.signedIn, isTrue);

  // A per-pump key: a second pumpWidget of the same widget types reuses the
  // element tree, so the tab would keep the first pump's state (and its
  // selection) instead of mounting fresh.
  await tester.pumpWidget(
    MaterialApp(
      theme: EzBiziTheme.light(),
      home: Scaffold(
        body: GeneralTab(
          key: ValueKey('general-tab-$tag'),
          api: api,
          session: session,
          onOpenDesktopMonitoring: onOpenDesktopMonitoring,
        ),
      ),
    ),
  );
  await tester.pumpAndSettle();
}

/// The whole Monitoring screen, so the sub-tab switch can be observed where it
/// actually lives. Desktop Monitoring's own two fetches are stubbed empty — the
/// assertion is about which tab is showing, not what it found.
Future<void> _pumpScreen(WidgetTester tester) async {
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
            'permissions': const <String>[
              'monitoring.view',
              'monitoring.general.view',
              'monitoring.map.view',
              'monitoring.screenshots.view',
            ],
          },
        });
      }

      if (path.endsWith('/monitoring/floor-plans')) {
        return _json({
          'data': [_plan],
        });
      }

      if (path.contains('/monitoring/floor-plans/')) {
        return _json({'data': _planBody(withSpecs: true)});
      }

      if (path.endsWith('/monitoring/devices') ||
          path.endsWith('/monitoring/screenshots')) {
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
      home: Scaffold(body: MonitoringScreen(api: api, session: session)),
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
  'permissions': <String>['monitoring.view', 'monitoring.general.view'],
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
