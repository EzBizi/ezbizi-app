import 'dart:async';
import 'dart:convert';

import 'package:ezbizi_client/core/api_client.dart';
import 'package:ezbizi_client/core/session.dart';
import 'package:ezbizi_client/core/theme.dart';
import 'package:ezbizi_client/screens/monitoring/general_tab.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';

/// The Monitoring "General" tab — the floor-plan picker and the load/poll path
/// that feeds the map. The canvas itself arrives in Task 6, so the area under
/// test here is the placeholder, keyed the same as the canvas that replaces it.
///
/// The load-bearing test is the last one: a response for a plan the viewer has
/// SINCE LEFT must never paint. Two GETs in flight have no ordering guarantee,
/// so one plan's answer can land after another's; without the generation guard
/// in `_loadPlan` the abandoned plan would overwrite the chosen one's map while
/// the picker still names the chosen one — one office's layout shown under
/// another office's name. It is proven non-vacuously: the two plans carry
/// different node counts, and the assertion names both the count that must be
/// on screen and the count that must not.
void main() {
  testWidgets(
    'without monitoring.view the tab shows the note and never fetches',
    (tester) async {
      var fetched = false;

      await _pumpTab(
        tester,
        plans: [_companyPlan],
        grantView: false,
        onPlansFetch: () => fetched = true,
      );

      expect(find.text(GeneralTab.noPermissionNote), findsOneWidget);
      expect(find.byKey(const Key('floor-plan-picker')), findsNothing);
      expect(
        fetched,
        isFalse,
        reason: 'A caller who cannot view monitoring at all should never need '
            'a round trip to learn it — the session already says so.',
      );
    },
  );

  testWidgets(
    'the picker groups plans by scope, and choosing one loads its layout',
    (tester) async {
      await _pumpTab(
        tester,
        plans: [_companyPlan, _franchisePlan],
        planBody: (id) => _planBody(id, nodes: 3, walls: 4),
      );

      // Both plans offered, each under its own scope heading.
      expect(find.byKey(const Key('floor-plan-picker')), findsOneWidget);
      expect(find.byKey(const ValueKey('floor-plan-fp-1')), findsOneWidget);
      expect(find.byKey(const ValueKey('floor-plan-fp-2')), findsOneWidget);
      expect(find.textContaining('Company'), findsOneWidget);
      expect(find.textContaining('Franchise'), findsOneWidget);

      // Nothing chosen yet, so the map area is not up.
      expect(find.byKey(const Key('floor-plan-canvas')), findsNothing);
      expect(find.text(GeneralTab.selectPrompt), findsOneWidget);

      await tester.tap(find.byKey(const ValueKey('floor-plan-fp-1')));
      await tester.pumpAndSettle();

      expect(find.byKey(const Key('floor-plan-canvas')), findsOneWidget);
      expect(find.textContaining('3 computers'), findsOneWidget);
    },
  );

  testWidgets(
    'with no plans an editor is offered a way to make one, and a viewer is not',
    (tester) async {
      await _pumpTab(tester, plans: const [], grantManage: true);

      expect(find.text(GeneralTab.noPlansNote), findsOneWidget);
      expect(find.byKey(const Key('general-add-plan')), findsOneWidget);

      // The same empty roster without the grant: no way in, and no hint of one.
      await _pumpTab(tester, plans: const [], grantManage: false);

      expect(find.text(GeneralTab.noPlansNote), findsOneWidget);
      expect(
        find.byKey(const Key('general-add-plan')),
        findsNothing,
        reason: 'Without monitoring.map.manage the server refuses the create, '
            'so offering it would be a button that only ever fails.',
      );
    },
  );

  testWidgets(
    'a late answer for the plan you left never paints over the one you chose',
    (tester) async {
      // Each plan fetch is held open on its own Completer, so the test — not
      // the network — decides which answer lands first.
      final gates = <String, Completer<http.Response>>{
        'fp-1': Completer<http.Response>(),
        'fp-2': Completer<http.Response>(),
      };

      final requested = <String>[];

      await _pumpTab(
        tester,
        plans: [_companyPlan, _franchisePlan],
        planResponder: (id) => gates[id]!.future,
        onPlanFetch: requested.add,
        settle: false,
      );
      await _advance(tester); // the plan LIST is not gated; let it land.

      // Choose the company plan (3 computers); its answer is held open.
      await tester.tap(find.byKey(const ValueKey('floor-plan-fp-1')));
      await _advance(tester);

      // Move to the franchise plan (7 computers) before the first answers.
      await tester.tap(find.byKey(const ValueKey('floor-plan-fp-2')));
      await _advance(tester);

      gates['fp-2']!
          .complete(_json({'data': _planBody('fp-2', nodes: 7, walls: 2)}));
      await _advance(tester);

      expect(find.textContaining('7 computers'), findsOneWidget);

      // The abandoned fetch really was in flight — without this the next
      // assertion could pass because the first tap never landed at all, which
      // would look exactly like the guard working.
      expect(
        requested,
        containsAll(<String>['fp-1', 'fp-2']),
        reason: 'Both plans must have been fetched for the late-answer case to '
            'mean anything.',
      );

      // Now the abandoned plan finally answers. It must change nothing.
      gates['fp-1']!
          .complete(_json({'data': _planBody('fp-1', nodes: 3, walls: 4)}));
      await _advance(tester);

      expect(find.textContaining('7 computers'), findsOneWidget);
      expect(
        find.textContaining('3 computers'),
        findsNothing,
        reason: 'The company plan was left before it answered; painting it now '
            'would show one office layout under the other office name.',
      );
    },
  );
}

const _companyPlan = {
  'id': 'fp-1',
  'name': 'Main office',
  'scope': {'type': 'company', 'id': 'co-1', 'label': 'A-1 Chimney'},
  'updated_at': '2026-09-09T10:00:00Z',
};

const _franchisePlan = {
  'id': 'fp-2',
  'name': 'North branch',
  'scope': {'type': 'franchise', 'id': 'fr-1', 'label': 'North'},
  'updated_at': '2026-09-09T11:00:00Z',
};

Map<String, dynamic> _planBody(
  String id, {
  required int nodes,
  required int walls,
}) => {
  'id': id,
  'name': id == 'fp-1' ? 'Main office' : 'North branch',
  'scope': (id == 'fp-1' ? _companyPlan : _franchisePlan)['scope'],
  'layout': {
    'grid': {'cols': 40, 'rows': 28},
    'walls': [
      for (var i = 0; i < walls; i++) {'x1': i, 'y1': 0, 'x2': i + 1, 'y2': 0},
    ],
    'nodes': [
      for (var i = 0; i < nodes; i++)
        {'id': 'n$i', 'x': i, 'y': i, 'label': 'Desk $i', 'user_id': null},
    ],
  },
  'status': {
    for (var i = 0; i < nodes; i++)
      'n$i': {'assigned': false, 'online': false, 'name': null},
  },
};

Future<void> _advance(WidgetTester tester, [int steps = 10]) async {
  for (var i = 0; i < steps; i++) {
    await tester.pump(const Duration(milliseconds: 50));
  }
}

Future<void> _pumpTab(
  WidgetTester tester, {
  required List<Map<String, dynamic>> plans,
  bool grantView = true,
  bool grantManage = true,
  VoidCallback? onPlansFetch,
  Map<String, dynamic> Function(String planId)? planBody,
  Future<http.Response> Function(String planId)? planResponder,
  void Function(String planId)? onPlanFetch,
  bool settle = true,
  Size size = const Size(1280, 900),
}) async {
  tester.view.physicalSize = size;
  tester.view.devicePixelRatio = 1.0;
  addTearDown(tester.view.reset);

  final permissions = <String>[
    if (grantView) 'monitoring.view',
    if (grantManage) 'monitoring.map.manage',
  ];

  final api = ApiClient(
    client: MockClient((request) async {
      final path = request.url.path;

      if (path.endsWith('/me')) {
        return _json({
          'data': {..._me, 'permissions': permissions},
        });
      }

      if (request.method == 'GET' && path.endsWith('/monitoring/floor-plans')) {
        onPlansFetch?.call();
        return _json({'data': plans});
      }

      if (request.method == 'GET' &&
          path.contains('/monitoring/floor-plans/')) {
        final id = path.split('/').last;
        onPlanFetch?.call(id);
        if (planResponder != null) return planResponder(id);
        return _json({'data': planBody!(id)});
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
      home: Scaffold(body: GeneralTab(api: api, session: session)),
    ),
  );

  if (settle) {
    await tester.pumpAndSettle();
  } else {
    await tester.pump();
  }
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
